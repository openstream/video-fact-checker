<?php
/**
 * Plugin Name: Video Fact Checker
 * Plugin URI: https://github.com/nickweisser/video-fact-checker
 * Description: Transcribe and fact-check videos from social media
 * Version: 1.0
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
    wp_enqueue_style(
        'video-fact-checker',
        VFC_PLUGIN_URL . 'assets/css/style.css',
        [],
        '1.0.0'
    );
    
    wp_enqueue_script(
        'video-fact-checker',
        VFC_PLUGIN_URL . 'js/video-fact-checker.js',
        ['jquery'],
        '1.0.0',
        true
    );
    
    wp_localize_script('video-fact-checker', 'vfcAjax', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('vfc_nonce'),
        'model_info' => get_option('vfc_openai_model', 'GPT-4') // Added model info
    ]);
}

// Activation hook
register_activation_hook(__FILE__, function() {
    // Create upload directory
    $upload_dir = wp_upload_dir();
    $vfc_dir = $upload_dir['basedir'] . '/video-fact-checker';
    if (!file_exists($vfc_dir)) {
        wp_mkdir_p($vfc_dir);
    }
});