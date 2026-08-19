#!/bin/bash
######################################################################################
#
#   Daily cron: refresh community phpPgAdmin and rotate plugin logs.
#   Installed as /etc/cron.d/da-postgresql
#
######################################################################################

PLUGIN_DIR="/usr/local/directadmin/plugins/postgresql"
LOG_DIR="${PLUGIN_DIR}/logs"
mkdir -p "${LOG_DIR}"
LOG_FILE="${LOG_DIR}/cron.update.$(date +%Y%m%d).log"

{
    echo "==== $(date -u +%Y-%m-%dT%H:%M:%SZ) phpPgAdmin update ===="
    if [ -x "${PLUGIN_DIR}/scripts/setup/phppgadmin.sh" ]; then
        # Keep existing config; installer overwrites from plugin template
        "${PLUGIN_DIR}/scripts/setup/phppgadmin.sh"
    else
        echo "[ERROR] phppgadmin.sh missing"
    fi
    if [ -x "${PLUGIN_DIR}/exec/remove_old_logs.sh" ]; then
        "${PLUGIN_DIR}/exec/remove_old_logs.sh" 14
    fi
    echo "==== done ===="
} >> "${LOG_FILE}" 2>&1

exit 0
