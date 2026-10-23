<?php
declare(strict_types=1);

/**
 * Reads the shop's own website pages (policies, delivery, about, FAQ ...) listed in app/site_pages.txt,
 * turns them into plain text and caches the result so every reply can use it without refetching.
 */
final class SiteKnowledge
{
    private const PAGE_CHAR_LIMIT  = 8000;
    private const TOTAL_CHAR_LIMIT = 30000;

    private static function cacheFile(): string
    {
        return DATA_DIR . '/site_cache.json';
    }

    /** URLs from app/site_pages.txt (one per line, lines starting with # are comments). */
    public static function urls(): array
    {
        $file = APP_DIR . '/site_pages.txt';
        if (!is_file($file)) {
            return [];
        }
        $urls = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#' && preg_match('~^https?://~i', $line)) {
                $urls[] = $line;
            }
        }
        return array_values(array_unique($urls));
    }

    /** Text used in the prompt. Refreshes the cache when it is old or the URL list changed. */
    public static function text(): string
    {
        $cache = self::load();
        $hours = (float) cfg('site_cache_hours', 12);
        $stale = !$cache
            || (time() - (int) $cache['fetched_at']) > $hours * 3600
            || array_column($cache['pages'], 'url') !== self::urls();

        if ($stale) {
            $cache = self::refresh($cache) ?? $cache;
        }
        if (!$cache) {
            return '';
        }

        $out = '';
        foreach ($cache['pages'] as $page) {
            if (($page['text'] ?? '') === '') {
                continue;
            }
            $block = "\n### Page: {$page['url']}\n{$page['text']}\n";
            if (mb_strlen($out . $block) > self::TOTAL_CHAR_LIMIT) {
                break;
            }
            $out .= $block;
        }
        return trim($out);
    }

    /** Status per page, for the test page. */
    public static function status(): array
    {
        $cache = self::load();
        return [
            'fetched_at' => $cache['fetched_at'] ?? null,
            'pages'      => array_map(static fn($p) => [
                'url'     => $p['url'],
                'ok'      => $p['ok'],
                'chars'   => mb_strlen($p['text'] ?? ''),
                'preview' => mb_substr($p['text'] ?? '', 0, 400),
                'error'   => $p['error'] ?? null,
            ], $cache['pages'] ?? []),
        ];
    }

    /**
     * Fetches every page again. If a page fails, its previous text is kept.
     * Returns null when another request is already refreshing.
     */
    public static function refresh(?array $previous = null): ?array
    {
        $lock = @fopen(DATA_DIR . '/locks/site_refresh.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }

        try {
            $previous = $previous ?? self::load();
            $old = [];
            foreach ($previous['pages'] ?? [] as $p) {
                $old[$p['url']] = $p['text'] ?? '';
            }

            $pages = [];
            foreach (self::urls() as $url) {
                try {
                    $text = self::fetchText($url);
                    $pages[] = [
                        'url'   => $url,
                        'ok'    => $text !== '',
                        'text'  => $text,
                        'error' => $text === '' ? 'No readable text found on this page (it may load with JavaScript)' : null,
                    ];
                } catch (Throwable $e) {
                    Log::error('site page fetch failed: ' . $url . ' ' . $e->getMessage());
                    $pages[] = ['url' => $url, 'ok' => false, 'text' => $old[$url] ?? '', 'error' => $e->getMessage()];
                }
            }

            $cache = ['fetched_at' => time(), 'pages' => $pages];
            file_put_contents(self::cacheFile(), json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            return $cache;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function load(): ?array
    {
        $file = self::cacheFile();
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) && isset($data['pages']) ? $data : null;
    }

    private static function fetchText(string $url): string
    {
        $res = Http::get($url, ['Accept: text/html,application/json;q=0.9', 'User-Agent: NurBuyBot/1.0'], 20);
        if ($res['status'] !== 200) {
            throw new RuntimeException('HTTP ' . $res['status']);
        }

        $text = is_array($res['json']) ? self::jsonToText($res['json']) : self::htmlToText($res['body']);
        $text = trim(preg_replace("/\n{3,}/u", "\n\n", $text));
        return mb_substr($text, 0, self::PAGE_CHAR_LIMIT);
    }

    /** Keeps the readable text of a page, dropping menus, scripts and styling. */
    public static function htmlToText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//header|//footer|//form|//iframe|//template') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $root = $xpath->query('//main')->item(0)
            ?? $xpath->query('//article')->item(0)
            ?? $xpath->query('//body')->item(0)
            ?? $dom->documentElement;
        if (!$root) {
            return '';
        }

        $text = self::nodeText($root);
        $lines = array_map(static fn($l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $text));
        return implode("\n", array_filter($lines, static fn($l) => $l !== ''));
    }

    private static function nodeText(DOMNode $node): string
    {
        static $block = ['p', 'div', 'section', 'li', 'ul', 'ol', 'br', 'tr', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'dd', 'dt', 'blockquote'];
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= $child->wholeText;
            } elseif ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                $inner = self::nodeText($child);
                if (in_array($tag, ['h1', 'h2', 'h3', 'h4'], true)) {
                    $out .= "\n## " . trim($inner) . "\n";
                } elseif ($tag === 'li') {
                    $out .= "\n- " . trim($inner) . "\n";
                } elseif (in_array($tag, $block, true)) {
                    $out .= "\n" . $inner . "\n";
                } else {
                    $out .= $inner;
                }
            }
        }
        return $out;
    }

    /** For API endpoints that return JSON: keep the human-readable strings. */
    private static function jsonToText(array $data): string
    {
        $parts = [];
        array_walk_recursive($data, static function ($value, $key) use (&$parts): void {
            if (!is_string($value) || mb_strlen(trim($value)) < 3 || preg_match('~^(https?://|/|\d{4}-\d\d-\d\d)~', $value)) {
                return;
            }
            $value = str_contains($value, '<') ? self::htmlToText($value) : $value;
            $parts[] = (is_string($key) ? $key . ': ' : '') . trim($value);
        });
        return implode("\n", $parts);
    }
}
