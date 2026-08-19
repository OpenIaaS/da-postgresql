#!/bin/bash
######################################################################################
#
#   Postgresql integration for DirectAdmin $ 0.3.1
#
#   Usage:
#     ./scripts/update.sh
#     ./scripts/update.sh --backup
#     ./scripts/update.sh --skip-backup
#     ./scripts/update.sh --from-github
#
#   Interactive: asks whether to dump all databases into
#     /usr/local/directadmin/custombuild/custom/postgresql-backups/
#   Non-interactive (DirectAdmin GUI / cron): dumps automatically unless --skip-backup.
#
######################################################################################

set -u

PLUGIN_DIR="/usr/local/directadmin/plugins/postgresql"
PLUGIN_REPO="${PLUGIN_REPO:-https://github.com/OpenIaaS/da-postgresql.git}"
PLUGIN_REF="${PLUGIN_REF:-main}"
LOG_DIR="${PLUGIN_DIR}/logs"
mkdir -p "${LOG_DIR}"
LOG_FILE="${LOG_DIR}/update.$(date +%Y%m%d.%s).log"

FORCE_BACKUP=0
SKIP_BACKUP=0
FROM_GITHUB=0
FORCE=0

for ARG in "$@"; do
    case "${ARG}" in
        --backup) FORCE_BACKUP=1 ;;
        --skip-backup|--no-backup) SKIP_BACKUP=1 ;;
        --from-github|--pull) FROM_GITHUB=1 ;;
        --force) FORCE=1 ;;
        --help|-h)
            sed -n '2,20p' "$0"
            exit 0
            ;;
    esac
done

exec > >(tee -a "${LOG_FILE}") 2>&1

echo "==== plugin update $(date -u +%Y-%m-%dT%H:%M:%SZ) ===="

php_warn()
{
    local phpbin="" ver=""
    for phpbin in /usr/local/bin/php /usr/bin/php php; do
        command -v "${phpbin}" >/dev/null 2>&1 && break
    done
    if ! command -v "${phpbin}" >/dev/null 2>&1; then
        echo "[WARNING] php CLI not in PATH — cannot verify 8.2–8.5"
        return 0
    fi
    ver=$("${phpbin}" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)
    echo "[OK] PHP CLI ${ver} (${phpbin})"
    case "${ver}" in
        8.2|8.3|8.4|8.5)
            ;;
        8.0|8.1)
            echo "[WARNING] PHP ${ver} is below the supported range (8.2–8.5)"
            ;;
        8.*)
            echo "[WARNING] PHP ${ver} is newer than tested 8.5 — plugin should run, report issues"
            ;;
        *)
            echo "[WARNING] PHP ${ver} is not in 8.x — plugin requires PHP 8.2+"
            ;;
    esac
}

pull_from_github()
{
    if [ ! -d "${PLUGIN_DIR}/.git" ] && [ "${FROM_GITHUB}" != "1" ]; then
        echo "[OK] No .git directory — assuming DirectAdmin already extracted the new files"
        return 0
    fi
    if ! command -v git >/dev/null 2>&1; then
        echo "[WARNING] git not installed, skip pull"
        return 0
    fi
    if [ -d "${PLUGIN_DIR}/.git" ]; then
        echo "[OK] Fast-forward from ${PLUGIN_REPO} (${PLUGIN_REF})"
        git -C "${PLUGIN_DIR}" remote set-url origin "${PLUGIN_REPO}" 2>/dev/null || true
        git -C "${PLUGIN_DIR}" fetch origin "${PLUGIN_REF}" || return 1
        git -C "${PLUGIN_DIR}" pull --ff-only origin "${PLUGIN_REF}" || return 1
        return 0
    fi
    echo "[OK] Downloading ${PLUGIN_REPO}@${PLUGIN_REF} into a temp tree"
    local tmp
    tmp=$(mktemp -d /tmp/da-postgresql.src.XXXXXX)
    if ! git clone --depth 1 --branch "${PLUGIN_REF}" "${PLUGIN_REPO}" "${tmp}"; then
        rm -rf "${tmp}"
        return 1
    fi
    rsync -a --delete \
        --exclude 'pgpass.conf' \
        --exclude 'data/sso/' \
        --exclude 'logs/' \
        --exclude '.git/' \
        --exclude 'exec/test_php.php' \
        --exclude 'exec/move_uploaded_file' \
        "${tmp}/" "${PLUGIN_DIR}/"
    rm -rf "${tmp}"
    echo "[OK] Plugin files synced from GitHub"
}

ask_backup()
{
    if [ "${SKIP_BACKUP}" = "1" ]; then
        echo "[OK] Skipping database backup (--skip-backup)"
        return 1
    fi
    if [ "${FORCE_BACKUP}" = "1" ]; then
        return 0
    fi
    if [ ! -t 0 ]; then
        echo "[OK] Non-interactive update: backing up databases (pass --skip-backup to skip)"
        return 0
    fi
    echo
    echo "Backup all PostgreSQL databases before updating the plugin?"
    echo "  Destination: /usr/local/directadmin/custombuild/custom/postgresql-backups/<timestamp>/"
    echo "  (plain .sql.gz + custom .dump, roles, SHA256; last 7 sets kept)"
    local ans=""
    read -r -t 60 -p "Proceed with backup? [Y/n] " ans || ans="Y"
    case "${ans}" in
        n|N|no|NO|не|Не) return 1 ;;
        *) return 0 ;;
    esac
}

php_warn

# 1) Backup first — never replace files before a dump if the operator asked for one
if ask_backup; then
    if [ -x "${PLUGIN_DIR}/scripts/setup/backup_all.sh" ]; then
        if ! "${PLUGIN_DIR}/scripts/setup/backup_all.sh"; then
            echo "[ERROR] Database backup failed"
            if [ "${FORCE}" != "1" ]; then
                echo "[ERROR] Aborting update. Re-run with --force to update anyway, or --skip-backup."
                exit 2
            fi
            echo "[WARNING] --force: continuing despite backup failure"
        fi
    else
        echo "[ERROR] backup_all.sh missing"
        [ "${FORCE}" = "1" ] || exit 2
    fi
fi

# 2) Snapshot credentials and phpPgAdmin config
TMPDIR=$(mktemp -d /tmp/da-postgresql.update.XXXXXX)
chmod 700 "${TMPDIR}"
if [ -f "${PLUGIN_DIR}/pgpass.conf" ]; then
    cp -p "${PLUGIN_DIR}/pgpass.conf" "${TMPDIR}/pgpass.conf"
fi
if [ -f /usr/local/directadmin/.pgpass ]; then
    cp -p /usr/local/directadmin/.pgpass "${TMPDIR}/dot.pgpass"
fi
if [ -f /var/www/html/phpPgAdmin/conf/config.inc.php ]; then
    cp -p /var/www/html/phpPgAdmin/conf/config.inc.php "${TMPDIR}/config.inc.php"
fi
if [ -d "${PLUGIN_DIR}/data/sso" ]; then
    cp -a "${PLUGIN_DIR}/data/sso" "${TMPDIR}/sso"
fi

# 3) Pull latest sources when this is a git checkout or --from-github
if ! pull_from_github; then
    echo "[ERROR] Failed to download plugin sources"
    [ "${FORCE}" = "1" ] || { rm -rf "${TMPDIR}"; exit 3; }
fi

# 4) Re-run installer without rotating the diradmin password
export DA_PG_UPDATE_MODE=1
export DA_PG_KEEP_PPA_CONF=1
chmod 700 "${PLUGIN_DIR}/scripts/"*.sh "${PLUGIN_DIR}/scripts/setup/"*.sh 2>/dev/null || true
"${PLUGIN_DIR}/scripts/install.sh"

# 5) Restore preserved secrets / config
if [ -f "${TMPDIR}/pgpass.conf" ]; then
    cp -p "${TMPDIR}/pgpass.conf" "${PLUGIN_DIR}/pgpass.conf"
    chmod 600 "${PLUGIN_DIR}/pgpass.conf"
    chown diradmin:diradmin "${PLUGIN_DIR}/pgpass.conf" 2>/dev/null || true
fi
if [ -f "${TMPDIR}/dot.pgpass" ]; then
    cp -p "${TMPDIR}/dot.pgpass" /usr/local/directadmin/.pgpass
    chmod 600 /usr/local/directadmin/.pgpass
fi
if [ -f "${TMPDIR}/config.inc.php" ]; then
    mkdir -p /var/www/html/phpPgAdmin/conf
    cp -p "${TMPDIR}/config.inc.php" /var/www/html/phpPgAdmin/conf/config.inc.php
    chmod 640 /var/www/html/phpPgAdmin/conf/config.inc.php
    chown webapps:webapps /var/www/html/phpPgAdmin/conf/config.inc.php 2>/dev/null || true
fi
if [ -d "${TMPDIR}/sso" ]; then
    mkdir -p "${PLUGIN_DIR}/data/sso"
    cp -a "${TMPDIR}/sso/." "${PLUGIN_DIR}/data/sso/"
    chmod 700 "${PLUGIN_DIR}/data/sso"
    find "${PLUGIN_DIR}/data/sso" -type f -exec chmod 600 {} \;
fi
rm -rf "${TMPDIR}"

echo "Plugin has been updated to 0.3.1 (PHP 8.2–8.5)."
echo "Log: ${LOG_FILE}"
exit 0
