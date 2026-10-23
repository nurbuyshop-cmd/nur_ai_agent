<?php
declare(strict_types=1);

/**
 * Handles one normalised incoming message end to end:
 * de-duplicate -> respect handoff pause -> read media -> ask the agent -> send reply -> save history.
 */
final class MessageHandler
{
    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
    private const MAX_AUDIO_BYTES = 24 * 1024 * 1024;

    /** Replies collected for the 'test' channel instead of being sent to Meta. */
    public array $collected = [];

    private Agent $agent;
    private ?OpenAI $ai;

    public function __construct(?Agent $agent = null, ?OpenAI $ai = null)
    {
        $this->ai    = $ai;
        $this->agent = $agent ?? new Agent($ai);
    }

    public function handle(array $msg): void
    {
        $channel = $msg['channel'];
        $userId  = $msg['user_id'];

        if (!empty($msg['event_id']) && !Store::markEventProcessed($channel . ':' . $msg['event_id'])) {
            Log::info('duplicate event skipped', ['event' => $msg['event_id']]);
            return;
        }

        $conv = Store::touchConversation($channel, $userId);

        if ($msg['kind'] === 'admin_echo') {
            if (cfg('handoff.pause_on_admin_reply', true)) {
                Store::pause($conv, (int) cfg('handoff.pause_hours', 12), 'admin replied manually');
                Log::info('bot paused: admin replied', ['conv' => $conv]);
            }
            return;
        }

        // Website chats follow the Bot/Human mode in the admin panel instead of this pause.
        if ($channel !== WebChat::CHANNEL && Store::isPaused($conv)) {
            Log::info('bot paused, message left for a human', ['conv' => $conv]);
            return;
        }

        $lock = $this->lock($conv);
        try {
            $this->typing($msg);
            $this->process($conv, $msg);
        } catch (Throwable $e) {
            Log::error('handle failed: ' . $e->getMessage(), ['conv' => $conv]);
            $this->sendText($msg, 'দুঃখিত, এই মুহূর্তে একটু সমস্যা হচ্ছে। কিছুক্ষণ পর আবার লিখুন, অথবা আমাদের হটলাইনে কল করুন।');
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function process(string $conv, array $msg): void
    {
        $kind     = $msg['kind'];
        $text     = trim((string) ($msg['text'] ?? ''));
        $imageUrl = null;

        if ($kind === 'unsupported') {
            $this->sendText($msg, 'দুঃখিত, এই ধরনের ফাইল আমি এখনো খুলতে পারি না। অনুগ্রহ করে লিখে, ছবি দিয়ে বা ভয়েস মেসেজে জানান।');
            return;
        }

        if ($kind === 'voice') {
            $media = $this->download($msg);
            if (strlen($media['bytes']) > self::MAX_AUDIO_BYTES) {
                $this->sendText($msg, 'ভয়েস মেসেজটা অনেক বড়। অনুগ্রহ করে ছোট করে আবার পাঠান বা লিখে জানান।');
                return;
            }
            $text = $this->transcribe($media);
            Log::info('voice transcribed', ['conv' => $conv, 'text' => $text]);
            if ($text === '') {
                $this->sendText($msg, 'দুঃখিত, ভয়েস মেসেজটা পরিষ্কার শোনা যায়নি। আরেকবার বলবেন, বা লিখে জানাবেন?');
                return;
            }
        }

        if ($kind === 'image') {
            $media = $this->download($msg);
            if (strlen($media['bytes']) > self::MAX_IMAGE_BYTES) {
                $this->sendText($msg, 'ছবিটা অনেক বড়। একটু ছোট ছবি পাঠাবেন?');
                return;
            }
            $mime     = str_starts_with($media['mime'], 'image/') ? $media['mime'] : 'image/jpeg';
            $imageUrl = 'data:' . $mime . ';base64,' . base64_encode($media['bytes']);
        }

        // The website chat greets the customer by itself, so the first reply should not greet again.
        $askText = $text;
        if ($msg['channel'] === WebChat::CHANNEL && !Store::history($conv, 1)) {
            $askText = "[The chat window already greeted the customer with a welcome message. Do not greet or welcome them again; answer directly.]\n" . $text;
        }

        $result = $this->agent->reply($conv, $askText, $kind, $imageUrl);

        $this->sendText($msg, $result['text']);
        if ($result['products']) {
            $this->sendProducts($msg, $result['products']);
        }

        // Save history as plain text so later turns stay cheap.
        $stored = match ($kind) {
            'voice' => '[Voice message, transcribed] ' . $text,
            'image' => '[Customer sent a photo] ' . ($text !== '' ? $text : '(no caption)') . ($result['note'] ? " ({$result['note']})" : ''),
            default => $text,
        };
        Store::addMessage($conv, 'user', $stored);

        $assistant = $result['text'];
        if ($result['products']) {
            $assistant .= "\n[Shown: " . implode('; ', array_map(
                static fn($p) => $p['name'] . ' - ' . Messenger::priceLine($p),
                $result['products']
            )) . ']';
        }
        Store::addMessage($conv, 'assistant', $assistant);

        if ($result['handoff']) {
            if ($msg['channel'] !== WebChat::CHANNEL) {
                Store::pause($conv, (int) cfg('handoff.pause_hours', 12), $result['handoff']['reason']);
            }
            $this->notifyHandoff($msg, $result['handoff']);
        }
    }

    // ------------------------------------------------------------------
    // Channel dispatch
    // ------------------------------------------------------------------

    private function typing(array $msg): void
    {
        try {
            match ($msg['channel']) {
                Messenger::CHANNEL => Messenger::typing($msg['user_id']),
                WhatsApp::CHANNEL  => WhatsApp::typing((string) $msg['event_id']),
                default            => null, // the website shows its own typing dots
            };
        } catch (Throwable) {
            // A missing typing indicator is harmless.
        }
    }

    private function sendText(array $msg, string $text): void
    {
        match ($msg['channel']) {
            Messenger::CHANNEL => Messenger::sendText($msg['user_id'], $text),
            WhatsApp::CHANNEL  => WhatsApp::sendText($msg['user_id'], $text),
            WebChat::CHANNEL   => WebChat::sendText($msg['user_id'], $text),
            default            => $this->collected[] = ['type' => 'text', 'text' => $text],
        };
    }

    private function sendProducts(array $msg, array $products): void
    {
        match ($msg['channel']) {
            Messenger::CHANNEL => Messenger::sendProducts($msg['user_id'], $products),
            WhatsApp::CHANNEL  => WhatsApp::sendProducts($msg['user_id'], $products),
            WebChat::CHANNEL   => WebChat::sendProducts($msg['user_id'], $products),
            default            => $this->collected[] = ['type' => 'products', 'products' => $products],
        };
    }

    private function download(array $msg): array
    {
        if (isset($msg['media']['bytes'])) {
            return $msg['media']; // test channel passes the file directly
        }
        return match ($msg['channel']) {
            Messenger::CHANNEL => Messenger::downloadMedia($msg['media']),
            WhatsApp::CHANNEL  => WhatsApp::downloadMedia($msg['media']),
            WebChat::CHANNEL   => WebChat::downloadMedia($msg['media']),
        };
    }

    private function transcribe(array $media): string
    {
        $ext = match (true) {
            str_contains($media['mime'], 'ogg'), str_contains($media['mime'], 'opus') => 'ogg',
            str_contains($media['mime'], 'mpeg'), str_contains($media['mime'], 'mp3') => 'mp3',
            str_contains($media['mime'], 'wav')  => 'wav',
            str_contains($media['mime'], 'webm') => 'webm',
            str_contains($media['mime'], 'm4a'), str_contains($media['mime'], 'aac') => 'm4a',
            default => 'mp4',
        };
        $dir = DATA_DIR . '/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/voice_' . bin2hex(random_bytes(6)) . '.' . $ext;
        file_put_contents($path, $media['bytes']);
        try {
            return ($this->ai ?? new OpenAI())->transcribe($path, $media['mime'], basename($path));
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function notifyHandoff(array $msg, array $handoff): void
    {
        Log::info('handoff', ['channel' => $msg['channel'], 'user' => $msg['user_id']] + $handoff);
        if ($msg['channel'] === WebChat::CHANNEL) {
            WebChat::handoff($msg['user_id']); // the chat moves to admin > Live messages as Human
        }
        $to = (string) cfg('handoff.notify_email', '');
        if ($to === '') {
            return;
        }
        $where = match ($msg['channel']) {
            WhatsApp::CHANNEL => 'WhatsApp: +' . $msg['user_id'],
            WebChat::CHANNEL  => 'Website chat (admin > Live messages), conversation ' . $msg['user_id'],
            default           => 'Messenger (Page inbox), user id ' . $msg['user_id'],
        };
        $body = "A customer needs a human reply.\n\nChannel: {$where}\nReason: {$handoff['reason']}\nSummary: {$handoff['summary']}\n\n"
              . 'The bot stays silent in this chat for ' . cfg('handoff.pause_hours', 12) . " hours.\n";
        @mail($to, '=?UTF-8?B?' . base64_encode('[' . cfg('shop_name') . ' bot] Customer needs help') . '?=', $body,
            "Content-Type: text/plain; charset=UTF-8\r\n");
    }

    /** Serialises messages from the same customer so replies stay in order. */
    private function lock(string $conv)
    {
        $fh = @fopen(DATA_DIR . '/locks/' . md5($conv) . '.lock', 'c');
        if ($fh && flock($fh, LOCK_EX)) {
            return $fh;
        }
        return null;
    }
}
