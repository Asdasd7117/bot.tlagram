<?php
$TOKEN = "8377372254:AAEs6EgmP90WcVjiX29v3x5IQRcnGRBgHM4";

// ملف قاعدة البيانات SQLite
$db_file = __DIR__ . "/nft_bot.db";

// إنشاء قاعدة البيانات والجداول إذا لم تكن موجودة
$db = new SQLite3($db_file);
$db->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tg_id INTEGER UNIQUE,
    username TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER,
    name TEXT,
    image_url TEXT,
    listed_price REAL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// قراءة التحديث من Telegram
$update = json_decode(file_get_contents("php://input"), true);
if(!isset($update["message"])) exit;

$chat_id = $update["message"]["chat"]["id"];
$username = $update["message"]["from"]["username"] ?? "";
$text = $update["message"]["text"] ?? "";

// تسجيل المستخدم إذا لم يكن موجود
$user = $db->querySingle("SELECT * FROM users WHERE tg_id=$chat_id", true);
if(!$user){
    $db->exec("INSERT INTO users (tg_id, username) VALUES ($chat_id, '$username')");
    $user = $db->querySingle("SELECT * FROM users WHERE tg_id=$chat_id", true);
}

// دالة إرسال رسالة
function sendMessage($chat_id, $text) {
    global $TOKEN;
    file_get_contents("https://api.telegram.org/bot$TOKEN/sendMessage?chat_id=$chat_id&text=".urlencode($text));
}

// /start
if($text == "/start"){
    sendMessage($chat_id, "مرحباً بك $username في بوت NFT! استخدم /mint لإنشاء أصلك التجريبي.");
}

// /profile
elseif($text == "/profile"){
    $assets = $db->query("SELECT * FROM assets WHERE owner_user_id=".$user['id']);
    $msg = "";
    while($a = $assets->fetchArray(SQLITE3_ASSOC)){
        $msg .= "ID: {$a['id']}\nName: {$a['name']}\nPrice: {$a['listed_price']}\nImage: {$a['image_url']}\n\n";
    }
    if($msg=="") $msg = "لم تقم بإنشاء أي أصول بعد.";
    sendMessage($chat_id, $msg);
}

// /mint
elseif($text == "/mint"){
    $asset_name = "NFT_" . time();
    $image_file = "images/{$asset_name}.png";
    if(!is_dir("images")) mkdir("images");
    // إنشاء صورة PNG بسيطة
    $im = imagecreatetruecolor(200, 200);
    $bg = imagecolorallocate($im, rand(0,255), rand(0,255), rand(0,255));
    imagefill($im,0,0,$bg);
    $text_color = imagecolorallocate($im,255,255,255);
    imagestring($im,5,50,90,$asset_name,$text_color);
    imagepng($im, $image_file);
    imagedestroy($im);
    $image_url = "https://bot-tlagram-38tm.onrender.com/".$image_file;

    $db->exec("INSERT INTO assets (owner_user_id, name, image_url, listed_price) VALUES ({$user['id']}, '$asset_name', '$image_url', 0.01)");
    sendMessage($chat_id, "تم إنشاء الأصل التجريبي:\nName: $asset_name\nImage: $image_url");
}

// /market
elseif($text == "/market"){
    $assets = $db->query("SELECT * FROM assets LIMIT 10");
    $msg = "";
    while($a = $assets->fetchArray(SQLITE3_ASSOC)){
        $msg .= "ID: {$a['id']}\nName: {$a['name']}\nPrice: {$a['listed_price']}\nOwner: {$a['owner_user_id']}\nImage: {$a['image_url']}\n\n";
    }
    if($msg=="") $msg = "لا يوجد أصول للبيع حالياً.";
    sendMessage($chat_id, $msg);
}

// /buy id
elseif(str_starts_with($text, "/buy")){
    $parts = explode(" ", $text);
    if(!isset($parts[1])) { sendMessage($chat_id,"استخدم: /buy ID"); exit; }
    $asset_id = intval($parts[1]);
    $asset = $db->querySingle("SELECT * FROM assets WHERE id=$asset_id", true);
    if(!$asset) { sendMessage($chat_id,"الأصل غير موجود."); exit; }
    if($asset['owner_user_id'] == $user['id']) { sendMessage($chat_id,"هذا الأصل ملكك بالفعل."); exit; }

    $db->exec("UPDATE assets SET owner_user_id={$user['id']} WHERE id=$asset_id");
    sendMessage($chat_id,"تم شراء الأصل بنجاح!");
}

?>
