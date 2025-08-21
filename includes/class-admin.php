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
        echo '<th>Date</th>';
        echo '<th>Platform</th>';
        echo '<th>Video</th>';
        echo '<th>Short URL</th>';
        echo '<th>Transcript Size</th>';
        echo '<th>Analysis Summary</th>';
        echo '<th>Fact Check</th>';
        echo '</tr></thead><tbody>';
        foreach ($items as $row) {
            $video_url_raw = is_string($row->video_url) ? $row->video_url : '';
            $video_url = esc_url($video_url_raw);
            $created = esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->created_at)));
            $short_slug = esc_attr($row->short_url);
            $public_url = home_url('/share/' . $short_slug);

            // Platform from host
            $host = '';
            $parsed = @parse_url($video_url_raw);
            if (is_array($parsed) && isset($parsed['host'])) {
                $host = $parsed['host'];
            }
            $platform = esc_html($host ?: '—');



            // Transcript size
            $transcript_text = is_string($row->transcription) ? $row->transcription : '';
            $transcript_size = strlen($transcript_text);
            $transcript_words = str_word_count(strip_tags($transcript_text));
            $transcript_label = esc_html(number_format($transcript_words)) . ' words';

            // Analysis summary (clean HTML entities, show more chars, add toggle)
            $analysis_text = is_string($row->analysis) ? $row->analysis : '';
            $clean_analysis = html_entity_decode(strip_tags($analysis_text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $summary = substr($clean_analysis, 0, 200);
            $has_more = strlen($clean_analysis) > 200;
            $summary_html = esc_html($summary);
            if ($has_more) {
                $summary_html .= ' <button class="button button-small vfc-toggle-summary" data-full="' . esc_attr($clean_analysis) . '">Show more</button>';
            }

            echo '<tr>';
            echo '<td>' . $created . '</td>';
            echo '<td>' . $platform . '</td>';
            echo '<td>'
                . '<button class="button button-small" onclick="window.open(\'' . $video_url . '\', \'_blank\')">Open</button>'
                . ' <button class="button button-small vfc-copy" data-copy="' . esc_attr($video_url_raw) . '">Copy</button>'
                . '</td>';
            echo '<td>'
                . '<code>' . esc_html($short_slug) . '</code>'
                . '<br/><button class="button button-small" onclick="window.open(\'' . esc_url($public_url) . '\', \'_blank\')">Open</button>'
                . ' <button class="button button-small vfc-copy" data-copy="' . esc_attr($public_url) . '">Copy</button>'
                . '</td>';
            echo '<td>' . esc_html($transcript_label) . '</td>';
            echo '<td>' . $summary_html . '</td>';
            echo '<td><a href="' . esc_url($public_url) . '" target="_blank" rel="noopener">Open fact check</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        // Copy-to-clipboard and summary toggle handlers
        echo '<script>
        document.addEventListener("click", function(e) {
            var t = e.target;
            if (t && t.classList && t.classList.contains("vfc-copy")) {
                e.preventDefault();
                var v = t.getAttribute("data-copy");
                if (!v) return;
                navigator.clipboard.writeText(v).then(function() {
                    t.textContent = "Copied";
                    setTimeout(function() { t.textContent = "Copy"; }, 1200);
                });
            } else if (t && t.classList && t.classList.contains("vfc-toggle-summary")) {
                e.preventDefault();
                var full = t.getAttribute("data-full");
                var cell = t.closest("td");
                if (t.textContent === "Show more") {
                    cell.innerHTML = \'<div style="max-height: 300px; overflow-y: auto;">\' + 
                                   \'<div style="white-space: pre-wrap;">\' + full.replace(/</g, "&lt;").replace(/>/g, "&gt;") + \'</div>\' +
                                   \'<button class="button button-small vfc-toggle-summary" data-full="\' + full.replace(/"/g, "&quot;") + \'">Show less</button></div>\';
                } else {
                    var summary = full.substring(0, 200);
                    cell.innerHTML = summary.replace(/</g, "&lt;").replace(/>/g, "&gt;") + 
                                   \' <button class="button button-small vfc-toggle-summary" data-full="\' + full.replace(/"/g, "&quot;") + \'">Show more</button>\';
                }
            }
        });
        </script>';
        echo '</div>';
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
