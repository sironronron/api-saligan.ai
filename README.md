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
Laravel API :8000       Docker network: batayan_sail       AI provider :8080
   |                                                           |
   +-------------------------- PostgreSQL --------------------+
```

- Laravel serves the public API and owns all application writes.
- The AI provider is reachable only over the Docker network and calls Laravel's
  authenticated internal callback endpoints.
- The provider reads PostgreSQL through a dedicated read-only database user.
- The frontend remains a separate deployment and points to the public Laravel
  URL with `NUXT_PUBLIC_API_BASE`.

## Requirements

- Ubuntu 22.04 or newer, or another Docker-supported Linux distribution.
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
but keep their shared network name and `AI_INTERNAL_SECRET` identical.

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

FRONTEND_URL=https://app.example.com
SANCTUM_STATEFUL_DOMAINS=app.example.com

# Bind Laravel to localhost; the reverse proxy is the public listener.
APP_PORT=127.0.0.1:8000

# Keep the provider on the shared Docker network.
AI_PROVIDER_URL=http://ai-provider:8080
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

Set the Compose project name before every Compose command. This gives the API
network a predictable name for the provider's external-network declaration:

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
docker compose exec pgsql psql -U "$DB_USERNAME" -d "$DB_DATABASE"
```

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

## Configure the AI provider

```bash
cd /opt/batayan/ai-provider.batayan.ai
cp .env.example .env
```

Edit the provider `.env` with values that are valid inside Docker, not values
that point to `localhost`:

```dotenv
ENVIRONMENT=production
LOG_LEVEL=INFO
AI_INTERNAL_SECRET=replace-with-the-same-long-random-secret
LARAVEL_BASE_URL=http://laravel.test
DATABASE_URL=postgresql://batayan_ai_reader:replace-with-reader-password@pgsql:5432/laravel

AI_CHAT_PROVIDER=anthropic
ANTHROPIC_API_KEY=replace-with-provider-key
GOOGLE_API_KEY=replace-with-provider-key
AI_EMBED_PROVIDER=gemini
AI_EMBED_MODEL=gemini-embedding-2
EMBEDDING_DIMENSIONS=768

# Used by compose.yaml to join the Laravel network.
AI_PROVIDER_NETWORK=batayan_sail
```

The provider's `LARAVEL_BASE_URL` must be `http://laravel.test`, and its
`DATABASE_URL` host must be `pgsql`. Those Docker service names resolve only on
the shared network. The secret must match Laravel's `AI_INTERNAL_SECRET`
exactly.

Start the provider after the Laravel stack has created the network and database:

```bash
export COMPOSE_PROJECT_NAME=batayan
export AI_PROVIDER_NETWORK=batayan_sail
```

Check the provider from the server:

```bash
curl --fail http://127.0.0.1:8080/health
docker compose logs --tail=100 ai-provider
```

The provider Compose file currently publishes port `8080`. Allow only ports
`80` and `443` through the server firewall; do not expose `8080` publicly.
For a stricter setup, bind the provider port to `127.0.0.1` in a production
Compose override. Laravel still reaches it through `http://ai-provider:8080`.

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
export COMPOSE_PROJECT_NAME=batayan AI_PROVIDER_NETWORK=batayan_sail
git pull --ff-only
docker compose up -d --build

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

- Confirm both Compose projects use `COMPOSE_PROJECT_NAME=batayan`.
- Confirm the provider `.env` has `AI_PROVIDER_NETWORK=batayan_sail`.
- Confirm `LARAVEL_BASE_URL=http://laravel.test`.
- Confirm `AI_INTERNAL_SECRET` is identical in both services.
- Check `docker network inspect batayan_sail` and verify both containers are attached.

If Docker reports `network ... not found` when starting the provider, the
container was created against an older network instance. Recreate the provider
container so Docker attaches it to the current network:

```bash
cd /opt/batayan/ai-provider.batayan.ai
export COMPOSE_PROJECT_NAME=batayan
export AI_PROVIDER_NETWORK=batayan_sail
docker compose up -d --force-recreate
docker compose ps
curl --fail http://127.0.0.1:8080/health
```

Do not delete the network or PostgreSQL volumes as a first response. The
provider container is disposable; the database and its volumes are not.

### Provider cannot reach PostgreSQL

- Use `pgsql` as the host, not `localhost`.
- Verify the reader role has `CONNECT`, schema `USAGE`, and table `SELECT` grants.
- Confirm the provider `DATABASE_URL` database and password match the API stack.

### Chat streams stop or time out

- Confirm the reverse proxy has buffering disabled and a long read timeout.
- Check `docker compose logs laravel.test ai-provider`.
- Verify `AI_CHAT_ENGINE` and `AI_PROVIDER_URL` in Laravel's runtime environment.
- After changing `.env`, run `php artisan octane:reload` inside the Laravel container.

### Provider health works but callbacks return 401

The internal callback middleware requires the bearer secret. Compare the two
`AI_INTERNAL_SECRET` values byte-for-byte and restart both services after fixing
the value.

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
