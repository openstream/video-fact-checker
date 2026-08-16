<div class="video-fact-checker-form">
    <form id="vfc-form">
        <div class="form-group">
            <input type="url"
                   id="video-url"
                   name="video-url"
                   required
                   placeholder="Video URL (must contain speech)">
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