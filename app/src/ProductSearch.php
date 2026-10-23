<?php
declare(strict_types=1);

/**
 * Looks up products on the shop website, either straight from MySQL or through an HTTP API.
 * Every result is normalised to:
 *   id, name, price, sale_price, in_stock, image, url, category, description, match
 */
final class ProductSearch
{
    private const CANDIDATE_LIMIT = 80;

    private ?PDO $pdo = null;

    /** Lets tests (or a shared connection) supply the database handle. */
    public function usePdo(PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    public function search(string $query, ?float $maxPrice = null, ?int $limit = null): array
    {
        $limit = $limit ?? (int) cfg('products.max_results', 5);
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $mode = self::mode();
        $raw  = match ($mode) {
            'nurbuy' => NurBuyApi::productRows(),
            'api'    => $this->fromApi($query),
            default  => $this->fromMysql($query),
        };

        $products = array_map([$this, 'normalise'], $raw);
        if ($maxPrice !== null && $maxPrice > 0) {
            $products = array_values(array_filter(
                $products,
                static fn(array $p) => ($p['sale_price'] ?? $p['price']) <= $maxPrice
            ));
        }

        $products = $this->rank($products, $query);
        return array_slice($products, 0, $limit);
    }

    /** 'nurbuy' when the NurBuy API is configured, otherwise products.mode ('mysql' or 'api'). */
    public static function mode(): string
    {
        return NurBuyApi::enabled() ? 'nurbuy' : (string) cfg('products.mode', 'mysql');
    }

    // ------------------------------------------------------------------
    // Sources
    // ------------------------------------------------------------------

    private function fromMysql(string $query): array
    {
        $c     = cfg('products.mysql');
        $pdo   = $this->mysql($c);
        $table = self::ident($c['table']);
        $alias = 'p';

        $select = [];
        foreach (['id', 'name', 'name_bn', 'description', 'price', 'sale_price', 'image', 'stock', 'category', 'slug'] as $field) {
            $col = $c['col_' . $field] ?? null;
            if ($col) {
                $select[] = "{$alias}." . self::ident($col) . " AS `{$field}`";
            }
        }

        $join = '';
        $categoryExpr = !empty($c['col_category']) ? "{$alias}." . self::ident($c['col_category']) : null;
        if (!empty($c['category_join'])) {
            $j = $c['category_join'];
            $join = sprintf(
                ' LEFT JOIN %s c ON c.%s = %s.%s',
                self::ident($j['table']),
                self::ident($j['foreign_key']),
                $alias,
                self::ident($j['local_key'])
            );
            $categoryExpr = 'c.' . self::ident($j['name_col']);
            $select[] = "{$categoryExpr} AS `category`";
        }

        $searchCols = [];
        foreach (['name', 'name_bn', 'description'] as $field) {
            if (!empty($c['col_' . $field])) {
                $searchCols[] = "{$alias}." . self::ident($c['col_' . $field]);
            }
        }
        if ($categoryExpr) {
            $searchCols[] = $categoryExpr;
        }

        $words  = self::words($query);
        $or     = [];
        $params = [];
        foreach ($words as $i => $word) {
            foreach ($searchCols as $j => $col) {
                $key = ":w{$i}_{$j}";
                $or[] = "{$col} LIKE {$key} ESCAPE '!'";
                $params[$key] = '%' . self::escapeLike($word) . '%';
            }
        }
        if (!$or) {
            return [];
        }

        $where = '(' . implode(' OR ', $or) . ')';
        if (!empty($c['where_active'])) {
            $where .= ' AND (' . $c['where_active'] . ')';
        }

        $sql = 'SELECT ' . implode(', ', $select) . " FROM {$table} {$alias}{$join} WHERE {$where} LIMIT " . self::CANDIDATE_LIMIT;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fromApi(string $query): array
    {
        $url     = str_replace('{q}', rawurlencode($query), (string) cfg('products.api.search_url'));
        $headers = (array) cfg('products.api.headers', []);
        $res     = Http::get($url, $headers, 15);
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw new RuntimeException('Product API error ' . $res['status']);
        }
        $data = $res['json']['data'] ?? $res['json']['products'] ?? $res['json'];
        return is_array($data) ? array_values($data) : [];
    }

    private function mysql(array $c): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['database'], $c['charset'] ?? 'utf8mb4');
            $this->pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        }
        return $this->pdo;
    }

    // ------------------------------------------------------------------
    // Ranking and normalisation
    // ------------------------------------------------------------------

    private function rank(array $products, string $query): array
    {
        $words  = self::words($query);
        $phrase = mb_strtolower($query);

        foreach ($products as &$p) {
            $name = mb_strtolower($p['name'] . ' ' . ($p['name_bn'] ?? ''));
            $cat  = mb_strtolower((string) $p['category']);
            $desc = $p['_text'] ?? mb_strtolower((string) $p['description']);

            $score   = 0;
            $inName  = 0;
            foreach ($words as $w) {
                if (self::has($name, $w)) {
                    $score += 3;
                    $inName++;
                } elseif (self::has($cat, $w)) {
                    $score += 2;
                } elseif (self::has($desc, $w)) {
                    $score += 1;
                }
            }
            if ($phrase !== '' && self::has($name, $phrase)) {
                $score += 5;
            }
            $p['_score'] = $score;
            $p['match']  = ($words && $inName === count($words)) ? 'exact' : 'similar';
        }
        unset($p);

        // In-stock items first, then by relevance.
        usort($products, static fn($a, $b) =>
            [($b['in_stock'] !== false), $b['_score']] <=> [($a['in_stock'] !== false), $a['_score']]);

        // The MySQL source matches any single word, so drop rows with no real relevance.
        // An external API already did its own matching, so keep its results.
        if (self::mode() !== 'api') {
            $products = array_filter($products, static fn($p) => $p['_score'] > 0);
        }

        return array_map(static function (array $p) {
            unset($p['_score'], $p['name_bn'], $p['_text']);
            return $p;
        }, array_values($products));
    }

    private function normalise(array $row): array
    {
        $id    = (string) ($row['id'] ?? '');
        $slug  = (string) ($row['slug'] ?? '');
        $image = (string) ($row['image'] ?? '');

        // Some shops store several images as JSON or comma-separated; take the first one.
        if ($image !== '' && $image[0] === '[') {
            $list  = json_decode($image, true);
            $image = is_array($list) && $list ? (string) reset($list) : '';
        } elseif (str_contains($image, ',')) {
            $image = trim(explode(',', $image)[0]);
        }
        if ($image !== '' && !preg_match('~^https?://~i', $image)) {
            $image = rtrim((string) cfg('products.image_base_url', ''), '/') . '/' . ltrim($image, '/');
        }

        $url = (string) ($row['url'] ?? '');
        if ($url === '') {
            $url = strtr((string) cfg('products.url_pattern', ''), ['{id}' => rawurlencode($id), '{slug}' => rawurlencode($slug)]);
        }

        $stock   = $row['stock'] ?? null;
        $inStock = $stock === null ? null : ((is_numeric($stock) ? (float) $stock > 0 : (bool) $stock));
        $sale    = isset($row['sale_price']) && is_numeric($row['sale_price']) && (float) $row['sale_price'] > 0
            ? (float) $row['sale_price'] : null;

        $desc = strip_tags((string) ($row['description'] ?? ''));
        $desc = trim(preg_replace('/\s+/u', ' ', $desc));

        return [
            'id'          => $id,
            'name'        => (string) ($row['name'] ?? ''),
            'name_bn'     => (string) ($row['name_bn'] ?? ''),
            'price'       => (float) ($row['price'] ?? 0),
            'sale_price'  => $sale,
            'in_stock'    => $inStock,
            'image'       => $image,
            'url'         => $url,
            'category'    => (string) ($row['category'] ?? ''),
            'description' => mb_substr($desc, 0, 160),
            '_text'       => mb_strtolower(mb_substr($desc, 0, 3000)),
            'shop'        => (string) ($row['shop'] ?? ''),
            'variants'    => $row['variants'] ?? null,
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Lower-cased search words, dropping filler words that would match everything. */
    public static function words(string $query): array
    {
        static $stop = ['the', 'a', 'an', 'and', 'or', 'for', 'with', 'of', 'in', 'ar', 'er', 'ki', 'koto', 'dam',
            'আর', 'এর', 'কি', 'কী', 'একটা', 'টা', 'দাম', 'কত', 'আছে', 'চাই', 'এই', 'ওই'];
        $parts = preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
        $parts = array_filter($parts, static fn($w) => mb_strlen($w) >= 2 && !in_array($w, $stop, true));
        return array_values(array_unique($parts));
    }

    /** Short English words must match a whole word ("fan" should not match "fantastic"). */
    private static function has(string $hay, string $word): bool
    {
        if (strlen($word) <= 3 && preg_match('/^[a-z0-9]+$/', $word)) {
            return (bool) preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($word, '/') . 's?($|[^\p{L}\p{N}])/u', $hay);
        }
        return str_contains($hay, $word);
    }

    private static function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException("Invalid column or table name: {$name}");
        }
        return '`' . $name . '`';
    }

    private static function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
