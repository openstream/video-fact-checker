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
        </table>

        <?php submit_button(); ?>
    </form>
</div>
