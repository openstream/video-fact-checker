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
    html { scroll-behavior: smooth; }
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

    /* Table of contents: compact, tappable list of section links. */
    .vfc-shared-content .vfc-toc {
        background: #f7f7f5;
        border-left: 3px solid #c9c9c4;
        border-radius: 4px;
        padding: 12px 16px 12px 20px;
        margin: 0 0 20px;
    }
    .vfc-shared-content .vfc-toc ol {
        margin: 0;
        padding-left: 20px;
    }
    .vfc-shared-content .vfc-toc li {
        margin: 4px 0;
        line-height: 1.4;
    }
    .vfc-shared-content .vfc-toc a {
        text-decoration: none;
    }
    .vfc-shared-content .vfc-toc a:hover,
    .vfc-shared-content .vfc-toc a:focus {
        text-decoration: underline;
    }
    /* Offset anchor jumps so the heading isn't hidden under the sticky header.
       Section level is h2 (Parsedown) or h3 (fallback converter), so cover both. */
    .vfc-shared-content #analysis-result h2[id],
    .vfc-shared-content #analysis-result h3[id],
    .vfc-shared-content #analysis-result h4[id] {
        scroll-margin-top: 24px;
    }

    /* Collapsible transcript — folded by default, the reader rarely needs it. */
    details.vfc-transcript > summary {
        cursor: pointer;
        font-weight: 700;
        font-size: 18px;
        padding: 8px 0;
        list-style-position: inside;
    }
    details.vfc-transcript[open] > summary {
        margin-bottom: 12px;
    }
</style>
<?php
get_footer();
