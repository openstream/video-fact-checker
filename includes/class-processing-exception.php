<?php
namespace VideoFactChecker;

/**
 * A fact-check processing failure with enough structure to (a) show the user a
 * concrete, actionable message and (b) tell the admin — in the error email —
 * WHAT failed and WHY, instead of a useless PHP stack trace.
 *
 *  - user_message: shown on the website (already human-friendly, no raw output).
 *  - stage:        which step failed, in plain words
 *                  (e.g. "YouTube captions (InnerTube, direct)",
 *                   "YouTube audio download via proxy (Decodo)").
 *  - technical:    the real underlying reason — the yt-dlp/HTTP error line,
 *                  a proxy status, "no captions", "over the length limit", …
 *                  NOT a stack trace.
 */
class ProcessingException extends \Exception {
    /** @var string */
    private $user_message;
    /** @var string */
    private $stage;
    /** @var string */
    private $technical;

    public function __construct($user_message, $stage = '', $technical = '') {
        // The exception "message" carries the user-facing text so existing
        // catch/log sites keep working; stage/technical add the detail.
        parent::__construct($user_message);
        $this->user_message = (string) $user_message;
        $this->stage = (string) $stage;
        $this->technical = (string) $technical;
    }

    public function get_user_message() { return $this->user_message; }
    public function get_stage()        { return $this->stage; }
    public function get_technical()    { return $this->technical; }
}
