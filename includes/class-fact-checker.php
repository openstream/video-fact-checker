<?php
namespace VideoFactChecker;

class FactChecker {
    private $api_key;
    private $model;
    private $logger;

    // Usage from the last check_facts() call, for cost accounting.
    private $last_prompt_tokens = 0;
    private $last_completion_tokens = 0;

    public function __construct() {
        $this->api_key = get_option('vfc_openai_api_key');
        $this->model = get_option('vfc_openai_model', 'gpt-4o-mini');
        $this->logger = new Logger();
    }

    public function get_last_prompt_tokens() { return $this->last_prompt_tokens; }
    public function get_last_completion_tokens() { return $this->last_completion_tokens; }
    public function get_model() { return $this->model; }
    
    /**
     * True for models that only accept the default temperature (1) — the GPT-5 /
     * reasoning family. These reject a custom `temperature` like 0.3.
     */
    private function model_uses_default_temperature($model) {
        return (bool) preg_match('/^(gpt-5|o[0-9])/i', (string) $model);
    }

    public function check_facts($transcription) {
        $this->logger->log("Starting fact check for transcription");
        // Reset per-run usage.
        $this->last_prompt_tokens = 0;
        $this->last_completion_tokens = 0;
        
        $prompt = "Please fact check the following text and provide a detailed analysis. " .
                  "Highlight any claims that are verifiable and indicate their accuracy. " .
                  "Text to analyze: " . $transcription;
        
        $payload = [
            'model' => $this->model,
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
        // a different token-limit parameter. Classic gpt-4.x / gpt-4o models take a
        // custom temperature. Send the right shape per model.
        if ($this->model_uses_default_temperature($this->model)) {
            // Omit temperature entirely (defaults to 1 — the only value allowed).
            $this->logger->log("Model {$this->model}: using default temperature (reasoning-style model)");
        } else {
            $payload['temperature'] = 0.3;
        }

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($payload),
            'timeout' => 60
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->log("Fact check failed: " . $response->get_error_message(), 'error');
            throw new \Exception($response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Capture token usage for cost accounting (present on Chat Completions).
        if (isset($body['usage'])) {
            $this->last_prompt_tokens = (int) ($body['usage']['prompt_tokens'] ?? 0);
            $this->last_completion_tokens = (int) ($body['usage']['completion_tokens'] ?? 0);
            $this->logger->log(sprintf(
                "OpenAI usage: prompt=%d completion=%d",
                $this->last_prompt_tokens, $this->last_completion_tokens
            ));
        }

        return $this->format_response($body['choices'][0]['message']['content']);
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

        // Fallback to basic markdown to HTML conversion
        $formatted = nl2br(htmlspecialchars($content));
        
        // Convert markdown syntax to HTML
        $patterns = [
            '/\*\*(.*?)\*\*/s' => '<strong>$1</strong>',  // Bold
            '/\*(.*?)\*/s' => '<em>$1</em>',              // Italic
            '/#{3}(.*?)\n/' => '<h3>$1</h3>',            // H3
            '/#{2}(.*?)\n/' => '<h2>$1</h2>',            // H2
            '/#{1}(.*?)\n/' => '<h1>$1</h1>',            // H1
            '/`(.*?)`/' => '<code>$1</code>',            // Inline code
            '/\[(.*?)\]\((.*?)\)/' => '<a href="$2">$1</a>', // Links
        ];
        
        foreach ($patterns as $pattern => $replacement) {
            $formatted = preg_replace($pattern, $replacement, $formatted);
        }
        
        // Convert bullet points
        $formatted = preg_replace('/^- (.*?)$/m', '<li>$1</li>', $formatted);
        $formatted = preg_replace('/<li>.*?<\/li>/s', '<ul>$0</ul>', $formatted);
        
        return $formatted;
    }
}