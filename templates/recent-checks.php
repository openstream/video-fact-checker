<?php
/**
 * "Recently fact-checked" list, shown under the form on the home page.
 * Expects $vfc_recent — an array of cache rows (id, short_url, video_title,
 * platform, analysis, created_at). Rows without a short_url are skipped (they
 * have no public share page).
 */
if (empty($vfc_recent) || !is_array($vfc_recent)) {
    return;
}

$vfc_recent_items = array_filter($vfc_recent, function ($r) {
    return !empty($r->short_url);
});
if (empty($vfc_recent_items)) {
    return;
}
?>
<section class="vfc-recent" aria-label="Recently fact-checked videos">
    <h2 class="vfc-recent-title">Recently fact-checked</h2>
    <ul class="vfc-recent-list">
        <?php foreach ($vfc_recent_items as $vfc_row) :
            $vfc_label = \VideoFactChecker\PlatformIcon::describe(
                isset($vfc_row->video_title) ? $vfc_row->video_title : '',
                isset($vfc_row->analysis) ? $vfc_row->analysis : '',
                100
            );
            if ($vfc_label === '') {
                $vfc_label = 'Fact check';
            }
            $vfc_platform = isset($vfc_row->platform) ? $vfc_row->platform : '';
            $vfc_url = home_url('/share/' . $vfc_row->short_url . '/');
            $vfc_date = !empty($vfc_row->created_at) ? date_i18n(get_option('date_format'), strtotime($vfc_row->created_at)) : '';
        ?>
        <li class="vfc-recent-item">
            <a class="vfc-recent-link" href="<?php echo esc_url($vfc_url); ?>">
                <?php if ($vfc_platform !== '') : ?>
                <span class="vfc-recent-icon" aria-hidden="true"><?php echo \VideoFactChecker\PlatformIcon::svg($vfc_platform, 20); ?></span>
                <?php endif; ?>
                <span class="vfc-recent-text">
                    <span class="vfc-recent-name"><?php echo esc_html($vfc_label); ?></span>
                    <?php if ($vfc_date !== '') : ?>
                    <span class="vfc-recent-date"><?php echo esc_html($vfc_date); ?></span>
                    <?php endif; ?>
                </span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
