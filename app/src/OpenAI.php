<?php
declare(strict_types=1);

/**
 * Thin client for the OpenAI Responses API and audio transcription.
 */
final class OpenAI
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = (string) cfg('openai.api_key');
        $this->baseUrl = rtrim((string) cfg('openai.base_url', 'https://api.openai.com/v1'), '/');
        $this->timeout = (int) cfg('openai.timeout', 45);
    }

    /**
     * Calls POST /responses.
     *
     * @return array{text:string, calls:list<array{call_id:string,name:string,arguments:array}>, output:list<array>, usage:array}
     */
    public function respond(string $model, string $instructions, array $input, array $tools = []): array
    {
        $payload = [
            'model'             => $model,
            'instructions'      => $instructions,
            'input'             => $input,
            'max_output_tokens' => (int) cfg('openai.max_output_tokens', 600),
            'store'             => false,
        ];
        if ($tools) {
            $payload['tools'] = $tools;
        }
        $effort = cfg('openai.reasoning_effort');
        if ($effort) {
            $payload['reasoning'] = ['effort' => $effort];
            $payload['include']   = ['reasoning.encrypted_content'];
        }

        $res = $this->post('/responses', $payload);

        $text  = '';
        $calls = [];
        foreach ($res['output'] ?? [] as $item) {
            $type = $item['type'] ?? '';
            if ($type === 'message') {
                foreach ($item['content'] ?? [] as $part) {
                    if (($part['type'] ?? '') === 'output_text') {
                        $text .= $part['text'];
                    }
                }
            } elseif ($type === 'function_call') {
                $args = json_decode((string) ($item['arguments'] ?? '{}'), true);
                $calls[] = [
                    'call_id'   => (string) $item['call_id'],
                    'name'      => (string) $item['name'],
                    'arguments' => is_array($args) ? $args : [],
                ];
            }
        }

        return [
            'text'   => trim($text),
            'calls'  => $calls,
            'output' => self::replayableItems($res['output'] ?? []),
            'usage'  => $res['usage'] ?? [],
        ];
    }

    /**
     * Output items that can be sent back as input on the next turn of a tool loop.
     * Server-side ids are dropped because responses are not stored (store=false).
     */
    private static function replayableItems(array $output): array
    {
        $items = [];
        foreach ($output as $item) {
            $type = $item['type'] ?? '';
            if ($type === 'function_call') {
                $items[] = [
                    'type'      => 'function_call',
                    'call_id'   => $item['call_id'],
                    'name'      => $item['name'],
                    'arguments' => $item['arguments'],
                ];
            } elseif ($type === 'reasoning' && !empty($item['encrypted_content'])) {
                $items[] = $item;
            } elseif ($type === 'message') {
                $text = '';
                foreach ($item['content'] ?? [] as $part) {
                    if (($part['type'] ?? '') === 'output_text') {
                        $text .= $part['text'];
                    }
                }
                if ($text !== '') {
                    $items[] = ['role' => 'assistant', 'content' => $text];
                }
            }
        }
        return $items;
    }

    /**
     * Speech to text. Accepts ogg/opus (WhatsApp), mp4/aac (Messenger), mp3, m4a, wav, webm.
     */
    public function transcribe(string $filePath, string $mimeType, string $fileName): string
    {
        $fields = [
            'model'  => (string) cfg('openai.transcribe_model', 'gpt-4o-mini-transcribe'),
            'file'   => new CURLFile($filePath, $mimeType, $fileName),
            'prompt' => 'Customer voice message to an online shop in Bangladesh. Usually Bangla, sometimes mixed with English. '
                      . 'Words like: দাম, ডেলিভারি, অর্ডার, সাইজ, কালার, ক্যাশ অন ডেলিভারি, বিকাশ.',
            'response_format' => 'json',
        ];

        $res = Http::request(
            'POST',
            $this->baseUrl . '/audio/transcriptions',
            ['Authorization: Bearer ' . $this->apiKey],
            $fields,
            $this->timeout
        );
        $this->assertOk($res, '/audio/transcriptions');

        return trim((string) ($res['json']['text'] ?? ''));
    }

    private function post(string $path, array $payload): array
    {
        $res = Http::postJson(
            $this->baseUrl . $path,
            $payload,
            ['Authorization: Bearer ' . $this->apiKey],
            $this->timeout
        );
        $this->assertOk($res, $path);
        return $res['json'];
    }

    private function assertOk(array $res, string $path): void
    {
        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            $msg = $res['json']['error']['message'] ?? substr($res['body'], 0, 500);
            throw new RuntimeException("OpenAI {$path} error {$res['status']}: {$msg}");
        }
    }
}
