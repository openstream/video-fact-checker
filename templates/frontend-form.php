<div class="video-fact-checker-form">
    <form id="vfc-form">
        <div class="form-group">
            <div class="form-header">
                Supports over 1,800 video platforms—including top‑tier services such as YouTube, TikTok, Instagram, Vimeo, Twitch, and Dailymotion.
            </div>
            <input type="url"
                   id="video-url"
                   name="video-url"
                   required
                   placeholder="Enter video URL">
        </div>

        <div class="form-actions">
            <button type="submit" id="analyze-btn">Analyze Video</button>
        </div>

        <div id="progress-container" style="display: none;">
            <div class="progress-bar">
                <div class="progress-fill"></div>
            </div>
            <p id="status-message"></p>
        </div>

        <div id="results-container" style="display: none;">
            <div id="transcription-result">
                <h3>Transcription</h3>
                <div class="content"></div>
            </div>

            <div id="analysis-result">
                <h3>Fact Check Analysis</h3>
                <div class="content"></div>
            </div>
        </div>
    </form>
</div>