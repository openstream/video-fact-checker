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

            // Download and convert to MP3
            $command = sprintf(
                'yt-dlp -x --audio-format mp3 --audio-quality 0 -o %s %s 2>&1',
                escapeshellarg($output_file),
                escapeshellarg($url)
            );

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
        // Use yt-dlp to get video info
        $command = sprintf(
            'yt-dlp --dump-json %s 2>&1',
            escapeshellarg($url)
        );
        
        exec($command, $output, $return_var);
        
        if ($return_var !== 0) {
            throw new \Exception('Failed to get video information. Exit code: ' . $return_var);
        }
        
        if (empty($output)) {
            throw new \Exception('No video information received');
        }
        
        $info = json_decode($output[0], true);
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
}