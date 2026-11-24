<?php
namespace VideoFactChecker;

if (!defined('ABSPATH')) {
    exit;
}

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
 *
 * Check-and-count runs under a MySQL named lock (GET_LOCK), so parallel
 * requests can't all read the same counter and slip past a limit (GitHub AI
 * Scan #6). The YouTube preflight and the premium-feedback answer have their own,
 * separate allowances (#7, #11): they cost no OpenAI money but do outbound
 * requests / Slack messages.
 */
class BotGuard {

    const HONEYPOT_FIELD = 'website';

    const DEFAULT_PER_IP_HOUR = 10;
    const DEFAULT_PER_IP_DAY  = 30;
    const DEFAULT_GLOBAL_DAY  = 200;

    // Preflight: one per run attempt, so a few times the fresh-check limits.
    const PREFLIGHT_PER_IP_HOUR = 30;
    const PREFLIGHT_GLOBAL_DAY  = 1000;

    // Premium-interest answers that reach Slack.
    const FEEDBACK_PER_IP_DAY = 3;
    const FEEDBACK_GLOBAL_DAY = 50;

    const LOCK_NAME = 'vfc_bot_guard';

    private $logger;

    public function __construct($logger = null) {
        $this->logger = $logger ?: new Logger();
    }

    /** True when the hidden honeypot field came back filled in. */
    public function is_honeypot_filled() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verified the nonce first.
        $value = isset($_POST[self::HONEYPOT_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::HONEYPOT_FIELD])) : '';
        return trim($value) !== '';
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
        $ip_key = self::ip_key($ip);
        $hour = gmdate('YmdH');
        $day = gmdate('Ymd');

        $counters = [
            // [transient key, limit, ttl, what to tell Slack]
            ['vfc_bg_h_' . $ip_key . '_' . $hour, self::limit('vfc_limit_per_ip_hour', self::DEFAULT_PER_IP_HOUR), HOUR_IN_SECONDS, 'per-IP hourly'],
            ['vfc_bg_d_' . $ip_key . '_' . $day,  self::limit('vfc_limit_per_ip_day', self::DEFAULT_PER_IP_DAY),   DAY_IN_SECONDS,  'per-IP daily'],
            ['vfc_bg_g_' . $day,                  self::limit('vfc_limit_global_day', self::DEFAULT_GLOBAL_DAY),   DAY_IN_SECONDS,  'global daily'],
        ];

        $hit = $this->claim($counters);
        if ($hit === null) {
            return '';
        }
        list($key, $limit, , $what) = $hit;
        $this->logger->log("Bot guard: {$what} limit ({$limit}) reached for IP hash {$ip_key}", 'error');
        $this->alert_once($what, $limit, $ip, $key);
        return $what === 'global daily'
            ? "We've reached today's limit for new fact-checks. Please try again tomorrow."
            : "You've started a lot of fact-checks in a short time. Please try again later.";
    }

    /**
     * Allowance for one YouTube preflight probe (outbound requests, possibly via
     * the metered proxy). Admins are exempt. No Slack alert: when it runs out the
     * preflight just answers "can't tell" and the run's own limits apply.
     */
    public function claim_preflight() {
        if (current_user_can('manage_options')) {
            return true;
        }
        $ip_key = self::ip_key(self::client_ip());
        $hit = $this->claim([
            ['vfc_bg_ph_' . $ip_key . '_' . gmdate('YmdH'), self::PREFLIGHT_PER_IP_HOUR, HOUR_IN_SECONDS, 'preflight per-IP hourly'],
            ['vfc_bg_pg_' . gmdate('Ymd'),                  self::PREFLIGHT_GLOBAL_DAY,  DAY_IN_SECONDS,  'preflight global daily'],
        ]);
        if ($hit !== null) {
            $this->logger->log("Bot guard: {$hit[3]} limit ({$hit[1]}) reached for IP hash {$ip_key}; skipping preflight probe", 'error');
            return false;
        }
        return true;
    }

    /**
     * Allowance for one premium-interest answer to reach Slack. Keyed by IP hash
     * and day only — never by the submitted URL, which an attacker could vary.
     */
    public function claim_feedback() {
        $ip_key = self::ip_key(self::client_ip());
        return $this->claim([
            ['vfc_bg_fb_' . $ip_key . '_' . gmdate('Ymd'), self::FEEDBACK_PER_IP_DAY, DAY_IN_SECONDS, 'feedback per-IP daily'],
            ['vfc_bg_fg_' . gmdate('Ymd'),                 self::FEEDBACK_GLOBAL_DAY, DAY_IN_SECONDS, 'feedback global daily'],
        ]) === null;
    }

    /**
     * Atomically check all counters and, if none is at its limit, count one hit
     * on each. Returns null on success, otherwise the counter row that was full.
     * Fails open (counts without the lock) only if MySQL refuses the lock, so a
     * DB hiccup can't take the site down; that path is logged.
     */
    private function claim(array $counters) {
        global $wpdb;
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, 5)) === 1;
        if (!$locked) {
            $this->logger->log('Bot guard: could not get the counter lock; counting without it', 'error');
        }
        try {
            foreach ($counters as $c) {
                list($key, $limit) = $c;
                if ($limit > 0 && (int) self::read_counter($key) >= $limit) {
                    return $c;
                }
            }
            foreach ($counters as $c) {
                list($key, $limit, $ttl) = $c;
                if ($limit > 0) {
                    set_transient($key, (int) self::read_counter($key) + 1, $ttl);
                }
            }
            return null;
        } finally {
            if ($locked) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK_NAME));
            }
        }
    }

    /**
     * Counter value straight from the DB. get_transient() could answer from the
     * per-request object cache, i.e. a value read before we held the lock.
     */
    private static function read_counter($key) {
        if (!wp_using_ext_object_cache()) {
            wp_cache_delete('_transient_' . $key, 'options');
            wp_cache_delete('_transient_timeout_' . $key, 'options');
            wp_cache_delete('notoptions', 'options');
        }
        return get_transient($key);
    }

    private static function ip_key($ip) {
        return $ip !== '' ? substr(md5(wp_salt('nonce') . $ip), 0, 16) : 'unknown';
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
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
}
