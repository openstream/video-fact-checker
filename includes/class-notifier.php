<?php
namespace VideoFactChecker;

/**
 * Admin notifications for the plugin:
 *   - notify_check():       a fact-check succeeded or was served from cache.
 *   - notify_error():       a fact-check run failed (proxy 407s get a Decodo hint).
 *     Both fire on every run — Nick wants one Slack message per check.
 *   - check_daily_budget(): today's spend crossed the configured budget.
 *   - send_daily_log():     rotates the log, then mails it (size-capped) when no Slack is set.
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
     * @param string $slack_mrkdwn  Optional Slack-formatted body (clickable links);
     *                              without it Slack gets $body in a code block.
     */
    public function send($title, $body, $slack_mrkdwn = '') {
        $site = wp_parse_url(home_url(), PHP_URL_HOST);

        $webhook = $this->slack_webhook();
        if ($webhook !== '') {
            if ($this->post_to_slack($webhook, $title, $body, $site, $slack_mrkdwn)) {
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

    private function post_to_slack($webhook, $title, $body, $site, $mrkdwn = '') {
        $text = '*' . self::slack_escape($title) . '* · ' . $site;
        if ($mrkdwn !== '') {
            $text .= "\n" . $mrkdwn;
        } else {
            $body = trim($body);
            if (strlen($body) > self::SLACK_MAX_BODY) {
                $body = substr($body, 0, self::SLACK_MAX_BODY) . "\n…";
            }
            if ($body !== '') {
                $text .= "\n```" . $body . "```";
            }
        }

        $response = wp_remote_post($webhook, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['text' => $text]),
            // Runs inside the user's fact-check request; keep the wait short.
            'timeout' => 5,
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

    /** Slack mrkdwn needs &, < and > escaped in plain text. */
    public static function slack_escape($text) {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string) $text);
    }

    private static function slack_link($url, $label = '') {
        $url = str_replace(['<', '>', '|'], ['%3C', '%3E', '%7C'], (string) $url);
        return '<' . $url . ($label !== '' ? '|' . self::slack_escape($label) : '') . '>';
    }

    /** "Label: value" lines for Slack, skipping empty values. */
    private static function slack_lines(array $rows) {
        $out = [];
        foreach ($rows as $label => $value) {
            if ($value !== '' && $value !== null) {
                $out[] = '*' . $label . ':* ' . $value;
            }
        }
        return implode("\n", $out);
    }

    /**
     * Report a successful (or cache-served) fact-check — one message per run.
     *
     * @param array $d  url, share_url, title, platform, cached (bool), cached_at,
     *                  nocache (bool), model, source, summary, cost (float|null),
     *                  seconds (float), country, lang, ref.
     */
    public function notify_check(array $d) {
        $get = function ($k) use ($d) { return isset($d[$k]) ? $d[$k] : ''; };

        if (!empty($d['cached'])) {
            $title = '✅ Fact-check served from cache';
        } elseif (!empty($d['nocache'])) {
            $title = '✅ Fact-check completed (nocache, not saved)';
        } else {
            $title = '✅ Fact-check completed';
        }

        $share = $get('share_url');
        $mrkdwn = self::slack_lines([
            'Fact-check' => $share !== '' ? self::slack_link($share) : '',
            'Video'      => self::slack_link($get('url'), $get('title') !== '' ? $get('title') : $get('url')),
            'Platform'   => self::slack_escape($get('platform')),
            'Summary'    => self::slack_escape($get('summary')),
            'Model'      => self::slack_escape($get('model')),
            'Transcript' => self::slack_escape($get('source')),
            'Cost'       => isset($d['cost']) && $d['cost'] !== null ? sprintf('$%.4f', $d['cost']) : '',
            'Duration'   => isset($d['seconds']) ? sprintf('%.0f s', $d['seconds']) : '',
            'Checked on' => self::slack_escape($get('cached_at')),
            'Language'   => self::slack_escape($get('lang')),
            'Country'    => self::slack_escape($get('country')),
            'Ref'        => self::slack_escape($get('ref')),
        ]);

        $body = "Fact-check: {$share}\nVideo: " . $get('url') . "\n";
        $this->send($title, $body, $mrkdwn);
    }

    /**
     * Report a failed run. Every failure is reported (one message per check);
     * a proxy 407 gets a Decodo hint.
     *
     * @param string $user_message  The concise message shown to the user.
     * @param string $raw_details   The real reason (yt-dlp/HTTP error line, proxy
     *                              status, "no captions/too long", …) — NOT a stack
     *                              trace. Shown to the admin only.
     * @param string $url           The video URL that failed (optional).
     * @param string $ref           The log reference id (optional).
     * @param string $stage         Which step failed, in plain words (optional).
     * @param array  $extra         Optional: seconds, country.
     */
    public function notify_error($user_message, $raw_details = '', $url = '', $ref = '', $stage = '', array $extra = []) {
        $proxy_problem = self::is_proxy_auth_error($user_message . ' ' . $raw_details);

        $title = $proxy_problem
            ? '❌ Fact-check failed: Decodo proxy refused (407), traffic or balance likely used up'
            : '❌ Fact-check failed';

        $reason = $raw_details;
        if (strlen($reason) > 1500) {
            $reason = substr($reason, 0, 1500) . '…';
        }

        $mrkdwn = self::slack_lines([
            'Video'           => $url !== '' ? self::slack_link($url) : '',
            'What failed'     => self::slack_escape($stage),
            'Shown to user'   => self::slack_escape($user_message),
            'Duration'        => isset($extra['seconds']) ? sprintf('%.0f s', $extra['seconds']) : '',
            'Country'         => self::slack_escape(isset($extra['country']) ? $extra['country'] : ''),
            'Ref'             => self::slack_escape($ref),
        ]);
        if ($proxy_problem) {
            $mrkdwn .= "\nTop up or enable auto-recharge: " . self::slack_link('https://dashboard.decodo.com/');
        }
        if ($reason !== '') {
            $mrkdwn .= "\n```" . $reason . "```";
        }

        $body = '';
        if ($stage !== '') { $body .= "What failed:\n  " . $stage . "\n\n"; }
        if ($raw_details !== '') { $body .= "Reason:\n  " . $raw_details . "\n\n"; }
        $body .= "Message shown to user:\n  " . $user_message . "\n\n";
        if ($url !== '')   { $body .= "Video URL:\n  " . $url . "\n\n"; }
        if ($ref !== '')   { $body .= "Log reference:\n  " . $ref . "\n\n"; }
        $body .= "Time: " . current_time('Y-m-d H:i:s') . "\n";

        $this->send($title, $body, $mrkdwn);
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

    /** Largest log we still attach to the fallback mail; bigger ones go out without attachment. */
    const MAX_LOG_ATTACHMENT_BYTES = 5 * 1024 * 1024;

    /**
     * Rotate the log once a day (WP-Cron). Rotation happens first and never
     * depends on mail: PHPMailer loads attachments into memory, and mailing a
     * never-rotated 320 MB log fataled the cron every night before it got to
     * rotate (2024-11 to 2026-10). Without Slack, yesterday's log (`<log>.1`)
     * is then mailed, attached only while it is small enough.
     */
    public function send_daily_log() {
        $log_file = $this->logger->get_log_file();
        if (!file_exists($log_file) || filesize($log_file) === 0) {
            return;
        }

        if (!$this->rotate_log($log_file) || $this->slack_webhook() !== '') {
            return;
        }

        $to = $this->recipient();
        if (!$to || !is_email($to)) {
            return;
        }
        $rotated = $log_file . '.1';
        $size = (int) @filesize($rotated);
        $site = wp_parse_url(home_url(), PHP_URL_HOST);
        $date = current_time('Y-m-d');
        $subject = sprintf('[Video Fact Checker] Daily log %s (%s)', $date, $site);
        $body = "The Video Fact Checker log for the period ending {$date} has been rotated to {$rotated}.\n";
        $attachments = [];
        if ($size <= self::MAX_LOG_ATTACHMENT_BYTES) {
            $body .= "It is attached.\n";
            $attachments[] = $rotated;
        } else {
            $body .= sprintf("It is %.1f MB, too large to attach; read it on the server.\n", $size / 1048576);
        }
        $sent = wp_mail($to, $subject, $body, [], $attachments);
        $this->logger->log("Daily log mail " . ($sent ? "handed to MTA" : "FAILED") . " for {$to}.");
    }

    private function rotate_log($log_file) {
        if (@rename($log_file, $log_file . '.1')) {
            @touch($log_file);
            $this->logger->log("Log rotated (previous day kept as " . basename($log_file) . ".1).");
            return true;
        }
        $this->logger->log("Log rotation FAILED for {$log_file}.", 'error');
        return false;
    }
}
