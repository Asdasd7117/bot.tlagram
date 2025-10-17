import os, json, aiosqlite
from datetime import datetime
from aiogram import Bot, Dispatcher, types
from aiogram.types import InlineKeyboardMarkup, InlineKeyboardButton
from PIL import Image, ImageDraw
from web3 import Web3
import requests

# ---- إعدادات البيئة ----
BOT_TOKEN = os.getenv("BOT_TOKEN")
INFURA_PROJECT_ID = os.getenv("INFURA_PROJECT_ID")
INFURA_PROJECT_SECRET = os.getenv("INFURA_PROJECT_SECRET")
bot = Bot(token=BOT_TOKEN)
dp = Dispatcher(bot)
DB_PATH = "nft_bot.db"

# ---- معرف الأدمن ----
ADMIN_IDS = [123456789]  # ضع هنا Telegram ID الخاص بالأدمن

# ---- Web3 ----
w3 = Web3(Web3.HTTPProvider(os.getenv("RPC_URL")))
account = w3.eth.account.from_key(os.getenv("PRIVATE_KEY"))
nft_contract_address = os.getenv("NFT_CONTRACT_ADDRESS")
with open("nft_contract/abi.json") as f:
    nft_abi = json.load(f)
contract = w3.eth.contract(address=nft_contract_address, abi=nft_abi)

# ---- رفع الملفات على IPFS عبر Infura ----
def upload_to_ipfs(file_path):
    url = "https://ipfs.infura.io:5001/api/v0/add"
    with open(file_path, "rb") as f:
        files = {"file": f}
        res = requests.post(url, files=files, auth=(INFURA_PROJECT_ID, INFURA_PROJECT_SECRET))
    hash = res.json()["Hash"]
    return f"https://ipfs.io/ipfs/{hash}"

# ---- إنشاء قاعدة البيانات تلقائيًا ----
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
@dp.message_handler(commands=["start"])
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
            await message.reply(f"مرحبا {username} ✅ تم تسجيلك بنجاح!")
        else:
            await message.reply(f"مرحبا {username} 👋 أنت مسجل بالفعل.")

# ---- /mint ----
@dp.message_handler(commands=["mint"])
async def mint(message: types.Message):
    tg_id = message.from_user.id
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute("SELECT id FROM users WHERE tg_id=?", (tg_id,))
        user = await cursor.fetchone()
        if not user:
            await message.reply("أنت غير مسجل، استخدم /start")
            return
        user_id = user[0]

        os.makedirs("nft_images", exist_ok=True)
        img = Image.new('RGB', (200,200), color=(255,0,0))
        draw = ImageDraw.Draw(img)
        draw.text((50,90), "NFT", fill=(255,255,255))
        file_path = f"nft_images/nft_{tg_id}_{int(datetime.utcnow().timestamp())}.png"
        img.save(file_path)

        # رفع الصورة على IPFS عبر Infura
        ipfs_url = upload_to_ipfs(file_path)

        # Mint على ERC1155
        nonce = w3.eth.get_transaction_count(account.address)
        tx = contract.functions.mint(account.address, 1, ipfs_url).build_transaction({
            'from': account.address,
            'nonce': nonce,
            'gas': 500000,
            'gasPrice': w3.to_wei('5', 'gwei')
        })
        signed_tx = account.sign_transaction(tx)
        tx_hash = w3.eth.send_raw_transaction(signed_tx.rawTransaction)
        w3.eth.wait_for_transaction_receipt(tx_hash)
        token_id = contract.functions.currentTokenID().call()

        # حفظ البيانات
        await db.execute("""
            INSERT INTO assets (owner_user_id, name, data_json, image_url, onchain_token_id, listed_price, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        """, (user_id, f"NFT-{token_id}", '{}', ipfs_url, str(token_id), 0.0, datetime.utcnow()))
        await db.commit()

        await message.reply_photo(photo=file_path, caption=f"تم إنشاء NFT!\nToken ID: {token_id}\nIPFS: {ipfs_url}")

# ---- باقي الأوامر مثل /list, /market, /buy, /admin ----
# يمكن أن تتركها كما في النسخة السابقة، فقط استبدل أي استخدام ipfshttpclient بـ upload_to_ipfs()
# مثال: ipfs_url = upload_to_ipfs(file_path)

# ---- تشغيل البوت ----
if __name__ == "__main__":
    import asyncio
    from aiogram import executor
    asyncio.run(init_db())
    executor.start_polling(dp, skip_updates=True)
