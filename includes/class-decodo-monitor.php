<?php
namespace VideoFactChecker;

/**
 * Daily watch on the Decodo proxy (the YouTube fallback), so an exhausted plan is
 * noticed before users hit it — Decodo's own low-balance alerts are opt-in and
 * were off when the proxy silently died with 407s.
 *
 *   1. Probe: one tiny request through the configured proxy. A 407 means the
 *      plan's traffic/balance is used up (or the credentials are wrong). Needs
 *      no API key.
 *   2. Usage: with `vfc_decodo_api_key` (Decodo dashboard → Settings → Public API)
 *      compare used traffic against the subscription limit and alert from
 *      `vfc_decodo_alert_percent` (default 80) or when the plan ends within 3 days.
 *
 * Alerts go through Notifier::send() (Slack or mail), at most one per kind per day.
 */
class DecodoMonitor {

    const API_BASE      = 'https://api.decodo.com/v2/';
    const PROBE_URL     = 'https://ip.decodo.com/json';
    const DEFAULT_ALERT_PERCENT = 80;
    const EXPIRY_WARN_DAYS      = 3;

    private $logger;

    public function __construct($logger = null) {
        $this->logger = $logger ?: new Logger();
    }

    public function run() {
        $this->probe_proxy();
        $this->check_usage();
    }

    /** Returns the HTTP status from the probe (0 on transport error, null if no proxy). */
    public function probe_proxy() {
        $proxy = (new VideoProcessor())->build_youtube_proxy();
        if ($proxy === '') {
            return null;
        }
        if (!function_exists('curl_init')) {
            // php-cli on prod has no curl; WP-Cron runs under php-fpm, which does.
            $this->logger->log('Decodo probe skipped: curl not available in this SAPI');
            return null;
        }

        $ch = \curl_init(self::PROBE_URL);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($ch, CURLOPT_PROXY, $proxy);
        \curl_exec($ch);
        $code     = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = \curl_error($ch);
        \curl_close($ch);

        // curl reports a refused CONNECT as a transport error mentioning the code.
        $refused = $code === 407 || preg_match('/\b407\b/', $err) === 1;
        $this->logger->log('Decodo probe: ' . ($refused ? 'HTTP 407' : ($code ?: 'error ' . $err)));

        if ($refused) {
            $this->alert_once('probe407',
                'Decodo proxy refused the connection (407): traffic or balance likely used up',
                "The daily proxy check got HTTP 407 from Decodo.\n\n"
                . "Top up or enable auto-recharge at https://dashboard.decodo.com/ — until then,\n"
                . "YouTube videos whose subtitles aren't directly reachable fail.\n"
                . ($err !== '' ? "\ncurl: {$err}\n" : '')
            );
        }
        return $refused ? 407 : $code;
    }

    /**
     * Compare used traffic with the plan limit. Returns the parsed numbers (for the
     * admin/CLI) or null when no API key is set or the API call failed.
     */
    public function check_usage() {
        $key = trim((string) get_option('vfc_decodo_api_key', ''));
        if ($key === '') {
            return null;
        }

        $subs = $this->api_get('subscriptions', $key);
        $users = $this->api_get('sub-users?service_type=residential_proxies', $key);
        if ($subs === null || $users === null) {
            return null;
        }

        // The endpoint returns one object or a list; take the residential plan.
        $plan = isset($subs['traffic_limit']) ? $subs : null;
        if ($plan === null) {
            foreach ($subs as $s) {
                if (is_array($s) && ($s['service_type'] ?? '') === 'residential_proxies') {
                    $plan = $s;
                    break;
                }
            }
        }
        if ($plan === null) {
            $this->logger->log('Decodo usage: no residential subscription in API response: ' . substr(wp_json_encode($subs), 0, 300), 'error');
            return null;
        }

        $used = 0.0;
        foreach ($users as $u) {
            if (is_array($u) && isset($u['traffic'])) {
                $used += (float) $u['traffic'];
            }
        }
        $limit = (float) ($plan['traffic_limit'] ?? 0);
        $valid_until = (string) ($plan['valid_until'] ?? '');
        $percent = $limit > 0 ? $used / $limit * 100 : null;

        $this->logger->log(sprintf('Decodo usage: %.2f GB of %s GB%s, valid until %s',
            $used,
            $limit > 0 ? sprintf('%.2f', $limit) : '?',
            $percent !== null ? sprintf(' (%.0f%%)', $percent) : '',
            $valid_until !== '' ? $valid_until : '?'
        ));

        $threshold = (int) get_option('vfc_decodo_alert_percent', self::DEFAULT_ALERT_PERCENT);
        if ($threshold <= 0) {
            $threshold = self::DEFAULT_ALERT_PERCENT;
        }
        if ($percent !== null && $percent >= $threshold) {
            $this->alert_once('usage',
                sprintf('Decodo proxy traffic at %.0f%% of the plan', $percent),
                sprintf("Used %.2f GB of %.2f GB (alert threshold %d%%).\n\n", $used, $limit, $threshold)
                . "Top up or enable auto-recharge at https://dashboard.decodo.com/ before it runs out —\n"
                . "at 100% Decodo answers 407 and YouTube videos without reachable subtitles fail.\n"
            );
        }

        if ($valid_until !== '') {
            $ends = strtotime($valid_until . ' 23:59:59');
            if ($ends && $ends - time() < self::EXPIRY_WARN_DAYS * DAY_IN_SECONDS) {
                $this->alert_once('expiry',
                    'Decodo proxy plan ends ' . $valid_until,
                    "The Decodo subscription is valid until {$valid_until}.\n"
                    . "Renew it at https://dashboard.decodo.com/ to keep the YouTube fallback working.\n"
                );
            }
        }

        return ['used_gb' => $used, 'limit_gb' => $limit, 'percent' => $percent, 'valid_until' => $valid_until];
    }

    private function api_get($path, $key) {
        $response = wp_remote_get(self::API_BASE . $path, [
            'headers' => ['Authorization' => $key, 'Accept' => 'application/json'],
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            $this->logger->log("Decodo API {$path}: " . $response->get_error_message(), 'error');
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code !== 200) {
            $this->logger->log("Decodo API {$path}: HTTP {$code} " . substr($body, 0, 200), 'error');
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    private function alert_once($kind, $title, $body) {
        $key = 'vfc_decodo_alert_' . $kind . '_' . current_time('Ymd');
        if (get_transient($key)) {
            return;
        }
        set_transient($key, 1, DAY_IN_SECONDS);
        (new Notifier($this->logger))->send($title, $body);
    }
}
