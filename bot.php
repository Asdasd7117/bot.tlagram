<?php
// bot.php - Telegram NFT mock bot (PHP, SQLite, Inline Keyboard)
// Works WITHOUT blockchain (mint & buy are simulated)

// ---------- CONFIG ----------
$BOT_TOKEN = getenv('BOT_TOKEN') ?: '8377372254:AAEs6EgmP90WcVjiX29v3x5IQRcnGRBgHM4';
$BASE_URL   = getenv('BASE_URL') ?: ''; // optional: https://yourdomain.com -- used when returning local image URLs
$API_URL    = "https://api.telegram.org/bot{$BOT_TOKEN}/";
$DB_FILE    = __DIR__ . '/nft_bot.db';
$IMAGES_DIR = __DIR__ . '/images';
@mkdir($IMAGES_DIR, 0755, true);

// ---------- DB init ----------
function db() {
    global $DB_FILE;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO("sqlite:$DB_FILE");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // create tables
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tg_id INTEGER UNIQUE,
            username TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS assets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_user_id INTEGER,
            name TEXT,
            data_json TEXT,
            image_url TEXT,
            onchain_token_id TEXT,
            listed_price FLOAT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }
    return $pdo;
}

// ---------- Helpers: Telegram API ----------
function sendAPI($method, $params = [], $is_multipart = false) {
    global $API_URL;
    $url = $API_URL . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($is_multipart) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $res = curl_exec($ch);
    if ($res === false) {
        error_log("CURL ERROR: " . curl_error($ch));
    }
    curl_close($ch);
    $json = json_decode($res, true);
    return $json;
}

function sendMessage($chat_id, $text, $reply_markup = null) {
    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($reply_markup) $params['reply_markup'] = json_encode($reply_markup);
    return sendAPI('sendMessage', $params);
}

function sendPhotoByPath($chat_id, $photo_path, $caption = '', $reply_markup = null) {
    // $photo_path must be local path
    if (!file_exists($photo_path)) return null;
    $params = [
        'chat_id' => $chat_id,
        'photo' => new CURLFile(realpath($photo_path)),
        'caption' => $caption
    ];
    if ($reply_markup) $params['reply_markup'] = json_encode($reply_markup);
    return sendAPI('sendPhoto', $params, true);
}

// ---------- Inline keyboard helpers ----------
function inlineButton($text, $callback_data) {
    return ['text' => $text, 'callback_data' => $callback_data];
}
function inlineKeyboard($rows) {
    return ['inline_keyboard' => $rows];
}

// ---------- IPFS or local URL helper ----------
function localOrPublicUrl($local_path) {
    global $BASE_URL, $IMAGES_DIR;
    // local_path is filesystem path; convert to URL if BASE_URL set
    $name = basename($local_path);
    if ($BASE_URL) return rtrim($BASE_URL, '/') . '/images/' . $name;
    return 'file://' . $local_path;
}

// ---------- Read incoming update ----------
$raw = file_get_contents('php://input');
if (!$raw) {
    // health check:
    echo "ok";
    exit;
}
$update = json_decode($raw, true);
if (!$update) {
    http_response_code(400);
    echo "bad request";
    exit;
}

// ---------- Callback queries (buttons) ----------
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $from = $cq['from'];
    $data = $cq['data'];
    $callback_id = $cq['id'];
    // handle buy button: buy_{asset_id}
    if (str_starts_with($data, 'buy_')) {
        $asset_id = intval(substr($data, 4));
        $pdo = db();
        // get asset
        $stmt = $pdo->prepare("SELECT owner_user_id, listed_price FROM assets WHERE id = ?");
        $stmt->execute([$asset_id]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$asset) {
            sendAPI('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'الأصل غير موجود', 'show_alert' => false]);
            exit;
        }
        // get buyer id
        $stmt = $pdo->prepare("SELECT id FROM users WHERE tg_id = ?");
        $stmt->execute([$from['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            sendAPI('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'أنت غير مسجل. استخدم /start', 'show_alert' => true]);
            exit;
        }
        $buyer_id = $user['id'];
        if ($asset['owner_user_id'] == $buyer_id) {
            sendAPI('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'لا يمكنك شراء أصل تملكه', 'show_alert' => false]);
            exit;
        }
        // perform transfer (DB only)
        $stmt = $pdo->prepare("UPDATE assets SET owner_user_id = ? WHERE id = ?");
        $stmt->execute([$buyer_id, $asset_id]);
        sendAPI('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'تم الشراء بنجاح ✅', 'show_alert' => false]);
        // notify buyer
        sendMessage($from['id'], "لقد اشتريت الأصل بنجاح! Asset ID: $asset_id\nالسعر: " . ($asset['listed_price'] ?? 0));
        exit;
    }
    // other callbacks: menu etc.
    if ($data === 'menu_profile') {
        // reuse /profile logic: call as if message
        $chat_id = $cq['message']['chat']['id'];
        // simulate message flow by redirect to code below by constructing a small update? Simpler: return quick text and instruct to press /profile
        sendAPI('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'اضغط /profile لعرض أصولك', 'show_alert' => false]);
        exit;
    }
    // unknown callback
    sendAPI('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'عملية غير معروفة', 'show_alert' => false]);
    exit;
}

// ---------- Message handling ----------
$msg = $update['message'] ?? null;
if (!$msg) { echo "ok"; exit; }
$text = $msg['text'] ?? '';
$chat_id = $msg['chat']['id'];
$from = $msg['from'];
$tg_id = $from['id'];
$username = $from['username'] ?? ($from['first_name'] ?? '');

// helper: show main menu keyboard
function mainMenuKeyboard() {
    return inlineKeyboard([
        [ inlineButton("🧾 ملفي (/profile)", "menu_profile"), inlineButton("🎨 اصنع NFT (/mint)", "menu_mint") ],
        [ inlineButton("🛒 السوق (/market)", "menu_market") ]
    ]);
}

// parse command (case-insensitive)
$parts = preg_split('/\s+/', trim($text));
$cmd = strtolower($parts[0] ?? '');

// ---------- /start ----------
if ($cmd === '/start') {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO users (tg_id, username) VALUES (?, ?)");
    $stmt->execute([$tg_id, $username]);
    $reply = "👋 أهلاً @$username!\nمرحباً في بوت NFT التجريبي.\nاستخدم الأزرار أو الأوامر.";
    sendMessage($chat_id, $reply, mainMenuKeyboard());
    exit;
}

// ---------- /profile ----------
if ($cmd === '/profile') {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE tg_id = ?");
    $stmt->execute([$tg_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) { sendMessage($chat_id, "أنت غير مسجل. استخدم /start"); exit; }
    $user_id = $u['id'];
    $stmt = $pdo->prepare("SELECT id, name, image_url, listed_price FROM assets WHERE owner_user_id = ?");
    $stmt->execute([$user_id]);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$assets) { sendMessage($chat_id, "ليس لديك أي أصول حتى الآن."); exit; }
    foreach ($assets as $a) {
        $caption = "Name: {$a['name']}\nID: {$a['id']}\nPrice: " . ($a['listed_price'] ?? 0);
        if (str_starts_with($a['image_url'], 'file://')) {
            $local = substr($a['image_url'], 7);
            if (file_exists($local)) {
                sendPhotoByPath($chat_id, $local, $caption);
                continue;
            }
        }
        // fallback: send text + url
        sendMessage($chat_id, $caption . "\n" . $a['image_url']);
    }
    exit;
}

// ---------- /mint ----------
// create simple PNG, save, upload locally (no blockchain)
if ($cmd === '/mint') {
    $pdo = db();
    // ensure user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE tg_id = ?");
    $stmt->execute([$tg_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) { sendMessage($chat_id, "أنت غير مسجل. استخدم /start"); exit; }
    $user_id = $u['id'];

    // create image
    $w = 512; $h = 512;
    $img = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($img, rand(0,255), rand(0,255), rand(0,255));
    imagefilledrectangle($img, 0, 0, $w, $h, $bg);
    $white = imagecolorallocate($img, 255,255,255);
    $text_str = "NFT-" . time();
    // center text using imagestring (simple)
    imagestring($img, 5, 10, $h/2 - 8, $text_str, $white);
    $fname = $IMAGES_DIR . '/nft_' . $tg_id . '_' . time() . '.png';
    imagepng($img, $fname);
    imagedestroy($img);

    // image url (local or BASE_URL)
    $img_url = localOrPublicUrl($fname);

    // simulated token id
    $token_id = time() . rand(100,999);

    // insert asset
    $stmt = $pdo->prepare("INSERT INTO assets (owner_user_id, name, data_json, image_url, onchain_token_id, listed_price, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, "NFT-{$token_id}", json_encode([]), $img_url, (string)$token_id, 0.0, date('c')]);

    // send photo with success and main menu
    $kb = inlineKeyboard([[ inlineButton("↩️ القائمة الرئيسية", "menu_profile") ]]);
    sendPhotoByPath($chat_id, $fname, "✅ تم إنشاء الأصل التجريبي!\nToken ID: $token_id\n(محلي)", $kb);
    exit;
}

// ---------- /market ----------
if ($cmd === '/market') {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, name, image_url, listed_price FROM assets WHERE listed_price > 0 ORDER BY created_at DESC LIMIT 10");
    $stmt->execute();
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$assets) { sendMessage($chat_id, "لا يوجد أصول معروضة للبيع."); exit; }
    foreach ($assets as $a) {
        $caption = "ID: {$a['id']}\nName: {$a['name']}\nPrice: {$a['listed_price']}";
        $kb = inlineKeyboard([[ inlineButton("اشتري", "buy_".$a['id']) ]]);
        if (str_starts_with($a['image_url'], 'file://')) {
            $local = substr($a['image_url'], 7);
            if (file_exists($local)) {
                sendPhotoByPath($chat_id, $local, $caption, $kb);
                continue;
            }
        }
        sendMessage($chat_id, $caption . "\n" . $a['image_url'], $kb);
    }
    exit;
}

// ---------- /list (usage: /list <asset_id> <price>) ----------
if ($cmd === '/list') {
    $args = array_slice($parts, 1);
    if (count($args) < 2) { sendMessage($chat_id, "استخدام: /list <asset_id> <price>"); exit; }
    $asset_id = intval($args[0]); $price = floatval($args[1]);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE tg_id = ?");
    $stmt->execute([$tg_id]); $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) { sendMessage($chat_id, "أنت غير مسجل."); exit; }
    $user_id = $u['id'];
    $stmt = $pdo->prepare("SELECT owner_user_id FROM assets WHERE id = ?");
    $stmt->execute([$asset_id]); $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) { sendMessage($chat_id, "الأصل غير موجود."); exit; }
    if ($a['owner_user_id'] != $user_id) { sendMessage($chat_id, "لا يمكنك عرض أصل ليس ملكك."); exit; }
    $stmt = $pdo->prepare("UPDATE assets SET listed_price = ? WHERE id = ?");
    $stmt->execute([$price, $asset_id]);
    sendMessage($chat_id, "تم عرض الأصل للبيع بسعر $price ETH ✅");
    exit;
}

// ---------- /admin (simple) ----------
if ($cmd === '/admin') {
    // For simplicity, set your Telegram ID(s) here or use env var ADMIN_IDS comma-separated
    $ADMIN_IDS = array_map('intval', explode(',', getenv('ADMIN_IDS') ?: ''));
    if (!in_array($tg_id, $ADMIN_IDS)) {
        sendMessage($chat_id, "❌ ليس لديك صلاحيات الأدمن.");
        exit;
    }
    $pdo = db();
    $users = $pdo->query("SELECT id, tg_id, username, created_at FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $assets = $pdo->query("SELECT id, name, owner_user_id, image_url, listed_price FROM assets ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $text = "📋 لوحة تحكم الأدمن\n\nUsers:\n";
    foreach ($users as $u) $text .= "- {$u['username']} ({$u['tg_id']})\n";
    $text .= "\nAssets:\n";
    foreach ($assets as $a) $text .= "- ID {$a['id']} | {$a['name']} | Owner: {$a['owner_user_id']} | Price: {$a['listed_price']}\n";
    sendMessage($chat_id, $text);
    exit;
}

// ---------- default: show main menu ----------
sendMessage($chat_id, "أوامر البوت:\n/start - ابدأ\n/profile - ملفي\n/mint - صنع NFT\n/list <id> <price> - عرض للبيع\n/market - السوق\n/admin - لوحة الأدمن (مقيد)", mainMenuKeyboard());
exit;
