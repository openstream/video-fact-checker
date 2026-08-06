<?php
namespace VideoFactChecker;

/**
 * Adds the public fact-check share pages (/share/<code>/) to Rank Math's XML
 * sitemap as its own sub-sitemap ("video-fact-checks-sitemap.xml") inside the
 * existing /sitemap_index.xml. This way there's ONE sitemap to submit to Google
 * and Bing, and the /share/ URLs — which aren't WP posts, so Rank Math wouldn't
 * find them on its own — get indexed.
 *
 * Registered via the `rank_math/sitemap/providers` filter (see the bootstrap).
 * Implements RankMath\Sitemap\Providers\Provider — instantiated lazily only when
 * that interface exists, so a missing/disabled Rank Math never causes a fatal.
 */
class SitemapProvider implements \RankMath\Sitemap\Providers\Provider {

    const SLUG = 'video-fact-checks';

    /** Entries per sub-sitemap page (kept comfortably under Rank Math's limit). */
    const PER_PAGE = 1000;

    private function cache() {
        return new CacheManager();
    }

    /**
     * Full URL of a sub-sitemap file, e.g. …/video-fact-checks-sitemap.xml or
     * …/video-fact-checks-sitemap2.xml. Uses Rank Math's Router so it matches
     * however the site's sitemap URLs are shaped.
     */
    private function sitemap_url($page = '') {
        return \RankMath\Sitemap\Router::get_base_url(self::SLUG . '-sitemap' . $page . '.xml');
    }

    /**
     * Does this provider serve the given sitemap type?
     */
    public function handles_type($type) {
        return self::SLUG === $type;
    }

    /**
     * Index links: one entry per sub-sitemap page, for /sitemap_index.xml.
     */
    public function get_index_links($max_entries) {
        $total = $this->cache()->count_sitemap_rows();
        if ($total === 0) {
            return [];
        }

        $per_page  = min((int) $max_entries, self::PER_PAGE);
        $per_page  = $per_page > 0 ? $per_page : self::PER_PAGE;
        $max_pages = (int) ceil($total / $per_page);

        // Newest row overall is the lastmod for the (first) index entry.
        $newest = $this->cache()->get_sitemap_rows(1, 0);
        $lastmod = (!empty($newest) && !empty($newest[0]->created_at))
            ? $this->w3c_date($newest[0]->created_at) : '';

        $index = [];
        for ($page = 0; $page < $max_pages; $page++) {
            $suffix = ($max_pages > 1) ? ($page + 1) : '';
            $index[] = [
                'loc'     => $this->sitemap_url($suffix),
                'lastmod' => $lastmod,
            ];
        }
        return $index;
    }

    /**
     * URL entries for one sub-sitemap page: every share page with its lastmod.
     */
    public function get_sitemap_links($type, $max_entries, $current_page) {
        $per_page = min((int) $max_entries, self::PER_PAGE);
        $per_page = $per_page > 0 ? $per_page : self::PER_PAGE;
        $page     = max(1, (int) $current_page);
        $offset   = ($page - 1) * $per_page;

        $rows = $this->cache()->get_sitemap_rows($per_page, $offset);
        $links = [];
        foreach ($rows as $row) {
            if (empty($row->short_url)) {
                continue;
            }
            $links[] = [
                'loc' => home_url('/share/' . $row->short_url . '/'),
                'mod' => !empty($row->created_at) ? $this->w3c_date($row->created_at) : '',
            ];
        }
        return $links;
    }

    /**
     * Convert a stored (site-timezone) datetime string to a UTC W3C date.
     */
    private function w3c_date($datetime) {
        $ts = strtotime($datetime);
        if (!$ts) {
            return '';
        }
        $date = new \DateTime('@' . $ts);
        $date->setTimezone(new \DateTimeZone('UTC'));
        return $date->format(DATE_W3C);
    }
}
