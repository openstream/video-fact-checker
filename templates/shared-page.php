<?php
/**
 * Share page (/share/<code>/) rendered inside the site theme, so it carries the
 * normal Twenty Sixteen header nav and the Openstream/Claude footer credit — the
 * same chrome as the rest of the site. $content holds the rendered fact-check.
 */
get_header();
?>
<div id="primary" class="content-area">
    <main id="main" class="site-main" role="main">
        <article class="vfc-shared-article">
            <header class="entry-header">
                <h1 class="entry-title">Video Fact Check Results</h1>
            </header>
            <div class="entry-content vfc-shared-content">
                <?php echo $content; ?>
            </div>
        </article>
    </main>
</div>
<style>
    .vfc-shared-content .video-info {
        margin-bottom: 24px;
        padding-bottom: 20px;
        border-bottom: 1px solid #eee;
    }
    .vfc-shared-content .video-info .video-title {
        font-size: 20px;
        line-height: 1.35;
        margin: 0 0 10px;
    }
    .vfc-shared-content .video-info p {
        margin: 0 0 6px;
        word-break: break-word;
    }
    .vfc-shared-content #transcription-result,
    .vfc-shared-content #analysis-result {
        margin-bottom: 32px;
    }
    .vfc-shared-content .content {
        background: #f7f7f5;
        padding: 16px;
        border-radius: 4px;
    }
</style>
<?php
get_footer();
