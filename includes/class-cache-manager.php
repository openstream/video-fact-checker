<?php
namespace VideoFactChecker;

class CacheManager {
    private $wpdb;
    private $table_name;
    private $logger;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'vfc_cache';
        $this->logger = new Logger();
    }

    public function get_cached_result($url) {
        $video_hash = md5($url);
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE video_hash = %s",
                $video_hash
            )
        );

        if ($result) {
            $this->logger->log("Cache hit for URL: " . $url);
            return [
                'transcription' => $result->transcription,
                'analysis' => $result->analysis,
                'short_url' => $result->short_url
            ];
        }

        return null;
    }

    public function cache_result($url, $transcription, $analysis) {
        $video_hash = md5($url);
        $short_url = $this->generate_short_url();

        $result = $this->wpdb->insert(
            $this->table_name,
            [
                'video_url' => $url,
                'video_hash' => $video_hash,
                'short_url' => $short_url,
                'transcription' => $transcription,
                'analysis' => $analysis
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            $this->logger->log("Failed to cache result for URL: " . $url, 'error');
            return null;
        }

        return $short_url;
    }

    /**
     * Get the most recent transcriptions
     */
    public function get_recent_transcriptions($limit = 5) {
        $limit = intval($limit);
        if ($limit <= 0) {
            $limit = 5;
        }
        $sql = $this->wpdb->prepare(
            "SELECT id, video_url, short_url, created_at FROM {$this->table_name} ORDER BY created_at DESC, id DESC LIMIT %d",
            $limit
        );
        return $this->wpdb->get_results($sql);
    }

    /**
     * Get all transcriptions (ordered newest first)
     */
    public function get_all_transcriptions() {
        $sql = "SELECT id, video_url, short_url, transcription, analysis, created_at FROM {$this->table_name} ORDER BY created_at DESC, id DESC";
        return $this->wpdb->get_results($sql);
    }

    public function get_by_short_url($short_url) {
        $this->logger->log("Looking up result for short URL: " . $short_url);
        
        // Debug: Print table structure
        $table_structure = $this->wpdb->get_results("DESCRIBE {$this->table_name}");
        $this->logger->log("Table structure: " . print_r($table_structure, true));
        
        // Debug: Count total rows
        $total_rows = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $this->logger->log("Total rows in table: " . $total_rows);
        
        // Debug: Show all short URLs
        $all_urls = $this->wpdb->get_col("SELECT short_url FROM {$this->table_name}");
        $this->logger->log("All short URLs in database: " . print_r($all_urls, true));
        
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE short_url = %s",
                $short_url
            )
        );

        if ($result) {
            $this->logger->log("Found result for short URL");
            return $result;
        } else {
            $this->logger->log("No result found for short URL");
            $this->logger->log("Query: " . $this->wpdb->last_query);
            $this->logger->log("Last DB error: " . $this->wpdb->last_error);
            return null;
        }
    }

    private function generate_short_url() {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        do {
            $url_id = '';
            for ($i = 0; $i < 6; $i++) {
                $url_id .= $characters[rand(0, strlen($characters) - 1)];
            }
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE short_url = %s",
                    $url_id
                )
            );
        } while ($exists > 0);

        return $url_id;
    }
} 