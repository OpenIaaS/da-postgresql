#!/bin/bash
######################################################################################
#
#   Install / remove /etc/cron.d/da-postgresql
#   Usage: install_cron.sh enable|disable
#
######################################################################################

action="${1:-enable}"
CRON_FILE="/etc/cron.d/da-postgresql"
PLUGIN_DIR="/usr/local/directadmin/plugins/postgresql"

do_enable()
{
    cat > "${CRON_FILE}" <<EOF
# DirectAdmin PostgreSQL plugin — community phpPgAdmin update + log rotate
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
15 3 * * * root ${PLUGIN_DIR}/scripts/setup/cron_update.sh >/dev/null 2>&1
EOF
    chmod 644 "${CRON_FILE}"
    echo "[OK] Installed ${CRON_FILE} (daily 03:15 phpPgAdmin update)"
}

do_disable()
{
    if [ -f "${CRON_FILE}" ]; then
        rm -f "${CRON_FILE}"
        echo "[OK] Removed ${CRON_FILE}"
    else
        echo "[OK] Cron file not present"
    fi
}

case "${action}" in
    enable) do_enable ;;
    disable) do_disable ;;
    *) echo "Usage: $0 <enable|disable>"; exit 1 ;;
esac
exit 0
