<?php
namespace VideoFactChecker;

/**
 * Watches OpenAI's model list and emails the admin when a new, relevant chat
 * model appears — so we can add its price + knowledge cutoff to
 * CostCalculator::MODEL_PRICING and, if it's a cheaper/newer generation, switch
 * to it.
 *
 * Why email-only (no auto-switch): the /v1/models API exposes ONLY id + created
 * — NOT price or knowledge cutoff, which are exactly our two selection criteria.
 * Switching automatically to a model whose price we don't know could be
 * expensive, so the watcher only notifies; a human enters the price/cutoff and
 * flips the model in Settings.
 *
 * Runs weekly (vfc_weekly_model_check cron). State lives in the option
 * `vfc_known_models` (the model ids we've already seen).
 */
class ModelWatcher {

    const OPTION_KNOWN = 'vfc_known_models';
    const MODELS_URL   = 'https://api.openai.com/v1/models';

    private $logger;

    public function __construct($logger = null) {
        $this->logger = $logger ?: new Logger();
    }

    /**
     * Is this a "next-generation general chat model" we care about tracking?
     * We track the gpt-5.x line and any future gpt-6/gpt-7…, but skip the
     * non-general or non-chat variants (codex, mini/nano/pro tiers we don't use,
     * audio/realtime/search/image/embedding/tts). We DO keep the plain tiers and
     * the named 5.6 tiers (luna/sol/terra) so a new generation's cheapest tier
     * surfaces.
     */
    public static function is_relevant_model($id) {
        $id = strtolower((string) $id);

        // Only the modern gpt-5+/gpt-6+/… generations.
        if (!preg_match('/^gpt-([5-9]|\d{2,})(\.|-|$)/', $id)) {
            return false;
        }
        // Skip specialised / non-chat or tiers we don't run, plus the rolling
        // "-chat-latest" alias (a moving pointer, not a distinct new model).
        $skip = ['codex', 'audio', 'realtime', 'search', 'image', 'vision',
                 'embedding', 'tts', 'transcribe', '-nano', '-pro', '-mini',
                 'chat-latest'];
        foreach ($skip as $needle) {
            if (strpos($id, $needle) !== false) {
                return false;
            }
        }
        // Skip dated snapshot aliases like "gpt-5.6-2026-06-23" — the bare id is
        // enough and dated ones would double every notification.
        if (preg_match('/-\d{4}-\d{2}-\d{2}$/', $id)) {
            return false;
        }
        return true;
    }

    /**
     * Fetch the relevant model ids from OpenAI. Returns a sorted list, or null
     * on any API/transport failure (so callers can skip quietly).
     */
    public function fetch_relevant_models() {
        $api_key = get_option('vfc_openai_api_key', '');
        if (!is_string($api_key) || $api_key === '') {
            $this->logger->log('ModelWatcher: no OpenAI API key configured; skipping', 'warning');
            return null;
        }

        $response = wp_remote_get(self::MODELS_URL, [
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) {
            $this->logger->log('ModelWatcher: request failed: ' . $response->get_error_message(), 'error');
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            $this->logger->log('ModelWatcher: HTTP ' . wp_remote_retrieve_response_code($response), 'error');
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['data'])) {
            $this->logger->log('ModelWatcher: unexpected response shape', 'error');
            return null;
        }

        $ids = [];
        foreach ($body['data'] as $m) {
            $id = isset($m['id']) ? (string) $m['id'] : '';
            if ($id !== '' && self::is_relevant_model($id)) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /**
     * Main entry point (called by the weekly cron). Compares the current
     * relevant models against the stored "known" set; emails the admin about any
     * new ones and updates the stored set. First-ever run seeds the set silently.
     *
     * @return array The list of newly-detected model ids (empty if none/failure).
     */
    public function check() {
        $current = $this->fetch_relevant_models();
        if ($current === null) {
            return []; // transient failure; try again next week
        }

        $known = get_option(self::OPTION_KNOWN, null);

        // First run: remember the current set without emailing (avoid spamming
        // the whole existing list as "new").
        if (!is_array($known)) {
            update_option(self::OPTION_KNOWN, $current, false);
            $this->logger->log('ModelWatcher: seeded known-models list (' . count($current) . ' models)');
            return [];
        }

        $new = array_values(array_diff($current, $known));
        if (empty($new)) {
            return [];
        }

        $this->logger->log('ModelWatcher: new models detected: ' . implode(', ', $new));
        $this->notify($new);

        // Merge so we don't email the same model twice (union keeps ids that may
        // have disappeared from the list too, which is harmless).
        $merged = array_values(array_unique(array_merge($known, $current)));
        sort($merged);
        update_option(self::OPTION_KNOWN, $merged, false);

        return $new;
    }

    /**
     * Email the admin about newly-available models, with next steps.
     */
    private function notify($new_models) {
        $email = get_option('vfc_notify_email', '');
        if (!is_string($email) || $email === '' || !is_email($email)) {
            $email = get_option('admin_email');
        }
        if (!$email || !is_email($email)) {
            return;
        }

        $site    = wp_parse_url(home_url(), PHP_URL_HOST);
        $subject = sprintf('[Video Fact Checker] New OpenAI model available on %s', $site);

        $current_model = get_option('vfc_openai_model', '(none)');
        $current_cutoff = CostCalculator::cutoff_for($current_model);

        $body  = "OpenAI is offering one or more new models your account didn't have before:\n\n";
        foreach ($new_models as $m) {
            $body .= "  • " . $m . "\n";
        }
        $body .= "\nYour fact-check model right now: " . $current_model;
        if ($current_cutoff !== '') {
            $body .= " (knowledge cutoff " . $current_cutoff . ")";
        }
        $body .= "\n\n";
        $body .= "Why this matters: the fact-checker has no live web search, so a newer\n";
        $body .= "knowledge cutoff means fresher facts — and a cheaper tier keeps costs low.\n\n";
        $body .= "The OpenAI API does NOT expose price or knowledge cutoff, so this can't be\n";
        $body .= "done automatically. Please:\n";
        $body .= "  1. Look up the model's input/output price and knowledge cutoff in the\n";
        $body .= "     OpenAI docs (https://developers.openai.com/api/docs/models/).\n";
        $body .= "  2. Add them to CostCalculator::MODEL_PRICING and MODEL_CUTOFF\n";
        $body .= "     (includes/class-cost-calculator.php) and deploy.\n";
        $body .= "  3. If it's a newer generation with a cheap tier, switch the primary\n";
        $body .= "     model under Settings → Fact Checker.\n\n";
        $body .= "Time: " . current_time('Y-m-d H:i:s') . "\n";

        $sent = wp_mail($email, $subject, $body);
        $this->logger->log('ModelWatcher: new-model email ' . ($sent ? 'sent' : 'FAILED') . ' to ' . $email);
    }
}
