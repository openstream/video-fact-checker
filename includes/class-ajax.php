<?php
namespace VideoFactChecker;

class Ajax {
    private $logger;
    private $processor;
    private $transcriber;
    private $fact_checker;

    public function __construct() {
        // Initialize all required services
        $this->logger = new Logger();
        $this->processor = new VideoProcessor();
        $this->transcriber = new TranscriptionService();
        $this->fact_checker = new FactChecker();

        // Register Ajax actions
        add_action('wp_ajax_vfc_process_video', [$this, 'handle_process_video']);
        add_action('wp_ajax_nopriv_vfc_process_video', [$this, 'handle_process_video']);
        
        add_action('wp_ajax_vfc_update_status', [$this, 'handle_update_status']);
        add_action('wp_ajax_nopriv_vfc_update_status', [$this, 'handle_update_status']);
        
        add_action('wp_ajax_vfc_check_progress', [$this, 'handle_check_progress']);
        add_action('wp_ajax_nopriv_vfc_check_progress', [$this, 'handle_check_progress']);

        add_action('wp_ajax_vfc_refresh_nonce', [$this, 'handle_refresh_nonce']);
        add_action('wp_ajax_nopriv_vfc_refresh_nonce', [$this, 'handle_refresh_nonce']);

        add_action('wp_ajax_vfc_preflight', [$this, 'handle_preflight']);
        add_action('wp_ajax_nopriv_vfc_preflight', [$this, 'handle_preflight']);

        add_action('wp_ajax_vfc_premium_feedback', [$this, 'handle_premium_feedback']);
        add_action('wp_ajax_nopriv_vfc_premium_feedback', [$this, 'handle_premium_feedback']);
    }

    /**
     * Yes/No answer to "would you pay to check longer videos?", shown when a
     * video is too long. Goes to Slack; one answer per visitor and video per day.
     */
    public function handle_premium_feedback() {
        if (!check_ajax_referer('vfc_nonce', 'nonce', false)) {
            $this->send_invalid_nonce();
            return;
        }
        $answer = isset($_POST['answer']) ? sanitize_key($_POST['answer']) : '';
        if (!in_array($answer, ['yes', 'no'], true) || (new BotGuard($this->logger))->is_honeypot_filled()) {
            wp_send_json_error(['message' => 'Invalid answer.']);
            return;
        }
        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $once = 'vfc_premium_fb_' . md5(wp_salt('nonce') . $ip . '|' . $url);
        if (!get_transient($once)) {
            set_transient($once, 1, DAY_IN_SECONDS);
            $this->logger->log("Premium feedback: {$answer} for {$url}");
            try {
                (new Notifier($this->logger))->notify_premium_feedback($answer === 'yes', $url, Geo::current_country_code());
            } catch (\Throwable $e) {
                $this->logger->log("Failed to send premium feedback: " . $e->getMessage(), 'error');
            }
        }
        wp_send_json_success(['thanks' => true]);
    }

    /**
     * Quick check before a run starts, so a video we'd refuse anyway (YouTube
     * without subtitles and over the download limit) is declined up front instead
     * of failing minutes later. Asks YouTube directly, then via the proxy.
     * Answers {ok:true} whenever it can't tell (not YouTube, cached, no answer
     * either way) — the run itself and yt-dlp's --match-filter still enforce it.
     */
    public function handle_preflight() {
        if (!check_ajax_referer('vfc_nonce', 'nonce', false)) {
            $this->send_invalid_nonce();
            return;
        }
        if (!isset($_POST['url']) || (new BotGuard($this->logger))->is_honeypot_filled()) {
            wp_send_json_success(['ok' => true]);
            return;
        }

        list($url, $nocache) = $this->clean_url(sanitize_text_field($_POST['url']));
        if (!$this->processor->is_youtube_url($url)
            || !(bool) get_option('vfc_youtube_use_innertube', true)
            || (!$nocache && (new CacheManager())->get_cached_result($url))) {
            wp_send_json_success(['ok' => true]);
            return;
        }

        $video_id = YouTubeCaptions::extract_video_id($url);
        $captions = new YouTubeCaptions($this->logger);
        $probe = $video_id !== '' ? $captions->probe($video_id) : null;
        if ($probe === null && $video_id !== '') {
            // Refused to this server (LOGIN_REQUIRED is per video): ask via the proxy.
            $proxy = $this->processor->build_youtube_proxy();
            if ($proxy !== '') {
                $probe = $captions->probe($video_id, $proxy);
            }
        }
        $rejection = ($probe && !$probe['has_captions'])
            ? $this->no_caption_rejection($probe['duration'])
            : null;
        if (!$rejection) {
            wp_send_json_success(['ok' => true]);
            return;
        }

        $this->logger->log("Preflight declined {$url}: " . $rejection->get_technical());
        try {
            (new Notifier($this->logger))->notify_declined(
                $rejection->get_user_message(),
                $rejection->get_technical(),
                $url,
                Geo::current_country_code()
            );
        } catch (\Throwable $e) {
            $this->logger->log("Failed to send decline notification: " . $e->getMessage(), 'error');
        }
        wp_send_json_success(['ok' => false, 'too_long' => true, 'message' => $rejection->get_user_message()]);
    }

    /**
     * The rule for YouTube videos without subtitles: only the audio-download
     * fallback can handle them, and only up to vfc_youtube_max_download_minutes.
     * Returns the exception to throw, or null when the video may be downloaded
     * (an unknown duration of 0 is let through).
     */
    private function no_caption_rejection($duration) {
        $limit_min = (int) get_option('vfc_youtube_max_download_minutes', VideoProcessor::DEFAULT_MAX_DOWNLOAD_MINUTES);
        if ($limit_min <= 0) {
            return new ProcessingException(
                "This YouTube video has no subtitles, so it can't be fact-checked automatically.",
                "YouTube: no captions, audio-download fallback disabled",
                "NO_CAPTIONS and vfc_youtube_max_download_minutes=0"
            );
        }
        if ($duration > $limit_min * 60) {
            return new ProcessingException(
                sprintf("This YouTube video has no subtitles and is too long (%s) to transcribe automatically. Please try a shorter video (up to %d minutes).", self::format_duration($duration), $limit_min),
                "YouTube: no captions, over the {$limit_min}-min download limit",
                sprintf("NO_CAPTIONS and duration=%ds > limit=%ds", $duration, $limit_min * 60)
            );
        }
        return null;
    }

    /** 754 → "12:34", 22258 → "6:10:58". */
    private static function format_duration($seconds) {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /**
     * Strip the nocache flag and tracking params from a submitted URL.
     *
     * @return array{0:string,1:bool} [cleaned URL, nocache requested]
     */
    private function clean_url($raw_url) {
        $nocache = false;
        $url = $raw_url;

        // Parse URL to handle nocache parameter robustly
        $parsed_url = parse_url($raw_url);
        if (isset($parsed_url['query'])) {
            $query_params = [];
            parse_str($parsed_url['query'], $query_params);

            if (isset($query_params['nocache'])) {
                $nocache = true;
                unset($query_params['nocache']);

                // Rebuild URL without nocache parameter
                $url = $parsed_url['scheme'] . '://' . $parsed_url['host'] .
                       (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '') .
                       (isset($parsed_url['path']) ? $parsed_url['path'] : '') .
                       (empty($query_params) ? '' : '?' . http_build_query($query_params));

                $this->logger->log("Cache bypass requested for URL: " . $url);
            }
        }

        // Strip tracking/share query params so the cache key is stable across
        // the many URL variants of the same video (utm_*, share_*, _t, igsh, …).
        $normalized_url = VideoProcessor::normalize_url($url);
        if ($normalized_url !== $url) {
            $this->logger->log("Normalized URL: " . $url . " -> " . $normalized_url);
            $url = $normalized_url;
        }
        return [$url, $nocache];
    }

    /**
     * Hands the browser a fresh nonce. A tab that iOS Safari restores from memory
     * hours later still carries the nonce from the original page load, which
     * WordPress rejects after 12-24 h; the JS calls this and retries once instead
     * of showing "Security check failed".
     */
    public function handle_refresh_nonce() {
        nocache_headers();
        wp_send_json_success(['nonce' => wp_create_nonce('vfc_nonce')]);
    }

    /** Error response for a stale/invalid nonce; the JS refreshes and retries on this code. */
    private function send_invalid_nonce() {
        wp_send_json_error([
            'code' => 'invalid_nonce',
            'message' => 'Your session has expired. Please reload the page and try again.',
        ]);
    }

    /** Slack/mail note for a finished check; never lets a notifier problem break the response. */
    private function notify_check(array $details) {
        try {
            (new Notifier($this->logger))->notify_check($details);
        } catch (\Throwable $e) {
            $this->logger->log("Failed to send check notification: " . $e->getMessage(), 'error');
        }
    }

    public function handle_process_video() {
        $started = microtime(true);
        $country_code = '';
        try {
            // Verify services are initialized
            if (!$this->processor || !$this->transcriber || !$this->fact_checker) {
                throw new \Exception("Required services not initialized");
            }

            $this->logger->log("Starting video processing");
            
            // Verify nonce and URL
            if (!check_ajax_referer('vfc_nonce', 'nonce', false)) {
                $this->logger->log("Nonce verification failed");
                $this->send_invalid_nonce();
                return;
            }

            if (!isset($_POST['url'])) {
                $this->logger->log("No URL provided");
                wp_send_json_error(['message' => 'No URL provided']);
                return;
            }

            $guard = new BotGuard($this->logger);
            if ($guard->is_honeypot_filled()) {
                $this->logger->log("Bot guard: honeypot filled, request rejected", 'error');
                wp_send_json_error(['code' => 'rejected', 'message' => 'Your request could not be processed.']);
                return;
            }

            // Capture the requester's country now, from the live request's IP.
            // Best-effort; '' when it can't be resolved (see Geo). Stored per run.
            $country_code = Geo::current_country_code();

            list($url, $nocache) = $this->clean_url(sanitize_text_field($_POST['url']));

            // Correlate this run
            $request_id = substr(md5($url . microtime(true) . wp_rand()), 0, 8);
            $this->logger->assignRequestId($request_id);
            $this->logger->logVideoMetadata($url);
            $this->logger->log("Processing URL: " . $url);

            // Check cache (unless nocache is requested)
            $cache_manager = new CacheManager();
            $cached_result = null;
            
            if (!$nocache) {
                $cached_result = $cache_manager->get_cached_result($url);
                if ($cached_result) {
                    $this->logger->log("Returning cached result with short URL: " . $cached_result['short_url']);
                    $cached_at = '';
                    if (!empty($cached_result['created_at'])) {
                        $ts = strtotime($cached_result['created_at']);
                        if ($ts) {
                            $cached_at = date_i18n(
                                get_option('date_format') . ' ' . get_option('time_format'),
                                $ts
                            );
                        }
                    }
                    $cached_row = $cache_manager->get_by_short_url($cached_result['short_url']);
                    $this->notify_check([
                        'url'       => $url,
                        'share_url' => home_url('/share/' . $cached_result['short_url'] . '/'),
                        'title'     => $cached_row && !empty($cached_row->video_title) ? $cached_row->video_title : '',
                        'summary'   => $cached_row && !empty($cached_row->meta_description) ? $cached_row->meta_description : '',
                        'platform'  => VideoProcessor::detect_platform($url),
                        'cached'    => true,
                        'cached_at' => $cached_at,
                        'model'     => isset($cached_result['used_model']) ? $cached_result['used_model'] : '',
                        'seconds'   => microtime(true) - $started,
                        'country'   => $country_code,
                        'ref'       => $request_id,
                    ]);
                    wp_send_json_success([
                        'transcription' => $cached_result['transcription'],
                        'analysis' => $cached_result['analysis'],
                        'short_url' => $cached_result['short_url'],
                        'cached' => true,
                        'cached_at' => $cached_at,
                        'model' => isset($cached_result['used_model']) ? $cached_result['used_model'] : '',
                        // Full result markup (TOC + transcript at the end), same as
                        // the /share/ page, so the inline view matches it. Render
                        // from the full DB row (get_cached_result omits video_url /
                        // video_title, which the template needs).
                        'result_html' => vfc_render_result_html($cached_row ?: $cached_result),
                    ]);
                    return;
                }
            } else {
                $this->logger->log("Skipping cache check due to nocache parameter");
            }

            // A fresh check costs money: count it, or refuse when a limit is hit.
            $limit_message = $guard->claim_fresh_check();
            if ($limit_message !== '') {
                $this->set_status('error');
                wp_send_json_error(['code' => 'rate_limited', 'message' => $limit_message]);
                return;
            }

            // Obtain a transcript. For YouTube we first try captions (InnerTube),
            // which needs no audio download and no Whisper; only if that fails do
            // we download the audio and transcribe it. Other platforms download
            // and transcribe as before.
            $transcription = null;
            $used_captions = false;
            $transcript_source = 'download+whisper';
            $lang = ''; // content language, for <html lang> on the share page

            if ($this->processor->is_youtube_url($url)) {
                $this->set_status('downloading'); // shown as "fetching" to the user
                $caption = $this->processor->try_youtube_captions($url);
                if ($caption !== null && trim($caption['transcript']) !== '') {
                    $transcription = $caption['transcript'];
                    $used_captions = true;
                    $transcript_source = $caption['source']; // innertube-direct | innertube-proxy
                    $lang = isset($caption['lang']) ? $caption['lang'] : ''; // from the caption track
                } else {
                    // No captions: fall back to audio download + Whisper, but only
                    // for videos within the configured length limit.
                    $duration = $this->processor->get_last_caption_duration();
                    if ($duration <= 0) {
                        $duration = $this->processor->get_video_duration_seconds($url);
                    }
                    $rejection = $this->no_caption_rejection($duration);
                    if ($rejection) {
                        throw $rejection;
                    }
                    $audio_file = $this->processor->download_video($url);
                    $this->set_status('transcribing');
                    $transcription = $this->transcriber->transcribe($audio_file);
                    if (file_exists($audio_file)) {
                        unlink($audio_file);
                    }
                }
            } else {
                $this->set_status('downloading');
                $audio_file = $this->processor->download_video($url);
                $this->set_status('transcribing');
                $transcription = $this->transcriber->transcribe($audio_file);
                if (file_exists($audio_file)) {
                    unlink($audio_file);
                }
            }

            $this->logger->log("Transcript source: " . $transcript_source);

            // Music and other near-speechless videos: stop before the analysis.
            $speech_seconds = $used_captions
                ? $this->processor->get_last_caption_duration()
                : $this->processor->get_last_audio_seconds();
            $speech = SpeechCheck::analyze($transcription, (float) $speech_seconds);
            $this->logger->log(sprintf(
                "Speech check: words=%d wpm=%s distinct=%.2f%s",
                $speech['words'], $speech['wpm'] === null ? '?' : $speech['wpm'], $speech['unique'],
                $speech['ok'] ? '' : ' -> rejected (' . $speech['reason'] . ')'
            ));
            if (!$speech['ok']) {
                throw new ProcessingException(
                    "This video contains too little speech to fact-check (music or mostly silent?). Please try a video in which someone talks.",
                    "Too little speech",
                    "LOW_SPEECH: " . $speech['reason']
                );
            }

            $this->set_status('analyzing');
            $analysis = $this->fact_checker->check_facts($transcription);

            // Compute per-run cost metrics from the measured usage. When captions
            // were used there was no Whisper transcription, so bill 0 audio
            // seconds regardless of the video's length.
            $calc = new CostCalculator();
            $whisper_seconds = $used_captions ? 0 : $this->processor->get_last_audio_seconds();
            $metrics = $calc->build_metrics(
                VideoProcessor::detect_platform($url), // e.g. youtube, tiktok, instagram
                $this->fact_checker->get_last_prompt_tokens(),
                $this->fact_checker->get_last_completion_tokens(),
                $whisper_seconds,
                $this->processor->get_last_download_bytes(),
                $this->processor->get_last_is_youtube(),
                $this->fact_checker->get_model()
            );
            $this->logger->log(sprintf(
                "Run cost: openai=$%.4f whisper=$%.4f proxy=$%.4f total=$%.4f",
                $metrics['openai_cost'], $metrics['whisper_cost'], $metrics['proxy_cost'], $metrics['total_cost']
            ));

            // Store the captured video title (if any) alongside the metrics.
            $title = $this->processor->get_last_title();
            if ($title !== '') {
                $metrics['video_title'] = $title;
            }
            // Record the content language (for <html lang> on the share page).
            // Prefer the caption track's language; otherwise detect it from the
            // transcript.
            if ($lang === '') {
                $lang = \VideoFactChecker\FactChecker::detect_language($transcription);
            }
            if ($lang !== '') {
                $metrics['lang'] = $lang;
            }
            // Record which model actually produced the analysis (may be the fallback).
            $used_model = $this->fact_checker->get_used_model();
            if ($used_model !== '') {
                $metrics['used_model'] = $used_model;
            }
            // Store the ≤160-char summary for the share page's meta description.
            $meta_description = $this->fact_checker->get_last_meta_description();
            if ($meta_description !== '') {
                $metrics['meta_description'] = $meta_description;
            }
            // Record the requester's country (best-effort; may be '').
            if ($country_code !== '') {
                $metrics['country_code'] = $country_code;
            }

            // Cache result (unless nocache is requested)
            $short_url = null;
            if (!$nocache) {
                $short_url = $cache_manager->cache_result($url, $transcription, $analysis, $metrics);
                $this->logger->log("Generated short URL: " . $short_url);

                if (!$short_url) {
                    throw new \Exception("Failed to cache result");
                }
            } else {
                $this->logger->log("Skipping cache storage due to nocache parameter");
                // Generate a temporary short URL for display purposes
                $short_url = 'temp_' . substr(md5($url . time()), 0, 6);
            }

            $this->set_status('complete');

            // Check the daily budget now that this run's cost is persisted.
            // (Only meaningful for cached runs, which are the ones written to the DB;
            // deduplicated to one alert per day inside the notifier.)
            if (!$nocache) {
                try {
                    (new Notifier($this->logger))->check_daily_budget();
                } catch (\Throwable $budgetError) {
                    $this->logger->log("Budget check failed: " . $budgetError->getMessage(), 'error');
                }
            }

            // Build a result object so the inline view renders via the same
            // template as the /share/ page (TOC + transcript at the end).
            $result_obj = (object) [
                'analysis'      => $analysis,
                'transcription' => $transcription,
                'video_url'     => $url,
                'video_title'   => isset($metrics['video_title']) ? $metrics['video_title'] : '',
                'created_at'    => current_time('mysql'),
            ];

            $this->notify_check([
                'url'       => $url,
                'share_url' => $nocache ? '' : home_url('/share/' . $short_url . '/'),
                'title'     => isset($metrics['video_title']) ? $metrics['video_title'] : '',
                'summary'   => $meta_description,
                'platform'  => VideoProcessor::detect_platform($url),
                'nocache'   => $nocache,
                'model'     => $used_model,
                'source'    => $transcript_source,
                'cost'      => isset($metrics['total_cost']) ? (float) $metrics['total_cost'] : null,
                'seconds'   => microtime(true) - $started,
                'lang'      => $lang,
                'country'   => $country_code,
                'ref'       => $request_id,
            ]);

            wp_send_json_success([
                'transcription' => $transcription,
                'analysis' => $analysis,
                'short_url' => $short_url,
                'cached' => false,
                'nocache' => $nocache,
                'model' => $used_model,
                'result_html' => vfc_render_result_html($result_obj),
            ]);

        } catch (\Exception $e) {
            // Ensure progress checker stops polling
            $this->set_status('error');

            $ref = $this->logger->getCurrentVideoId();

            // Pull structured detail out of our own exceptions; for anything else
            // fall back to the message and (as a last resort) the stack trace.
            if ($e instanceof ProcessingException) {
                $user_message = $e->get_user_message();
                $stage        = $e->get_stage();
                $technical    = $e->get_technical();
            } else {
                $user_message = $e->getMessage();
                $stage        = '';
                $technical    = '';
            }

            $this->logger->log("Error [{$stage}]: {$user_message}", 'error');
            if ($technical !== '') {
                $this->logger->log("Reason: {$technical}", 'error');
            } else {
                // Only keep the noisy trace when we have no better detail.
                $this->logger->log("Stack trace: " . $e->getTraceAsString());
            }

            // Notify the admin via Slack or email (de-duplicated inside the notifier).
            try {
                $notifier = new Notifier($this->logger);
                $notifier->notify_error(
                    $user_message,
                    $technical !== '' ? $technical : $e->getTraceAsString(),
                    isset($url) ? $url : '',
                    $ref,
                    $stage,
                    ['seconds' => microtime(true) - $started, 'country' => $country_code]
                );
            } catch (\Throwable $notifyError) {
                $this->logger->log("Failed to send admin error mail: " . $notifyError->getMessage(), 'error');
            }

            // Send the concise, human-friendly message to the user.
            // Over the length limit: the page offers the premium-interest question.
            // Too little speech is the video's nature, not a fault to look into.
            $code = '';
            if (strpos($stage, 'download limit') !== false) {
                $code = 'too_long';
            } elseif ($stage === 'Too little speech') {
                $code = 'no_speech';
            }
            wp_send_json_error([
                'code' => $code,
                'message' => $user_message . ' (Ref: ' . $ref . ')'
            ]);
        }
    }

    public function handle_update_status() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vfc_nonce')) {
                $this->send_invalid_nonce();
                return;
            }

            $status = sanitize_text_field($_POST['status']);
            // Your status update logic here...
            wp_send_json_success(['status' => $status]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handle_check_progress() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vfc_nonce')) {
                $this->send_invalid_nonce();
                return;
            }

            $status = get_option('vfc_current_status', 'starting');
            $progress = $this->get_progress_for_status($status);
            
            $this->logger->log("Progress check - Status: $status, Progress: $progress%");
            
            wp_send_json_success([
                'status' => $status,
                'progress' => $progress
            ]);
        } catch (\Exception $e) {
            $this->logger->log("Error in progress check: " . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function set_status($status) {
        $this->logger->log("Setting status to: " . $status);
        update_option('vfc_current_status', $status);
        $progress = $this->get_progress_for_status($status);
        $this->logger->log("Progress: $progress%");
    }

    private function get_progress_for_status($status) {
        $progress_map = [
            'starting' => 0,
            'downloading' => 25,
            'transcribing' => 50,
            'analyzing' => 75,
            'complete' => 100,
            'error' => 100
        ];
        return isset($progress_map[$status]) ? $progress_map[$status] : 0;
    }
}