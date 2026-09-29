<?php

namespace App\Services\Ai;

use App\Services\Settings;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for any OpenAI-compatible /chat/completions endpoint
 * (Gemini's OpenAI layer, Xiaomi MiMo, OpenRouter, vLLM, ...).
 */
class AiClient
{
    public function __construct(private Settings $settings) {}

    public function isConfigured(): bool
    {
        return filled($this->settings->get('ai.base_url')) && filled($this->settings->get('ai.api_key'))
            && filled($this->settings->get('ai.model'));
    }

    public function model(): string
    {
        return (string) $this->settings->get('ai.model');
    }

    /**
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @return string the assistant message content
     */
    public function chat(array $messages, ?int $maxTokens = null): string
    {
        // Reasoning models (MiMo, Gemini 2.5, ...) spend part of this budget thinking before they answer.
        $maxTokens ??= $this->settings->int('ai.max_tokens') ?: 8000;
        $payload = [
            'model' => $this->model(),
            'messages' => $messages,
            'temperature' => 0,
            'max_tokens' => $maxTokens,
        ];
        if ($this->settings->bool('ai.json_mode')) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::withToken((string) $this->settings->get('ai.api_key'))
            ->acceptJson()
            ->timeout($this->settings->int('ai.timeout') ?: 120)
            ->post($this->endpoint(), $payload);

        if ($response->failed()) {
            throw new AiRequestException(
                'AI request failed with HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500),
                $response->status(),
            );
        }

        $content = $response->json('choices.0.message.content');
        if (is_array($content)) { // some providers return content parts
            $content = implode('', array_map(fn ($p) => $p['text'] ?? '', $content));
        }
        if (! is_string($content) || trim($content) === '') {
            $reason = $response->json('choices.0.finish_reason');
            throw new AiRequestException('AI response has no message content'
                .($reason === 'length' ? ' (max_tokens habis; naikkan "Maks. token jawaban").' : '.'));
        }
        if ($response->json('choices.0.finish_reason') === 'length') {
            throw new AiRequestException('AI answer was cut off at max_tokens; naikkan "Maks. token jawaban".');
        }

        return $content;
    }

    /** Cheap round-trip used by the "Tes koneksi" button. */
    public function ping(): string
    {
        return $this->chat([
            ['role' => 'system', 'content' => 'Balas hanya dengan JSON.'],
            ['role' => 'user', 'content' => 'Kirim {"ok": true}'],
        ]);
    }

    private function endpoint(): string
    {
        $base = rtrim((string) $this->settings->get('ai.base_url'), '/');

        return str_ends_with($base, '/chat/completions') ? $base : $base.'/chat/completions';
    }
}
