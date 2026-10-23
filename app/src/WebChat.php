<?php
declare(strict_types=1);

/**
 * Website live chat adapter (nurbuy.com support chat, through the NurBuy API).
 * Incoming messages arrive at public/site-chat.php; replies go to /bot/chat/{conv}/send.
 */
final class WebChat
{
    public const CHANNEL = 'web';

    /** Turns the API's notification into a normalised incoming message. */
    public static function parse(array $payload): ?array
    {
        $conv = (string) ($payload['conv'] ?? '');
        $m    = $payload['msg'] ?? null;
        if (!preg_match('/^[A-Za-z0-9_]{3,40}$/', $conv) || !is_array($m)) {
            return null;
        }
        $text = trim((string) ($m['text'] ?? ''));

        // The page the customer is looking at, so "is this available?" makes sense
        $p = $payload['product'] ?? null;
        if (is_array($p) && !empty($p['n'])) {
            $context = '[Customer is viewing the product "' . $p['n'] . '"' . (!empty($p['price']) ? ', price ' . $p['price'] : '') . ']';
            $text = $text !== '' ? $context . "\n" . $text : $context;
        }

        $base = [
            'channel'  => self::CHANNEL,
            'user_id'  => $conv,
            'event_id' => 'm' . (int) ($m['id'] ?? 0),
            'text'     => $text,
        ];
        $url = (string) ($m['url'] ?? '');
        return match ((string) ($m['kind'] ?? 't')) {
            'img'   => $url !== '' ? $base + ['kind' => 'image', 'media' => ['url' => $url]] : null,
            'aud'   => $url !== '' ? $base + ['kind' => 'voice', 'media' => ['url' => $url]] : null,
            't', 'prd', 'ord' => $text !== '' ? $base + ['kind' => 'text'] : null,
            default => null,
        };
    }

    public static function downloadMedia(array $media): array
    {
        $res = Http::get($media['url'], [], 30);
        if ($res['status'] !== 200) {
            throw new RuntimeException('Chat media download failed: ' . $res['status']);
        }
        return ['bytes' => $res['body'], 'mime' => strtok($res['content_type'], ';') ?: 'application/octet-stream'];
    }

    public static function sendText(string $conv, string $text): void
    {
        self::post($conv, '/send', ['kind' => 't', 'text' => $text]);
    }

    /** One product card per product (photo, name, price, link). */
    public static function sendProducts(string $conv, array $products): void
    {
        foreach ($products as $p) {
            self::post($conv, '/send', ['kind' => 'prd', 'text' => '', 'meta' => ['p' => [
                'name'  => $p['name'],
                'price' => $p['sale_price'] ?? $p['price'],
                'img'   => $p['image'],
                'link'  => $p['url'],
            ]]]);
        }
    }

    /** Moves the chat to the team's inbox (Human mode). */
    public static function handoff(string $conv): void
    {
        self::post($conv, '/handoff', []);
    }

    private static function post(string $conv, string $path, array $body): void
    {
        $url = rtrim((string) cfg('nurbuy.api_url'), '/') . '/bot/chat/' . rawurlencode($conv) . $path;
        $res = Http::postJson($url, $body, ['X-Bot-Key: ' . cfg('nurbuy.bot_key')], 20);
        if ($res['status'] === 409) {
            Log::info('web chat taken over by the team', ['conv' => $conv]);
        } elseif ($res['status'] !== 200) {
            Log::error('web chat send failed', ['status' => $res['status'], 'body' => $res['body']]);
        }
    }
}
