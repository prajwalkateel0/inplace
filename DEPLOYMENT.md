# Deploying InPlace for free (Render + Aiven MySQL + Cloudflare R2)

This app is a PHP/Apache monolith, so the deploy shape is a bit different from a
Node/React + Postgres app, but the *workflow* is the same: push to your main
branch and Render rebuilds and redeploys the Docker image automatically.

- **App** → Render (Docker Web Service, free plan)
- **Database** → Aiven (free MySQL)
- **File storage** → Cloudflare R2 (free S3-compatible storage, for uploaded
  reports/documents — Render's free filesystem is wiped on every deploy)

Local XAMPP development keeps working unchanged: `config/db.php` and
`includes/storage_helper.php` fall back to your existing local settings when
the new environment variables aren't set.

---

## 1. Database — Aiven free MySQL (done)

Aiven MySQL is provisioned and the local schema + data have been imported.
`config/db.php` connects over TLS automatically (mysqlnd negotiates SSL when
the server requires it; no CA file needed). Connection details:

| Key | Value |
|---|---|
| `DB_HOST` | `inplace-db-prajwalkateel4-3938.a.aivencloud.com` |
| `DB_PORT` | `28313` |
| `DB_USER` | `avnadmin` |
| `DB_PASS` | *(from the Aiven console)* |
| `DB_NAME` | `defaultdb` |

If you ever need to re-sync schema/data changes, dump locally and import with
PHP's `mysqli::multi_query` (the bundled XAMPP `mysql` client can't auth
against MySQL 8.4's `caching_sha2_password`).

---

## 2. File storage — Cloudflare R2 (done)

Bucket created, public access enabled, API token verified with a live
upload/delete via the AWS CLI. Connection details:

| Key | Value |
|---|---|
| `R2_ACCOUNT_ID` | `7cf5f24b16bcae7253ba61438bd1acba` |
| `R2_ACCESS_KEY_ID` | *(from the Cloudflare API token)* |
| `R2_SECRET_ACCESS_KEY` | *(from the Cloudflare API token)* |
| `R2_BUCKET` | `inplace-uploads` |
| `R2_PUBLIC_URL` | `https://pub-dbf90c73e27e4d0794494687d573c9e3.r2.dev` |

Uploads (`includes/storage_helper.php`) automatically switch to R2 once the
`R2_*` environment variables are present; locally (without them) files keep
going to `assets/uploads/...` on disk as before.

---

## 3. App — Render (Docker)

1. Push this repo to GitHub.
2. In Render, **New → Web Service**, connect the repo, and choose
   **Docker** as the runtime (Render will detect the `Dockerfile`
   automatically). Pick the **Free** plan.
3. Add these environment variables (Render → your service → Environment):

   Use the values from sections 1 and 2 above:
   `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`,
   `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`,
   `R2_BUCKET`, `R2_PUBLIC_URL`.

   `render.yaml` lists these too — you can use it as a Blueprint
   (New → Blueprint) to create the service with these vars pre-declared.

4. Deploy. Render builds the `Dockerfile`, listens on the `$PORT` it
   injects (handled by `docker/entrypoint.sh`), and serves the app at
   `/inplace/` (matching all the existing hardcoded `/inplace/...` links).

### Auto-redeploy

Every push to your connected branch (e.g. `main`) triggers a new Render
build and deploy automatically — no extra config needed.

### Schema changes

There's no migration tool (Prisma etc.) in this project — it's raw SQL.
For schema changes, run the `.sql` file against the Aiven database the same
way as the initial import in step 1.
