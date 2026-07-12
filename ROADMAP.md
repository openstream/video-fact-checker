# Roadmap — Video Fact Checker

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

- **InnerTube captions for YouTube (no proxy).** Suggested in issue #1 (thanks @rmoriz):
  fetch YouTube captions via YouTube's internal InnerTube API (e.g.
  [youtube-caption-extractor](https://github.com/devhims/youtube-caption-extractor)) —
  no proxy, no Whisper. Trade-offs: only helps YouTube videos that *have* captions,
  auto-captions are lower quality, and it adds a fragile internal-API dependency (needs a
  PHP port). With the proxy now audio-only and cheap, the saving is small — revisit if
  volume grows or proxy costs/reliability worsen. (The earlier yt-dlp `--write-subs`
  approach failed via the proxy with 429s; InnerTube is a different, direct path.)

### Spend control

- **Global spend cap.** A site-wide daily ceiling on YouTube runs so a usage spike can't
  blow the proxy budget, with an alert when near the cap.
- **Proactive proxy-limit monitoring.** The reactive part is done (a 407 traffic-limit
  failure produces a clear message + admin email). TODO: *proactively* surface the
  remaining proxy allowance before it runs out.

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
