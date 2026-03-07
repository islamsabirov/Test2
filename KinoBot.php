<?php
/**
 * ============================================================
 *  🎬 KinoBot — Universal Telegram Kino Bot
 *  Barcha PHP hostinglarga mos (shared, VPS, Timeweb, Beget)
 *  Webhook + Tas-ix muvofiq
 * ============================================================
 */

ob_start();
error_reporting(0);
@ini_set('display_errors', 0);
@set_time_limit(120);
date_default_timezone_set('Asia/Tashkent');

// ============================================================
//  ⚙️  ASOSIY SOZLAMALAR — faqat shu joyni o'zgartiring
// ============================================================
define('BOT_TOKEN',  "TOKEN");
define('OWNER_ID',   "5907118746");
define('BASE_DIR',   __DIR__);          // barcha fayllar shu joyda
// ============================================================
//  📁  Papkalar
// ============================================================
$dirs = ['step','kino','tizim','users','admin'];
foreach ($dirs as $d) {
    $path = BASE_DIR . "/$d";
    if (!is_dir($path)) mkdir($path, 0755, true);
}

// ============================================================
//  🔧  Yordamchi funksiyalar
// ============================================================

/** Telegram API chaqiruvi */
function bot(string $method, array $data = []): ?object
{
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/$method";

    // cURL mavjudligini tekshirish
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
    } else {
        // cURL bo'lmasa file_get_contents bilan
        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($data),
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ];
        $res = @file_get_contents($url, false, stream_context_create($opts));
    }
    return $res ? json_decode($res) : null;
}

/** Fayl o'qish (default qiymat bilan) */
function fread_safe(string $path, string $default = ''): string
{
    return file_exists($path) ? trim(file_get_contents($path)) : $default;
}

/** Fayl yozish */
function fwrite_safe(string $path, string $data, bool $append = false): void
{
    file_put_contents($path, $data, $append ? FILE_APPEND | LOCK_EX : LOCK_EX);
}

/** Step saqlash va o'chirish */
function setStep(string $cid, string $step = ''): void
{
    $f = BASE_DIR . "/step/$cid.step";
    $step === '' ? (@unlink($f)) : fwrite_safe($f, $step);
}
function getStep(string $cid): string
{
    return fread_safe(BASE_DIR . "/step/$cid.step");
}

/** Bot admin ekanini tekshirish */
function isAdmin(string $username): bool
{
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getChatAdministrators?chat_id=@$username";
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $r = curl_exec($ch); curl_close($ch);
    } else {
        $r = @file_get_contents($url);
    }
    $d = $r ? json_decode($r) : null;
    return isset($d->ok) && $d->ok;
}

/** Foydalanuvchini bloklash */
function addBlock(string $id): void
{
    $f = BASE_DIR . "/tizim/blocked.txt";
    $list = array_filter(explode("\n", fread_safe($f)));
    if (!in_array($id, $list)) {
        fwrite_safe($f, "\n$id", true);
    }
}

/** Foydalanuvchini bazaga qo'shish */
function addUser(string $id, string $name, string $username, string $chatId): void
{
    $f    = BASE_DIR . "/users/$id.txt";
    $bazaf = BASE_DIR . "/azo.dat";
    $baza = fread_safe($bazaf);

    if (!file_exists($f)) {
        fwrite_safe($f, date("d.m.Y"));
    }
    if (mb_stripos($baza, $id) === false) {
        fwrite_safe($bazaf, "\n$id", true);
        // Owner ga xabar
        bot('sendMessage', [
            'chat_id'      => OWNER_ID,
            'text'         => "<b>👤 Yangi foydalanuvchi!\n\n👤 Ism: $name\n🆔 ID: <code>$id</code>\n🔗 Username: $username\n🕒 " . date("d.m.Y | H:i") . "</b>",
            'parse_mode'   => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "👀 Ko'rish", 'url' => "tg://user?id=$id"]]]]),
        ]);
    }
}

/** Kanal obunasini tekshirish */
function checkSubscription(string $uid): bool
{
    global $bot_username;
    $buttons = [];

    // Ommaviy kanallar
    $ch1 = fread_safe(BASE_DIR . "/channel.txt");
    if ($ch1) {
        foreach (array_filter(explode("\n", $ch1)) as $line) {
            $username = ltrim(trim(explode("@", $line)[1] ?? $line), '@');
            if (!$username) continue;
            $info   = bot('getChat', ['chat_id' => "@$username"]);
            $title  = $info->result->title ?? $username;
            $member = bot('getChatMember', ['chat_id' => "@$username", 'user_id' => $uid]);
            $status = $member->result->status ?? 'left';
            if (!in_array($status, ['creator', 'administrator', 'member'])) {
                $buttons[] = [['text' => "❌ $title", 'url' => "https://t.me/$username"]];
            }
        }
    }

    // Maxfiy kanallar (link\n-100xxx format)
    $ch2  = fread_safe(BASE_DIR . "/channel2.txt");
    $rows = array_values(array_filter(explode("\n", $ch2)));
    for ($i = 0; $i < count($rows) - 1; $i += 2) {
        $link    = trim($rows[$i]);
        $cid_str = trim($rows[$i + 1]);
        $fayl    = BASE_DIR . "/tizim/$cid_str.txt";
        $members = file_exists($fayl) ? array_filter(explode("\n", fread_safe($fayl))) : [];
        if (!in_array($uid, $members)) {
            $buttons[] = [['text' => "❌ Maxfiy kanal", 'url' => $link]];
        }
    }

    if (!empty($buttons)) {
        $buttons[] = [['text' => "🔄 Tekshirish", 'callback_data' => "checksuv"]];
        bot('sendMessage', [
            'chat_id'      => $uid,
            'text'         => "<b>⚠️ Botdan foydalanish uchun quyidagi kanallarga obuna bo'ling!</b>",
            'parse_mode'   => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
        ]);
        return false;
    }
    return true;
}

/** Adminlar ro'yxatini olish */
function getAdmins(): array
{
    $list = array_filter(explode("\n", fread_safe(BASE_DIR . "/tizim/admins.txt")));
    $list[] = OWNER_ID;
    return array_unique($list);
}

// ============================================================
//  📥  Kiruvchi so'rovni o'qish
// ============================================================
$input = file_get_contents('php://input');
if (!$input) { http_response_code(200); exit(); }
$update = json_decode($input);
if (!$update) { http_response_code(200); exit(); }

// Bot username
$bot_info     = bot('getMe');
$bot_username = $bot_info->result->username ?? 'KinoBot';

// Adminlar ro'yxati
$admins = getAdmins();

// ============================================================
//  📋  Klaviaturalar
// ============================================================
$kb_panel = json_encode([
    'resize_keyboard' => true,
    'keyboard' => [
        [['text' => "📢 Kanallar"], ['text' => "📥 Kino Yuklash"]],
        [['text' => "✉ Xabarnoma"],  ['text' => "📊 Statistika"]],
        [['text' => "🤖 Bot holati"], ['text' => "👥 Adminlar"]],
        [['text' => "◀️ Orqaga"]],
    ],
]);
$kb_back  = json_encode(['resize_keyboard' => true, 'keyboard' => [[['text' => "◀️ Orqaga"]]]]);
$kb_main  = json_encode(['resize_keyboard' => true, 'keyboard' => [[['text' => "🗄 Boshqaruv paneli"]]]]);
$kb_reply = json_encode(['resize_keyboard' => false, 'force_reply' => true, 'selective' => true]);

// ============================================================
//  🔔  Chat join request (maxfiy kanal)
// ============================================================
if (isset($update->chat_join_request)) {
    $req     = $update->chat_join_request;
    $jcid    = (string)$req->chat->id;
    $juid    = (string)$req->from->id;
    $fayl    = BASE_DIR . "/tizim/$jcid.txt";
    $members = file_exists($fayl) ? array_filter(explode("\n", fread_safe($fayl))) : [];
    if (!in_array($juid, $members)) {
        $members[] = $juid;
        fwrite_safe($fayl, implode("\n", array_filter($members)));
        bot('sendMessage', [
            'chat_id'    => $juid,
            'text'       => "<b>✅ Obunangiz qabul qilindi!\n\n/start - bosing va kino kodini yuboring!</b>",
            'parse_mode' => 'html',
        ]);
    }
    http_response_code(200); exit();
}

// ============================================================
//  🚷  Botdan chiqish (kick)
// ============================================================
if (isset($update->my_chat_member)) {
    $mcm    = $update->my_chat_member;
    $status = $mcm->new_chat_member->status ?? '';
    if ($status === 'kicked') {
        addBlock((string)$mcm->from->id);
    }
    http_response_code(200); exit();
}

// ============================================================
//  📨  Callback query
// ============================================================
$callback = $update->callback_query ?? null;
$cdata = $cmid = $cfid = $cfname = $cfsurname = $cfuser = $cmcid = null;
if ($callback) {
    $cdata     = $callback->data;
    $cmid      = $callback->id;
    $cfid      = (string)$callback->from->id;
    $cfname    = $callback->from->first_name ?? '';
    $cfsurname = $callback->from->last_name ?? '';
    $cfuser    = $callback->from->username ?? '';
    $cmcid     = (string)$callback->message->chat->id;
    $cmmid     = $callback->message->message_id;
    $cname_link = "<a href='tg://user?id=$cfid'>$cfname $cfsurname</a>";
    addUser($cfid, $cfname, $cfuser, $cmcid);
}

// ============================================================
//  💬  Message
// ============================================================
$message = $update->message ?? null;
$cid = $uid = $text = $mid = $username = $name = $familya = $name_link = null;
$photo = $video = $caption = null;
$step = '';
if ($message) {
    $cid      = (string)$message->chat->id;
    $uid      = (string)$message->from->id;
    $name     = $message->chat->first_name ?? '';
    $familya  = $message->from->last_name ?? '';
    $username = $message->from->username ?? '';
    $text     = $message->text ?? '';
    $mid      = $message->message_id;
    $caption  = $message->caption ?? '';
    $photo    = $message->photo ?? null;
    $video    = $message->video ?? null;
    $step     = getStep($cid);
    $name_link = "<a href='tg://user?id=$uid'>$name $familya</a>";
    addUser($uid, $name, $username, $cid);
}

// ============================================================
//  🔌  Bot holati
// ============================================================
$holat_f = BASE_DIR . "/holat.txt";
if (!file_exists($holat_f)) fwrite_safe($holat_f, "Yoqilgan");
$holat = fread_safe($holat_f, "Yoqilgan");

// Agar bot o'chirilgan bo'lsa
if ($message && $holat !== "Yoqilgan" && !in_array($cid, $admins)) {
    bot('sendMessage', [
        'chat_id'    => $cid,
        'text'       => "⛔️ <b>Bot vaqtinchalik o'chirilgan!</b>\n\n<i>Ta'mirlash ishlari olib borilmoqda...</i>",
        'parse_mode' => 'html',
    ]);
    http_response_code(200); exit();
}
if ($callback && $holat !== "Yoqilgan" && !in_array($cmcid, $admins)) {
    bot('answerCallbackQuery', [
        'callback_query_id' => $cmid,
        'text'              => "⛔️ Bot vaqtinchalik o'chirilgan!",
        'show_alert'        => true,
    ]);
    http_response_code(200); exit();
}

// ============================================================
//  🎬  Kino kanali
// ============================================================
$kino_ch = fread_safe(BASE_DIR . "/kino_ch.txt");

// ============================================================
//  🔁  CALLBACK HANDLER
// ============================================================
if ($callback && $cdata) {

    $is_admin_cb = in_array($cmcid, $admins);

    // --- Obunani tekshirish ---
    if ($cdata === 'checksuv') {
        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        if (checkSubscription($cfid)) {
            bot('sendMessage', [
                'chat_id'    => $cfid,
                'text'       => "✅ <b>Obunangiz tasdiqlandi!</b>\n\n🔎 Kino kodini yuboring:",
                'parse_mode' => 'html',
            ]);
        }
        exit();
    }

    // --- Boshqaruv paneli ---
    if ($cdata === 'boshqar' || $cdata === 'bosh') {
        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        bot('sendMessage', [
            'chat_id'      => $cmcid,
            'text'         => "<b>🖥️ Admin paneliga xush kelibsiz!</b>",
            'parse_mode'   => 'html',
            'reply_markup' => $kb_panel,
        ]);
        exit();
    }

    // === ADMIN CALLBACK'LAR ===

    // Adminlar
    if ($cdata === 'admins' && $is_admin_cb) {
        $kb = ($cmcid === OWNER_ID)
            ? ['inline_keyboard' => [
                [['text' => "➕ Admin qo'shish", 'callback_data' => "add"]],
                [['text' => "📑 Ro'yxat", 'callback_data' => "list"], ['text' => "🗑 O'chirish", 'callback_data' => "remove"]],
              ]]
            : ['inline_keyboard' => [[['text' => "📑 Ro'yxat", 'callback_data' => "list"]]]];
        bot('editMessageText', [
            'chat_id'      => $cmcid, 'message_id' => $cmmid,
            'text'         => "👮 <b>Adminlar boshqaruvi:</b>",
            'parse_mode'   => 'html',
            'reply_markup' => json_encode($kb),
        ]);
        exit();
    }

    if ($cdata === 'add' && $cmcid === OWNER_ID) {
        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        bot('sendMessage', [
            'chat_id'      => $cmcid,
            'text'         => "<b>📝 Yangi admin ID sini yuboring:</b>",
            'parse_mode'   => 'html',
            'reply_markup' => $kb_main,
        ]);
        setStep($cmcid, 'add-admin');
        exit();
    }

    if ($cdata === 'remove' && $cmcid === OWNER_ID) {
        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        bot('sendMessage', [
            'chat_id'      => $cmcid,
            'text'         => "<b>📝 O'chiriladigan admin ID sini yuboring:</b>",
            'parse_mode'   => 'html',
            'reply_markup' => $kb_main,
        ]);
        setStep($cmcid, 'remove-admin');
        exit();
    }

    if ($cdata === 'list' && $is_admin_cb) {
        $al   = fread_safe(BASE_DIR . "/tizim/admins.txt");
        $text2 = empty($al) ? "🚫 <b>Qo'shimcha admin yo'q!</b>" : "👮‍♂️ <b>Adminlar:</b>\n$al";
        bot('editMessageText', [
            'chat_id' => $cmcid, 'message_id' => $cmmid,
            'text'    => $text2, 'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 Orqaga", 'callback_data' => "admins"]]]]),
        ]);
        exit();
    }

    // Kanallar
    if ($cdata === 'majburiy' && $is_admin_cb) {
        bot('editMessageText', [
            'chat_id'      => $cmcid, 'message_id' => $cmmid,
            'text'         => "<b>📢 Majburiy obuna kanallarini boshqarish:</b>",
            'parse_mode'   => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => "🌐 Ommaviy kanallar",  'callback_data' => "ommav"]],
                [['text' => "🔒 Maxfiy kanallar",   'callback_data' => "maxfiy"]],
                [['text' => "◀️ Orqaga",             'callback_data' => "bosh"]],
            ]]),
        ]);
        exit();
    }

    if ($cdata === 'kanallar' && $is_admin_cb) {
        $kb2 = ['inline_keyboard' => [
            [['text' => "📢 Majburiy obuna", 'callback_data' => "majburiy"]],
            [['text' => "🎬 Kino kanali",    'callback_data' => "qoshimcha"]],
            [['text' => "◀️ Orqaga",          'callback_data' => "bosh"]],
        ]];
        bot('editMessageText', [
            'chat_id' => $cmcid, 'message_id' => $cmmid,
            'text'    => "<b>📢 Kanallar bo'limi:</b>", 'parse_mode' => 'html',
            'reply_markup' => json_encode($kb2),
        ]);
        exit();
    }

    if ($cdata === 'ommav' && $is_admin_cb) {
        bot('editMessageText', [
            'chat_id' => $cmcid, 'message_id' => $cmmid,
            'text'    => "<b>🌐 Ommaviy kanallar:</b>", 'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => "➕ Qo'shish", 'callback_data' => "qoshish"]],
                [['text' => "📑 Ro'yxat",  'callback_data' => "royxati"], ['text' => "🗑 O'chirish", 'callback_data' => "ochirish"]],
                [['text' => "◀️ Orqaga",   'callback_data' => "majburiy"]],
            ]]),
        ]);
        exit();
    }

    if ($cdata === 'maxfiy' && $is_admin_cb) {
        bot('editMessageText', [
            'chat_id' => $cmcid, 'message_id' => $cmmid,
            'text'    => "<b>🔒 Maxfiy kanallar:</b>", 'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => "➕ Qo'shish", 'callback_data' => "qosh"]],
                [['text' => "📑 Ro'yxat",  'callback_data' => "roy"], ['text' => "🗑 O'chirish", 'callback_data' => "ochir"]],
                [['text' => "◀️ Orqaga",   'callback_data' => "majburiy"]],
            ]]),
        ]);
        exit();
    }

    if ($cdata === 'royxati' && $is_admin_cb) {
        $ch1 = fread_safe(BASE_DIR . "/channel.txt");
        $t   = $ch1 ? "<b>🌐 Ommaviy kanallar:</b>\n$ch1" : "<b>🌐 Ommaviy kanallar ulanmagan!</b>";
        bot('editMessageText', [
            'chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => $t, 'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 Orqaga", 'callback_data' => "ommav"]]]]),
        ]);
        exit();
    }

    if ($cdata === 'roy' && $is_admin_cb) {
        $ch2  = fread_safe(BASE_DIR . "/channel2.txt");
        $rows = array_values(array_filter(explode("\n", $ch2)));
        $t    = "<b>🔒 Maxfiy kanallar:</b>\n";
        for ($i = 0; $i < count($rows) - 1; $i += 2) {
            $t .= "🔹 <code>" . trim($rows[$i]) . "</code>\n";
        }
        if (count($rows) < 2) $t = "<b>🔒 Maxfiy kanallar ulanmagan!</b>";
        bot('editMessageText', [
            'chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => $t, 'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 Orqaga", 'callback_data' => "maxfiy"]]]]),
        ]);
        exit();
    }

    if ($cdata === 'qoshish' && $is_admin_cb) {
        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        bot('sendMessage', [
            'chat_id'    => $cmcid,
            'text'       => "<b>📢 Ommaviy kanal username ini yuboring:\n\n📄 Namuna:</b> <code>@KanalNomi</code>",
            'parse_mode' => 'html',
            'reply_markup' => $kb_main,
        ]);
        setStep($cmcid, 'add-pub-channel');
        exit();
    }

    if ($cdata === 'ochirish' && $is_admin_cb) {
        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        bot('sendMessage', [
            'chat_id'    => $cmcid,
            'text'       => "<b>🗑 O'chiriladigan kanal username ini yuboring:\n\n📄 Namuna:</b> <code>@KanalNomi</code>",
            'parse_mode' => 'html',
            'reply_markup' => $kb_main,
        ]);
        setStep($cmcid, 'remove-pub-channel');
        exit();
    }

    if ($cdata === 'qosh' && $is_admin_cb) {
        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        bot('sendMessage', [
            'chat_id'    => $cmcid,
            'text'       => "<i>⚠️ Botni kanalingizga admin qilib, so'ngra quyidagi formatda yuboring:</i>\n\n📄 <b>Namuna:</b>\n<code>https://t.me/+ZEcQiRY_pRph\n-100326189432</code>",
            'parse_mode' => 'html',
            'reply_markup' => $kb_main,
        ]);
        setStep($cmcid, 'add-chanel');
        exit();
    }

    if ($cdata === 'ochir' && $is_admin_cb) {
        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        bot('sendMessage', [
            'chat_id'    => $cmcid,
            'text'       => "<b>🗑 O'chiriladigan maxfiy kanal ma'lumotlarini yuboring:</b>\n\n📄 <b>Namuna:</b>\n<code>https://t.me/+ZEcQiRY_pRph\n-100326189432</code>",
            'parse_mode' => 'html',
            'reply_markup' => $kb_main,
        ]);
        setStep($cmcid, 'remove-secret-channel');
        exit();
    }

    if ($cdata === 'qoshimcha' && $is_admin_cb) {
        bot('editMessageText', [
            'chat_id' => $cmcid, 'message_id' => $cmmid,
            'text'    => "<b>🎬 Hozirgi kino kanali:</b> " . ($kino_ch ?: "Qo'shilmagan"),
            'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => "📝 O'zgartirish", 'callback_data' => "kinokanal"]],
                [['text' => "◀️ Orqaga",         'callback_data' => "kanallar"]],
            ]]),
        ]);
        exit();
    }

    if ($cdata === 'kinokanal' && $is_admin_cb) {
        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        bot('sendMessage', [
            'chat_id'    => $cmcid,
            'text'       => "<blockquote>⚠️ Botni kanalingizga admin qiling, so'ngra username ini yuboring!</blockquote>\n\n📄 <b>Namuna:</b> <code>@KanalNomi</code>",
            'parse_mode' => 'html',
            'reply_markup' => $kb_main,
        ]);
        setStep($cmcid, 'add-channl');
        exit();
    }

    // Statistika callbacklar
    if (in_array($cdata, ['stat', 'kunlik', 'haftalik', 'oylik']) && $is_admin_cb) {
        $statt = fread_safe(BASE_DIR . "/azo.dat");
        $total = substr_count($statt, "\n");
        $users_dir = BASE_DIR . "/users";

        if ($cdata === 'stat') {
            bot('editMessageText', [
                'chat_id' => $cmcid, 'message_id' => $cmmid,
                'text'    => "<b>📊 Statistika\n\n✅ Jami: $total ta</b>",
                'parse_mode' => 'html',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => "📅 Kunlik", 'callback_data' => "kunlik"],
                    ['text' => "📆 Haftalik", 'callback_data' => "haftalik"],
                    ['text' => "📊 Oylik", 'callback_data' => "oylik"],
                ]]]),
            ]);
            exit();
        }

        $files  = is_dir($users_dir) ? array_diff(scandir($users_dir), ['.', '..']) : [];
        $counts = [];

        if ($cdata === 'kunlik') {
            $days = [];
            for ($i = 0; $i < 6; $i++) {
                $days[] = date("d.m.Y", strtotime("-$i days"));
            }
            foreach ($days as $d) $counts[$d] = 0;
            foreach ($files as $file) {
                $s = fread_safe("$users_dir/$file");
                if (isset($counts[$s])) $counts[$s]++;
            }
            $msg = "<b>📅 Kunlik statistika:</b>\n<blockquote>";
            $i = 0;
            foreach ($counts as $d => $c) {
                $label = $i === 0 ? "Bugun" : ($i === 1 ? "Kecha" : "$i kun oldin");
                $msg .= "🔹 $label ($d): $c ta\n";
                $i++;
            }
            $msg .= "</blockquote>";

        } elseif ($cdata === 'haftalik') {
            for ($i = 0; $i < 3; $i++) $counts[$i] = 0;
            $w0 = (int)date("W");
            foreach ($files as $file) {
                $s = fread_safe("$users_dir/$file");
                $w = (int)date("W", strtotime(str_replace(".", "-", $s)));
                $diff = $w0 - $w;
                if ($diff >= 0 && $diff < 3) $counts[$diff]++;
            }
            $msg = "<b>📆 Haftalik statistika:</b>\n<blockquote>🔹 Shu hafta: {$counts[0]} ta\n🔹 O'tgan hafta: {$counts[1]} ta\n🔹 2 hafta oldin: {$counts[2]} ta</blockquote>";

        } else { // oylik
            for ($i = 0; $i < 3; $i++) $counts[$i] = 0;
            $m0 = date("m.Y");
            $m1 = date("m.Y", strtotime("-1 month"));
            $m2 = date("m.Y", strtotime("-2 months"));
            foreach ($files as $file) {
                $s = fread_safe("$users_dir/$file");
                $mo = date("m.Y", strtotime(str_replace(".", "-", $s)));
                if ($mo === $m0) $counts[0]++;
                elseif ($mo === $m1) $counts[1]++;
                elseif ($mo === $m2) $counts[2]++;
            }
            $msg = "<b>📊 Oylik statistika:</b>\n<blockquote>🔹 Shu oy: {$counts[0]} ta\n🔹 O'tgan oy: {$counts[1]} ta\n🔹 2 oy oldin: {$counts[2]} ta</blockquote>";
        }

        bot('editMessageText', [
            'chat_id' => $cmcid, 'message_id' => $cmmid,
            'text'    => $msg, 'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "⬅️ Ortga", 'callback_data' => "stat"]]]]),
        ]);
        exit();
    }

    // Bot holati
    if ($cdata === 'xolat' && $is_admin_cb) {
        $h    = fread_safe($holat_f, "Yoqilgan");
        $stxt = $h === "Yoqilgan" ? "✅ Yoqilgan" : "❌ O'chirilgan";
        $btn  = $h === "Yoqilgan" ? "❌ O'chirish" : "✅ Yoqish";
        bot('editMessageText', [
            'chat_id' => $cmcid, 'message_id' => $cmmid,
            'text'    => "<b>📄 Bot holati:</b> $stxt", 'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => $btn, 'callback_data' => "bot"]]]]),
        ]);
        exit();
    }

    if ($cdata === 'bot' && $is_admin_cb) {
        $h = fread_safe($holat_f, "Yoqilgan");
        if ($h === "Yoqilgan") {
            fwrite_safe($holat_f, "O'chirilgan");
            $new = "❌ O'chirilgan";
        } else {
            fwrite_safe($holat_f, "Yoqilgan");
            $new = "✅ Yoqilgan";
        }
        bot('editMessageText', [
            'chat_id' => $cmcid, 'message_id' => $cmmid,
            'text'    => "<b>✅ Bot holati o'zgartirildi!\n\nYangi holat: $new</b>", 'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "◀️ Orqaga", 'callback_data' => "xolat"]]]]),
        ]);
        exit();
    }

    // Kino yuklash (oddiy)
    if ($cdata === 'oddiyk' && $is_admin_cb) {
        if (empty($kino_ch)) {
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<b>⚠️ Kino kanali qo'shilmagan!</b>", 'parse_mode' => 'html']);
            exit();
        }
        $kod_f = BASE_DIR . "/kino/kodi.txt";
        if (!file_exists($kod_f)) fwrite_safe($kod_f, "0");
        $kod = (int)fread_safe($kod_f) + 1;
        fwrite_safe($kod_f, (string)$kod);
        fwrite_safe(BASE_DIR . "/step/new_kino.txt", (string)$kod);

        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        bot('sendMessage', [
            'chat_id'      => $cmcid,
            'text'         => "<i>📄 Avval kino uchun rasm yuboring!</i>",
            'parse_mode'   => 'html',
            'reply_markup' => $kb_main,
        ]);
        setStep($cmcid, 'rasm');
        exit();
    }

    // Xabarnoma callbacklar
    if ($cdata === 'send' && $is_admin_cb) {
        $uc = count(array_filter(file(BASE_DIR . "/azo.dat") ?: []));
        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        bot('sendMessage', [
            'chat_id'      => $cmcid,
            'text'         => "<b>📨 $uc ta foydalanuvchiga xabar yuboring (oddiy):</b>",
            'parse_mode'   => 'html',
            'reply_markup' => $kb_main,
        ]);
        setStep($cmcid, 'sendpost');
        exit();
    }

    if ($cdata === 'send2' && $is_admin_cb) {
        $uc = count(array_filter(file(BASE_DIR . "/azo.dat") ?: []));
        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        bot('sendMessage', [
            'chat_id'      => $cmcid,
            'text'         => "<b>📨 $uc ta foydalanuvchiga forward xabar yuboring:</b>",
            'parse_mode'   => 'html',
            'reply_markup' => $kb_main,
        ]);
        setStep($cmcid, 'sendfwrd');
        exit();
    }

    if ($cdata === 'user' && $is_admin_cb) {
        bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
        bot('sendMessage', [
            'chat_id'      => $cmcid,
            'text'         => "<b>📝 Foydalanuvchi ID raqamini kiriting:</b>",
            'parse_mode'   => 'html',
            'reply_markup' => $kb_main,
        ]);
        setStep($cmcid, 'user');
        exit();
    }

    http_response_code(200); exit();
}

// ============================================================
//  💬  MESSAGE HANDLER
// ============================================================
if ($message) {
    $is_admin = in_array($cid, $admins);

    // ---- /start ----
    if (str_starts_with($text, '/start')) {
        $parts = explode(' ', $text);
        $kod   = $parts[1] ?? '';
        setStep($cid);

        if (!empty($kod) && checkSubscription($uid)) {
            // Kino berish
            $kdir = BASE_DIR . "/kino/$kod";
            if (is_dir($kdir)) {
                $nomi     = fread_safe("$kdir/nomi.txt");
                $video_id = fread_safe("$kdir/film.txt");
                $dc       = (int)fread_safe("$kdir/downcount.txt", "0") + 1;
                fwrite_safe("$kdir/downcount.txt", (string)$dc);
                if ($video_id) {
                    bot('sendVideo', [
                        'chat_id'      => $cid,
                        'video'        => $video_id,
                        'caption'      => "<b>🍿 Kino:\n<blockquote>$nomi</blockquote>\n\n🔰 Kanal: $kino_ch\n🗂 Yuklashlar: $dc\n\n🤖 Bot: @$bot_username</b>",
                        'parse_mode'   => 'html',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => "🔎 Kino kodlari", 'url' => "https://t.me/" . ltrim($kino_ch, '@')]],
                            [['text' => "📋 Ulashish", 'url' => "https://t.me/share/url?url=https://t.me/$bot_username?start=$kod"]],
                        ]]),
                    ]);
                    http_response_code(200); exit();
                }
            }
        }

        // Start sahifasi
        $boshqar_btn = $is_admin
            ? [['text' => "🗄 Boshqaruv paneli", 'callback_data' => "boshqar"]]
            : [];

        $inline = [[['text' => "🔎 Kino kodlari", 'url' => "https://t.me/" . ltrim($kino_ch, '@')]]];
        if ($boshqar_btn) $inline[] = $boshqar_btn;

        if (checkSubscription($uid)) {
            bot('sendMessage', [
                'chat_id'      => $cid,
                'text'         => "🖐 <b>Assalomu alaykum, $name_link!\n\n<blockquote>📊 Buyruqlar:\n/start — Botni qayta ishga tushirish\n/help — Yordam</blockquote>\n\n🔎 Kino kodini yuboring:</b>",
                'parse_mode'   => 'html',
                'disable_web_page_preview' => true,
                'reply_markup' => json_encode(['inline_keyboard' => $inline]),
            ]);
        }
        http_response_code(200); exit();
    }

    // ---- /help ----
    if ($text === '/help') {
        if (checkSubscription($uid)) {
            bot('sendMessage', [
                'chat_id'      => $cid,
                'text'         => "💻 <b>Savol va takliflar uchun murojaat qiling:</b>",
                'parse_mode'   => 'html',
                'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => "☎️ Qo'llab-quvvatlash", 'url' => "tg://user?id=" . OWNER_ID]],
                ]]),
            ]);
        }
        http_response_code(200); exit();
    }

    // ---- Orqaga ----
    if ($text === "◀️ Orqaga") {
        setStep($cid);
        bot('sendMessage', [
            'chat_id'      => $cid,
            'text'         => "🖐 <b>Assalomu alaykum, $name_link!\n\n🔎 Kino kodini yuboring:</b>",
            'parse_mode'   => 'html',
            'reply_markup' => $kb_reply,
        ]);
        http_response_code(200); exit();
    }

    // ---- Admin panel ----
    if (($text === "🗄 Boshqaruv paneli" || $text === "/panel") && $is_admin) {
        setStep($cid);
        bot('sendMessage', [
            'chat_id'      => $cid,
            'text'         => "<b>🖥️ Admin paneliga xush kelibsiz!</b>",
            'parse_mode'   => 'html',
            'reply_markup' => $kb_panel,
        ]);
        http_response_code(200); exit();
    }

    // ==== ADMIN STEP HANDLER ====

    // Admin qo'shish
    if ($step === 'add-admin' && $cid === OWNER_ID) {
        if (is_numeric(trim($text))) {
            $af = BASE_DIR . "/tizim/admins.txt";
            $al = array_filter(explode("\n", fread_safe($af)));
            if (!in_array(trim($text), $al)) {
                fwrite_safe($af, "\n" . trim($text), true);
            }
            bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ Admin qo'shildi: <code>$text</code>", 'parse_mode' => 'html', 'reply_markup' => $kb_panel]);
            setStep($cid);
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "❗ Faqat raqam (ID) yuboring!", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }

    if ($step === 'remove-admin' && $cid === OWNER_ID) {
        if (is_numeric(trim($text))) {
            $af  = BASE_DIR . "/tizim/admins.txt";
            $al  = array_filter(explode("\n", fread_safe($af)));
            $new = array_filter($al, fn($a) => trim($a) !== trim($text));
            fwrite_safe($af, implode("\n", $new));
            bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ Admin o'chirildi: <code>$text</code>", 'parse_mode' => 'html', 'reply_markup' => $kb_panel]);
            setStep($cid);
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "❗ Faqat raqam (ID) yuboring!", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }

    // Ommaviy kanal qo'shish
    if ($step === 'add-pub-channel' && $is_admin) {
        if (mb_stripos($text, "@") !== false) {
            $ch = trim($text);
            $f  = BASE_DIR . "/channel.txt";
            $cl = fread_safe($f);
            if (mb_stripos($cl, $ch) === false) {
                fwrite_safe($f, ($cl ? $cl . "\n" : "") . $ch);
            }
            bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ $ch qo'shildi.", 'parse_mode' => 'html', 'reply_markup' => $kb_panel]);
            setStep($cid);
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "❗ <code>@KanalNomi</code> formatida yuboring.", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }

    if ($step === 'remove-pub-channel' && $is_admin) {
        $f   = BASE_DIR . "/channel.txt";
        $cl  = array_filter(explode("\n", fread_safe($f)));
        $new = array_filter($cl, fn($c) => trim($c) !== trim($text));
        fwrite_safe($f, implode("\n", $new));
        bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ $text o'chirildi.", 'parse_mode' => 'html', 'reply_markup' => $kb_panel]);
        setStep($cid);
        http_response_code(200); exit();
    }

    // Maxfiy kanal qo'shish
    if ($step === 'add-chanel' && $is_admin) {
        $lines = array_values(array_filter(explode("\n", $text)));
        if (count($lines) === 2 && mb_stripos($lines[0], "https://t.me/+") !== false && mb_stripos($lines[1], "-100") !== false) {
            $link   = trim($lines[0]);
            $chanid = trim($lines[1]);
            fwrite_safe(BASE_DIR . "/tizim/$chanid.txt", "");
            $f  = BASE_DIR . "/channel2.txt";
            $cl = fread_safe($f);
            fwrite_safe($f, ($cl ? $cl . "\n" : "") . "$link\n$chanid");
            bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ Maxfiy kanal qo'shildi.", 'parse_mode' => 'html', 'reply_markup' => $kb_panel]);
            setStep($cid);
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "❗ To'g'ri formatda yuboring:\n<code>https://t.me/+...\n-100...</code>", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }

    if ($step === 'remove-secret-channel' && $is_admin) {
        $lines = array_values(array_filter(explode("\n", $text)));
        if (count($lines) === 2) {
            $link   = trim($lines[0]);
            $chanid = trim($lines[1]);
            $f    = BASE_DIR . "/channel2.txt";
            $rows = array_values(array_filter(explode("\n", fread_safe($f))));
            $new  = [];
            for ($i = 0; $i < count($rows) - 1; $i += 2) {
                if (trim($rows[$i]) === $link && trim($rows[$i + 1]) === $chanid) continue;
                $new[] = $rows[$i];
                $new[] = $rows[$i + 1];
            }
            fwrite_safe($f, implode("\n", $new));
            @unlink(BASE_DIR . "/tizim/$chanid.txt");
            bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ Maxfiy kanal o'chirildi.", 'parse_mode' => 'html', 'reply_markup' => $kb_panel]);
            setStep($cid);
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "❗ To'g'ri formatda yuboring:\n<code>https://t.me/+...\n-100...</code>", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }

    // Kino kanali qo'shish
    if ($step === 'add-channl' && $is_admin) {
        if (mb_stripos($text, "@") !== false) {
            $get = bot('getChat', ['chat_id' => $text]);
            if (isset($get->result)) {
                $chuser = $get->result->username ?? '';
                if (isAdmin($chuser)) {
                    fwrite_safe(BASE_DIR . "/kino_ch.txt", $text);
                    bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ $text kino kanali sifatida saqlandi.", 'parse_mode' => 'html', 'reply_markup' => $kb_panel]);
                    setStep($cid);
                } else {
                    bot('sendMessage', [
                        'chat_id'    => $cid,
                        'text'       => "<b>⚠️ Bot ushbu kanalda admin emas!</b>",
                        'parse_mode' => 'html',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🛡 Kanalga admin qilish", 'url' => "https://t.me/$bot_username?startchannel=on"]]]]),
                    ]);
                }
            } else {
                bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>Kanal topilmadi!</b>", 'parse_mode' => 'html']);
            }
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>📄 Namuna:</b> <code>@KanalNomi</code>", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }

    // Kino yuklash - rasm
    if ($step === 'rasm' && $photo && $is_admin) {
        $k       = fread_safe(BASE_DIR . "/step/new_kino.txt", "1");
        $kdir    = BASE_DIR . "/kino/$k";
        if (!is_dir($kdir)) mkdir($kdir, 0755, true);
        $photo_id = end($photo)->file_id;
        fwrite_safe("$kdir/rasm.txt", $photo_id);
        setStep($cid, 'kinoo');
        bot('sendMessage', [
            'chat_id'    => $cid,
            'text'       => "<i>✅ Rasm saqlandi!\n\n🎬 Endi filmni caption bilan yuboring:</i>",
            'parse_mode' => 'html',
            'reply_markup' => $kb_main,
        ]);
        http_response_code(200); exit();
    }

    // Kino yuklash - video
    if ($step === 'kinoo' && $video && $is_admin) {
        $k       = fread_safe(BASE_DIR . "/step/new_kino.txt", "1");
        $kdir    = BASE_DIR . "/kino/$k";
        if (!is_dir($kdir)) mkdir($kdir, 0755, true);
        $vid_id  = $video->file_id;
        fwrite_safe("$kdir/nomi.txt", $caption);
        fwrite_safe("$kdir/film.txt", $vid_id);
        $rasm   = fread_safe("$kdir/rasm.txt");

        $msg = bot('sendPhoto', [
            'chat_id'      => $kino_ch,
            'photo'        => $rasm,
            'caption'      => "<b>🍿 Yangi film!\n\n🎞 Film:\n<blockquote>$caption</blockquote>\n\n🔢 Kodi: <code>$k</code>\n\n🤖 Bot: @$bot_username</b>",
            'parse_mode'   => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "📥 Yuklab olish", 'url' => "https://t.me/$bot_username?start=$k"]]]]),
        ]);

        $son_f = BASE_DIR . "/kino/son.txt";
        fwrite_safe($son_f, (string)((int)fread_safe($son_f) + 1));

        $post_mid = $msg->result->message_id ?? 0;
        $ch_clean = ltrim($kino_ch, '@');

        bot('sendMessage', [
            'chat_id'      => $cid,
            'text'         => "<blockquote>✅ Film bazaga joylandi!</blockquote>\n\n🔢 Kod: <code>$k</code>",
            'parse_mode'   => 'html',
            'reply_to_message_id' => $mid,
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "📢 Ko'rish", 'url' => "https://t.me/$ch_clean/$post_mid"]]]]),
        ]);
        setStep($cid);
        bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>✅ Admin panelga qaytdingiz.</b>", 'parse_mode' => 'html', 'reply_markup' => $kb_panel]);
        http_response_code(200); exit();
    }

    // ---- Admin panel tugmalari ----
    if ($text === "📢 Kanallar" && $is_admin) {
        bot('sendMessage', [
            'chat_id'    => $cid,
            'text'       => "<b>📢 Kanallar bo'limi:</b>",
            'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => "📢 Majburiy obuna", 'callback_data' => "majburiy"]],
                [['text' => "🎬 Kino kanali",    'callback_data' => "qoshimcha"]],
            ]]),
        ]);
        http_response_code(200); exit();
    }

    if ($text === "📥 Kino Yuklash" && $is_admin) {
        bot('sendMessage', [
            'chat_id'    => $cid,
            'text'       => "<b>⁉️ Qaysi usulda kino yuklaysiz?</b>",
            'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => "✅ Rasm + Video usuli", 'callback_data' => "oddiyk"]],
            ]]),
        ]);
        http_response_code(200); exit();
    }

    if ($text === "✉ Xabarnoma" && $is_admin) {
        bot('sendMessage', [
            'chat_id'    => $cid,
            'text'       => "<b>❗ Xabar turini tanlang:</b>",
            'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => "💠 Oddiy xabar",   'callback_data' => "send"],
                 ['text' => "💠 Forward xabar",  'callback_data' => "send2"]],
                [['text' => "👤 Userga xabar",   'callback_data' => "user"]],
                [['text' => "❌ Yopish",          'callback_data' => "bosh"]],
            ]]),
        ]);
        http_response_code(200); exit();
    }

    if ($text === "📊 Statistika" && $is_admin) {
        $total = substr_count(fread_safe(BASE_DIR . "/azo.dat"), "\n");
        bot('sendMessage', [
            'chat_id'    => $cid,
            'text'       => "<b>📊 Statistika\n\n✅ Jami: $total ta</b>",
            'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[
                ['text' => "📅 Kunlik",  'callback_data' => "kunlik"],
                ['text' => "📆 Haftalik",'callback_data' => "haftalik"],
                ['text' => "📊 Oylik",   'callback_data' => "oylik"],
            ]]]),
        ]);
        http_response_code(200); exit();
    }

    if ($text === "🤖 Bot holati" && $is_admin) {
        $h    = fread_safe($holat_f, "Yoqilgan");
        $stxt = $h === "Yoqilgan" ? "✅ Yoqilgan" : "❌ O'chirilgan";
        $btn  = $h === "Yoqilgan" ? "❌ O'chirish" : "✅ Yoqish";
        bot('sendMessage', [
            'chat_id'    => $cid,
            'text'       => "<b>📄 Bot holati:</b> $stxt",
            'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => $btn, 'callback_data' => "bot"]]]]),
        ]);
        http_response_code(200); exit();
    }

    if ($text === "👥 Adminlar" && $is_admin) {
        $kb = ($cid === OWNER_ID)
            ? ['inline_keyboard' => [
                [['text' => "➕ Admin qo'shish", 'callback_data' => "add"]],
                [['text' => "📑 Ro'yxat", 'callback_data' => "list"], ['text' => "🗑 O'chirish", 'callback_data' => "remove"]],
              ]]
            : ['inline_keyboard' => [[['text' => "📑 Ro'yxat", 'callback_data' => "list"]]]];
        bot('sendMessage', [
            'chat_id'    => $cid,
            'text'       => "👮 <b>Adminlar boshqaruvi:</b>",
            'parse_mode' => 'html',
            'reply_markup' => json_encode($kb),
        ]);
        http_response_code(200); exit();
    }

    // Xabarnoma - Userga
    if ($step === 'user' && $is_admin) {
        if (is_numeric(trim($text))) {
            fwrite_safe(BASE_DIR . "/step/target_user.txt", trim($text));
            setStep($cid, 'xabar');
            bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>📝 Yubormoqchi bo'lgan xabarni yuboring:</b>", 'parse_mode' => 'html', 'reply_markup' => $kb_main]);
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>Faqat raqam (ID) yuboring!</b>", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }

    if ($step === 'xabar' && $is_admin) {
        $target = fread_safe(BASE_DIR . "/step/target_user.txt");
        bot('sendMessage', ['chat_id' => $target, 'text' => "<b>📩 Yangi xabar:\n\n</b>" . $text, 'parse_mode' => 'html']);
        bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ Xabar yuborildi!", 'parse_mode' => 'html', 'reply_markup' => $kb_panel]);
        setStep($cid);
        @unlink(BASE_DIR . "/step/target_user.txt");
        http_response_code(200); exit();
    }

    // Xabarnoma - Barcha foydalanuvchilarga
    if ($step === 'sendpost' && $is_admin) {
        setStep($cid);
        $users = file(BASE_DIR . "/azo.dat", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $ok_c = $err_c = 0;
        bot('sendMessage', ['chat_id' => $cid, 'text' => "🔄 <b>Xabar yuborilmoqda...</b>", 'parse_mode' => 'html']);
        foreach ($users as $uid2) {
            $uid2 = trim($uid2);
            if (!$uid2) continue;
            $r = bot('copyMessage', ['from_chat_id' => $cid, 'chat_id' => $uid2, 'message_id' => $mid]);
            ($r && $r->ok) ? $ok_c++ : $err_c++;
            usleep(50000); // 50ms — flood limit himoyasi
        }
        bot('sendMessage', [
            'chat_id'    => $cid,
            'text'       => "<b>✅ Xabar yuborildi!\n\n✅ Yuborildi: $ok_c\n❌ Yuborilmadi: $err_c</b>",
            'parse_mode' => 'html',
            'reply_markup' => $kb_panel,
        ]);
        http_response_code(200); exit();
    }

    if ($step === 'sendfwrd' && $is_admin) {
        setStep($cid);
        $users = file(BASE_DIR . "/azo.dat", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $ok_c = $err_c = 0;
        bot('sendMessage', ['chat_id' => $cid, 'text' => "🔄 <b>Xabar yuborilmoqda...</b>", 'parse_mode' => 'html']);
        foreach ($users as $uid2) {
            $uid2 = trim($uid2);
            if (!$uid2) continue;
            $r = bot('forwardMessage', ['from_chat_id' => $cid, 'chat_id' => $uid2, 'message_id' => $mid]);
            ($r && $r->ok) ? $ok_c++ : $err_c++;
            usleep(50000);
        }
        bot('sendMessage', [
            'chat_id'    => $cid,
            'text'       => "<b>✅ Forward yuborildi!\n\n✅ Yuborildi: $ok_c\n❌ Yuborilmadi: $err_c</b>",
            'parse_mode' => 'html',
            'reply_markup' => $kb_panel,
        ]);
        http_response_code(200); exit();
    }

    // ---- Kino kodi (raqam yoki /start?kod) ----
    if (is_numeric($text) && empty($step) && checkSubscription($uid)) {
        $kod  = $text;
        $kdir = BASE_DIR . "/kino/$kod";
        if (is_dir($kdir)) {
            $nomi     = fread_safe("$kdir/nomi.txt");
            $video_id = fread_safe("$kdir/film.txt");
            $dc       = (int)fread_safe("$kdir/downcount.txt", "0") + 1;
            fwrite_safe("$kdir/downcount.txt", (string)$dc);
            if ($video_id) {
                bot('sendVideo', [
                    'chat_id'      => $cid,
                    'video'        => $video_id,
                    'caption'      => "<b>🍿 Kino:\n<blockquote>$nomi</blockquote>\n\n🔰 Kanal: $kino_ch\n🗂 Yuklashlar: $dc\n\n🤖 Bot: @$bot_username</b>",
                    'parse_mode'   => 'html',
                    'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => "🔎 Kino kodlari", 'url' => "https://t.me/" . ltrim($kino_ch, '@')]],
                        [['text' => "📋 Ulashish", 'url' => "https://t.me/share/url?url=https://t.me/$bot_username?start=$kod"]],
                    ]]),
                ]);
            } else {
                bot('sendMessage', ['chat_id' => $cid, 'text' => "❌ <b>Bu kod bo'yicha film topilmadi.</b>", 'parse_mode' => 'html']);
            }
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "❌ <b>Bunday kod mavjud emas.</b>", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }
}

http_response_code(200);
