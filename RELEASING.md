# Releasing / Versioning

This plugin uses [Semantic Versioning](https://semver.org): **MAJOR.MINOR.PATCH**.

While the product is in **Beta**, versions stay in the **`0.x`** range. The jump to
`1.0.0` is reserved for the first stable, out-of-beta release (at which point the
"(Beta)" label in the page title and footer should be removed too).

## When to bump which number

| Change | Bump | Example |
|---|---|---|
| Bug fix, no new behavior | PATCH | `0.5.0` → `0.5.1` |
| New feature, backwards compatible | MINOR | `0.5.1` → `0.6.0` |
| Breaking change (or leaving Beta) | MAJOR | `0.x` → `1.0.0` |

## Where the version lives

There is **one** version string to update, kept in two spots that must match:

1. The `Version:` header in `video-fact-checker.php`.
2. The `VFC_VERSION` constant in `video-fact-checker.php` (used for display).

The footer shows `Fact Checker vX.Y.Z (Beta)` via the `twentysixteen_credits` hook,
**only** on pages containing the `[video_fact_checker]` shortcode.

## Release steps

1. Make your changes on a feature/fix branch and verify (locally + in the browser).
2. Bump `Version:` and `VFC_VERSION` to the new number in `video-fact-checker.php`.
3. Commit, push, and merge to `main` (fast-forward).
4. Tag the release commit and push the tag:
   ```sh
   git tag -a v0.6.0 -m "v0.6.0 — short summary of what changed"
   git push origin v0.6.0
   ```
5. Deploy to production (see the prod deploy notes / CLAUDE.md).
6. Delete the merged feature branch (local + origin).

## Tag history

Milestones were tagged retroactively:

- `v0.1.0` — Base plugin (transcription, fact-check, share URLs, YouTube proxy/cookies, dashboard widget)
- `v0.2.0` — Newer OpenAI models; per-user daily rate limits; clearer proxy errors
- `v0.2.1` — Fix TikTok silent-HEVC downloads
- `v0.3.0` — Slim error reporting; admin email on failure; daily log email
- `v0.3.1` — Model-based OpenAI pricing; Decodo proxy default
- `v0.4.0` — Per-fact-check cost tracking, stats, daily budget alert
- `v0.4.1` — Backfill estimated costs; wrap long URLs in dashboard widget
- `v0.5.0` — Real platform detection; 0.x versioning
- `v0.5.1` — Move version display into the site footer; add release docs

To create GitHub Releases from these tags later: `gh release create vX.Y.Z --notes "…"`.
