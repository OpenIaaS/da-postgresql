#!/bin/bash
######################################################################################
#
#   Postgresql integration for DirectAdmin $ 0.3.0
#   Installs the community phpPgAdmin fork (ReimuHakurei) with PHP 8 support.
#   Source: https://github.com/ReimuHakurei/phpPgAdmin
#
######################################################################################

set -u

PLUGIN_DIR="/usr/local/directadmin/plugins/postgresql"
PHPPGADMIN_REPO="${PHPPGADMIN_REPO:-https://github.com/ReimuHakurei/phpPgAdmin.git}"
PHPPGADMIN_REF="${PHPPGADMIN_REF:-v7.14.8-mod}"
WEBROOT="/var/www/html"
INSTALL_NAME="phpPgAdmin"
SRC_DIR="/usr/local/src/phppgadmin-community"
LOG_DIR="${PLUGIN_DIR}/logs"
mkdir -p "${LOG_DIR}"

PSQL_BIN="/usr/local/bin/psql"
[ ! -x "${PSQL_BIN}" ] && PSQL_BIN="/usr/bin/psql"
[ ! -x "${PSQL_BIN}" ] && echo "[ERROR] PostgreSQL is not installed! You should first install PostgreSQL." && exit 1

GIT_BIN="/usr/local/bin/git"
[ ! -x "${GIT_BIN}" ] && GIT_BIN="/usr/bin/git"
[ ! -x "${GIT_BIN}" ] && echo "[ERROR] Git is not installed! You should first install git." && exit 2

RSYNC_BIN="/usr/local/bin/rsync"
[ ! -x "${RSYNC_BIN}" ] && RSYNC_BIN="/usr/bin/rsync"
[ ! -x "${RSYNC_BIN}" ] && echo "[ERROR] Rsync is not installed! You should first install rsync." && exit 3

CONF_DIST="${PLUGIN_DIR}/scripts/setup/phpPgAdmin/config.inc.php-dist"
PATCH_SRC="${PLUGIN_DIR}/scripts/setup/phpPgAdmin/Postgres.php.patch"

echo "[OK] Cloning phpPgAdmin community fork ${PHPPGADMIN_REPO} (${PHPPGADMIN_REF})"
rm -rf "${SRC_DIR}"
mkdir -p /usr/local/src
if ! ${GIT_BIN} clone --depth 1 --branch "${PHPPGADMIN_REF}" "${PHPPGADMIN_REPO}" "${SRC_DIR}"; then
    echo "[WARNING] Tag ${PHPPGADMIN_REF} not found, cloning default branch"
    rm -rf "${SRC_DIR}"
    ${GIT_BIN} clone --depth 1 "${PHPPGADMIN_REPO}" "${SRC_DIR}" || exit 10
fi

VER=$(cd "${SRC_DIR}" && ${GIT_BIN} describe --tags --always 2>/dev/null | tr '/' '-')
[ -z "${VER}" ] && VER="community"
TARGET="${WEBROOT}/${INSTALL_NAME}-${VER}"
CONF_FILE="${WEBROOT}/${INSTALL_NAME}/conf/config.inc.php"

echo "[OK] Installing phpPgAdmin ${VER} into ${TARGET}"
rm -rf "${WEBROOT}/${INSTALL_NAME}-"* 2>/dev/null
mkdir -p "${TARGET}"
${RSYNC_BIN} -a --delete --exclude '.git' "${SRC_DIR}/" "${TARGET}/"
rm -f "${WEBROOT}/${INSTALL_NAME}"
ln -s "${TARGET}" "${WEBROOT}/${INSTALL_NAME}"
chown -R webapps:webapps "${TARGET}"
chown -h webapps:webapps "${WEBROOT}/${INSTALL_NAME}"

if [ ! -f "${CONF_DIST}" ]; then
    echo "[ERROR] Missing plugin config template ${CONF_DIST}"
    exit 20
fi

echo "[OK] Writing hardened phpPgAdmin config"
mkdir -p "$(dirname "${CONF_FILE}")"
if [ "${DA_PG_KEEP_PPA_CONF:-0}" = "1" ] && [ -f "${CONF_FILE}" ]; then
    echo "[OK] Keeping existing ${CONF_FILE} (DA_PG_KEEP_PPA_CONF=1)"
else
    cp -p "${CONF_DIST}" "${CONF_FILE}"
    # Prefer real pg_dump path
    for DUMP in /usr/pgsql-*/bin/pg_dump /usr/bin/pg_dump /usr/local/bin/pg_dump; do
        if [ -x "${DUMP}" ]; then
            DUMPALL="$(dirname "${DUMP}")/pg_dumpall"
            perl -pi -e "s#/usr/bin/pg_dump#${DUMP}#g" "${CONF_FILE}"
            if [ -x "${DUMPALL}" ]; then
                perl -pi -e "s#/usr/bin/pg_dumpall#${DUMPALL}#g" "${CONF_FILE}"
            fi
            break
        fi
    done
fi
chmod 640 "${CONF_FILE}"
chown webapps:webapps "${CONF_FILE}"

echo "[OK] Rewriting web-server configs"
if [ -d /usr/local/directadmin/custombuild ]; then
    cd /usr/local/directadmin/custombuild || true
    mkdir -p custom
    c=$(grep -m1 -c "^phppgadmin=" custom/webapps.list 2>/dev/null || true)
    if [ -z "${c}" ] || [ "${c}" = "0" ]; then
        echo "phppgadmin=phpPgAdmin" >> custom/webapps.list
        ./build rewrite_confs || true
    fi
fi

echo "[OK] Applying DirectAdmin SSO visibility patch"
PATCH_TGT="${WEBROOT}/${INSTALL_NAME}/classes/database/Postgres.php"
if [ -f "${PATCH_TGT}" ]; then
    if grep -q "SSO_USERNAME" "${PATCH_TGT}"; then
        echo "[OK] SSO patch already applied"
    elif [ -f "${PATCH_SRC}" ]; then
        (cd "$(dirname "${PATCH_TGT}")" && patch --forward --ignore-whitespace -p1 < "${PATCH_SRC}") || {
            echo "[WARNING] Context patch failed, applying fallback replace"
            perl -0777 -pi -e "s#\\\$clause = \" AND pg_has_role\\('\\{\\\$username\\}'::name,pr.rolname,'USAGE'\\)\";#\\\$clause = (defined('SSO_USERNAME') && SSO_USERNAME) ? \" AND (pr.rolname='\".SSO_USERNAME.\"' OR pr.rolname LIKE '\".SSO_USERNAME.\"_%')\" : \" AND (pr.rolname='{\\\$username}' OR pr.rolname LIKE '{\\\$username}_%')\";#s" "${PATCH_TGT}"
        }
    else
        perl -0777 -pi -e "s#\\\$clause = \" AND pg_has_role\\('\\{\\\$username\\}'::name,pr.rolname,'USAGE'\\)\";#\\\$clause = (defined('SSO_USERNAME') && SSO_USERNAME) ? \" AND (pr.rolname='\".SSO_USERNAME.\"' OR pr.rolname LIKE '\".SSO_USERNAME.\"_%')\" : \" AND (pr.rolname='{\\\$username}' OR pr.rolname LIKE '{\\\$username}_%')\";#s" "${PATCH_TGT}"
    fi
    chown webapps:webapps "${PATCH_TGT}"
else
    echo "[WARNING] Postgres.php not found, skip SSO patch"
fi

# Drop leftover vendor tests / VCS from web tree
find "${TARGET}" -type d -name '.git' -prune -exec rm -rf {} + 2>/dev/null || true
find "${TARGET}" -name '*.md' -o -name 'phpunit*' | head >/dev/null

echo "${PHPPGADMIN_REPO} ${VER} $(date -u +%Y-%m-%dT%H:%M:%SZ)" > "${PLUGIN_DIR}/data/phppgadmin.version"
chmod 640 "${PLUGIN_DIR}/data/phppgadmin.version"
chown diradmin:diradmin "${PLUGIN_DIR}/data/phppgadmin.version" 2>/dev/null || true

echo "[OK] phpPgAdmin community fork ${VER} installed"
exit 0
