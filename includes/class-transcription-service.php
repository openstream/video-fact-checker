<?php
namespace VideoFactChecker;

class TranscriptionService {
    private $api_key;
    private $logger;

    public function __construct() {
        $this->api_key = get_option('vfc_openai_api_key');
        $this->logger = new Logger();
    }

    public function transcribe($audio_file) {
        if (!file_exists($audio_file)) {
            throw new \Exception("Audio file not found");
        }

        $this->logger->log("Starting transcription for: $audio_file");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/audio/transcriptions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->api_key
        ]);

        $post_data = [
            'file' => new \CURLFile($audio_file),
            'model' => 'whisper-1'
        ];

        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $curl_error = curl_error($ch);
            $this->logger->log("Curl error: $curl_error", 'error');
            throw new \Exception("Transcription request failed: $curl_error");
        }

        if ($http_code !== 200) {
            $error_response = json_decode($response, true);
            $error_message = $error_response['error']['message'] ?? 'Unknown error';
            $this->logger->log("Transcription failed with code $http_code: $error_message", 'error');
            throw new \Exception("Transcription failed: $error_message");
        }

        $result = json_decode($response, true);
        return $result['text'] ?? '';
    }
}
