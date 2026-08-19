# Security notes (plugin 0.3.0)

## Restore as a non-superuser

When importing backups as a user, attempts to change a password or gain higher privileges still fail at PostgreSQL (not in PHP):

- Change superuser password → `ERROR: must be superuser to alter superusers`
- Change another role's password → `ERROR: permission denied`
- `ALTER ROLE … WITH SUPERUSER` → `ERROR: must be superuser to alter superusers`

Do **not** restore user-uploaded dumps as the `diradmin` superuser unless you trust the file. The UI restore uses the customer's DB user.

## Hardening in 0.3.0

- Identifiers validated (`^[A-Za-z_][A-Za-z0-9_]*$`) and passed through `pg_escape_identifier` / `pg_escape_literal`
- `CREATE DATABASE` no longer `GRANT CONNECT … TO PUBLIC`
- Customer roles: `NOSUPERUSER NOCREATEDB NOCREATEROLE`
- `restrict_access_dbs.sh --run` revokes PUBLIC on `template1` and existing DBs
- phpPgAdmin: `extra_login_security=true`, `owned_only=true`, `extra_session_security=true`, min password 12
- SSO cookies: HttpOnly, SameSite=Lax, 12h TTL, `hash_equals` token check
- DirectAdmin session parser now reads the **value** after `username=` / `passwd=` (0.2.1 used `substr(..., 0, len)` and captured the key name)
- Upload helper stays setuid `root:diradmin` 4550; restore temp files 0600
- Daily cron only updates phpPgAdmin from the pinned GitHub fork (https)
- Plugin PHP: `disable_functions` still blocks `passthru` / `shell_exec` / `proc_open` in `php.ini` used by phpPgAdmin

## Credentials

- `/usr/local/directadmin/plugins/postgresql/pgpass.conf` — plugin superuser, mode 0600
- `/usr/local/directadmin/.pgpass` — same, for CLI
- `data/sso/user.*.pgpass.conf` — per-user SSO, mode 0600
- Never commit these files; they are in `.gitignore`

## Auth model

Prefer `scram-sha-256` (`create_admin.sh --strict`). Do not switch PostgreSQL back to `md5` for phpPgAdmin — the community fork supports SCRAM.

## Reporting

Open an issue on https://github.com/OpenIaaS/da-postgresql
