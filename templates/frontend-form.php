<div class="video-fact-checker-form">
    <form id="vfc-form">
        <div class="form-group">
            <input type="url"
                   id="video-url"
                   name="video-url"
                   required
                   placeholder="Video URL (must contain speech)">
        </div>

        <!-- Honeypot: invisible to people, bots that fill every field get rejected (BotGuard). -->
        <div class="vfc-hp" aria-hidden="true">
            <label for="vfc-website">Website</label>
            <input type="text" id="vfc-website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div class="form-actions">
            <button type="submit" id="analyze-btn">Fact check video</button>
        </div>

        <div id="progress-container" style="display: none;">
            <div class="progress-bar">
                <div class="progress-fill"></div>
            </div>
            <p id="status-message"></p>
        </div>

        <div id="results-container" style="display: none;">
            <!-- Server-rendered result markup (TOC + analysis + transcript) goes
                 here, identical to the /share/ page. -->
            <div id="vfc-result-body"></div>
        </div>
    </form>
</div>