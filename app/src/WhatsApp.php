<?php
declare(strict_types=1);

/**
 * WhatsApp Cloud API adapter.
 */
final class WhatsApp
{
    public const CHANNEL = 'whatsapp';
    private const TEXT_LIMIT = 4000;

    /**
     * Converts a webhook payload (object = "whatsapp_business_account") into normalised incoming messages.
     * @return list<array>
     */
    public static function parse(array $payload): array
    {
        $out = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                foreach ($value['messages'] ?? [] as $msg) {
                    $base = [
                        'channel'  => self::CHANNEL,
                        'user_id'  => (string) ($msg['from'] ?? ''),
                        'event_id' => (string) ($msg['id'] ?? ''),
                        'text'     => '',
                    ];
                    switch ($msg['type'] ?? '') {
                        case 'text':
                            $out[] = ['kind' => 'text', 'text' => (string) $msg['text']['body']] + $base;
                            break;
                        case 'image':
                            $out[] = [
                                'kind'  => 'image',
                                'text'  => (string) ($msg['image']['caption'] ?? ''),
                                'media' => ['id' => $msg['image']['id'], 'mime' => $msg['image']['mime_type'] ?? 'image/jpeg'],
                            ] + $base;
                            break;
                        case 'audio':
                            $out[] = [
                                'kind'  => 'voice',
                                'media' => ['id' => $msg['audio']['id'], 'mime' => $msg['audio']['mime_type'] ?? 'audio/ogg'],
                            ] + $base;
                            break;
                        case 'button':
                            $out[] = ['kind' => 'text', 'text' => (string) ($msg['button']['text'] ?? '')] + $base;
                            break;
                        case 'interactive':
                            $i = $msg['interactive'] ?? [];
                            $t = $i['button_reply']['title'] ?? $i['list_reply']['title'] ?? '';
                            $out[] = ['kind' => 'text', 'text' => (string) $t] + $base;
                            break;
                        case 'reaction':
                            break; // emoji reactions need no reply
                        default:
                            $out[] = ['kind' => 'unsupported'] + $base;
                    }
                }
            }
        }
        return array_values(array_filter($out, static fn($m) => $m['user_id'] !== ''));
    }

    public static function downloadMedia(array $media): array
    {
        $auth = ['Authorization: Bearer ' . cfg('whatsapp.access_token')];
        $meta = Http::get(self::graph($media['id']), $auth, 20);
        $url  = $meta['json']['url'] ?? null;
        if (!$url) {
            throw new RuntimeException('WhatsApp media lookup failed: ' . $meta['status']);
        }
        $file = Http::get($url, $auth, 30);
        if ($file['status'] !== 200) {
            throw new RuntimeException('WhatsApp media download failed: ' . $file['status']);
        }
        $mime = $meta['json']['mime_type'] ?? $media['mime'] ?? 'application/octet-stream';
        return ['bytes' => $file['body'], 'mime' => strtok((string) $mime, ';')];
    }

    /** Marks the message as read and shows the typing indicator. */
    public static function typing(string $messageId): void
    {
        if ($messageId === '') {
            return;
        }
        self::call([
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => $messageId,
            'typing_indicator'  => ['type' => 'text'],
        ], false);
    }

    public static function sendText(string $to, string $text): void
    {
        foreach (Messenger::chunks($text, self::TEXT_LIMIT) as $part) {
            self::call([
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['body' => $part, 'preview_url' => false],
            ]);
        }
    }

    /** One image message per product with name, price and link in the caption. */
    public static function sendProducts(string $to, array $products): void
    {
        foreach ($products as $p) {
            $caption = $p['name'] . "\n" . Messenger::priceLine($p) . ($p['url'] !== '' ? "\n" . $p['url'] : '');
            if ($p['image'] !== '') {
                self::call([
                    'messaging_product' => 'whatsapp',
                    'to'                => $to,
                    'type'              => 'image',
                    'image'             => ['link' => $p['image'], 'caption' => mb_substr($caption, 0, 1000)],
                ]);
            } else {
                self::sendText($to, $caption);
            }
        }
    }

    private static function graph(string $path): string
    {
        return sprintf(
            '%s/%s/%s',
            rtrim((string) cfg('meta.graph_base', 'https://graph.facebook.com'), '/'),
            cfg('meta.graph_version', 'v23.0'),
            ltrim($path, '/')
        );
    }

    private static function call(array $payload, bool $logErrors = true): void
    {
        $res = Http::postJson(
            self::graph(cfg('whatsapp.phone_number_id') . '/messages'),
            $payload,
            ['Authorization: Bearer ' . cfg('whatsapp.access_token')],
            20
        );
        if ($logErrors && $res['status'] !== 200) {
            Log::error('WhatsApp send failed', ['status' => $res['status'], 'body' => $res['body']]);
        }
    }
}
