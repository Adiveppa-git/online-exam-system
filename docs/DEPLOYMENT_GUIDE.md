# Production Deployment Guide & Architecture Audit

> [!IMPORTANT]
> **Production Infrastructure Directive**:
> This document specifies the production deployment architecture, environment variable requirements, database connection setup, FastAPI microservice configuration, RAG vector database persistence, and outbound mail/OTP delivery for the Online Examination & AI Platform.

---

## 1. System Topology & Service Architecture

The system comprises three primary layers in production:

```
+-----------------------------------------------------------------------+
|                       End-User Web Browsers                           |
+-----------------------------------------------------------------------+
                                   |
                                   v
+-----------------------------------------------------------------------+
|                    PHP Application Web Host (Apache / PHP 8+)          |
|  - Auth, Sessions, CSRF, Admin & Student Workflows                    |
|  - Environment loader reads DB_HOST, AI_SERVICE_URL, MAIL_DRIVER      |
+-----------------------------------------------------------------------+
         |                                           |
         v                                           v
+-----------------------------------+     +-----------------------------------+
|      Managed MySQL Database       |     |     FastAPI Python AI Microservice|
|  - Host: External Cloud Managed DB|     |  - Host: 0.0.0.0 (Port $PORT)     |
|  - DB_HOST, DB_PORT, DB_NAME      |     |  - Vector Store: ChromaDB         |
|  - Migrations: run_migrations.php |     |  - Persistent Volume: /app/data   |
+-----------------------------------+     +-----------------------------------+
```

---

## 2. Environment Variables & Secret Configuration Audit

Production environments must **never** hardcode passwords or API keys. All service credentials must be passed dynamically via hosting platform environment settings (e.g. Render, Railway, AWS ECS, Heroku).

### PHP Web Backend Environment Variables (`.env` or Cloud Dashboard)

| Variable | Description | Required / Default | Example Production Value |
| :--- | :--- | :--- | :--- |
| `APP_ENV` | Application environment state | Required (`production`) | `production` |
| `APP_DEBUG` | Debug output toggle | Required (`false`) | `false` |
| `DB_HOST` | Managed MySQL database host address | **REQUIRED IN PRODUCTION** | `mysql.railway.internal` / `db.render.com` |
| `DB_PORT` | Managed MySQL port | Required (`3306`) | `3306` |
| `DB_NAME` | MySQL database name | Required | `online_exam_system` |
| `DB_USER` | Database user account | Required | `exam_prod_user` |
| `DB_PASS` | Database password | Required | `<strong_production_password>` |
| `AI_SERVICE_URL` | URL of the deployed FastAPI service | Required | `https://ai-service.onrender.com` |
| `AI_SERVICE_KEY` | Shared internal API key between PHP and FastAPI | Required | `<secure_random_api_key>` |
| `MAIL_DRIVER` | Outbound mail driver (`brevo`, `smtp`, `mail`, `auto`) | Required | `brevo` |
| `BREVO_API_KEY` | Brevo REST API key for transactional emails/OTPs | Required if `MAIL_DRIVER=brevo` | `xkeysib-...` |
| `SENDER_EMAIL` | Verified sender email address | Required | `noreply@yourdomain.com` |
| `SENDER_NAME` | Display sender name | Required | `"Online Examination System"` |

### FastAPI Python Service Environment Variables (`ai-service/.env`)

| Variable | Description | Required / Default | Example Production Value |
| :--- | :--- | :--- | :--- |
| `ENVIRONMENT` | Microservice mode | Required (`production`) | `production` |
| `DEBUG` | FastAPI debug log mode | Required (`false`) | `false` |
| `HOST` | Binding interface | Required (`0.0.0.0`) | `0.0.0.0` |
| `PORT` | Listening HTTP port | Provided by Cloud Platform | `10000` / `8001` |
| `INTERNAL_API_KEY` | Shared internal API key matching `AI_SERVICE_KEY` | Required | `<secure_random_api_key>` |
| `LLM_PROVIDER` | Question generation engine (`heuristic`, `openai`, `gemini`) | Required (`heuristic` if no API key) | `heuristic` |
| `LLM_API_KEY` | Optional LLM API key | Optional | `sk-...` |
| `CHROMA_PERSIST_DIR` | Persistent directory for ChromaDB embeddings | Required | `/app/data/chroma_db` |

---

## 3. Database Architecture & Migrations

- **External Managed Database Requirement**:
  Production MySQL must run on a managed database instance (e.g. Railway MySQL, Render PostgreSQL/MySQL, AWS RDS, Aiven). Do **NOT** run production database processes inside an ephemeral application web container.

- **Connection Error Root-Cause Resolution**:
  The live deployment error `mysqli_sql_exception: Connection refused 127.0.0.1:3306` occurs when `DB_HOST` is unconfigured on the cloud platform dashboard, defaulting PHP connection to `127.0.0.1`.
  `config/db.php` has been updated to explicitly enforce environment-variable loading and surface missing `DB_HOST` errors cleanly without silent fallback to localhost in production.

- **Applying Migrations safely**:
  Execute `database/run_migrations.php` using environment variables against the managed database:
  ```bash
  php database/run_migrations.php
  ```
  The migration script applies `CREATE TABLE IF NOT EXISTS` statements cleanly without dropping, truncating, or corrupting existing database tables or records.

---

## 4. FastAPI Production Configuration

- **Binding & Port**:
  FastAPI binds to `0.0.0.0` and respects the `$PORT` environment variable assigned dynamically by cloud hosting platforms (`uvicorn app.main:app --host 0.0.0.0 --port ${PORT:-8001}`).

- **Observability Endpoints**:
  - `/health` (Liveness Check) -> Returns HTTP `200 OK`
  - `/readiness` (Readiness Check) -> Validates vector store connectivity and returns indexed chunk stats.

- **LLM Safety & Fallback**:
  If `LLM_PROVIDER=heuristic`, the system uses structured template-based question generation without failing or making external network calls.

---

## 5. RAG Persistence Strategy

- **Vector Database**:
  ChromaDB vector indices (`course_materials` collection) are stored at `CHROMA_PERSIST_DIR` (`/app/data/chroma_db`).

- **Volume Mounting**:
  Production deployment requires a persistent disk mount for `/app/data` (or volume `chroma_data` in Docker Compose) to guarantee vector index data is preserved across container redeployments.

---

## 6. OTP & Transactional Email Delivery

- **Supported Production Drivers**:
  - `brevo` (Brevo REST API v3): Requires `BREVO_API_KEY`, `SENDER_EMAIL`, `SENDER_NAME`.
  - `smtp` (PHPMailer / Gmail SMTP / Custom SMTP): Requires `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SENDER_EMAIL`.

- **Production Security Safeguard**:
  In non-development environments (`APP_ENV=production`), `auth/send_mail.php` will **NEVER** write OTPs to local `mail.log` files as a fallback. If mail credentials fail in production, the function logs a security alert and fails safely.
