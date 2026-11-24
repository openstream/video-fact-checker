<?php
namespace VideoFactChecker;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gatekeeper for the video URL a visitor submits, before it reaches yt-dlp or
 * any outbound request (GitHub AI Scan #8 argument injection, #1/#9 SSRF).
 *
 *   - only absolute http(s) URLs with a host, no user:pass@, port 80/443 only;
 *   - the host must not be localhost/.local/.internal or an IP literal, and every
 *     address it resolves to must be public (no loopback, private, link-local,
 *     reserved, e.g. 169.254.169.254 cloud metadata).
 *
 * yt-dlp additionally runs with "--" before the URL and without its generic
 * extractor (VideoProcessor::YTDLP_SAFE_OPTS), so only site-specific extractors
 * (YouTube, TikTok, Instagram, …) fetch anything. That also covers redirects and
 * DNS rebinding, which a check up front alone cannot.
 */
class UrlGuard {

    /**
     * @return string '' when the URL may be processed, otherwise the user-facing reason.
     */
    public static function rejection($url) {
        $url = (string) $url;
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\s\x00-\x1f\x7f]/', $url)) {
            return "Please enter a valid video link (starting with https://).";
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return "Please enter a valid video link (starting with https://).";
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return "Only http(s) video links are supported.";
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return "This link isn't a supported video URL. Please check the address and try again.";
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            return "This link isn't a supported video URL. Please check the address and try again.";
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if (self::is_ip_literal($host)
            || $host === 'localhost'
            || strpos($host, '.') === false
            || preg_match('/\.(localhost|local|internal|lan|home|intranet|corp)$/', $host)) {
            return "This link isn't a supported video URL. Please check the address and try again.";
        }

        $ips = self::resolve($host);
        if (!$ips) {
            return "This link's address could not be found. Please check it and try again.";
        }
        foreach ($ips as $ip) {
            if (!self::is_public_ip($ip)) {
                return "This link isn't a supported video URL. Please check the address and try again.";
            }
        }
        return '';
    }

    private static function is_ip_literal($host) {
        $h = trim($host, '[]');
        // Also catches decimal/octal/hex forms like 2130706433 or 0x7f.1.
        return filter_var($h, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+)){0,3}$/i', $h);
    }

    public static function is_public_ip($ip) {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false
            // PHP's flags miss a few special ranges: CGNAT, IPv4-mapped IPv6, ULA, NAT64.
            && !preg_match('/^(100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.|0\.|::ffff:|f[cd][0-9a-f]{2}:|64:ff9b:)/i', $ip);
    }

    /** All A/AAAA records for $host ([] when it doesn't resolve). */
    private static function resolve($host) {
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $r) {
                    if (!empty($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }
}
