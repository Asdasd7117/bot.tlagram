import os
import json
import aiosqlite
from datetime import datetime
from aiogram import Bot, Dispatcher, types
from aiogram.types import InlineKeyboardMarkup, InlineKeyboardButton
from aiogram.filters import Command
from PIL import Image, ImageDraw
import requests
from dotenv import load_dotenv
import asyncio

# ---- تحميل متغيرات البيئة ----
load_dotenv()
BOT_TOKEN = os.getenv("BOT_TOKEN")
if not BOT_TOKEN:
    raise ValueError("❌ BOT_TOKEN غير موجود. تأكد من Environment أو ملف .env")

INFURA_PROJECT_ID = os.getenv("INFURA_PROJECT_ID", "")
INFURA_PROJECT_SECRET = os.getenv("INFURA_PROJECT_SECRET", "")

bot = Bot(token=BOT_TOKEN)
dp = Dispatcher()  # لا تمرر bot
DB_PATH = "nft_bot_test.db"

# ---- معرف الأدمن ----
ADMIN_IDS = [123456789]  # ضع هنا Telegram ID الخاص بالأدمن

# ---- رفع الملفات على IPFS عبر Infura (اختياري) ----
def upload_to_ipfs(file_path):
    if not INFURA_PROJECT_ID or not INFURA_PROJECT_SECRET:
        # مجرد محاكاة
        return f"file://{file_path}"
    url = "https://ipfs.infura.io:5001/api/v0/add"
    with open(file_path, "rb") as f:
        files = {"file": f}
        res = requests.post(url, files=files, auth=(INFURA_PROJECT_ID, INFURA_PROJECT_SECRET))
    hash = res.json()["Hash"]
    return f"https://ipfs.io/ipfs/{hash}"

# ---- إنشاء قاعدة البيانات ----
async def init_db():
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute("""
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tg_id INTEGER UNIQUE,
                username TEXT,
                created_at DATETIME
            )
        """)
        await db.execute("""
            CREATE TABLE IF NOT EXISTS assets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_user_id INTEGER,
                name TEXT,
                data_json TEXT,
                image_url TEXT,
                onchain_token_id TEXT,
                listed_price FLOAT,
                created_at DATETIME,
                FOREIGN KEY(owner_user_id) REFERENCES users(id)
            )
        """)
        await db.commit()

# ---- /start ----
@dp.message(Command("start"))
async def start(message: types.Message):
    tg_id = message.from_user.id
    username = message.from_user.username
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute("SELECT * FROM users WHERE tg_id=?", (tg_id,))
        user = await cursor.fetchone()
        if not user:
            await db.execute(
                "INSERT INTO users (tg_id, username, created_at) VALUES (?, ?, ?)",
                (tg_id, username, datetime.utcnow())
            )
            await db.commit()
            await message.answer(f"مرحبا {username} ✅ تم تسجيلك بنجاح (تجريبي)!")
        else:
            await message.answer(f"مرحبا {username} 👋 أنت مسجل بالفعل (تجريبي).")

# ---- /mint تجريبي ----
@dp.message(Command("mint"))
async def mint(message: types.Message):
    tg_id = message.from_user.id
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute("SELECT id FROM users WHERE tg_id=?", (tg_id,))
        user = await cursor.fetchone()
        if not user:
            await message.answer("أنت غير مسجل، استخدم /start")
            return
        user_id = user[0]

        # ---- توليد صورة NFT تجريبية ----
        os.makedirs("nft_images", exist_ok=True)
        img = Image.new('RGB', (200,200), color=(255,0,0))
        draw = ImageDraw.Draw(img)
        draw.text((50,90), "NFT", fill=(255,255,255))
        file_path = f"nft_images/nft_{tg_id}_{int(datetime.utcnow().timestamp())}.png"
        img.save(file_path)

        # ---- رفع الصورة (محلي أو IPFS) ----
        ipfs_url = upload_to_ipfs(file_path)

        # ---- Mint تجريبي بدون شبكة ----
        token_id = int(datetime.utcnow().timestamp())  # رقم Token وهمي

        # ---- حفظ البيانات ----
        await db.execute("""
            INSERT INTO assets (owner_user_id, name, data_json, image_url, onchain_token_id, listed_price, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        """, (user_id, f"NFT-{token_id}", '{}', ipfs_url, str(token_id), 0.0, datetime.utcnow()))
        await db.commit()

        await message.answer_photo(photo=file_path, caption=f"تم إنشاء NFT تجريبي!\nToken ID: {token_id}\nURL: {ipfs_url}")

# ---- /admin لوحة تحكم بسيطة ----
@dp.message(Command("admin"))
async def admin_panel(message: types.Message):
    if message.from_user.id not in ADMIN_IDS:
        await message.answer("❌ لا تملك صلاحيات الأدمن")
        return

    async with aiosqlite.connect(DB_PATH) as db:
        users = await db.execute_fetchall("SELECT tg_id, username FROM users")
        assets = await db.execute_fetchall("SELECT id, name, owner_user_id, image_url, listed_price FROM assets")

    text = "📋 لوحة التحكم:\n\nUsers:\n"
    for u in users:
        text += f"- {u[1]} ({u[0]})\n"
    text += "\nAssets:\n"
    for a in assets:
        text += f"- ID {a[0]} | {a[1]} | Owner ID: {a[2]} | Price: {a[4]}\n"
        text += f"  URL: {a[3]}\n"

    await message.answer(text)

# ---- تشغيل البوت ----
async def main():
    await init_db()
    await dp.start_polling(bot)

if __name__ == "__main__":
    asyncio.run(main())
