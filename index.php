<?php
declare(strict_types=1);

// Health check: open https://<your-subdomain>/ in a browser to confirm the upload works.
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$checks = [
    'PHP >= 8.1'           => version_compare(PHP_VERSION, '8.1.0', '>='),
    'curl extension'       => extension_loaded('curl'),
    'pdo_sqlite extension' => extension_loaded('pdo_sqlite'),
    'mbstring extension'   => extension_loaded('mbstring'),
    'data folder writable' => is_writable(DATA_DIR),
    'OpenAI key set'       => !str_contains((string) cfg('openai.api_key'), 'PASTE'),
    'verify token changed' => !str_contains((string) cfg('meta.verify_token'), 'CHANGE-ME'),
];

if (NurBuyApi::enabled()) {
    try {
        $checks['NurBuy API: ' . count(NurBuyApi::catalog()['products']) . ' products'] = true;
    } catch (Throwable $e) {
        $checks['NurBuy API reachable'] = false;
    }
    $key = (string) cfg('nurbuy.bot_key', '');
    if ($key === '') {
        $checks['bot_key empty: chat orders off'] = true;
    } else {
        $ping = Http::get(rtrim((string) cfg('nurbuy.api_url'), '/') . '/bot/ping', ['X-Bot-Key: ' . $key], 15);
        $checks['bot_key accepted by API (chat orders on)'] = $ping['status'] === 200;
    }
}

$ok = !in_array(false, $checks, true);
echo cfg('shop_name') . " AI bot: " . ($ok ? 'READY' : 'NEEDS SETUP') . "\n\n";
foreach ($checks as $name => $pass) {
    echo ($pass ? '[ok]   ' : '[FAIL] ') . $name . "\n";
}
