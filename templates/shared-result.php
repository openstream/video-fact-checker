<div class="video-fact-checker-result">
    <div class="video-info">
        <p>Original video: <a href="<?php echo esc_url($result->video_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_url($result->video_url); ?></a></p>
        <p>Fact checked on: <?php echo esc_html(date('F j, Y', strtotime($result->created_at))); ?></p>
    </div>

    <div id="transcription-result">
        <h3>Video Transcription</h3>
        <div class="content"><?php echo nl2br(esc_html($result->transcription)); ?></div>
    </div>

    <div id="analysis-result">
        <h3>Fact Check Analysis</h3>
        <div class="content"><?php echo wp_kses_post($result->analysis); ?></div>
    </div>
</div> 