# Deploying Store Shift to Hostinger

Landing page: `https://shift-shop.webnday.com/`
App: `https://shift-shop.webnday.com/app/`

---

## 1. Server folder layout

```
public_html/
├── index.html              ← landing page (domain root)
├── favicon.svg  icons.svg
│
└── app/                    ← the whole application
    ├── index.php           ← Laravel front controller (paths point to ./backend/)
    ├── .htaccess           ← Laravel rewrite rules
    ├── favicon.svg  icons.svg
    ├── assets/             ← built JS/CSS  (/app/assets/...)
    └── backend/            ← full Laravel app
        ├── .htaccess       ← DENY ALL (protects .env, vendor, app ...)
        ├── app/  bootstrap/  config/  database/  routes/  vendor/
        ├── resources/spa/index.html   ← SPA shell served by Laravel
        ├── storage/        ← writable (775)
        └── .env
```

Laravel detects the `/app` subdirectory automatically from `SCRIPT_NAME`, so routes
stay declared without a prefix — `/app/api/...` and `/app/admin` just work.

---

## 2. Upload

Unzip **`store-shift-deploy.zip`** into `public_html/`. It contains:

```
index.html          favicon.svg   icons.svg
app/index.php       app/.htaccess
app/assets/*        app/favicon.svg  app/icons.svg
app/backend-htaccess.txt   ← rename to backend/.htaccess (see §3)
```

Then upload the Laravel app itself into `public_html/app/backend/`
(everything from the local `backend/` folder **except** `public/`).

---

## 3. Protect the backend folder ⚠️

`backend/` sits inside the web root, so it must be blocked by Apache:

1. Move `app/backend-htaccess.txt` → `app/backend/.htaccess`
2. Rename it to exactly **`.htaccess`** (leading dot, no `.txt`)

> File Manager hides dotfiles — enable **Show hidden files** first.

---

## 4. Configure

```bash
cd ~/public_html/app/backend
mv .env.production .env
chmod 600 .env
chmod -R 775 storage bootstrap/cache
```

Key values (already set in `.env.production`):

```ini
APP_URL=https://shift-shop.webnday.com/app
SHOPIFY_APP_URL=https://shift-shop.webnday.com/app
APP_DEBUG=false
DB_DATABASE=ss_main
DB_USERNAME=ss_root
ADMIN_PASSWORD=            # ← set before seeding
```

---

## 5. Install & migrate (SSH)

```bash
cd ~/public_html/app/backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=AdminSeeder --force
php artisan config:cache
php artisan route:cache
```

---

## 6. Queue cron (REQUIRED)

Without this, migrations stay stuck at *pending* forever.

hPanel → **Advanced → Cron Jobs** → every minute (`* * * * *`):

```bash
cd ~/public_html/app/backend && php artisan queue:work --stop-when-empty --max-time=55
```

---

## 7. Shopify Partner Dashboard

| Field | Value |
|---|---|
| App URL | `https://shift-shop.webnday.com/app/api/auth` |
| Allowed redirection URL | `https://shift-shop.webnday.com/app/api/auth/callback` |

GDPR webhooks:

```
https://shift-shop.webnday.com/app/api/webhooks/customers-data-request
https://shift-shop.webnday.com/app/api/webhooks/customers-redact
https://shift-shop.webnday.com/app/api/webhooks/shop-redact
```

---

## 8. Verify

```bash
curl -I https://shift-shop.webnday.com/                      # 200  landing page
curl -I https://shift-shop.webnday.com/app/                  # 200  SPA
curl -I https://shift-shop.webnday.com/app/assets/index-Be5bzpko.js   # 200
curl -I https://shift-shop.webnday.com/app/admin             # 200  admin login
curl -i  "https://shift-shop.webnday.com/app/api/auth?shop=YOUR-STORE.myshopify.com"  # 302 → shopify

# these MUST NOT return 200
curl -I https://shift-shop.webnday.com/app/backend/.env
curl -I https://shift-shop.webnday.com/app/backend/composer.json
```

Install URL:

```
https://shift-shop.webnday.com/app/api/auth?shop=YOUR-STORE.myshopify.com
```

Admin panel: `https://shift-shop.webnday.com/app/admin`

---

## 9. Redeploying after a change

| Changed | Do |
|---|---|
| Frontend | `build-prod.bat` → upload `app/assets/` + `backend/resources/spa/index.html` |
| Backend PHP | upload files → `php artisan route:clear && php artisan config:cache` |
| `.env` | edit → `php artisan config:cache` (otherwise the change is ignored) |
| DB schema | `php artisan migrate --force` |

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| `/app/` 404 | `app/index.php` paths not pointing at `./backend/` |
| `/app/assets/*` 404 | Zip extracted as `assets\file.js` — rebuild with `build-prod.bat` |
| Blank page / 500 | `storage/` not writable → `chmod -R 775 storage` |
| API 401 everywhere | `.htaccess` missing → `Authorization` header stripped |
| Migration stuck *pending* | Queue cron (§6) not running |
| `.env` edit ignored | Run `php artisan config:cache` |
| `backend/composer.json` returns 200 | `backend/.htaccess` missing or still named `.txt` |
