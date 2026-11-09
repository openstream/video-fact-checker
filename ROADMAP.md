# Roadmap — Video Fact Checker

> **Internal document — not publicly linked.** This is a developer-facing idea backlog; it is
> intentionally not exposed in the site navigation. (The public "Roadmap" nav item and page were
> removed once the plugin stopped being aimed at third-party installs.)

Ideas and directions for the tool. Nothing here is committed to a delivery date; it
captures possible features and the reasoning behind them.

## Context

- **YouTube is expensive.** YouTube blocks datacenter IPs, so downloads go through a paid
  proxy metered by traffic. We download audio only to keep that traffic small.
- **Other platforms are cheap.** TikTok, X/Twitter, Vimeo, etc. download without a proxy
  (see `class-video-processor.php` — the proxy is only applied to YouTube URLs).

## Possible features

### Fact-check quality

- **Add real web search to the fact-check.** Today `check_facts()` uses plain
  `chat/completions` with no tools, so the model verifies claims **only from its training
  data** — no live sources, no citations, blind to anything after its cutoff. OpenAI's
  web-search tool (Responses API) would let it look up current sources and cite them.
  Costs: higher per-call price, slower, and the UI needs to show the sources. Until then,
  the public "How It Works" page states plainly that there is no live web search.

### Cost reduction

- [x] **InnerTube captions for YouTube (no proxy).** Suggested in issue #1 (thanks @rmoriz).
  Done in v0.15.0 (`class-you-tube-captions.php`): YouTube URLs first try captions via the
  internal InnerTube player API (iOS → Android VR clients, json3 parsing). Staged fallback:
  (1) InnerTube direct (no proxy, no Whisper) → (2) InnerTube via the residential proxy — for
  videos YouTube refuses to serve from our datacenter IP (`LOGIN_REQUIRED`; the block is
  per-video, not IP-wide) → (3) audio download + Whisper, but only if the video is within
  `vfc_youtube_max_download_minutes` (default 20; 0 disables the download fallback). Toggle
  `vfc_youtube_use_innertube` for a kill switch. Trade-offs remain: only helps videos that
  *have* captions, and auto-captions are lower quality than Whisper.

### Spend control

- **Global spend cap.** Partly done in v0.24.0: `BotGuard` caps fresh checks per IP
  (hour/day) and site-wide per day (`vfc_limit_*`, Slack note when hit). TODO: a cap in
  dollars rather than in runs.
- **Proactive proxy-limit monitoring.** The reactive part is done (a 407 traffic-limit
  failure produces a clear message + admin email). TODO: *proactively* surface the
  remaining proxy allowance before it runs out.

### Premium: long videos

- **Longer videos as a paid feature.** Since v0.24.0 a preflight (`vfc_preflight`) declines
  YouTube videos without subtitles over `vfc_youtube_max_download_minutes` *before* the run,
  with a clear message. Premium would lift that limit (and possibly allow longer non-YouTube
  videos) for paying users: needs accounts or a license/voucher code, payment (e.g. Stripe
  Checkout), a per-user limit instead of the global one, and a cost estimate shown up front
  (Whisper ≈ $0.006/min plus proxy traffic). The preflight message is the natural upsell spot.
- **Demand test (v0.25.0):** every "too long" message asks *"Would you be interested in
  fact-checking longer videos for a fee (subscription or micropayments)?"* Yes/No → Slack
  (💰 / 💬 Premium interest). Count the answers before building anything.

### Product polish

- **Per-user history / dashboard of past fact-checks.**
- **Richer admin analytics.** Per-run cost is already captured and shown on the
  Transcriptions page with a daily-budget alert. TODO: runs per platform and cache-hit rate.

## Reliability & upkeep (done, keep an eye on)

The tool depends on `yt-dlp`, which breaks whenever YouTube changes something. On
2026-07-11 prod YouTube broke because yt-dlp was ~1 year old and had no JS runtime;
updating yt-dlp + installing `deno` fixed it.

- [x] **yt-dlp needs a JS runtime for YouTube** — `deno` installed at `/opt/deno/bin/deno`
      (symlinked to `/usr/local/bin/deno`, readable by www-data).
- [x] **yt-dlp auto-update.** Weekly cron on the droplet (`/etc/cron.d/vfc-ytdlp-update`).
- [x] **Health check / smoke test.** Daily cron runs a real fact-check and emails the admin
      on failure (in the plugin's daily cron).
- [x] **Show dependency versions in admin** — Settings → “System information”.
- [ ] **OS updates.** Patch the Ubuntu droplet on a regular cadence. `deno` was installed
      manually — re-provisioning must reinstall it.

## Done (recent)

- Current OpenAI models in the dropdown, including GPT-5 support (conditional temperature)
  and per-model knowledge-cutoff display; automatic fallback to a secondary model when the
  primary returns an empty analysis, with the used model shown on every result.
- YouTube downloads audio only (`-f bestaudio/best`) to minimize proxy bandwidth.
- TikTok "silent HEVC" download fix.
- Concise user-facing error messages; admin email on failures; daily log email + rotation.
- Per-fact-check cost tracking with a stats page and a daily-budget alert.
- Real platform detection (tiktok, instagram, …); URL normalization for stable cache keys.
- Public "How It Works" page (discloses the prompt) and a public Roadmap page.
