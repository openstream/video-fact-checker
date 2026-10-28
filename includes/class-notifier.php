<?php
namespace VideoFactChecker;

/**
 * Admin notifications for the plugin:
 *   - notify_error():       a fact-check run failed (proxy 407s get a Decodo hint).
 *   - check_daily_budget(): today's spend crossed the configured budget.
 *   - send_daily_log():     rotates the log; mails it first when no Slack is set.
 *   - send():               shared channel for every other alert (health check,
 *                           model watcher, Decodo monitor).
 *
 * Channel: Slack (incoming webhook in `vfc_slack_webhook_url`) when configured,
 * otherwise wp_mail to the `vfc_notify_email` option / `vfc_notify_email` filter /
 * site admin email. Slack exists because the prod droplet can't deliver mail
 * (DigitalOcean blocks outbound port 25), so wp_mail "succeeds" into a queue
 * that never drains.
 */
class Notifier {

    // Don't send the same error twice within this window (seconds), to avoid a
    // flood when the same failure repeats (e.g. a dead proxy).
    const DEDUPE_WINDOW = 600; // 10 minutes

    // A dead proxy fails every YouTube run; one Decodo alert per hour is enough.
    const PROXY_DEDUPE_WINDOW = HOUR_IN_SECONDS;

    // Slack renders up to ~40k chars, but long code blocks are unreadable.
    const SLACK_MAX_BODY = 3500;

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

    private function slack_webhook() {
        $url = get_option('vfc_slack_webhook_url', '');
        return is_string($url) ? trim($url) : '';
    }

    /**
     * Send an alert to Slack (if configured) or by email. Returns true on success.
     *
     * @param string $title  Short headline, without the site name (added here).
     * @param string $body   Plain-text details.
     */
    public function send($title, $body) {
        $site = wp_parse_url(home_url(), PHP_URL_HOST);

        $webhook = $this->slack_webhook();
        if ($webhook !== '') {
            if ($this->post_to_slack($webhook, $title, $body, $site)) {
                return true;
            }
            // Fall through to mail so a broken webhook doesn't swallow the alert.
        }

        $to = $this->recipient();
        if (!$to || !is_email($to)) {
            return false;
        }
        $sent = wp_mail($to, sprintf('[Video Fact Checker] %s on %s', $title, $site), $body);
        $this->logger->log("Alert mail " . ($sent ? "handed to MTA" : "FAILED") . " for {$to}: {$title}");
        return $sent;
    }

    private function post_to_slack($webhook, $title, $body, $site) {
        $body = trim($body);
        if (strlen($body) > self::SLACK_MAX_BODY) {
            $body = substr($body, 0, self::SLACK_MAX_BODY) . "\n…";
        }
        $text = '*' . $title . '* · ' . $site;
        if ($body !== '') {
            $text .= "\n```" . $body . "```";
        }

        $response = wp_remote_post($webhook, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['text' => $text]),
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            $this->logger->log("Slack alert FAILED ({$title}): " . $response->get_error_message(), 'error');
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->logger->log("Slack alert FAILED ({$title}): HTTP {$code} " . substr((string) wp_remote_retrieve_body($response), 0, 200), 'error');
            return false;
        }
        $this->logger->log("Slack alert sent: {$title}");
        return true;
    }

    /** True when an error text points at the proxy refusing us (Decodo out of traffic/balance). */
    public static function is_proxy_auth_error($text) {
        // \b keeps a "407" inside a numeric video id (TikTok, X) from matching.
        return preg_match('/\b407\b/', $text) === 1
            || stripos($text, 'proxy authentication failed') !== false
            || stripos($text, 'proxy traffic limit') !== false;
    }

    /**
     * Notify the admin about a failed run. De-duplicated per error signature.
     *
     * @param string $user_message  The concise message shown to the user.
     * @param string $raw_details   The real reason (yt-dlp/HTTP error line, proxy
     *                              status, "no captions/too long", …) — NOT a stack
     *                              trace. Shown to the admin only.
     * @param string $url           The video URL that failed (optional).
     * @param string $ref           The log reference id (optional).
     * @param string $stage         Which step failed, in plain words (optional).
     */
    public function notify_error($user_message, $raw_details = '', $url = '', $ref = '', $stage = '') {
        $proxy_problem = self::is_proxy_auth_error($user_message . ' ' . $raw_details);

        // De-dupe: skip if an identical error signature was sent very recently.
        // Proxy failures share one key, since every YouTube URL hits the same wall.
        if ($proxy_problem) {
            $sig = 'vfc_notified_proxy407';
            $window = self::PROXY_DEDUPE_WINDOW;
        } else {
            $sig = 'vfc_notified_' . md5($user_message . '|' . $url);
            $window = self::DEDUPE_WINDOW;
        }
        if (get_transient($sig)) {
            $this->logger->log("Admin alert suppressed (deduped): " . $user_message);
            return;
        }
        set_transient($sig, 1, $window);

        $title = $proxy_problem
            ? 'Decodo proxy refused the connection (407): traffic or balance likely used up'
            : 'Fact-check failed';

        $body = '';
        if ($proxy_problem) {
            $body .= "Top up or enable auto-recharge at https://dashboard.decodo.com/ — YouTube videos\n";
            $body .= "without directly reachable subtitles fail until then.\n\n";
        }
        if ($stage !== '') { $body .= "What failed:\n  " . $stage . "\n\n"; }
        if ($raw_details !== '') { $body .= "Reason:\n  " . $raw_details . "\n\n"; }
        $body .= "Message shown to user:\n  " . $user_message . "\n\n";
        if ($url !== '')   { $body .= "Video URL:\n  " . $url . "\n\n"; }
        if ($ref !== '')   { $body .= "Log reference:\n  " . $ref . "\n\n"; }
        $body .= "Time: " . current_time('Y-m-d H:i:s') . "\n";
        $body .= "Log: " . $this->logger->get_log_file() . "\n";

        $this->send($title, $body);
    }

    /**
     * If today's spend exceeds the configured daily budget, alert the admin.
     * De-duplicated per day so at most one alert per calendar day is sent.
     */
    public function check_daily_budget() {
        $budget = get_option('vfc_daily_cost_budget', '');
        if ($budget === '' || !is_numeric($budget) || (float) $budget <= 0) {
            return; // budget alerts disabled
        }
        $budget = (float) $budget;

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

        $budget_str = $budget < 1 ? rtrim(rtrim(sprintf('%.4f', $budget), '0'), '.') : sprintf('%.2f', $budget);
        $body  = sprintf("Today's fact-check spend has reached \$%.4f, exceeding the daily budget of \$%s.\n\n",
            $spent, $budget_str);
        $body .= sprintf("Runs today: %d\n", $summary ? (int) $summary->runs : 0);
        $body .= sprintf("  OpenAI:  \$%.4f\n", $summary ? (float) $summary->openai_cost : 0);
        $body .= sprintf("  Whisper: \$%.4f\n", $summary ? (float) $summary->whisper_cost : 0);
        $body .= sprintf("  Proxy:   \$%.4f\n", $summary ? (float) $summary->proxy_cost : 0);
        $body .= "\nTime: " . current_time('Y-m-d H:i:s') . "\n";

        $this->send('Daily budget exceeded', $body);
    }

    /**
     * Rotate the log once a day (WP-Cron). Without Slack the log is mailed first,
     * as before; with Slack there's nothing to attach it to, so it's only rotated.
     * Rotation keeps yesterday's log as `<log>.1` either way, so it never again
     * hinges on a mail being delivered.
     */
    public function send_daily_log() {
        $log_file = $this->logger->get_log_file();
        if (!file_exists($log_file) || filesize($log_file) === 0) {
            return;
        }

        if ($this->slack_webhook() === '') {
            $to = $this->recipient();
            if ($to && is_email($to)) {
                $site = wp_parse_url(home_url(), PHP_URL_HOST);
                $date = current_time('Y-m-d');
                $subject = sprintf('[Video Fact Checker] Daily log %s (%s)', $date, $site);
                $body  = "Attached is the Video Fact Checker log for the period ending {$date}.\n";
                $body .= "The log has been rotated after sending.\n";
                $sent = wp_mail($to, $subject, $body, [], [$log_file]);
                $this->logger->log("Daily log mail " . ($sent ? "handed to MTA" : "FAILED") . " for {$to}.");
            }
        }

        $this->rotate_log($log_file);
    }

    private function rotate_log($log_file) {
        if (@rename($log_file, $log_file . '.1')) {
            @touch($log_file);
            $this->logger->log("Log rotated (previous day kept as " . basename($log_file) . ".1).");
        } else {
            $this->logger->log("Log rotation FAILED for {$log_file}.", 'error');
        }
    }
}
