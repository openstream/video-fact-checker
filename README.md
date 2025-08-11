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

To skip caching for testing purposes, add `?nocache=1` to any video URL:
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
  - Either a valid `cookies.txt` file in the plugin directory (`wp-content/plugins/video-fact-checker/cookies.txt`),
    or configure a proxy in Settings > Fact Checker. If `cookies.txt` is missing, the plugin will require a proxy for YouTube.

Note: You may need to update the `cookies.txt` file periodically when authentication expires.

## License

GPL v2 or later