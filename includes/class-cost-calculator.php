<?php
namespace VideoFactChecker;

/**
 * Computes the USD cost of a single fact-check run from measured usage.
 *
 * Rates are configurable in Settings; defaults reflect approximate list prices
 * (mid-2026). All getters fall back to the defaults when the option is empty, so
 * the feature works out of the box and can be tuned without a code deploy.
 */
class CostCalculator {

    // Defaults (USD).
    const DEFAULT_CHAT_INPUT_PER_1M  = 0.15;   // gpt-4o-mini input  ($/1M tokens)
    const DEFAULT_CHAT_OUTPUT_PER_1M = 0.60;   // gpt-4o-mini output ($/1M tokens)
    const DEFAULT_WHISPER_PER_MIN    = 0.006;  // whisper-1 ($/audio minute)
    const DEFAULT_PROXY_PER_GB       = 3.00;   // typical residential proxy ($/GB) — tune to your plan

    private function rate($option, $default) {
        $v = get_option($option, '');
        if ($v === '' || !is_numeric($v)) {
            return $default;
        }
        return (float) $v;
    }

    public function chat_input_per_1m()  { return $this->rate('vfc_price_chat_input_per_1m',  self::DEFAULT_CHAT_INPUT_PER_1M); }
    public function chat_output_per_1m() { return $this->rate('vfc_price_chat_output_per_1m', self::DEFAULT_CHAT_OUTPUT_PER_1M); }
    public function whisper_per_min()    { return $this->rate('vfc_price_whisper_per_min',    self::DEFAULT_WHISPER_PER_MIN); }
    public function proxy_per_gb()       { return $this->rate('vfc_price_proxy_per_gb',       self::DEFAULT_PROXY_PER_GB); }

    public function openai_cost($prompt_tokens, $completion_tokens) {
        return ($prompt_tokens / 1_000_000) * $this->chat_input_per_1m()
             + ($completion_tokens / 1_000_000) * $this->chat_output_per_1m();
    }

    public function whisper_cost($audio_seconds) {
        return ($audio_seconds / 60.0) * $this->whisper_per_min();
    }

    /**
     * Proxy cost only applies to YouTube (the only platform routed through the proxy).
     */
    public function proxy_cost($download_bytes, $is_youtube) {
        if (!$is_youtube) {
            return 0.0;
        }
        return ($download_bytes / 1_073_741_824) * $this->proxy_per_gb(); // bytes → GiB
    }

    /**
     * Build the full metrics array to persist for one run.
     */
    public function build_metrics($platform, $prompt_tokens, $completion_tokens, $audio_seconds, $download_bytes, $is_youtube) {
        $openai  = $this->openai_cost($prompt_tokens, $completion_tokens);
        $whisper = $this->whisper_cost($audio_seconds);
        $proxy   = $this->proxy_cost($download_bytes, $is_youtube);
        return [
            'platform' => $platform,
            'openai_prompt_tokens' => (int) $prompt_tokens,
            'openai_completion_tokens' => (int) $completion_tokens,
            'openai_cost' => round($openai, 6),
            'whisper_seconds' => round((float) $audio_seconds, 2),
            'whisper_cost' => round($whisper, 6),
            'proxy_bytes' => (int) $download_bytes,
            'proxy_cost' => round($proxy, 6),
            'total_cost' => round($openai + $whisper + $proxy, 6),
        ];
    }
}
