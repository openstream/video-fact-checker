<?php
namespace VideoFactChecker;

/**
 * Weekly model status check: tells the admin (Slack, mail fallback) when OpenAI
 * has new relevant chat models, when those are missing from
 * CostCalculator::MODEL_PRICING, and when a priced model offers a newer
 * knowledge cutoff without costing more than the configured one.
 *
 * Why notify-only (no auto-switch): the /v1/models API exposes ONLY id + created
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
     * Relevant API models we deliberately don't price/offer, so they don't
     * trigger the "missing from MODEL_PRICING" reminder every week.
     * gpt-6-sol: in the API but undocumented, superseded by gpt-6.1-sol.
     */
    const IGNORED_MODELS = ['gpt-6-sol'];

    /**
     * Main entry point (called by the weekly cron). A status check, not just a
     * "new id" diff: it re-reports every week until someone acts, because a
     * one-shot notice can get lost (Sep/Oct 2026: four gpt-6 notices went to a
     * mailbox prod can't deliver to, then counted as "known" and never came back).
     *
     * Reports to Slack (via Notifier) when any of these holds:
     *  - new: relevant model ids not seen before (first run seeds silently);
     *  - unpriced: models newer than our newest priced one that are missing
     *    from CostCalculator::MODEL_PRICING (no price/cutoff → not selectable);
     *  - upgrade: a priced model with a NEWER knowledge cutoff that is not more
     *    expensive (input price) than the configured primary model.
     *
     * @return array ['new' => [...], 'unpriced' => [...], 'upgrade' => [...]]
     */
    public function check() {
        $current = $this->fetch_relevant_models();
        if ($current === null) {
            return []; // transient failure; try again next week
        }

        $known = get_option(self::OPTION_KNOWN, null);
        $new = [];
        if (!is_array($known)) {
            // First run: remember the current set without calling everything "new".
            $this->logger->log('ModelWatcher: seeded known-models list (' . count($current) . ' models)');
            $known = [];
        } else {
            $new = array_values(array_diff($current, $known));
        }
        $merged = array_values(array_unique(array_merge($known, $current)));
        sort($merged);
        update_option(self::OPTION_KNOWN, $merged, false);

        $status = [
            'new'      => $new,
            'unpriced' => $this->unpriced_models($current),
            'upgrade'  => self::upgrade_candidates(get_option('vfc_openai_model', '')),
        ];
        $this->logger->log(sprintf(
            'ModelWatcher: new=[%s] unpriced=[%s] upgrade=[%s]',
            implode(', ', $status['new']), implode(', ', $status['unpriced']), implode(', ', $status['upgrade'])
        ));

        if ($status['new'] || $status['unpriced'] || $status['upgrade']) {
            $this->notify($status);
        }
        return $status;
    }

    /**
     * API models missing from MODEL_PRICING, limited to generations at least as
     * new as the newest priced one — older gpt-5.x we never priced stay quiet.
     */
    private function unpriced_models(array $current) {
        $newest_priced = 0.0;
        foreach (array_keys(CostCalculator::MODEL_PRICING) as $m) {
            $newest_priced = max($newest_priced, self::generation($m));
        }
        $out = [];
        foreach ($current as $id) {
            if (isset(CostCalculator::MODEL_PRICING[$id]) || in_array($id, self::IGNORED_MODELS, true)) {
                continue;
            }
            if (self::generation($id) >= $newest_priced) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** "gpt-6.1-terra" → 6.1, "gpt-5" → 5.0, anything else → 0. */
    private static function generation($id) {
        return preg_match('/^gpt-(\d+(?:\.\d+)?)/i', $id, $m) ? (float) $m[1] : 0.0;
    }

    /** "May 2026" → comparable timestamp, '' → 0. */
    private static function cutoff_ts($label) {
        $ts = $label !== '' ? strtotime('1 ' . $label) : false;
        return $ts ?: 0;
    }

    /**
     * Priced models with a newer knowledge cutoff than $model at the same or a
     * lower input price — i.e. fresher facts without extra cost. Cheapest first.
     */
    public static function upgrade_candidates($model) {
        $cur_cutoff = self::cutoff_ts(CostCalculator::cutoff_for($model));
        if ($cur_cutoff === 0 || !isset(CostCalculator::MODEL_PRICING[$model])) {
            return [];
        }
        $cur_in = CostCalculator::MODEL_PRICING[$model][0];
        $out = [];
        foreach (CostCalculator::MODEL_PRICING as $id => $price) {
            if ($id !== $model && $price[0] <= $cur_in
                && self::cutoff_ts(CostCalculator::cutoff_for($id)) > $cur_cutoff) {
                $out[$id] = $price[0];
            }
        }
        asort($out);
        return array_keys($out);
    }

    /** Slack (or mail fallback) report with what to do next. */
    private function notify(array $status) {
        $model = get_option('vfc_openai_model', '(none)');
        $fmt = function ($id) {
            $p = CostCalculator::MODEL_PRICING[$id] ?? null;
            $c = CostCalculator::cutoff_for($id);
            return $id . ($p ? sprintf(' — $%.2f / $%.2f per 1M', $p[0], $p[1]) : '') . ($c !== '' ? ", cutoff {$c}" : '');
        };

        $body = "Fact-check model now: " . $fmt($model) . "\n";
        if ($status['upgrade']) {
            $body .= "\nNewer knowledge cutoff at the same or a lower price — consider switching\n";
            $body .= "(Settings → Fact Checker → model):\n";
            foreach ($status['upgrade'] as $m) {
                $body .= "  • " . $fmt($m) . "\n";
            }
        }
        if ($status['unpriced']) {
            $body .= "\nIn the OpenAI API but not in CostCalculator::MODEL_PRICING yet (price and\n";
            $body .= "cutoff are only in the docs: https://developers.openai.com/api/docs/models/ —\n";
            $body .= "add them, or list the id in ModelWatcher::IGNORED_MODELS):\n";
            foreach ($status['unpriced'] as $m) {
                $body .= "  • " . $m . "\n";
            }
        }
        if ($status['new']) {
            $body .= "\nNew since last week: " . implode(', ', $status['new']) . "\n";
        }
        $body .= "\nThis reminder repeats every Monday until the points above are resolved.\n";

        $title = $status['upgrade'] ? 'Better OpenAI model available' : 'OpenAI model list changed';
        (new Notifier($this->logger))->send($title, $body);
    }
}
