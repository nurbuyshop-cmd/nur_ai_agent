<?php
declare(strict_types=1);

final class Log
{
    public static function info(string $message, array $context = []): void
    {
        if (cfg('debug_log', true)) {
            self::write('info', $message, $context);
        }
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? ' ' . json_encode(self::redact($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents(DATA_DIR . '/logs/' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    private static function redact(array $context): array
    {
        array_walk_recursive($context, static function (&$value, $key): void {
            if (is_string($key) && preg_match('/(^|_)(token|secret|key|password|authorization)$/i', $key)) {
                $value = '***';
            } elseif (is_string($value) && strlen($value) > 2000) {
                $value = substr($value, 0, 2000) . '...';
            }
        });
        return $context;
    }
}
