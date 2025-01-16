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

            $url = sanitize_text_field($_POST['url']);
            $this->logger->log("Processing URL: " . $url);

            // Check cache
            $cache_manager = new CacheManager();
            $cached_result = $cache_manager->get_cached_result($url);

            if ($cached_result) {
                $this->logger->log("Returning cached result with short URL: " . $cached_result['short_url']);
                wp_send_json_success([
                    'transcription' => $cached_result['transcription'],
                    'analysis' => $cached_result['analysis'],
                    'short_url' => $cached_result['short_url'],
                    'cached' => true
                ]);
                return;
            }

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

            // Cache result
            $short_url = $cache_manager->cache_result($url, $transcription, $analysis);
            $this->logger->log("Generated short URL: " . $short_url);

            if (!$short_url) {
                throw new \Exception("Failed to cache result");
            }

            $this->set_status('complete');

            wp_send_json_success([
                'transcription' => $transcription,
                'analysis' => $analysis,
                'short_url' => $short_url,
                'cached' => false
            ]);

        } catch (\Exception $e) {
            $this->logger->log("Error: " . $e->getMessage());
            $this->logger->log("Stack trace: " . $e->getTraceAsString());
            wp_send_json_error(['message' => $e->getMessage()]);
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