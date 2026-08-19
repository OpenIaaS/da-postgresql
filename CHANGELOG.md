# Changelog

## 0.3.1 — 2026-08-19

Public **beta**: documented as under test; not production-certified.

### Update / backups

- `scripts/update.sh` asks (TTY) whether to dump **all** databases before replacing files
- Non-interactive DirectAdmin GUI update dumps automatically (`--skip-backup` to opt out)
- Dumps land in `/usr/local/directadmin/custombuild/custom/postgresql-backups/<timestamp>/` (custom format + `.sql.gz`, roles, SHA256; last 7 kept)
- `scripts/setup/backup_all.sh` for the same dump outside of an update
- `--from-github` pulls/rsyncs from this repo
- **Does not rotate** the `diradmin` password on update (`create_admin.sh --keep-password`)
- Preserves `pgpass.conf`, phpPgAdmin `config.inc.php`, and SSO files
- Aborts if the dump fails unless `--force`

### PHP 8.5

- Documented and supported alongside 8.2 / 8.3 / 8.4
- Removed `disable_classes` from plugin `php.ini` (INI setting removed in PHP 8.5)
- Update script prints a PHP CLI version warning outside 8.2–8.5

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
