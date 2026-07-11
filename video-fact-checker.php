<?php
/**
 * Plugin Name: Video Fact Checker
 * Plugin URI: https://github.com/nickweisser/video-fact-checker
 * Description: Transcribe and fact-check videos from social media
 * Version: 0.5.1
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
define('VFC_VERSION', '0.5.1');
// Bump when the DB schema changes so existing installs migrate on the next load.
define('VFC_DB_VERSION', 4);

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

/**
 * Show the plugin version in the site footer, but only on pages that actually
 * contain the fact-checker shortcode (so it doesn't appear site-wide).
 * Uses the Twenty Sixteen `twentysixteen_credits` hook — no theme edit needed.
 */
function vfc_current_page_has_shortcode() {
    if (!is_singular()) {
        return false;
    }
    $post = get_post();
    return $post && has_shortcode((string) $post->post_content, 'video_fact_checker');
}

add_action('twentysixteen_credits', function() {
    if (!vfc_current_page_has_shortcode()) {
        return;
    }
    printf(
        '<span class="vfc-footer-version">Fact Checker v%s (Beta)</span><span role="separator" aria-hidden="true"></span>',
        esc_html(defined('VFC_VERSION') ? VFC_VERSION : '')
    );
});

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

// Daily cron: check the daily cost budget, then mail + rotate the log.
add_action('vfc_daily_log_email', function() {
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