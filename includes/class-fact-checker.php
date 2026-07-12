<?php
namespace VideoFactChecker;

class FactChecker {
    private $api_key;
    private $model;           // primary/configured model
    private $fallback_model;  // used if the primary returns an empty analysis
    private $used_model = '';  // the model that actually produced the result
    private $logger;

    // Usage from the last check_facts() call, for cost accounting.
    private $last_prompt_tokens = 0;
    private $last_completion_tokens = 0;

    public function __construct() {
        $this->api_key = get_option('vfc_openai_api_key');
        $this->model = get_option('vfc_openai_model', 'gpt-4o-mini');
        $this->fallback_model = get_option('vfc_openai_fallback_model', '');
        $this->logger = new Logger();
    }

    public function get_last_prompt_tokens() { return $this->last_prompt_tokens; }
    public function get_last_completion_tokens() { return $this->last_completion_tokens; }
    public function get_model() { return $this->model; }
    /** The model that actually produced the last result (may be the fallback). */
    public function get_used_model() { return $this->used_model !== '' ? $this->used_model : $this->model; }
    
    /**
     * True for models that only accept the default temperature (1) — the GPT-5 /
     * reasoning family. These reject a custom `temperature` like 0.3.
     */
    private function model_uses_default_temperature($model) {
        return (bool) preg_match('/^(gpt-5|o[0-9])/i', (string) $model);
    }

    public function check_facts($transcription) {
        $this->logger->log("Starting fact check for transcription");
        // Reset per-run state.
        $this->last_prompt_tokens = 0;
        $this->last_completion_tokens = 0;
        $this->used_model = '';

        $prompt = "Please fact check the following text and provide a detailed analysis. " .
                  "Highlight any claims that are verifiable and indicate their accuracy. " .
                  "Text to analyze: " . $transcription;

        // Try the primary model, then the configured fallback if the primary yields
        // no usable content (seen with GPT-5 reasoning models). This guarantees a
        // result while letting us use a newer primary model.
        $models = [$this->model];
        if ($this->fallback_model !== '' && $this->fallback_model !== $this->model) {
            $models[] = $this->fallback_model;
        }

        $last_error = '';
        foreach ($models as $idx => $model) {
            if ($idx > 0) {
                $this->logger->log("Primary model produced no analysis; falling back to {$model}");
            }
            $content = $this->request_analysis($model, $prompt, $last_error);
            if ($content !== '') {
                $this->used_model = $model;
                return $this->format_response($content);
            }
        }

        throw new \Exception('The fact-check could not be generated. ' . $last_error);
    }

    /**
     * Request an analysis from a single model. Returns the content string, or ''
     * if the model returned no usable content after a retry. Sets $last_error by
     * reference. Captures token usage for the successful/last call.
     */
    private function request_analysis($model, $prompt, &$last_error) {
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a fact-checking assistant. Analyze the provided text and identify factual claims, verifying their accuracy where possible. You answer in the language of the transcript. '
                        . 'Format your answer as readable prose with short paragraphs and, where helpful, bullet lists. '
                        . 'Do NOT use tables or any tabular/columnar layout — the results are read on mobile screens where tables do not fit.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
        ];

        // GPT-5 / reasoning models only accept the default temperature (1) and use
        // max_completion_tokens; classic gpt-4.x / gpt-4o models take a custom
        // temperature and use max_tokens.
        if ($this->model_uses_default_temperature($model)) {
            // GPT-5.x are hybrid reasoning models: hidden reasoning tokens are drawn
            // from the same output budget, so a tight budget can yield HTTP 200 with
            // EMPTY content (finish_reason "length"). For transcript fact-checking we
            // don't need chain-of-thought, so disable it and give a generous budget
            // for the visible answer. (reasoning_effort:none is honored on gpt-5.x
            // since OpenAI's Apr-2026 fix.)
            $payload['reasoning_effort'] = 'none';
            $payload['max_completion_tokens'] = 6000;
            $this->logger->log("Model {$model}: reasoning_effort=none, max_completion_tokens=6000 (reasoning-style model)");
        } else {
            $payload['temperature'] = 0.3;
            $payload['max_tokens'] = 4000;
        }

        // Retry once per model on transient/empty responses before giving up on it.
        $max_attempts = 2;
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
                'timeout' => 60
            ]);

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                $this->logger->log("Fact check request failed ({$model}, attempt {$attempt}): {$last_error}", 'error');
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200) {
                $last_error = isset($body['error']['message']) ? $body['error']['message'] : "HTTP {$code}";
                $this->logger->log("Fact check API error ({$model}, attempt {$attempt}): {$last_error}", 'error');
                // A bad-request (e.g. unsupported param) won't fix itself on retry.
                if ($code === 400) {
                    break;
                }
                continue;
            }

            // Capture token usage (accumulate across models for cost accounting).
            if (isset($body['usage'])) {
                $this->last_prompt_tokens += (int) ($body['usage']['prompt_tokens'] ?? 0);
                $this->last_completion_tokens += (int) ($body['usage']['completion_tokens'] ?? 0);
                $this->logger->log(sprintf(
                    "OpenAI usage (%s): prompt=%d completion=%d",
                    $model, (int) ($body['usage']['prompt_tokens'] ?? 0), (int) ($body['usage']['completion_tokens'] ?? 0)
                ));
            }

            $content = isset($body['choices'][0]['message']['content'])
                ? trim((string) $body['choices'][0]['message']['content'])
                : '';
            if ($content !== '') {
                return $content;
            }

            $finish = isset($body['choices'][0]['finish_reason']) ? $body['choices'][0]['finish_reason'] : 'unknown';
            $last_error = "empty analysis content (finish_reason: {$finish})";
            $this->logger->log("Fact check returned empty content ({$model}, attempt {$attempt}, finish_reason {$finish})", 'error');
        }

        return '';
    }
    
    /**
     * Convert GitHub-flavored Markdown tables into mobile-friendly prose/lists.
     * Each data row becomes a bullet line of "Header: cell · Header: cell". Text
     * outside tables is left untouched. Safe no-op if there are no tables.
     */
    private function convert_markdown_tables($content) {
        if (!is_string($content) || strpos($content, '|') === false) {
            return $content;
        }

        $lines = preg_split('/\r?\n/', $content);
        $out = [];
        $n = count($lines);
        $i = 0;

        $is_row = function ($line) {
            return trim($line) !== '' && strpos($line, '|') !== false;
        };
        $is_separator = function ($line) {
            // e.g. |---|:--:|---| — only dashes, colons, pipes, spaces.
            return (bool) preg_match('/^\s*\|?[\s:|-]*-[\s:|-]*\|?\s*$/', $line)
                && strpos($line, '-') !== false;
        };
        $cells = function ($line) {
            $line = trim($line);
            $line = preg_replace('/^\|/', '', $line);
            $line = preg_replace('/\|$/', '', $line);
            $parts = explode('|', $line);
            return array_map('trim', $parts);
        };

        while ($i < $n) {
            // A table = a header row, a separator row, then >=1 data rows.
            if ($i + 1 < $n && $is_row($lines[$i]) && $is_separator($lines[$i + 1])) {
                $headers = $cells($lines[$i]);
                $i += 2; // skip header + separator
                $rows = [];
                while ($i < $n && $is_row($lines[$i]) && !$is_separator($lines[$i])) {
                    $rows[] = $cells($lines[$i]);
                    $i++;
                }
                foreach ($rows as $row) {
                    $pairs = [];
                    foreach ($row as $c => $val) {
                        if ($val === '') {
                            continue;
                        }
                        $label = isset($headers[$c]) ? trim($headers[$c]) : '';
                        $pairs[] = ($label !== '') ? ($label . ': ' . $val) : $val;
                    }
                    if ($pairs) {
                        $out[] = '- ' . implode(' — ', $pairs);
                    }
                }
                $out[] = ''; // blank line after the converted block
                continue;
            }
            $out[] = $lines[$i];
            $i++;
        }

        return implode("\n", $out);
    }

    private function format_response($content) {
        $output_format = get_option('vfc_output_format', 'html');

        // Safety net: the prompt asks the model not to use tables (they don't read
        // well on mobile), but models don't always comply — convert any Markdown
        // tables to readable prose/lists before rendering.
        $content = $this->convert_markdown_tables($content);

        if ($output_format === 'markdown') {
            return $content; // Return raw markdown
        }

        try {
            // Try using Parsedown first
            if (class_exists('\Parsedown')) {
                $parsedown = new \Parsedown();
                // Configure Parsedown for security
                $parsedown->setSafeMode(true);
                return $parsedown->text($content);
            }
        } catch (\Exception $e) {
            $this->logger->log(
                "Parsedown conversion failed, falling back to basic conversion", 
                'warning',
                ['error' => $e->getMessage()]
            );
        }

        // Fallback to basic markdown to HTML conversion (only used when Parsedown
        // is unavailable). Convert headings/lists line-by-line BEFORE escaping, so
        // heading markers are only recognised at the start of a line — never on a
        // stray '#' inside the text (e.g. the '#' in a numeric HTML entity like
        // &#039;, which previously turned into "&<h1>039;" and blew up the font).
        $lines = preg_split('/\r?\n/', $content);
        $html_lines = [];
        $in_list = false;
        foreach ($lines as $line) {
            // Bullet list item.
            if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
                if (!$in_list) { $html_lines[] = '<ul>'; $in_list = true; }
                $html_lines[] = '<li>' . $this->format_inline($m[1]) . '</li>';
                continue;
            }
            if ($in_list) { $html_lines[] = '</ul>'; $in_list = false; }

            // Headings, only when '#' is at the very start of the line.
            if (preg_match('/^\s*(#{1,6})\s+(.*)$/', $line, $m)) {
                $level = min(strlen($m[1]) + 1, 6); // #→h2, ##→h3, … (keep h1 for page)
                $html_lines[] = "<h{$level}>" . $this->format_inline($m[2]) . "</h{$level}>";
                continue;
            }

            if (trim($line) === '') { $html_lines[] = ''; continue; }
            $html_lines[] = '<p>' . $this->format_inline($line) . '</p>';
        }
        if ($in_list) { $html_lines[] = '</ul>'; }

        return implode("\n", $html_lines);
    }

    /**
     * Inline markdown → HTML for a single line. Escapes text first (without
     * double-encoding existing HTML entities), then applies bold/italic/code/links.
     */
    private function format_inline($text) {
        // ENT_HTML5 + double_encode=false leaves existing entities (e.g. &#039;)
        // intact instead of turning them into &amp;#039;.
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
        $patterns = [
            '/\*\*(.*?)\*\*/s' => '<strong>$1</strong>',      // Bold
            '/\*(.*?)\*/s'     => '<em>$1</em>',              // Italic
            '/`(.*?)`/'        => '<code>$1</code>',          // Inline code
            '/\[(.*?)\]\((.*?)\)/' => '<a href="$2">$1</a>',   // Links
        ];
        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }
        return $text;
    }
}