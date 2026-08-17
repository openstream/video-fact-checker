<?php
/**
 * Plugin Name: Video Fact Checker
 * Plugin URI: https://github.com/nickweisser/video-fact-checker
 * Description: Transcribe and fact-check videos from social media
 * Version: 0.18.1
 * Author: Nick Weisser
 * Author URI: https://gravatar.com/nickweisser
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * 
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VFC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VFC_PLUGIN_URL', plugin_dir_url(__FILE__));
// Keep in sync with the "Version:" plugin header above (single source for display).
define('VFC_VERSION', '0.18.1');
// Bump when the DB schema changes so existing installs migrate on the next load.
define('VFC_DB_VERSION', 11);

// Autoloader fallback if Composer is not installed
spl_autoload_register(function ($class) {
    static $loaded_classes = [];
    
    // Skip if we've already tried to load this class
    if (isset($loaded_classes[$class])) {
        return;
    }
    
    // Mark this class as processed
    $loaded_classes[$class] = true;
    
    $prefix = 'VideoFactChecker\\';
    $base_dir = VFC_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    
    // Convert camelCase to hyphenated (VideoProcessor -> video-processor)
    $class_name = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $relative_class));
    
    $file = $base_dir . 'class-' . $class_name . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

// Initialize plugin
function vfc_init() {
    if (file_exists(VFC_PLUGIN_DIR . 'vendor/autoload.php')) {
        require_once VFC_PLUGIN_DIR . 'vendor/autoload.php';
    }
    
    new VideoFactChecker\Admin();
    new VideoFactChecker\Ajax();  // Initialize the new Ajax handler
    
    // Register shortcode and scripts
    add_shortcode('video_fact_checker', 'vfc_render_form');
    add_action('wp_enqueue_scripts', 'vfc_enqueue_scripts');
}
add_action('plugins_loaded', 'vfc_init');

// Shortcode render function
function vfc_render_form() {
    ob_start();
    include VFC_PLUGIN_DIR . 'templates/frontend-form.php';

    // Show the most recently fact-checked videos under the form, linking to
    // their public share pages (helps discovery + gives crawlers internal links).
    $vfc_recent = (new VideoFactChecker\CacheManager())->get_recent_transcriptions(3);
    if (!empty($vfc_recent)) {
        include VFC_PLUGIN_DIR . 'templates/recent-checks.php';
    }

    return ob_get_clean();
}

// Enqueue scripts and styles
function vfc_enqueue_scripts() {
    // Use file modification time as the asset version so browsers pick up
    // CSS/JS changes immediately instead of serving a stale cached copy.
    $css_path = VFC_PLUGIN_DIR . 'assets/css/style.css';
    $js_path  = VFC_PLUGIN_DIR . 'js/video-fact-checker.js';
    $css_ver  = file_exists($css_path) ? filemtime($css_path) : '1.0.0';
    $js_ver   = file_exists($js_path) ? filemtime($js_path) : '1.0.0';

    wp_enqueue_style(
        'video-fact-checker',
        VFC_PLUGIN_URL . 'assets/css/style.css',
        [],
        $css_ver
    );

    wp_enqueue_script(
        'video-fact-checker',
        VFC_PLUGIN_URL . 'js/video-fact-checker.js',
        ['jquery'],
        $js_ver,
        true
    );
    
    wp_localize_script('video-fact-checker', 'vfc_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('vfc_nonce')
    ]);
}
add_action('wp_enqueue_scripts', 'vfc_enqueue_scripts');

// FOUC guard: set <html data-vfc-theme> from the saved preference as early as
// possible (before the browser paints), so a manually-chosen theme doesn't flash
// the other one on load. No saved value => attribute stays unset => the CSS
// prefers-color-scheme media query decides (follow the system).
add_action('wp_head', function() {
    ?>
<script>(function(){try{var t=localStorage.getItem('vfc-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-vfc-theme',t);}}catch(e){}})();</script>
    <?php
}, 0);

// Customize the Twenty Sixteen footer credit. The theme renders, in .site-info:
//   [twentysixteen_credits hook]
//   <span class="site-title"><a href="{home_url}" rel="home">{site name}</a></span>
//   {privacy link}  <a class="imprint">Proudly powered by WordPress</a>
//
// We want:  {Site Name vX (Beta)} → GitHub tag · powered by <model> (cutoff …) ·
//           Privacy · Made by Openstream …
//
// - A `home_url` filter (active only during the credits) points the site-title link
//   at the current GitHub release tag instead of home.
// - A one-shot `bloginfo('name')` filter appends the version inside that link, then
//   closes it and emits "powered by <model>" as plain (unlinked) text.
add_action('twentysixteen_credits', function() {
    // Dark-mode toggle, printed inside .site-info (before the credit text). A
    // 2-state switch (light/dark); default follows the system (prefers-color-
    // scheme) until the user picks one, remembered in localStorage. The actual
    // theming is CSS via the --vfc-* variables in style.css. See the FOUC guard
    // (vfc_theme_head_guard) and the click handler in video-fact-checker.js.
    echo '<button type="button" class="vfc-theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">'
        . '<span class="vfc-theme-toggle-icon" aria-hidden="true"></span>'
        . '</button> ';

    // Nav links (Home / How It Works) now live in the header menu, not
    // here. The footer shows: "{Site Name vX (Beta)} → GitHub · powered by <model>
    // (cutoff …) · Privacy · Made by Openstream …".
    // The site title is printed by the theme right after this hook; we point its
    // link at the GitHub tag and append the version to it (via the filters below).
    // Then the model is shown as plain text right after (no "model:" label).
    $model = get_option('vfc_openai_model', 'gpt-4o-mini');
    $cutoff = VideoFactChecker\CostCalculator::cutoff_for($model);
    $model_label = $model . ($cutoff ? ' (cutoff ' . $cutoff . ')' : '');
    // Stash the model note; it is emitted by the bloginfo filter right after the
    // site-title link closes, so it reads directly after the version (no leading
    // separator, no "model:" prefix).
    $GLOBALS['vfc_footer_model_html'] = ' <span class="vfc-footer-model-text">powered by '
        . esc_html($model_label) . '</span>';

    $GLOBALS['vfc_in_footer_credits'] = true;
    $GLOBALS['vfc_append_version_to_name'] = true;
});

// Point the site-title link (only in the footer credits) to the GitHub release tag.
add_filter('home_url', function($url) {
    if (!empty($GLOBALS['vfc_in_footer_credits'])) {
        $ver = defined('VFC_VERSION') ? VFC_VERSION : '';
        return 'https://github.com/openstream/video-fact-checker/releases/tag/v' . rawurlencode($ver);
    }
    return $url;
}, 10, 1);

add_filter('bloginfo', function($output, $show) {
    if ($show === 'name' && !empty($GLOBALS['vfc_append_version_to_name'])) {
        // One-shot: only the footer's site-title occurrence. Also stop rewriting
        // home_url now that the site-title link has been built.
        $GLOBALS['vfc_append_version_to_name'] = false;
        $GLOBALS['vfc_in_footer_credits'] = false;

        // Append the version inside the (GitHub-linked) site title, then close that
        // link and emit the model note as plain text right after — so the version is
        // a link but "powered by <model>" is not. The theme prints its own </a> after
        // this; a browser harmlessly ignores that extra stray close.
        $version = sprintf(' v%s (Beta)', esc_html(defined('VFC_VERSION') ? VFC_VERSION : ''));
        $model_html = isset($GLOBALS['vfc_footer_model_html']) ? $GLOBALS['vfc_footer_model_html'] : '';
        $output .= $version . '</a>' . $model_html;
    }
    return $output;
}, 10, 2);

/**
 * Replace the theme's "Proudly powered by WordPress" credit with our own line
 * naming the authors, including the Claude model that helped build the plugin.
 * The theme calls __('Proudly powered by %s') then printf()s "WordPress" into it,
 * so we return a new template that still consumes that %s.
 */
add_filter('gettext', function($translation, $text, $domain) {
    if ($domain === 'twentysixteen' && $text === 'Proudly powered by %s') {
        $model = get_option('vfc_claude_model', '');
        $claude = $model !== '' ? ('Claude ' . $model) : 'Claude';
        // "Openstream" links to the company site; %s stays for "WordPress".
        $openstream = '<a href="https://www.openstream.ch">Openstream</a>';
        return 'Made by ' . $openstream . ' with %s &amp; ' . esc_html($claude);
    }
    return $translation;
}, 10, 3);

// Activation hook
register_activation_hook(__FILE__, function() {
    global $wpdb;
    
    // Create/upgrade the fact check cache table (includes cost columns).
    VideoFactChecker\CacheManager::ensure_schema();
    update_option('vfc_db_version', VFC_DB_VERSION);

    // Create upload directory
    $upload_dir = wp_upload_dir();
    $vfc_dir = $upload_dir['basedir'] . '/video-fact-checker';
    if (!file_exists($vfc_dir)) {
        wp_mkdir_p($vfc_dir);
    }

    // Add rewrite rules
    vfc_add_rewrite_rules();

    // Flush rewrite rules
    flush_rewrite_rules();

    // Schedule the daily log email (roughly 03:00 site time on first run).
    if (!wp_next_scheduled('vfc_daily_log_email')) {
        $first = strtotime('tomorrow 03:00');
        wp_schedule_event($first ?: time() + DAY_IN_SECONDS, 'daily', 'vfc_daily_log_email');
    }

    // Schedule the weekly OpenAI-model watcher (Mondays ~04:00 site time).
    if (!wp_next_scheduled('vfc_weekly_model_check')) {
        $first = strtotime('next monday 04:00');
        wp_schedule_event($first ?: time() + WEEK_IN_SECONDS, 'weekly', 'vfc_weekly_model_check');
    }
});

// Also flush rules on deactivation
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();

    // Remove the scheduled daily log email.
    $timestamp = wp_next_scheduled('vfc_daily_log_email');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'vfc_daily_log_email');
    }

    // Remove the scheduled weekly model check.
    $model_ts = wp_next_scheduled('vfc_weekly_model_check');
    if ($model_ts) {
        wp_unschedule_event($model_ts, 'vfc_weekly_model_check');
    }
});

// Daily cron: run the health-check smoke test, check the budget, then mail + rotate
// the log. Health check runs first so any failure lands in the same day's log.
add_action('vfc_daily_log_email', function() {
    try {
        (new VideoFactChecker\HealthCheck())->run();
    } catch (\Throwable $e) {
        // Never let the smoke test break the rest of the daily job.
        (new VideoFactChecker\Logger())->log("Health check threw: " . $e->getMessage(), 'error');
    }
    $notifier = new VideoFactChecker\Notifier();
    $notifier->check_daily_budget();
    $notifier->send_daily_log();
});

// Weekly cron: check OpenAI for new models and email the admin if any appeared.
add_action('vfc_weekly_model_check', function() {
    try {
        (new VideoFactChecker\ModelWatcher())->check();
    } catch (\Throwable $e) {
        (new VideoFactChecker\Logger())->log("Model watcher threw: " . $e->getMessage(), 'error');
    }
});

// Safety net: if the plugin was updated (not reactivated) and the events aren't
// scheduled yet, schedule them on a normal request.
add_action('init', function() {
    if (!wp_next_scheduled('vfc_daily_log_email')) {
        $first = strtotime('tomorrow 03:00');
        wp_schedule_event($first ?: time() + DAY_IN_SECONDS, 'daily', 'vfc_daily_log_email');
    }
    if (!wp_next_scheduled('vfc_weekly_model_check')) {
        $first = strtotime('next monday 04:00');
        wp_schedule_event($first ?: time() + WEEK_IN_SECONDS, 'weekly', 'vfc_weekly_model_check');
    }
});

// DB migration on update: if the plugin was updated (not reactivated), bring the
// schema up to date once when the stored version is behind the code's version.
add_action('init', function() {
    if ((int) get_option('vfc_db_version', 0) < VFC_DB_VERSION) {
        VideoFactChecker\CacheManager::ensure_schema();
        update_option('vfc_db_version', VFC_DB_VERSION);

        // One-off: estimate costs for historical rows once, after the cost columns
        // exist. Idempotent (only touches rows without total_cost) and guarded by a
        // flag so it never re-runs.
        if (!get_option('vfc_costs_backfilled')) {
            (new VideoFactChecker\CacheManager())->backfill_estimated_costs();
            update_option('vfc_costs_backfilled', 1);
        }

        // One-off: replace coarse youtube/other platform values with real platform
        // names (tiktok, instagram, …) detected from each row's URL.
        if (!get_option('vfc_platforms_redetected')) {
            (new VideoFactChecker\CacheManager())->redetect_platforms();
            update_option('vfc_platforms_redetected', 1);
        }

        // One-off: strip tracking params from stored URLs and recompute hashes so
        // future lookups hit the cache regardless of share/tracking query strings.
        if (!get_option('vfc_urls_normalized')) {
            (new VideoFactChecker\CacheManager())->normalize_stored_urls();
            update_option('vfc_urls_normalized', 1);
        }

        // One-off: the per-user daily rate limits were removed (costs are low even
        // for YouTube). Delete the leftover per-user/per-day counter options that the
        // old RateLimiter wrote (keys like vfc_rl_youtube_YYYYMMDD_<user>), which are
        // date-scoped and never self-delete otherwise.
        if (!get_option('vfc_rate_limit_options_purged')) {
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'vfc_rl\\_%'");
            update_option('vfc_rate_limit_options_purged', 1);
        }
    }
});

// Add these new functions
function vfc_shared_result($atts) {
    $url_id = isset($atts['url_id']) ? sanitize_text_field($atts['url_id']) : '';
    if (!$url_id) {
        return '<p>Invalid fact check URL.</p>';
    }

    $cache_manager = new VideoFactChecker\CacheManager();
    $result = $cache_manager->get_by_short_url($url_id);

    if (!$result) {
        return '<p>Fact check not found.</p>';
    }

    ob_start();
    include VFC_PLUGIN_DIR . 'templates/shared-result.php';
    return ob_get_clean();
}
add_shortcode('video_fact_checker_result', 'vfc_shared_result');

/**
 * Render the shared-result template from a result object (stdClass or array),
 * so the inline result shown right after a fact-check is IDENTICAL to the
 * /share/ page — same table of contents, same transcript-at-the-end layout.
 * One rendering source, no JS/PHP duplication.
 */
function vfc_render_result_html($result) {
    if (is_array($result)) {
        $result = (object) $result;
    }
    if (!is_object($result) || empty($result->analysis)) {
        return '';
    }
    ob_start();
    include VFC_PLUGIN_DIR . 'templates/shared-result.php';
    return ob_get_clean();
}

function vfc_add_rewrite_rules() {
    add_rewrite_rule(
        'share/([A-Za-z0-9]+)/?$',
        'index.php?vfc_short_url=$matches[1]',
        'top'
    );
}
add_action('init', 'vfc_add_rewrite_rules');

function vfc_add_query_vars($vars) {
    $vars[] = 'vfc_short_url';
    return $vars;
}
add_filter('query_vars', 'vfc_add_query_vars');

/**
 * Permanent (301) redirects between share codes. Used when a fact-check was
 * regenerated under a new short URL (e.g. an old result in the wrong language)
 * and we want existing links + search engines to follow to the correct one.
 * Map: old short code => new short code.
 */
function vfc_share_redirects() {
    return [
        // Regenerated to fix a wrong-language analysis (EN video → DE analysis).
        'bdWIuG' => 'ZTwg4N', // CAR-T cell therapy (Instagram)
    ];
}

function vfc_template_redirect() {
    $short_url = get_query_var('vfc_short_url');
    if ($short_url) {
        // 301 old → new share codes before doing anything else.
        $redirects = vfc_share_redirects();
        if (isset($redirects[$short_url])) {
            wp_redirect(home_url('/share/' . $redirects[$short_url] . '/'), 301);
            exit;
        }

        $logger = new VideoFactChecker\Logger();
        $logger->log("Handling share URL: " . $short_url);

        // Debug the exact short URL being looked up
        $logger->log("Looking up exact short URL: '" . $short_url . "'");

        // Look the result up first. An unknown/deleted code must serve a real 404
        // (so search engines deindex it), not a 200 "not found" page.
        $vfc_meta_result = (new VideoFactChecker\CacheManager())->get_by_short_url($short_url);
        if (!$vfc_meta_result) {
            $logger->log("Share URL not found, serving 404: '" . $short_url . "'");
            global $wp_query;
            status_header(404);
            nocache_headers();
            if ($wp_query instanceof \WP_Query) {
                $wp_query->set_404();
            }
            require get_query_template('404');
            exit;
        }

        // This is a real, resolvable page — serve 200, not the default 404 that the
        // (unmatched) main query would otherwise imply, and give it a proper title.
        status_header(200);
        global $wp_query;
        if ($wp_query instanceof \WP_Query) {
            $wp_query->is_404 = false;
        }
        add_filter('pre_get_document_title', function() {
            return 'Video Fact Check Results · ' . get_bloginfo('name');
        });

        vfc_emit_share_meta_tags($vfc_meta_result, home_url('/share/' . $short_url . '/'));

        // Load the shared result
        $content = do_shortcode("[video_fact_checker_result url_id='" . esc_attr($short_url) . "']");

        // Render inside the site theme (header nav + footer credit).
        require plugin_dir_path(__FILE__) . 'templates/shared-page.php';
        exit;
    }
}
add_action('template_redirect', 'vfc_template_redirect');

/**
 * Register the fact-check share pages as a sub-sitemap inside Rank Math's
 * /sitemap_index.xml. Guarded so it only runs when Rank Math's sitemap provider
 * interface is available (referencing it otherwise would fatal on class load).
 */
add_filter('rank_math/sitemap/providers', function ($providers) {
    if (interface_exists('\\RankMath\\Sitemap\\Providers\\Provider')) {
        $providers[] = new VideoFactChecker\SitemapProvider();
    }
    return $providers;
});

/**
 * Feed the share page's title + descriptions to the SEO/meta layer.
 *
 * The /share/ route resolves to no WP post, so an SEO plugin would otherwise
 * emit a generic site-wide description. Rank Math is active here; we hook its
 * filters so tags render once, with no duplicates. If Rank Math is absent we
 * echo the tags ourselves on wp_head.
 *
 * Two descriptions, on purpose:
 *  - SEO (<meta name="description">, ≤160): derived from the analysis text —
 *    a fuller, informative snippet reads better in Google results.
 *  - Social (og:/twitter:description, ≤120): the short model-written
 *    meta_description, so messengers show it in full without truncating.
 */
function vfc_emit_share_meta_tags($result, $canonical_url) {
    $descriptor = \VideoFactChecker\PlatformIcon::describe(
        isset($result->video_title) ? $result->video_title : '',
        isset($result->analysis) ? $result->analysis : '',
        90
    );
    $title = ($descriptor !== '')
        ? ('Fact check: ' . $descriptor)
        : 'Video Fact Check Results';

    // Set <html lang> to the content's language (SEO + accessibility) instead of
    // the site default. Prefer the stored language; else detect it from the
    // analysis (or transcript) for rows saved before we recorded it.
    $lang = isset($result->lang) ? strtolower(substr((string) $result->lang, 0, 5)) : '';
    if ($lang === '') {
        $lang = \VideoFactChecker\FactChecker::detect_language(
            (string) ($result->analysis ?? '') . ' ' . (string) ($result->transcription ?? '')
        );
    }
    if ($lang !== '') {
        add_filter('language_attributes', function ($output) use ($lang) {
            // Replace the lang="…" attribute; leave dir/other attrs intact.
            if (preg_match('/lang=/i', $output)) {
                return preg_replace('/lang="[^"]*"/i', 'lang="' . esc_attr($lang) . '"', $output);
            }
            return $output . ' lang="' . esc_attr($lang) . '"';
        });
    }

    // Clean, plain-text rendering of the analysis (used for the SEO description
    // and as the fallback social description). Repairs the legacy "&039;"
    // entity corruption and decodes entities before measuring length.
    $analysis_text = wp_strip_all_tags((string) ($result->analysis ?? ''));
    $analysis_text = preg_replace('/&(\d+);/', '&#$1;', $analysis_text);
    $analysis_text = html_entity_decode($analysis_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $analysis_text = trim(preg_replace('/\s+/u', ' ', $analysis_text));

    // Social: the short model summary, else the analysis text — both trimmed to
    // the short social length.
    $social_source = isset($result->meta_description) ? trim((string) $result->meta_description) : '';
    if ($social_source === '') {
        $social_source = $analysis_text;
    }
    $social_desc = \VideoFactChecker\FactChecker::truncate_meta(
        $social_source, \VideoFactChecker\FactChecker::META_MAX_LEN
    );

    // SEO: the longer, analysis-derived description (falls back to the social
    // text if there's no analysis for some reason).
    $seo_source = $analysis_text !== '' ? $analysis_text : $social_source;
    $seo_desc = \VideoFactChecker\FactChecker::truncate_meta(
        $seo_source, \VideoFactChecker\FactChecker::SEO_MAX_LEN
    );

    // Preferred path: let the SEO plugin render the tags from our values.
    // frontend/description feeds <meta name="description"> (SEO); the per-network
    // og_description / twitter_description filters override just the social tags.
    if (defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper')) {
        add_filter('rank_math/frontend/title', function () use ($title) { return $title; });
        add_filter('rank_math/frontend/description', function () use ($seo_desc) { return $seo_desc; });
        add_filter('rank_math/opengraph/type', function () { return 'article'; });
        add_filter('rank_math/opengraph/facebook/og_description', function () use ($social_desc) { return $social_desc; });
        add_filter('rank_math/opengraph/twitter/twitter_description', function () use ($social_desc) { return $social_desc; });
        return;
    }

    // Fallback: no SEO plugin — emit the tags directly (SEO desc on the meta
    // description, social desc on og:/twitter:).
    add_action('wp_head', function () use ($title, $seo_desc, $social_desc, $canonical_url) {
        $out  = '<meta name="description" content="' . esc_attr($seo_desc) . '">' . "\n";
        $out .= '<meta property="og:type" content="article">' . "\n";
        $out .= '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        $out .= '<meta property="og:description" content="' . esc_attr($social_desc) . '">' . "\n";
        $out .= '<meta property="og:url" content="' . esc_url($canonical_url) . '">' . "\n";
        $out .= '<meta name="twitter:card" content="summary">' . "\n";
        $out .= '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        $out .= '<meta name="twitter:description" content="' . esc_attr($social_desc) . '">' . "\n";
        echo $out;
    }, 1);
}

// Add shortcode handler if it doesn't exist
function vfc_shared_result_shortcode($atts) {
    $logger = new VideoFactChecker\Logger();
    
    $url_id = isset($atts['url_id']) ? trim($atts['url_id']) : '';
    $logger->log("Processing shortcode for URL ID: '" . $url_id . "'");
    
    if (!$url_id) {
        $logger->log("No URL ID provided");
        return '<p>Invalid fact check URL.</p>';
    }

    try {
        $cache_manager = new VideoFactChecker\CacheManager();
        $result = $cache_manager->get_by_short_url($url_id);

        if (!$result) {
            $logger->log("No result found for URL ID: '" . $url_id . "'");
            return '<p>Fact check not found.</p>';
        }

        $logger->log("Found result, rendering template");
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/shared-result.php';
        return ob_get_clean();
        
    } catch (\Exception $e) {
        $logger->log("Error processing shortcode: " . $e->getMessage());
        return '<p>Error retrieving fact check results.</p>';
    }
}
add_shortcode('video_fact_checker_result', 'vfc_shared_result_shortcode');