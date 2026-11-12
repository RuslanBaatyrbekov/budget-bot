<?php

namespace App\Services\Ai;

use App\Exceptions\AiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $timeout = 45,
    ) {}

    /**
     * Sends a prompt (optionally with one inline file) and returns the decoded JSON answer.
     *
     * @param  array<string, mixed>  $schema  Gemini response schema
     * @param  array{mime: string, data: string}|null  $file  raw file bytes
     * @return array<string, mixed>
     */
    public function generateJson(string $prompt, array $schema, ?array $file = null): array
    {
        if (blank($this->apiKey)) {
            throw new AiException('GEMINI_API_KEY is not configured');
        }

        $parts = [['text' => $prompt]];

        if ($file !== null) {
            $parts[] = ['inlineData' => ['mimeType' => $file['mime'], 'data' => base64_encode($file['data'])]];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->post(self::BASE_URL.$this->model.':generateContent', [
                    'contents' => [['role' => 'user', 'parts' => $parts]],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $schema,
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new AiException($e->getMessage(), 0, $e);
        }

        if ($response->status() === 429) {
            throw AiException::rateLimited();
        }

        if ($response->failed()) {
            Log::channel('ai')->error('Gemini API error', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            throw new AiException('Gemini API error: HTTP '.$response->status(), $response->status());
        }

        $raw = (string) $response->json('candidates.0.content.parts.0.text', '');
        $clean = trim(str_replace(['```json', '```'], '', $raw));
        $data = json_decode($clean, true);

        Log::channel('ai')->debug('Gemini response', ['raw' => $raw]);

        if (! is_array($data)) {
            throw new AiException('Gemini returned invalid JSON: '.json_last_error_msg());
        }

        return $data;
    }
}
