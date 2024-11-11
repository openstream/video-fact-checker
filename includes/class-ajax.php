<?php
namespace VideoFactChecker;

class Ajax {
    private $logger;
    private $processor;
    private $transcriber;
    private $fact_checker;

    public function __construct() {
        // Initialize AJAX handlers
        add_action('wp_ajax_process_video', [$this, 'handle_process_video']);
        add_action('wp_ajax_nopriv_process_video', [$this, 'handle_process_video']);
        add_action('wp_ajax_check_progress', [$this, 'handle_check_progress']);
        add_action('wp_ajax_nopriv_check_progress', [$this, 'handle_check_progress']);
        
        // Initialize services
        $this->logger = new Logger();
        $this->processor = new VideoProcessor();
        $this->transcriber = new TranscriptionService();
        $this->fact_checker = new FactChecker();
    }

    public function handle_process_video() {
        try {
            check_ajax_referer('vfc_nonce', 'nonce');
            
            $url = sanitize_text_field($_POST['url']);
            $this->logger->log("Starting video processing for URL: " . $url);
            
            $this->set_status('downloading');
            $this->logger->log("Downloading video from URL");
            $audio_file = $this->processor->download_video($url);
            $this->logger->log("Video downloaded successfully: " . $audio_file);
            
            $this->set_status('transcribing');
            $this->logger->log("Starting transcription");
            $transcription = $this->transcriber->transcribe($audio_file);
            $this->logger->log("Transcription completed");
            
            // Clean up the audio file after successful transcription
            if (file_exists($audio_file)) {
                unlink($audio_file);
                $this->logger->log("Deleted temporary audio file: " . $audio_file);
            }
            
            $this->set_status('analyzing');
            $this->logger->log("Starting fact checking");
            $analysis = $this->fact_checker->check_facts($transcription);
            $this->logger->log("Fact checking completed");
            
            $this->set_status('complete');
            $this->logger->log("Process completed successfully");
            
            wp_send_json_success([
                'transcription' => $transcription,
                'analysis' => $analysis
            ]);
            
        } catch (\Exception $e) {
            // Clean up on error as well
            if (isset($audio_file) && file_exists($audio_file)) {
                unlink($audio_file);
                $this->logger->log("Deleted temporary audio file after error: " . $audio_file);
            }
            
            $this->logger->log("Error processing video: " . $e->getMessage(), 'error');
            $this->set_status('error');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handle_check_progress() {
        try {
            check_ajax_referer('vfc_nonce', 'nonce');
            $status = get_transient('vfc_process_status_' . get_current_user_id());
            $this->logger->log("Progress check - current status: " . ($status ?: 'unknown'));
            wp_send_json_success(['status' => $status ?: 'processing']);
        } catch (\Exception $e) {
            $this->logger->log("Error checking progress: " . $e->getMessage(), 'error');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function set_status($status) {
        $this->logger->log("Setting status to: " . $status);
        set_transient(
            'vfc_process_status_' . get_current_user_id(), 
            $status, 
            HOUR_IN_SECONDS
        );
    }
}