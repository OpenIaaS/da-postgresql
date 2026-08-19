#!/bin/bash
######################################################################################
#
#   Postgresql integration for DirectAdmin $ 0.3.0
#
######################################################################################

de()
{
    if [ "1" == "${DEBUG}" ];
    then
        if [ -n "${1}" ]; then
            echo "[DEBUG] ${1}";
            return;
        else
            while read data; do echo "[DEBUG] ${data}"; done;
        fi;
    fi;
}

die()
{
    echo "[ERROR] ${1}";
    [ -n "${LOG_FILE}" ] && log "[ERROR] ${1}";
    exit "${2}";
}

# DirectAdmin usernames / PG identifiers: letters, digits, underscore
pg_ident_ok()
{
    echo "${1}" | grep -Eq '^[A-Za-z_][A-Za-z0-9_]*$'
}

sql_lit()
{
    # single-quote a literal for SQL (identifiers should use pg_ident_ok instead)
    local v="${1}";
    v=${v//\'/\'\'};
    printf "'%s'" "${v}";
}
