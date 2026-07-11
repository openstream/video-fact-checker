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

        // Cost accounting: pricing rates (USD) and daily budget.
        // OpenAI chat pricing is derived from the selected model (CostCalculator::MODEL_PRICING),
        // so there are no manual chat-token fields here.
        $float = ['type' => 'number', 'sanitize_callback' => [$this, 'sanitize_float']];
        register_setting('vfc_settings', 'vfc_price_whisper_per_min', $float);     // $ / audio minute
        register_setting('vfc_settings', 'vfc_price_proxy_per_gb', $float);        // $ / GB proxy traffic
        register_setting('vfc_settings', 'vfc_daily_cost_budget', $float);         // $ / day alert threshold
        register_setting('vfc_settings', 'vfc_notify_email', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_email_option'],
        ]);
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



    public function sanitize_float($value) {
        if ($value === '' || $value === null) return '';
        $value = str_replace(',', '.', (string) $value);
        if (!is_numeric($value)) return '';
        $num = (float) $value;
        return $num < 0 ? '' : (string) $num;
    }

    public function sanitize_email_option($value) {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') return '';
        return is_email($value) ? $value : '';
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

        // Cost summary (today / this month / all-time avg).
        $today_start = current_time('Y-m-d') . ' 00:00:00';
        $month_start = current_time('Y-m') . '-01 00:00:00';
        $today = $cache->get_cost_summary($today_start);
        $month = $cache->get_cost_summary($month_start);
        $all   = $cache->get_cost_summary(null);
        $fmt = function($v) { return '$' . number_format((float) $v, 4); };
        $avg = ($all && $all->runs > 0) ? ((float) $all->total_cost / (int) $all->runs) : 0.0;

        echo '<div class="vfc-cost-summary" style="display:flex;gap:24px;flex-wrap:wrap;margin:12px 0 20px;">';
        foreach ([
            ['Today', $today],
            ['This month', $month],
            ['All time', $all],
        ] as $box) {
            list($label, $s) = $box;
            $runs = $s ? (int) $s->runs : 0;
            echo '<div style="border:1px solid #ccd0d4;border-radius:6px;padding:10px 14px;background:#fff;min-width:180px;">';
            echo '<div style="font-weight:600;">' . esc_html($label) . '</div>';
            echo '<div style="font-size:20px;margin:4px 0;">' . esc_html($fmt($s ? $s->total_cost : 0)) . '</div>';
            echo '<div style="color:#666;font-size:12px;">' . esc_html($runs) . ' runs · '
                . 'OpenAI ' . esc_html($fmt($s ? $s->openai_cost : 0))
                . ' · Whisper ' . esc_html($fmt($s ? $s->whisper_cost : 0))
                . ' · Proxy ' . esc_html($fmt($s ? $s->proxy_cost : 0))
                . '</div>';
            echo '</div>';
        }
        echo '<div style="border:1px solid #ccd0d4;border-radius:6px;padding:10px 14px;background:#fff;min-width:180px;">';
        echo '<div style="font-weight:600;">Avg / fact check</div>';
        echo '<div style="font-size:20px;margin:4px 0;">' . esc_html($fmt($avg)) . '</div>';
        echo '<div style="color:#666;font-size:12px;">across ' . esc_html($all ? (int) $all->runs : 0) . ' runs</div>';
        echo '</div>';
        echo '</div>';

        if (empty($items)) {
            echo '<p>No transcriptions found.</p></div>';
            return;
        }

        // Wrap in a horizontally scrollable container so the wide table doesn't
        // get squished on narrow/mobile screens. Drop "fixed" so columns size to
        // their content and can overflow horizontally instead.
        echo '<div class="vfc-table-scroll" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">';
        echo '<table class="widefat striped" style="min-width:1000px;"><thead><tr>';
        echo '<th>Date</th>';
        echo '<th>Platform</th>';
        echo '<th>Title / Summary</th>';
        echo '<th>Video</th>';
        echo '<th>Short URL</th>';
        echo '<th>Transcript Size</th>';
        echo '<th>Cost</th>';
        echo '<th>Analysis Summary</th>';
        echo '<th>Fact Check</th>';
        echo '</tr></thead><tbody>';
        foreach ($items as $row) {
            $video_url_raw = is_string($row->video_url) ? $row->video_url : '';
            $video_url = esc_url($video_url_raw);
            $created = esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->created_at)));
            $short_slug = esc_attr($row->short_url);
            $public_url = home_url('/share/' . $short_slug);

            // Platform: prefer the stored value, else detect from the URL.
            $platform_slug = !empty($row->platform) ? $row->platform : VideoProcessor::detect_platform($video_url_raw);
            $platform_label = PlatformIcon::label($platform_slug);
            $platform_cell = '<span style="display:inline-flex;align-items:center;gap:6px;">'
                . PlatformIcon::svg($platform_slug, 20)
                . '<span>' . esc_html($platform_label) . '</span></span>';

            // One-glance descriptor: video title, or a short summary of the analysis.
            $descriptor = PlatformIcon::describe(
                isset($row->video_title) ? $row->video_title : '',
                isset($row->analysis) ? $row->analysis : '',
                120
            );
            $descriptor_cell = $descriptor !== '' ? esc_html($descriptor) : '<span style="color:#999;">—</span>';

            // Per-run cost (may be null for pre-cost-tracking rows).
            $total_cost = isset($row->total_cost) && $row->total_cost !== null ? (float) $row->total_cost : null;
            if ($total_cost === null) {
                $cost_cell = '<span style="color:#999;">—</span>';
            } else {
                $is_estimated = !empty($row->cost_estimated);
                $breakdown = sprintf(
                    'OpenAI $%s · Whisper $%s · Proxy $%s%s',
                    number_format((float) $row->openai_cost, 4),
                    number_format((float) $row->whisper_cost, 4),
                    number_format((float) $row->proxy_cost, 4),
                    $is_estimated ? ' (estimated from transcript length; proxy not counted)' : ''
                );
                $amount = '$' . esc_html(number_format($total_cost, 4));
                $suffix = $is_estimated ? ' <span style="color:#999;font-size:11px;">(est.)</span>' : '';
                $cost_cell = '<span title="' . esc_attr($breakdown) . '">' . $amount . '</span>' . $suffix;
            }



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
            echo '<td>' . $platform_cell . '</td>';
            echo '<td>' . $descriptor_cell . '</td>';
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
            echo '<td>' . $cost_cell . '</td>';
            echo '<td>' . $summary_html . '</td>';
            echo '<td><a href="' . esc_url($public_url) . '" target="_blank" rel="noopener">Open fact check</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>'; // .vfc-table-scroll
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

            // Unified platform icon (falls back to URL host if platform is unknown).
            $platform = !empty($row->platform) ? $row->platform : VideoProcessor::detect_platform($row->video_url);
            $icon = PlatformIcon::svg($platform, 28);
            $platform_label = PlatformIcon::label($platform);

            // One-glance descriptor: video title, or a short summary of the analysis.
            $descriptor = PlatformIcon::describe(
                isset($row->video_title) ? $row->video_title : '',
                isset($row->analysis) ? $row->analysis : ''
            );

            $public_url = home_url('/share/' . $short_url);

            echo '<div style="display:flex;align-items:flex-start;gap:10px;margin:0 0 12px;">';
            echo '<span title="' . esc_attr($platform_label) . '" style="flex:none;margin-top:2px;">' . $icon . '</span>';
            echo '<div style="min-width:0;flex:1;">';
            if ($descriptor !== '') {
                echo '<div style="font-weight:600;line-height:1.3;">' . esc_html($descriptor) . '</div>';
            } else {
                echo '<div style="font-weight:600;overflow-wrap:anywhere;">' . esc_html($row->video_url) . '</div>';
            }
            echo '<div style="color:#666;font-size:12px;margin:2px 0;">' . $created . ' · ' . esc_html($platform_label) . '</div>';
            echo '<div style="font-size:12px;">'
                . '<a href="' . esc_url($public_url) . '" target="_blank" rel="noopener">Open fact check</a>'
                . ' · <a href="' . esc_url($video_url) . '" target="_blank" rel="noopener">Source</a>'
                . '</div>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo '<p style="margin-top:10px;"><a href="' . esc_url(admin_url('admin.php?page=vfc-transcriptions')) . '">View all transcriptions</a></p>';
    }
}
