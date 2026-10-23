<?php
declare(strict_types=1);

/**
 * Minimal cURL wrapper used for OpenAI and Meta Graph API calls.
 */
final class Http
{
    /**
     * @return array{status:int, body:string, json:mixed, content_type:string}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        string|array|null $body = null,
        int $timeout = 30
    ): array {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '', // accept gzip (the NurBuy catalog is sent compressed)
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $response    = curl_exec($ch);
        $status      = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error       = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("HTTP {$method} {$url} failed: {$error}");
        }

        $json = null;
        if (str_contains($contentType, 'json') || (isset($response[0]) && ($response[0] === '{' || $response[0] === '['))) {
            $json = json_decode($response, true);
        }

        return ['status' => $status, 'body' => $response, 'json' => $json, 'content_type' => $contentType];
    }

    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 30): array
    {
        $headers[] = 'Content-Type: application/json';
        return self::request(
            'POST',
            $url,
            $headers,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $timeout
        );
    }

    public static function get(string $url, array $headers = [], int $timeout = 30): array
    {
        return self::request('GET', $url, $headers, null, $timeout);
    }
}
