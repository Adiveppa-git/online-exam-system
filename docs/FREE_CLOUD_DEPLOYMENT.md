# 100% Free Cloud Deployment Guide ($0 Cost)

> [!NOTE]
> This guide documents the process for deploying the **Online Examination & AI System** to a completely free cloud infrastructure ($0 cost, no credit card required) using **Render Free Web Services** and **Supabase Free Tier (PostgreSQL + Storage)**.

---

## 1. Local vs Cloud Architecture

```
LOCAL DEVELOPMENT (XAMPP):
PHP Application ----> MySQL/MariaDB (Port 3306)
FastAPI AI Service -> Local ChromaDB (ai-service/data/chroma_db)
Uploads Directory  -> Local Filesystem (uploads/course_materials/)

FREE CLOUD DEPLOYMENT (RENDER + SUPABASE):
Render PHP Web Service   ----> Supabase PostgreSQL (Port 5432)
Render FastAPI Service   ----> Supabase PostgreSQL + pgvector (384-d vectors)
Uploaded Materials      ----> Supabase Storage (Private Bucket: course-materials)
```

---

## 2. Environment Variables Reference

### PHP Web Service
| Variable | Local Value | Cloud Value (Supabase / Render) |
| :--- | :--- | :--- |
| `APP_ENV` | `development` | `production` |
| `APP_DEBUG` | `true` | `false` |
| `DB_DRIVER` | `mysql` | `pgsql` |
| `DB_HOST` | `127.0.0.1` | `aws-0-ap-south-1.pooler.supabase.com` |
| `DB_PORT` | `3306` | `5432` |
| `DB_NAME` | `online_exam_system` | `postgres` |
| `DB_USER` | `root` | `postgres.[PROJECT_REF]` |
| `DB_PASS` | `""` | `[YOUR_SUPABASE_DB_PASSWORD]` |
| `SUPABASE_URL` | `""` | `https://[PROJECT_REF].supabase.co` |
| `SUPABASE_KEY` | `""` | `[YOUR_SUPABASE_SERVICE_ROLE_KEY]` |
| `SUPABASE_BUCKET` | `course-materials` | `course-materials` |
| `AI_SERVICE_URL` | `http://127.0.0.1:8001` | `https://<actual-ai-service-name>.onrender.com` |
| `AI_SERVICE_KEY` | `dev_secret_key...` | `[SHARED_RANDOM_SECURE_KEY]` |

### FastAPI AI Service
| Variable | Local Value | Cloud Value |
| :--- | :--- | :--- |
| `ENVIRONMENT` | `development` | `production` |
| `DEBUG` | `true` | `false` |
| `VECTOR_STORE_TYPE` | `chroma` | `pgvector` |
| `EMBEDDING_MODEL_NAME` | `BAAI/bge-small-en-v1.5` | `BAAI/bge-small-en-v1.5` |
| `SUPABASE_DB_URL` | `""` | `postgresql://postgres.[PROJECT_REF]:[PASS]@aws-0-ap-south-1.pooler.supabase.com:5432/postgres` |
| `SUPABASE_URL` | `""` | `https://[PROJECT_REF].supabase.co` |
| `SUPABASE_KEY` | `""` | `[YOUR_SUPABASE_SERVICE_ROLE_KEY]` |
| `SUPABASE_BUCKET` | `course-materials` | `course-materials` |
| `INTERNAL_API_KEY` | `dev_secret_key...` | `[SHARED_RANDOM_SECURE_KEY]` |

---

## 3. Step-by-Step Supabase Setup

1. **Create Supabase Account**: Sign up at [supabase.com](https://supabase.com) (100% Free, no credit card required).
2. **Create New Project**: Name it `online-exam-ai` in region `ap-south-1` (Mumbai), and set a strong database password.
3. **Enable `pgvector` & Create Schema**:
   - Open Supabase SQL Editor.
   - Paste contents of [`database/schema_postgres.sql`](file:///c:/xampp/htdocs/exam-online/online-exam-system/database/schema_postgres.sql).
   - Execute query to create tables, primary keys, identity sequences, and indices.
4. **Create Storage Bucket**:
   - Navigate to **Storage** -> **Create Bucket**.
   - Bucket Name: `course-materials`.
   - Set to **Private** (authenticated server-side requests using `SUPABASE_KEY` handle uploads and RAG text ingestion securely).

---

## 4. Database Migration (MySQL -> Supabase)

To export your local MySQL data into Supabase PostgreSQL:
1. Configure environment variables in CLI (do not commit to git):
   ```powershell
   $env:PG_HOST="aws-0-ap-south-1.pooler.supabase.com"; $env:PG_USER="postgres.xxx"; $env:PG_PASS="your_password"; $env:PG_NAME="postgres"; C:\xampp\php\php.exe database/export_mysql_to_postgres.php
   ```
2. Run migration verification:
   ```powershell
   $env:PG_HOST="aws-0-ap-south-1.pooler.supabase.com"; $env:PG_USER="postgres.xxx"; $env:PG_PASS="your_password"; $env:PG_NAME="postgres"; C:\xampp\php\php.exe database/verify_migration.php
   ```

---

## 5. Render Blueprint Deployment

1. **Create Render Account**: Sign up at [render.com](https://render.com) (100% Free, no credit card required).
2. **Connect GitHub Repo**: Grant Render access to your repository.
3. **Deploy Blueprint**:
   - Click **New** -> **Blueprint**.
   - Select repository. Render automatically reads [`render.yaml`](file:///c:/xampp/htdocs/exam-online/online-exam-system/render.yaml).
   - Fill in target secret values (`DB_HOST`, `DB_PASS`, `SUPABASE_URL`, `SUPABASE_KEY`, `AI_SERVICE_URL`, `AI_SERVICE_KEY`, `INTERNAL_API_KEY`).
   - Click **Apply**.
4. **Configure PHP to FastAPI Connection**:
   - Once both services are generated on Render, copy the public HTTPS URL of `exam-online-ai` (e.g., `https://exam-online-ai.onrender.com`).
   - Set `AI_SERVICE_URL` in `exam-online-php` environment variables to `https://exam-online-ai.onrender.com`.

---

## 6. Cold Starts & Free-Tier Limitations

> [!WARNING]
> - **Inactivity Sleep**: Render Free Web Services spin down after 15 minutes of zero HTTP traffic and take **about one minute (60 seconds)** to wake up on the first request.
> - **Supabase Pause**: Supabase Free tier databases pause if inactive for 7 consecutive days. Unpausing takes 1 click in the Supabase dashboard.
> - **Portfolio SLA**: This setup is designed for zero-cost portfolio / demonstration deployments.

---

## 7. Rollback & Local Development Integrity

Local XAMPP development is **completely decoupled** from cloud PostgreSQL settings.
To return to local MySQL development at any time:
1. Ensure `.env` has `DB_DRIVER=mysql`.
2. Ensure `AI_SERVICE_URL=http://127.0.0.1:8001`.
3. Start XAMPP MySQL and Apache.
