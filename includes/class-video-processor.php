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
            $this->logger->log("=== Starting download with debug info ===");
            
            // Get plugin directory and temp directory paths
            $plugin_dir = plugin_dir_path(dirname(__FILE__));
            $output_dir = $plugin_dir . 'temp';
            
            $this->logger->log("Plugin directory: " . $plugin_dir);
            $this->logger->log("Temp directory: " . $output_dir);
            
            // Check plugin directory
            $plugin_perms = substr(sprintf('%o', fileperms($plugin_dir)), -4);
            $plugin_owner = posix_getpwuid(fileowner($plugin_dir));
            $plugin_group = posix_getgrgid(filegroup($plugin_dir));
            
            $this->logger->log("Plugin directory status:");
            $this->logger->log("- Permissions: " . $plugin_perms);
            $this->logger->log("- Owner: " . ($plugin_owner['name'] ?? 'unknown'));
            $this->logger->log("- Group: " . ($plugin_group['name'] ?? 'unknown'));
            
            // Get current process info
            $current_user = posix_getpwuid(posix_geteuid());
            $this->logger->log("Process running as: " . $current_user['name']);
            
            // Create temp directory with proper permissions
            if (!file_exists($output_dir)) {
                $this->logger->log("Creating temp directory with proper permissions");
                
                // First ensure plugin directory is writable
                if (!is_writable($plugin_dir)) {
                    // Attempt to fix plugin directory permissions
                    $this->logger->log("Attempting to fix plugin directory permissions");
                    if (!@chmod($plugin_dir, 0775)) {
                        throw new \Exception(sprintf(
                            "Cannot set plugin directory permissions: %s (current: %s, owner: %s)",
                            $plugin_dir,
                            $plugin_perms,
                            $plugin_owner['name'] ?? 'unknown'
                        ));
                    }
                }
                
                // Create temp directory
                if (!@mkdir($output_dir, 0775)) {
                    $error = error_get_last();
                    throw new \Exception(sprintf(
                        "Failed to create temp directory: %s (error: %s)",
                        $output_dir,
                        $error['message'] ?? 'Unknown error'
                    ));
                }
            }
            
            // Verify temp directory permissions
            $temp_perms = substr(sprintf('%o', fileperms($output_dir)), -4);
            $temp_owner = posix_getpwuid(fileowner($output_dir));
            $temp_group = posix_getgrgid(filegroup($output_dir));
            
            $this->logger->log("Temp directory status:");
            $this->logger->log("- Permissions: " . $temp_perms);
            $this->logger->log("- Owner: " . ($temp_owner['name'] ?? 'unknown'));
            $this->logger->log("- Group: " . ($temp_group['name'] ?? 'unknown'));
            
            // Test write access
            $test_file = $output_dir . '/.test';
            if (@file_put_contents($test_file, 'test') === false) {
                throw new \Exception(sprintf(
                    "Cannot write to temp directory: %s (perms: %s, owner: %s)",
                    $output_dir,
                    $temp_perms,
                    $temp_owner['name'] ?? 'unknown'
                ));
            }
            
            // Verify directory permissions
            $perms = substr(sprintf('%o', fileperms($output_dir)), -4);
            $this->logger->log("Directory permissions: " . $perms);
            
            // Get directory ownership
            $owner = posix_getpwuid(fileowner($output_dir));
            $group = posix_getgrgid(filegroup($output_dir));
            $this->logger->log("Directory owner: " . $owner['name']);
            $this->logger->log("Directory group: " . $group['name']);
            
            // Ensure directory is writable
            if (!is_writable($output_dir)) {
                throw new \Exception("Temp directory is not writable: " . $output_dir);
            }
            
            // Create unique filename
            $filename = uniqid('audio_') . '.%(ext)s';
            $output_file = $output_dir . '/' . $filename;
            $this->logger->log("Output file template: " . $output_file);
            
            // Verify YouTube URL detection
            $is_youtube = $this->is_youtube_url($url);
            $this->logger->log("Is YouTube URL: " . ($is_youtube ? 'yes' : 'no'));
            
            if ($is_youtube) {
                $this->logger->log("=== Checking cookies for YouTube URL ===");
                $cookies_file = plugin_dir_path(dirname(__FILE__)) . 'cookies.txt';
                
                if (file_exists($cookies_file)) {
                    $this->logger->log("Found cookies file. Size: " . filesize($cookies_file) . " bytes");
                    
                    // Verify Netscape format
                    $content = file_get_contents($cookies_file);
                    if (strpos($content, '# Netscape HTTP Cookie File') === false) {
                        $this->logger->log("Converting cookies to Netscape format");
                        $content = "# Netscape HTTP Cookie File\n# https://curl.haxx.se/rfc/cookie_spec.html\n# This is a generated file!  Do not edit.\n\n" . $content;
                        file_put_contents($cookies_file, $content);
                    }
                    
                    $command = sprintf(
                        'yt-dlp --cookies %s -x --audio-format mp3 --audio-quality 0 -o %s %s 2>&1',
                        escapeshellarg($cookies_file),
                        escapeshellarg($output_file),
                        escapeshellarg($url)
                    );
                } else {
                    throw new \Exception('YouTube authentication required: cookies.txt not found');
                }
            } else {
                // Non-YouTube URL - don't use cookies
                $command = sprintf(
                    'yt-dlp -x --audio-format mp3 --audio-quality 0 -o %s %s 2>&1',
                    escapeshellarg($output_file),
                    escapeshellarg($url)
                );
            }

            $this->logger->log("=== Executing final command ===");
            $this->logger->log("Command: " . $command);
            exec($command, $output, $return_var);

            if (!empty($output)) {
                $this->logger->log("Command output: " . implode("\n", $output));
            }

            if ($return_var !== 0) {
                throw new \Exception('Failed to download and convert video. Exit code: ' . $return_var);
            }

            // Find the generated MP3 file
            $mp3_file = str_replace('%(ext)s', 'mp3', $output_file);
            $this->logger->log("Looking for MP3 file: " . $mp3_file);
            
            if (!file_exists($mp3_file)) {
                throw new \Exception('Audio file not found: ' . $mp3_file);
            }
            
            $this->logger->log("Found MP3 file: " . $mp3_file);
            return $mp3_file;
            
        } catch (\Exception $e) {
            $this->logger->log("=== Error in download_video ===");
            $this->logger->log("Error message: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function get_video_info($url) {
        if ($this->is_youtube_url($url)) {
            $cookies_file = $this->check_cookies_file($url);
            $command = sprintf('yt-dlp --cookies %s --dump-json %s 2>&1',
                escapeshellarg($cookies_file),
                escapeshellarg($url)
            );
        } else {
            $command = sprintf('yt-dlp --dump-json %s 2>&1', escapeshellarg($url));
        }
        
        return $command;
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
        $this->logger->log("=== Checking if URL is YouTube ===");
        $this->logger->log("URL to check: " . $url);
        
        // More strict YouTube URL validation
        $patterns = [
            '#^https?://(?:www\.)?youtube\.com/watch\?v=[\w-]+#',
            '#^https?://(?:www\.)?youtube\.com/v/[\w-]+#',
            '#^https?://youtu\.be/[\w-]+#',
            '#^https?://(?:www\.)?youtube\.com/embed/[\w-]+#',
            '#^https?://(?:www\.)?youtube\.com/shorts/[\w-]+#'  // Added shorts pattern
        ];
        
        foreach ($patterns as $pattern) {
            $this->logger->log("Checking pattern: " . $pattern);
            if (preg_match($pattern, $url)) {
                $this->logger->log("✓ Matched YouTube pattern");
                return true;
            }
        }
        
        $this->logger->log("✗ No YouTube patterns matched");
        return false;
    }

    private function check_cookies_file($url) {
        $this->logger->log("DEBUG: Starting cookie file check");
        
        if (!$this->is_youtube_url($url)) {
            $this->logger->log("DEBUG: Not a YouTube URL, skipping cookie check");
            return null;
        }

        // Try both possible paths
        $paths = [
            plugin_dir_path(dirname(__FILE__)) . 'cookies.txt',
            WP_PLUGIN_DIR . '/video-fact-checker/cookies.txt',
            '/var/www/html/wp-content/plugins/video-fact-checker/cookies.txt'
        ];
        
        $this->logger->log("DEBUG: Will check these paths:");
        foreach ($paths as $path) {
            $this->logger->log("DEBUG: - " . $path);
        }
        
        foreach ($paths as $cookies_file) {
            $this->logger->log("DEBUG: Checking: " . $cookies_file);
            
            try {
                if (file_exists($cookies_file)) {
                    $this->logger->log("DEBUG: ✓ File exists");
                    $this->logger->log("DEBUG: File size: " . filesize($cookies_file) . " bytes");
                    $this->logger->log("DEBUG: Permissions: " . substr(sprintf('%o', fileperms($cookies_file)), -4));
                    
                    if (function_exists('posix_getpwuid')) {
                        $owner = posix_getpwuid(fileowner($cookies_file));
                        $group = posix_getgrgid(filegroup($cookies_file));
                        $this->logger->log("DEBUG: Owner: " . ($owner['name'] ?? 'unknown'));
                        $this->logger->log("DEBUG: Group: " . ($group['name'] ?? 'unknown'));
                    }
                    
                    if (is_readable($cookies_file)) {
                        $this->logger->log("DEBUG: ✓ File is readable");
                        $content = file_get_contents($cookies_file);
                        if ($content === false) {
                            $this->logger->log("DEBUG: ✗ Could not read file content");
                            continue;
                        }
                        $this->logger->log("DEBUG: Content preview: " . substr($content, 0, 100));
                        return $cookies_file;
                    } else {
                        $this->logger->log("DEBUG: ✗ File is not readable");
                    }
                } else {
                    $this->logger->log("DEBUG: ✗ File does not exist");
                }
            } catch (\Exception $e) {
                $this->logger->log("DEBUG: Error checking " . $cookies_file . ": " . $e->getMessage());
            }
        }
        
        $error_msg = 'YouTube authentication failed: No readable cookies.txt found. ' .
                     'Checked paths: ' . implode(', ', $paths);
        $this->logger->log("DEBUG: " . $error_msg);
        throw new \Exception($error_msg);
    }
}