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
                'short_url' => $result->short_url,
                'created_at' => $result->created_at,
                'used_model' => $result->used_model,
            ];
        }

        return null;
    }

    /**
     * @param array $metrics Optional cost/usage metrics for this run. Recognized keys:
     *   platform, openai_prompt_tokens, openai_completion_tokens, openai_cost,
     *   whisper_seconds, whisper_cost, proxy_bytes, proxy_cost, total_cost.
     */
    public function cache_result($url, $transcription, $analysis, $metrics = []) {
        $video_hash = md5($url);
        $short_url = $this->generate_short_url();

        $data = [
            'video_url' => $url,
            'video_hash' => $video_hash,
            'short_url' => $short_url,
            'transcription' => $transcription,
            'analysis' => $analysis,
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s'];

        // Append any provided metrics with the correct format specifiers.
        $metric_formats = [
            'video_title' => '%s',
            'used_model' => '%s',
            'platform' => '%s',
            'openai_prompt_tokens' => '%d',
            'openai_completion_tokens' => '%d',
            'openai_cost' => '%f',
            'whisper_seconds' => '%f',
            'whisper_cost' => '%f',
            'proxy_bytes' => '%d',
            'proxy_cost' => '%f',
            'total_cost' => '%f',
            'cost_estimated' => '%d',
        ];
        foreach ($metric_formats as $key => $fmt) {
            if (array_key_exists($key, $metrics) && $metrics[$key] !== null) {
                $data[$key] = $metrics[$key];
                $formats[] = $fmt;
            }
        }

        $result = $this->wpdb->insert($this->table_name, $data, $formats);

        if ($result === false) {
            $this->logger->log("Failed to cache result for URL: " . $url, 'error');
            return null;
        }

        return $short_url;
    }

    /**
     * Canonical schema for the cache table. Used by both the activation hook and
     * the versioned migration so they never drift. dbDelta adds missing columns.
     */
    public static function get_schema_sql($table_name, $charset_collate) {
        return "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            video_url varchar(2048) NOT NULL,
            video_hash varchar(32) NOT NULL,
            short_url varchar(10) NOT NULL,
            transcription longtext,
            analysis longtext,
            video_title varchar(500) DEFAULT NULL,
            used_model varchar(50) DEFAULT NULL,
            platform varchar(20) DEFAULT NULL,
            openai_prompt_tokens int(11) DEFAULT NULL,
            openai_completion_tokens int(11) DEFAULT NULL,
            openai_cost decimal(10,6) DEFAULT NULL,
            whisper_seconds decimal(10,2) DEFAULT NULL,
            whisper_cost decimal(10,6) DEFAULT NULL,
            proxy_bytes bigint(20) DEFAULT NULL,
            proxy_cost decimal(10,6) DEFAULT NULL,
            total_cost decimal(10,6) DEFAULT NULL,
            cost_estimated tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY video_hash (video_hash),
            UNIQUE KEY short_url (short_url)
        ) $charset_collate;";
    }

    /**
     * Run dbDelta to create/upgrade the table. Safe to call repeatedly.
     */
    public static function ensure_schema() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vfc_cache';
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(self::get_schema_sql($table_name, $charset_collate));
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
            "SELECT id, video_url, short_url, created_at, video_title, platform, analysis FROM {$this->table_name} ORDER BY created_at DESC, id DESC LIMIT %d",
            $limit
        );
        return $this->wpdb->get_results($sql);
    }

    /**
     * Get all transcriptions (ordered newest first)
     */
    public function get_all_transcriptions() {
        $sql = "SELECT id, video_url, short_url, transcription, analysis, created_at,
                       video_title, used_model, platform, openai_cost, whisper_cost, proxy_cost, total_cost, cost_estimated
                FROM {$this->table_name} ORDER BY created_at DESC, id DESC";
        return $this->wpdb->get_results($sql);
    }

    /**
     * One-off backfill: estimate costs for rows that have no total_cost yet.
     * Idempotent — only touches rows where total_cost IS NULL. Returns the count updated.
     */
    public function backfill_estimated_costs() {
        $rows = $this->wpdb->get_results(
            "SELECT id, video_url, transcription, analysis FROM {$this->table_name} WHERE total_cost IS NULL"
        );
        if (empty($rows)) {
            return 0;
        }

        $calc = new CostCalculator();
        $updated = 0;
        foreach ($rows as $row) {
            // Detect the real platform from the URL (youtube, tiktok, instagram, …).
            $platform = VideoProcessor::detect_platform($row->video_url);

            $metrics = $calc->estimate_metrics($platform, $row->transcription, $row->analysis);

            $ok = $this->wpdb->update(
                $this->table_name,
                $metrics,
                ['id' => $row->id],
                ['%s', '%d', '%d', '%f', '%f', '%f', '%d', '%f', '%f', '%d'],
                ['%d']
            );
            if ($ok !== false) {
                $updated++;
            }
        }
        $this->logger->log("Backfilled estimated costs for {$updated} historical rows.");
        return $updated;
    }

    /**
     * One-off: normalize stored video_url values (strip tracking params) and
     * recompute video_hash so future lookups match the cleaned URLs. If two rows
     * normalize to the same URL, keep the most recent and delete the older
     * duplicate. Returns [updated, deleted].
     */
    public function normalize_stored_urls() {
        $rows = $this->wpdb->get_results(
            "SELECT id, video_url, created_at FROM {$this->table_name} ORDER BY created_at DESC, id DESC"
        );
        if (empty($rows)) {
            return [0, 0];
        }

        // Pass 1: group all rows by their target (normalized) hash. Rows are ordered
        // newest-first, so the first row in each group is the one we keep.
        $groups = []; // hash => ['keeper' => row, 'normalized' => url, 'dupes' => [ids]]
        foreach ($rows as $row) {
            $normalized = VideoProcessor::normalize_url($row->video_url);
            $hash = md5($normalized);
            if (!isset($groups[$hash])) {
                $groups[$hash] = ['keeper' => $row, 'normalized' => $normalized, 'dupes' => []];
            } else {
                $groups[$hash]['dupes'][] = $row->id; // older row → duplicate
            }
        }

        $updated = 0;
        $deleted = 0;

        // Pass 2: delete duplicates first (frees the hash), then update keepers.
        foreach ($groups as $hash => $g) {
            foreach ($g['dupes'] as $dupe_id) {
                $this->wpdb->delete($this->table_name, ['id' => $dupe_id], ['%d']);
                $deleted++;
            }
            if ($g['normalized'] !== $g['keeper']->video_url) {
                $this->wpdb->update(
                    $this->table_name,
                    ['video_url' => $g['normalized'], 'video_hash' => $hash],
                    ['id' => $g['keeper']->id],
                    ['%s', '%s'],
                    ['%d']
                );
                $updated++;
            }
        }
        $this->logger->log("Normalized stored URLs: updated={$updated} deleted_dupes={$deleted}");
        return [$updated, $deleted];
    }

    /**
     * One-off: re-detect the platform for every row from its URL, replacing the
     * coarse youtube/other values with real platform names. Returns count updated.
     */
    public function redetect_platforms() {
        $rows = $this->wpdb->get_results("SELECT id, video_url, platform FROM {$this->table_name}");
        if (empty($rows)) {
            return 0;
        }
        $updated = 0;
        foreach ($rows as $row) {
            $detected = VideoProcessor::detect_platform($row->video_url);
            if ($detected !== $row->platform) {
                $ok = $this->wpdb->update(
                    $this->table_name,
                    ['platform' => $detected],
                    ['id' => $row->id],
                    ['%s'],
                    ['%d']
                );
                if ($ok !== false) {
                    $updated++;
                }
            }
        }
        $this->logger->log("Re-detected platform for {$updated} rows.");
        return $updated;
    }

    /**
     * Sum total_cost (and per-service costs) for rows created since a given
     * MySQL datetime (site timezone). Returns an object with the summed columns.
     */
    public function get_cost_summary($since_datetime = null) {
        $where = '';
        $params = [];
        if ($since_datetime !== null) {
            $where = ' WHERE created_at >= %s';
            $params[] = $since_datetime;
        }
        $sql = "SELECT
                    COUNT(*) AS runs,
                    COALESCE(SUM(openai_cost),0) AS openai_cost,
                    COALESCE(SUM(whisper_cost),0) AS whisper_cost,
                    COALESCE(SUM(proxy_cost),0) AS proxy_cost,
                    COALESCE(SUM(total_cost),0) AS total_cost
                FROM {$this->table_name}" . $where;
        if ($params) {
            $sql = $this->wpdb->prepare($sql, $params);
        }
        return $this->wpdb->get_row($sql);
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