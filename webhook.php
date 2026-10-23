<?php
declare(strict_types=1);

/**
 * Single webhook URL for both Facebook Messenger and WhatsApp.
 * Paste https://<your-subdomain>/webhook.php as the Callback URL in the Meta App dashboard.
 */

require __DIR__ . '/app/bootstrap.php';

// --- 1. Verification handshake (Meta sends a GET once when you save the webhook) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && hash_equals((string) cfg('meta.verify_token'), (string) $token)) {
        header('Content-Type: text/plain');
        echo $challenge;
    } else {
        http_response_code(403);
        echo 'Verification failed';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// --- 2. Read and authenticate the event ---
$raw = (string) file_get_contents('php://input');

$secret = (string) cfg('meta.app_secret', '');
if ($secret !== '') {
    $sent     = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    if (!hash_equals($expected, $sent)) {
        Log::error('Rejected webhook: bad signature');
        http_response_code(403);
        exit;
    }
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

// --- 3. Answer 200 right away; Meta retries if it waits too long ---
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

// --- 4. Process ---
$messages = match ($payload['object'] ?? '') {
    'page'                      => cfg('messenger.enabled', true) ? Messenger::parse($payload) : [],
    'whatsapp_business_account' => cfg('whatsapp.enabled', false) ? WhatsApp::parse($payload) : [],
    default                     => [],
};

if ($messages) {
    $handler = new MessageHandler();
    foreach ($messages as $msg) {
        $handler->handle($msg);
    }
}
