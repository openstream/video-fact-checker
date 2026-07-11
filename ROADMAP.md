# Roadmap — Video Fact Checker

**Current focus: grow usage and keep the tool reliable.** Nothing here is committed to a
delivery date; it captures direction and decisions.

## Context

- **YouTube is expensive.** YouTube blocks datacenter IPs, so downloads go through a paid
  proxy metered by traffic. We download audio only to keep that traffic small.
- **Other platforms are cheap.** TikTok, X/Twitter, Vimeo, etc. download without a proxy
  (see `class-video-processor.php` — the proxy is only applied to YouTube URLs).

## Done (recent)

- Per-user daily usage limits (`RateLimiter`), tracked separately for YouTube vs. other
  platforms; filterable via `vfc_rate_limit`.
- Current OpenAI models in the dropdown, including GPT-5 support (conditional temperature)
  and per-model knowledge-cutoff display.
- YouTube downloads audio only (`-f bestaudio/best`) to minimize proxy bandwidth.
- TikTok "silent HEVC" download fix.
- Concise user-facing error messages; admin email on failures; daily log email + rotation.
- Per-fact-check cost tracking with a stats page and a daily-budget alert.
- Real platform detection (tiktok, instagram, …); URL normalization for stable cache keys.
- Public "How It Works" page (discloses the prompt) and a public Roadmap page.

## Reliability & upkeep

The tool depends on `yt-dlp`, which breaks whenever YouTube changes something. On
2026-07-11 prod YouTube broke because yt-dlp was ~1 year old and had no JS runtime;
updating yt-dlp + installing `deno` fixed it.

- [x] **yt-dlp needs a JS runtime for YouTube** — `deno` installed at `/opt/deno/bin/deno`
      (symlinked to `/usr/local/bin/deno`, readable by www-data).
- [ ] **yt-dlp auto-update.** Weekly cron on the droplet (`pip install -U yt-dlp`); the
      health check below is the safety net if an update breaks something.
- [ ] **Health check / smoke test.** Daily cron runs a real fact-check (nocache) and emails
      the admin if it fails — so breakage is caught immediately, not by users.
- [ ] **Show dependency versions in admin** (yt-dlp / ffmpeg / PHP), warn when yt-dlp is old.
- [ ] **OS updates.** Patch the Ubuntu droplet on a regular cadence. `deno` was installed
      manually — re-provisioning must reinstall it.

## Fact-check quality

- [ ] **Add real web search to the fact-check.** Today `check_facts()` uses plain
      `chat/completions` with no tools, so the model verifies claims **only from its training
      data** — no live sources, no citations, blind to anything after its cutoff. OpenAI's
      web-search tool (Responses API) would let it look up current sources and cite them.
      Costs: higher per-call price, slower, and the UI needs to show the sources. Until then,
      the public "How It Works" page states plainly that there is no live web search.

## Abuse & spend control

- [ ] **Tighten anonymous identity.** IP-based limits are easy to bypass; consider requiring
      login beyond the first free run, or a lightweight challenge.
- [ ] **Global spend cap.** A site-wide daily ceiling on YouTube runs so a usage spike can't
      blow the proxy budget. Alert when near the cap.
- [~] **Proxy limit monitoring.** Reactive part done (a 407 traffic-limit failure produces a
      clear message + admin email). TODO: *proactively* surface remaining proxy allowance.

## Product polish

- [ ] Per-user history / dashboard of past fact-checks.
- [~] **Admin analytics: cost per fact-check.** Per-run cost is captured and shown on the
      Transcriptions page with a daily-budget alert. TODO: runs per platform, cache-hit rate.
