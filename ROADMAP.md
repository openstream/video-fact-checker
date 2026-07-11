# Roadmap — Video Fact Checker

Working plan for turning the plugin into a freemium product with paid tiers.
Nothing here is committed to a delivery date yet; it captures direction and decisions.

## Context & constraints

- **YouTube is expensive.** YouTube blocks datacenter IPs, so downloads must go through a
  paid residential/rotating proxy that is metered by traffic. Every YouTube fact-check costs
  us proxy bandwidth on top of the OpenAI (Whisper + Chat) API cost.
- **Other platforms are cheap.** TikTok, X/Twitter, Vimeo, etc. download without a proxy
  (see `class-video-processor.php` — proxy is only applied for YouTube URLs). Cost is just
  the OpenAI API usage.
- Therefore limits and pricing treat **YouTube** and **other platforms** as separate buckets.

## Tier model

| Bucket           | Free (per user / day) | Paid (per user / day) |
|------------------|-----------------------|-----------------------|
| YouTube          | 1                     | 5                     |
| Other platforms  | 3                     | 10                    |

- "Per user" = logged-in WP user, else client IP (see `class-rate-limiter.php`).
- Counters reset at local midnight (site timezone).
- **Cached results don't count** against the limit — only runs that actually consume
  proxy/API resources are recorded.
- Paid access is offered **either** as a recurring **subscription** **or** a **one-time
  payment** (decision below).

## Current status

**Done:**
- Free-tier daily limits enforced: YouTube 1/day, other 3/day
  (`RateLimiter`, wired into `Ajax::handle_process_video`).
- Limits are filterable via `vfc_rate_limit` (`$default, $bucket, $user_key`) so paid
  tiers can raise them per user without changing enforcement logic.
- Proxy `407` error now distinguishes "traffic/plan limit exhausted" from "bad credentials"
  by surfacing the proxy's own `x-error-message`.
- Model dropdown updated to current OpenAI models (gpt-4.1 family + gpt-4o family);
  reasoning models intentionally excluded (would need `max_completion_tokens` /
  no custom temperature in `class-fact-checker.php`).
- TikTok "silent HEVC" fix: some TikTok videos advertise audio on every format but
  serve a silent HEVC stream, breaking `-x`. Non-YouTube downloads now use
  `-f download/bestaudio/best`, preferring TikTok's combined h264+aac format.
- Error reporting reworked (`class-notifier.php`):
  - Raw yt-dlp/ffmpeg output no longer shown in the UI — mapped to one concise
    message (`summarize_download_error`); full output stays in the log via Ref id.
  - Compact error panel, no "Try Again" button, shows "Our team has been notified…".
  - Admin gets an email on every failed run (de-duplicated 10 min per signature).
  - Daily WP-Cron (`vfc_daily_log_email`, ~03:00) mails the full log as an
    attachment to `admin_email` (override via `vfc_notify_email`), then rotates the log.
- Assets enqueued with `filemtime()` version so browsers pick up CSS/JS changes
  immediately.

**Not yet built:** everything below (monetization/accounts/payments).

## Phase 1 — Foundations for monetization

- [ ] **User accounts / identity.** Decide whether paid users must register (WP users) or
      whether we key entitlements off email + payment token. Registration gives a reliable
      per-user key (better than IP) and enables the dashboard.
- [ ] **Entitlement store.** A place to record "user X has paid plan P until date D (sub) or
      forever (one-time)". Likely a custom table or user meta.
- [ ] **Hook entitlements into limits.** Implement the `vfc_rate_limit` filter so a paid user
      returns 5 (youtube) / 10 (other). Free users keep the defaults.
- [ ] **Usage visibility.** Show remaining quota ("0 of 1 YouTube checks left today") in the
      frontend form so limits are transparent before a run starts.

## Phase 2 — Payments

- [ ] **Choose payment model** (see decision section). Recommendation: start with a
      **monthly subscription** for predictable revenue against the recurring proxy cost,
      and optionally add a **one-time day-pass / credit pack** later.
- [ ] **Payment provider.** Stripe is the default (Checkout + Billing/subscriptions,
      webhooks for entitlement updates). Evaluate WooCommerce only if we already want a
      store/invoicing stack — otherwise it's overhead.
- [ ] **Webhook → entitlement.** On successful payment / subscription event, update the
      entitlement store. On cancellation/expiry, revert the user to the free tier.
- [ ] **Billing edge cases.** Failed renewals, refunds, chargebacks, proration.

## Phase 3 — Abuse & cost control

- [ ] **Tighten anonymous identity.** IP-based limits are trivially bypassed (mobile
      networks, VPNs). Consider requiring login for anything beyond the very first free run,
      or adding a lightweight challenge.
- [ ] **Global YouTube spend cap.** A site-wide daily ceiling on YouTube runs so a spike in
      paid usage can't blow the proxy budget unexpectedly. Alert when near the cap.
- [~] **Proxy limit monitoring.** Reactive part done: a 407 traffic-limit failure now
      produces a clear message + an admin email. Still TODO: *proactively* surface the
      proxy's remaining traffic allowance before it hard-fails.

## Phase 3b — Cost reduction

### Transcript sourcing — investigated 2026-07-11

Goal: reduce or replace the two recurring costs — the Decodo proxy ($3.75/GB, YouTube
only) and OpenAI Whisper. Two approaches were evaluated:

- [ ] **(A) yt-dlp captions instead of Whisper.** yt-dlp *finds* YouTube auto-captions
      (`--write-auto-subs`), but **fetching them via the proxy is unreliable**: tested on
      prod, the caption download hit `HTTP 429 Too Many Requests`, client-blocks
      ("content is not available on this app"), and PO-token requirements. It would fall
      back to Whisper often, and it still needs the proxy. **Verdict: not worth building
      as-is.** A PO-token setup might stabilize it but is itself fragile/maintenance-heavy.

- [ ] **(B) Third-party transcript API (recommended to evaluate).** Managed services like
      **Supadata** return transcripts for YouTube, TikTok, Instagram, X, Facebook via a
      simple REST API — **no proxy, no yt-dlp, no own Whisper**. It serves native captions
      where available and AI-generates otherwise (built-in fallback = exactly our
      "captions first, Whisper fallback" logic, but solved).
      - Pricing (Supadata Pro, $17 / 3,000 credits ≈ **$0.0057/credit**):
        native transcript = 1 credit (~$0.006); AI-generated = 2 credits/min.
      - Potential wins: **drop the Decodo proxy** (biggest cost + the 407/429 pain),
        drop most Whisper calls, remove yt-dlp fragility (silent-HEVC, TikTok format
        churn, 429s).
      - Trade-offs: another third-party dependency; at very high volume our own Whisper
        may beat 2 credits/min; verify native-caption hit-rate and quality on *our* real
        videos (esp. TikTok/Instagram) before switching.
      - Next step (deferred): test the Supadata free tier (100 credits) against our test
        videos, compare cost + quality, then decide whether to make it the primary source
        with proxy+Whisper as fallback.

## Phase 4 — Product polish

- [ ] Per-user history / dashboard of past fact-checks.
- [ ] Email receipts and quota-reset notifications.
- [~] **Admin analytics: cost per fact-check.** In progress — capturing per-run cost
      (OpenAI tokens, Whisper minutes, proxy bytes) in the cache table, surfaced on the
      Transcriptions admin page, plus a daily-budget email alert. Still TODO: runs per
      platform, cache-hit rate.

## Open decisions

1. **Subscription vs. one-time.**
   - *Subscription* matches our recurring proxy cost and is easier to reason about for a
     daily quota. **Recommended as the primary model.**
   - *One-time* fits users who need a burst (e.g. a "day pass" or a credit pack of N checks).
     Good as a secondary option; maps naturally to a non-resetting credit balance rather than
     a daily limit.
   - Likely end state: subscription for the daily-quota plan **plus** a one-time credit pack.
2. **Login required for paid?** Strongly leaning yes — reliable entitlement + quota tracking
   is hard without an account.
3. **Free-tier identity.** Keep IP-based for now; revisit in Phase 3 if abuse appears.
4. **Whisper cost.** Transcription cost scales with video length — consider a max-duration
   cap per tier (free = shorter clips, paid = longer).
