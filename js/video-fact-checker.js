jQuery(document).ready(function($) {
    const form = $('#vfc-form');
    const analyzeBtn = $('#analyze-btn');
    const progressContainer = $('#progress-container');
    const statusMessage = $('#status-message');
    const resultsContainer = $('#results-container');

    console.log('Video Fact Checker initialized');

    form.on('submit', function(e) {
        e.preventDefault();
        console.log('Form submitted');

        const videoUrl = $('#video-url').val();
        if (!videoUrl) {
            alert('Please enter a video URL');
            return;
        }

        console.log('Processing video URL:', videoUrl);

        // Reset and show progress
        analyzeBtn.prop('disabled', true);
        progressContainer.show();
        resultsContainer.hide();
        updateStatus('starting');

        // Start polling immediately
        let statusCheckInterval = setInterval(checkProgress, 1000); // Check every second

        $.ajax({
            url: vfcAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'process_video',
                nonce: vfcAjax.nonce,
                url: videoUrl
            },
            beforeSend: function() {
                console.log('Sending process_video AJAX request...', {
                    url: videoUrl,
                    nonce: vfcAjax.nonce,
                    ajaxurl: vfcAjax.ajaxurl
                });
            },
            success: function(response) {
                console.log('Process video response:', response);
                clearInterval(statusCheckInterval);
                if (response.success) {
                    updateStatus('complete');
                    displayResults(response.data);
                } else {
                    handleError(response.data.message);
                }
            },
            error: function(xhr, status, error) {
                console.error('Process video AJAX Error:', {
                    xhr: xhr,
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
                clearInterval(statusCheckInterval);
                handleError('An error occurred: ' + error);
            },
            complete: function() {
                console.log('Process video request completed');
                analyzeBtn.prop('disabled', false);
            }
        });
    });

    function checkProgress() {
        $.ajax({
            url: vfcAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'check_progress',
                nonce: vfcAjax.nonce
            },
            beforeSend: function() {
                console.log('Sending check_progress AJAX request...');
            },
            success: function(response) {
                console.log('Progress check response:', response);
                if (response.success && response.data.status) {
                    updateStatus(response.data.status);
                }
            },
            error: function(xhr, status, error) {
                console.error('Progress check AJAX Error:', {
                    xhr: xhr,
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
            }
        });
    }

    function updateStatus(status) {
        console.log('Updating status:', status);
        const statusMessages = {
            'starting': 'Starting video analysis',
            'downloading': 'Downloading video from URL',
            'transcribing': 'Converting video to text',
            'analyzing': `Checking facts using ${vfcAjax.model_info}`,
            'complete': 'Analysis complete!',
            'error': 'An error occurred'
        };

        // Create progress message with animation
        let message = statusMessages[status] || status;
        
        if (status !== 'complete' && status !== 'error') {
            // Add animated dots with space before them
            message += ' <span class="loading-dots">...</span>'; // Added space here
        }

        statusMessage.html(message);

        // Update progress bar if you have one
        const progressSteps = ['starting', 'downloading', 'transcribing', 'analyzing', 'complete'];
        const currentStep = progressSteps.indexOf(status);
        if (currentStep > -1) {
            const progress = (currentStep / (progressSteps.length - 1)) * 100;
            updateProgressBar(progress);
        }
    }

    function updateProgressBar(progress) {
        console.log('Updating progress bar:', progress + '%');
        $('.progress-fill').css('width', progress + '%');
    }

    function displayResults(data) {
        console.log('Displaying results:', data);
        const transcriptionContent = $('#transcription-result .content');
        const analysisContent = $('#analysis-result .content');

        // Fade out progress, fade in results
        progressContainer.fadeOut(400, function() {
            transcriptionContent.text(data.transcription);
            analysisContent.html(data.analysis);
            resultsContainer.fadeIn(400);
        });
    }

    function handleError(message) {
        console.error('Error handled:', message);
        updateStatus('error');
        alert('Error: ' + message);
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