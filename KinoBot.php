<?php
/**
 * ============================================================
 *  🎬 KinoBot — Universal Telegram Kino Bot
 *  ✅ Render, VPS, Shared Hosting, Tas-ix — hammaga mos
 *  ✅ .env orqali token boshqaruvi
 *  ✅ cURL + fallback file_get_contents
 *  ✅ Apache, Nginx, Docker — hammada ishlaydi
 * ============================================================
 */

ob_start();
error_reporting(0);
@ini_set('display_errors', '0');
@set_time_limit(120);
date_default_timezone_set('Asia/Tashkent');

// ============================================================
//  ⚙️  .env faylini o'qish (agar getenv ishlamasa)
// ============================================================
function loadEnv(string $path): void
{
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            if (!getenv($key)) putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}
loadEnv(__DIR__ . '/.env');

// ============================================================
//  🔑  ASOSIY SOZLAMALAR
//  Render da: Settings → Environment Variables ichiga qo'shing
//  BOT_TOKEN = sizning_token
//  OWNER_ID  = sizning_telegram_id
// ============================================================
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'BOT_TOKEN');
define('OWNER_ID',  getenv('OWNER_ID')  ?: '5907118746');
define('BASE_DIR',  __DIR__);

// ============================================================
//  📁  Papkalarni yaratish
// ============================================================
foreach (['step', 'kino', 'tizim', 'users', 'admin'] as $d) {
    $p = BASE_DIR . "/$d";
    if (!is_dir($p)) @mkdir($p, 0755, true);
}

// ============================================================
//  🔧  Yordamchi funksiyalar
// ============================================================

/**
 * Telegram API chaqiruvi
 * cURL bo'lmasa file_get_contents bilan ishlaydi
 */
function bot(string $method, array $data = []): ?object
{
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ['Expect:'],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
    } else {
        // Fallback: file_get_contents (Tas-ix va arzon hostinglar uchun)
        $boundary = '----FormBoundary' . uniqid();
        $body     = '';
        foreach ($data as $k => $v) {
            $body .= "--$boundary\r\n";
            $body .= "Content-Disposition: form-data; name=\"$k\"\r\n\r\n";
            $body .= "$v\r\n";
        }
        $body .= "--$boundary--\r\n";
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: multipart/form-data; boundary=$boundary\r\n",
                'content' => $body,
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx);
    }

    return ($res !== false && $res !== null) ? json_decode($res) : null;
}

/** Fayl o'qish — xavfsiz */
function fget(string $path, string $default = ''): string
{
    return file_exists($path) ? trim(file_get_contents($path)) : $default;
}

/** Fayl yozish — xavfsiz */
function fput(string $path, string $data, bool $append = false): void
{
    $flags = $append ? FILE_APPEND | LOCK_EX : LOCK_EX;
    @file_put_contents($path, $data, $flags);
}

/** Step saqlash / o'chirish */
function setStep(string $cid, string $step = ''): void
{
    $f = BASE_DIR . "/step/$cid.step";
    ($step === '') ? @unlink($f) : fput($f, $step);
}

function getStep(string $cid): string
{
    return fget(BASE_DIR . "/step/$cid.step");
}

/** Bot kanalda admin ekanini tekshirish */
function isBotAdmin(string $username): bool
{
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/getChatAdministrators?chat_id=@' . $username;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
        $r = curl_exec($ch); curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 10], 'ssl' => ['verify_peer' => false]]);
        $r   = @file_get_contents($url, false, $ctx);
    }
    $d = ($r !== false) ? json_decode($r) : null;
    return isset($d->ok) && $d->ok;
}

/** Foydalanuvchini bloklash */
function addBlock(string $id): void
{
    $f    = BASE_DIR . '/tizim/blocked.txt';
    $list = array_filter(explode("\n", fget($f)));
    if (!in_array($id, $list)) {
        fput($f, "\n$id", true);
    }
}

/** Foydalanuvchini bazaga qo'shish + owner ga xabar */
function addUser(string $id, string $name, string $username): void
{
    $uf   = BASE_DIR . "/users/$id.txt";
    $bazf = BASE_DIR . '/azo.dat';
    $baza = fget($bazf);

    if (!file_exists($uf)) {
        fput($uf, date('d.m.Y'));
    }
    if (mb_stripos($baza, $id) === false) {
        fput($bazf, "\n$id", true);
        $uname = $username ? "@$username" : "yo'q";
        bot('sendMessage', [
            'chat_id'      => OWNER_ID,
            'text'         => "<b>👤 Yangi foydalanuvchi!\n\n👤 Ism: $name\n🆔 ID: <code>$id</code>\n🔗 Username: $uname\n🕒 " . date('d.m.Y | H:i') . "</b>",
            'parse_mode'   => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "👀 Ko'rish", 'url' => "tg://user?id=$id"]]]]),
        ]);
    }
}

/** Majburiy obuna tekshiruvi */
function checkSub(string $uid): bool
{
    global $bot_username;
    $buttons = [];

    // Ommaviy kanallar
    $ch1 = fget(BASE_DIR . '/channel.txt');
    if ($ch1) {
        foreach (array_filter(explode("\n", $ch1)) as $line) {
            $un = ltrim(trim($line), '@');
            if (!$un) continue;
            $info   = bot('getChat', ['chat_id' => "@$un"]);
            $title  = $info->result->title ?? $un;
            $member = bot('getChatMember', ['chat_id' => "@$un", 'user_id' => $uid]);
            $stat   = $member->result->status ?? 'left';
            if (!in_array($stat, ['creator', 'administrator', 'member'])) {
                $buttons[] = [['text' => "❌ $title", 'url' => "https://t.me/$un"]];
            }
        }
    }

    // Maxfiy kanallar (link\n-100id format)
    $ch2  = fget(BASE_DIR . '/channel2.txt');
    $rows = array_values(array_filter(explode("\n", $ch2)));
    for ($i = 0; $i < count($rows) - 1; $i += 2) {
        $link    = trim($rows[$i]);
        $chanid  = trim($rows[$i + 1]);
        $fayl    = BASE_DIR . "/tizim/$chanid.txt";
        $members = file_exists($fayl) ? array_filter(explode("\n", fget($fayl))) : [];
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

/** Adminlar ro'yxati */
function getAdmins(): array
{
    $list   = array_filter(explode("\n", fget(BASE_DIR . '/tizim/admins.txt')));
    $list[] = OWNER_ID;
    return array_unique(array_map('trim', $list));
}

// ============================================================
//  📥  Update o'qish
// ============================================================
$input = @file_get_contents('php://input');
if (empty($input)) { http_response_code(200); exit(); }
$update = json_decode($input);
if (!$update) { http_response_code(200); exit(); }

// Bot username
$bi           = bot('getMe');
$bot_username = $bi->result->username ?? 'KinoBot';

// Adminlar
$admins = getAdmins();

// ============================================================
//  📋  Klaviaturalar
// ============================================================
$KB_PANEL = json_encode([
    'resize_keyboard' => true,
    'keyboard' => [
        [['text' => "📢 Kanallar"],   ['text' => "📥 Kino Yuklash"]],
        [['text' => "✉ Xabarnoma"],   ['text' => "📊 Statistika"]],
        [['text' => "🤖 Bot holati"], ['text' => "👥 Adminlar"]],
        [['text' => "◀️ Orqaga"]],
    ],
]);
$KB_BACK  = json_encode(['resize_keyboard' => true, 'keyboard' => [[['text' => "◀️ Orqaga"]]]]);
$KB_MAIN  = json_encode(['resize_keyboard' => true, 'keyboard' => [[['text' => "🗄 Boshqaruv paneli"]]]]);
$KB_REPLY = json_encode(['resize_keyboard' => false, 'force_reply' => true, 'selective' => true]);

// holat fayli
$holat_f = BASE_DIR . '/holat.txt';
if (!file_exists($holat_f)) fput($holat_f, 'Yoqilgan');
$holat   = fget($holat_f, 'Yoqilgan');
$kino_ch = fget(BASE_DIR . '/kino_ch.txt');

// ============================================================
//  🔔  CHAT JOIN REQUEST (maxfiy kanal)
// ============================================================
if (isset($update->chat_join_request)) {
    $req    = $update->chat_join_request;
    $jcid   = (string)$req->chat->id;
    $juid   = (string)$req->from->id;
    $fayl   = BASE_DIR . "/tizim/$jcid.txt";
    $mbrs   = file_exists($fayl) ? array_filter(explode("\n", fget($fayl))) : [];
    if (!in_array($juid, $mbrs)) {
        $mbrs[] = $juid;
        fput($fayl, implode("\n", array_filter($mbrs)));
        bot('sendMessage', [
            'chat_id'    => $juid,
            'text'       => "<b>✅ Obunangiz qabul qilindi!\n\n/start bosing va kino kodini yuboring!</b>",
            'parse_mode' => 'html',
        ]);
    }
    http_response_code(200); exit();
}

// ============================================================
//  🚷  BOT KICK
// ============================================================
if (isset($update->my_chat_member)) {
    $stat = $update->my_chat_member->new_chat_member->status ?? '';
    if ($stat === 'kicked') addBlock((string)$update->my_chat_member->from->id);
    http_response_code(200); exit();
}

// ============================================================
//  📣  CALLBACK QUERY
// ============================================================
$cb = $update->callback_query ?? null;
if ($cb) {
    $cdata  = $cb->data;
    $qid    = $cb->id;
    $cfid   = (string)$cb->from->id;
    $cfname = $cb->from->first_name ?? '';
    $cfsur  = $cb->from->last_name ?? '';
    $cfuser = $cb->from->username ?? '';
    $cmcid  = (string)$cb->message->chat->id;
    $cmmid  = $cb->message->message_id;
    $isAdmCb = in_array($cmcid, $admins);

    addUser($cfid, $cfname, $cfuser);

    // Bot o'chirilgan
    if ($holat !== 'Yoqilgan' && !$isAdmCb) {
        bot('answerCallbackQuery', ['callback_query_id' => $qid, 'text' => "⛔️ Bot vaqtinchalik o'chirilgan!", 'show_alert' => true]);
        http_response_code(200); exit();
    }

    // ------ Callback switch ------
    switch ($cdata) {

        case 'checksuv':
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            if (checkSub($cfid)) {
                bot('sendMessage', [
                    'chat_id'    => $cfid,
                    'text'       => "✅ <b>Obunangiz tasdiqlandi!\n\n🔎 Kino kodini yuboring:</b>",
                    'parse_mode' => 'html',
                ]);
            }
            break;

        case 'boshqar':
        case 'bosh':
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<b>🖥️ Admin paneli:</b>", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
            break;

        case 'admins':
            if (!$isAdmCb) break;
            $kb = ($cmcid === OWNER_ID)
                ? ['inline_keyboard' => [[['text' => "➕ Admin qo'shish", 'callback_data' => "add"]], [['text' => "📑 Ro'yxat", 'callback_data' => "list"], ['text' => "🗑 O'chirish", 'callback_data' => "remove"]]]]
                : ['inline_keyboard' => [[['text' => "📑 Ro'yxat", 'callback_data' => "list"]]]];
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => "👮 <b>Adminlar:</b>", 'parse_mode' => 'html', 'reply_markup' => json_encode($kb)]);
            break;

        case 'add':
            if ($cmcid !== OWNER_ID) break;
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<b>📝 Yangi admin ID sini yuboring:</b>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
            setStep($cmcid, 'add-admin');
            break;

        case 'remove':
            if ($cmcid !== OWNER_ID) break;
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<b>📝 O'chiriladigan admin ID sini yuboring:</b>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
            setStep($cmcid, 'remove-admin');
            break;

        case 'list':
            if (!$isAdmCb) break;
            $al = fget(BASE_DIR . '/tizim/admins.txt');
            $t2 = empty($al) ? "🚫 <b>Qo'shimcha admin yo'q!</b>" : "👮 <b>Adminlar ro'yxati:</b>\n$al";
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => $t2, 'parse_mode' => 'html', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 Orqaga", 'callback_data' => "admins"]]]])]);
            break;

        case 'majburiy':
            if (!$isAdmCb) break;
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => "<b>📢 Majburiy obuna:</b>", 'parse_mode' => 'html',
                'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => "🌐 Ommaviy kanallar", 'callback_data' => "ommav"]],
                    [['text' => "🔒 Maxfiy kanallar",  'callback_data' => "maxfiy"]],
                    [['text' => "◀️ Orqaga",            'callback_data' => "bosh"]],
                ]])]);
            break;

        case 'kanallar':
            if (!$isAdmCb) break;
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => "<b>📢 Kanallar:</b>", 'parse_mode' => 'html',
                'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => "📢 Majburiy obuna", 'callback_data' => "majburiy"]],
                    [['text' => "🎬 Kino kanali",    'callback_data' => "qoshimcha"]],
                    [['text' => "◀️ Orqaga",          'callback_data' => "bosh"]],
                ]])]);
            break;

        case 'ommav':
            if (!$isAdmCb) break;
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => "<b>🌐 Ommaviy kanallar:</b>", 'parse_mode' => 'html',
                'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => "➕ Qo'shish", 'callback_data' => "qoshish"]],
                    [['text' => "📑 Ro'yxat",  'callback_data' => "royxati"], ['text' => "🗑 O'chirish", 'callback_data' => "ochirish"]],
                    [['text' => "◀️ Orqaga",   'callback_data' => "majburiy"]],
                ]])]);
            break;

        case 'maxfiy':
            if (!$isAdmCb) break;
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => "<b>🔒 Maxfiy kanallar:</b>", 'parse_mode' => 'html',
                'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => "➕ Qo'shish", 'callback_data' => "qosh"]],
                    [['text' => "📑 Ro'yxat",  'callback_data' => "roy"], ['text' => "🗑 O'chirish", 'callback_data' => "ochir"]],
                    [['text' => "◀️ Orqaga",   'callback_data' => "majburiy"]],
                ]])]);
            break;

        case 'royxati':
            if (!$isAdmCb) break;
            $c1 = fget(BASE_DIR . '/channel.txt');
            $t3 = $c1 ? "<b>🌐 Ommaviy kanallar:</b>\n$c1" : "<b>Ommaviy kanallar ulanmagan.</b>";
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => $t3, 'parse_mode' => 'html', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 Orqaga", 'callback_data' => "ommav"]]]])]);
            break;

        case 'roy':
            if (!$isAdmCb) break;
            $c2r  = fget(BASE_DIR . '/channel2.txt');
            $rowsr = array_values(array_filter(explode("\n", $c2r)));
            $t4   = "<b>🔒 Maxfiy kanallar:</b>\n";
            for ($i = 0; $i < count($rowsr) - 1; $i += 2) $t4 .= "🔹 <code>" . trim($rowsr[$i]) . "</code>\n";
            if (count($rowsr) < 2) $t4 = "<b>Maxfiy kanallar ulanmagan.</b>";
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => $t4, 'parse_mode' => 'html', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 Orqaga", 'callback_data' => "maxfiy"]]]])]);
            break;

        case 'qoshish':
            if (!$isAdmCb) break;
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<b>📢 Kanal username ni yuboring:\n\n📄 Namuna:</b> <code>@KanalNomi</code>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
            setStep($cmcid, 'add-pub-channel');
            break;

        case 'ochirish':
            if (!$isAdmCb) break;
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<b>🗑 O'chiriladigan kanal username ni yuboring:\n\n📄 Namuna:</b> <code>@KanalNomi</code>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
            setStep($cmcid, 'remove-pub-channel');
            break;

        case 'qosh':
            if (!$isAdmCb) break;
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<i>⚠️ Botni kanalingizga admin qiling!\n\n</i><b>📄 Namuna:</b>\n<code>https://t.me/+ZEcQiRY_pRph\n-100326189432</code>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
            setStep($cmcid, 'add-chanel');
            break;

        case 'ochir':
            if (!$isAdmCb) break;
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<b>🗑 O'chiriladigan maxfiy kanal:</b>\n\n📄 <b>Namuna:</b>\n<code>https://t.me/+ZEcQiRY_pRph\n-100326189432</code>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
            setStep($cmcid, 'remove-secret-channel');
            break;

        case 'qoshimcha':
            if (!$isAdmCb) break;
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid,
                'text'       => "<b>🎬 Hozirgi kino kanali:</b> " . ($kino_ch ?: "Qo'shilmagan"),
                'parse_mode' => 'html',
                'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => "📝 O'zgartirish", 'callback_data' => "kinokanal"]],
                    [['text' => "◀️ Orqaga",         'callback_data' => "kanallar"]],
                ]])]);
            break;

        case 'kinokanal':
            if (!$isAdmCb) break;
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<blockquote>⚠️ Avval botni kanalga admin qiling!</blockquote>\n\n📄 <b>Namuna:</b> <code>@KanalNomi</code>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
            setStep($cmcid, 'add-channl');
            break;

        case 'oddiyk':
            if (!$isAdmCb) break;
            if (empty($kino_ch)) {
                bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<b>⚠️ Kino kanali qo'shilmagan!</b>", 'parse_mode' => 'html']); break;
            }
            $kf  = BASE_DIR . '/kino/kodi.txt';
            if (!file_exists($kf)) fput($kf, '0');
            $nkod = (int)fget($kf) + 1;
            fput($kf, (string)$nkod);
            fput(BASE_DIR . '/step/new_kino.txt', (string)$nkod);
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<i>📸 Kino uchun rasm yuboring:</i>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
            setStep($cmcid, 'rasm');
            break;

        // Statistika
        case 'stat':
            if (!$isAdmCb) break;
            $total = substr_count(fget(BASE_DIR . '/azo.dat'), "\n");
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid,
                'text'       => "<b>📊 Statistika\n\n✅ Jami: $total ta</b>", 'parse_mode' => 'html',
                'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "📅 Kunlik", 'callback_data' => "kunlik"], ['text' => "📆 Haftalik", 'callback_data' => "haftalik"], ['text' => "📊 Oylik", 'callback_data' => "oylik"]]]])]);
            break;

        case 'kunlik':
            if (!$isAdmCb) break;
            $ud  = BASE_DIR . '/users';
            $fls = is_dir($ud) ? array_diff(scandir($ud), ['.', '..']) : [];
            $counts = [];
            for ($i = 0; $i < 6; $i++) $counts[date('d.m.Y', strtotime("-{$i} days"))] = 0;
            foreach ($fls as $f2) { $s = fget("$ud/$f2"); if (isset($counts[$s])) $counts[$s]++; }
            $msg = "<b>📅 Kunlik statistika:</b>\n<blockquote>";
            $idx = 0;
            foreach ($counts as $d => $c) { $lbl = $idx === 0 ? "Bugun" : ($idx === 1 ? "Kecha" : "$idx kun oldin"); $msg .= "🔹 $lbl: $c ta\n"; $idx++; }
            $msg .= "</blockquote>";
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => $msg, 'parse_mode' => 'html', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "⬅️ Ortga", 'callback_data' => "stat"]]]])]);
            break;

        case 'haftalik':
            if (!$isAdmCb) break;
            $ud  = BASE_DIR . '/users';
            $fls = is_dir($ud) ? array_diff(scandir($ud), ['.', '..']) : [];
            $wc  = [0, 0, 0];
            $w0  = (int)date('W');
            foreach ($fls as $f2) {
                $s = fget("$ud/$f2");
                $dt = strtotime(str_replace('.', '-', $s));
                if ($dt) { $diff = $w0 - (int)date('W', $dt); if ($diff >= 0 && $diff < 3) $wc[$diff]++; }
            }
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid,
                'text'       => "<b>📆 Haftalik statistika:</b>\n<blockquote>🔹 Shu hafta: {$wc[0]} ta\n🔹 O'tgan hafta: {$wc[1]} ta\n🔹 2 hafta oldin: {$wc[2]} ta</blockquote>",
                'parse_mode' => 'html', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "⬅️ Ortga", 'callback_data' => "stat"]]]])]);
            break;

        case 'oylik':
            if (!$isAdmCb) break;
            $ud  = BASE_DIR . '/users';
            $fls = is_dir($ud) ? array_diff(scandir($ud), ['.', '..']) : [];
            $mc  = [0, 0, 0];
            $m0  = date('m.Y'); $m1 = date('m.Y', strtotime('-1 month')); $m2 = date('m.Y', strtotime('-2 months'));
            foreach ($fls as $f2) {
                $s = fget("$ud/$f2");
                $mo = date('m.Y', strtotime(str_replace('.', '-', $s)));
                if ($mo === $m0) $mc[0]++; elseif ($mo === $m1) $mc[1]++; elseif ($mo === $m2) $mc[2]++;
            }
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid,
                'text'       => "<b>📊 Oylik statistika:</b>\n<blockquote>🔹 Shu oy: {$mc[0]} ta\n🔹 O'tgan oy: {$mc[1]} ta\n🔹 2 oy oldin: {$mc[2]} ta</blockquote>",
                'parse_mode' => 'html', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "⬅️ Ortga", 'callback_data' => "stat"]]]])]);
            break;

        // Bot holati
        case 'xolat':
            if (!$isAdmCb) break;
            $h  = fget($holat_f, 'Yoqilgan');
            $st = $h === 'Yoqilgan' ? "✅ Yoqilgan" : "❌ O'chirilgan";
            $bn = $h === 'Yoqilgan' ? "❌ O'chirish"  : "✅ Yoqish";
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => "<b>📄 Bot holati:</b> $st", 'parse_mode' => 'html', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => $bn, 'callback_data' => "bot"]]]])]);
            break;

        case 'bot':
            if (!$isAdmCb) break;
            $h = fget($holat_f, 'Yoqilgan');
            fput($holat_f, $h === 'Yoqilgan' ? "O'chirilgan" : 'Yoqilgan');
            $new = $h === 'Yoqilgan' ? "❌ O'chirilgan" : "✅ Yoqilgan";
            bot('editMessageText', ['chat_id' => $cmcid, 'message_id' => $cmmid, 'text' => "<b>✅ Bot holati: $new</b>", 'parse_mode' => 'html', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "◀️ Orqaga", 'callback_data' => "xolat"]]]])]);
            break;

        // Xabarnoma
        case 'send':
            if (!$isAdmCb) break;
            $uc = count(array_filter(file(BASE_DIR . '/azo.dat') ?: []));
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<b>📨 $uc ta foydalanuvchiga oddiy xabar yuboring:</b>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
            setStep($cmcid, 'sendpost');
            break;

        case 'send2':
            if (!$isAdmCb) break;
            $uc = count(array_filter(file(BASE_DIR . '/azo.dat') ?: []));
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<b>📨 $uc ta foydalanuvchiga forward xabar yuboring:</b>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
            setStep($cmcid, 'sendfwrd');
            break;

        case 'user':
            if (!$isAdmCb) break;
            bot('deleteMessage', ['chat_id' => $cmcid, 'message_id' => $cmmid]);
            bot('sendMessage', ['chat_id' => $cmcid, 'text' => "<b>📝 Foydalanuvchi ID sini kiriting:</b>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
            setStep($cmcid, 'user-msg');
            break;
    }

    http_response_code(200); exit();
}

// ============================================================
//  💬  MESSAGE HANDLER
// ============================================================
if (isset($update->message)) {
    $msg     = $update->message;
    $cid     = (string)$msg->chat->id;
    $uid     = (string)$msg->from->id;
    $name    = $msg->chat->first_name ?? '';
    $surname = $msg->from->last_name ?? '';
    $uname   = $msg->from->username ?? '';
    $text    = $msg->text ?? '';
    $mid     = $msg->message_id;
    $caption = $msg->caption ?? '';
    $photo   = $msg->photo ?? null;
    $video   = $msg->video ?? null;
    $step    = getStep($cid);
    $nlink   = "<a href='tg://user?id=$uid'>$name $surname</a>";
    $isAdm   = in_array($cid, $admins);

    addUser($uid, $name, $uname);

    // Bot o'chirilgan
    if ($holat !== 'Yoqilgan' && !$isAdm) {
        bot('sendMessage', ['chat_id' => $cid, 'text' => "⛔️ <b>Bot vaqtinchalik o'chirilgan!</b>", 'parse_mode' => 'html']);
        http_response_code(200); exit();
    }

    // ---- /start ----
    if (str_starts_with($text, '/start')) {
        $parts = explode(' ', $text);
        $kod   = trim($parts[1] ?? '');
        setStep($cid);

        if ($kod !== '' && checkSub($uid)) {
            $kdir = BASE_DIR . "/kino/$kod";
            if (is_dir($kdir)) {
                $nomi   = fget("$kdir/nomi.txt");
                $vid_id = fget("$kdir/film.txt");
                $dc     = (int)fget("$kdir/downcount.txt", '0') + 1;
                fput("$kdir/downcount.txt", (string)$dc);
                if ($vid_id) {
                    bot('sendVideo', [
                        'chat_id'      => $cid, 'video' => $vid_id,
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

        if (checkSub($uid)) {
            $inline = [[['text' => "🔎 Kino kodlari", 'url' => "https://t.me/" . ltrim($kino_ch, '@')]]];
            if ($isAdm) $inline[] = [['text' => "🗄 Boshqaruv paneli", 'callback_data' => "boshqar"]];
            bot('sendMessage', [
                'chat_id'      => $cid,
                'text'         => "🖐 <b>Assalomu alaykum, $nlink!\n\n<blockquote>📋 Buyruqlar:\n/start — Botni qayta ishga tushirish\n/help — Yordam</blockquote>\n\n🔎 Kino kodini yuboring:</b>",
                'parse_mode'   => 'html',
                'disable_web_page_preview' => true,
                'reply_markup' => json_encode(['inline_keyboard' => $inline]),
            ]);
        }
        http_response_code(200); exit();
    }

    // ---- /help ----
    if ($text === '/help') {
        bot('sendMessage', [
            'chat_id'      => $cid,
            'text'         => "💻 <b>Savol va takliflar uchun:</b>",
            'parse_mode'   => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "☎️ Qo'llab-quvvatlash", 'url' => "tg://user?id=" . OWNER_ID]]]]),
        ]);
        http_response_code(200); exit();
    }

    // ---- Orqaga ----
    if ($text === "◀️ Orqaga") {
        setStep($cid);
        bot('sendMessage', ['chat_id' => $cid, 'text' => "🖐 <b>$nlink\n\n🔎 Kino kodini yuboring:</b>", 'parse_mode' => 'html', 'reply_markup' => $KB_REPLY]);
        http_response_code(200); exit();
    }

    // ---- Admin panel ----
    if (($text === "🗄 Boshqaruv paneli" || $text === '/panel') && $isAdm) {
        setStep($cid);
        bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>🖥️ Admin paneliga xush kelibsiz!</b>", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
        http_response_code(200); exit();
    }

    // ============================================================
    //  ADMIN STEPS
    // ============================================================

    if ($step === 'add-admin' && $cid === OWNER_ID) {
        if (is_numeric(trim($text))) {
            $af  = BASE_DIR . '/tizim/admins.txt';
            $al  = array_unique(array_filter(array_map('trim', explode("\n", fget($af)))));
            if (!in_array(trim($text), $al)) fput($af, "\n" . trim($text), true);
            bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ Admin qo'shildi: <code>$text</code>", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
            setStep($cid);
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "❗ Faqat ID raqam yuboring!", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }

    if ($step === 'remove-admin' && $cid === OWNER_ID) {
        $af  = BASE_DIR . '/tizim/admins.txt';
        $al  = array_filter(array_map('trim', explode("\n", fget($af))));
        $new = array_filter($al, fn($a) => $a !== trim($text));
        fput($af, implode("\n", $new));
        bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ Admin o'chirildi: <code>$text</code>", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
        setStep($cid);
        http_response_code(200); exit();
    }

    if ($step === 'add-pub-channel' && $isAdm) {
        if (mb_stripos($text, '@') !== false) {
            $f  = BASE_DIR . '/channel.txt';
            $cl = fget($f);
            if (mb_stripos($cl, trim($text)) === false) fput($f, ($cl ? $cl . "\n" : '') . trim($text));
            bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ $text qo'shildi.", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
            setStep($cid);
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "❗ <code>@KanalNomi</code> formatida yuboring.", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }

    if ($step === 'remove-pub-channel' && $isAdm) {
        $f   = BASE_DIR . '/channel.txt';
        $cl  = array_filter(array_map('trim', explode("\n", fget($f))));
        $new = array_filter($cl, fn($c) => $c !== trim($text));
        fput($f, implode("\n", $new));
        bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ $text o'chirildi.", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
        setStep($cid);
        http_response_code(200); exit();
    }

    if ($step === 'add-chanel' && $isAdm) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
        if (count($lines) === 2 && mb_stripos($lines[0], 'https://t.me/+') !== false && mb_stripos($lines[1], '-100') !== false) {
            fput(BASE_DIR . "/tizim/{$lines[1]}.txt", '');
            $f  = BASE_DIR . '/channel2.txt';
            $cl = fget($f);
            fput($f, ($cl ? $cl . "\n" : '') . $lines[0] . "\n" . $lines[1]);
            bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ Maxfiy kanal qo'shildi.", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
            setStep($cid);
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "❗ Format:\n<code>https://t.me/+...\n-100...</code>", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }

    if ($step === 'remove-secret-channel' && $isAdm) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
        if (count($lines) === 2) {
            $f    = BASE_DIR . '/channel2.txt';
            $rows = array_values(array_filter(array_map('trim', explode("\n", fget($f)))));
            $new  = [];
            for ($i = 0; $i < count($rows) - 1; $i += 2) {
                if ($rows[$i] === $lines[0] && $rows[$i + 1] === $lines[1]) continue;
                $new[] = $rows[$i]; $new[] = $rows[$i + 1];
            }
            fput($f, implode("\n", $new));
            @unlink(BASE_DIR . "/tizim/{$lines[1]}.txt");
            bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ Maxfiy kanal o'chirildi.", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
            setStep($cid);
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "❗ Format:\n<code>https://t.me/+...\n-100...</code>", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }

    if ($step === 'add-channl' && $isAdm) {
        if (mb_stripos($text, '@') !== false) {
            $get = bot('getChat', ['chat_id' => $text]);
            if (isset($get->result)) {
                $chun = $get->result->username ?? '';
                if (isBotAdmin($chun)) {
                    fput(BASE_DIR . '/kino_ch.txt', $text);
                    bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ $text kino kanali qo'shildi.", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
                    setStep($cid);
                } else {
                    bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>⚠️ Bot ushbu kanalda admin emas!</b>", 'parse_mode' => 'html',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🛡 Admin qilish", 'url' => "https://t.me/$bot_username?startchannel=on"]]]]),
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

    // Kino yuklash — rasm
    if ($step === 'rasm' && $photo && $isAdm) {
        $k    = fget(BASE_DIR . '/step/new_kino.txt', '1');
        $kdir = BASE_DIR . "/kino/$k";
        if (!is_dir($kdir)) @mkdir($kdir, 0755, true);
        fput("$kdir/rasm.txt", end($photo)->file_id);
        setStep($cid, 'kinoo');
        bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ <i>Rasm saqlandi!\n\n🎬 Endi filmni caption bilan yuboring:</i>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
        http_response_code(200); exit();
    }

    // Kino yuklash — video
    if ($step === 'kinoo' && $video && $isAdm) {
        $k    = fget(BASE_DIR . '/step/new_kino.txt', '1');
        $kdir = BASE_DIR . "/kino/$k";
        if (!is_dir($kdir)) @mkdir($kdir, 0755, true);
        fput("$kdir/nomi.txt", $caption);
        fput("$kdir/film.txt", $video->file_id);
        $rasm = fget("$kdir/rasm.txt");

        $msgr = bot('sendPhoto', [
            'chat_id'      => $kino_ch,
            'photo'        => $rasm,
            'caption'      => "<b>🍿 Yangi film!\n\n🎞 Film:\n<blockquote>$caption</blockquote>\n\n🔢 Kodi: <code>$k</code>\n\n🤖 Bot: @$bot_username</b>",
            'parse_mode'   => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "📥 Yuklab olish", 'url' => "https://t.me/$bot_username?start=$k"]]]]),
        ]);
        $sf  = BASE_DIR . '/kino/son.txt';
        fput($sf, (string)((int)fget($sf) + 1));
        $pmid   = $msgr->result->message_id ?? 0;
        $chclean = ltrim($kino_ch, '@');

        bot('sendMessage', ['chat_id' => $cid, 'text' => "<blockquote>✅ Film joylandi!</blockquote>\n\n🔢 Kod: <code>$k</code>", 'parse_mode' => 'html',
            'reply_to_message_id' => $mid,
            'reply_markup'        => json_encode(['inline_keyboard' => [[['text' => "📢 Ko'rish", 'url' => "https://t.me/$chclean/$pmid"]]]]),
        ]);
        setStep($cid);
        bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>✅ Admin panelga qaytdingiz.</b>", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
        http_response_code(200); exit();
    }

    // Admin panel tugmalari
    if ($text === "📢 Kanallar" && $isAdm) {
        bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>📢 Kanallar:</b>", 'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => "📢 Majburiy obuna", 'callback_data' => "majburiy"]],
                [['text' => "🎬 Kino kanali",    'callback_data' => "qoshimcha"]],
            ]])]);
        http_response_code(200); exit();
    }

    if ($text === "📥 Kino Yuklash" && $isAdm) {
        bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>⁉️ Kino yuklash usuli:</b>", 'parse_mode' => 'html', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "✅ Rasm + Video", 'callback_data' => "oddiyk"]]]])]);
        http_response_code(200); exit();
    }

    if ($text === "✉ Xabarnoma" && $isAdm) {
        bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>❗ Xabar turini tanlang:</b>", 'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => "💠 Oddiy xabar",  'callback_data' => "send"], ['text' => "💠 Forward",      'callback_data' => "send2"]],
                [['text' => "👤 Userga xabar",  'callback_data' => "user"], ['text' => "❌ Yopish",        'callback_data' => "bosh"]],
            ]])]);
        http_response_code(200); exit();
    }

    if ($text === "📊 Statistika" && $isAdm) {
        $total = substr_count(fget(BASE_DIR . '/azo.dat'), "\n");
        bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>📊 Statistika\n\n✅ Jami: $total ta</b>", 'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "📅 Kunlik", 'callback_data' => "kunlik"], ['text' => "📆 Haftalik", 'callback_data' => "haftalik"], ['text' => "📊 Oylik", 'callback_data' => "oylik"]]]])]);
        http_response_code(200); exit();
    }

    if ($text === "🤖 Bot holati" && $isAdm) {
        $h  = fget($holat_f, 'Yoqilgan');
        $st = $h === 'Yoqilgan' ? "✅ Yoqilgan" : "❌ O'chirilgan";
        $bn = $h === 'Yoqilgan' ? "❌ O'chirish"  : "✅ Yoqish";
        bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>📄 Bot holati:</b> $st", 'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => $bn, 'callback_data' => "bot"]]]])]);
        http_response_code(200); exit();
    }

    if ($text === "👥 Adminlar" && $isAdm) {
        $kb = ($cid === OWNER_ID)
            ? ['inline_keyboard' => [[['text' => "➕ Admin qo'shish", 'callback_data' => "add"]], [['text' => "📑 Ro'yxat", 'callback_data' => "list"], ['text' => "🗑 O'chirish", 'callback_data' => "remove"]]]]
            : ['inline_keyboard' => [[['text' => "📑 Ro'yxat", 'callback_data' => "list"]]]];
        bot('sendMessage', ['chat_id' => $cid, 'text' => "👮 <b>Adminlar:</b>", 'parse_mode' => 'html', 'reply_markup' => json_encode($kb)]);
        http_response_code(200); exit();
    }

    // Xabarnoma — userga
    if ($step === 'user-msg' && $isAdm) {
        if (is_numeric(trim($text))) {
            fput(BASE_DIR . '/step/target_user.txt', trim($text));
            setStep($cid, 'xabar');
            bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>📝 Xabar matnini yuboring:</b>", 'parse_mode' => 'html', 'reply_markup' => $KB_MAIN]);
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>Faqat ID raqam!</b>", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }

    if ($step === 'xabar' && $isAdm) {
        $tgt = fget(BASE_DIR . '/step/target_user.txt');
        bot('sendMessage', ['chat_id' => $tgt, 'text' => "<b>📩 Yangi xabar:\n\n</b>$text", 'parse_mode' => 'html']);
        bot('sendMessage', ['chat_id' => $cid, 'text' => "✅ Xabar yuborildi!", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
        setStep($cid);
        @unlink(BASE_DIR . '/step/target_user.txt');
        http_response_code(200); exit();
    }

    // Xabarnoma — barchaga oddiy
    if ($step === 'sendpost' && $isAdm) {
        setStep($cid);
        $users = file(BASE_DIR . '/azo.dat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $ok = $err = 0;
        bot('sendMessage', ['chat_id' => $cid, 'text' => "🔄 <b>Yuborilmoqda...</b>", 'parse_mode' => 'html']);
        foreach ($users as $u) {
            $u = trim($u); if (!$u) continue;
            $r = bot('copyMessage', ['from_chat_id' => $cid, 'chat_id' => $u, 'message_id' => $mid]);
            ($r && $r->ok) ? $ok++ : $err++;
            usleep(50000);
        }
        bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>✅ Yuborildi!\n\n✅ Muvaffaqiyatli: $ok\n❌ Xato: $err</b>", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
        http_response_code(200); exit();
    }

    // Xabarnoma — barchaga forward
    if ($step === 'sendfwrd' && $isAdm) {
        setStep($cid);
        $users = file(BASE_DIR . '/azo.dat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $ok = $err = 0;
        bot('sendMessage', ['chat_id' => $cid, 'text' => "🔄 <b>Yuborilmoqda...</b>", 'parse_mode' => 'html']);
        foreach ($users as $u) {
            $u = trim($u); if (!$u) continue;
            $r = bot('forwardMessage', ['from_chat_id' => $cid, 'chat_id' => $u, 'message_id' => $mid]);
            ($r && $r->ok) ? $ok++ : $err++;
            usleep(50000);
        }
        bot('sendMessage', ['chat_id' => $cid, 'text' => "<b>✅ Forward yuborildi!\n\n✅ Muvaffaqiyatli: $ok\n❌ Xato: $err</b>", 'parse_mode' => 'html', 'reply_markup' => $KB_PANEL]);
        http_response_code(200); exit();
    }

    // ---- Kino kodi (foydalanuvchi raqam yuborsa) ----
    if (is_numeric($text) && empty($step) && checkSub($uid)) {
        $kod  = $text;
        $kdir = BASE_DIR . "/kino/$kod";
        if (is_dir($kdir)) {
            $nomi   = fget("$kdir/nomi.txt");
            $vid_id = fget("$kdir/film.txt");
            $dc     = (int)fget("$kdir/downcount.txt", '0') + 1;
            fput("$kdir/downcount.txt", (string)$dc);
            if ($vid_id) {
                bot('sendVideo', [
                    'chat_id'      => $cid, 'video' => $vid_id,
                    'caption'      => "<b>🍿 Kino:\n<blockquote>$nomi</blockquote>\n\n🔰 Kanal: $kino_ch\n🗂 Yuklashlar: $dc\n\n🤖 Bot: @$bot_username</b>",
                    'parse_mode'   => 'html',
                    'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => "🔎 Kino kodlari", 'url' => "https://t.me/" . ltrim($kino_ch, '@')]],
                        [['text' => "📋 Ulashish", 'url' => "https://t.me/share/url?url=https://t.me/$bot_username?start=$kod"]],
                    ]]),
                ]);
            } else {
                bot('sendMessage', ['chat_id' => $cid, 'text' => "❌ <b>Film topilmadi.</b>", 'parse_mode' => 'html']);
            }
        } else {
            bot('sendMessage', ['chat_id' => $cid, 'text' => "❌ <b>Bunday kod mavjud emas.</b>", 'parse_mode' => 'html']);
        }
        http_response_code(200); exit();
    }
}

http_response_code(200);
