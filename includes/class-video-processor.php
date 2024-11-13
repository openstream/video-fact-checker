<?php
namespace VideoFactChecker;

class VideoProcessor {
    private $logger;
    private $upload_dir;

    public function __construct() {
        $this->logger = new Logger();
        $upload_dir = wp_upload_dir();
        $this->upload_dir = $upload_dir['basedir'] . '/video-fact-checker';
        
        // Ensure upload directory exists
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
    }

    public function download_video($url) {
        try {
            // Verify yt-dlp is installed
            $this->check_dependencies();
            
            // Get video info first
            $this->logger->log("Fetching video information for: " . $url);
            $video_info = $this->get_video_info($url);
            
            // Log video metadata
            $this->logger->logVideoMetadata(
                $url,
                $video_info['title'] ?? null,
                $video_info['duration'] ?? null
            );
    
            // Generate a unique filename
            $filename = uniqid('vfc_') . '.mp3';
            $output_file = $this->upload_dir . '/' . $filename;

            // Base command without cookies
            $command = 'yt-dlp -x --audio-format mp3 --audio-quality 0 -o %s %s 2>&1';
            
            // Add cookies only for YouTube URLs
            if ($this->is_youtube_url($url)) {
                $cookies_file = plugin_dir_path(__FILE__) . '../cookies.txt';
                if (file_exists($cookies_file)) {
                    $command = 'yt-dlp --cookies %s -x --audio-format mp3 --audio-quality 0 -o %s %s 2>&1';
                    $command = sprintf($command,
                        escapeshellarg($cookies_file),
                        escapeshellarg($output_file),
                        escapeshellarg($url)
                    );
                }
            } else {
                $command = sprintf($command,
                    escapeshellarg($output_file),
                    escapeshellarg($url)
                );
            }

            $this->logger->log("Executing download command");
            exec($command, $output, $return_var);

            // Log the command output
            if (!empty($output)) {
                $this->logger->log("Command output: " . implode("\n", $output));
            }

            if ($return_var !== 0) {
                throw new \Exception('Failed to download and convert video. Exit code: ' . $return_var);
            }

            // Check if file exists and is readable
            if (!file_exists($output_file)) {
                throw new \Exception('Audio file not found after download: ' . $output_file);
            }

            // Verify file size
            $file_size = filesize($output_file);
            if ($file_size === 0) {
                unlink($output_file); // Clean up empty file
                throw new \Exception('Downloaded file is empty');
            }

            $this->logger->log("Successfully downloaded and converted video", 'info', [
                'file' => $output_file,
                'size' => $this->format_file_size($file_size)
            ]);
            
            return $output_file;

        } catch (\Exception $e) {
            $this->logger->log("Error processing video: " . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    private function get_video_info($url) {
        // Validate URL first
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \Exception('Invalid URL format provided: ' . $url);
        }

        // Log the URL we're processing
        $this->logger->log("Processing URL: " . $url);

        // Base command without cookies
        $command = 'yt-dlp --dump-json %s 2>&1';
        
        // Add cookies only for YouTube URLs
        if ($this->is_youtube_url($url)) {
            $cookies_file = plugin_dir_path(__FILE__) . '../cookies.txt';
            $this->logger->log("Checking for cookies file at: " . $cookies_file);
            
            if (file_exists($cookies_file)) {
                $this->logger->log("Found cookies file. Size: " . filesize($cookies_file) . " bytes");
                $this->logger->log("File permissions: " . substr(sprintf('%o', fileperms($cookies_file)), -4));
                $this->logger->log("File contents (first 100 chars): " . substr(file_get_contents($cookies_file), 0, 100));
                
                $command = 'yt-dlp --cookies %s --dump-json %s 2>&1';
                $command = sprintf($command, 
                    escapeshellarg($cookies_file),
                    escapeshellarg($url)
                );
            } else {
                $this->logger->log("WARNING: Cookies file not found at: " . $cookies_file);
                // Try absolute path as fallback
                $cookies_file = WP_PLUGIN_DIR . '/video-fact-checker/cookies.txt';
                $this->logger->log("Trying alternate path: " . $cookies_file);
                
                if (file_exists($cookies_file)) {
                    $this->logger->log("Found cookies file at alternate location");
                    $command = 'yt-dlp --cookies %s --dump-json %s 2>&1';
                    $command = sprintf($command, 
                        escapeshellarg($cookies_file),
                        escapeshellarg($url)
                    );
                } else {
                    $this->logger->log("WARNING: Cookies file not found at alternate location");
                    throw new \Exception(
                        'YouTube authentication required. cookies.txt file not found. ' .
                        'Please ensure the file exists at: ' . $cookies_file
                    );
                }
            }
        } else {
            $command = sprintf('yt-dlp --dump-json %s 2>&1', escapeshellarg($url));
        }
        
        $this->logger->log("Executing command: " . preg_replace('/--cookies\s+[^\s]+/', '--cookies [REDACTED]', $command));
        
        exec($command, $output, $return_var);
        
        if ($return_var !== 0) {
            $error_output = implode("\n", $output);
            $this->logger->log("yt-dlp error output: $error_output", 'error');
            
            // Enhanced error message for YouTube authentication issues
            if (strpos($error_output, 'Sign') !== false) {
                throw new \Exception(
                    'YouTube authentication failed. Please ensure cookies.txt is present and contains valid cookies'
                );
            }
            
            throw new \Exception(sprintf(
                'Failed to get video information. Exit code: %d. Error: %s',
                $return_var,
                $error_output
            ));
        }
        
        if (empty($output)) {
            throw new \Exception('No video information received');
        }
        
        // Try to find the JSON data in the output
        $json_line = null;
        foreach ($output as $line) {
            if ($this->isValidJSON($line)) {
                $json_line = $line;
                break;
            }
        }
        
        if ($json_line === null) {
            $this->logger->log("Raw output: " . implode("\n", $output), 'error');
            throw new \Exception('No valid JSON found in video information');
        }
        
        $info = json_decode($json_line, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid video information received: ' . json_last_error_msg());
        }
        
        return $info;
    }

    private function check_dependencies() {
        exec('yt-dlp --version 2>&1', $output, $return_var);
        
        if ($return_var !== 0) {
            throw new \Exception('yt-dlp is not installed or not accessible');
        }
        
        $this->logger->log("yt-dlp version: " . $output[0]);
    }

    private function format_file_size($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }

    private function isValidJSON($string) {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function is_youtube_url($url) {
        // More strict YouTube URL validation
        $patterns = [
            '#^https?://(?:www\.)?youtube\.com/watch\?v=[\w-]+#',
            '#^https?://(?:www\.)?youtube\.com/v/[\w-]+#',
            '#^https?://youtu\.be/[\w-]+#',
            '#^https?://(?:www\.)?youtube\.com/embed/[\w-]+#'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        
        return false;
    }
}