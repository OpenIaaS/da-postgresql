# DirectAdmin PostgreSQL plugin (PHP 8.2–8.5)

Fork of [shvaber/da-postgresql](https://github.com/shvaber/da-postgresql) (Poralix), maintained at
[OpenIaaS/da-postgresql](https://github.com/OpenIaaS/da-postgresql).

**Status: public beta.** Version **0.3.1** is under active validation on DirectAdmin with PHP 8.2–8.5 and PostgreSQL 14+. Interfaces, install paths, and backup behaviour are in place, but the release is **not yet production-certified**. Run it first on a staging server (or a snapshot of production), take a PostgreSQL dump before upgrading, and treat findings as part of the test cycle. Please report issues on the GitHub tracker.

phpPgAdmin is installed from the maintained community fork
[ReimuHakurei/phpPgAdmin](https://github.com/ReimuHakurei/phpPgAdmin) (`v7.14.8-mod`), not the abandoned upstream 7.13 tree.

## Requirements

- DirectAdmin with **PHP 8.2, 8.3, 8.4 or 8.5** (plugin PHP **and** the webapps PHP used by phpPgAdmin)
- PHP extensions: `pgsql` (and `pdo_pgsql` recommended)
- PHP functions: `exec` (plugin dumps/restores). Do **not** rely on the backtick operator — it is deprecated in PHP 8.5
- PostgreSQL server 14+ (15/16/17 OK). Keep `password_encryption = scram-sha-256`
- `git`, `rsync`, `gzip`, `unzip`, `gcc` (for the upload helper)
- CustomBuild `pgsql` module built for each PHP version you run

The plugin does **not** install PostgreSQL itself. Build PHP `--with-pgsql` / `--with-pdo-pgsql` the usual DirectAdmin way.

### PHP 8.5 notes

PHP 8.5 (20 Nov 2025) is supported. Plugin-side changes:

- `disable_classes` is **not** set in `php.ini` (the INI key was **removed** in 8.5)
- no backtick shells, no `(boolean)`/`(integer)` casts, no `each()` / `get_magic_quotes_gpc()`
- `htmlspecialchars()` always gets a string (`ENT_QUOTES | ENT_SUBSTITUTE`)
- dynamic properties declared; identifiers go through `pg_escape_identifier`
- `pg_close` / `pg_connect` use the PHP 8 `PgSql\Connection` object API

phpPgAdmin 7.14.8-mod already targets PHP 7.4+ and runs on 8.5 (SCRAM, no deprecated constructor args). Rebuild CustomBuild PHP 8.5 **with pgsql** before switching the plugin PHP.

## Install

```bash
cd /usr/local/directadmin/plugins
git clone https://github.com/OpenIaaS/da-postgresql.git postgresql
cd postgresql
chmod 700 scripts/*.sh scripts/setup/*.sh exec/*.sh exec/hooks/*.sh
./scripts/install.sh
```

`install.sh` now:

1. Sets permissions and compiles `move_uploaded_file`
2. Adds the `postgresql` custom package item
3. Enables DirectAdmin **user backup / restore hooks** so PostgreSQL data is included in account backups
4. Creates the `diradmin` PostgreSQL superuser (`create_admin.sh --strict`, scram auth) — **only rotates the password on first install**
5. Installs **phpPgAdmin community fork** under `/var/www/html/phpPgAdmin`
6. Revokes `PUBLIC` connect on customer databases (`restrict_access_dbs.sh`)
7. Installs `/etc/cron.d/da-postgresql` (daily 03:15 phpPgAdmin update + log rotate)
8. Activates the plugin

Re-save **every** User Package (including `admin`) and set how many PostgreSQL databases the package may create.

## Update

```bash
cd /usr/local/directadmin/plugins/postgresql
./scripts/update.sh                 # asks (TTY) / auto-dumps (GUI)
./scripts/update.sh --backup        # always dump first
./scripts/update.sh --skip-backup   # skip dumps
./scripts/update.sh --from-github   # git pull / rsync from GitHub then install
```

On a TTY the script **asks** whether to dump all databases first. Confirm with `Y` (default, 60s timeout). DirectAdmin’s non-interactive updater dumps automatically unless you pass `--skip-backup`.

Dumps go to CustomBuild so they survive plugin replacement:

```
/usr/local/directadmin/custombuild/custom/postgresql-backups/<YYYYMMDD-HHMMSS>/
  MANIFEST.txt
  globals.sql          # tablespaces / roles without passwords
  roles.sql            # roles WITH passwords (mode 0600)
  <dbname>.dump        # pg_dump -Fc
  <dbname>.sql.gz      # portable SQL
  SHA256SUMS
```

Last **7** sets are kept. Update **does not** rotate the `diradmin` password and **keeps** the existing phpPgAdmin `config.inc.php` and SSO files.

Standalone dump (same location):

```bash
./scripts/setup/backup_all.sh
./scripts/setup/backup_all.sh --keep=14
```

## phpPgAdmin

- Source: `https://github.com/ReimuHakurei/phpPgAdmin` (override with `PHPPGADMIN_REPO` / `PHPPGADMIN_REF`)
- Re-install / update: `/usr/local/directadmin/plugins/postgresql/scripts/setup/phppgadmin.sh`
- Daily cron pulls that same installer so the webapp tracks the community fork
- Config template: `scripts/setup/phpPgAdmin/config.inc.php-dist` (`owned_only`, extra login + session security, SSO cookies HttpOnly/SameSite)
- Do **not** use phpPgAdmin 7.12/7.13 from the original plugin — they break on PHP 8

## Backups

| Path | What |
| --- | --- |
| **Plugin update** | Prompt / auto dump into CustomBuild `custom/postgresql-backups/` |
| DirectAdmin user/reseller/admin backup | Hook `user_backup_compress_pre.sh` dumps roles + each DB into `backup/psql/` inside the archive |
| DirectAdmin restore | Hook `user_restore_post.sh` → `dbimportuser.sh` recreates roles/DBs then imports SQL |
| Plugin UI download | gzip SQL dump of one database |
| Plugin UI restore | upload `.sql` / `.sql.gz` / `.zip` / `.tar` |

Manual per-user:

```bash
/usr/local/directadmin/plugins/postgresql/exec/dbbackupuser.sh USER /path/to/dir
/usr/local/directadmin/plugins/postgresql/exec/dbrestore.sh /path/to/dump.sql.gz DBNAME
```

Enable/disable account-backup hooks:

```bash
scripts/setup/custom_backups.sh enable|disable
scripts/setup/custom_restore.sh enable|disable
```

## Cron

`/etc/cron.d/da-postgresql` runs `scripts/setup/cron_update.sh` daily (phpPgAdmin + log rotate — **not** a full DB dump).

```bash
scripts/setup/install_cron.sh enable|disable
```

Logs: `/usr/local/directadmin/plugins/postgresql/logs/` (rotated after 14 days).

## Security notes

- SQL identifiers/literals are escaped (`pg_escape_identifier` / `pg_escape_literal`); names must match `[A-Za-z_][A-Za-z0-9_]*`
- New databases: `REVOKE ALL … FROM PUBLIC`, roles created `NOSUPERUSER NOCREATEDB NOCREATEROLE`
- SSO no longer mis-parses `username=` from the DirectAdmin session file
- phpPgAdmin extra login security on; SSO cookies are HttpOnly + SameSite=Lax
- Strict `pg_hba.conf` uses `scram-sha-256` (do **not** force `password_encryption = md5`)
- `pgpass.conf`, SSO files, and CustomBuild dumps are mode `0600`
- Updates never rewrite the `diradmin` password when `pgpass.conf` already exists

See [SECURITY.md](SECURITY.md).

## PHP 8 fixes (vs 0.2.1)

- Removed `get_magic_quotes_gpc()` / `each()` (fatal on PHP 8)
- Declared dynamic properties (`query`, `_DA_CONF`, `_ERROR_TEXT` as array)
- Replaced `${var}` interpolation
- Null-safe `$_SERVER['LANGUAGE']` and `htmlspecialchars()`
- `pg_connect` conninfo quoting; `pg_query` on a missing connection no longer TypeErrors
- PHP 8.5: dropped `disable_classes` from plugin `php.ini`

## Docs for operators

1. Install PostgreSQL packages for your OS
2. CustomBuild: add `--with-pgsql=/usr` (or `/usr/pgsql-XX`) to `custom/php_extensions/*.conf` and rebuild PHP (including 8.5)
3. Install this plugin
4. Set package limits
5. Confirm Admin → PostgreSQL plugin status page is green

## License

Apache-2.0. Original work © Poralix / Alex Grebenschikov. This fork © OpenIaaS.
