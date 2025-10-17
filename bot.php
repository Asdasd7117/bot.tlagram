<?php
// bot.php
// Telegram webhook bot in PHP (SQLite, simple NFT mock, IPFS optional via Infura)

// ---------- CONFIG (ضع القيم في environment أو عدل هنا مباشرة لمختبر محلي) ----------
$BOT_TOKEN = getenv('BOT_TOKEN') ?: 'YOUR_BOT_TOKEN_HERE';
$INFURA_PROJECT_ID = getenv('INFURA_PROJECT_ID') ?: '';
$INFURA_PROJECT_SECRET = getenv('INFURA_PROJECT_SECRET') ?: '';
// Base URL of your hosted script (used to build image/public URLs if needed)
$BASE_URL = getenv('BASE_URL') ?: ''; // example: https://yourdomain.com

if ($BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE' || !$BOT_TOKEN) {
    error_log("BOT_TOKEN not set. Set BOT_TOKEN env var.");
    // don't exit: allow testing but you'll get errors calling Telegram
}

// ---------- CONSTANTS ----------
$API_URL = "https://api.telegram.org/bot{$BOT_TOKEN}/";
$DB_FILE = __DIR__ . '/nft_bot.db';
$IMAGES_DIR = __DIR__ . '/images';
@mkdir($IMAGES_DIR, 0755, true);

// ---------- DB: init if not exists ----------
function db() {
    global $DB_FILE;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO("sqlite:$DB_FILE");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // create tables if not exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tg_id INTEGER UNIQUE,
            username TEXT,
            created_at DATETIME
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS assets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_user_id INTEGER,
            name TEXT,
            data_json TEXT,
            image_url TEXT,
            onchain_token_id TEXT,
            listed_price FLOAT,
            created_at DATETIME
        )");
    }
    return $pdo;
}

// ---------- Helpers ----------
function sendRequest($method, $params = []) {
    global $API_URL;
    $url = $API_URL . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function sendMessage($chat_id, $text, $reply_markup = null) {
    $params = ['chat_id'=>$chat_id, 'text'=>$text, 'parse_mode'=>'HTML'];
    if ($reply_markup) $params['reply_markup'] = json_encode($reply_markup);
    return sendRequest('sendMessage', $params);
}

function sendPhotoByPath($chat_id, $photo_path, $caption = '') {
    $url = "https://api.telegram.org/bot" . getenv('BOT_TOKEN') . "/sendPhoto";
    $cfile = new CURLFile($photo_path);
    $post = ['chat_id'=>$chat_id, 'photo'=>$cfile, 'caption'=>$caption];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// IPFS upload via Infura (optional)
function uploadToIPFS($file_path) {
    global $INFURA_PROJECT_ID, $INFURA_PROJECT_SECRET;
    if (!$INFURA_PROJECT_ID || !$INFURA_PROJECT_SECRET) {
        // fallback: return local file:// URL or public URL if BASE_URL known
        global $BASE_URL;
        if ($BASE_URL) return rtrim($BASE_URL, '/') . '/images/' . basename($file_path);
        return 'file://' . $file_path;
    }
    $url = "https://ipfs.infura.io:5001/api/v0/add";
    $ch = curl_init($url);
    $cfile = new CURLFile($file_path);
    curl_setopt($ch, CURLOPT_USERPWD, $INFURA_PROJECT_ID . ":" . $INFURA_PROJECT_SECRET);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['file'=>$cfile]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    $arr = json_decode($res, true);
    if (isset($arr['Hash'])) return 'https://ipfs.io/ipfs/' . $arr['Hash'];
    return 'file://' . $file_path;
}

// Inline keyboard helper
function mkInlineKeyboard($buttons_grid) {
    return ['inline_keyboard' => $buttons_grid];
}

// ---------- Handlers ----------
$raw = file_get_contents("php://input");
if (!$raw) {
    // health check
    echo "ok";
    exit;
}
$update = json_decode($raw, true);
if (!$update) {
    // invalid input
    http_response_code(400);
    echo "bad request";
    exit;
}

// handle callback_query first
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $from = $cq['from'];
    $data = $cq['data'];
    $callback_id = $cq['id'];
    // example data: buy_123
    if (strpos($data, 'buy_') === 0) {
        $asset_id = intval(substr($data, 4));
        $pdo = db();
        // get asset
        $stmt = $pdo->prepare("SELECT owner_user_id, onchain_token_id, listed_price FROM assets WHERE id = ?");
        $stmt->execute([$asset_id]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$asset) {
            sendRequest('answerCallbackQuery', ['callback_query_id'=>$callback_id, 'text'=>'الأصل غير موجود', 'show_alert'=>false]);
            exit;
        }
        // find buyer user id
        $stmt = $pdo->prepare("SELECT id FROM users WHERE tg_id = ?");
        $stmt->execute([$from['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            sendRequest('answerCallbackQuery', ['callback_query_id'=>$callback_id, 'text'=>'أنت غير مسجل. استخدم /start', 'show_alert'=>false]);
            exit;
        }
        $buyer_id = $user['id'];
        if ($asset['owner_user_id'] == $buyer_id) {
            sendRequest('answerCallbackQuery', ['callback_query_id'=>$callback_id, 'text'=>'أنت المالك بالفعل', 'show_alert'=>false]);
            exit;
        }
        // update DB (transfer ownership)
        $stmt = $pdo->prepare("UPDATE assets SET owner_user_id = ? WHERE id = ?");
        $stmt->execute([$buyer_id, $asset_id]);
        sendRequest('answerCallbackQuery', ['callback_query_id'=>$callback_id, 'text'=>'تم الشراء بنجاح ✅', 'show_alert'=>false]);
        sendMessage($from['id'], "لقد اشتريت الأصل بنجاح! Asset ID: $asset_id\nPrice: " . ($asset['listed_price'] ?? 0));
        exit;
    }
    // unknown callback
    sendRequest('answerCallbackQuery', ['callback_query_id'=>$callback_id, 'text'=>'عملية غير معروفة', 'show_alert'=>false]);
    exit;
}

// handle message updates
$msg = $update['message'] ?? null;
if (!$msg) {
    // ignore other update types for now
    echo "ok";
    exit;
}

$text = $msg['text'] ?? '';
$chat_id = $msg['chat']['id'];
$from = $msg['from'];
$tg_id = $from['id'];
$username = $from['username'] ?? ($from['first_name'] ?? '');

// parse command
$parts = preg_split('/\s+/', trim($text));
$cmd = strtolower($parts[0] ?? '');

// ---------- /start ----------
if ($cmd === '/start') {
    $pdo = db();
    // insert user if not exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE tg_id = ?");
    $stmt->execute([$tg_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        $stmt = $pdo->prepare("INSERT INTO users (tg_id, username, created_at) VALUES (?, ?, ?)");
        $stmt->execute([$tg_id, $username, date('c')]);
        sendMessage($chat_id, "مرحبا $username ✅ تم تسجيلك بنجاح!");
    } else {
        sendMessage($chat_id, "مرحبا $username 👋 أنت مسجل بالفعل.");
    }
    exit;
}

// ---------- /profile ----------
if ($cmd === '/profile') {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE tg_id = ?");
    $stmt->execute([$tg_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        sendMessage($chat_id, "أنت غير مسجل. استخدم /start");
        exit;
    }
    $user_id = $u['id'];
    $stmt = $pdo->prepare("SELECT id, name, image_url, listed_price FROM assets WHERE owner_user_id = ?");
    $stmt->execute([$user_id]);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$assets) {
        sendMessage($chat_id, "ليس لديك أي أصول حتى الآن.");
        exit;
    }
    foreach ($assets as $a) {
        $caption = "Name: {$a['name']}\nID: {$a['id']}\nPrice: " . ($a['listed_price'] ?? 0);
        // if image_url is file://local, send local image; if http(s) send by URL
        if (strpos($a['image_url'], 'file://') === 0) {
            $local = substr($a['image_url'], 7);
            if (file_exists($local)) {
                sendPhotoByPath($chat_id, $local, $caption);
                continue;
            }
        }
        // fallback sendMessage with URL
        sendMessage($chat_id, $caption . "\n" . $a['image_url']);
    }
    exit;
}

// ---------- /mint ----------
if ($cmd === '/mint') {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE tg_id = ?");
    $stmt->execute([$tg_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        sendMessage($chat_id, "أنت غير مسجل. استخدم /start");
        exit;
    }
    $user_id = $u['id'];
    // generate simple PNG
    $img = imagecreatetruecolor(400, 400);
    $bg = imagecolorallocate($img, rand(0,255), rand(0,255), rand(0,255));
    imagefilledrectangle($img, 0, 0, 400, 400, $bg);
    $white = imagecolorallocate($img, 255,255,255);
    $fontSize = 5;
    imagestring($img, $fontSize, 150, 190, "NFT", $white);
    $fname = $IMAGES_DIR . '/nft_' . $tg_id . '_' . time() . '.png';
    imagepng($img, $fname);
    imagedestroy($img);
    // upload (IPFS optional)
    $url = uploadToIPFS($fname);
    // simulate mint: generate token id using timestamp + random
    $token_id = time() . rand(100,999);
    // save asset
    $stmt = $pdo->prepare("INSERT INTO assets (owner_user_id, name, data_json, image_url, onchain_token_id, listed_price, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, "NFT-{$token_id}", json_encode([]), $url, (string)$token_id, 0.0, date('c')]);
    // send result (send photo local)
    sendPhotoByPath($chat_id, $fname, "تم إنشاء الأصل التجريبي!\nToken ID: $token_id\nURL: $url");
    exit;
}

// ---------- /market ----------
if ($cmd === '/market') {
    $pdo = db();
    $stmt = $pdo->query("SELECT id, name, image_url, listed_price FROM assets WHERE listed_price > 0 ORDER BY created_at DESC LIMIT 10");
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$assets) {
        sendMessage($chat_id, "لا يوجد أصول معروضة للبيع.");
        exit;
    }
    foreach ($assets as $a) {
        $caption = "ID: {$a['id']}\nName: {$a['name']}\nPrice: {$a['listed_price']}";
        // button buy
        $kb = mkInlineKeyboard([[['text'=>'اشتري','callback_data'=>'buy_'.$a['id']]]]);
        if (strpos($a['image_url'], 'file://') === 0) {
            $local = substr($a['image_url'], 7);
            if (file_exists($local)) {
                sendPhotoByPath($chat_id, $local, $caption);
                sendRequest('sendMessage', ['chat_id'=>$chat_id, 'text'=>" ", 'reply_markup'=>json_encode($kb)]);
                continue;
            }
        }
        // else send message with image url and keyboard
        sendMessage($chat_id, $caption . "\n" . $a['image_url'], $kb);
    }
    exit;
}

// ---------- /list (usage: /list <asset_id> <price>) ----------
if ($cmd === '/list') {
    $args = array_slice($parts, 1);
    if (count($args) < 2) {
        sendMessage($chat_id, "استخدام: /list <asset_id> <price>");
        exit;
    }
    $asset_id = intval($args[0]);
    $price = floatval($args[1]);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE tg_id = ?");
    $stmt->execute([$tg_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) { sendMessage($chat_id, "أنت غير مسجل."); exit; }
    $user_id = $u['id'];
    $stmt = $pdo->prepare("SELECT owner_user_id FROM assets WHERE id = ?");
    $stmt->execute([$asset_id]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) { sendMessage($chat_id, "الأصل غير موجود."); exit; }
    if ($a['owner_user_id'] != $user_id) { sendMessage($chat_id, "لا يمكنك عرض أصل ليس ملكك."); exit; }
    $stmt = $pdo->prepare("UPDATE assets SET listed_price = ? WHERE id = ?");
    $stmt->execute([$price, $asset_id]);
    sendMessage($chat_id, "تم عرض الأصل للبيع بسعر $price ETH ✅");
    exit;
}

// ---------- unknown command ----------
sendMessage($chat_id, "أمر غير معروف. الأوامر المتاحة:\n/start\n/profile\n/mint\n/list <asset_id> <price>\n/market\n\nAdmin: /admin");
exit;
