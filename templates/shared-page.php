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
<?php
// The result layout (TOC, transcript-at-end, etc.) is styled in assets/css/style.css
// (scoped to .video-fact-checker-result), which this themed page also enqueues — so
// the share page and the inline result share one stylesheet.
get_footer();
