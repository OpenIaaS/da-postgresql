#!/bin/bash
######################################################################################
#
#   Postgresql integration for DirectAdmin $ 0.3.0
#   PHP 8.2 / 8.3 / 8.4 — community phpPgAdmin, backups, cron, hardening
#
######################################################################################

set_permissions()
{
    if [ -n "${3}" ] && [ -e "${3}" ]; then
        chown ${1} "${3}";
        chmod ${2} "${3}";
    fi;
}

PLUGIN_DIR="/usr/local/directadmin/plugins/postgresql";
set_permissions diradmin:diradmin 755 "${PLUGIN_DIR}";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/admin";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/admin/index.html";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/user";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/create.html";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/create.raw";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/database.html";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/download.raw";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/index.html";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/restore.html";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/data";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/data/_css";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/data/_js";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/data/_tpl";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/data/sso";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/exec";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/exec/actions";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/exec/actions/html";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/exec/actions/shell";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/exec/actions/html/admin";

[ -d "${PLUGIN_DIR}/logs" ] || mkdir "${PLUGIN_DIR}/logs";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/logs";
[ -d "${PLUGIN_DIR}/data/sso" ] || mkdir -p "${PLUGIN_DIR}/data/sso";
set_permissions diradmin:diradmin 700 "${PLUGIN_DIR}/data/sso";

# Executable scripts
find "${PLUGIN_DIR}/scripts" -type f -name '*.sh' -exec chmod 700 {} \;
find "${PLUGIN_DIR}/exec" -type f -name '*.sh' -exec chmod 700 {} \;
chmod 700 "${PLUGIN_DIR}/exec/hooks/"*.sh 2>/dev/null || true

# CUSTOM PACKAGE ITEMS
CPIF="/usr/local/directadmin/data/admin/custom_package_items.conf";
CPIL="postgresql=type=text&string=PostgreSQL Databases&desc=Allow to create PostgreSQL databases&default=5";

if [ -f "${CPIF}" ];
then
    INSTALL_CPIF=0;
    c=$(grep -m1 -c "^postgresql=" "${CPIF}");
    if [ "${c}" -eq "0" ];
    then
        INSTALL_CPIF=1;
    fi;
else
    INSTALL_CPIF=1;
fi;

if [ "$INSTALL_CPIF" -eq "1" ];
then
    echo "${CPIL}" >> "${CPIF}";
    set_permissions diradmin:diradmin 640 "${CPIF}";
fi;

# WRAPPER
GCC="/usr/bin/gcc";
[ -x "${GCC}" ] || GCC="/usr/local/bin/gcc";
[ -x "${GCC}" ] || GCC="/bin/gcc";
if [ -x "${GCC}" ] && [ -f "${PLUGIN_DIR}/exec/move_uploaded_file.c" ]; then
    ${GCC} -std=gnu99 -B/usr/bin -o "${PLUGIN_DIR}/exec/move_uploaded_file" "${PLUGIN_DIR}/exec/move_uploaded_file.c" >> /dev/null 2>&1;
    set_permissions root:diradmin 4550 "${PLUGIN_DIR}/exec/move_uploaded_file";
fi;

# Enable DirectAdmin user backup / restore hooks so PostgreSQL dumps travel with account backups
if [ -x "${PLUGIN_DIR}/scripts/setup/custom_backups.sh" ]; then
    "${PLUGIN_DIR}/scripts/setup/custom_backups.sh" enable || true
fi
if [ -x "${PLUGIN_DIR}/scripts/setup/custom_restore.sh" ]; then
    "${PLUGIN_DIR}/scripts/setup/custom_restore.sh" enable || true
fi
if [ -x "${PLUGIN_DIR}/scripts/setup/custom_quotas.sh" ]; then
    "${PLUGIN_DIR}/scripts/setup/custom_quotas.sh" enable || true
fi

# Create plugin superuser (diradmin) when PostgreSQL is already installed
if command -v psql >/dev/null 2>&1 || [ -x /usr/bin/psql ] || [ -x /usr/local/bin/psql ]; then
    if [ -x "${PLUGIN_DIR}/scripts/setup/create_admin.sh" ]; then
        "${PLUGIN_DIR}/scripts/setup/create_admin.sh" --strict || true
    fi
    if [ -x "${PLUGIN_DIR}/scripts/setup/phppgadmin.sh" ]; then
        "${PLUGIN_DIR}/scripts/setup/phppgadmin.sh" || true
    fi
    if [ -x "${PLUGIN_DIR}/exec/restrict_access_dbs.sh" ]; then
        "${PLUGIN_DIR}/exec/restrict_access_dbs.sh" --run || true
    fi
else
    echo "[WARNING] psql not found — skipped create_admin / phpPgAdmin. Install PostgreSQL then re-run setup scripts.";
fi

# Daily cron: update community phpPgAdmin + rotate logs
if [ -x "${PLUGIN_DIR}/scripts/setup/install_cron.sh" ]; then
    "${PLUGIN_DIR}/scripts/setup/install_cron.sh" enable || true
fi

# ACTIVATE PLUGIN
PL_CONF="${PLUGIN_DIR}/plugin.conf";
perl -pi -e "s#^active=.*#active=yes#" ${PL_CONF};
perl -pi -e "s#^installed=.*#installed=yes#" ${PL_CONF};

echo "Plugin 0.3.0 has been installed and activated.<br>";
echo "phpPgAdmin uses the community fork (ReimuHakurei, PHP 8).<br>";
echo "User backup/restore hooks and a daily update cron are enabled.<br>";
echo "Re-save User Packages (including admin) to set PostgreSQL database limits.";
exit 0;
