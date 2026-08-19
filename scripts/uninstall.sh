#!/bin/bash
######################################################################################
#
#   Postgresql integration for DirectAdmin $ 0.3.0
#
######################################################################################

PLUGIN_DIR="/usr/local/directadmin/plugins/postgresql";
CPIF="/usr/local/directadmin/data/admin/custom_package_items.conf";

if [ -x "${PLUGIN_DIR}/scripts/setup/install_cron.sh" ]; then
    "${PLUGIN_DIR}/scripts/setup/install_cron.sh" disable || true
fi
if [ -x "${PLUGIN_DIR}/scripts/setup/custom_backups.sh" ]; then
    "${PLUGIN_DIR}/scripts/setup/custom_backups.sh" disable || true
fi
if [ -x "${PLUGIN_DIR}/scripts/setup/custom_restore.sh" ]; then
    "${PLUGIN_DIR}/scripts/setup/custom_restore.sh" disable || true
fi

if [ -f "${CPIF}" ];
then
    c=$(grep -m1 -c "^postgresql=" "${CPIF}");
    if [ "${c}" -gt "0" ];
    then
        grep -v "^postgresql=" "${CPIF}" > "${CPIF}.new";
        cat "${CPIF}.new" > "${CPIF}";
        rm -f "${CPIF}.new";
    fi;
fi;

PL_CONF="${PLUGIN_DIR}/plugin.conf";
if [ -f "${PL_CONF}" ]; then
    perl -pi -e "s#^active=.*#active=no#" "${PL_CONF}";
    perl -pi -e "s#^installed=.*#installed=no#" "${PL_CONF}";
fi;

echo "Plugin has been removed!";
exit 0;
