# InPlace — Deployment & Architecture Guide

---

## Live URLs

| What | URL |
|---|---|
| **Live app** | https://inplace-uup1.onrender.com/inplace/login.php |
| **Landing page** | https://inplace-uup1.onrender.com/inplace/index.html |
| **GitHub repo** | https://github.com/prajwalkateel0/inplace |
| **Render dashboard** | https://dashboard.render.com/web/srv-d8nklhr7uimc73a0hlsg |
| **TiDB console** | https://tidbcloud.com |
| **Cloudflare R2** | https://dash.cloudflare.com (R2 → inplace-uploads) |

---

## Architecture Overview

```
Browser
  │
  ▼
Render (Docker, free tier)          ← auto-deploys on push to main
  │  PHP 8.2 + Apache
  │  /var/www/html/inplace/
  │
  ├──► TiDB Serverless (MySQL)      ← permanent free DB, Frankfurt
  │    via PDO over TLS (port 4000)
  │
  └──► Cloudflare R2                ← permanent free file storage
       via AWS SDK (S3-compatible)
       public URL: pub-dbf90c73e27e4d0794494687d573c9e3.r2.dev
```

**How a request flows:**
1. User opens a URL → hits Render's CDN → PHP script runs in the Docker container
2. PHP connects to TiDB Serverless over TLS to read/write data
3. File uploads (reports, documents) go to Cloudflare R2 via the AWS SDK
4. R2-hosted files are served directly from the public R2 URL (no Render bandwidth used)

---

## Services & Credentials

### 1. App Hosting — Render (Docker)

- **Plan**: Free (spins down after 15min inactivity, ~50s cold start on next request)
- **Runtime**: Docker (`php:8.2-apache`)
- **Repo**: `prajwalkateel0/inplace`, branch `main`
- **Auto-deploy**: every push to `main` triggers a rebuild + redeploy
- **Service ID**: `srv-d8nklhr7uimc73a0hlsg`
- **Dashboard**: Render → My project → inplace

To trigger a manual redeploy: Render dashboard → **Manual Deploy → Deploy latest commit**

### 2. Database — TiDB Serverless (free forever)

MySQL-compatible, TLS required, 5GiB free storage.

| Env var | Value |
|---|---|
| `DB_HOST` | `gateway01.eu-central-1.prod.aws.tidbcloud.com` |
| `DB_PORT` | `4000` |
| `DB_NAME` | `inplace_db` |
| `DB_USER` | `4S5EJeoXKY1DwP5.root` |
| `DB_PASS` | *(from TiDB console → Connect)* |

**Console**: https://tidbcloud.com → PRAJWAL's Org → inplace-db → SQL Editor

**How the app connects** (`config/db.php`):
- Reads the 5 `DB_*` env vars (falls back to `localhost/root/inplace_db` for local dev)
- Uses the Docker container's system CA bundle (`/etc/ssl/certs/ca-certificates.crt`) to establish TLS — no extra cert file needed in production
- Locally (XAMPP): falls back to skip-verify SSL (MariaDB on localhost doesn't need TLS)

**Tables** (23 total):
`users`, `companies`, `placements`, `placement_opportunities`,
`placement_change_requests`, `placement_notifications`, `documents`,
`reflections`, `reports`, `visits`, `provider_meetings`, `provider_evaluations`,
`provider_issues`, `provider_tokens`, `announcements`, `announcement_reads`,
`messages`, `notifications`, `audit_log`, `otp_codes`, `password_resets`,
`system_settings`, `tutor_settings`

**Demo accounts** (password: `password`):

| Role | Email |
|---|---|
| Admin | admin.leicester.ac.uk@gmail.com |
| Tutor | tutor.leicester.ac.uk@gmail.com |
| Provider | provider.deloitte.ac.uk@gmail.com |
| Director | Director.leicester.ac.uk@gmail.com |

### 3. File Storage — Cloudflare R2 (free forever)

S3-compatible, 10GB free storage, 1M write ops/month free.

| Env var | Value |
|---|---|
| `R2_ACCOUNT_ID` | `7cf5f24b16bcae7253ba61438bd1acba` |
| `R2_ACCESS_KEY_ID` | *(from Cloudflare → R2 → API Tokens)* |
| `R2_SECRET_ACCESS_KEY` | *(from Cloudflare → R2 → API Tokens)* |
| `R2_BUCKET` | `inplace-uploads` |
| `R2_PUBLIC_URL` | `https://pub-dbf90c73e27e4d0794494687d573c9e3.r2.dev` |

**How it works** (`includes/storage_helper.php`):
- When `R2_*` env vars are present → uploads go to R2 bucket, files served from R2 public URL
- When env vars are absent (local dev) → files saved to `assets/uploads/` on disk as before

---

## Render Environment Variables

Set all of these in **Render → inplace → Environment**:

```
DB_HOST      = gateway01.eu-central-1.prod.aws.tidbcloud.com
DB_PORT      = 4000
DB_NAME      = inplace_db
DB_USER      = 4S5EJeoXKY1DwP5.root
DB_PASS      = <from TiDB console>
R2_ACCOUNT_ID        = 7cf5f24b16bcae7253ba61438bd1acba
R2_ACCESS_KEY_ID     = <from Cloudflare>
R2_SECRET_ACCESS_KEY = <from Cloudflare>
R2_BUCKET            = inplace-uploads
R2_PUBLIC_URL        = https://pub-dbf90c73e27e4d0794494687d573c9e3.r2.dev
```

---

## Local Development (XAMPP)

1. Clone repo into `C:\xampp\htdocs\inplace`
2. Start Apache + MySQL in XAMPP control panel
3. Import dump into local `inplace_db`:
   ```bash
   mysql -u root inplace_db < dump.sql
   ```
4. Install PHP dependencies:
   ```bash
   composer install
   ```
5. Visit `http://localhost/inplace/login.php`

`config/db.php` auto-detects no env vars are set → connects to `localhost/root/inplace_db` (MariaDB).
R2 env vars absent → files saved locally to `assets/uploads/`.

---

## Deploying a Code Change

```bash
# Make your changes locally, then:
git add <files>
git commit -m "your message"
git push https://prajwalkateel0:<PAT>@github.com/prajwalkateel0/inplace.git admin:main
```

Render detects the push and auto-redeploys. Watch progress in **Render → Events**.

---

## Applying Schema / Data Changes

No migration tool — raw SQL only.

**To apply a schema change to TiDB (production):**
```php
<?php
$mysqli = new mysqli();
$mysqli->ssl_set(null, null, 'C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem', null, null);
$mysqli->real_connect(
    'gateway01.eu-central-1.prod.aws.tidbcloud.com',
    '4S5EJeoXKY1DwP5.root', '<password>',
    'inplace_db', 4000, null, MYSQLI_CLIENT_SSL
);
$mysqli->query("ALTER TABLE ... ");
```

Or use the **TiDB SQL Editor** in the web console directly.

**To re-import a full dump:**
Use `tmp_tidb_import.php` pattern (multi_query over each per-table `.sql` file) —
XAMPP's bundled `mysql` CLI cannot connect to TiDB (wrong auth plugin).

---

## reCAPTCHA

Keys are stored in the `system_settings` table (not env vars), managed via
**Admin → Settings** in the app, or directly in the DB:

| setting_key | Value |
|---|---|
| `recaptcha_site_key` | `6Lc8rB8tAAAAADGuMYhIr30jagPyYl57XbSXlM_I` |
| `recaptcha_secret_key` | `6Lc8rB8tAAAAAFFoKgC-20jnICktIbriGyRz7dwC` |

Registered domains: `localhost`, `inplace-uup1.onrender.com`
Type: **reCAPTCHA v2 "I'm not a robot" Checkbox**

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| App takes 50s to load | Render free tier cold start | Normal — upgrade to paid or accept it |
| `getaddrinfo failed` on DB | Managed DB service suspended/expired | Check TiDB console — service should stay active (free forever) |
| `ONLY_FULL_GROUP_BY` SQL error | MySQL 8 strict mode | Wrap non-aggregated joined columns in subqueries or `ANY_VALUE()` |
| `TEXT column can't have DEFAULT` | MySQL 8 rejects literal defaults on TEXT | Remove `DEFAULT ''` from CREATE TABLE; use `DEFAULT NULL` instead |
| reCAPTCHA "Invalid domain" | Site key not registered for this domain | Add domain in Google reCAPTCHA admin console |
| reCAPTCHA "Invalid key type" | Wrong key type (v3 key used for v2 widget) | Create a new v2 "I'm not a robot Checkbox" key |
| Push rejected 403 | Wrong cached GitHub credentials | Use `https://prajwalkateel0:<PAT>@github.com/...` in push URL |
