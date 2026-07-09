<?php
namespace VideoFactChecker;

/**
 * Per-user, per-day rate limiting for fact-check requests.
 *
 * Two buckets are tracked independently:
 *   - "youtube": YouTube videos (expensive — require a paid proxy). Free tier: 1/day.
 *   - "other":   All other platforms (no proxy needed). Free tier: 3/day.
 *
 * A "user" is the logged-in WordPress user when available, otherwise the client
 * IP address. Counts reset at local midnight (per the site's timezone) because the
 * bucket key includes the current Y-m-d date.
 *
 * Limits are filterable so the upcoming paid tiers (see ROADMAP.md) can raise them
 * per user/plan without touching the enforcement logic:
 *   add_filter('vfc_rate_limit', function ($limit, $bucket, $user_key) { ... }, 10, 3);
 */
class RateLimiter {

    const DEFAULT_LIMITS = [
        'youtube' => 1,
        'other'   => 3,
    ];

    private $logger;

    public function __construct($logger = null) {
        $this->logger = $logger ?: new Logger();
    }

    /**
     * Throw if the current user has no remaining quota for this bucket.
     * Call before starting an expensive download.
     */
    public function enforce($bucket) {
        $limit = $this->limit_for($bucket);
        if ($limit <= 0) {
            return; // 0 or negative means "unlimited" (used by future paid tiers)
        }

        $used = $this->used($bucket);
        if ($used >= $limit) {
            $this->logger->log(sprintf(
                "Rate limit reached: bucket=%s used=%d limit=%d user=%s",
                $bucket, $used, $limit, $this->describe_user_key()
            ));
            $label = $bucket === 'youtube' ? 'YouTube video' : 'video';
            throw new \Exception(sprintf(
                'Daily limit reached: you can fact-check %d %s%s per day on the free plan. Please try again tomorrow.',
                $limit,
                $label,
                $limit === 1 ? '' : 's'
            ));
        }
    }

    /**
     * Record a successful use. Call only after a request actually consumed resources.
     */
    public function record($bucket) {
        $key = $this->option_key($bucket);
        $used = (int) get_option($key, 0);
        $used++;
        // Expire shortly after local midnight so stale rows don't accumulate.
        update_option($key, $used, false);
        $this->logger->log(sprintf("Rate limit consumed: bucket=%s now=%d user=%s", $bucket, $used, $this->describe_user_key()));
        return $used;
    }

    public function used($bucket) {
        return (int) get_option($this->option_key($bucket), 0);
    }

    public function limit_for($bucket) {
        $default = isset(self::DEFAULT_LIMITS[$bucket]) ? self::DEFAULT_LIMITS[$bucket] : 0;
        /**
         * Filter the daily limit for a bucket.
         *
         * @param int    $default  Default free-tier limit (0 = unlimited).
         * @param string $bucket   'youtube' | 'other'.
         * @param string $user_key Stable identifier for the current user.
         */
        return (int) apply_filters('vfc_rate_limit', $default, $bucket, $this->user_key());
    }

    /**
     * Stable per-user identifier: WP user id if logged in, else hashed client IP.
     */
    private function user_key() {
        $uid = get_current_user_id();
        if ($uid > 0) {
            return 'u' . $uid;
        }
        return 'ip' . substr(md5($this->client_ip()), 0, 16);
    }

    private function describe_user_key() {
        $uid = get_current_user_id();
        return $uid > 0 ? ('user#' . $uid) : 'anon-ip';
    }

    private function option_key($bucket) {
        // e.g. vfc_rl_youtube_20260709_ipabc123...
        $date = current_time('Ymd'); // site timezone
        return 'vfc_rl_' . preg_replace('/[^a-z]/', '', $bucket) . '_' . $date . '_' . $this->user_key();
    }

    private function client_ip() {
        // Behind the DO droplet's web server there is no shared proxy chain we trust,
        // so use REMOTE_ADDR directly. If a trusted reverse proxy is added later,
        // parse X-Forwarded-For here instead.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        return is_string($ip) ? $ip : '';
    }
}
