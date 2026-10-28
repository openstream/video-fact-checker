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
        // The link's visible label ("Dark mode" / "Light mode") is switched by CSS
        // off data-vfc-theme / prefers-color-scheme, so there is nothing to sync
        // here — not on click, and not when the system theme changes while on
        // "auto". Flipping the attribute below is enough for the label to follow.
        $(document).on('click', '.vfc-theme-toggle', function(e) {
            e.preventDefault();
            const next = effectiveTheme() === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-vfc-theme', next);
            try { localStorage.setItem('vfc-theme', next); } catch (err) {}
        });
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

    // --- Nonce handling -------------------------------------------------------
    // The nonce is baked into the page at load time and WordPress rejects it after
    // 12-24 h. iOS Safari happily restores a tab from memory days later without
    // reloading it, so the first request then fails. vfcPost() sends the current
    // nonce and, if the server answers with code 'invalid_nonce', fetches a fresh
    // one and retries the request exactly once. Concurrent failures share one
    // refresh request.
    let nonceRefresh = null;

    function refreshNonce() {
        if (!nonceRefresh) {
            nonceRefresh = $.post(vfc_ajax.ajax_url, { action: 'vfc_refresh_nonce' })
                .then(function(response) {
                    if (response && response.success && response.data.nonce) {
                        vfc_ajax.nonce = response.data.nonce;
                        return response.data.nonce;
                    }
                    return $.Deferred().reject().promise();
                })
                .always(function() { nonceRefresh = null; });
        }
        return nonceRefresh;
    }

    function isInvalidNonce(response) {
        return response && !response.success && response.data && response.data.code === 'invalid_nonce';
    }

    // Returns a jQuery promise: .done(response) for any JSON answer (success or
    // error), .fail(xhr, status, error) for transport errors.
    function vfcPost(data) {
        const send = function() {
            return $.post(vfc_ajax.ajax_url, $.extend({}, data, { nonce: vfc_ajax.nonce }));
        };
        return send().then(function(response) {
            if (!isInvalidNonce(response)) {
                return response;
            }
            console.log('Nonce expired, refreshing and retrying:', data.action);
            return refreshNonce().then(send, function() { return response; });
        });
    }

    // Page restored from the back/forward cache (e.g. an old Safari tab):
    // fetch a fresh nonce right away so the first submit doesn't hit a stale one.
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            refreshNonce();
        }
    });

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
        
        vfcPost({ action: 'vfc_process_video', url: videoUrl })
            .done(function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    displayResults(response.data);
                } else {
                    const errorMessage = response.data ? response.data.message : 'An unknown error occurred';
                    displayError(errorMessage);
                }
            })
            .fail(function(xhr, status, error) {
                console.log('Raw response:', xhr.responseText);
                console.error('Ajax error:', {xhr, status, error});
                displayError('An error occurred while processing the request.');
            });

        // Start progress checking
        startProgressChecking();
    });

    let progressInterval = null;

    function stopProgressChecking() {
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
    }

    function startProgressChecking() {
        stopProgressChecking();
        progressInterval = setInterval(function() {
            vfcPost({ action: 'vfc_check_progress' })
                .done(function(response) {
                    console.log('Progress response:', response);
                    if (response.success) {
                        updateProgressBar(response.data.progress);
                        updateStatusMessage(response.data.status);
                        
                        if (response.data.status === 'complete' || response.data.status === 'error') {
                            stopProgressChecking();
                        }
                    }
                });
        }, 2000); // Check every 2 seconds
    }

    function updateStatus(status) {
        console.log('Updating status:', status);
        vfcPost({ action: 'vfc_update_status', status: status })
            .done(function(response) {
                console.log('Status update response:', response);
                if (response.success) {
                    updateProgressBar(response.data.progress);
                    updateStatusMessage(status);
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
        stopProgressChecking();
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