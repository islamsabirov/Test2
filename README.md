# 🎬 KinoBot — O'rnatish qo'llanmasi

## 📁 Loyiha tuzilmasi
```
KinoBot/
 ├── Dockerfile
 ├── index.php          ← Apache kirish nuqtasi
 ├── KinoBot.php        ← Asosiy bot kodi
 ├── .env               ← Maxfiy sozlamalar
 ├── .env.example       ← Namuna (GitHubga yuklanadi)
 ├── .htaccess          ← Apache sozlamalari
 ├── .gitignore
 ├── users/
 ├── step/
 ├── kino/
 ├── tizim/
 └── admin/
```

---

## 🚀 Render.com da ishga tushirish

### 1. GitHub ga yuklash
```bash
git init
git add .
git commit -m "KinoBot first commit"
git remote add origin https://github.com/SIZNING/repo.git
git push -u origin main
```

### 2. Render da yangi Web Service yaratish
- `New` → `Web Service`
- GitHub repo ni ulang
- **Environment**: `Docker`
- **Region**: yaqin server (Frankfurt/Singapore)

### 3. Environment Variables qo'shish
Render dashboard → `Environment` bo'limiga:
```
BOT_TOKEN = sizning_bot_tokeni
OWNER_ID  = 5907118746
```

### 4. Deploy qiling va URL oling
URL ko'rinishi: `https://your-app.onrender.com`

### 5. Webhook o'rnatish
Brauzerda oching:
```
https://api.telegram.org/botTOKEN/setWebhook?url=https://your-app.onrender.com/KinoBot.php
```

---

## 🖥️ VPS / Shared Hosting da o'rnatish

### Shared Hosting (Beget, Timeweb, Hostinger)
1. Fayllarni `public_html/` ga yuklang
2. `.env` faylini to'ldiring
3. Webhook o'rnating

### VPS (Ubuntu/Debian)
```bash
# Apache + PHP o'rnatish
apt install apache2 php8.2 php8.2-curl -y

# Fayllarni ko'chirish
cp -r KinoBot/* /var/www/html/
chown -R www-data:www-data /var/www/html/

# Webhook
curl "https://api.telegram.org/botTOKEN/setWebhook?url=https://sizning-domen.com/KinoBot.php"
```

---

## ⚙️ Bot sozlamalari

### Admin panel
1. Botga `/start` yuboring
2. `🗄 Boshqaruv paneli` tugmasini bosing
3. `📢 Kanallar` → kino kanalini qo'shing
4. `📥 Kino Yuklash` → kino joylang
5. Foydalanuvchiga kino kodini bering

### Majburiy obuna
- `📢 Kanallar` → `📢 Majburiy obuna`
- Ommaviy kanal: `@username` formatida
- Maxfiy kanal: `https://t.me/+link` + `\n` + `-100XXXXXXX`

---

## 🔧 Webhook tekshirish
```
https://api.telegram.org/botTOKEN/getWebhookInfo
```

## 🔄 Webhook o'chirish
```
https://api.telegram.org/botTOKEN/deleteWebhook
```
