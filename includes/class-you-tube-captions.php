<?php
namespace VideoFactChecker;

/**
 * Fetch a YouTube transcript from its captions via YouTube's internal InnerTube
 * player API — no proxy and no Whisper needed when the video has captions.
 *
 * Port of the approach in devhims/youtube-caption-extractor (suggested in
 * issue #1 by @rmoriz): POST to youtubei/v1/player with a mobile/embedded
 * client context, read captionTracks, then fetch the chosen track as json3.
 *
 * Important reality (measured 2026-08-05): datacenter IPs get LOGIN_REQUIRED
 * for SOME videos while others work fine — the block is per-video, not a blanket
 * IP ban. So callers try direct first, then optionally through the residential
 * proxy for the videos YouTube refuses to serve datacenter-side.
 */
class YouTubeCaptions {
    private $logger;

    /**
     * Client profiles to try, in order. iOS and Android VR proved the most
     * reliable for surfacing caption tracks; MWEB/TVHTML5 mostly returned
     * UNPLAYABLE/ERROR in testing, so they're omitted.
     */
    const CLIENTS = [
        [
            'clientName'    => 'IOS',
            'clientVersion' => '20.10.4',
            'clientNumber'  => '5',
            'userAgent'     => 'com.google.ios.youtube/20.10.4 (iPhone16,2; U; CPU iOS 18_3_2 like Mac OS X)',
        ],
        [
            'clientName'    => 'ANDROID_VR',
            'clientVersion' => '1.62.27',
            'clientNumber'  => '28',
            'userAgent'     => 'com.google.android.apps.youtube.vr.oculus/1.62.27 (Linux; U; Android 12; GB) gzip',
        ],
    ];

    const PLAYER_URL = 'https://youtubei.googleapis.com/youtubei/v1/player?prettyPrint=false';

    public function __construct($logger = null) {
        $this->logger = $logger ?: new Logger();
    }

    /**
     * Extract the 11-char video id from any common YouTube URL form, or '' if
     * the URL isn't a recognisable YouTube video link.
     */
    public static function extract_video_id($url) {
        $url = (string) $url;
        $patterns = [
            '#youtube\.com/watch\?(?:.*&)?v=([\w-]{11})#i',
            '#youtu\.be/([\w-]{11})#i',
            '#youtube\.com/shorts/([\w-]{11})#i',
            '#youtube\.com/embed/([\w-]{11})#i',
            '#youtube\.com/v/([\w-]{11})#i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $url, $m)) {
                return $m[1];
            }
        }
        return '';
    }

    /**
     * Try to build a transcript from captions for a YouTube video id.
     *
     * @param string      $video_id
     * @param string|null $proxy    Full proxy URL (http://user:pass@host:port) to
     *                              route through, or null for a direct request.
     * @return array{transcript:string,lang:string,kind:string,duration:int,title:string}
     * @throws ProcessingException with a machine-usable reason on failure:
     *   technical starts with "LOGIN_REQUIRED" | "NO_CAPTIONS" | "UNPLAYABLE:…"
     *   | "HTTP …" | "EMPTY_CAPTIONS" so the caller can branch.
     */
    public function fetch($video_id, $proxy = null) {
        $label = $proxy ? 'proxy' : 'direct';
        $last_status = '';

        foreach (self::CLIENTS as $client) {
            $resp = $this->player_request($video_id, $client, $proxy);
            if ($resp === null) {
                $last_status = 'HTTP request failed';
                continue; // network error already logged; try next client
            }

            $status = isset($resp['playabilityStatus']['status'])
                ? $resp['playabilityStatus']['status'] : 'UNKNOWN';

            if ($status !== 'OK') {
                $reason = isset($resp['playabilityStatus']['reason'])
                    ? $resp['playabilityStatus']['reason'] : '';
                $last_status = $status . ($reason ? " ({$reason})" : '');
                $this->logger->log("InnerTube [{$label}/{$client['clientName']}]: status={$status} {$reason}");
                // LOGIN_REQUIRED etc. are per-client; keep trying other clients.
                continue;
            }

            $tracks = isset($resp['captions']['playerCaptionsTracklistRenderer']['captionTracks'])
                ? $resp['captions']['playerCaptionsTracklistRenderer']['captionTracks']
                : [];

            $duration = isset($resp['videoDetails']['lengthSeconds'])
                ? (int) $resp['videoDetails']['lengthSeconds'] : 0;
            $title = isset($resp['videoDetails']['title'])
                ? (string) $resp['videoDetails']['title'] : '';

            if (empty($tracks)) {
                // Playable but genuinely has no caption tracks — no point trying
                // other clients (they'd say the same). Surface the duration so the
                // caller can decide whether to fall back to audio+Whisper.
                $this->logger->log("InnerTube [{$label}/{$client['clientName']}]: OK but no caption tracks (duration={$duration}s)");
                throw new ProcessingException(
                    "This video has no subtitles.",
                    "YouTube captions (InnerTube, {$label})",
                    "NO_CAPTIONS|duration={$duration}|title=" . $title
                );
            }

            $track = $this->pick_caption_track($tracks);
            $transcript = $this->fetch_track_text($track['baseUrl'], $proxy);

            if ($transcript === '') {
                $last_status = 'EMPTY_CAPTIONS';
                $this->logger->log("InnerTube [{$label}/{$client['clientName']}]: caption track fetched but empty");
                continue;
            }

            $kind = (isset($track['kind']) && $track['kind'] === 'asr') ? 'asr' : 'manual';
            $this->logger->log(sprintf(
                "InnerTube [%s/%s]: got %s captions, lang=%s, %d chars, duration=%ds",
                $label, $client['clientName'], $kind,
                isset($track['languageCode']) ? $track['languageCode'] : '?',
                mb_strlen($transcript), $duration
            ));

            return [
                'transcript' => $transcript,
                'lang'       => isset($track['languageCode']) ? $track['languageCode'] : '',
                'kind'       => $kind,
                'duration'   => $duration,
                'title'      => $title,
            ];
        }

        // Every client failed. Encode the last status so the caller can branch
        // (LOGIN_REQUIRED -> try proxy; other -> fall through to download).
        $technical = 'LOGIN_REQUIRED' === $last_status || false !== stripos($last_status, 'LOGIN_REQUIRED')
            ? 'LOGIN_REQUIRED'
            : $last_status;
        throw new ProcessingException(
            "YouTube didn't return subtitles for this video.",
            "YouTube captions (InnerTube, {$label})",
            $technical !== '' ? $technical : 'no usable client response'
        );
    }

    /**
     * POST the InnerTube player request for one client. Returns the decoded
     * JSON array, or null on a transport-level failure.
     */
    private function player_request($video_id, $client, $proxy) {
        $body = json_encode([
            'context' => [
                'client' => [
                    'clientName'    => $client['clientName'],
                    'clientVersion' => $client['clientVersion'],
                    'hl'            => 'en',
                    'gl'            => 'US',
                ],
                'user'    => ['lockedSafetyMode' => false],
                'request' => ['useSsl' => true],
            ],
            'videoId'        => $video_id,
            'contentCheckOk' => true,
            'racyCheckOk'    => true,
        ]);

        $headers = [
            'Content-Type: application/json',
            'X-YouTube-Client-Name: ' . $client['clientNumber'],
            'X-YouTube-Client-Version: ' . $client['clientVersion'],
            'User-Agent: ' . $client['userAgent'],
        ];

        return $this->http_post_json(self::PLAYER_URL, $body, $headers, $proxy);
    }

    /**
     * Fetch a caption track as json3 and flatten it to plain text. Returns ''
     * on any failure (caller treats empty as "try another client / fall back").
     */
    private function fetch_track_text($base_url, $proxy) {
        if (!is_string($base_url) || $base_url === '') {
            return '';
        }
        // Force json3 output: drop any existing fmt, then append.
        $url = preg_replace('/([?&])fmt=[^&]*/', '$1', $base_url);
        $url = rtrim($url, '?&');
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'fmt=json3';

        $json = $this->http_get($url, $proxy);
        if ($json === null || $json === '') {
            return '';
        }
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['events'])) {
            return '';
        }

        $parts = [];
        foreach ($data['events'] as $event) {
            // Skimmed/append-only events carry no new text.
            if (isset($event['aAppend']) && $event['aAppend'] === 1) {
                continue;
            }
            if (empty($event['segs']) || !is_array($event['segs'])) {
                continue;
            }
            $line = '';
            foreach ($event['segs'] as $seg) {
                if (isset($seg['utf8'])) {
                    $line .= $seg['utf8'];
                }
            }
            $line = trim($line);
            if ($line !== '') {
                $parts[] = $line;
            }
        }

        $text = implode(' ', $parts);
        // Decode entities, strip any stray tags, collapse whitespace.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = wp_strip_all_tags($text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        return $text;
    }

    /**
     * Choose the best caption track: prefer a manual track, then auto (asr),
     * preferring English-ish where ambiguous but otherwise taking the video's
     * own first track (the fact-check answers in the transcript's language, so
     * the original language is the right choice).
     */
    private function pick_caption_track($tracks) {
        $manual = null;
        $asr = null;
        foreach ($tracks as $t) {
            $is_asr = (isset($t['kind']) && $t['kind'] === 'asr')
                || (isset($t['vssId']) && strpos($t['vssId'], 'a.') === 0);
            if ($is_asr) {
                if ($asr === null) { $asr = $t; }
            } else {
                if ($manual === null) { $manual = $t; }
            }
        }
        // Manual beats auto-generated; otherwise first available.
        return $manual ?: ($asr ?: $tracks[0]);
    }

    /* ---- HTTP helpers ----
     *
     * Direct (no-proxy) requests go through WordPress's HTTP API (wp_remote_*),
     * which works whichever transport is available and does NOT require the PHP
     * curl extension in the current SAPI (prod's php-cli has no curl; php-fpm,
     * where real requests run, does). WP's HTTP layer can't apply a per-request
     * proxy (WP_Http_Proxy is driven by global constants), so the proxy path
     * uses curl's CURLOPT_PROXY directly — available in the php-fpm request
     * context where stage 2 actually runs. */

    private function http_post_json($url, $body, $headers, $proxy) {
        if ($proxy) {
            return $this->curl_request('POST', $url, $headers, $body, $proxy, true);
        }
        $header_map = [];
        foreach ($headers as $h) {
            $pos = strpos($h, ':');
            if ($pos !== false) {
                $header_map[trim(substr($h, 0, $pos))] = trim(substr($h, $pos + 1));
            }
        }
        $response = wp_remote_post($url, [
            'headers'     => $header_map,
            'body'        => $body,
            'timeout'     => 15,
            'redirection' => 2,
        ]);
        if (is_wp_error($response)) {
            $this->logger->log("InnerTube POST error: " . $response->get_error_message(), 'error');
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->logger->log("InnerTube POST HTTP {$code}", 'error');
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : null;
    }

    private function http_get($url, $proxy) {
        if ($proxy) {
            $raw = $this->curl_request('GET', $url, ['User-Agent: Mozilla/5.0 (compatible; VideoFactChecker/1.0)'], null, $proxy, false);
            return $raw;
        }
        $response = wp_remote_get($url, [
            // A browser-ish UA; timedtext can be picky about empty UAs.
            'headers'     => ['User-Agent' => 'Mozilla/5.0 (compatible; VideoFactChecker/1.0)'],
            'timeout'     => 15,
            'redirection' => 2,
        ]);
        if (is_wp_error($response)) {
            $this->logger->log("InnerTube GET error: " . $response->get_error_message(), 'error');
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->logger->log("InnerTube GET HTTP {$code}", 'error');
            return null;
        }
        return (string) wp_remote_retrieve_body($response);
    }

    /**
     * curl request routed through $proxy. Used only for the proxy path (stage 2),
     * which runs in the php-fpm request context where curl is available. Returns
     * the decoded array for JSON, the raw body string otherwise, or null.
     */
    private function curl_request($method, $url, $headers, $body, $proxy, $decode_json) {
        if (!function_exists('curl_init')) {
            $this->logger->log("InnerTube proxy path needs curl, which isn't available in this SAPI", 'error');
            return null;
        }
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_PROXY, $proxy);
        if ($method === 'POST') {
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = \curl_exec($ch);
        $code = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = \curl_error($ch);
        \curl_close($ch);

        if ($resp === false) {
            $this->logger->log("InnerTube {$method} (proxy) curl error: " . $err, 'error');
            return null;
        }
        if ((int) $code !== 200) {
            $this->logger->log("InnerTube {$method} (proxy) HTTP {$code}", 'error');
            return null;
        }
        if ($decode_json) {
            $data = json_decode($resp, true);
            return is_array($data) ? $data : null;
        }
        return (string) $resp;
    }
}
