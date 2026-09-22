# Local development

Two supported setups. Both talk to the same MariaDB and Redis containers, so
you can switch between them without touching data.

- **Herd (Windows/macOS)** — PHP and the web server run on the host, services
  run in Docker. Fastest edit/refresh loop; this is what the project is set up
  for right now.
- **Full Docker** — everything in containers, identical to production shape.
  Use it when you need to verify Nginx configuration, Horizon supervision or
  the production image.

---

## Prerequisites

| Tool | Minimum | Checked with |
| --- | --- | --- |
| PHP | 8.4 | `php -v` |
| Composer | 2.8 | `composer -V` |
| Node | 22 LTS | `node -v` |
| Docker Desktop | 26 (Compose v2) | `docker compose version` |

PHP needs `bcmath ctype curl dom fileinfo filter gd gmp intl mbstring openssl
pdo_mysql redis session sodium tokenizer xml zip`. Herd ships all of these.

---

## Setup with Herd

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
```

Point `.env` at the containers' **forwarded** ports (the service names `db`
and `redis` only resolve inside the Docker network):

```dotenv
APP_URL=http://infracms.test

DB_HOST=127.0.0.1
DB_PORT=33061

REDIS_HOST=127.0.0.1
REDIS_PORT=63791

MAIL_HOST=127.0.0.1
AWS_ENDPOINT=http://127.0.0.1:9000

SANCTUM_STATEFUL_DOMAINS=infracms.test,localhost:5173
VITE_HMR_HOST=infracms.test
```

Start the backing services, then prepare the database:

```bash
docker compose up -d db redis mailpit minio

php artisan migrate
php artisan db:seed          # provider organization, permissions, system roles

npm run build                # or: npm run dev
```

Link the directory in Herd so it serves at `http://infracms.test`. Herd
serves `public/` automatically.

## Setup with full Docker

```bash
cp .env.example .env         # the defaults already use the service names
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
npm install && npm run dev   # Vite runs on the host, not in a container
```

| Service | URL |
| --- | --- |
| Application | http://localhost:8080 |
| Mailpit | http://localhost:8025 |
| MinIO console | http://localhost:9001 |
| MariaDB | 127.0.0.1:33061 |
| Redis | 127.0.0.1:63791 |

---

## What is reachable in Phase 0

| Route | Status | Notes |
| --- | --- | --- |
| `GET /` | 200 | Storefront, rendered through `StorefrontRenderer` |
| `GET /up` | 200 | Framework liveness probe |
| `GET /api/v1/health` | 200 / 503 | Database, cache and Redis checks; 503 when a dependency is down |
| `GET /admin` | 302 → `/admin/login` | Requires `platform.health.view`; sign-in arrives in Phase 1 |
| `GET /client` | 302 → `/login` | Sign-in arrives in Phase 1 |
| `GET /horizon` | 403 | Requires `platform.queue.view` |

The redirect targets do not exist yet. That is intentional: the middleware
contract is already correct, and Phase 1 fills in the screens.

## Running the checks

```bash
docker compose up -d db redis         # the suite needs a real MariaDB
php artisan migrate --env=testing

composer check                         # pint --test, rector --dry-run, phpstan, pest

npm run lint
npm run format:check
npm run typecheck
npm run test:unit
```

`composer fix` and `npm run lint:fix` apply the formatting fixes.

### Why the tests need MariaDB

The suite runs against MariaDB, never SQLite. Collation, JSON functions and
foreign-key behaviour differ enough that a green SQLite suite would not tell
us the product works. `.env.testing` points at the `infracms_test` schema,
which the database container creates on first start; development data is
never touched.

## Useful commands

```bash
php artisan platform:permissions:sync   # mirror declared permissions into the DB
php artisan db:seed                      # idempotent: provider org, permissions, roles
php artisan pail                         # tail structured logs
docker compose logs -f db                # database logs
docker compose down -v                   # reset all data and start clean
```

## Troubleshooting

**`SQLSTATE[HY000] [2002]` on the host.** `.env` is pointing at `db`/`redis`
instead of `127.0.0.1` with the forwarded ports. See the Herd section above.

**`Route [login] not defined`.** Expected before Phase 1 only if
`redirectGuestsTo` has been removed from `bootstrap/app.php`; the shipped
configuration redirects to a path, not a route name.

**The site 500s after changing `.env`.** Run `php artisan config:clear`.

**Horizon will not start on Windows.** It requires `ext-pcntl`, which Windows
does not have. Run it in the container: `docker compose up -d queue`.

**Permissions look empty on a role.** Run `php artisan db:seed` — the role
seeder syncs the permission registry first, so it is safe to re-run.
