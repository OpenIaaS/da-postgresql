#!/bin/bash
######################################################################################
#
#   Postgresql integration for DirectAdmin $ 0.3.0
#
######################################################################################

PLUGIN_DIR="/usr/local/directadmin/plugins/postgresql"
# Preserve superuser credentials across updates
TMP_PGPASS=""
if [ -f "${PLUGIN_DIR}/pgpass.conf" ]; then
    TMP_PGPASS=$(mktemp /tmp/da-postgresql.pgpass.XXXXXX)
    cp -p "${PLUGIN_DIR}/pgpass.conf" "${TMP_PGPASS}"
fi

"${PLUGIN_DIR}/scripts/install.sh"

if [ -n "${TMP_PGPASS}" ] && [ -f "${TMP_PGPASS}" ]; then
    cp -p "${TMP_PGPASS}" "${PLUGIN_DIR}/pgpass.conf"
    chmod 600 "${PLUGIN_DIR}/pgpass.conf"
    chown diradmin:diradmin "${PLUGIN_DIR}/pgpass.conf" 2>/dev/null || true
    rm -f "${TMP_PGPASS}"
fi

echo "Plugin has been updated to 0.3.0!";
exit 0;
