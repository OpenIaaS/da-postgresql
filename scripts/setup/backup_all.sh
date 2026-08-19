#!/bin/bash
######################################################################################
#
#   Dump all PostgreSQL databases into CustomBuild (persists across plugin updates).
#   Default destination:
#     /usr/local/directadmin/custombuild/custom/postgresql-backups/<timestamp>/
#
#   Usage: backup_all.sh [--dir DIR] [--keep N]
#
######################################################################################

set -u
umask 077

PLUGIN_DIR="/usr/local/directadmin/plugins/postgresql"
BACKUP_ROOT="/usr/local/directadmin/custombuild/custom/postgresql-backups"
KEEP=7

[ -f "${PLUGIN_DIR}/exec/functions.inc.sh" ] || { echo "[ERROR] Plugin not installed"; exit 1; }
# shellcheck source=/dev/null
source "${PLUGIN_DIR}/exec/functions.inc.sh"

for ARG in "$@"; do
    case "${ARG}" in
        --dir=*) BACKUP_ROOT="${ARG#--dir=}" ;;
        --keep=*) KEEP="${ARG#--keep=}" ;;
        --debug) DEBUG=1 ;;
        --help|-h)
            echo "Usage: $0 [--dir=/path] [--keep=7]"
            exit 0
            ;;
    esac
done

DBCONF="${PLUGIN_DIR}/pgpass.conf"
[ -f "${DBCONF}" ] || die "Missing ${DBCONF} — run create_admin.sh first" 1

dbhost=$(awk -F: '{print $1}' "${DBCONF}")
dbport=$(awk -F: '{print $2}' "${DBCONF}")
dbuser=$(awk -F: '{print $4}' "${DBCONF}")
dbpass=$(awk -F: '{print $5}' "${DBCONF}")

find_bin()
{
    local name="$1" p
    for p in /usr/pgsql-*/bin/${name} /usr/local/pgsql/bin/${name} /usr/local/bin/${name} /usr/bin/${name} /bin/${name}; do
        if [ -x "${p}" ]; then
            echo "${p}"
            return 0
        fi
    done
    return 1
}

PSQL_BIN=$(find_bin psql) || die "psql not found" 20
DUMP_BIN=$(find_bin pg_dump) || die "pg_dump not found" 21
DUMPALL_BIN=$(find_bin pg_dumpall) || die "pg_dumpall not found" 22

export PGHOST="${dbhost}"
export PGPORT="${dbport}"
export PGUSER="${dbuser}"
export PGPASSWORD="${dbpass}"
export PGCONNECT_TIMEOUT=8

if ! "${PSQL_BIN}" -d postgres -tAc "SELECT 1" >/dev/null 2>&1; then
    unset PGPASSWORD
    die "Cannot connect to PostgreSQL with plugin credentials" 30
fi

# Require some free space on the CustomBuild filesystem
CB_FS="/usr/local/directadmin/custombuild"
[ -d "${CB_FS}" ] || mkdir -p "${CB_FS}"
FREE_KB=$(df -Pk "${CB_FS}" | awk 'NR==2 {print $4}')
if [ -n "${FREE_KB}" ] && [ "${FREE_KB}" -lt 1048576 ]; then
    unset PGPASSWORD
    die "Less than 1 GiB free on ${CB_FS} (${FREE_KB} KiB) — refusing to dump" 40
fi

STAMP=$(date +%Y%m%d-%H%M%S)
DEST="${BACKUP_ROOT}/${STAMP}"
mkdir -p "${DEST}"
chmod 700 "${BACKUP_ROOT}" "${DEST}"
chown root:root "${BACKUP_ROOT}" "${DEST}" 2>/dev/null || true

echo "[OK] Dumping PostgreSQL into ${DEST}"

{
    echo "plugin=$(grep '^version=' "${PLUGIN_DIR}/plugin.conf" 2>/dev/null | cut -d= -f2)"
    echo "stamp=${STAMP}"
    echo "host=${dbhost}"
    echo "port=${dbport}"
    echo "pg_dump=$(${DUMP_BIN} --version | head -1)"
    echo "server=$(${PSQL_BIN} -d postgres -tAc 'SHOW server_version' | xargs)"
} > "${DEST}/MANIFEST.txt"

if ! "${DUMPALL_BIN}" --globals-only --no-role-passwords -f "${DEST}/globals.sql" 2>"${DEST}/globals.err"; then
    echo "[WARNING] pg_dumpall --globals-only failed (see globals.err)"
fi
# Roles WITH passwords for disaster recovery (mode 600)
if ! "${DUMPALL_BIN}" --roles-only -f "${DEST}/roles.sql" 2>"${DEST}/roles.err"; then
    echo "[WARNING] pg_dumpall --roles-only failed (see roles.err)"
fi
chmod 600 "${DEST}/globals.sql" "${DEST}/roles.sql" 2>/dev/null || true

FAILED=0
while IFS= read -r DB; do
    [ -n "${DB}" ] || continue
    echo "[OK] Dumping database ${DB}"
    if ! "${DUMP_BIN}" --format=custom --file="${DEST}/${DB}.dump" "${DB}" 2>>"${DEST}/dump.err"; then
        echo "[ERROR] custom dump failed: ${DB}"
        FAILED=$((FAILED+1))
        continue
    fi
    if ! "${DUMP_BIN}" --no-owner --no-privileges --clean --if-exists --inserts -f - "${DB}" 2>>"${DEST}/dump.err" | gzip -c > "${DEST}/${DB}.sql.gz"; then
        echo "[WARNING] plain gzip dump failed: ${DB}"
    fi
    chmod 600 "${DEST}/${DB}.dump" "${DEST}/${DB}.sql.gz" 2>/dev/null || true
    if [ ! -s "${DEST}/${DB}.dump" ]; then
        echo "[ERROR] empty dump: ${DB}"
        FAILED=$((FAILED+1))
    fi
done < <("${PSQL_BIN}" -d postgres -tAc "SELECT datname FROM pg_database WHERE datallowconn AND NOT datistemplate ORDER BY datname;")

unset PGPASSWORD
unset PGUSER
unset PGHOST
unset PGPORT

(cd "${DEST}" && sha256sum -- * >/dev/null 2>&1 && sha256sum -- * > SHA256SUMS) || true
chmod 600 "${DEST}/SHA256SUMS" "${DEST}/MANIFEST.txt" 2>/dev/null || true
chmod 600 "${DEST}/"*.err 2>/dev/null || true

# Prune old sets
if echo "${KEEP}" | grep -Eq '^[0-9]+$' && [ "${KEEP}" -gt 0 ]; then
    # shellcheck disable=SC2012
    ls -1dt "${BACKUP_ROOT}"/*/ 2>/dev/null | tail -n +$((KEEP+1)) | while read -r old; do
        echo "[OK] Removing old backup ${old}"
        rm -rf "${old}"
    done
fi

if [ "${FAILED}" -gt 0 ]; then
    echo "[ERROR] ${FAILED} database dump(s) failed — see ${DEST}"
    exit 50
fi

echo "[OK] Backup complete: ${DEST}"
ls -lah "${DEST}"
exit 0
