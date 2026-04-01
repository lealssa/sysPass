# CLAUDE.md

## Project

sysPass 3.3.0 — PHP password manager forked from upstream tag 3.2.11.

- **Branch:** `migration/php85` (active development)
- **PHP:** 8.1–8.5 (migrated from 7.4)
- **Framework:** Klein router v2.1 (legacy, Slim 4 migration planned)
- **Database:** MariaDB 11
- **Deploy:** Docker (Dockerfile + docker-compose.yml)

## Conventions

- All commit messages MUST be in English
- Commit messages: short imperative style (e.g., "fix: resolve X", "feat: add Y")
- No co-authorship line unless explicitly requested
- Code comments may be in Spanish (original codebase) or English (new code)

## Architecture

- Entry points: `index.php` (web), `api.php` (JSON-RPC API)
- Bootstrap chain: `index.php` → `lib/Base.php` → `lib/SP/Bootstrap.php`
- Config: `app/config/config.xml` (auto-created on first run)
- DI container: PHP-DI 7, definitions in `lib/Definitions.php`
- Routing: Klein router, controllers in `app/modules/web/Controllers/`
- Views: PHP templates in `app/modules/web/themes/material-blue/views/`
- Version constant: `lib/SP/Services/Install/Installer.php` (VERSION, VERSION_TEXT, BUILD)

## Build & Run

```bash
# Local dev (no Docker)
composer install
php -S 0.0.0.0:8080

# Docker
docker compose up -d --build
```

## Key files

- `SECURITY_AUDIT.md` — vulnerability report with prioritization and status
- `MIGRATION_PHP81_PLAN.md` — migration plan and execution log
- `docker/php.ini` — hardened PHP config
- `docker/apache-vhost.conf` — Apache vhost with security headers
