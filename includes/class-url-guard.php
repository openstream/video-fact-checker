<?php
namespace VideoFactChecker;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gatekeeper for the video URL a visitor submits, before it reaches yt-dlp or
 * any outbound request (GitHub AI Scan #8 argument injection, #1/#9/#13 SSRF).
 *
 *   - only absolute http(s) URLs with a host, no user:pass@, port 80/443 only;
 *   - the host must belong to one of the supported video platforms (PLATFORMS).
 *
 * v0.27.0 accepted any host whose DNS records were public. That check ran
 * before the bot limits, so slow attacker-controlled DNS could tie up PHP
 * workers (#14/#17), and yt-dlp resolved the name again on its own, so DNS
 * rebinding and redirects slipped past it (#13). With a fixed list of platform
 * domains there is no lookup on our side at all, and yt-dlp only loads the
 * extractors of those platforms (VideoProcessor::ytdlp_safe_opts()), never
 * the ones for self-hosted services on arbitrary domains (OwnCloud, PeerTube …).
 */
class UrlGuard {

    /** Longer input is refused before it is parsed or logged (#16). */
    const MAX_LENGTH = 2048;

    /**
     * Supported domain (subdomains included) => yt-dlp extractor names for it,
     * as --use-extractors regexes (matched case-insensitively against the whole
     * name). 'generic' = yt-dlp's generic extractor, only on paths listed in
     * GENERIC_PATHS. Extend via the vfc_supported_platforms filter.
     */
    const PLATFORMS = [
        'youtube.com'     => 'youtube.*',
        'youtu.be'        => 'youtube.*',
        'tiktok.com'      => 'tiktok.*,vm\.tiktok',
        'instagram.com'   => 'instagram.*',
        'x.com'           => 'twitter.*',
        'twitter.com'     => 'twitter.*',
        'video.twimg.com' => 'generic',
        'facebook.com'    => 'facebook.*',
        'fb.watch'        => 'facebook.*',
        'linkedin.com'    => 'linkedin.*,generic',
        'vimeo.com'       => 'vimeo.*',
        'reddit.com'      => 'reddit',
        'redd.it'         => 'reddit',
        'twitch.tv'       => 'twitch.*',
        'dailymotion.com' => 'dailymotion.*',
        'dai.ly'          => 'dailymotion.*',
        'snapchat.com'    => 'snapchat.*',
        'bilibili.com'    => 'bilibili.*',
        'rumble.com'      => 'rumble.*',
        'bitchute.com'    => 'bitchute.*',
        'odysee.com'      => 'lbry.*',
        't.me'            => 'telegram:embed',
    ];

    /**
     * Extractors that only resolve a redirect/shortlink (t.co, facebook.com/l.php).
     * They match the platform's name patterns but follow HTTP redirects to any
     * host, so they are always excluded (GitHub AI Scan #18). Shortlinks are
     * refused outright; visitors can paste the resolved x.com URL instead.
     */
    const EXCLUDED_EXTRACTORS = ['twitter:shortener', 'FacebookRedirectURL'];

    /**
     * Where the generic extractor may run (domain => path regex): direct media
     * files on the X/Twitter video CDN and LinkedIn posts, which have no extractor
     * of their own (both real past submissions). Never on all of linkedin.com:
     * /redir/redirect?url=… is an open redirect, and the generic extractor
     * follows redirects to any host.
     */
    const GENERIC_PATHS = [
        'video.twimg.com' => '#^/#',
        'linkedin.com'    => '#^/(posts|feed/update)/#',
    ];

    /** True when yt-dlp's generic extractor may handle $url (see GENERIC_PATHS). */
    public static function allows_generic($url) {
        $parts = wp_parse_url((string) $url);
        $domain = is_array($parts) && isset($parts['host']) ? self::platform_domain($parts['host']) : '';
        $paths = (array) apply_filters('vfc_generic_extractor_paths', self::GENERIC_PATHS);
        return $domain !== '' && isset($paths[$domain])
            && preg_match($paths[$domain], isset($parts['path']) ? $parts['path'] : '/') === 1;
    }

    /** PLATFORMS after the vfc_supported_platforms filter, keys lower-case. */
    public static function platforms() {
        $platforms = (array) apply_filters('vfc_supported_platforms', self::PLATFORMS);
        return array_change_key_case($platforms, CASE_LOWER);
    }

    /** True when $raw is too long to be worth parsing or logging. */
    public static function is_oversized($raw) {
        return strlen((string) $raw) > self::MAX_LENGTH;
    }

    /**
     * @return string '' when the URL may be processed, otherwise the user-facing reason.
     */
    public static function rejection($url) {
        $url = (string) $url;
        if ($url === '' || self::is_oversized($url) || preg_match('/[\s\x00-\x1f\x7f]/', $url)) {
            return "Please enter a valid video link (starting with https://).";
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return "Please enter a valid video link (starting with https://).";
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return "Only http(s) video links are supported.";
        }
        if (isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true))) {
            return "This link isn't a supported video URL. Please check the address and try again.";
        }
        if (self::platform_domain($parts['host']) === '') {
            return "Links from this site aren't supported. Please use a video link from YouTube, TikTok, Instagram, X, Facebook, LinkedIn, Vimeo or another major video platform.";
        }
        return '';
    }

    /** The PLATFORMS key $host belongs to ('' when none), e.g. m.youtube.com → youtube.com. */
    public static function platform_domain($host) {
        $host = strtolower(rtrim((string) $host, '.'));
        foreach (array_keys(self::platforms()) as $domain) {
            if ($host === $domain || substr($host, -strlen($domain) - 1) === '.' . $domain) {
                return $domain;
            }
        }
        return '';
    }
}
