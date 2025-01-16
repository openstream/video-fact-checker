jQuery(document).ready(function($) {
    const form = $('#vfc-form');
    const analyzeBtn = $('#analyze-btn');
    const progressContainer = $('#progress-container');
    const statusMessage = $('#status-message');
    const resultsContainer = $('#results-container');

    console.log('Video Fact Checker initialized');

    form.on('submit', function(e) {
        e.preventDefault();
        const videoUrl = $('#video-url').val();
        console.log('Processing video URL:', videoUrl);
        
        // Show progress container and hide results
        progressContainer.show();
        resultsContainer.hide();
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
            const errorHtml = `
                <div class="error-message">
                    <p>Error: ${message}</p>
                    <button onclick="location.reload()">Try Again</button>
                </div>
            `;
            resultsContainer.html(errorHtml).fadeIn(400);
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