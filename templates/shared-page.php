<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Video Fact Check Results</title>
    <?php wp_head(); ?>
    <style>
        body {
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        .vfc-shared-page {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .vfc-shared-content h1 {
            margin-top: 0;
            color: #333;
        }
        .video-info {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        #transcription-result, #analysis-result {
            margin-bottom: 30px;
        }
        .content {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
        }
    </style>
</head>
<body <?php body_class('vfc-shared-page-body'); ?>>
    <div class="vfc-shared-page">
        <div class="vfc-shared-content">
            <h1>Video Fact Check Results</h1>
            <?php echo $content; ?>
        </div>
    </div>
    <?php wp_footer(); ?>
</body>
</html> 