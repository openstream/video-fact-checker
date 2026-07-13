<?php
namespace VideoFactChecker;

/**
 * Best-effort request geolocation: resolve the visitor's ISO 3166-1 alpha-2
 * country code from their IP.
 *
 * The server has no Cloudflare (so no CF-IPCountry header) and no PHP geoip
 * extension. Instead we read a MaxMind GeoIP2 Country .mmdb database via the
 * MaxMind reader + database that the Independent Analytics ("iawp") plugin
 * already ships and auto-updates on this site — no new dependency, no external
 * network call, and the visitor IP never leaves the server:
 *
 *   - Reader class: IAWP scopes its vendored reader into \IAWPSCOPED\MaxMind\Db\
 *     Reader; that's what we use. If a plugin ever installs the unscoped
 *     \MaxMind\Db\Reader (e.g. we add maxmind-db/reader to our own composer.json),
 *     we prefer that. reader_class() resolves whichever is present.
 *   - Database (.mmdb): the ~130 MB file IAWP keeps at wp-content/uploads. Path is
 *     filterable (vfc_geoip_mmdb_path) so a different GeoIP2/DB-IP country DB can
 *     be pointed at without code changes.
 *
 * Everything degrades gracefully: if neither reader class is loadable or the
 * .mmdb file is missing (e.g. fresh/local install without IAWP), lookups return
 * '' and the feature quietly shows "—". No hard dependency on IAWP.
 */
class Geo {

    /** Path to a MaxMind Country .mmdb; the one IAWP downloads by default. */
    const MMDB_PATH = WP_CONTENT_DIR . '/uploads/iawp-geo-db.mmdb';

    /**
     * ISO country code for the current request, or '' if it can't be determined
     * (unknown IP, private/local IP, missing DB, or reader unavailable).
     */
    public static function current_country_code() {
        $ip = self::client_ip();
        if ($ip === '') {
            return '';
        }
        return self::country_for_ip($ip);
    }

    /**
     * Resolve a single IP to an ISO alpha-2 country code (uppercase), or ''.
     */
    public static function country_for_ip($ip) {
        if (!is_string($ip) || $ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }
        // Skip private/reserved ranges — they never resolve to a country.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return '';
        }

        $reader_class = self::reader_class();
        if ($reader_class === '') {
            return '';
        }
        $mmdb = self::mmdb_path();
        if ($mmdb === '' || !is_readable($mmdb)) {
            return '';
        }

        try {
            $reader = new $reader_class($mmdb);
            $record = $reader->get($ip);
            $reader->close();
        } catch (\Throwable $e) {
            return '';
        }

        if (is_array($record) && isset($record['country']['iso_code'])) {
            return strtoupper(substr((string) $record['country']['iso_code'], 0, 2));
        }
        return '';
    }

    /**
     * Fully-qualified MaxMind reader class to use, or '' if none is available.
     * Prefers our own unscoped \MaxMind\Db\Reader (installed via the plugin's
     * composer.json / vendor autoload). Falls back to the Independent Analytics
     * plugin's scoped \IAWPSCOPED\MaxMind\Db\Reader if that's all that's present.
     */
    private static function reader_class() {
        if (class_exists('\MaxMind\Db\Reader')) {
            return '\MaxMind\Db\Reader';
        }
        if (class_exists('\IAWPSCOPED\MaxMind\Db\Reader')) {
            return '\IAWPSCOPED\MaxMind\Db\Reader';
        }
        return '';
    }

    /** Path to the .mmdb country database (filterable). '' if it doesn't exist. */
    private static function mmdb_path() {
        $path = (string) apply_filters('vfc_geoip_mmdb_path', self::MMDB_PATH);
        return $path;
    }

    /**
     * The best client IP for this request. Behind Apache directly, REMOTE_ADDR is
     * the real client. We do NOT trust X-Forwarded-For by default (spoofable when
     * there's no known proxy in front); this can be revisited if a trusted proxy
     * is introduced.
     */
    private static function client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * Turn an ISO alpha-2 code into a flag emoji (regional indicator symbols),
     * e.g. "CH" -> 🇨🇭. Returns '' for invalid input.
     */
    public static function flag_emoji($code) {
        $code = is_string($code) ? strtoupper(trim($code)) : '';
        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            return '';
        }
        $a = 0x1F1E6 + (ord($code[0]) - ord('A'));
        $b = 0x1F1E6 + (ord($code[1]) - ord('A'));
        return self::codepoint_to_utf8($a) . self::codepoint_to_utf8($b);
    }

    /** English display name for a country code (e.g. "CH" -> "Switzerland"). */
    public static function country_name($code) {
        $code = is_string($code) ? strtoupper(trim($code)) : '';
        return self::NAMES[$code] ?? $code;
    }

    private static function codepoint_to_utf8($cp) {
        return mb_convert_encoding('&#' . intval($cp) . ';', 'UTF-8', 'HTML-ENTITIES');
    }

    /**
     * Minimal ISO alpha-2 -> English name map for tooltip display. Not exhaustive;
     * unknown codes fall back to the raw code. Covers the countries most likely to
     * appear for this site plus common ones.
     */
    const NAMES = [
        'AD' => 'Andorra', 'AE' => 'United Arab Emirates', 'AF' => 'Afghanistan', 'AL' => 'Albania',
        'AM' => 'Armenia', 'AO' => 'Angola', 'AR' => 'Argentina', 'AT' => 'Austria', 'AU' => 'Australia',
        'AZ' => 'Azerbaijan', 'BA' => 'Bosnia and Herzegovina', 'BD' => 'Bangladesh', 'BE' => 'Belgium',
        'BG' => 'Bulgaria', 'BH' => 'Bahrain', 'BO' => 'Bolivia', 'BR' => 'Brazil', 'BY' => 'Belarus',
        'CA' => 'Canada', 'CH' => 'Switzerland', 'CL' => 'Chile', 'CN' => 'China', 'CO' => 'Colombia',
        'CR' => 'Costa Rica', 'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DE' => 'Germany', 'DK' => 'Denmark',
        'DO' => 'Dominican Republic', 'DZ' => 'Algeria', 'EC' => 'Ecuador', 'EE' => 'Estonia',
        'EG' => 'Egypt', 'ES' => 'Spain', 'FI' => 'Finland', 'FR' => 'France', 'GB' => 'United Kingdom',
        'GE' => 'Georgia', 'GR' => 'Greece', 'GT' => 'Guatemala', 'HK' => 'Hong Kong', 'HR' => 'Croatia',
        'HU' => 'Hungary', 'ID' => 'Indonesia', 'IE' => 'Ireland', 'IL' => 'Israel', 'IN' => 'India',
        'IQ' => 'Iraq', 'IR' => 'Iran', 'IS' => 'Iceland', 'IT' => 'Italy', 'JO' => 'Jordan',
        'JP' => 'Japan', 'KE' => 'Kenya', 'KR' => 'South Korea', 'KW' => 'Kuwait', 'KZ' => 'Kazakhstan',
        'LB' => 'Lebanon', 'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka', 'LT' => 'Lithuania',
        'LU' => 'Luxembourg', 'LV' => 'Latvia', 'MA' => 'Morocco', 'MD' => 'Moldova', 'ME' => 'Montenegro',
        'MK' => 'North Macedonia', 'MT' => 'Malta', 'MX' => 'Mexico', 'MY' => 'Malaysia', 'NG' => 'Nigeria',
        'NL' => 'Netherlands', 'NO' => 'Norway', 'NP' => 'Nepal', 'NZ' => 'New Zealand', 'PA' => 'Panama',
        'PE' => 'Peru', 'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland', 'PT' => 'Portugal',
        'PY' => 'Paraguay', 'QA' => 'Qatar', 'RO' => 'Romania', 'RS' => 'Serbia', 'RU' => 'Russia',
        'SA' => 'Saudi Arabia', 'SE' => 'Sweden', 'SG' => 'Singapore', 'SI' => 'Slovenia', 'SK' => 'Slovakia',
        'TH' => 'Thailand', 'TN' => 'Tunisia', 'TR' => 'Türkiye', 'TW' => 'Taiwan', 'UA' => 'Ukraine',
        'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VE' => 'Venezuela',
        'VN' => 'Vietnam', 'ZA' => 'South Africa',
    ];
}
