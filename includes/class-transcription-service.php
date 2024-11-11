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

        if ($http_code !== 200) {
            $this->logger->log("Transcription failed with code $http_code", 'error');
            throw new \Exception("Transcription failed");
        }

        $result = json_decode($response, true);
        return $result['text'] ?? '';
    }
}
