# DirectAdmin PostgreSQL plugin (PHP 8)

Fork of [shvaber/da-postgresql](https://github.com/shvaber/da-postgresql) (Poralix) maintained at
[OpenIaaS/da-postgresql](https://github.com/OpenIaaS/da-postgresql).

Version **0.3.0** targets **PHP 8.2 / 8.3 / 8.4**, DirectAdmin Evolution, and PostgreSQL 14+.

phpPgAdmin is installed from the actively maintained community fork
[ReimuHakurei/phpPgAdmin](https://github.com/ReimuHakurei/phpPgAdmin) (`v7.14.8-mod`), not the abandoned upstream 7.13 tree.

## Requirements

- DirectAdmin with PHP 8.2+ (plugin PHP and phpPgAdmin)
- PHP extensions: `pgsql` (and `pdo_pgsql` recommended)
- PHP functions: `exec` (plugin dumps/restores)
- PostgreSQL server 14+ (15/16/17 OK). Keep `password_encryption = scram-sha-256`
- `git`, `rsync`, `unzip`, `gcc` (for the upload helper)
- CustomBuild `pgsql` module built for the PHP version used by DirectAdmin plugins **and** the webapps PHP used by phpPgAdmin

The plugin does **not** install PostgreSQL itself. Build PHP `--with-pgsql` / `--with-pdo-pgsql` the usual DirectAdmin way.

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
4. Creates the `diradmin` PostgreSQL superuser (`create_admin.sh --strict`, scram auth)
5. Installs **phpPgAdmin community fork** under `/var/www/html/phpPgAdmin`
6. Revokes `PUBLIC` connect on customer databases (`restrict_access_dbs.sh`)
7. Installs `/etc/cron.d/da-postgresql` (daily 03:15 phpPgAdmin update + log rotate)
8. Activates the plugin

Re-save **every** User Package (including `admin`) and set how many PostgreSQL databases the package may create.

## phpPgAdmin

- Source: `https://github.com/ReimuHakurei/phpPgAdmin` (override with `PHPPGADMIN_REPO` / `PHPPGADMIN_REF`)
- Re-install / update: `/usr/local/directadmin/plugins/postgresql/scripts/setup/phppgadmin.sh`
- Daily cron pulls that same installer so the webapp tracks the community fork
- Config template: `scripts/setup/phpPgAdmin/config.inc.php-dist` (`owned_only`, extra login + session security, SSO cookies HttpOnly/SameSite)
- Do **not** use phpPgAdmin 7.12/7.13 from the original plugin — they break on PHP 8

## Backups

| Path | What |
| --- | --- |
| DirectAdmin user/reseller/admin backup | Hook `user_backup_compress_pre.sh` dumps roles + each DB into `backup/psql/` inside the archive |
| DirectAdmin restore | Hook `user_restore_post.sh` → `dbimportuser.sh` recreates roles/DBs then imports SQL |
| Plugin UI download | gzip SQL dump of one database |
| Plugin UI restore | upload `.sql` / `.sql.gz` / `.zip` / `.tar` |

Manual:

```bash
/usr/local/directadmin/plugins/postgresql/exec/dbbackupuser.sh USER /path/to/dir
/usr/local/directadmin/plugins/postgresql/exec/dbrestore.sh /path/to/dump.sql.gz DBNAME
```

Enable/disable hooks:

```bash
scripts/setup/custom_backups.sh enable|disable
scripts/setup/custom_restore.sh enable|disable
```

## Cron

`/etc/cron.d/da-postgresql` runs `scripts/setup/cron_update.sh` daily.

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
- `pgpass.conf` and SSO files are mode `0600`

See [SECURITY.md](SECURITY.md).

## PHP 8 fixes (vs 0.2.1)

- Removed `get_magic_quotes_gpc()` / `each()` (fatal on PHP 8)
- Declared dynamic properties (`query`, `_DA_CONF`, `_ERROR_TEXT` as array)
- Replaced `${var}` interpolation
- Null-safe `$_SERVER['LANGUAGE']` and `htmlspecialchars()`
- `pg_connect` conninfo quoting; `pg_query` on a missing connection no longer TypeErrors

## Docs for operators

1. Install PostgreSQL packages for your OS
2. CustomBuild: add `--with-pgsql=/usr` (or `/usr/pgsql-XX`) to `custom/php_extensions/*.conf` and rebuild PHP
3. Install this plugin
4. Set package limits
5. Confirm Admin → PostgreSQL plugin status page is green

## License

Apache-2.0. Original work © Poralix / Alex Grebenschikov. This fork © OpenIaaS.
