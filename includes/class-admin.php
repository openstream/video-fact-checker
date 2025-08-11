<?php
namespace VideoFactChecker;

class Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);
    }

    public function add_admin_menu() {
        add_options_page(
            'Video Fact Checker Settings',
            'Fact Checker',
            'manage_options',
            'video-fact-checker',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'options-general.php',
            'All Transcriptions',
            'All Transcriptions',
            'manage_options',
            'vfc-transcriptions',
            [$this, 'render_transcriptions_page']
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

    public function render_transcriptions_page() {
        $cache = new CacheManager();
        $items = $cache->get_all_transcriptions();
        echo '<div class="wrap"><h1>All Transcriptions</h1>';
        if (empty($items)) {
            echo '<p>No transcriptions found.</p></div>';
            return;
        }

        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th>Date</th><th>Video</th><th>Fact Check</th>';
        echo '</tr></thead><tbody>';
        foreach ($items as $row) {
            $video_url = esc_url($row->video_url);
            $created = esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->created_at)));
            $public_url = home_url('/share/' . esc_attr($row->short_url));
            echo '<tr>';
            echo '<td>' . $created . '</td>';
            echo '<td><a href="' . $video_url . '" target="_blank" rel="noopener">' . $video_url . '</a></td>';
            echo '<td><a href="' . esc_url($public_url) . '" target="_blank" rel="noopener">Open fact check</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public function register_dashboard_widget() {
        wp_add_dashboard_widget(
            'vfc_recent_transcriptions',
            'Video Fact Checker – Recent Transcriptions',
            [$this, 'render_dashboard_widget']
        );
    }

    public function render_dashboard_widget() {
        $cache = new CacheManager();
        $items = $cache->get_recent_transcriptions(5);
        if (empty($items)) {
            echo '<p>No recent transcriptions.</p>';
            return;
        }

        echo '<div class="vfc-recent-list" style="margin:0;">';
        foreach ($items as $row) {
            $video_url = esc_url($row->video_url);
            $short_url = esc_attr($row->short_url);
            $created = esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->created_at)));

            // Thumbnail via oEmbed preview where possible (YouTube quick heuristic)
            $thumb = '';
            if (preg_match('#youtu(?:\.be|be\.com)/(?:shorts/|watch\?v=|embed/|v/)?([\w-]+)#i', $row->video_url, $m)) {
                $thumb = sprintf('https://img.youtube.com/vi/%s/hqdefault.jpg', esc_attr($m[1]));
            }

            $thumb_html = $thumb ? '<img src="' . esc_url($thumb) . '" alt="" style="width:72px;height:auto;border-radius:4px;margin-right:8px;object-fit:cover;" />' : '';
            $frontend_link = admin_url('admin-ajax.php');
            // Build public share URL
            $public_url = home_url('/share/' . $short_url);

            echo '<div style="margin:0 0 10px;">';
            echo '<div style="display:flex;align-items:center;">';
            echo $thumb_html;
            echo '<div>';
            echo '<div style="font-weight:600;">' . $created . '</div>';
            echo '<div><a href="' . esc_url($video_url) . '" target="_blank" rel="noopener">' . esc_html($video_url) . '</a></div>';
            echo '<div><a href="' . esc_url($public_url) . '" target="_blank" rel="noopener">Open fact check</a></div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo '<p style="margin-top:10px;"><a href="' . esc_url(admin_url('admin.php?page=vfc-transcriptions')) . '">View all transcriptions</a></p>';
    }
}
