<?php
declare(strict_types=1);

/**
 * Client for the NurBuy Marketplace API (api.nurbuy.com).
 * Public data (catalog, delivery settings, districts) is cached in app/data;
 * order and tracking calls use the secret bot key.
 */
final class NurBuyApi
{
    private const CATALOG_TTL = 600;      // 10 minutes
    private const GEO_TTL     = 43200;    // 12 hours

    /** District spellings people often use that differ from the official list. */
    private const DISTRICT_ALIASES = [
        'chittagong' => 'chattogram', 'ctg' => 'chattogram', 'comilla' => 'cumilla', 'barisal' => 'barishal',
        'jessore' => 'jashore', 'bogra' => 'bogura', 'chapai nawabganj' => 'chapainawabganj', 'nawabganj' => 'chapainawabganj',
        'netrakona' => 'netrokona', 'moulvibazar' => 'maulvibazar', 'jhalakati' => 'jhalokati', 'kishorgonj' => 'kishoreganj',
    ];

    public static function enabled(): bool
    {
        return trim((string) cfg('nurbuy.api_url', '')) !== '';
    }

    public static function ordersEnabled(): bool
    {
        return self::enabled() && strlen((string) cfg('nurbuy.bot_key', '')) >= 24;
    }

    private static function base(): string
    {
        return rtrim((string) cfg('nurbuy.api_url'), '/');
    }

    private static function siteUrl(): string
    {
        return rtrim((string) cfg('site_url', 'https://nurbuy.com'), '/');
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** Products and settings from GET /catalog (only the parts the bot needs). */
    public static function catalog(): array
    {
        return self::cached('catalog', self::CATALOG_TTL, static function (): array {
            $res = Http::get(self::base() . '/catalog', ['Accept: application/json'], 30);
            if ($res['status'] !== 200 || !is_array($res['json'])) {
                throw new RuntimeException('Catalog error ' . $res['status']);
            }
            $j = $res['json'];
            $s = $j['settings'] ?? [];
            return [
                'products' => array_values(array_filter($j['products'] ?? [], 'is_array')),
                'settings' => [
                    'name'   => $s['name'] ?? 'NurBuy',
                    'codFee' => (float) ($s['codFee'] ?? 0),
                    'minOrd' => isset($s['minOrd']) && is_numeric($s['minOrd']) ? (float) $s['minOrd'] : null,
                    'nw'     => $s['nw'] ?? [],
                    'pol'    => $s['pol'] ?? [],
                    'footer' => $s['footer'] ?? null,
                ],
            ];
        });
    }

    /** Catalog products as rows for ProductSearch::normalise(). */
    public static function productRows(): array
    {
        $rows = [];
        foreach (self::catalog()['products'] as $p) {
            $pr  = (float) ($p['pr'] ?? 0);
            $old = (float) ($p['old'] ?? 0);
            $variants = null;
            if (!empty($p['vr']['v']) && is_array($p['vr']['v'])) {
                $variants = [];
                foreach ($p['vr']['v'] as $v) {
                    if ((int) ($v['on'] ?? 1) !== 1) {
                        continue;
                    }
                    $variants[] = [
                        'id'       => (string) $v['id'],
                        'name'     => (string) $v['name'],
                        'price'    => (float) $v['price'],
                        'in_stock' => (int) ($v['stock'] ?? 0) > 0,
                    ];
                }
            }
            $rows[] = [
                'id'          => (string) ($p['id'] ?? ''),
                'name'        => trim((string) ($p['n'] ?? '')),
                'name_bn'     => trim((string) ($p['bn'] ?? '') . ' ' . (string) ($p['e'] ?? '')),
                'description' => trim(($p['about'] ?? '') . ' ' . ($p['tags'] ?? '') . ' ' . ($p['sub'] ?? '')),
                'price'       => $old > $pr ? $old : $pr,
                'sale_price'  => $old > $pr ? $pr : null,
                'image'       => (string) ($p['img'] ?? ''),
                'stock'       => (int) ($p['st'] ?? 0),
                'category'    => trim(($p['cat'] ?? '') . ' ' . ($p['catBn'] ?? '')),
                'url'         => self::productUrl($p),
                'shop'        => (string) ($p['sh'] ?? ''),
                'variants'    => $variants,
            ];
        }
        return $rows;
    }

    public static function product(string $id): ?array
    {
        foreach (self::catalog()['products'] as $p) {
            if (strcasecmp((string) ($p['id'] ?? ''), $id) === 0) {
                return $p;
            }
        }
        return null;
    }

    /** Same address format the website uses: /p/{english-name-slug}-{id}. */
    public static function productUrl(array $p): string
    {
        $name = (string) (($p['e'] ?? '') !== '' ? $p['e'] : ($p['n'] ?? ''));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        return self::siteUrl() . '/p/' . ($slug !== '' ? $slug : 'product') . '-' . strtolower((string) $p['id']);
    }

    // ------------------------------------------------------------------
    // Shop information for the prompt
    // ------------------------------------------------------------------

    /** Policies, delivery charges and contact details as plain text. */
    public static function shopInfoText(): string
    {
        $c   = self::catalog();
        $s   = $c['settings'];
        $nw  = $s['nw'];
        $cur = (string) cfg('products.currency', '৳');
        $out = [];

        $d = [];
        if (isset($nw['in'])) {
            $d[] = "Inside Dhaka district: {$cur}{$nw['in']}, delivery in " . ($nw['eta_in'] ?? '1-2') . ' days';
        }
        if (isset($nw['out'])) {
            $d[] = "Outside Dhaka (all other districts): {$cur}{$nw['out']}, delivery in " . ($nw['eta_out'] ?? '3-5') . ' days';
        }
        if (!empty($nw['free'])) {
            $d[] = "Free delivery for orders of {$cur}{$nw['free']} or more (product subtotal)";
        }
        if ($s['codFee'] > 0) {
            $d[] = "Cash on Delivery fee: {$cur}{$s['codFee']} per order";
        }
        if ($s['minOrd']) {
            $d[] = "Minimum order: {$cur}{$s['minOrd']}";
        }
        $d[] = 'Delivery all over Bangladesh by courier. One delivery charge per order, however many shops are in it.';
        $d[] = 'Payment: Cash on Delivery.';
        $out[] = "## Delivery and payment (live from the shop settings)\n- " . implode("\n- ", $d);

        $f = $s['footer'];
        if (is_array($f)) {
            $contact = [];
            foreach (['phone' => 'Phone', 'email' => 'Email'] as $k => $label) {
                if (!empty($f[$k]) && is_string($f[$k])) {
                    $contact[] = "{$label}: {$f[$k]}";
                }
            }
            foreach (['hours' => 'Hours', 'addr' => 'Address'] as $k => $label) {
                $v = ($f[$k]['bn'] ?? '') ?: ($f[$k]['en'] ?? '');
                if (is_string($v) && $v !== '') {
                    $contact[] = "{$label}: {$v}";
                }
            }
            if ($contact) {
                $out[] = "## Contact\n- " . implode("\n- ", $contact);
            }
        }

        $titles = ['about' => 'About us', 'privacy' => 'Privacy policy', 'return' => 'Return and refund policy', 'shipping' => 'Shipping policy'];
        foreach ($titles as $k => $title) {
            $p = $s['pol'][$k] ?? null;
            if (!is_array($p)) {
                continue;
            }
            $text = trim(implode("\n\n", array_filter([(string) ($p['bn'] ?? ''), (string) ($p['en'] ?? '')])));
            if ($text !== '') {
                $out[] = "## {$title}\n" . mb_substr($text, 0, 6000);
            }
        }

        return implode("\n\n", $out);
    }

    // ------------------------------------------------------------------
    // Districts
    // ------------------------------------------------------------------

    /** [[id, name, bn, fee, eta], ...] from GET /geo. */
    public static function districts(): array
    {
        return self::cached('geo', self::GEO_TTL, static function (): array {
            $res = Http::get(self::base() . '/geo', ['Accept: application/json'], 20);
            $list = $res['json']['min']['d'] ?? null;
            if ($res['status'] !== 200 || !is_array($list)) {
                throw new RuntimeException('District list error ' . $res['status']);
            }
            return $list;
        });
    }

    /** Matches a district written in English or Bangla. */
    public static function findDistrict(string $text): ?array
    {
        $want = mb_strtolower(trim(preg_replace('/\s*(district|jela|zila|জেলা)\s*$/iu', '', trim($text))));
        $want = self::DISTRICT_ALIASES[$want] ?? $want;
        if ($want === '') {
            return null;
        }
        foreach (self::districts() as $d) {
            $en = mb_strtolower((string) $d[1]);
            if ($want === $en || $want === (string) $d[2] || str_replace(' ', '', $want) === str_replace(' ', '', $en)) {
                return ['id' => (int) $d[0], 'name' => (string) $d[1], 'bn' => (string) $d[2]];
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Orders
    // ------------------------------------------------------------------

    /**
     * Builds and checks an order without placing it.
     * @param list<array{product_id:string, variant_id:?string, qty:int}> $items
     * @return array{ok:bool, error?:string, quote?:array}
     */
    public static function quote(array $items, string $name, string $mobile, string $district, string $thana, string $postOffice = '', string $details = ''): array
    {
        $mobile = self::normaliseMobile($mobile);
        if (!preg_match('/^01[0-9]{9}$/', $mobile)) {
            return ['ok' => false, 'error' => 'invalid_mobile: ask for an 11-digit Bangladeshi mobile number starting with 01'];
        }
        if (mb_strlen(trim($name)) < 2) {
            return ['ok' => false, 'error' => 'missing_name'];
        }
        $thana = trim($thana);
        if (mb_strlen($thana) < 2) {
            return ['ok' => false, 'error' => 'missing_thana: ask which thana/upazila the address is in'];
        }
        // Detailed address (village, house, road) is optional; thana and post office are enough to deliver.
        $parts = array_filter([trim($details), trim($postOffice) !== '' ? 'Post office: ' . trim($postOffice) : '', 'Thana: ' . $thana],
            static fn($x) => $x !== '');
        $address = implode(', ', $parts);
        $dist = self::findDistrict($district);
        if (!$dist) {
            return ['ok' => false, 'error' => 'unknown_district: ask the customer which of the 64 districts the address is in'];
        }
        if (!$items || count($items) > 10) {
            return ['ok' => false, 'error' => 'items_required'];
        }

        $lines = [];
        $sub   = 0.0;
        foreach ($items as $it) {
            $p = self::product((string) ($it['product_id'] ?? ''));
            if (!$p) {
                return ['ok' => false, 'error' => 'product_not_found: ' . ($it['product_id'] ?? '') . ' (search again and use an id from the results)'];
            }
            $qty = max(1, min(20, (int) ($it['qty'] ?? 1)));
            $vid = (string) ($it['variant_id'] ?? '');
            $unit = (float) ($p['pr'] ?? 0);
            $variantName = null;
            $variants = $p['vr']['v'] ?? [];
            if ($variants) {
                $hit = null;
                foreach ($variants as $v) {
                    if ((string) $v['id'] === $vid && (int) ($v['on'] ?? 1) === 1) {
                        $hit = $v;
                    }
                }
                if (!$hit) {
                    $names = implode(', ', array_map(static fn($v) => $v['name'] . ' (variant_id ' . $v['id'] . ')', $variants));
                    return ['ok' => false, 'error' => 'choose_variant for ' . $p['n'] . ': ' . $names];
                }
                if ((int) ($hit['stock'] ?? 0) < $qty) {
                    return ['ok' => false, 'error' => 'out_of_stock: ' . $p['n'] . ' ' . $hit['name']];
                }
                $unit = (float) $hit['price'];
                $variantName = (string) $hit['name'];
            } elseif ((int) ($p['st'] ?? 0) < $qty && (int) ($p['so'] ?? 1) === 1 && str_starts_with((string) $p['id'], 'SP')) {
                return ['ok' => false, 'error' => 'out_of_stock: ' . $p['n'] . ' (only ' . (int) ($p['st'] ?? 0) . ' left)'];
            }
            $line = ['id' => (string) $p['id'], 'n' => (string) $p['n'], 'unit' => $unit, 'q' => $qty];
            if (!empty($p['sh'])) {
                $line['shop'] = (string) $p['sh'];
            }
            if ($variantName !== null) {
                $line['vid'] = $vid;
                $line['variant'] = $variantName;
            }
            $lines[] = $line;
            $sub += $unit * $qty;
        }

        $s   = self::catalog()['settings'];
        $nw  = $s['nw'];
        $inDhaka = $dist['id'] === 47;
        $fee = (!empty($nw['free']) && $sub >= (float) $nw['free']) ? 0.0 : (float) ($inDhaka ? ($nw['in'] ?? 70) : ($nw['out'] ?? 130));
        if ($s['minOrd'] && $sub < $s['minOrd']) {
            return ['ok' => false, 'error' => 'below_minimum_order: minimum is ' . $s['minOrd']];
        }

        return ['ok' => true, 'quote' => [
            'items'    => $lines,
            'name'     => trim($name),
            'mobile'   => $mobile,
            'district' => $dist,
            'address'  => trim($address),
            'sub'      => round($sub, 2),
            'fee'      => $fee,
            'cod'      => (float) $s['codFee'],
            'total'    => round($sub + $fee + (float) $s['codFee'], 2),
            'eta_days' => $inDhaka ? ($nw['eta_in'] ?? '1-2') : ($nw['eta_out'] ?? '3-5'),
        ]];
    }

    /** Places a quoted order through POST /bot/orders. */
    public static function placeOrder(array $quote, string $channel): array
    {
        $payload = [
            'district' => $quote['district']['id'],
            'name'     => $quote['name'],
            'mobile'   => $quote['mobile'],
            'address'  => $quote['address'],
            'items'    => $quote['items'],
        ];
        $res = Http::postJson(self::base() . '/bot/orders', $payload, self::botHeaders($channel), 30);
        if ($res['status'] === 201 && isset($res['json']['order']['id'])) {
            $o = $res['json']['order'];
            return ['ok' => true, 'order' => [
                'order_id' => $o['id'], 'status' => $o['status'], 'sub' => $o['sub'], 'fee' => $o['fee'],
                'cod' => $o['cod'], 'total' => $o['total'], 'eta_days' => $o['extra']['eta_days'] ?? $quote['eta_days'],
            ]];
        }
        $msg = (string) ($res['json']['error'] ?? ('HTTP ' . $res['status']));
        Log::error('bot order failed', ['status' => $res['status'], 'error' => $msg]);
        return ['ok' => false, 'error' => $msg, 'code' => $res['json']['code'] ?? null];
    }

    public static function trackOrder(string $orderId, string $mobile): array
    {
        $orderId = strtoupper(trim($orderId));
        if (!preg_match('/^[A-Z0-9-]{3,40}$/', $orderId)) {
            return ['ok' => false, 'error' => 'invalid_order_id'];
        }
        $url = self::base() . '/bot/orders/' . rawurlencode($orderId) . '?mobile=' . rawurlencode(self::normaliseMobile($mobile));
        $res = Http::get($url, self::botHeaders('chat'), 20);
        if ($res['status'] === 200 && isset($res['json']['order'])) {
            $o = $res['json']['order'];
            // Dates in plain form so the reply can say how long is left
            $created = (int) ($o['created_at'] ?? 0);
            $o['ordered_on'] = $created ? date('Y-m-d', $created) : null;
            $o['today'] = date('Y-m-d');
            $o['days_since_order'] = $created ? (int) floor((time() - $created) / 86400) : null;
            if ($created && preg_match('/^(\d+)(?:-(\d+))?$/', (string) ($o['eta_days'] ?? ''), $m)) {
                $o['expected_delivery'] = date('Y-m-d', $created + (int) $m[1] * 86400)
                    . (isset($m[2]) && $m[2] !== '' ? ' to ' . date('Y-m-d', $created + (int) $m[2] * 86400) : '');
            }
            unset($o['created_at'], $o['updated_at']);
            $o['last_update'] = isset($res['json']['order']['updated_at']) ? date('Y-m-d H:i', (int) $res['json']['order']['updated_at']) : null;
            return ['ok' => true, 'order' => $o];
        }
        if ($res['status'] === 404) {
            return ['ok' => false, 'error' => 'not_found: no order with this number and mobile'];
        }
        return ['ok' => false, 'error' => (string) ($res['json']['error'] ?? ('HTTP ' . $res['status']))];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function botHeaders(string $channel): array
    {
        return ['X-Bot-Key: ' . cfg('nurbuy.bot_key'), 'X-Bot-Channel: ' . $channel, 'Accept: application/json'];
    }

    public static function normaliseMobile(string $m): string
    {
        $m = strtr($m, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $m = (string) preg_replace('/[\s\-\(\)\.]+/', '', $m);
        if (str_starts_with($m, '+88')) {
            $m = substr($m, 3);
        } elseif (str_starts_with($m, '88') && strlen($m) === 13) {
            $m = substr($m, 2);
        }
        return $m;
    }

    /** File cache in app/data; on a failed refresh the last good copy is used. */
    private static function cached(string $name, int $ttl, callable $load): array
    {
        static $memory = [];
        if (isset($memory[$name])) {
            return $memory[$name];
        }
        $file = DATA_DIR . '/nurbuy_' . $name . '.json';
        $old  = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_array($old) && isset($old['at']) && time() - (int) $old['at'] < $ttl) {
            return $memory[$name] = $old['data'];
        }
        try {
            $data = $load();
            file_put_contents($file, json_encode(['at' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            return $memory[$name] = $data;
        } catch (Throwable $e) {
            Log::error("NurBuy API {$name}: " . $e->getMessage());
            if (is_array($old) && isset($old['data'])) {
                return $memory[$name] = $old['data'];
            }
            throw $e;
        }
    }
}
