<?php
/**
 * Copy this file to config.php and fill in your own values.
 * config.php holds secrets: never commit it to GitHub or share it.
 */

return [

    // ---------------------------------------------------------------
    // General
    // ---------------------------------------------------------------
    'shop_name'      => 'NurBuy',
    'site_url'       => 'https://nurbuy.com',
    'timezone'       => 'Asia/Dhaka',
    'debug_log'      => true,            // writes app/data/logs/*.log

    // ---------------------------------------------------------------
    // OpenAI
    // ---------------------------------------------------------------
    'openai' => [
        'api_key'          => 'sk-PASTE-YOUR-KEY-HERE',
        'base_url'         => 'https://api.openai.com/v1',
        'chat_model'       => 'gpt-6-luna',        // cheapest model for replies
        'vision_model'     => 'gpt-6-luna',        // used when the customer sends a photo
        'transcribe_model' => 'gpt-4o-mini-transcribe', // voice -> text (or 'gpt-transcribe')
        'image_detail'     => 'low',               // 'low' keeps image cost very small
        'reasoning_effort' => null,                // e.g. 'low' if the model supports it, else null
        'max_output_tokens'=> 600,
        'timeout'          => 45,
    ],

    // ---------------------------------------------------------------
    // Meta (Facebook Messenger + WhatsApp Cloud API)
    // ---------------------------------------------------------------
    'meta' => [
        'graph_version' => 'v23.0',
        'verify_token'  => 'CHANGE-ME-any-random-text', // you invent this; paste the same text in the Meta dashboard
        'app_secret'    => '',                          // Meta App > Settings > Basic > App Secret (verifies requests)
    ],

    'messenger' => [
        'enabled'           => true,
        'page_access_token' => '',
    ],

    'whatsapp' => [
        'enabled'         => false,
        'access_token'    => '',   // permanent system-user token
        'phone_number_id' => '',
    ],

    // ---------------------------------------------------------------
    // NurBuy Marketplace API (products, policies, orders, tracking)
    //   api_url : the marketplace API; when set, products and shop policies come from it
    //   bot_key : the same secret as 'bot_key' in api.nurbuy.com/config.php; enables
    //             taking orders and tracking them in the chat (leave '' to keep them off)
    // ---------------------------------------------------------------
    'nurbuy' => [
        'api_url' => 'https://api.nurbuy.com',
        'bot_key' => '',
    ],

    // ---------------------------------------------------------------
    // Product search: used only when 'nurbuy.api_url' above is empty
    //   mode 'mysql' : reads your website database directly (read-only user recommended)
    //   mode 'api'   : calls an HTTP endpoint that returns JSON products
    // ---------------------------------------------------------------
    'products' => [
        'mode' => 'mysql',

        'mysql' => [
            'host'     => 'localhost',
            'database' => '',
            'username' => '',
            'password' => '',
            'charset'  => 'utf8mb4',

            // Map your table and column names. Leave a column as null if it does not exist.
            'table'            => 'products',
            'col_id'           => 'id',
            'col_name'         => 'name',
            'col_name_bn'      => null,
            'col_description'  => 'description',
            'col_price'        => 'price',
            'col_sale_price'   => null,
            'col_image'        => 'image',
            'col_stock'        => 'stock',
            'col_category'     => null,       // a text column; for a category table use 'category_join' below
            'col_slug'         => null,

            // Optional JOIN to show a category name, e.g.
            // ['table' => 'categories', 'local_key' => 'category_id', 'foreign_key' => 'id', 'name_col' => 'name']
            'category_join'    => null,

            // Extra SQL condition that marks a product as visible, e.g. "status = 1"
            'where_active'     => null,
        ],

        'api' => [
            // {q} is replaced with the URL-encoded search text.
            // The endpoint must return a JSON array of products (or {"data": [...]})
            // with fields: id, name, price, image, url (optional: sale_price, stock, category, description)
            'search_url' => 'https://api.example.com/products/search?q={q}&limit=8',
            'headers'    => [],
        ],

        // Customer-facing product link. Placeholders: {id} {slug}
        'url_pattern'    => 'https://nurbuy.com/product/{id}',
        // Prepended when the image column holds a relative path
        'image_base_url' => 'https://nurbuy.com/uploads/',
        'currency'       => '৳',
        'max_results'    => 5,
    ],

    // ---------------------------------------------------------------
    // Human handoff
    // ---------------------------------------------------------------
    'handoff' => [
        'pause_hours'        => 12,   // bot stays silent in that chat after handing off
        'pause_on_admin_reply'=> true, // pause automatically when you reply manually from the Page inbox
        'notify_email'       => '',   // gets an email on every handoff (leave empty to skip)
    ],

    // ---------------------------------------------------------------
    // Test chat page (public/test.php) to try the bot without Meta
    // ---------------------------------------------------------------
    'test_page_password' => 'CHANGE-ME-strong-password',

    // How many previous messages the bot remembers per customer
    'history_messages' => 12,
];
