<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <form action="options.php" method="post">
        <?php
        settings_fields('vfc_settings');
        do_settings_sections('vfc_settings');
        ?>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="vfc_openai_api_key">OpenAI API Key</label>
                </th>
                <td>
                    <input type="password"
                           id="vfc_openai_api_key"
                           name="vfc_openai_api_key"
                           value="<?php echo esc_attr(get_option('vfc_openai_api_key')); ?>"
                           class="regular-text">
                </td>
            </tr>

            <?php
            // Shared option-list renderer for the model dropdowns. Labels + prices
            // come from CostCalculator::MODEL_PRICING (single source of truth).
            $vfc_render_model_options = function ($selected, $include_none = false) {
                if ($include_none) {
                    printf('<option value="" %s>— none —</option>', selected($selected, '', false));
                }
                foreach (\VideoFactChecker\CostCalculator::MODEL_PRICING as $value => $rates) {
                    list($in, $out) = $rates;
                    $cutoff = \VideoFactChecker\CostCalculator::cutoff_for($value);
                    $label = sprintf('%s%s ($%s/1M in · $%s/1M out)', $value,
                        $cutoff ? ' — cutoff ' . $cutoff : '',
                        rtrim(rtrim(number_format($in, 2), '0'), '.'),
                        rtrim(rtrim(number_format($out, 2), '0'), '.'));
                    printf('<option value="%s" %s>%s</option>',
                        esc_attr($value), selected($selected, $value, false), esc_html($label));
                }
            };
            ?>
            <tr>
                <th scope="row">
                    <label for="vfc_openai_model">AI Model</label>
                </th>
                <td>
                    <select id="vfc_openai_model" name="vfc_openai_model">
                        <?php $vfc_render_model_options(get_option('vfc_openai_model', 'gpt-4o-mini')); ?>
                    </select>
                    <p class="description">Primary model used for the fact-check analysis.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="vfc_openai_fallback_model">Fallback Model</label>
                </th>
                <td>
                    <select id="vfc_openai_fallback_model" name="vfc_openai_fallback_model">
                        <?php $vfc_render_model_options(get_option('vfc_openai_fallback_model', ''), true); ?>
                    </select>
                    <p class="description">Used automatically if the primary model returns no analysis (e.g. GPT-5 reasoning models occasionally do). Pick a reliable classic model like <code>gpt-4.1-mini</code>. Set to “none” to disable.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">Enable Logging</th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="vfc_enable_logging"
                               value="1"
                               <?php checked(get_option('vfc_enable_logging')); ?>>
                        Enable debug logging
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="vfc_output_format">Output Format</label>
                </th>
                <td>
                    <select id="vfc_output_format" name="vfc_output_format">
                        <?php
                        $current_format = get_option('vfc_output_format', 'html');
                        $formats = [
                            'html' => 'HTML (formatted)',
                            'markdown' => 'Markdown (raw)',
                        ];
                        foreach ($formats as $value => $label) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr($value),
                                selected($current_format, $value, false),
                                esc_html($label)
                            );
                        }
                        ?>
                    </select>
                    <p class="description">
                        Choose whether to output the fact check results in formatted HTML or raw Markdown format.
                    </p>
                </td>
            </tr>

            <tr>
                <th colspan="2"><h2 style="margin-top: 2em;">Cost accounting</h2></th>
            </tr>
            <tr>
                <td colspan="2">
                    <p class="description">
                        OpenAI chat pricing is taken automatically from the selected AI model above.
                        The rates below are used to estimate the remaining costs. Leave a field
                        empty to use the built-in default. Proxy cost applies to YouTube only.
                    </p>
                </td>
            </tr>
            <?php
            $cost_fields = [
                'vfc_price_whisper_per_min'    => ['Whisper transcription ($ / audio minute)', '0.006'],
                'vfc_price_proxy_per_gb'       => ['Proxy traffic ($ / GB)', '3.75'],
                'vfc_daily_cost_budget'        => ['Daily budget alert ($ / day, 0 = off)', ''],
            ];
            foreach ($cost_fields as $name => $meta):
                list($label, $placeholder) = $meta;
            ?>
            <tr>
                <th scope="row"><label for="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label></th>
                <td>
                    <input type="number" step="0.000001" min="0"
                           id="<?php echo esc_attr($name); ?>"
                           name="<?php echo esc_attr($name); ?>"
                           value="<?php echo esc_attr(get_option($name, '')); ?>"
                           placeholder="<?php echo esc_attr($placeholder); ?>"
                           class="small-text">
                </td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <th scope="row"><label for="vfc_notify_email">Notification email</label></th>
                <td>
                    <input type="email"
                           id="vfc_notify_email"
                           name="vfc_notify_email"
                           value="<?php echo esc_attr(get_option('vfc_notify_email', '')); ?>"
                           placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"
                           class="regular-text">
                    <p class="description">Where error alerts, the daily log, and budget alerts are sent. Defaults to the site admin email.</p>
                </td>
            </tr>

            <tr>
                <th colspan="2"><h2 style="margin-top: 2em;">Proxy (YouTube only)</h2></th>
            </tr>
            <tr>
                <td colspan="2">
                    <p class="description">
                        For YouTube, the plugin will use a <code>cookies.txt</code> file located in the plugin directory
                        (<code>wp-content/plugins/video-fact-checker/cookies.txt</code>). If there is no cookies.txt file,
                        you need to configure and use a proxy.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="vfc_ytdlp_proxy_address">Proxy Address</label>
                </th>
                <td>
                    <input type="text"
                           id="vfc_ytdlp_proxy_address"
                           name="vfc_ytdlp_proxy_address"
                           value="<?php echo esc_attr(get_option('vfc_ytdlp_proxy_address', '')); ?>"
                           class="regular-text"
                           placeholder="e.g. gate.decodo.com">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="vfc_ytdlp_proxy_port">Proxy Port</label>
                </th>
                <td>
                    <input type="number"
                           id="vfc_ytdlp_proxy_port"
                           name="vfc_ytdlp_proxy_port"
                           value="<?php echo esc_attr(get_option('vfc_ytdlp_proxy_port', '')); ?>"
                           class="regular-text"
                           min="1" max="65535" placeholder="e.g. 10001">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="vfc_ytdlp_proxy_username">Proxy Username</label>
                </th>
                <td>
                    <input type="text"
                           id="vfc_ytdlp_proxy_username"
                           name="vfc_ytdlp_proxy_username"
                           value="<?php echo esc_attr(get_option('vfc_ytdlp_proxy_username', '')); ?>"
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="vfc_ytdlp_proxy_password">Proxy Password</label>
                </th>
                <td>
                    <input type="password"
                           id="vfc_ytdlp_proxy_password"
                           name="vfc_ytdlp_proxy_password"
                           value="<?php echo esc_attr(get_option('vfc_ytdlp_proxy_password', '')); ?>"
                           class="regular-text">
                    <p class="description">If provided, credentials will be used for proxy authentication.</p>
                </td>
            </tr>
            
        </table>

        <?php submit_button(); ?>
    </form>

    <h2 style="margin-top: 2em;">System information</h2>
    <p class="description">Versions of the external tools the plugin relies on. If yt-dlp is flagged as old, updating it usually fixes YouTube download problems.</p>
    <table class="widefat striped" style="max-width: 640px;">
        <thead><tr><th>Component</th><th>Version</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach (\VideoFactChecker\SystemInfo::report() as $row):
            list($label, $version, $status) = $row;
            $badge = ['ok' => ['OK', '#2b7b2b'], 'warn' => ['Check', '#b58900'], 'missing' => ['Missing', '#c0392b']];
            list($badge_text, $badge_color) = isset($badge[$status]) ? $badge[$status] : ['—', '#666'];
        ?>
            <tr>
                <td><strong><?php echo esc_html($label); ?></strong></td>
                <td><code><?php echo esc_html($version); ?></code></td>
                <td><span style="color:<?php echo esc_attr($badge_color); ?>;font-weight:600;"><?php echo esc_html($badge_text); ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
