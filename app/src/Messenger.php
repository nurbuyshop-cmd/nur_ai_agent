<?php
declare(strict_types=1);

/**
 * Facebook Messenger adapter (Messenger Platform Send API + webhook parsing).
 */
final class Messenger
{
    public const CHANNEL = 'messenger';
    private const BOT_METADATA = 'nurbuy-ai-bot';
    private const TEXT_LIMIT   = 1900;

    /**
     * Converts a webhook payload (object = "page") into normalised incoming messages.
     * @return list<array>
     */
    public static function parse(array $payload): array
    {
        $out = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $event) {
                $msg = $event['message'] ?? null;

                // Echo of a message sent from the Page: either our bot or a human admin.
                if ($msg && !empty($msg['is_echo'])) {
                    if (($msg['metadata'] ?? '') !== self::BOT_METADATA) {
                        $out[] = [
                            'channel'  => self::CHANNEL,
                            'user_id'  => (string) ($event['recipient']['id'] ?? ''),
                            'event_id' => (string) ($msg['mid'] ?? ''),
                            'kind'     => 'admin_echo',
                        ];
                    }
                    continue;
                }

                $userId = (string) ($event['sender']['id'] ?? '');
                if ($userId === '') {
                    continue;
                }

                if (isset($event['postback'])) {
                    $out[] = [
                        'channel'  => self::CHANNEL,
                        'user_id'  => $userId,
                        'event_id' => 'pb:' . $userId . ':' . ($event['timestamp'] ?? microtime(true)),
                        'kind'     => 'text',
                        'text'     => (string) ($event['postback']['title'] ?? $event['postback']['payload'] ?? ''),
                    ];
                    continue;
                }

                if (!$msg) {
                    continue; // delivery/read receipts etc.
                }

                $base = [
                    'channel'  => self::CHANNEL,
                    'user_id'  => $userId,
                    'event_id' => (string) ($msg['mid'] ?? ''),
                    'text'     => (string) ($msg['quick_reply']['payload'] ?? $msg['text'] ?? ''),
                ];

                $attachment = $msg['attachments'][0] ?? null;
                if ($attachment && !isset($msg['sticker_id'])) {
                    $type = $attachment['type'] ?? '';
                    $url  = (string) ($attachment['payload']['url'] ?? '');
                    if ($type === 'image' && $url !== '') {
                        $out[] = $base + ['kind' => 'image', 'media' => ['url' => $url]];
                        continue;
                    }
                    if ($type === 'audio' && $url !== '') {
                        $out[] = $base + ['kind' => 'voice', 'media' => ['url' => $url]];
                        continue;
                    }
                    $out[] = $base + ['kind' => 'unsupported'];
                    continue;
                }

                if (isset($msg['sticker_id'])) {
                    continue; // a like/sticker needs no reply
                }

                if ($base['text'] !== '') {
                    $out[] = $base + ['kind' => 'text'];
                }
            }
        }
        return $out;
    }

    public static function downloadMedia(array $media): array
    {
        $res = Http::get($media['url'], [], 30);
        if ($res['status'] !== 200) {
            throw new RuntimeException('Messenger media download failed: ' . $res['status']);
        }
        return ['bytes' => $res['body'], 'mime' => strtok($res['content_type'], ';') ?: 'application/octet-stream'];
    }

    public static function typing(string $userId): void
    {
        self::call(['recipient' => ['id' => $userId], 'sender_action' => 'typing_on']);
    }

    public static function sendText(string $userId, string $text): void
    {
        foreach (self::chunks($text, self::TEXT_LIMIT) as $part) {
            self::call([
                'recipient'      => ['id' => $userId],
                'messaging_type' => 'RESPONSE',
                'message'        => ['text' => $part, 'metadata' => self::BOT_METADATA],
            ]);
        }
    }

    /**
     * Product cards as a horizontally scrollable carousel (generic template).
     */
    public static function sendProducts(string $userId, array $products): void
    {
        if (!$products) {
            return;
        }
        $elements = [];
        foreach ($products as $p) {
            $el = [
                'title'    => mb_substr($p['name'], 0, 80),
                'subtitle' => mb_substr(self::priceLine($p), 0, 80),
            ];
            if ($p['image'] !== '') {
                $el['image_url'] = $p['image'];
            }
            if ($p['url'] !== '') {
                $el['default_action'] = ['type' => 'web_url', 'url' => $p['url']];
                $el['buttons'] = [['type' => 'web_url', 'url' => $p['url'], 'title' => 'বিস্তারিত দেখুন']];
            }
            $elements[] = $el;
        }

        self::call([
            'recipient'      => ['id' => $userId],
            'messaging_type' => 'RESPONSE',
            'message'        => [
                'metadata'   => self::BOT_METADATA,
                'attachment' => [
                    'type'    => 'template',
                    'payload' => ['template_type' => 'generic', 'image_aspect_ratio' => 'square', 'elements' => $elements],
                ],
            ],
        ]);
    }

    public static function priceLine(array $p): string
    {
        $cur  = (string) cfg('products.currency', '৳');
        $line = $p['sale_price'] !== null
            ? sprintf('%s%s (আগে %s%s)', $cur, self::money($p['sale_price']), $cur, self::money($p['price']))
            : $cur . self::money($p['price']);
        if ($p['in_stock'] === false) {
            $line .= ' · স্টক নেই';
        }
        return $line;
    }

    private static function money(float $v): string
    {
        return number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2);
    }

    private static function call(array $payload): void
    {
        $url = sprintf(
            '%s/%s/me/messages?access_token=%s',
            rtrim((string) cfg('meta.graph_base', 'https://graph.facebook.com'), '/'),
            cfg('meta.graph_version', 'v23.0'),
            rawurlencode((string) cfg('messenger.page_access_token'))
        );
        $res = Http::postJson($url, $payload, [], 20);
        if ($res['status'] !== 200) {
            Log::error('Messenger send failed', ['status' => $res['status'], 'body' => $res['body']]);
        }
    }

    /** Splits long text on paragraph/sentence boundaries. */
    public static function chunks(string $text, int $limit): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }
        $parts = [];
        $buf   = '';
        foreach (preg_split('/(?<=\n)|(?<=[।.!?])\s+/u', $text) as $piece) {
            if (mb_strlen($buf . $piece) > $limit && $buf !== '') {
                $parts[] = trim($buf);
                $buf = '';
            }
            while (mb_strlen($piece) > $limit) {
                $parts[] = mb_substr($piece, 0, $limit);
                $piece   = mb_substr($piece, $limit);
            }
            $buf .= $piece . ' ';
        }
        if (trim($buf) !== '') {
            $parts[] = trim($buf);
        }
        return $parts;
    }
}
