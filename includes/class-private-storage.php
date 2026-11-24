<?php
namespace VideoFactChecker;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One non-public folder for everything that must never be served over HTTP:
 * the log (+ its daily .log.1), the optional YouTube cookies.txt and the
 * temporary audio files.
 *
 * Default: wp-content/uploads/vfc-private-<random>/ with a deny-all .htaccess
 * (Apache) and an empty index.php. The random suffix is generated once and kept
 * in the option vfc_private_dir_suffix, so the path is not guessable on servers
 * that ignore .htaccess (nginx, e.g. DDEV). Define VFC_PRIVATE_DIR in
 * wp-config.php to put the folder outside the web root instead.
 *
 * Before v0.27.0 the log lived in wp-content/video-fact-checker.log and
 * cookies.txt / temp/ in the plugin folder, all publicly reachable
 * (GitHub AI Scan #2, #5). migrate_legacy_files() moves them here once.
 */
class PrivateStorage {

    const SUFFIX_OPTION = 'vfc_private_dir_suffix';

    /** Absolute path without trailing slash; created and protected on first use. */
    public static function dir() {
        static $dir = null;
        if ($dir !== null) {
            return $dir;
        }
        if (defined('VFC_PRIVATE_DIR') && VFC_PRIVATE_DIR) {
            $dir = untrailingslashit((string) VFC_PRIVATE_DIR);
        } else {
            $suffix = (string) get_option(self::SUFFIX_OPTION, '');
            if (!preg_match('/^[a-z0-9]{16}$/', $suffix)) {
                $suffix = strtolower(wp_generate_password(16, false, false));
                update_option(self::SUFFIX_OPTION, $suffix, false);
            }
            $uploads = wp_upload_dir(null, false);
            $dir = untrailingslashit($uploads['basedir']) . '/vfc-private-' . $suffix;
        }
        self::protect($dir);
        return $dir;
    }

    public static function log_file() {
        return self::dir() . '/video-fact-checker.log';
    }

    /** Netscape-format cookies for yt-dlp (only used for YouTube without a proxy). */
    public static function cookies_file() {
        return self::dir() . '/cookies.txt';
    }

    /** Temporary audio downloads; created on demand. */
    public static function temp_dir() {
        $tmp = self::dir() . '/tmp';
        if (!is_dir($tmp)) {
            wp_mkdir_p($tmp);
        }
        return $tmp;
    }

    /** Create the folder with deny-all .htaccess + index.php (idempotent). */
    private static function protect($dir) {
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return;
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "# Video Fact Checker: private files (logs, cookies, temp audio)\nRequire all denied\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * One-off move of files from their old public places. Safe to call again:
     * it only acts on files that still exist at the old location.
     */
    public static function migrate_legacy_files() {
        $moved = [];
        $old_log = WP_CONTENT_DIR . '/video-fact-checker.log';
        foreach (['' => '', '.1' => '.1'] as $from_suffix => $to_suffix) {
            $from = $old_log . $from_suffix;
            if (file_exists($from)) {
                $to = self::log_file() . $to_suffix;
                if (file_exists($to)) {
                    // The Logger may already have started a new file: keep both,
                    // old lines first, then drop the public copy.
                    $old = (string) @file_get_contents($from);
                    $new = (string) @file_get_contents($to);
                    if (@file_put_contents($to, $old . $new, LOCK_EX) !== false) {
                        $moved[] = basename($from);
                    }
                    @unlink($from);
                } elseif (@rename($from, $to)) {
                    $moved[] = basename($from);
                } else {
                    @unlink($from);
                }
            }
        }

        $old_cookies = VFC_PLUGIN_DIR . 'cookies.txt';
        if (file_exists($old_cookies)) {
            if (!file_exists(self::cookies_file()) && @rename($old_cookies, self::cookies_file())) {
                @chmod(self::cookies_file(), 0600);
                $moved[] = 'cookies.txt';
            } else {
                @unlink($old_cookies);
            }
        }

        $old_temp = VFC_PLUGIN_DIR . 'temp';
        if (is_dir($old_temp)) {
            foreach ((array) glob($old_temp . '/{,.}*', GLOB_BRACE) as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($old_temp);
        }
        return $moved;
    }

    /** Delete leftover temp audio files older than $max_age seconds (crashed runs). */
    public static function purge_stale_temp($max_age = 3 * HOUR_IN_SECONDS) {
        $tmp = self::dir() . '/tmp';
        if (!is_dir($tmp)) {
            return;
        }
        $cutoff = time() - $max_age;
        foreach ((array) glob($tmp . '/audio_*') as $f) {
            if (is_file($f) && @filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }
}
