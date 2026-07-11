<?php
namespace VideoFactChecker;

/**
 * Daily smoke test: run one real fact-check end to end (download → transcribe →
 * fact-check) against a known test video, and email the admin if it fails. This
 * catches breakage (e.g. yt-dlp/YouTube changes) immediately instead of via users.
 *
 * The test video defaults to a stable TikTok clip and can be overridden with the
 * `vfc_healthcheck_url` option. Runs with a short transcript so cost is negligible;
 * results are NOT cached (the point is to exercise the live download path).
 */
class HealthCheck {

    const DEFAULT_TEST_URL = 'https://vm.tiktok.com/ZGd9Vvnq2/';

    private $logger;

    public function __construct($logger = null) {
        $this->logger = $logger ?: new Logger();
    }

    private function test_url() {
        $url = get_option('vfc_healthcheck_url', '');
        if (!is_string($url) || $url === '') {
            $url = self::DEFAULT_TEST_URL;
        }
        return apply_filters('vfc_healthcheck_url', $url);
    }

    /**
     * Run the smoke test. On failure, email the admin (deduplicated per day).
     * Returns true on success, false on failure.
     */
    public function run() {
        $url = $this->test_url();
        $this->logger->log("Health check starting for: {$url}");

        $processor = new VideoProcessor();
        $audio_file = null;
        try {
            $audio_file = $processor->download_video($url);
            $transcriber = new TranscriptionService();
            $transcription = $transcriber->transcribe($audio_file);
            if (file_exists($audio_file)) {
                @unlink($audio_file);
                $audio_file = null;
            }
            if (!is_string($transcription) || trim($transcription) === '') {
                throw new \Exception('Empty transcription');
            }
            $fact_checker = new FactChecker();
            $analysis = $fact_checker->check_facts($transcription);
            if (!is_string($analysis) || trim($analysis) === '') {
                throw new \Exception('Empty fact-check analysis');
            }

            $this->logger->log("Health check OK (transcript " . strlen($transcription) . " chars, analysis " . strlen($analysis) . " chars).");
            return true;

        } catch (\Throwable $e) {
            if ($audio_file && file_exists($audio_file)) {
                @unlink($audio_file);
            }
            $this->logger->log("Health check FAILED: " . $e->getMessage(), 'error');
            $this->notify_failure($url, $e->getMessage());
            return false;
        }
    }

    private function notify_failure($url, $error) {
        $to = get_option('vfc_notify_email', '');
        if (!is_string($to) || $to === '' || !is_email($to)) {
            $to = get_option('admin_email');
        }
        if (!$to || !is_email($to)) {
            return;
        }

        // One alert per day max, so a persistent outage doesn't spam.
        $day_key = 'vfc_healthcheck_alert_' . current_time('Ymd');
        if (get_transient($day_key)) {
            return;
        }
        set_transient($day_key, 1, DAY_IN_SECONDS);

        $site = wp_parse_url(home_url(), PHP_URL_HOST);
        $subject = sprintf('[Video Fact Checker] Health check FAILED on %s', $site);
        $body  = "The daily health check could not complete a fact-check.\n\n";
        $body .= "Test video:\n  {$url}\n\n";
        $body .= "Error:\n  {$error}\n\n";
        $body .= "This usually means the video downloader (yt-dlp) or a dependency needs attention.\n";
        $body .= "Time: " . current_time('Y-m-d H:i:s') . "\n";

        $sent = wp_mail($to, $subject, $body);
        $this->logger->log("Health check alert " . ($sent ? "sent" : "FAILED") . " to {$to}");
    }
}
