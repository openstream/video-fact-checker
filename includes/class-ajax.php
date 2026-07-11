<?php
namespace VideoFactChecker;

class Ajax {
    private $logger;
    private $processor;
    private $transcriber;
    private $fact_checker;
    private $rate_limiter;

    public function __construct() {
        // Initialize all required services
        $this->logger = new Logger();
        $this->processor = new VideoProcessor();
        $this->transcriber = new TranscriptionService();
        $this->fact_checker = new FactChecker();
        $this->rate_limiter = new RateLimiter($this->logger);

        // Register Ajax actions
        add_action('wp_ajax_vfc_process_video', [$this, 'handle_process_video']);
        add_action('wp_ajax_nopriv_vfc_process_video', [$this, 'handle_process_video']);
        
        add_action('wp_ajax_vfc_update_status', [$this, 'handle_update_status']);
        add_action('wp_ajax_nopriv_vfc_update_status', [$this, 'handle_update_status']);
        
        add_action('wp_ajax_vfc_check_progress', [$this, 'handle_check_progress']);
        add_action('wp_ajax_nopriv_vfc_check_progress', [$this, 'handle_check_progress']);
    }

    public function handle_process_video() {
        try {
            // Verify services are initialized
            if (!$this->processor || !$this->transcriber || !$this->fact_checker) {
                throw new \Exception("Required services not initialized");
            }

            $this->logger->log("Starting video processing");
            
            // Verify nonce and URL
            if (!check_ajax_referer('vfc_nonce', 'nonce', false)) {
                $this->logger->log("Nonce verification failed");
                wp_send_json_error(['message' => 'Security check failed']);
                return;
            }

            if (!isset($_POST['url'])) {
                $this->logger->log("No URL provided");
                wp_send_json_error(['message' => 'No URL provided']);
                return;
            }

            $raw_url = sanitize_text_field($_POST['url']);
            $original_url = $raw_url;
            
            // Check for nocache parameter and remove it from the URL
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
                    if (empty($query_params)) {
                        // No more query parameters, remove the ? entirely
                        $url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . 
                               (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '') .
                               (isset($parsed_url['path']) ? $parsed_url['path'] : '');
                    } else {
                        // Rebuild with remaining query parameters
                        $url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . 
                               (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '') .
                               (isset($parsed_url['path']) ? $parsed_url['path'] : '') .
                               '?' . http_build_query($query_params);
                    }
                    
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
                    wp_send_json_success([
                        'transcription' => $cached_result['transcription'],
                        'analysis' => $cached_result['analysis'],
                        'short_url' => $cached_result['short_url'],
                        'cached' => true,
                        'cached_at' => $cached_at
                    ]);
                    return;
                }
            } else {
                $this->logger->log("Skipping cache check due to nocache parameter");
            }

            // Enforce the per-user daily limit before doing any expensive work.
            // YouTube (paid proxy) and other platforms are limited separately.
            $rate_bucket = $this->processor->is_youtube_url($url) ? 'youtube' : 'other';
            $this->rate_limiter->enforce($rate_bucket);

            // Process video
            $this->set_status('downloading');
            $audio_file = $this->processor->download_video($url);

            $this->set_status('transcribing');
            $transcription = $this->transcriber->transcribe($audio_file);

            if (file_exists($audio_file)) {
                unlink($audio_file);
            }

            $this->set_status('analyzing');
            $analysis = $this->fact_checker->check_facts($transcription);

            // Count this successful, resource-consuming run against the daily limit.
            // Cached hits return earlier and are intentionally not counted.
            $this->rate_limiter->record($rate_bucket);

            // Compute per-run cost metrics from the measured usage.
            $calc = new CostCalculator();
            $metrics = $calc->build_metrics(
                VideoProcessor::detect_platform($url), // e.g. youtube, tiktok, instagram
                $this->fact_checker->get_last_prompt_tokens(),
                $this->fact_checker->get_last_completion_tokens(),
                $this->processor->get_last_audio_seconds(),
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

            wp_send_json_success([
                'transcription' => $transcription,
                'analysis' => $analysis,
                'short_url' => $short_url,
                'cached' => false,
                'nocache' => $nocache
            ]);

        } catch (\Exception $e) {
            $this->logger->log("Error: " . $e->getMessage());
            $this->logger->log("Stack trace: " . $e->getTraceAsString());
            // Ensure progress checker stops polling
            $this->set_status('error');

            $ref = $this->logger->getCurrentVideoId();

            // Notify the admin by email (de-duplicated inside the notifier).
            try {
                $notifier = new Notifier($this->logger);
                $notifier->notify_error(
                    $e->getMessage(),
                    $e->getTraceAsString(),
                    isset($url) ? $url : '',
                    $ref
                );
            } catch (\Throwable $notifyError) {
                $this->logger->log("Failed to send admin error mail: " . $notifyError->getMessage(), 'error');
            }

            // Send minimal info to user, full details are in the log
            $error_payload = [
                'message' => $e->getMessage() . ' (Ref: ' . $ref . ')'
            ];
            wp_send_json_error($error_payload);
        }
    }

    public function handle_update_status() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vfc_nonce')) {
                wp_send_json_error(['message' => 'Security check failed']);
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
            if (!wp_verify_nonce($_POST['nonce'], 'vfc_nonce')) {
                wp_send_json_error(['message' => 'Security check failed']);
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