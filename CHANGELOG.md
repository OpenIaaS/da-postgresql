# Changelog

## 0.3.0 — 2026-08-19

PHP 8.2 / 8.3 / 8.4 fork maintained by OpenIaaS.

### phpPgAdmin

- Installs [ReimuHakurei/phpPgAdmin](https://github.com/ReimuHakurei/phpPgAdmin) `v7.14.8-mod` instead of abandoned `phppgadmin/phppgadmin` 7.12/7.13
- Daily cron (`/etc/cron.d/da-postgresql`) re-runs the installer and rotates logs
- Hardened `config.inc.php` (owned_only, extra login/session security, HttpOnly SSO cookies)

### PHP 8

- Removed `get_magic_quotes_gpc()` and `each()` (fatal on PHP 8)
- Declared dynamic properties; `_ERROR_TEXT` is always an array
- Null-safe language / htmlspecialchars / pg_connect / pg_query
- Connection strings quote passwords; identifiers use `pg_escape_*`

### Backups

- `install.sh` enables DirectAdmin backup **and** restore hooks (they were never wired before)
- Fixed `dbbackupuser.sh` SQL quoting for database owner
- `dbdump.sh` uses `--clean --if-exists --no-owner --no-privileges`
- Restore accepts modern `file(1)` MIME types (`application/gzip`, not only `x-gzip`)
- Restore allows a database named exactly as the DirectAdmin user (not only `user_*`)
- Download helper no longer treats `tempnam()` as boolean-or

### Security

- No `GRANT … TO PUBLIC` on new databases
- Roles created without SUPERUSER/CREATEDB/CREATEROLE
- SSO session parse bug fixed
- Strict `pg_hba` uses `scram-sha-256`
- `createGrants()` now connects to the **target** database (previously compared the admin connection name and skipped grants)

### Docs

- README, SECURITY, this changelog
