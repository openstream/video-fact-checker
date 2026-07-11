<?php
namespace VideoFactChecker;

/**
 * Reports versions of the external dependencies the plugin relies on, for display
 * in the admin. Helps spot an outdated yt-dlp (the usual cause of YouTube breakage)
 * before users hit it.
 */
class SystemInfo {

    // yt-dlp releases roughly weekly; warn if the installed build is older than this
    // many days (its version string is date-based: YYYY.MM.DD).
    const YTDLP_STALE_DAYS = 45;

    /**
     * Run a command's --version and return the first output line, or null.
     */
    private static function cmd_version($binary, $flag = '--version') {
        $path = trim((string) @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
        if ($path === '') {
            return null;
        }
        $out = @shell_exec(escapeshellarg($path) . ' ' . $flag . ' 2>&1');
        if (!is_string($out) || $out === '') {
            return $path; // found but no parseable version
        }
        $line = trim(strtok($out, "\n"));
        // ffmpeg/ffprobe print a long banner; keep just "<name> version <x>".
        if (preg_match('/^(ffmpeg|ffprobe) version (\S+)/i', $line, $m)) {
            return $m[1] . ' ' . $m[2];
        }
        return $line !== '' ? $line : $path;
    }

    /**
     * Days since a yt-dlp date-based version (e.g. "2026.07.04"), or null.
     */
    public static function ytdlp_age_days($version) {
        if (!is_string($version) || !preg_match('/(\d{4})\.(\d{2})\.(\d{2})/', $version, $m)) {
            return null;
        }
        $released = strtotime("{$m[1]}-{$m[2]}-{$m[3]}");
        if (!$released) {
            return null;
        }
        return (int) floor((time() - $released) / DAY_IN_SECONDS);
    }

    /**
     * Returns a list of [label, version, status] rows. status: 'ok' | 'warn' | 'missing'.
     */
    public static function report() {
        $rows = [];

        // PHP
        $rows[] = ['PHP', PHP_VERSION, 'ok'];

        // yt-dlp (with staleness warning)
        $ytdlp = self::cmd_version('yt-dlp');
        if ($ytdlp === null) {
            $rows[] = ['yt-dlp', 'not found', 'missing'];
        } else {
            $age = self::ytdlp_age_days($ytdlp);
            $status = ($age !== null && $age > self::YTDLP_STALE_DAYS) ? 'warn' : 'ok';
            $label_ver = $ytdlp . ($age !== null ? " ({$age} days old)" : '');
            $rows[] = ['yt-dlp', $label_ver, $status];
        }

        // ffmpeg / ffprobe
        $ffmpeg = self::cmd_version('ffmpeg', '-version');
        $rows[] = ['ffmpeg', $ffmpeg ?: 'not found', $ffmpeg ? 'ok' : 'missing'];
        $ffprobe = self::cmd_version('ffprobe', '-version');
        $rows[] = ['ffprobe', $ffprobe ?: 'not found', $ffprobe ? 'ok' : 'missing'];

        // JS runtime for yt-dlp/YouTube (deno preferred, node accepted)
        $deno = self::cmd_version('deno');
        $node = $deno ? null : self::cmd_version('node');
        if ($deno) {
            $rows[] = ['JS runtime (deno)', $deno, 'ok'];
        } elseif ($node) {
            $rows[] = ['JS runtime (node)', $node, 'ok'];
        } else {
            $rows[] = ['JS runtime', 'not found — YouTube may be limited', 'warn'];
        }

        return $rows;
    }
}
