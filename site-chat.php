<?php
declare(strict_types=1);

/**
 * Receives customer messages from the nurbuy.com support chat (sent by api.nurbuy.com
 * while "AI replies" is on in admin > Live messages) and answers them.
 * In api.nurbuy.com/config.php: 'bot_chat_url' => 'https://bot.nurbuy.com/site-chat.php'
 */

require __DIR__ . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$key  = (string) cfg('nurbuy.bot_key', '');
$sent = (string) ($_SERVER['HTTP_X_BOT_KEY'] ?? '');
if (strlen($key) < 24 || !hash_equals($key, $sent)) {
    Log::error('Rejected site chat: bad key');
    http_response_code(401);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$msg = is_array($payload) ? WebChat::parse($payload) : null;

// Answer right away; the reply is written into the chat afterwards.
ignore_user_abort(true);
set_time_limit(180);
http_response_code(200);
header('Content-Type: text/plain');
header('Content-Length: 2');
header('Connection: close');
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

if ($msg) {
    (new MessageHandler())->handle($msg);
}
