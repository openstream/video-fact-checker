<?php
namespace VideoFactChecker;

class Logger {
    private $enabled;
    private $current_video_id;
    private $log_file;

    public function __construct() {
        $this->enabled = get_option('vfc_enable_logging', false);
        $this->current_video_id = null;
        
        // Set log file path in wp-content directory
        $this->log_file = WP_CONTENT_DIR . '/video-fact-checker.log';
    }

    public function log($message, $level = 'info', $context = []) {
        if (!$this->enabled) {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s');
        
        // Format context information if provided
        $context_str = '';
        if (!empty($context)) {
            $context_str = ' | ' . implode(' | ', array_map(
                function($key, $value) {
                    return "$key: $value";
                },
                array_keys($context),
                $context
            ));
        }

        // Make messages more human-readable
        $message = $this->humanizeMessage($message);
        
        $log_message = sprintf(
            "[%s] [%s] %s%s\n",
            $timestamp,
            strtoupper($level),
            $message,
            $context_str
        );

        error_log($log_message, 3, $this->log_file);
    }

    public function logVideoMetadata($url, $title = null, $duration = null) {
        if (!$this->enabled) {
            // Still ensure a request id exists for correlation even if logging is disabled
            if ($this->current_video_id === null) {
                $this->current_video_id = substr(md5($url . time()), 0, 8);
            }
            return;
        }

        // Generate a unique ID for this video processing session if not already assigned
        if ($this->current_video_id === null) {
            $this->current_video_id = substr(md5($url . time()), 0, 8);
        }

        $metadata = [
            'video_id' => $this->current_video_id,
            'url' => $url
        ];

        if ($title) {
            $metadata['title'] = $title;
        }

        if ($duration) {
            $metadata['duration'] = $this->formatDuration($duration);
        }

        $this->log("Processing new video", 'info', $metadata);
        
        // Log individual components for easier parsing
        $this->log("Video URL: " . $url);
        if ($title) {
            $this->log("Video Title: " . $title);
        }
        if ($duration) {
            $this->log("Video Duration: " . $this->formatDuration($duration));
        }
    }

    public function assignRequestId($request_id) {
        $this->current_video_id = $request_id;
    }

    private function formatDuration($seconds) {
        if (!is_numeric($seconds)) {
            return $seconds;
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
        } else {
            return sprintf("%02d:%02d", $minutes, $secs);
        }
    }

    private function humanizeMessage($message) {
        // Common file operation messages
        $patterns = [
            '/^Looking for file: (.+)$/' => 'Checking plugin file location: $1',
            '/^Successfully loaded: (.+)$/' => 'Plugin file loaded successfully: $1',
            '/^Attempting to autoload class: (.+)$/' => 'Loading component: $1',
            '/^Video URL: (.+)$/' => 'Video URL: $1',
            '/^Video Title: (.+)$/' => 'Video Title: $1',
            '/^Video Duration: (.+)$/' => 'Video Duration: $1'
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $message)) {
                return preg_replace($pattern, $replacement, $message);
            }
        }

        return $message;
    }

    public function getCurrentVideoId() {
        return $this->current_video_id;
    }
}