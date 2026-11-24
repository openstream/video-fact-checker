<?php
namespace VideoFactChecker;

class VideoProcessor {
    /** Default for vfc_youtube_max_download_minutes (YouTube without subtitles). */
    const DEFAULT_MAX_DOWNLOAD_MINUTES = 45;

    private $logger;
    private $upload_dir;

    // Metrics captured during the last download, read by the AJAX layer for cost
    // accounting. Reset at the start of each download_video() call.
    private $last_download_bytes = 0;
    private $last_audio_seconds = 0.0;
    private $last_is_youtube = false;
    private $last_title = '';
    // Video duration (seconds) learned during the last caption attempt, so the
    // download-fallback length check doesn't need a second metadata request.
    private $last_caption_duration = 0;

    public function __construct() {
        $this->logger = new Logger();
        $upload_dir = wp_upload_dir();
        $this->upload_dir = $upload_dir['basedir'] . '/video-fact-checker';

        // Ensure upload directory exists
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
    }

    public function get_last_download_bytes() { return $this->last_download_bytes; }
    public function get_last_audio_seconds() { return $this->last_audio_seconds; }
    public function get_last_is_youtube() { return $this->last_is_youtube; }
    public function get_last_title() { return $this->last_title; }

    /**
     * Try to get a YouTube transcript from captions (no audio download, no
     * Whisper). Two stages: InnerTube direct, then — for videos YouTube refuses
     * to serve from our datacenter IP (LOGIN_REQUIRED) — InnerTube through the
     * residential proxy (only the small caption traffic goes through it).
     *
     * @return array{transcript:string,duration:int,title:string,source:string}
     *         on success, or null when captions aren't available for this video
     *         (the caller then decides about an audio-download fallback).
     * @throws ProcessingException only for hard, user-facing failures.
     */
    public function try_youtube_captions($url) {
        if (!(bool) get_option('vfc_youtube_use_innertube', true)) {
            $this->logger->log("InnerTube disabled by setting; skipping caption path");
            return null;
        }
        $video_id = YouTubeCaptions::extract_video_id($url);
        if ($video_id === '') {
            return null;
        }

        $captions = new YouTubeCaptions($this->logger);

        // Stage 1: direct (no proxy).
        try {
            $r = $captions->fetch($video_id, null);
            return $this->caption_result($r, 'innertube-direct');
        } catch (ProcessingException $e) {
            $tech = $e->get_technical();
            // "Playable but no captions" is definitive — trying the proxy won't
            // conjure subtitles. Signal the caller to consider audio download.
            if (strpos($tech, 'NO_CAPTIONS') === 0) {
                $this->last_caption_duration = $this->duration_from_technical($tech);
                $this->last_title = $this->title_from_technical($tech) ?: $this->last_title;
                $this->logger->log("InnerTube direct: no captions for {$video_id}");
                return null;
            }
            // LOGIN_REQUIRED (or other datacenter refusal): try via proxy.
            $this->logger->log("InnerTube direct failed ({$tech}); trying via proxy");
        }

        // Stage 2: through the residential proxy (if configured).
        $proxy = $this->build_youtube_proxy();
        if ($proxy === '') {
            $this->logger->log("No proxy configured; cannot retry InnerTube via proxy");
            return null;
        }
        try {
            $r = $captions->fetch($video_id, $proxy);
            return $this->caption_result($r, 'innertube-proxy');
        } catch (ProcessingException $e) {
            $tech = $e->get_technical();
            if (strpos($tech, 'NO_CAPTIONS') === 0) {
                $this->last_caption_duration = $this->duration_from_technical($tech);
                $this->last_title = $this->title_from_technical($tech) ?: $this->last_title;
                $this->logger->log("InnerTube proxy: no captions for {$video_id}");
                return null;
            }
            // Even the proxy couldn't get captions — give up on the caption path;
            // the caller falls back to audio download.
            $this->logger->log("InnerTube proxy failed too ({$tech}); falling back to download");
            return null;
        }
    }

    /** Duration (seconds) discovered during the last caption attempt, if any. */
    public function get_last_caption_duration() { return $this->last_caption_duration; }

    private function caption_result($r, $source) {
        // Record shared metrics so the AJAX layer reads them uniformly.
        $this->last_audio_seconds = (float) $r['duration']; // used for Whisper cost = 0 anyway
        $this->last_caption_duration = (int) $r['duration'];
        $this->last_download_bytes = 0;
        $this->last_is_youtube = true;
        if (!empty($r['title'])) {
            $this->last_title = mb_substr($r['title'], 0, 500);
        }
        $this->logger->log("Transcript via {$source} (duration={$r['duration']}s, no Whisper)");
        // Normalise the caption language code to a 2-letter code (e.g. "en-US" → "en").
        $lang = isset($r['lang']) ? strtolower(substr((string) $r['lang'], 0, 2)) : '';
        return [
            'transcript' => $r['transcript'],
            'duration'   => (int) $r['duration'],
            'title'      => isset($r['title']) ? $r['title'] : '',
            'source'     => $source,
            'lang'       => $lang,
        ];
    }

    private function duration_from_technical($tech) {
        if (preg_match('/duration=(\d+)/', $tech, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private function title_from_technical($tech) {
        if (preg_match('/title=(.*)$/s', $tech, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * Get a YouTube video's duration in seconds via yt-dlp --dump-json (used to
     * enforce the audio-download length limit when captions aren't available).
     * Returns 0 if it can't be determined.
     */
    public function get_video_duration_seconds($url) {
        $command = $this->get_video_info($url); // builds a --dump-json command
        $output = [];
        $return_var = 0;
        exec($command, $output, $return_var);
        if ($return_var !== 0 || empty($output)) {
            return 0;
        }
        // The command merges stderr, so warnings (e.g. yt-dlp's "Python 3.10 is
        // deprecated" on prod) precede the JSON line; parse only that line.
        foreach (array_reverse($output) as $line) {
            if (strpos(ltrim($line), '{') !== 0) {
                continue;
            }
            $json = json_decode($line, true);
            if (is_array($json) && isset($json['duration']) && is_numeric($json['duration'])) {
                return (int) round((float) $json['duration']);
            }
        }
        return 0;
    }

    /**
     * Read the audio duration (seconds) of a media file via ffprobe.
     * Returns 0.0 on any failure — cost accounting treats that as "unknown".
     */
    private function probe_audio_seconds($file) {
        if (!is_string($file) || !file_exists($file)) {
            return 0.0;
        }
        $cmd = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellarg($file)
        );
        $out = trim((string) shell_exec($cmd));
        return is_numeric($out) ? (float) $out : 0.0;
    }

    /**
     * Parse the total downloaded size (bytes) from yt-dlp output lines like
     * "[download] 100% of 13.18MiB in 00:00:00". Returns the largest match found
     * (the final total), or 0 if none.
     */
    private function parse_downloaded_bytes($text) {
        if (!is_string($text) || $text === '') {
            return 0;
        }
        $units = ['B' => 1, 'KIB' => 1024, 'MIB' => 1048576, 'GIB' => 1073741824,
                  'KB' => 1000, 'MB' => 1000000, 'GB' => 1000000000];
        $max = 0;
        if (preg_match_all('/of\s+~?\s*([0-9]+(?:\.[0-9]+)?)\s*([KMG]?i?B)/i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $num = (float) $match[1];
                $unit = strtoupper($match[2]);
                $bytes = (int) round($num * ($units[$unit] ?? 1));
                if ($bytes > $max) {
                    $max = $bytes;
                }
            }
        }
        return $max;
    }

    /**
     * yt-dlp options on every call (GitHub AI Scan #1/#8/#9/#4/#10):
     *   - no generic extractor: yt-dlp only fetches URLs a site-specific
     *     extractor claims (YouTube, TikTok, …), never an arbitrary host/redirect;
     *   - no playlists (one submitted link = one video);
     *   - a hard cap on the downloaded media size.
     * The URL itself always follows "--", so a value starting with "-" can never
     * be read as an option.
     */
    const YTDLP_SAFE_OPTS = '--use-extractors default,-generic --no-playlist --max-filesize 500M';

    /**
     * Media CDNs whose direct file links (e.g. a tweet's .mp4) only the generic
     * extractor can read. For these hosts — and only these — it stays on.
     * Extend via the vfc_generic_extractor_hosts filter.
     */
    const GENERIC_EXTRACTOR_HOSTS = ['video.twimg.com'];

    /** YTDLP_SAFE_OPTS, with the generic extractor allowed for listed CDN hosts. */
    private static function ytdlp_safe_opts($url) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $hosts = (array) apply_filters('vfc_generic_extractor_hosts', self::GENERIC_EXTRACTOR_HOSTS);
        if ($host !== '' && in_array($host, array_map('strtolower', $hosts), true)) {
            return str_replace('default,-generic', 'default', self::YTDLP_SAFE_OPTS);
        }
        return self::YTDLP_SAFE_OPTS;
    }

    /** Wall-clock cap for one yt-dlp run (download + conversion). */
    const YTDLP_TIMEOUT_SECONDS = 600;

    /** "timeout -k 10 <s> " when coreutils' timeout is available, else ''. */
    private static function timeout_prefix($seconds) {
        foreach (['/usr/bin/timeout', '/bin/timeout'] as $bin) {
            if (@is_executable($bin)) {
                return $bin . ' -k 10 ' . (int) $seconds . ' ';
            }
        }
        return '';
    }

    public function download_video($url) {
        // Reset per-run metrics.
        $this->last_download_bytes = 0;
        $this->last_audio_seconds = 0.0;
        $this->last_is_youtube = $this->is_youtube_url($url);
        $this->last_title = '';
        $output_dir = '';
        $uniq = '';
        $mp3_file = '';

        try {
            // Quick dependency check for clearer error messages
            try {
                $this->check_dependencies();
            } catch (\Exception $depEx) {
                throw new \Exception('Dependency check failed: ' . $depEx->getMessage());
            }

            $this->logger->log("=== Starting download ===");

            // Temp audio lives in the private folder (not the plugin folder, which
            // is web-reachable and must not be written to).
            $output_dir = PrivateStorage::temp_dir();
            PrivateStorage::purge_stale_temp();
            if (!is_dir($output_dir) || !is_writable($output_dir)) {
                throw new \Exception("Temp directory is not writable: " . $output_dir);
            }

            // Create unique filename
            $uniq = uniqid('audio_');
            $output_file = $output_dir . '/' . $uniq . '.%(ext)s';
            $this->logger->log("Output file template: " . $output_file);

            // Sidecar file to capture the video title in the same yt-dlp run
            // (via --print-to-file), so we get it without an extra request.
            $title_file = $output_dir . '/' . $uniq . '.title';
            $title_opt = '--print-to-file ' . escapeshellarg('%(title)s') . ' ' . escapeshellarg($title_file);

            // Bound yt-dlp's own retries/timeout so a stuck proxy node fails fast;
            // our outer retry loop then re-runs (often getting a fresh proxy IP).
            $net_opts = self::ytdlp_safe_opts($url) . ' --retries 3 --socket-timeout 20';

            // Speech-sized audio for Whisper: mono, 16 kHz, 48 kbit/s ≈ 0.36 MB/min,
            // so up to ~69 min fit OpenAI's 25 MB upload cap (the old VBR-0 mp3 ran
            // ~1 MB/min and broke past ~22 min). The download itself is unchanged.
            $audio_opts = '--audio-quality 48K --postprocessor-args ' . escapeshellarg('ExtractAudio:-ac 1 -ar 16000');

            $is_youtube = $this->last_is_youtube;
            $this->logger->log("Is YouTube URL: " . ($is_youtube ? 'yes' : 'no'));

            // Enforce the length limit in yt-dlp itself, for every platform (before
            // v0.27.0 only YouTube had one: GitHub AI Scan #4/#10). "<=?" lets
            // videos without a duration field through; --max-filesize and the
            // timeout still bound those. Live streams never end, so they're refused.
            $limit_min = (int) get_option('vfc_youtube_max_download_minutes', self::DEFAULT_MAX_DOWNLOAD_MINUTES);
            $filter = '!is_live';
            if ($limit_min > 0) {
                $filter .= ' & duration <=? ' . ($limit_min * 60);
            }
            $net_opts .= ' --match-filter ' . escapeshellarg($filter);

            $proxy = $is_youtube ? $this->build_youtube_proxy() : '';
            $this->logger->log("Proxy for this download: " . ($proxy !== '' ? 'YES | ' . $this->describe_proxy($proxy) : 'NO'));

            if ($is_youtube) {
                if ($proxy !== '') {
                    // Explicitly select an audio-only stream so we never pull the
                    // (much larger) video track through the metered proxy.
                    $auth_opt = '--proxy ' . escapeshellarg($proxy);
                } else {
                    $auth_opt = '--cookies ' . escapeshellarg($this->check_cookies_file($url));
                }
                $format = 'bestaudio/best';
            } else {
                // Non-YouTube: no cookies, no proxy.
                //
                // Format selection matters here: some TikTok videos advertise an audio
                // codec on every format but actually hand out a silent HEVC (bytevc1)
                // stream on download, which makes "-x" fail with
                // "unable to obtain file audio codec with ffprobe". TikTok's combined
                // "download" format (h264 + aac) does carry real audio, so prefer it,
                // then fall back to bestaudio / best for all other platforms.
                $auth_opt = '';
                $format = 'download/bestaudio/best';
            }

            $command = self::timeout_prefix(self::YTDLP_TIMEOUT_SECONDS) . sprintf(
                'yt-dlp %s %s -f %s -x --audio-format mp3 %s %s -o %s -- %s 2>&1',
                $auth_opt,
                $net_opts,
                escapeshellarg($format),
                $audio_opts,
                $title_opt,
                escapeshellarg($output_file),
                escapeshellarg($url)
            );

            $this->logger->log("=== Executing final command ===");
            $this->logger->log("Command: " . $this->maskProxyCredentialsInCommand($command));

            // Retry on transient proxy failures (e.g. a 522 / "Tunnel connection
            // failed" — the proxy node briefly couldn't reach the target). These are
            // not deterministic, so a short retry usually succeeds. Non-transient
            // errors (no audio, unsupported URL, 407 auth, …) are not retried.
            $max_attempts = 3;
            for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
                $output = [];
                $return_var = 0;
                exec($command, $output, $return_var);

                if ($return_var === 0 || $return_var === 124 || $return_var === 137) {
                    break; // success, or killed by the timeout (not worth retrying)
                }
                $err = implode("\n", $output);
                $transient = (stripos($err, 'Tunnel connection failed') !== false)
                    || (stripos($err, '522') !== false)
                    || (stripos($err, 'Temporary failure') !== false)
                    || (stripos($err, 'Connection reset') !== false)
                    || (stripos($err, 'timed out') !== false);
                if (!$transient || $attempt === $max_attempts) {
                    break;
                }
                $this->logger->log("Transient download error (attempt {$attempt}/{$max_attempts}), retrying: " . $this->first_line($err));
                sleep(2 * $attempt); // brief backoff: 2s, 4s
            }

            if (!empty($output)) {
                $this->logger->log("Command output: " . implode("\n", $output));
            }
            $joined = implode("\n", $output);

            if ($return_var === 124 || $return_var === 137) {
                throw new ProcessingException(
                    "This video took too long to download. Please try a shorter video.",
                    'Video download (yt-dlp): timeout',
                    sprintf('yt-dlp killed after %ds (exit %d)', self::YTDLP_TIMEOUT_SECONDS, $return_var)
                );
            }

            if ($return_var !== 0) {
                $error_output = $joined !== '' ? $joined : 'No output';

                // The full yt-dlp output (progress bars, format lines, stack noise) is
                // already written to the log above. Never surface it in the UI — instead
                // map it to a single concise, human-readable message.
                $message = $this->summarize_download_error($error_output);

                // Keep the raw output in the log for debugging, tied to the request id.
                $this->logger->log("Download failed (exit {$return_var}): " . $error_output, 'error');

                // Carry the real reason (a concise error line) into the admin email
                // via a structured exception, instead of a useless stack trace.
                $stage = $this->last_is_youtube
                    ? 'YouTube audio download (yt-dlp' . ($proxy !== '' ? ' via proxy)' : ')')
                    : 'Video download (yt-dlp)';
                throw new ProcessingException(
                    $message,
                    $stage,
                    $this->download_error_technical($error_output)
                );
            }

            // Skipped by --match-filter / --max-filesize: yt-dlp exits 0 without a file.
            // The filter message doesn't say which part failed (length or live).
            if (stripos($joined, 'does not pass filter') !== false && $limit_min <= 0) {
                throw new ProcessingException(
                    "Live streams can't be fact-checked. Please try again once the recording is available.",
                    'Video download: live stream (yt-dlp match-filter)',
                    'is_live'
                );
            }
            if (stripos($joined, 'does not pass filter') !== false) {
                $user = $is_youtube
                    ? sprintf("This YouTube video has no subtitles and is longer than %d minutes (or a live stream), too long to transcribe automatically. Please try a shorter video.", $limit_min)
                    : sprintf("This video is longer than %d minutes (or a live stream), too long to transcribe automatically. Please try a shorter video.", $limit_min);
                throw new ProcessingException(
                    $user,
                    ($is_youtube ? "YouTube: no captions, over the {$limit_min}-min download limit" : "Video over the {$limit_min}-min download limit") . ' (yt-dlp match-filter)',
                    "duration > limit={$limit_min}min or is_live (duration was unknown before the download)"
                );
            }
            if (stripos($joined, 'larger than max-filesize') !== false) {
                throw new ProcessingException(
                    "This video file is too large to process. Please try a shorter video.",
                    'Video download over the 500 MB download limit (yt-dlp max-filesize)',
                    $this->download_error_technical($joined)
                );
            }

            // Find the generated MP3 file
            $mp3_file = $output_dir . '/' . $uniq . '.mp3';
            $this->logger->log("Looking for MP3 file: " . $mp3_file);

            if (!file_exists($mp3_file)) {
                throw new \Exception('Audio file not found: ' . basename($mp3_file));
            }

            // Read the captured video title (sidecar file).
            if (file_exists($title_file)) {
                $t = trim((string) @file_get_contents($title_file));
                // yt-dlp prints "NA" when a field is unavailable; treat as empty.
                if ($t !== '' && strtoupper($t) !== 'NA') {
                    $this->last_title = mb_substr($t, 0, 500);
                    $this->logger->log("Captured title: " . $this->last_title);
                }
            }

            // Capture metrics for cost accounting.
            // Audio duration drives the Whisper cost.
            $this->last_audio_seconds = $this->probe_audio_seconds($mp3_file);
            // Downloaded bytes drive the proxy cost. Prefer the size yt-dlp reports
            // (the actual video traffic); fall back to the mp3 size if not parseable.
            $downloaded = $this->parse_downloaded_bytes($joined);
            if ($downloaded <= 0) {
                $downloaded = (int) @filesize($mp3_file);
            }
            $this->last_download_bytes = $downloaded;
            $this->logger->log(sprintf(
                "Run metrics: audio_seconds=%.2f download_bytes=%d youtube=%s",
                $this->last_audio_seconds, $this->last_download_bytes, $this->last_is_youtube ? 'yes' : 'no'
            ));

            $this->cleanup_download($output_dir, $uniq, $mp3_file);
            return $mp3_file;

        } catch (\Exception $e) {
            // Never leave partial downloads behind (GitHub AI Scan #4/#10).
            $this->cleanup_download($output_dir, $uniq, '');
            $this->logger->log("=== Error in download_video ===");
            $this->logger->log("Error message: " . $e->getMessage());
            throw $e;
        }
    }

    /** Delete every temp file of one run except $keep (the finished mp3, if any). */
    private function cleanup_download($dir, $uniq, $keep) {
        if ($dir === '' || $uniq === '') {
            return;
        }
        foreach ((array) glob($dir . '/' . $uniq . '*') as $f) {
            if ($f !== $keep && is_file($f)) {
                @unlink($f);
            }
        }
    }

    private function get_video_info($url) {
        $base = self::timeout_prefix(60) . 'yt-dlp ' . self::ytdlp_safe_opts($url) . ' ';
        if ($this->is_youtube_url($url)) {
            $proxy = $this->build_youtube_proxy();
            $this->logger->log("Proxy configured for info: " . ($proxy !== '' ? 'YES | ' . $this->describe_proxy($proxy) : 'NO'));
            if ($proxy !== '') {
                return $base . sprintf('--proxy %s --retries 1 --socket-timeout 15 --dump-json -- %s 2>&1',
                    escapeshellarg($proxy),
                    escapeshellarg($url)
                );
            }
            return $base . sprintf('--cookies %s --dump-json -- %s 2>&1',
                escapeshellarg($this->check_cookies_file($url)),
                escapeshellarg($url)
            );
        }
        // Non-YouTube: do not apply proxy
        return $base . sprintf('--socket-timeout 15 --dump-json -- %s 2>&1', escapeshellarg($url));
    }

    public function build_youtube_proxy() {
        $address = trim(get_option('vfc_ytdlp_proxy_address', ''));
        $port = trim(get_option('vfc_ytdlp_proxy_port', ''));
        $username = trim(get_option('vfc_ytdlp_proxy_username', ''));
        $password = trim(get_option('vfc_ytdlp_proxy_password', ''));

        if ($address === '') {
            return '';
        }

        // If address already includes a scheme, keep it; default to http otherwise
        $has_scheme = preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $address) === 1;
        $scheme_prefixed = $has_scheme ? $address : ('http://' . $address);

        // Inject credentials if provided
        $auth = '';
        if ($username !== '') {
            $auth = rawurlencode($username);
            if ($password !== '') {
                $auth .= ':' . rawurlencode($password);
            }
            $auth .= '@';
        }

        // Split out scheme and host for safe recomposition
        $parts = parse_url($scheme_prefixed);
        if ($parts === false || !isset($parts['scheme']) || !isset($parts['host'])) {
            // If parsing fails, fall back to the raw address (yt-dlp will error with a clear message)
            return $scheme_prefixed;
        }

        $host = $parts['host'];
        $built = $parts['scheme'] . '://' . $auth . $host;

        // Apply port: prefer explicit field, otherwise parsed port if present
        $final_port = '';
        if ($port !== '') {
            $final_port = ':' . $port;
        } elseif (isset($parts['port'])) {
            $final_port = ':' . $parts['port'];
        }

        // Preserve path if provided in address
        $path = isset($parts['path']) ? $parts['path'] : '';

        return $built . $final_port . $path;
    }

    private function maskProxyCredentialsInCommand($command) {
        if (!is_string($command)) {
            return $command;
        }
        if (preg_match('/--proxy\s+("[^"]+"|\'[^\']+\'|\S+)/', $command, $matches)) {
            $raw = $matches[1];
            $quote = '';
            $proxySpec = $raw;
            if ($raw[0] === '"' || $raw[0] === "'") {
                $quote = $raw[0];
                $proxySpec = substr($raw, 1, -1);
            }
            $parts = @parse_url($proxySpec);
            if ($parts && isset($parts['user'])) {
                $redacted = (isset($parts['scheme']) ? $parts['scheme'] : 'http') . '://' . '****' . (isset($parts['pass']) ? ':****' : '') . '@' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . (isset($parts['path']) ? $parts['path'] : '');
                $replacement = '--proxy ' . ($quote ? $quote . $redacted . $quote : $redacted);
                return str_replace($matches[0], $replacement, $command);
            }
        }
        return $command;
    }

    /**
     * Turn raw yt-dlp/ffmpeg error output into a single concise, user-facing sentence.
     * The full technical output stays in the log; the UI only sees this.
     */
    private function summarize_download_error($error_output) {
        if (!is_string($error_output)) {
            $error_output = '';
        }

        // Proxy: 407 — distinguish exhausted plan from bad credentials.
        if (stripos($error_output, '407 Proxy Authentication Required') !== false) {
            $proxy_msg = $this->extract_proxy_error_message($error_output);
            if ($proxy_msg !== '' && (stripos($proxy_msg, 'limit') !== false || stripos($proxy_msg, 'traffic') !== false || stripos($proxy_msg, 'quota') !== false)) {
                return "The video service is temporarily unavailable (proxy traffic limit reached). Please try again later.";
            }
            return "The video service is temporarily unavailable (proxy authentication failed). Please try again later.";
        }
        if (stripos($error_output, '522 status code 522') !== false) {
            return "The video service is temporarily unavailable (connection timeout). Please try again later.";
        }
        if (stripos($error_output, 'Tunnel connection failed') !== false) {
            return "The video service is temporarily unavailable (connection failed). Please try again later.";
        }
        if (stripos($error_output, "Sign in to confirm you're not a bot") !== false || stripos($error_output, 'cookies') !== false) {
            return "This video requires sign-in and can't be processed right now.";
        }
        if (stripos($error_output, 'unable to obtain file audio codec') !== false) {
            return "This video has no readable audio track, so it can't be fact-checked.";
        }
        if (stripos($error_output, 'Video unavailable') !== false || stripos($error_output, 'This video is not available') !== false) {
            return "This video is unavailable or private and can't be accessed.";
        }
        if (stripos($error_output, 'Unsupported URL') !== false || stripos($error_output, 'is not a valid URL') !== false
            || stripos($error_output, 'No suitable extractor') !== false) {
            return "This link isn't a supported video URL. Please check the address and try again.";
        }

        // Fallback: generic, no raw output.
        return "This video couldn't be downloaded. Please check the link or try a different video.";
    }

    /**
     * Distil yt-dlp's multi-line output down to the one line that actually
     * explains the failure, for the admin email's "Reason". Prefers an ERROR:/
     * WARNING: line, skips the ever-present Python-deprecation notice and
     * progress/format noise, and adds a plain-language hint for the common 407.
     */
    private function download_error_technical($error_output) {
        $error_output = (string) $error_output;
        $lines = preg_split('/\r?\n/', $error_output);
        $skip = function ($l) {
            $l = trim($l);
            if ($l === '') return true;
            if (stripos($l, 'Deprecated Feature') !== false) return true;   // Python 3.10 notice
            if (stripos($l, 'Support for Python') !== false) return true;
            if (strpos($l, '[download]') === 0) return true;                 // progress bars
            if (strpos($l, '[info]') === 0) return true;
            return false;
        };

        // Prefer the yt-dlp ERROR: line, then WARNING:, then first meaningful line.
        $error_line = ''; $warn_line = ''; $first = '';
        foreach ($lines as $l) {
            if ($skip($l)) continue;
            $t = trim($l);
            if ($first === '') { $first = $t; }
            if ($error_line === '' && stripos($t, 'ERROR:') !== false) { $error_line = $t; }
            if ($warn_line === '' && stripos($t, 'WARNING:') !== false) { $warn_line = $t; }
        }
        $reason = $error_line ?: ($warn_line ?: $first);
        $reason = mb_substr($reason, 0, 300);

        // Add a human hint for the classic proxy-balance/limit 407.
        if (stripos($error_output, '407 Proxy Authentication Required') !== false) {
            $reason .= "  [hint: Decodo proxy rejected auth — usually an exhausted balance/traffic limit, not a wrong password. Top up / check the Decodo balance.]";
        }
        return $reason !== '' ? $reason : 'yt-dlp exited non-zero with no parseable error line.';
    }

    /** First non-empty line of a multi-line string (for concise log messages). */
    private function first_line($text) {
        foreach (preg_split('/\r?\n/', (string) $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return mb_substr($line, 0, 200);
            }
        }
        return '';
    }

    private function extract_proxy_error_message($error_output) {
        // Proxies often explain a 407 via an x-error-message header or a plain-text body
        // that yt-dlp includes in its output. Pull out the most descriptive line we can.
        if (!is_string($error_output) || $error_output === '') {
            return '';
        }
        if (preg_match('/x-error-message:\s*(.+)/i', $error_output, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/(You\'?ve reached[^\n\r]*|traffic limit[^\n\r]*|current traffic limit[^\n\r]*|quota[^\n\r]*exceeded[^\n\r]*)/i', $error_output, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function describe_proxy($proxy) {
        // Proxy descriptor without credentials for logging
        if (!is_string($proxy) || $proxy === '') {
            return '';
        }
        $parts = @parse_url($proxy);
        if ($parts === false) {
            return '[invalid proxy spec]';
        }
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
        $host = isset($parts['host']) ? $parts['host'] : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return sprintf('scheme=%s host=%s%s', $scheme, $host, $port);
    }

    private function check_dependencies() {
        exec('yt-dlp --version 2>&1', $output, $return_var);
        
        if ($return_var !== 0) {
            throw new \Exception('yt-dlp is not installed or not accessible');
        }
        
        $this->logger->log("yt-dlp version: " . $output[0]);
    }

    private function format_file_size($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }

    private function isValidJSON($string) {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Human-readable platform name derived from the video URL's host.
     * Used for display/stats — distinct from the rate-limit bucket (youtube/other).
     * Returns a lowercase slug like 'youtube', 'tiktok', 'instagram', 'twitter', etc.
     * Falls back to the bare host, or 'other' when no host can be parsed.
     */
    public static function detect_platform($url) {
        $host = '';
        $parsed = @parse_url((string) $url);
        if (is_array($parsed) && isset($parsed['host'])) {
            $host = strtolower($parsed['host']);
        }
        if ($host === '') {
            return 'other';
        }
        // host substring => platform slug
        $map = [
            'youtube.com'   => 'youtube',
            'youtu.be'      => 'youtube',
            'tiktok.com'    => 'tiktok',
            'instagram.com' => 'instagram',
            'twitter.com'   => 'twitter',
            'x.com'         => 'twitter',
            'twimg.com'     => 'twitter',
            't.co'          => 'twitter',
            'facebook.com'  => 'facebook',
            'fb.watch'      => 'facebook',
            'vimeo.com'     => 'vimeo',
            'twitch.tv'     => 'twitch',
            'dailymotion.com' => 'dailymotion',
            'dai.ly'        => 'dailymotion',
            'reddit.com'    => 'reddit',
            'redd.it'       => 'reddit',
            'linkedin.com'  => 'linkedin',
            'snapchat.com'  => 'snapchat',
            'bilibili.com'  => 'bilibili',
        ];
        foreach ($map as $needle => $slug) {
            if (strpos($host, $needle) !== false) {
                return $slug;
            }
        }
        // Unknown host: strip a leading www. and return it as-is.
        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Strip tracking/share query parameters so the same video always yields the
     * same cache key. Platform-aware: YouTube keeps the essential `v` (and `t`)
     * params; for every other platform the path already identifies the video, so
     * all query parameters and the fragment are dropped. Also trims a trailing
     * slash-less fragment and normalizes the result.
     */
    public static function normalize_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return $url;
        }
        // "www.tiktok.com/@x/video/1" pasted without a scheme: assume https.
        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+(/|$)#i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            // Unparseable: left as-is for the cache key; UrlGuard::rejection()
            // refuses it before anything is fetched.
            return $url;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host   = $parts['host'];
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path   = isset($parts['path']) ? $parts['path'] : '';

        // Decide which query params (if any) to keep.
        $keep = [];
        $platform = self::detect_platform($url);
        if ($platform === 'youtube') {
            // youtube.com/watch?v=... needs v; keep t (timestamp) too. youtu.be
            // and /shorts/ carry the id in the path and need nothing.
            $keep = ['v', 't'];
        }

        $query = '';
        if (!empty($parts['query']) && $keep) {
            parse_str($parts['query'], $params);
            $kept = array_intersect_key($params, array_flip($keep));
            if ($kept) {
                $query = '?' . http_build_query($kept);
            }
        }

        return $scheme . '://' . $host . $port . $path . $query;
    }

    /**
     * True for any YouTube video link — www., m. (mobile share links) and music.
     * hosts, youtu.be, /shorts/, /live/, /embed/, and `v=` anywhere in the query.
     * Shares its URL parsing with the InnerTube caption path, so both always
     * agree on what counts as YouTube.
     */
    public function is_youtube_url($url) {
        return YouTubeCaptions::extract_video_id($url) !== '';
    }

    /**
     * Path of the YouTube cookies.txt in the private folder (see PrivateStorage;
     * it used to sit in the web-reachable plugin folder: GitHub AI Scan #5).
     * Adds the Netscape header yt-dlp needs if it's missing.
     *
     * @throws \Exception when there is no readable cookies file.
     */
    private function check_cookies_file($url) {
        $cookies_file = PrivateStorage::cookies_file();
        if (!is_readable($cookies_file)) {
            $this->logger->log("No readable cookies.txt in the private folder", 'error');
            throw new \Exception('YouTube authentication required: configure a proxy or place cookies.txt in the private folder (Settings → Fact Checker → System information).');
        }
        $content = (string) file_get_contents($cookies_file);
        if (strpos($content, '# Netscape HTTP Cookie File') === false) {
            $this->logger->log("Converting cookies to Netscape format");
            file_put_contents($cookies_file, "# Netscape HTTP Cookie File\n# https://curl.haxx.se/rfc/cookie_spec.html\n# This is a generated file!  Do not edit.\n\n" . $content);
        }
        $this->logger->log("Using cookies.txt (" . strlen($content) . " bytes)");
        return $cookies_file;
    }
}