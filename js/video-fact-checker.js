jQuery(document).ready(function($) {
    const form = $('#vfc-form');
    const analyzeBtn = $('#analyze-btn');
    const progressContainer = $('#progress-container');
    const statusMessage = $('#status-message');
    const resultsContainer = $('#results-container');

    // Dedicated container for error messages, kept separate from the results
    // markup so rendering an error never destroys the transcription/analysis DOM.
    let errorContainer = $('#error-container');
    if (!errorContainer.length) {
        errorContainer = $('<div id="error-container" style="display:none;"></div>');
        resultsContainer.before(errorContainer);
    }

    console.log('Video Fact Checker initialized');

    // --- Clipboard auto-fill -------------------------------------------------
    // If the user already copied a video link before landing here, offer to save
    // them a paste: when the clipboard holds a URL on a supported video host, drop
    // it into the field and show a small, dismissible notice. Only fires when the
    // field is still empty, and only for recognized video hosts (never arbitrary
    // clipboard text). Silently does nothing if the browser blocks clipboard reads.
    const videoUrlInput = $('#video-url');
    const clipboardNotice = $('#vfc-clipboard-notice');

    // Hosts we actually support (mirrors VideoProcessor::detect_platform in PHP).
    const SUPPORTED_VIDEO_HOSTS = [
        'youtube.com', 'youtu.be', 'tiktok.com', 'instagram.com',
        'twitter.com', 'x.com', 'vimeo.com', 'twitch.tv',
        'dailymotion.com', 'dai.ly'
    ];

    function isSupportedVideoUrl(text) {
        if (!text) return false;
        let url;
        try {
            url = new URL(text.trim());
        } catch (e) {
            return false; // not a URL at all
        }
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return false;
        const host = url.hostname.replace(/^www\./, '').toLowerCase();
        return SUPPORTED_VIDEO_HOSTS.some(function(h) {
            return host === h || host.endsWith('.' + h);
        });
    }

    function tryClipboardAutofill() {
        // Only help when the field is empty and the API is available.
        if (videoUrlInput.val() || !navigator.clipboard || !navigator.clipboard.readText) {
            return;
        }
        navigator.clipboard.readText().then(function(text) {
            if (!videoUrlInput.val() && isSupportedVideoUrl(text)) {
                videoUrlInput.val(text.trim());
                clipboardNotice.show();
            }
        }).catch(function() {
            // Clipboard read blocked or denied — nothing to do, stay silent.
        });
    }

    // "Clear" removes the auto-filled URL and hides the notice.
    $('#vfc-clipboard-clear').on('click', function() {
        videoUrlInput.val('').focus();
        clipboardNotice.hide();
    });

    // Once the user edits the field themselves, the notice is no longer relevant.
    videoUrlInput.on('input', function() {
        clipboardNotice.hide();
    });

    tryClipboardAutofill();
    // -------------------------------------------------------------------------

    form.on('submit', function(e) {
        e.preventDefault();
        const videoUrl = $('#video-url').val();
        console.log('Processing video URL:', videoUrl);
        
        // Show progress container and clear any previous results/errors
        progressContainer.show();
        resultsContainer.hide();
        errorContainer.hide().empty();
        analyzeBtn.prop('disabled', true);
        
        updateStatus('starting');
        
        $.ajax({
            url: vfc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'vfc_process_video',
                nonce: vfc_ajax.nonce,
                url: videoUrl
            },
            success: function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    displayResults(response.data);
                } else {
                    const errorMessage = response.data ? response.data.message : 'An unknown error occurred';
                    displayError(errorMessage);
                }
            },
            error: function(xhr, status, error) {
                console.log('Raw response:', xhr.responseText);
                console.error('Ajax error:', {xhr, status, error});
                displayError('An error occurred while processing the request.');
            }
        });

        // Start progress checking
        startProgressChecking();
    });

    function startProgressChecking() {
        const progressInterval = setInterval(function() {
            $.ajax({
                url: vfc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'vfc_check_progress',
                    nonce: vfc_ajax.nonce
                },
                success: function(response) {
                    console.log('Progress response:', response);
                    if (response.success) {
                        updateProgressBar(response.data.progress);
                        updateStatusMessage(response.data.status);
                        
                        if (response.data.status === 'complete' || response.data.status === 'error') {
                            clearInterval(progressInterval);
                        }
                    }
                }
            });
        }, 2000); // Check every 2 seconds
    }

    function updateStatus(status) {
        console.log('Updating status:', status);
        $.ajax({
            url: vfc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'vfc_update_status',
                nonce: vfc_ajax.nonce,
                status: status
            },
            success: function(response) {
                console.log('Status update response:', response);
                if (response.success) {
                    updateProgressBar(response.data.progress);
                    updateStatusMessage(status);
                }
            }
        });
    }

    function updateProgressBar(progress) {
        if (typeof progress === 'undefined') progress = 0;
        console.log('Updating progress bar:', progress + '%');
        $('.progress-fill').css('width', progress + '%');
    }

    function updateStatusMessage(status) {
        const messages = {
            'starting': 'Starting video processing...',
            'downloading': 'Downloading video...',
            'transcribing': 'Transcribing audio...',
            'analyzing': 'Analyzing content...',
            'complete': 'Analysis complete!',
            'error': 'An error occurred'
        };
        
        const message = messages[status] || 'Processing...';
        statusMessage.text(message);
    }

    function displayResults(data) {
        console.log('Displaying results:', data);
        const transcriptionContent = $('#transcription-result .content');
        const analysisContent = $('#analysis-result .content');

        progressContainer.fadeOut(400, function() {
            // Clear any error from a previous attempt so success and error states
            // never show at the same time.
            errorContainer.hide().empty();
            // Remove a share section left over from a previous run before re-adding.
            resultsContainer.find('.share-section').remove();
            // Remove any cache notice from a previous run.
            resultsContainer.find('.vfc-cache-notice').remove();

            // Info notice: always show which model produced the analysis, plus
            // whether this came from the cache (and when). Uses .text() so nothing
            // from the response can inject markup.
            const parts = [];
            if (data.cached) {
                const when = data.cached_at ? ` on ${data.cached_at}` : '';
                parts.push(`Cached result — originally fact-checked${when}`);
            } else {
                parts.push('Freshly fact-checked');
            }
            if (data.model) {
                parts.push(`model: ${data.model}`);
            }
            const notice = $('<div class="vfc-cache-notice"></div>');
            notice.text(parts.join(' · ') + '.');
            resultsContainer.prepend(notice);

            transcriptionContent.text(data.transcription);
            analysisContent.html(data.analysis);

            if (data.short_url) {
                const shareUrl = `${window.location.origin}/share/${data.short_url}`;
                const shareHtml = `
                    <div class="share-section">
                        <p>Share this fact check:</p>
                        <input type="text" readonly value="${shareUrl}" class="share-url">
                        <button class="copy-share-url">Copy Link</button>
                    </div>
                `;
                resultsContainer.append(shareHtml);
                
                // Add click handler for copy button
                $('.copy-share-url').on('click', function(e) {
                    e.preventDefault();
                    navigator.clipboard.writeText(shareUrl)
                        .then(() => {
                            const originalText = $(this).text();
                            $(this).text('Copied!');
                            setTimeout(() => {
                                $(this).text(originalText);
                            }, 2000);
                        })
                        .catch(err => console.error('Failed to copy:', err));
                });
            }
            
            analyzeBtn.prop('disabled', false);
            resultsContainer.fadeIn(400);
        });
    }

    function displayError(message) {
        console.error('Error:', message);
        progressContainer.fadeOut(400, function() {
            // Hide any previous successful result so success and error states
            // never show at the same time. Render the error in its own container
            // so the results markup (#transcription-result etc.) stays intact.
            resultsContainer.hide();

            const errorHtml = `
                <div class="error-message">
                    <span class="error-text"></span>
                    <span class="error-note">Our team has been notified and will look into it.</span>
                </div>
            `;
            errorContainer.html(errorHtml);
            // Set text via .text() so the message can never inject markup.
            errorContainer.find('.error-text').text(message);
            errorContainer.fadeIn(400);
            analyzeBtn.prop('disabled', false);
        });
    }

    // Add CSS for loading dots animation
    const style = `
        <style>
            .loading-dots {
                animation: loading 1.5s infinite;
                display: inline-block;
            }
            @keyframes loading {
                0% { opacity: .2; }
                50% { opacity: 1; }
                100% { opacity: .2; }
            }
        </style>
    `;
    $('head').append(style);

    console.log('Video Fact Checker setup complete');
});