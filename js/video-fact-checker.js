jQuery(document).ready(function($) {
    // --- Dark-mode toggle (footer) ---------------------------------------
    // 2-state switch. Default follows the system (prefers-color-scheme); once
    // the user clicks, the choice is stored in localStorage and set as
    // data-vfc-theme on <html> (which overrides the media query in CSS).
    (function initThemeToggle() {
        const root = document.documentElement;
        const mq = window.matchMedia('(prefers-color-scheme: dark)');

        function effectiveTheme() {
            const forced = root.getAttribute('data-vfc-theme');
            if (forced === 'dark' || forced === 'light') return forced;
            return mq.matches ? 'dark' : 'light';
        }
        function updateIcons() {
            // Show the icon for the mode you'd switch TO (sun in dark, moon in light).
            const next = effectiveTheme() === 'dark' ? 'light' : 'dark';
            $('.vfc-theme-toggle').attr('aria-label', 'Switch to ' + next + ' mode')
                .attr('title', 'Switch to ' + next + ' mode');
        }
        $(document).on('click', '.vfc-theme-toggle', function(e) {
            e.preventDefault();
            const next = effectiveTheme() === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-vfc-theme', next);
            try { localStorage.setItem('vfc-theme', next); } catch (err) {}
            updateIcons();
        });
        // Keep the icon in sync if the system theme changes while on "auto".
        if (mq.addEventListener) {
            mq.addEventListener('change', function() {
                if (!root.getAttribute('data-vfc-theme')) updateIcons();
            });
        }
        updateIcons();
    })();

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
        const resultBody = $('#vfc-result-body');

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

            // Render the full server-built result markup (TOC + transcript at the
            // end), identical to the /share/ page. Falls back to raw analysis if
            // an older server didn't send result_html.
            if (data.result_html) {
                resultBody.html(data.result_html);
            } else {
                resultBody.html(data.analysis);
            }

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
            // never show at the same time. The error renders in its own container
            // so the results container (#vfc-result-body) stays intact.
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