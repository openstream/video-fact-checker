# Video Fact Checker

A WordPress plugin that allows users to input a video URL from sites like TikTok, YouTube, Twitter/X, etc. and performs audio transcription and fact-checking using the OpenAI Whisper and ChatGPT APIs.

## Installation

1. Upload the plugin files to the `/wp-content/plugins/video-fact-checker` directory
2. Run `composer install` in the plugin directory
3. Activate the plugin through the 'Plugins' screen in WordPress
4. Configure your OpenAI API key in Settings > Fact Checker

## Usage

Use the shortcode `[video_fact_checker]` in any post or page to display the video fact-checking form.

### Cache Bypass

Logged-in administrators can skip the cache for testing by adding `?nocache=1` to any video URL (for everyone else the flag is ignored):
- **Normal**: `https://www.youtube.com/watch?v=VIDEO_ID`
- **No cache**: `https://www.youtube.com/watch?v=VIDEO_ID?nocache=1`

This is useful for testing videos without storing results in the database.

## Logging

The plugin maintains detailed logs of video processing operations in:
- `/wp-content/video-fact-checker.log`

Logs include:
- Video metadata (URL, title, duration)
- Processing steps
- API responses
- Error messages

Logging can be enabled/disabled via:
- Settings > Fact Checker > Enable Logging
- Or programmatically: `update_option('vfc_enable_logging', true|false)`

## Requirements

- PHP 7.4 or higher
- WordPress 5.0 or higher
- OpenAI API key
- Local or hosting environment with ffmpeg and yt-dlp installed
- For YouTube videos on servers:
  - Either configure a proxy in Settings > Fact Checker (recommended), or put a valid `cookies.txt` into the plugin's
    **private folder** (`wp-content/uploads/vfc-private-<random>/cookies.txt`; the exact path is shown under
    Settings > Fact Checker > System information). Never put `cookies.txt` into the plugin directory: everything there
    can be downloaded over the web. If neither a proxy nor `cookies.txt` is available, YouTube videos without
    subtitles can't be downloaded.

Note: You may need to update the `cookies.txt` file periodically when authentication expires.

### Private folder (logs, cookies, temporary audio)

Since v0.27.0 the log (`video-fact-checker.log`, rotated daily to `.log.1`), `cookies.txt` and the temporary audio
files live in `wp-content/uploads/vfc-private-<random>/`. The folder gets a deny-all `.htaccess` (Apache) and a random
name (for servers that ignore `.htaccess`, e.g. nginx). To keep it outside the web root entirely, define the path in
`wp-config.php`:

```php
define('VFC_PRIVATE_DIR', '/var/lib/video-fact-checker');
```

Updating from an older version moves `wp-content/video-fact-checker.log(.1)`, the plugin's `cookies.txt` and
`temp/` there automatically.

## License

GPL v2 or later