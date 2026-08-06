<?php
// One-line descriptor of the video: its title if we captured one, otherwise a
// short summary derived from the analysis — same helper the admin list uses.
$vfc_descriptor = \VideoFactChecker\PlatformIcon::describe(
    isset($result->video_title) ? $result->video_title : '',
    isset($result->analysis) ? $result->analysis : '',
    200
);

// Build a table of contents from the analysis headings and get the analysis
// HTML back with anchor ids applied. $vfc_toc is '' when there are too few
// headings to bother with a TOC. The transcript is a real section too, so we
// add it to the TOC as a final entry that jumps to #vfc-transcript.
list($vfc_toc, $vfc_analysis_html) = \VideoFactChecker\FactChecker::build_toc(
    isset($result->analysis) ? $result->analysis : '',
    ['id' => 'vfc-transcript', 'title' => 'Video transcript']
);
?>
<div class="video-fact-checker-result">
    <div class="video-info">
        <?php if ($vfc_descriptor !== '') : ?>
        <p class="video-title"><strong><?php echo esc_html($vfc_descriptor); ?></strong></p>
        <?php endif; ?>
        <p>Original video: <a href="<?php echo esc_url($result->video_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_url($result->video_url); ?></a></p>
        <p>Fact checked on: <?php echo esc_html(date('F j, Y', strtotime($result->created_at))); ?></p>
    </div>

    <div id="analysis-result">
        <h3>Fact Check Analysis</h3>
        <?php if ($vfc_toc !== '') : ?>
        <?php echo $vfc_toc; // built from trusted headings, already escaped ?>
        <?php endif; ?>
        <div class="content"><?php echo wp_kses_post($vfc_analysis_html); ?></div>
    </div>

    <section id="transcription-result" class="vfc-transcript">
        <h2 id="vfc-transcript">Video transcript</h2>
        <div class="content"><?php echo nl2br(esc_html($result->transcription)); ?></div>
    </section>
</div>