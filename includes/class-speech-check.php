<?php
namespace VideoFactChecker;

/**
 * Does a transcript contain enough speech to fact-check? Music videos (lofi,
 * songs) come back as nearly nothing, "[Music]" captions, or a chorus / Whisper
 * hallucination repeated over and over. Checked before the (paid) analysis.
 *
 * Thresholds calibrated on the 253 prod transcripts (2026-11-09): every one
 * under 15 words was empty or noise; real speech runs at 100-160 words/min.
 */
class SpeechCheck {

    const MIN_WORDS        = 15;  // below: always too little
    const SHORT_WORDS      = 30;  // below: only OK when spoken densely...
    const SHORT_MIN_WPM    = 40;  // ...at least this many words per minute
    const MIN_WPM          = 20;  // for videos of 2+ minutes
    const REPEAT_MAX_WORDS = 300; // repetition check only for short texts
    const REPEAT_MIN_RATIO = 0.25;

    /**
     * @param string $transcript
     * @param float  $seconds  Audio/video length, 0 when unknown.
     * @return array{ok:bool,words:int,wpm:?int,unique:float,reason:string}
     */
    public static function analyze($transcript, $seconds) {
        // Drop caption tags like "[Music]" / "(Applause)" and note symbols.
        $text = preg_replace('/\[[^\]]*\]|\([^)]*\)|[♪♫]/u', ' ', (string) $transcript);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        // Scripts written without spaces (Chinese, Japanese, Thai, …): count each
        // character as a word, or a whole sentence would count as one.
        $text = preg_replace('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]/u', ' $0 ', $text);
        $words = preg_split('/\s+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY);
        $n = count($words);
        $unique = $n ? count(array_unique($words)) / $n : 0.0;
        $wpm = $seconds > 0 ? (int) round($n / ($seconds / 60)) : null;

        $reason = '';
        if ($n < self::MIN_WORDS) {
            $reason = "only {$n} words";
        } elseif ($n < self::SHORT_WORDS && $wpm !== null && $wpm < self::SHORT_MIN_WPM) {
            $reason = "only {$n} words at {$wpm} words/min";
        } elseif ($seconds >= 120 && $wpm !== null && $wpm < self::MIN_WPM) {
            $reason = "{$wpm} words/min over " . round($seconds / 60) . " min";
        } elseif ($n <= self::REPEAT_MAX_WORDS && $unique < self::REPEAT_MIN_RATIO) {
            $reason = sprintf('%d words, only %d%% distinct (repeated chorus or noise)', $n, $unique * 100);
        }

        return ['ok' => $reason === '', 'words' => $n, 'wpm' => $wpm, 'unique' => round($unique, 2), 'reason' => $reason];
    }
}
