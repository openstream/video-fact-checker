<?php
namespace VideoFactChecker;

/**
 * Email notifications for the plugin:
 *   - notify_error():  sent to the admin when a fact-check run fails.
 *   - send_daily_log(): the full log file, mailed once a day (WP-Cron), then rotated.
 *
 * Recipient defaults to the WordPress admin email and can be overridden with the
 * `vfc_notify_email` option or the `vfc_notify_email` filter.
 */
class Notifier {

    // Don't send the same error twice within this window (seconds), to avoid a
    // mail flood when the same failure repeats (e.g. a dead proxy).
    const DEDUPE_WINDOW = 600; // 10 minutes

    private $logger;

    public function __construct($logger = null) {
        $this->logger = $logger ?: new Logger();
    }

    private function recipient() {
        $email = get_option('vfc_notify_email', '');
        if (!is_string($email) || $email === '' || !is_email($email)) {
            $email = get_option('admin_email');
        }
        return apply_filters('vfc_notify_email', $email);
    }

    /**
     * Email the admin about a failed run. De-duplicated per error signature.
     *
     * @param string $user_message  The concise message shown to the user.
     * @param string $raw_details   Full technical detail for the admin (not shown to users).
     * @param string $url           The video URL that failed (optional).
     * @param string $ref           The log reference id (optional).
     */
    public function notify_error($user_message, $raw_details = '', $url = '', $ref = '') {
        $to = $this->recipient();
        if (!$to || !is_email($to)) {
            return;
        }

        // De-dupe: skip if an identical error signature was mailed very recently.
        $sig = 'vfc_notified_' . md5($user_message . '|' . $url);
        if (get_transient($sig)) {
            $this->logger->log("Admin error mail suppressed (deduped): " . $user_message);
            return;
        }
        set_transient($sig, 1, self::DEDUPE_WINDOW);

        $site = wp_parse_url(home_url(), PHP_URL_HOST);
        $subject = sprintf('[Video Fact Checker] Error on %s', $site);

        $body  = "A fact-check run failed.\n\n";
        $body .= "Message shown to user:\n  " . $user_message . "\n\n";
        if ($url !== '')   { $body .= "Video URL:\n  " . $url . "\n\n"; }
        if ($ref !== '')   { $body .= "Log reference:\n  " . $ref . "\n\n"; }
        if ($raw_details !== '') {
            $body .= "Technical details:\n" . $raw_details . "\n\n";
        }
        $body .= "Time: " . current_time('Y-m-d H:i:s') . "\n";
        $body .= "Full log is mailed daily and stored at " . $this->logger->get_log_file() . "\n";

        $sent = wp_mail($to, $subject, $body);
        $this->logger->log("Admin error mail " . ($sent ? "sent" : "FAILED") . " to " . $to);
    }

    /**
     * If today's spend exceeds the configured daily budget, email the admin.
     * De-duplicated per day so at most one alert per calendar day is sent.
     */
    public function check_daily_budget() {
        $budget = get_option('vfc_daily_cost_budget', '');
        if ($budget === '' || !is_numeric($budget) || (float) $budget <= 0) {
            return; // budget alerts disabled
        }
        $budget = (float) $budget;

        $to = $this->recipient();
        if (!$to || !is_email($to)) {
            return;
        }

        $cache = new CacheManager();
        $today_start = current_time('Y-m-d') . ' 00:00:00';
        $summary = $cache->get_cost_summary($today_start);
        $spent = $summary ? (float) $summary->total_cost : 0.0;

        if ($spent < $budget) {
            return;
        }

        // One alert per day max.
        $day_key = 'vfc_budget_alert_' . current_time('Ymd');
        if (get_transient($day_key)) {
            return;
        }
        set_transient($day_key, 1, DAY_IN_SECONDS);

        $site = wp_parse_url(home_url(), PHP_URL_HOST);
        $subject = sprintf('[Video Fact Checker] Daily budget exceeded on %s', $site);
        $budget_str = $budget < 1 ? rtrim(rtrim(sprintf('%.4f', $budget), '0'), '.') : sprintf('%.2f', $budget);
        $body  = sprintf("Today's fact-check spend has reached \$%.4f, exceeding the daily budget of \$%s.\n\n",
            $spent, $budget_str);
        $body .= sprintf("Runs today: %d\n", $summary ? (int) $summary->runs : 0);
        $body .= sprintf("  OpenAI:  \$%.4f\n", $summary ? (float) $summary->openai_cost : 0);
        $body .= sprintf("  Whisper: \$%.4f\n", $summary ? (float) $summary->whisper_cost : 0);
        $body .= sprintf("  Proxy:   \$%.4f\n", $summary ? (float) $summary->proxy_cost : 0);
        $body .= "\nTime: " . current_time('Y-m-d H:i:s') . "\n";

        $sent = wp_mail($to, $subject, $body);
        $this->logger->log("Daily budget alert " . ($sent ? "sent" : "FAILED") . " to {$to} (spent \${$spent} / budget \${$budget})");
    }

    /**
     * Mail the full log file as an attachment, then rotate (truncate) it so it
     * doesn't grow without bound. Intended to run once a day via WP-Cron.
     */
    public function send_daily_log() {
        $to = $this->recipient();
        if (!$to || !is_email($to)) {
            return;
        }

        $log_file = $this->logger->get_log_file();
        $site = wp_parse_url(home_url(), PHP_URL_HOST);
        $date = current_time('Y-m-d');
        $subject = sprintf('[Video Fact Checker] Daily log %s (%s)', $date, $site);

        if (!file_exists($log_file) || filesize($log_file) === 0) {
            wp_mail($to, $subject, "No log entries in the last 24 hours.");
            return;
        }

        // Copy to a dated temp file so the attachment has a meaningful name and
        // we can safely rotate the original afterwards.
        $tmp = trailingslashit(sys_get_temp_dir()) . 'video-fact-checker-' . $date . '.log';
        $copied = @copy($log_file, $tmp);
        $attachment = $copied ? $tmp : $log_file;

        $body  = "Attached is the Video Fact Checker log for the period ending {$date}.\n";
        $body .= "The log has been rotated (cleared) after sending.\n";

        $sent = wp_mail($to, $subject, $body, [], [$attachment]);

        if ($copied) {
            @unlink($tmp);
        }

        if ($sent) {
            // Rotate: truncate the live log so the next day starts fresh.
            @file_put_contents($log_file, '');
            $this->logger->log("Daily log mailed to {$to} and rotated.");
        } else {
            $this->logger->log("Daily log mail FAILED to {$to}; log NOT rotated.", 'error');
        }
    }
}
