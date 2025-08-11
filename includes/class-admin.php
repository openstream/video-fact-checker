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
        // yt-dlp proxy settings (YouTube only)
        register_setting('vfc_settings', 'vfc_ytdlp_proxy_address', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_proxy_address']
        ]);
        register_setting('vfc_settings', 'vfc_ytdlp_proxy_port', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_proxy_port']
        ]);
        register_setting('vfc_settings', 'vfc_ytdlp_proxy_username', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_proxy_username']
        ]);
        register_setting('vfc_settings', 'vfc_ytdlp_proxy_password', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_proxy_password']
        ]);
    }

    public function render_settings_page() {
        include VFC_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    public function sanitize_proxy_address($value) {
        if (!is_string($value)) return '';
        return trim($value);
    }

    public function sanitize_proxy_port($value) {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') return '';
        // Keep numeric only and valid range
        $digits = preg_replace('/[^0-9]/', '', $value);
        $num = (int) $digits;
        if ($num < 1 || $num > 65535) return '';
        return (string) $num;
    }

    public function sanitize_proxy_username($value) {
        if (!is_string($value)) return '';
        return trim($value);
    }

    public function sanitize_proxy_password($value) {
        if (!is_string($value)) return '';
        return trim($value);
    }
}
