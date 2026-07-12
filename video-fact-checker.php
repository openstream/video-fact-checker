<?php
/**
 * Plugin Name: Video Fact Checker
 * Plugin URI: https://github.com/nickweisser/video-fact-checker
 * Description: Transcribe and fact-check videos from social media
 * Version: 0.10.3
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
define('VFC_VERSION', '0.10.3');
// Bump when the DB schema changes so existing installs migrate on the next load.
define('VFC_DB_VERSION', 7);

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

// Customize the Twenty Sixteen footer credit. The theme renders, in .site-info:
//   [twentysixteen_credits hook]
//   <span class="site-title"><a href="{home_url}" rel="home">{site name}</a></span>
//   {privacy link}  <a class="imprint">Proudly powered by WordPress</a>
//
// We want:  Home · {Site Name vX (Beta)} → GitHub tag · model: … · How It Works ·
//           Roadmap · Privacy · Made by Openstream …
//
// Clean approach (no unbalanced-tag hacks):
//  - In the credits hook (fires BEFORE the site title) we print, in order: a "Home"
//    link, then our info links, then the "model:" text. These are complete elements.
//  - A `home_url` filter, active only during the credits, points the site-title link
//    at the current GitHub release tag instead of home.
//  - A one-shot `bloginfo('name')` filter appends the version to the site name, so
//    "Site Name vX (Beta)" is the GitHub-linked text.
add_action('twentysixteen_credits', function() {
    // Print the leading "Home" link, our info-page links, and the model note.
    // (These render before the site-title span.)
    $out = sprintf(
        '<a href="%s" class="vfc-footer-home">Home</a>',
        esc_url(home_url('/'))
    );

    foreach ([
        ['how-it-works', 'How It Works', 'vfc-footer-howitworks'],
        ['roadmap', 'Roadmap', 'vfc-footer-roadmap'],
    ] as $item) {
        list($slug, $label, $class) = $item;
        $page = get_page_by_path($slug);
        if ($page) {
            $out .= sprintf(
                '<span role="separator" aria-hidden="true"></span><a href="%s" class="%s">%s</a>',
                esc_url(get_permalink($page)),
                esc_attr($class),
                esc_html($label)
            );
        }
    }

    // Model shown as plain text (not linked).
    $model = get_option('vfc_openai_model', 'gpt-4o-mini');
    $cutoff = VideoFactChecker\CostCalculator::cutoff_for($model);
    $model_label = $model . ($cutoff ? ' (cutoff ' . $cutoff . ')' : '');
    $out .= '<span class="vfc-footer-model" role="separator" aria-hidden="true"></span>'
        . '<span class="vfc-footer-model-text">model: ' . esc_html($model_label) . '</span>'
        . '<span role="separator" aria-hidden="true"></span>';

    echo $out;

    // From here until the site title is printed, point home_url at the GitHub tag,
    // and arm the one-shot version-append on the site name.
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
        $output .= sprintf(' v%s (Beta)', esc_html(defined('VFC_VERSION') ? VFC_VERSION : ''));
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
});

// Also flush rules on deactivation
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();

    // Remove the scheduled daily log email.
    $timestamp = wp_next_scheduled('vfc_daily_log_email');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'vfc_daily_log_email');
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

// Safety net: if the plugin was updated (not reactivated) and the event isn't
// scheduled yet, schedule it on a normal request.
add_action('init', function() {
    if (!wp_next_scheduled('vfc_daily_log_email')) {
        $first = strtotime('tomorrow 03:00');
        wp_schedule_event($first ?: time() + DAY_IN_SECONDS, 'daily', 'vfc_daily_log_email');
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

function vfc_template_redirect() {
    $short_url = get_query_var('vfc_short_url');
    if ($short_url) {
        $logger = new VideoFactChecker\Logger();
        $logger->log("Handling share URL: " . $short_url);
        
        // Debug the exact short URL being looked up
        $logger->log("Looking up exact short URL: '" . $short_url . "'");
        
        // Remove admin bar
        add_filter('show_admin_bar', '__return_false');
        
        // Load the shared result
        $content = do_shortcode("[video_fact_checker_result url_id='" . esc_attr($short_url) . "']");
        
        // Include the template
        require plugin_dir_path(__FILE__) . 'templates/shared-page.php';
        exit;
    }
}
add_action('template_redirect', 'vfc_template_redirect');

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