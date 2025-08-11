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

            <tr>
                <th scope="row">
                    <label for="vfc_openai_model">AI Model</label>
                </th>
                <td>
                    <select id="vfc_openai_model" name="vfc_openai_model">
                        <?php
                        $current_model = get_option('vfc_openai_model', 'gpt-3.5-turbo');
                        $models = [
                            'gpt-4o' => 'gpt-4o (2024-03-01, High Energy, $0.03/1K tokens)',
                            'gpt-4o-mini' => 'gpt-4o-mini (2024-03-01, Medium Energy, $0.02/1K tokens)', 
                            'openai-o1-preview' => 'openai-o1-preview (2024-01-15, Very High Energy, $0.04/1K tokens)',
                            'openai-o1-mini' => 'openai-o1-mini (2024-01-15, Medium Energy, $0.02/1K tokens)',
                            'gpt-4' => 'gpt-4 (2023-03-14, High Energy, $0.03/1K tokens)',
                            'gpt-3.5-turbo' => 'gpt-3.5-turbo (2022-11-30, Low Energy, $0.002/1K tokens)',
                            'gpt-3.5' => 'gpt-3.5 (2022-11-30, Low Energy, $0.002/1K tokens)'
                        ];
                        foreach ($models as $value => $label) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr($value),
                                selected($current_model, $value, false),
                                esc_html($label)
                            );
                        }
                        ?>
                    </select>
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
</div>
