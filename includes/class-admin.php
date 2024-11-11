<?php
namespace VideoFactChecker;

class Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_admin_menu() {
        add_options_page(
            'Video Fact Checker Settings',
            'Fact Checker',
            'manage_options',
            'video-fact-checker',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('vfc_settings', 'vfc_openai_api_key');
        register_setting('vfc_settings', 'vfc_enable_logging');
        register_setting('vfc_settings', 'vfc_openai_model');
        register_setting('vfc_settings', 'vfc_output_format');
    }

    public function render_settings_page() {
        include VFC_PLUGIN_DIR . 'templates/admin-settings.php';
    }
}
