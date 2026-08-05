<?php
namespace VideoFactChecker;

class VideoProcessor {
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
        return [
            'transcript' => $r['transcript'],
            'duration'   => (int) $r['duration'],
            'title'      => isset($r['title']) ? $r['title'] : '',
            'source'     => $source,
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
        $json = json_decode(implode("\n", $output), true);
        if (is_array($json) && isset($json['duration']) && is_numeric($json['duration'])) {
            return (int) round((float) $json['duration']);
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

    public function download_video($url) {
        // Reset per-run metrics.
        $this->last_download_bytes = 0;
        $this->last_audio_seconds = 0.0;
        $this->last_is_youtube = $this->is_youtube_url($url);
        $this->last_title = '';

        try {
            // Quick dependency check for clearer error messages
            try {
                $this->check_dependencies();
            } catch (\Exception $depEx) {
                throw new \Exception('Dependency check failed: ' . $depEx->getMessage());
            }

            $this->logger->log("=== Starting download with debug info ===");
            
            // Get plugin directory and temp directory paths
            $plugin_dir = plugin_dir_path(dirname(__FILE__));
            $output_dir = $plugin_dir . 'temp';
            
            $this->logger->log("Plugin directory: " . $plugin_dir);
            $this->logger->log("Temp directory: " . $output_dir);
            
            // Check plugin directory
            $plugin_perms = substr(sprintf('%o', fileperms($plugin_dir)), -4);
            $plugin_owner = posix_getpwuid(fileowner($plugin_dir));
            $plugin_group = posix_getgrgid(filegroup($plugin_dir));
            
            $this->logger->log("Plugin directory status:");
            $this->logger->log("- Permissions: " . $plugin_perms);
            $this->logger->log("- Owner: " . ($plugin_owner['name'] ?? 'unknown'));
            $this->logger->log("- Group: " . ($plugin_group['name'] ?? 'unknown'));
            
            // Get current process info
            $current_user = posix_getpwuid(posix_geteuid());
            $this->logger->log("Process running as: " . $current_user['name']);
            
            // Create temp directory with proper permissions
            if (!file_exists($output_dir)) {
                $this->logger->log("Creating temp directory with proper permissions");
                
                // First ensure plugin directory is writable
                if (!is_writable($plugin_dir)) {
                    // Attempt to fix plugin directory permissions
                    $this->logger->log("Attempting to fix plugin directory permissions");
                    if (!@chmod($plugin_dir, 0775)) {
                        throw new \Exception(sprintf(
                            "Cannot set plugin directory permissions: %s (current: %s, owner: %s)",
                            $plugin_dir,
                            $plugin_perms,
                            $plugin_owner['name'] ?? 'unknown'
                        ));
                    }
                }
                
                // Create temp directory
                if (!@mkdir($output_dir, 0775)) {
                    $error = error_get_last();
                    throw new \Exception(sprintf(
                        "Failed to create temp directory: %s (error: %s)",
                        $output_dir,
                        $error['message'] ?? 'Unknown error'
                    ));
                }
            }
            
            // Verify temp directory permissions
            $temp_perms = substr(sprintf('%o', fileperms($output_dir)), -4);
            $temp_owner = posix_getpwuid(fileowner($output_dir));
            $temp_group = posix_getgrgid(filegroup($output_dir));
            
            $this->logger->log("Temp directory status:");
            $this->logger->log("- Permissions: " . $temp_perms);
            $this->logger->log("- Owner: " . ($temp_owner['name'] ?? 'unknown'));
            $this->logger->log("- Group: " . ($temp_group['name'] ?? 'unknown'));
            
            // Test write access
            $test_file = $output_dir . '/.test';
            if (@file_put_contents($test_file, 'test') === false) {
                throw new \Exception(sprintf(
                    "Cannot write to temp directory: %s (perms: %s, owner: %s)",
                    $output_dir,
                    $temp_perms,
                    $temp_owner['name'] ?? 'unknown'
                ));
            }
            
            // Verify directory permissions
            $perms = substr(sprintf('%o', fileperms($output_dir)), -4);
            $this->logger->log("Directory permissions: " . $perms);
            
            // Get directory ownership
            $owner = posix_getpwuid(fileowner($output_dir));
            $group = posix_getgrgid(filegroup($output_dir));
            $this->logger->log("Directory owner: " . $owner['name']);
            $this->logger->log("Directory group: " . $group['name']);
            
            // Ensure directory is writable
            if (!is_writable($output_dir)) {
                throw new \Exception("Temp directory is not writable: " . $output_dir);
            }
            
            // Create unique filename
            $uniq = uniqid('audio_');
            $filename = $uniq . '.%(ext)s';
            $output_file = $output_dir . '/' . $filename;
            $this->logger->log("Output file template: " . $output_file);

            // Sidecar file to capture the video title in the same yt-dlp run
            // (via --print-to-file), so we get it without an extra request.
            $title_file = $output_dir . '/' . $uniq . '.title';
            $title_opt = '--print-to-file ' . escapeshellarg('%(title)s') . ' ' . escapeshellarg($title_file);

            // Bound yt-dlp's own retries/timeout so a stuck proxy node fails fast;
            // our outer retry loop then re-runs (often getting a fresh proxy IP).
            $net_opts = '--retries 3 --socket-timeout 20';
            
            // Verify YouTube URL detection
            $is_youtube = $this->is_youtube_url($url);
            $this->logger->log("Is YouTube URL: " . ($is_youtube ? 'yes' : 'no'));
            
            $proxy = $this->build_youtube_proxy();
            if (!empty($proxy)) {
                $this->logger->log("Proxy configured: YES | " . $this->describe_proxy($proxy));
            } else {
                $this->logger->log("Proxy configured: NO");
            }

            if ($is_youtube) {
                if ($proxy) {
                    $this->logger->log("Using proxy for YouTube download");
                    // Explicitly select an audio-only stream so we never pull the
                    // (much larger) video track through the metered proxy. YouTube
                    // almost always offers a separate audio stream; the fallbacks
                    // only kick in if it genuinely doesn't.
                    $command = sprintf(
                        'yt-dlp --proxy %s %s -f %s -x --audio-format mp3 --audio-quality 0 %s -o %s %s 2>&1',
                        escapeshellarg($proxy),
                        $net_opts,
                        escapeshellarg('bestaudio/best'),
                        $title_opt,
                        escapeshellarg($output_file),
                        escapeshellarg($url)
                    );
                } else {
                    $this->logger->log("=== Checking cookies for YouTube URL ===");
                    $cookies_file = plugin_dir_path(dirname(__FILE__)) . 'cookies.txt';
                    
                    if (file_exists($cookies_file)) {
                        $this->logger->log("Found cookies file. Size: " . filesize($cookies_file) . " bytes");
                        
                        // Verify Netscape format
                        $content = file_get_contents($cookies_file);
                        if (strpos($content, '# Netscape HTTP Cookie File') === false) {
                            $this->logger->log("Converting cookies to Netscape format");
                            $content = "# Netscape HTTP Cookie File\n# https://curl.haxx.se/rfc/cookie_spec.html\n# This is a generated file!  Do not edit.\n\n" . $content;
                            file_put_contents($cookies_file, $content);
                        }
                        
                        // Audio-only selection (see the proxy branch above) — keeps
                        // downloads small even on the cookies path.
                        $command = sprintf(
                            'yt-dlp --cookies %s %s -f %s -x --audio-format mp3 --audio-quality 0 %s -o %s %s 2>&1',
                            escapeshellarg($cookies_file),
                            $net_opts,
                            escapeshellarg('bestaudio/best'),
                            $title_opt,
                            escapeshellarg($output_file),
                            escapeshellarg($url)
                        );
                    } else {
                        throw new \Exception('YouTube authentication required: cookies.txt not found');
                    }
                }
            } else {
                // Non-YouTube URL - don't use cookies and don't apply proxy.
                //
                // Format selection matters here: some TikTok videos advertise an audio
                // codec on every format but actually hand out a silent HEVC (bytevc1)
                // stream on download, which makes "-x" fail with
                // "unable to obtain file audio codec with ffprobe". TikTok's combined
                // "download" format (h264 + aac) does carry real audio, so prefer it,
                // then fall back to bestaudio / best for all other platforms.
                $command = sprintf(
                    'yt-dlp %s -f %s -x --audio-format mp3 --audio-quality 0 %s -o %s %s 2>&1',
                    $net_opts,
                    escapeshellarg('download/bestaudio/best'),
                    $title_opt,
                    escapeshellarg($output_file),
                    escapeshellarg($url)
                );
            }

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

                if ($return_var === 0) {
                    break;
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

            if ($return_var !== 0) {
                $error_output = !empty($output) ? implode("\n", $output) : 'No output';

                // The full yt-dlp output (progress bars, format lines, stack noise) is
                // already written to the log above. Never surface it in the UI — instead
                // map it to a single concise, human-readable message.
                $message = $this->summarize_download_error($error_output);

                // Keep the raw output in the log for debugging, tied to the request id.
                $this->logger->log("Download failed (exit {$return_var}): " . $error_output, 'error');

                // Carry the real reason (a concise error line) into the admin email
                // via a structured exception, instead of a useless stack trace.
                $stage = $this->last_is_youtube
                    ? 'YouTube audio download (yt-dlp' . ($this->build_youtube_proxy() !== '' ? ' via proxy)' : ')')
                    : 'Video download (yt-dlp)';
                throw new ProcessingException(
                    $message,
                    $stage,
                    $this->download_error_technical($error_output)
                );
            }

            // Find the generated MP3 file
            $mp3_file = str_replace('%(ext)s', 'mp3', $output_file);
            $this->logger->log("Looking for MP3 file: " . $mp3_file);
            
            if (!file_exists($mp3_file)) {
                throw new \Exception('Audio file not found: ' . $mp3_file);
            }
            
            $this->logger->log("Found MP3 file: " . $mp3_file);

            // Read the captured video title (sidecar file), then remove it.
            if (isset($title_file) && file_exists($title_file)) {
                $t = trim((string) @file_get_contents($title_file));
                @unlink($title_file);
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
            $downloaded = $this->parse_downloaded_bytes(isset($output) && is_array($output) ? implode("\n", $output) : '');
            if ($downloaded <= 0) {
                $downloaded = (int) @filesize($mp3_file);
            }
            $this->last_download_bytes = $downloaded;
            $this->logger->log(sprintf(
                "Run metrics: audio_seconds=%.2f download_bytes=%d youtube=%s",
                $this->last_audio_seconds, $this->last_download_bytes, $this->last_is_youtube ? 'yes' : 'no'
            ));

            return $mp3_file;

        } catch (\Exception $e) {
            $this->logger->log("=== Error in download_video ===");
            $this->logger->log("Error message: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function get_video_info($url) {
        $proxy = $this->build_youtube_proxy();
        if (!empty($proxy)) {
            $this->logger->log("Proxy configured for info: YES | " . $this->describe_proxy($proxy));
        } else {
            $this->logger->log("Proxy configured for info: NO");
        }
        if ($this->is_youtube_url($url)) {
            if ($proxy) {
                $command = sprintf('yt-dlp --proxy %s --dump-json %s 2>&1',
                    escapeshellarg($proxy),
                    escapeshellarg($url)
                );
            } else {
                $cookies_file = $this->check_cookies_file($url);
                $command = sprintf('yt-dlp --cookies %s --dump-json %s 2>&1',
                    escapeshellarg($cookies_file),
                    escapeshellarg($url)
                );
            }
        } else {
            // Non-YouTube: do not apply proxy
            $command = sprintf('yt-dlp --dump-json %s 2>&1', escapeshellarg($url));
        }
        
        return $command;
    }

    private function build_youtube_proxy() {
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
        if (stripos($error_output, 'Unsupported URL') !== false || stripos($error_output, 'is not a valid URL') !== false) {
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
        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url; // unparseable — leave as-is
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
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

    public function is_youtube_url($url) {
        $this->logger->log("=== Checking if URL is YouTube ===");
        $this->logger->log("URL to check: " . $url);
        
        // More strict YouTube URL validation
        $patterns = [
            '#^https?://(?:www\.)?youtube\.com/watch\?v=[\w-]+#',
            '#^https?://(?:www\.)?youtube\.com/v/[\w-]+#',
            '#^https?://youtu\.be/[\w-]+#',
            '#^https?://(?:www\.)?youtube\.com/embed/[\w-]+#',
            '#^https?://(?:www\.)?youtube\.com/shorts/[\w-]+#'  // Added shorts pattern
        ];
        
        foreach ($patterns as $pattern) {
            $this->logger->log("Checking pattern: " . $pattern);
            if (preg_match($pattern, $url)) {
                $this->logger->log("✓ Matched YouTube pattern");
                return true;
            }
        }
        
        $this->logger->log("✗ No YouTube patterns matched");
        return false;
    }

    private function check_cookies_file($url) {
        $this->logger->log("DEBUG: Starting cookie file check");
        
        if (!$this->is_youtube_url($url)) {
            $this->logger->log("DEBUG: Not a YouTube URL, skipping cookie check");
            return null;
        }

        // Try both possible paths
        $paths = [
            plugin_dir_path(dirname(__FILE__)) . 'cookies.txt',
            WP_PLUGIN_DIR . '/video-fact-checker/cookies.txt',
            '/var/www/html/wp-content/plugins/video-fact-checker/cookies.txt'
        ];
        
        $this->logger->log("DEBUG: Will check these paths:");
        foreach ($paths as $path) {
            $this->logger->log("DEBUG: - " . $path);
        }
        
        foreach ($paths as $cookies_file) {
            $this->logger->log("DEBUG: Checking: " . $cookies_file);
            
            try {
                if (file_exists($cookies_file)) {
                    $this->logger->log("DEBUG: ✓ File exists");
                    $this->logger->log("DEBUG: File size: " . filesize($cookies_file) . " bytes");
                    $this->logger->log("DEBUG: Permissions: " . substr(sprintf('%o', fileperms($cookies_file)), -4));
                    
                    if (function_exists('posix_getpwuid')) {
                        $owner = posix_getpwuid(fileowner($cookies_file));
                        $group = posix_getgrgid(filegroup($cookies_file));
                        $this->logger->log("DEBUG: Owner: " . ($owner['name'] ?? 'unknown'));
                        $this->logger->log("DEBUG: Group: " . ($group['name'] ?? 'unknown'));
                    }
                    
                    if (is_readable($cookies_file)) {
                        $this->logger->log("DEBUG: ✓ File is readable");
                        $content = file_get_contents($cookies_file);
                        if ($content === false) {
                            $this->logger->log("DEBUG: ✗ Could not read file content");
                            continue;
                        }
                        $this->logger->log("DEBUG: Content preview: " . substr($content, 0, 100));
                        return $cookies_file;
                    } else {
                        $this->logger->log("DEBUG: ✗ File is not readable");
                    }
                } else {
                    $this->logger->log("DEBUG: ✗ File does not exist");
                }
            } catch (\Exception $e) {
                $this->logger->log("DEBUG: Error checking " . $cookies_file . ": " . $e->getMessage());
            }
        }
        
        $error_msg = 'YouTube authentication failed: No readable cookies.txt found. ' .
                     'Checked paths: ' . implode(', ', $paths);
        $this->logger->log("DEBUG: " . $error_msg);
        throw new \Exception($error_msg);
    }
}