<?php
namespace VideoFactChecker;

class FactChecker {
    private $api_key;
    private $model;
    private $logger;
    
    public function __construct() {
        $this->api_key = get_option('vfc_openai_api_key');
        $this->model = get_option('vfc_openai_model', 'gpt-3.5-turbo');
        $this->logger = new Logger();
    }
    
    public function check_facts($transcription) {
        $this->logger->log("Starting fact check for transcription");
        
        $prompt = "Please fact check the following text and provide a detailed analysis. " .
                  "Highlight any claims that are verifiable and indicate their accuracy. " .
                  "Text to analyze: " . $transcription;
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a fact-checking assistant. Analyze the provided text and identify factual claims, verifying their accuracy where possible. You answer in the language of the transcript'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.3
            ]),
            'timeout' => 60
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->log("Fact check failed: " . $response->get_error_message(), 'error');
            throw new \Exception($response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $this->format_response($body['choices'][0]['message']['content']);
    }
    
    private function format_response($content) {
        $output_format = get_option('vfc_output_format', 'html');
        
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