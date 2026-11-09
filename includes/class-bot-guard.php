<?php
namespace VideoFactChecker;

/**
 * Keeps bots from running fact-checks on our OpenAI/Whisper/Decodo bill.
 *
 *   - Honeypot: the form carries a hidden "website" field that people never
 *     see; a bot that fills every input gets rejected before any work happens.
 *   - Limits on FRESH checks (cache hits are free and never count): per IP per
 *     hour, per IP per day, and a global cap per day. 0 disables a limit.
 *     Logged-in admins are exempt.
 *
 * The nonce alone is no protection: any bot can fetch it from the page or from
 * vfc_refresh_nonce. Counters live in transients (keyed by a hash of the IP, the
 * IP itself is never stored). Defaults are generous on purpose: v0.12.0 dropped
 * the old per-user daily limits because they got in the way of real users.
 */
class BotGuard {

    const HONEYPOT_FIELD = 'website';

    const DEFAULT_PER_IP_HOUR = 10;
    const DEFAULT_PER_IP_DAY  = 30;
    const DEFAULT_GLOBAL_DAY  = 200;

    private $logger;

    public function __construct($logger = null) {
        $this->logger = $logger ?: new Logger();
    }

    /** True when the hidden honeypot field came back filled in. */
    public function is_honeypot_filled() {
        return isset($_POST[self::HONEYPOT_FIELD]) && trim((string) $_POST[self::HONEYPOT_FIELD]) !== '';
    }

    /**
     * Count one fresh check for this request, or refuse it.
     *
     * @return string '' when the check may run, otherwise the message to show.
     */
    public function claim_fresh_check() {
        if (current_user_can('manage_options')) {
            return '';
        }

        $ip = self::client_ip();
        $ip_key = $ip !== '' ? substr(md5(wp_salt('nonce') . $ip), 0, 16) : 'unknown';
        $hour = gmdate('YmdH');
        $day = gmdate('Ymd');

        $counters = [
            // [transient key, limit, ttl, what to tell Slack]
            ['vfc_bg_h_' . $ip_key . '_' . $hour, self::limit('vfc_limit_per_ip_hour', self::DEFAULT_PER_IP_HOUR), HOUR_IN_SECONDS, 'per-IP hourly'],
            ['vfc_bg_d_' . $ip_key . '_' . $day,  self::limit('vfc_limit_per_ip_day', self::DEFAULT_PER_IP_DAY),   DAY_IN_SECONDS,  'per-IP daily'],
            ['vfc_bg_g_' . $day,                  self::limit('vfc_limit_global_day', self::DEFAULT_GLOBAL_DAY),   DAY_IN_SECONDS,  'global daily'],
        ];

        foreach ($counters as $c) {
            list($key, $limit) = $c;
            if ($limit > 0 && (int) get_transient($key) >= $limit) {
                $this->logger->log("Bot guard: {$c[3]} limit ({$limit}) reached for IP hash {$ip_key}", 'error');
                $this->alert_once($c[3], $limit, $ip, $key);
                return $c[3] === 'global daily'
                    ? "We've reached today's limit for new fact-checks. Please try again tomorrow."
                    : "You've started a lot of fact-checks in a short time. Please try again later.";
            }
        }

        foreach ($counters as $c) {
            list($key, $limit, $ttl) = $c;
            if ($limit > 0) {
                set_transient($key, (int) get_transient($key) + 1, $ttl);
            }
        }
        return '';
    }

    /** One Slack note per limit window, so a bot hammering away doesn't flood the channel. */
    private function alert_once($what, $limit, $ip, $counter_key) {
        $flag = 'vfc_bg_alerted_' . md5($counter_key);
        if (get_transient($flag)) {
            return;
        }
        set_transient($flag, 1, DAY_IN_SECONDS);
        try {
            $country = $ip !== '' ? Geo::country_for_ip($ip) : '';
            (new Notifier($this->logger))->send(
                "🛑 Bot guard: {$what} limit of {$limit} fresh fact-checks reached",
                "Further fresh checks are refused until the window ends.\nIP: {$ip}" . ($country !== '' ? " ({$country})" : '') . "\n"
                . "Limits: Settings → Fact Checker → Bot protection."
            );
        } catch (\Throwable $e) {
            $this->logger->log("Bot guard alert failed: " . $e->getMessage(), 'error');
        }
    }

    private static function limit($option, $default) {
        $v = get_option($option, $default);
        return is_numeric($v) ? max(0, (int) $v) : $default;
    }

    /** REMOTE_ADDR only, same as Geo: no trusted proxy sits in front of Apache. */
    private static function client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
}
