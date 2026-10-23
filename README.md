# NurBuy AI এজেন্ট (bot.nurbuy.com)

NurBuy-এর কাস্টমার সাপোর্ট ও বিক্রয় এজেন্ট। Facebook Messenger, WhatsApp আর nurbuy.com-এর লাইভ চ্যাটে কাস্টমারের লেখা, ছবি ও ভয়েস মেসেজ বুঝে উত্তর দেয়, প্রোডাক্ট খোঁজে, চ্যাটেই অর্ডার নেয় আর অর্ডার ট্র্যাক করে। OpenAI API ব্যবহার করে, সাধারণ PHP, cPanel-এ চলে।

## কী কী করে

- **প্রোডাক্ট:** api.nurbuy.com/catalog থেকে প্রোডাক্ট, দাম, স্টক আর ছবি নেয় (১০ মিনিট ক্যাশ)। ছবি পাঠালে মিল খুঁজে দেখায়, না থাকলে কাছাকাছি প্রোডাক্ট।
- **পলিসি:** About, Privacy, Return, Shipping, ডেলিভারি চার্জ আর যোগাযোগ সরাসরি ওয়েবসাইটের সেটিংস থেকে পড়ে।
- **ভয়েস:** OpenAI transcription দিয়ে লেখায় রূপান্তর করে উত্তর দেয়।
- **অর্ডার:** নাম, মোবাইল, জেলা, থানা নেয় (বিস্তারিত ঠিকানা ঐচ্ছিক), সারাংশ দেখায়, কাস্টমার "হ্যাঁ" বললে তবেই `POST /bot/orders` দিয়ে আসল অর্ডার (ক্যাশ অন ডেলিভারি)। কনফার্মেশনের নিয়ম কোডেই আটকানো, এক চ্যাট থেকে দিনে সর্বোচ্চ ৩টা অর্ডার।
- **ট্র্যাকিং:** অর্ডার নম্বর + মোবাইল মিললে স্ট্যাটাস, কুরিয়ার, ট্র্যাকিং আইডি আর আনুমানিক ডেলিভারির তারিখ।
- **মানুষের কাছে হস্তান্তর:** অভিযোগ, রিফান্ড বা কাস্টমার চাইলে চ্যাট টিমের কাছে যায়, বট চুপ থাকে।

## ফোল্ডার

```
bot.nurbuy.com/              ← এই রিপোর সব কিছু সরাসরি এখানে
├── index.php                ← স্ট্যাটাস পেজ (READY / NEEDS SETUP)
├── webhook.php              ← Meta webhook (Messenger + WhatsApp)
├── site-chat.php            ← nurbuy.com লাইভ চ্যাট (api.nurbuy.com থেকে আসে)
├── test.php                 ← পাসওয়ার্ড দেওয়া টেস্ট চ্যাট
├── .htaccess
└── app/                     ← বাইরে থেকে খোলা যায় না (.htaccess)
    ├── config.example.php   ← কপি করে config.php বানাও (রিপোতে config.php নেই)
    ├── instructions.md      ← বটের জন্য দোকান মালিকের নির্দেশনা
    ├── site_pages.txt       ← (ঐচ্ছিক) অতিরিক্ত ওয়েবসাইট পেজ
    ├── bootstrap.php
    ├── src/                 ← Agent, OpenAI, NurBuyApi, ProductSearch, Messenger, WhatsApp, WebChat, Store ...
    └── data/                ← কথোপকথন (SQLite), লগ, ক্যাশ (নিজে থেকে তৈরি হয়, রিপোতে নেই)
```

## সার্ভারে বসানো

1. cPanel-এ সাবডোমেইন `bot.nurbuy.com`, Document Root `bot.nurbuy.com`, AutoSSL চালু, PHP 8.1+।
2. এই রিপোর ফাইলগুলো `bot.nurbuy.com` ফোল্ডারে রাখো।
3. `app/config.example.php` কপি করে `app/config.php` বানাও, তারপর পূরণ করো:
   - `openai.api_key`
   - `meta.verify_token`, `meta.app_secret`, `messenger.page_access_token` (WhatsApp ব্যবহার করলে `whatsapp.*`)
   - `nurbuy` => `api_url` (`https://api.nurbuy.com`) আর `bot_key`
   - `test_page_password`, `handoff.notify_email`
4. `api.nurbuy.com/config.php`-এ একই `bot_key` আর `'bot_chat_url' => 'https://bot.nurbuy.com/site-chat.php'`।
5. `https://bot.nurbuy.com/` খুলে সব `[ok]` আর READY দেখো।
6. Meta App-এ Callback URL `https://bot.nurbuy.com/webhook.php`, পেজে `messages`, `messaging_postbacks`, `message_echoes` সাবস্ক্রাইব।
7. অ্যাডমিন > Live Messages > "AI উত্তর: চালু" দিলে ওয়েবসাইটের চ্যাটেও বট উত্তর দেয়।

## বটকে নির্দেশনা দেওয়া

`app/instructions.md`-এ লাইন যোগ করো (প্রতিটা `- ` দিয়ে)। Save করলেই কার্যকর। দাম বা পলিসি এখানে লিখবে না, ওগুলো ওয়েবসাইট থেকে আসে। নিরাপত্তার নিয়ম, কনফার্ম ছাড়া অর্ডার না করা আর মানুষ সেজে মিথ্যা না বলা নির্দেশনা দিয়ে বদলানো যায় না।

## মডেল ও খরচ

`config.php`-এ `openai.chat_model` (ডিফল্ট `gpt-6-luna`), `vision_model`, `transcribe_model`। ছবি `detail: low`, নির্দেশনা একই থাকে বলে prompt caching-এ খরচ কম। OpenAI ড্যাশবোর্ডে মাসিক খরচের সীমা দিয়ে রাখো।

## সমস্যা হলে

`app/data/logs/YYYY-MM-DD.log` দেখো। লগে key বা টোকেন লেখা হয় না।

- `bot paused`: হস্তান্তর বা পেজ থেকে নিজে উত্তর দেওয়ার পর Messenger/WhatsApp চ্যাটে বট ১২ ঘণ্টা চুপ (`handoff.pause_hours`)।
- `Rejected webhook: bad signature`: `meta.app_secret` ভুল।
- `OpenAI ... 401 / 429`: key ভুল / ক্রেডিট শেষ।
