# Migration Guide — sysPass 3.2.11 → 3.3.0

**Date:** 2026-04-01
**From:** sysPass 3.2.11 (PHP 7.4, bare metal or legacy Docker)
**To:** sysPass 3.3.0 (PHP 8.5, Docker)
**Branch:** `migration/php85`
**Estimated downtime:** 15–30 minutes

---

## Overview

This guide covers the production migration of sysPass from version 3.2.11 (PHP 7.4) to 3.3.0 (PHP 8.5) using Docker. The migration does **not** require database schema changes — the same database is used with no structural modifications.

### What changed in 3.3.0

- PHP 7.4 → 8.5 (all code updated)
- Secure `unserialize()` with `allowed_classes` (P0-2)
- LDAP: `is_resource()` → proper object check for PHP 8.1+ compatibility
- RSA public key format: PKCS1 → PKCS8 (JSEncrypt compatibility)
- Dependencies updated (monolog 3, guzzle 7, phpseclib 3, php-di 7, symfony 7)
- Docker-based deployment (Dockerfile + docker-compose.yml)
- Apache security headers (CSP, X-Frame-Options, nosniff, etc.)
- OPcache enabled by default
- mcrypt replaced by OpenSSL

---

## Prerequisites

- [ ] Docker and Docker Compose installed on the target server
- [ ] Access to the production database server (MariaDB/MySQL)
- [ ] Access to the production `config.xml` file
- [ ] SSH access to the production server
- [ ] Git access to `lealssa/sysPass` repository (branch `migration/php85`)
- [ ] LDAP server reachable from the Docker host (if LDAP auth is used)

---

## Step 1 — Backup (CRITICAL)

**Do NOT skip this step.**

```bash
# 1.1 Full database dump
mysqldump -u root -p --single-transaction --routines --triggers \
  --databases syspass > syspass_backup_$(date +%Y%m%d_%H%M%S).sql

# 1.2 Backup current application files
tar czf syspass_app_backup_$(date +%Y%m%d_%H%M%S).tar.gz /path/to/current/syspass/

# 1.3 Backup config.xml specifically (contains encryption keys references)
cp /path/to/current/syspass/app/config/config.xml ./config.xml.backup

# 1.4 If using Docker, save the current image
docker save syspass-app:current > syspass_image_backup.tar
```

---

## Step 2 — Prepare the Database

### 2.1 Clone production database (recommended)

If using a cloned VM of the production database server, no changes are needed — the original `sp_XXXX` user and views will work.

### 2.2 Fix view definers (if using a different DB user)

If the database user changes (e.g., from `sp_admin@localhost` to `syspass@%`), the views must be recreated. Connect as **root** and run:

```sql
USE syspass;

CREATE OR REPLACE DEFINER='syspass'@'%' SQL SECURITY INVOKER VIEW account_data_v AS
SELECT
  Account.id, Account.name, Account.categoryId, Account.userId, Account.clientId,
  Account.userGroupId, Account.userEditId, Account.login, Account.url, Account.notes,
  Account.countView, Account.countDecrypt, Account.dateAdd, Account.dateEdit,
  CONV(Account.otherUserEdit,10,2) AS otherUserEdit,
  CONV(Account.otherUserGroupEdit,10,2) AS otherUserGroupEdit,
  CONV(Account.isPrivate,10,2) AS isPrivate,
  CONV(Account.isPrivateGroup,10,2) AS isPrivateGroup,
  Account.passDate, Account.passDateChange, Account.parentId,
  Category.name AS categoryName,
  Client.name AS clientName,
  ug.name AS userGroupName,
  u1.name AS userName, u1.login AS userLogin,
  u2.name AS userEditName, u2.login AS userEditLogin,
  PublicLink.hash AS publicLinkHash
FROM Account
  LEFT JOIN Category ON Account.categoryId = Category.id
  JOIN UserGroup ug ON Account.userGroupId = ug.id
  JOIN User u1 ON Account.userId = u1.id
  JOIN User u2 ON Account.userEditId = u2.id
  LEFT JOIN Client ON Account.clientId = Client.id
  LEFT JOIN PublicLink ON Account.id = PublicLink.itemId;

CREATE OR REPLACE DEFINER='syspass'@'%' SQL SECURITY INVOKER VIEW account_search_v AS
SELECT
  Account.id, Account.clientId, Account.categoryId, Account.name, Account.login,
  Account.url, Account.notes, Account.userId, Account.userGroupId,
  Account.otherUserEdit, Account.otherUserGroupEdit,
  Account.isPrivate, Account.isPrivateGroup,
  Account.passDate, Account.passDateChange, Account.parentId,
  Account.countView, Account.dateEdit,
  User.name AS userName, User.login AS userLogin,
  UserGroup.name AS userGroupName,
  Category.name AS categoryName,
  Client.name AS clientName,
  (SELECT COUNT(0) FROM AccountFile WHERE AccountFile.accountId = Account.id) AS num_files,
  PublicLink.hash AS publicLinkHash,
  PublicLink.dateExpire AS publicLinkDateExpire,
  PublicLink.totalCountViews AS publicLinkTotalCountViews
FROM Account
  JOIN Category ON Account.categoryId = Category.id
  JOIN Client ON Client.id = Account.clientId
  JOIN User ON Account.userId = User.id
  JOIN UserGroup ON Account.userGroupId = UserGroup.id
  LEFT JOIN PublicLink ON Account.id = PublicLink.itemId;
```

You can check the current definers with:

```sql
SELECT TABLE_NAME, DEFINER, SECURITY_TYPE
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = 'syspass';
```

---

## Step 3 — Deploy the Application

### 3.1 Clone the repository

```bash
git clone -b migration/php85 https://github.com/lealssa/sysPass.git /opt/syspass
cd /opt/syspass
```

### 3.2 Configure docker-compose.yml

Edit `docker-compose.yml` to point to your **existing** database instead of spinning up a new one:

```yaml
services:
  syspass:
    build: .
    container_name: syspass-app
    restart: unless-stopped
    ports:
      - "8080:80"   # Change to 443:443 if using a reverse proxy with HTTPS
    volumes:
      - syspass-config:/var/www/syspass/app/config
      - syspass-backup:/var/www/syspass/app/backup
      - syspass-cache:/var/www/syspass/app/cache
      - syspass-temp:/var/www/syspass/app/temp

volumes:
  syspass-config:
  syspass-backup:
  syspass-cache:
  syspass-temp:
```

> **Note:** Remove the `syspass-db` service if using an external database server.

### 3.3 Build the Docker image

```bash
docker compose build --no-cache
```

### 3.4 Start the container (without config.xml first)

```bash
docker compose up -d
```

### 3.5 Copy production config.xml into the container

```bash
# Copy the production config.xml into the Docker volume
docker cp /path/to/production/config.xml syspass-app:/var/www/syspass/app/config/config.xml
docker exec syspass-app chown www-data:www-data /var/www/syspass/app/config/config.xml
docker exec syspass-app chmod 640 /var/www/syspass/app/config/config.xml
```

### 3.6 Adjust config.xml if needed

If the database host changed (e.g., from `localhost` to an external IP), edit `config.xml`:

```bash
docker exec -it syspass-app bash
vi /var/www/syspass/app/config/config.xml
```

Fields to verify:

| Field | Description | Action |
|-------|-------------|--------|
| `<dbHost>` | Database server address | Update if DB host changed |
| `<dbPort>` | Database port | Usually 3306, no change needed |
| `<dbUser>` | Database user | Keep production value (`sp_XXXX`) |
| `<dbPass>` | Database password | Keep production value |
| `<dbName>` | Database name | Keep production value (`syspass`) |
| `<ldapServer>` | LDAP server address | Verify reachable from Docker container |
| `<ldapTlsEnabled>` | LDAP TLS | Keep `1` if using TLS (ensure CA certs are trusted) |

### 3.7 Clear caches

```bash
docker exec syspass-app rm -f /var/www/syspass/app/cache/config.cache
docker exec syspass-app rm -f /var/www/syspass/app/cache/*.cache
```

### 3.8 Restart the container

```bash
docker compose restart syspass
```

---

## Step 4 — Verify

### 4.1 Check container health

```bash
docker compose ps
docker logs syspass-app --tail 50
```

### 4.2 Test login

1. Open `http://<server>:8080` in a browser
2. Login with an LDAP user
3. Login with a local database user
4. Verify the account list loads correctly

### 4.3 Functional checklist

| Test | What to verify |
|------|---------------|
| Login (LDAP) | User authenticates, session starts, accounts visible |
| Login (local) | Database user authenticates correctly |
| Search | Account search with filters works |
| View password | Decrypt and display password |
| Create account | All fields save correctly |
| Edit account | Changes persist |
| User management | Create/edit users and profiles |
| Public links | Create and access a public link |
| Backup | Generate backup (DB + files) |
| API | JSON-RPC calls with auth token |

### 4.4 Check logs for errors

```bash
docker exec syspass-app cat /var/www/syspass/app/config/syspass.log | grep EXCEPTION
docker logs syspass-app 2>&1 | grep -i error
```

---

## Step 5 — Go Live

### 5.1 Switch traffic

If using a reverse proxy (nginx/Apache/HAProxy):

1. Point the proxy to the new container (`localhost:8080`)
2. Enable HTTPS termination at the proxy level
3. Add HSTS header at the proxy: `Strict-Transport-Security: max-age=31536000; includeSubDomains`

### 5.2 Enable HTTPS cookies

Once HTTPS is confirmed working, verify `docker/php.ini` has:

```ini
session.cookie_secure = 1
```

This is already set by default.

---

## Rollback Plan

If something goes wrong after migration:

```bash
# 1. Stop the new container
docker compose down

# 2. Restore the old application
# (re-enable the previous container/service/bare-metal setup)

# 3. Restore database if needed (only if data was modified post-migration)
mysql -u root -p syspass < syspass_backup_YYYYMMDD_HHMMSS.sql

# 4. Restore config.xml
cp config.xml.backup /path/to/production/syspass/app/config/config.xml
```

**Important:** The database schema is unchanged between 3.2.11 and 3.3.0. If no data was modified after migration (no new accounts created, no passwords changed), the database restore is unnecessary — just switch back to the old application.

---

## Troubleshooting

### "Unable to connect to LDAP server"

**Cause:** PHP 8.1+ returns `LDAP\Connection` object instead of resource. Fixed in 3.3.0.

If this error still appears:
- Verify LDAP server is reachable from inside the container: `docker exec syspass-app php -r "var_dump(ldap_connect('ldap://your-server:389'));"`
- If using TLS, ensure the CA certificate is trusted inside the container
- To add a custom CA: mount it as a volume and update `/etc/ldap/ldap.conf`

### "Access denied for user" after login

**Cause:** Database views (`account_data_v`, `account_search_v`) have a `DEFINER` referencing a user that doesn't exist.

**Fix:** Recreate views with `SQL SECURITY INVOKER` (see Step 2.2).

Check current definers:
```sql
SELECT TABLE_NAME, DEFINER FROM information_schema.VIEWS WHERE TABLE_SCHEMA = 'syspass';
```

### Redirect to `/undefined` on login

**Cause:** An exception with code 0 was treated as success by the frontend. Fixed in 3.3.0 (`LoginController.php`).

If this still occurs, check `app/config/syspass.log` for the actual exception message.

### "Could not set locale to en_US.utf8"

**Cause:** Locale not installed in the container. This is a warning and doesn't affect functionality.

To fix, add to Dockerfile:
```dockerfile
RUN sed -i '/en_US.UTF-8/s/^# //g' /etc/locale.gen && locale-gen
```

### RSA key errors / "Master password too short"

**Cause:** Stale RSA key files in `app/cache/`. The PKCS format was changed from PKCS1 to PKCS8 in 3.3.0.

**Fix:** Delete old key files and let the app regenerate them:
```bash
docker exec syspass-app rm -f /var/www/syspass/app/cache/*.pem
docker compose restart syspass
```

### OPcache issues

OPcache `validate_timestamps` is disabled in `docker/php.ini` for performance. If you update PHP files inside the container, restart it:
```bash
docker compose restart syspass
```

---

## Post-Migration Security Tasks

After the migration is stable, address remaining items from `SECURITY_AUDIT.md`:

| Priority | Task | Status |
|----------|------|--------|
| P0-3 | XXE — Add `LIBXML_NONET` to `loadXML()` calls | Pending (partially mitigated by PHP 8.5) |
| P1 | XSS — Escape output in templates with `htmlspecialchars()` | Pending |
| P1 | SQL injection — Whitelist table names in `Database.php`, `FileBackupService.php`, `MySQL.php` | Pending |
| P1 | Remove MD5/SHA1 auth fallback | Pending |
| P2 | Replace `uniqid()`/`mt_rand()` with `random_bytes()`/`random_int()` | Pending |
| P2 | CSRF token per-request rotation | Pending |
| P3 | Replace SHA1 with SHA256+ for session keys | Pending |
| P3 | Escape shell arguments in backup (`escapeshellarg()`) | Pending |
| — | Replace Klein router with Slim 4 | Planned |
| — | Update jQuery 3.3.1 → 3.7+ | Planned |
| — | Add HSTS header (requires HTTPS) | Pending |

---

## Files Reference

| File | Purpose |
|------|---------|
| `Dockerfile` | Application container (PHP 8.5 + Apache) |
| `docker-compose.yml` | Service orchestration |
| `docker/php.ini` | Hardened PHP configuration |
| `docker/apache-vhost.conf` | Apache vhost with security headers |
| `app/config/config.xml` | Application configuration (DB, LDAP, site settings) |
| `SECURITY_AUDIT.md` | Full vulnerability report with status |
| `MIGRATION_PHP81_PLAN.md` | Technical migration plan and execution log |
