<?php
namespace VideoFactChecker;

/**
 * Unified, single-style platform icons (inline SVG) for the admin widget and
 * transcriptions table. One visual language: a rounded square badge with the
 * platform's initial letter and brand-ish color — consistent regardless of
 * platform, no external image requests, and crisp at any size.
 */
class PlatformIcon {

    // platform slug => [display label, badge background color]
    const STYLES = [
        'youtube'     => ['YouTube', '#FF0000'],
        'tiktok'      => ['TikTok', '#000000'],
        'instagram'   => ['Instagram', '#C13584'],
        'twitter'     => ['X / Twitter', '#000000'],
        'facebook'    => ['Facebook', '#1877F2'],
        'vimeo'       => ['Vimeo', '#1AB7EA'],
        'twitch'      => ['Twitch', '#9146FF'],
        'dailymotion' => ['Dailymotion', '#0066DC'],
        'reddit'      => ['Reddit', '#FF4500'],
        'linkedin'    => ['LinkedIn', '#0A66C2'],
    ];

    const FALLBACK = ['Video', '#6b7280'];

    public static function label($platform) {
        $s = self::STYLES[$platform] ?? self::FALLBACK;
        return $s[0];
    }

    /**
     * Return an inline SVG badge for a platform, sized $size px. The badge shows
     * the platform's first letter on its brand color — a consistent icon style.
     */
    public static function svg($platform, $size = 24) {
        $style = self::STYLES[$platform] ?? self::FALLBACK;
        list($label, $color) = $style;
        $letter = mb_strtoupper(mb_substr($label, 0, 1));
        $size = (int) $size;
        $radius = max(3, (int) round($size * 0.22));
        $font = max(9, (int) round($size * 0.58));

        return sprintf(
            '<svg width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d" role="img" aria-label="%2$s" '
            . 'style="vertical-align:middle;flex:none;">'
            . '<rect width="%1$d" height="%1$d" rx="%3$d" fill="%4$s"/>'
            . '<text x="50%%" y="50%%" dy="0.35em" text-anchor="middle" '
            . 'font-family="-apple-system,Segoe UI,Roboto,sans-serif" font-weight="700" '
            . 'font-size="%5$d" fill="#ffffff">%6$s</text></svg>',
            $size,
            esc_attr($label),
            $radius,
            esc_attr($color),
            $font,
            esc_html($letter)
        );
    }

    /**
     * Best one-line descriptor of a row: the video title if present, otherwise a
     * very short summary derived from the analysis text (first meaningful clause).
     * Returns a plain string (already trimmed to ~$max chars), or '' if nothing.
     */
    public static function describe($title, $analysis, $max = 90) {
        $title = is_string($title) ? trim($title) : '';
        if ($title !== '') {
            // Titles can contain entities too (e.g. &amp;); decode once.
            $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return self::clip($title, $max);
        }
        // Derive a terse summary from the analysis: strip markup/HTML, take the
        // first sentence-ish chunk. Decode entities twice because stored analysis
        // was HTML-escaped and may contain numeric entities like &#039;.
        $text = is_string($analysis) ? $analysis : '';
        $text = wp_strip_all_tags($text);
        // Repair malformed numeric entities (e.g. "&039;" missing its #) seen in
        // some older stored analyses, then decode normally.
        $text = preg_replace('/&(\d{2,4});/', '&#$1;', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return '';
        }
        // First sentence up to a period/newline, else the leading chunk.
        if (preg_match('/^(.{20,120}?[.!?])(\s|$)/u', $text, $m)) {
            return self::clip(trim($m[1]), $max);
        }
        return self::clip($text, $max);
    }

    private static function clip($s, $max) {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return rtrim(mb_substr($s, 0, $max - 1)) . '…';
    }
}
