# Batayan API and AI Provider

This repository contains the Laravel API. The Python AI provider lives in the
adjacent `ai-provider.batayan.ai` repository. This guide deploys both services
on one Linux server while keeping Laravel as the only public application
boundary.

## Architecture

```text
Internet
   |
Reverse proxy (TLS)
   |
   Laravel API :8000       Docker host gateway       AI provider :0.0.0.0:8080
   |                              |                         |
   +-------------------------- PostgreSQL ----------------+
```

- Laravel serves the public API and owns all application writes.
- The AI provider runs directly on Ubuntu under systemd and calls Laravel's
  authenticated internal callback endpoints over localhost.
- The provider reads PostgreSQL through a dedicated read-only database user.
- Laravel reaches the host-native provider through Docker's
  `host.docker.internal` gateway.
- The frontend remains a separate deployment and points to the public Laravel
  URL with `NUXT_PUBLIC_API_BASE`.

## Requirements

- Ubuntu 24.04 LTS is recommended. Python 3.12 or newer is required by the provider.
- Docker Engine and the Docker Compose plugin.
- A DNS record for the API, such as `api.example.com`.
- Supabase project credentials for authentication.
- Provider credentials required by the selected AI models, normally Anthropic
  and Gemini.
- At least 4 vCPUs, 8 GB RAM, and persistent disk for PostgreSQL and uploads.

Install Docker using the official instructions, then verify it:

```bash
docker --version
docker compose version
```

## Checkout

Use a common parent directory so both repositories are easy to manage:

```bash
sudo mkdir -p /opt/batayan
sudo chown "$USER":"$USER" /opt/batayan
cd /opt/batayan

git clone <api-repository-url> api.batayan.ai
git clone <ai-provider-repository-url> ai-provider.batayan.ai
```

The two repositories are independent. Deploy and update them independently,
but keep their `AI_INTERNAL_SECRET` identical.

## Configure Laravel

```bash
cd /opt/batayan/api.batayan.ai
cp .env.example .env
```

Edit `.env` and set production values. The important single-server settings are:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
APP_KEY=base64:generate-this-with-artisan

DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=laravel
DB_USERNAME=sail
DB_PASSWORD=replace-with-a-long-database-password
# Publish PostgreSQL only to the host so the systemd provider can read it.
FORWARD_DB_PORT=127.0.0.1:5432

FRONTEND_URL=https://app.example.com
SANCTUM_STATEFUL_DOMAINS=app.example.com

# Bind Laravel to localhost; the reverse proxy is the public listener.
APP_PORT=127.0.0.1:8000

# The provider runs on the host; host.docker.internal is provided by compose.yaml.
AI_PROVIDER_URL=http://host.docker.internal:8080
AI_INTERNAL_SECRET=replace-with-the-same-long-random-secret
AI_BATCH_ENGINE=laravel
AI_CHAT_ENGINE=laravel
```

Also set the Supabase, mail, storage, billing, and model API variables required
by the application. Do not use `.env.example` values in production. Use S3 or
another persistent object store for `FILESYSTEM_DISK` when uploads must survive
container replacement.

Generate the shared secret once and use the exact value in both `.env` files:

```bash
openssl rand -base64 48
```

Set the Compose project name before every API Compose command:

```bash
export COMPOSE_PROJECT_NAME=batayan
```

Build and start the Laravel stack:

```bash
docker compose up -d --build
```

Install production PHP dependencies and initialize Laravel:

```bash
docker compose exec laravel.test composer install --no-dev --optimize-autoloader
docker compose exec laravel.test php artisan key:generate --force
docker compose exec laravel.test php artisan migrate --force
docker compose exec laravel.test php artisan storage:link
docker compose exec laravel.test php artisan optimize
```

If `APP_KEY` was set manually, `key:generate` confirms the existing key instead
of replacing it. Never run `migrate:fresh` on a production database.

The stack includes PostgreSQL, Redis, Meilisearch, RabbitMQ, the Laravel HTTP
container, queue workers, and the scheduler. Confirm it is running:

```bash
docker compose ps
curl --fail http://127.0.0.1:8000/up
```

## Create a read-only provider database user

Run migrations first, then create a separate PostgreSQL login for the provider.
Do not give the provider the Laravel application's write-capable login.

Replace the placeholders with a strong password and the database role that owns
the Laravel schema, usually the value of `DB_USERNAME`:

```bash
docker compose exec pgsql psql -U sail -d laravel
```

Replace `sail` and `laravel` with the values of `DB_USERNAME` and
`DB_DATABASE` if your API uses different values.

Run this SQL in `psql`:

```sql
CREATE ROLE batayan_ai_reader LOGIN PASSWORD 'replace-with-a-long-reader-password';
GRANT CONNECT ON DATABASE laravel TO batayan_ai_reader;
GRANT USAGE ON SCHEMA public TO batayan_ai_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO batayan_ai_reader;
ALTER DEFAULT PRIVILEGES FOR ROLE sail IN SCHEMA public
  GRANT SELECT ON TABLES TO batayan_ai_reader;
```

Use the actual `DB_DATABASE`, schema-owner role, and reader password in the
commands above. If migrations run under a different role, use that role in
`ALTER DEFAULT PRIVILEGES`.

## Configure the AI provider on Ubuntu

The provider is not deployed with Docker. Install Python and create a dedicated
system user:

```bash
sudo apt update
sudo apt install -y python3.12 python3.12-venv python3.12-dev build-essential libpq-dev
sudo useradd --system --home /opt/batayan --shell /usr/sbin/nologin batayan || true
sudo chown -R batayan:batayan /opt/batayan/ai-provider.batayan.ai
sudo -u batayan python3.12 -m venv --upgrade-deps /opt/batayan/ai-provider.batayan.ai/.venv
sudo -u batayan /opt/batayan/ai-provider.batayan.ai/.venv/bin/python -m pip install /opt/batayan/ai-provider.batayan.ai
sudo install -d -o batayan -g batayan -m 750 /etc/batayan
sudo install -o batayan -g batayan -m 600 /dev/null /etc/batayan/ai-provider.env
```

If your Ubuntu release provides a different Python executable, use any Python
version supported by `pyproject.toml` (3.12 or newer).

Create `/etc/batayan/ai-provider.env` with values for host networking:

> The systemd unit reads `/etc/batayan/ai-provider.env`. Editing the
> repository's `.env` does not change the systemd service.

```dotenv
ENVIRONMENT=production
LOG_LEVEL=INFO
AI_INTERNAL_SECRET=replace-with-the-same-long-random-secret
LARAVEL_BASE_URL=http://127.0.0.1:8000
DATABASE_URL=postgresql://batayan_ai_reader:replace-with-reader-password@127.0.0.1:5432/laravel

AI_CHAT_PROVIDER=anthropic
ANTHROPIC_API_KEY=replace-with-provider-key
GOOGLE_API_KEY=replace-with-provider-key
AI_EMBED_PROVIDER=gemini
AI_EMBED_MODEL=gemini-embedding-2
EMBEDDING_DIMENSIONS=768

```

Create `/etc/systemd/system/batayan-ai-provider.service`:

```bash
sudo tee /etc/systemd/system/batayan-ai-provider.service >/dev/null <<'UNIT'
[Unit]
Description=Batayan AI Provider
After=network-online.target docker.service
Wants=network-online.target

[Service]
User=batayan
Group=batayan
WorkingDirectory=/opt/batayan/ai-provider.batayan.ai
EnvironmentFile=/etc/batayan/ai-provider.env
ExecStart=/opt/batayan/ai-provider.batayan.ai/.venv/bin/uvicorn ai_provider.main:app --host 0.0.0.0 --port 8080 --no-access-log
Restart=always
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable --now batayan-ai-provider
sudo systemctl status batayan-ai-provider --no-pager
```

Check the provider and its logs:

```bash
curl --fail http://127.0.0.1:8080/health
sudo journalctl -u batayan-ai-provider -n 100 --no-pager
```

The provider binds to the host interface so the Laravel container can reach it
through `host.docker.internal:8080`. Block port `8080` at the firewall; the
provider is still protected by its internal bearer secret.

## Enable the provider gradually

Keep both Laravel engines on `laravel` until the provider health check, database
read access, and callback authentication are verified. Then switch the batch
features first:

```dotenv
AI_BATCH_ENGINE=python
AI_CHAT_ENGINE=laravel
```

Rebuild or reload Laravel, observe queue and error logs, then enable streaming
chat:

```dotenv
AI_BATCH_ENGINE=python
AI_CHAT_ENGINE=python
```

Laravel uses Octane in production. Configuration changes require a graceful
reload, not just an `.env` edit:

```bash
docker compose exec laravel.test php artisan octane:reload
```

Rollback either capability independently by setting its engine back to
`laravel` and running the same graceful reload. Keep the Laravel provider keys
configured until the Python path has completed a full production cycle.

## Reverse proxy and TLS

Expose only the Laravel container through Nginx or Caddy. Example Nginx server
block:

```nginx
server {
    listen 80;
    server_name api.example.com;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_buffering off;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
```

Add TLS with your certificate manager, then set `APP_URL` and `FRONTEND_URL`
to their HTTPS values. `proxy_buffering off` and the long read timeout are
important for streamed chat responses.

## Firewall

For a server that uses Nginx or Caddy, expose only SSH and web traffic:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw deny 8080/tcp
sudo ufw enable
```

Do not expose PostgreSQL, Redis, RabbitMQ, Meilisearch, or the provider port to
the public internet.

## Updates and operations

Update the provider first, then Laravel:

```bash
cd /opt/batayan/ai-provider.batayan.ai
sudo -u batayan git pull --ff-only
sudo -u batayan /opt/batayan/ai-provider.batayan.ai/.venv/bin/pip install /opt/batayan/ai-provider.batayan.ai
sudo systemctl restart batayan-ai-provider
curl --fail http://127.0.0.1:8080/health

cd /opt/batayan/api.batayan.ai
export COMPOSE_PROJECT_NAME=batayan
git pull --ff-only
docker compose up -d --build
docker compose exec laravel.test composer install --no-dev --optimize-autoloader
docker compose exec laravel.test php artisan migrate --force
docker compose exec laravel.test php artisan optimize
docker compose exec laravel.test php artisan octane:reload
```

Inspect service health and recent failures:

```bash
docker compose ps
docker compose logs --tail=200 laravel.test queue scheduler
```

Back up the PostgreSQL volume and object storage separately. The Docker volume
contains application data, but it is not a substitute for an off-server backup.

## Troubleshooting

### Provider cannot reach Laravel

- Confirm Laravel publishes `APP_PORT=127.0.0.1:8000`.
- Confirm the provider has `LARAVEL_BASE_URL=http://127.0.0.1:8000`.
- Check `curl --fail http://127.0.0.1:8000/up` on the host.
- Confirm `AI_INTERNAL_SECRET` is identical in both services.
- Check `sudo journalctl -u batayan-ai-provider -n 100 --no-pager`.
- From Laravel, test `curl http://host.docker.internal:8080/health` inside the
  `laravel.test` container.

### Provider cannot reach PostgreSQL

- Use `127.0.0.1` as the host from the systemd provider, not `pgsql`.
- Confirm the API `.env` publishes `FORWARD_DB_PORT=127.0.0.1:5432`.
- Verify the reader role has `CONNECT`, schema `USAGE`, and table `SELECT` grants.
- Confirm the provider `DATABASE_URL` database and password match the API stack.

### Chat streams stop or time out

- Confirm the reverse proxy has buffering disabled and a long read timeout.
- Check `docker compose logs laravel.test` and
  `sudo journalctl -u batayan-ai-provider -n 100 --no-pager`.
- Verify `AI_CHAT_ENGINE` and `AI_PROVIDER_URL` in Laravel's runtime environment.
- After changing `.env`, run `php artisan octane:reload` inside the Laravel container.

### Provider health works but callbacks return 401

The internal callback middleware requires the bearer secret. Compare the two
`AI_INTERNAL_SECRET` values byte-for-byte and restart both services after fixing
the value.

### systemd reports `203/EXEC`

This means the `ExecStart` file is missing or not executable. Confirm the
virtualenv contains both Python and Uvicorn:

```bash
sudo -u batayan /opt/batayan/ai-provider.batayan.ai/.venv/bin/python --version
sudo -u batayan /opt/batayan/ai-provider.batayan.ai/.venv/bin/python -m pip show uvicorn
test -x /opt/batayan/ai-provider.batayan.ai/.venv/bin/uvicorn && echo ready
```

If `.venv/bin/pip` or `.venv/bin/uvicorn` is missing, install the Ubuntu venv
package and recreate only the generated virtualenv:

```bash
sudo systemctl stop batayan-ai-provider
sudo apt update
sudo apt install -y python3.12-venv python3.12-dev build-essential libpq-dev
sudo rm -rf /opt/batayan/ai-provider.batayan.ai/.venv
sudo -u batayan python3.12 -m venv --upgrade-deps /opt/batayan/ai-provider.batayan.ai/.venv
sudo -u batayan /opt/batayan/ai-provider.batayan.ai/.venv/bin/python -m pip install /opt/batayan/ai-provider.batayan.ai
sudo systemctl daemon-reload
sudo systemctl reset-failed batayan-ai-provider
sudo systemctl start batayan-ai-provider
sudo systemctl status batayan-ai-provider --no-pager -l
```

## Local development

For Laravel-only development, follow the standard commands in `CLAUDE.md`.
For the provider alone:

```bash
cd /opt/batayan/ai-provider.batayan.ai
cp .env.example .env
python -m venv .venv
.venv/bin/pip install -e '.[dev]'
.venv/bin/uvicorn ai_provider.main:app --reload --port 8080
```

The provider's own README documents its endpoints and test commands.
