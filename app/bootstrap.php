<?php
declare(strict_types=1);

define('APP_DIR', __DIR__);
define('DATA_DIR', __DIR__ . '/data');

$configFile = APP_DIR . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing app/config.php - copy app/config.example.php to app/config.php and fill it in.');
}

$GLOBALS['APP_CONFIG'] = require $configFile;

date_default_timezone_set(cfg('timezone', 'Asia/Dhaka'));
mb_internal_encoding('UTF-8');

if (!is_dir(DATA_DIR . '/logs')) {
    @mkdir(DATA_DIR . '/logs', 0775, true);
}
if (!is_dir(DATA_DIR . '/locks')) {
    @mkdir(DATA_DIR . '/locks', 0775, true);
}

spl_autoload_register(static function (string $class): void {
    $file = APP_DIR . '/src/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Read a config value using dot notation, e.g. cfg('openai.api_key').
 */
function cfg(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['APP_CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

set_exception_handler(static function (Throwable $e): void {
    Log::error('Uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(200); // Meta must still get 200, otherwise it keeps retrying
    }
});
