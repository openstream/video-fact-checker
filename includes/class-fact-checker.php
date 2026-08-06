<?php
namespace VideoFactChecker;

class FactChecker {
    // Max length of the social link-preview description (og:/twitter:). Kept
    // short so messengers (WhatsApp/Telegram/Slack) show it in full instead of
    // cutting it mid-word.
    const META_MAX_LEN = 120;
    // Max length of the SEO meta description (<meta name="description">). Longer,
    // since Google shows more — a fuller snippet reads better in search results.
    const SEO_MAX_LEN = 160;

    private $api_key;
    private $model;           // primary/configured model
    private $fallback_model;  // used if the primary returns an empty analysis
    private $used_model = '';  // the model that actually produced the result
    private $logger;

    // Usage from the last check_facts() call, for cost accounting.
    private $last_prompt_tokens = 0;
    private $last_completion_tokens = 0;
    // ≤160-char summary of the last analysis, used as the share page's meta
    // description (WhatsApp/social preview). '' when the model omitted it.
    private $last_meta_description = '';

    public function __construct() {
        $this->api_key = get_option('vfc_openai_api_key');
        $this->model = get_option('vfc_openai_model', 'gpt-4o-mini');
        $this->fallback_model = get_option('vfc_openai_fallback_model', '');
        $this->logger = new Logger();
    }

    public function get_last_prompt_tokens() { return $this->last_prompt_tokens; }
    public function get_last_completion_tokens() { return $this->last_completion_tokens; }
    /** ≤160-char summary of the last analysis for the share page's meta description ('' if none). */
    public function get_last_meta_description() { return $this->last_meta_description; }
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
        $this->last_meta_description = '';

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
                // Pull the leading "META: …" line (the model's own ≤160-char
                // summary) out of the raw text before rendering, so it never
                // shows up in the visible analysis.
                $content = $this->extract_meta_description($content);
                return $this->format_response($content);
            }
        }

        throw new \Exception('The fact-check could not be generated. ' . $last_error);
    }

    /**
     * Pull the model's leading "META: <summary>" line out of the raw analysis,
     * store it (trimmed to ≤160 chars at a word boundary) in
     * $last_meta_description, and return the content with that line removed.
     * If there is no META line, the content is returned unchanged.
     */
    private function extract_meta_description($content) {
        if (!preg_match('/^\s*META:\s*(.+?)\s*$/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            return $content;
        }
        // Only treat it as the meta line when it is at the very top of the answer
        // (allow leading blank lines), so a stray "META:" mid-text is left alone.
        if (strlen(trim(substr($content, 0, $m[0][1]))) !== 0) {
            return $content;
        }

        $summary = trim(wp_strip_all_tags($m[1][0]));
        $this->last_meta_description = self::truncate_meta($summary);

        // Remove the whole META line (and any blank line right after it).
        $stripped = substr($content, 0, $m[0][1]) . substr($content, $m[0][1] + strlen($m[0][0]));
        return ltrim($stripped, "\r\n");
    }

    /**
     * Trim a plain-text summary to $max characters, cutting at a word boundary
     * and appending an ellipsis. Shared by the model-written META line, the
     * analysis-derived fallback, and the SEO/social description split, so they
     * all cut cleanly. $max defaults to the short social-preview length.
     */
    public static function truncate_meta($text, $max = self::META_MAX_LEN) {
        $text = trim((string) $text);
        $max = (int) $max;
        if (function_exists('mb_strlen')) {
            if (mb_strlen($text) <= $max) {
                return $text;
            }
            $text = rtrim(mb_substr($text, 0, $max - 1));
            $text = preg_replace('/\s+\S*$/u', '', $text);
            return $text . '…';
        }
        if (strlen($text) <= $max) {
            return $text;
        }
        return rtrim(substr($text, 0, $max - 1)) . '…';
    }

    /**
     * Best-effort language detection from a piece of text, for the share page's
     * <html lang>. Dependency-free stopword scoring over the languages typical
     * for social-video content. Returns a 2-letter ISO code, or '' if unsure.
     */
    public static function detect_language($text) {
        $text = ' ' . mb_strtolower(wp_strip_all_tags((string) $text)) . ' ';
        if (trim($text) === '') {
            return '';
        }
        // High-signal, mostly language-exclusive stopwords per language.
        $sets = [
            'en' => ['the','and','of','to','is','are','that','with','this','you','for','not','have','it'],
            'de' => ['der','die','das','und','ist','nicht','ein','eine','auch','wird','sich','dass','mit','für','über','beim','wenn'],
            'fr' => ['le','la','les','des','une','est','pas','que','pour','dans','avec','sur','vous','nous','ce','cette'],
            'es' => ['el','la','los','las','una','que','por','para','con','como','pero','más','este','esta','son'],
            'it' => ['il','lo','gli','che','non','per','con','una','questo','sono','anche','più','della','delle'],
            'pt' => ['os','as','uma','que','não','para','com','como','mais','este','esta','são','também','está'],
            'nl' => ['de','het','een','en','van','is','niet','dat','met','voor','deze','wordt','ook','zijn'],
        ];
        $scores = [];
        foreach ($sets as $lang => $words) {
            $count = 0;
            foreach ($words as $w) {
                $count += mb_substr_count($text, ' ' . $w . ' ');
            }
            $scores[$lang] = $count;
        }
        arsort($scores);
        $best = array_key_first($scores);
        // Require a minimum signal so short/ambiguous text doesn't mislabel.
        return ($scores[$best] >= 3) ? $best : '';
    }

    /**
     * Build a table of contents for a rendered analysis: parse its section
     * headings, give each a stable id, and return [$toc_html, $html_with_ids].
     *
     * The section heading level depends on the Markdown renderer: Parsedown
     * turns "## " into <h2>, while the built-in fallback converter turns it into
     * <h3>. So we don't hardcode a level — we build the TOC from the SHALLOWEST
     * heading level present (h2 if any, else h3, else h4), which is where the
     * fixed top-level sections land in either case.
     *
     * $toc_html is '' when there are fewer than two such headings (a TOC with a
     * single entry adds noise, not navigation). The returned HTML always has
     * the ids applied so in-page anchors work even without a rendered TOC.
     */
    public static function build_toc($analysis_html, $extra_entry = null) {
        $analysis_html = (string) $analysis_html;
        // The extra entry (e.g. the transcript section) counts toward the TOC, so
        // an analysis with headings always gets a TOC once it's added.
        $extra = (is_array($extra_entry) && !empty($extra_entry['id']) && !empty($extra_entry['title']))
            ? ['id' => (string) $extra_entry['id'], 'title' => (string) $extra_entry['title']]
            : null;

        $items = [];
        if (preg_match('/<h[2-4]\b/i', $analysis_html)) {
            $dom = new \DOMDocument();
            // Load as a UTF-8 fragment without letting libxml inject <html>/<body>
            // wrappers or choke on HTML5 tags.
            $prev = libxml_use_internal_errors(true);
            $dom->loadHTML(
                '<?xml encoding="UTF-8"><div id="vfc-root">' . $analysis_html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            // Use the shallowest heading level that actually appears.
            $headings = null;
            foreach (['h2', 'h3', 'h4'] as $tag) {
                $found = $dom->getElementsByTagName($tag);
                if ($found->length > 0) {
                    $headings = $found;
                    break;
                }
            }
            if ($headings !== null) {
                $i = 0;
                // Snapshot the live NodeList before mutating attributes.
                $nodes = [];
                foreach ($headings as $h) { $nodes[] = $h; }
                foreach ($nodes as $h) {
                    $i++;
                    $id = 'vfc-sec-' . $i;
                    $h->setAttribute('id', $id);
                    $items[] = ['id' => $id, 'title' => trim($h->textContent)];
                }
                // Re-serialize just the inner HTML of #vfc-root.
                $root = $dom->getElementById('vfc-root');
                $html_with_ids = '';
                foreach ($root->childNodes as $child) {
                    $html_with_ids .= $dom->saveHTML($child);
                }
                $analysis_html = $html_with_ids;
            }
        }

        if ($extra !== null) {
            $items[] = $extra;
        }

        // A TOC with fewer than two entries adds noise, not navigation.
        if (count($items) < 2) {
            return ['', $analysis_html];
        }

        $links = '';
        foreach ($items as $it) {
            $links .= '<li><a href="#' . esc_attr($it['id']) . '">'
                . esc_html($it['title']) . '</a></li>';
        }
        $toc_html = '<nav class="vfc-toc" aria-label="Contents"><ol>' . $links . '</ol></nav>';

        return [$toc_html, $analysis_html];
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
                    'content' => 'You are a fact-checking assistant. Analyze the provided text and identify factual claims, verifying their accuracy where possible. '
                        . 'CRITICAL: Write your ENTIRE response — every heading, sentence and the META line — in the SAME LANGUAGE as the transcript you are given. If the transcript is English, answer in English; if it is German, answer in German; and so on. Detect the language from the transcript itself; ignore the language of these instructions. '
                        . 'Format your answer as readable prose with short paragraphs and, where helpful, bullet lists. '
                        . 'Do NOT use tables or any tabular/columnar layout — the results are read on mobile screens where tables do not fit. '
                        . "\n\n"
                        . 'Structure the analysis with EXACTLY these four second-level Markdown headings (lines starting with "## "), in this order. The heading meanings are, in English: '
                        . '(1) a short verdict — 1–2 sentences with the overall conclusion; '
                        . '(2) checked claims — each as a bullet: the claim, then whether it is true / partly true / false / unverifiable, with a brief reason; '
                        . '(3) context and nuance — relevant background; '
                        . '(4) a short closing takeaway. '
                        . 'Write each heading TITLE in the transcript\'s language (e.g. for English use "## Summary", "## Checked claims", "## Context & nuance", "## Takeaway"; for German use "## Kurzfazit", "## Geprüfte Behauptungen", "## Einordnung & Kontext", "## Fazit"). '
                        . 'Use "## " for these section headings and never use a single "#". Do not add other top-level sections. '
                        . "\n\n"
                        . 'Before the analysis, output ONE first line of the form "META: <summary>", where <summary> is a neutral, self-contained overview of the fact-check result in AT MOST 120 CHARACTERS (this is a hard limit — link previews cut off longer text), in the transcript\'s language. '
                        . 'This META line is metadata for link previews — put nothing else on that line and do not repeat it in the body.'
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