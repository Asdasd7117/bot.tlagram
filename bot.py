import os, json, aiosqlite
from datetime import datetime
from aiogram import Bot, Dispatcher, types
from aiogram.types import InlineKeyboardMarkup, InlineKeyboardButton
from PIL import Image, ImageDraw
from web3 import Web3
import ipfshttpclient

# ---- إعدادات البيئة ----
BOT_TOKEN = os.getenv("BOT_TOKEN")
bot = Bot(token=BOT_TOKEN)
dp = Dispatcher(bot)
DB_PATH = "nft_bot.db"

# ---- Web3 ----
w3 = Web3(Web3.HTTPProvider(os.getenv("RPC_URL")))
account = w3.eth.account.from_key(os.getenv("PRIVATE_KEY"))
nft_contract_address = os.getenv("NFT_CONTRACT_ADDRESS")
with open("nft_contract/abi.json") as f:
    nft_abi = json.load(f)
contract = w3.eth.contract(address=nft_contract_address, abi=nft_abi)

# ---- IPFS ----
client = ipfshttpclient.connect("/ip4/127.0.0.1/tcp/5001/http")  # أو Infura/IPFS

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

        # توليد صورة NFT بسيطة
        os.makedirs("nft_images", exist_ok=True)
        img = Image.new('RGB', (200,200), color=(255,0,0))
        draw = ImageDraw.Draw(img)
        draw.text((50,90), "NFT", fill=(255,255,255))
        file_path = f"nft_images/nft_{tg_id}_{int(datetime.utcnow().timestamp())}.png"
        img.save(file_path)

        # رفع الصورة على IPFS
        res = client.add(file_path)
        ipfs_url = f"https://ipfs.io/ipfs/{res['Hash']}"

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

        # حفظ البيانات في SQLite
        await db.execute("""
            INSERT INTO assets (owner_user_id, name, data_json, image_url, onchain_token_id, listed_price, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        """, (user_id, f"NFT-{token_id}", '{}', ipfs_url, str(token_id), 0.0, datetime.utcnow()))
        await db.commit()

        await message.reply_photo(photo=file_path, caption=f"تم إنشاء NFT!\nToken ID: {token_id}\nIPFS: {ipfs_url}")

# ---- /list ----
@dp.message_handler(commands=["list"])
async def list_asset(message: types.Message):
    tg_id = message.from_user.id
    args = message.get_args().split()
    if len(args) != 2:
        await message.reply("استخدام: /list <asset_id> <price>")
        return
    asset_id, price = args
    price = float(price)
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute("SELECT id FROM users WHERE tg_id=?", (tg_id,))
        user = await cursor.fetchone()
        if not user:
            await message.reply("أنت غير مسجل.")
            return
        user_id = user[0]
        cursor = await db.execute("SELECT owner_user_id FROM assets WHERE id=?", (asset_id,))
        asset = await cursor.fetchone()
        if not asset or asset[0]!=user_id:
            await message.reply("لا يمكنك عرض أصل ليس ملكك.")
            return
        await db.execute("UPDATE assets SET listed_price=? WHERE id=?", (price, asset_id))
        await db.commit()
        await message.reply(f"تم عرض الأصل للبيع بسعر {price} ETH ✅")

# ---- /market ----
@dp.message_handler(commands=["market"])
async def market(message: types.Message):
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute("SELECT * FROM assets WHERE listed_price>0 ORDER BY created_at DESC LIMIT 10")
        assets = await cursor.fetchall()
        if not assets:
            await message.reply("لا يوجد أصول معروضة للبيع.")
            return
        for asset in assets:
            kb = InlineKeyboardMarkup().add(
                InlineKeyboardButton("اشتري", callback_data=f"buy_{asset[0]}")
            )
            await message.reply_photo(photo=asset[4], caption=f"ID: {asset[0]}\nName: {asset[2]}\nPrice: {asset[6]} ETH", reply_markup=kb)

# ---- /buy ----
@dp.callback_query_handler(lambda c: c.data.startswith("buy_"))
async def buy_callback(callback_query: types.CallbackQuery):
    asset_id = int(callback_query.data.split("_")[1])
    tg_id = callback_query.from_user.id
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute("SELECT id FROM users WHERE tg_id=?", (tg_id,))
        user = await cursor.fetchone()
        if not user:
            await callback_query.answer("أنت غير مسجل.")
            return
        user_id = user[0]
        cursor = await db.execute("SELECT owner_user_id, onchain_token_id, listed_price FROM assets WHERE id=?", (asset_id,))
        asset = await cursor.fetchone()
        if not asset or asset[0]==user_id:
            await callback_query.answer("لا يمكن شراء هذا الأصل.")
            return
        owner_id, token_id, price = asset

        # نقل الملكية على العقد ERC1155
        nonce = w3.eth.get_transaction_count(account.address)
        tx = contract.functions.safeTransferFrom(account.address, account.address, int(token_id), 1, b'').build_transaction({
            'from': account.address,
            'nonce': nonce,
            'gas': 500000,
            'gasPrice': w3.to_wei('5', 'gwei')
        })
        signed_tx = account.sign_transaction(tx)
        tx_hash = w3.eth.send_raw_transaction(signed_tx.rawTransaction)
        w3.eth.wait_for_transaction_receipt(tx_hash)

        await db.execute("UPDATE assets SET owner_user_id=? WHERE id=?", (user_id, asset_id))
        await db.commit()

        await callback_query.answer("تم الشراء بنجاح ✅")
        await bot.send_message(tg_id, f"لقد اشتريت الأصل بنجاح! Asset ID: {asset_id}\nPrice: {price} ETH")

# ---- تشغيل البوت ----
if __name__ == "__main__":
    import asyncio
    from aiogram import executor
    asyncio.run(init_db())
    executor.start_polling(dp, skip_updates=True)
