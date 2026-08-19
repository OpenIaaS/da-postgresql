<?php
######################################################################################
#
#   Postgresql integration for DirectAdmin $ 0.3.0
#   ==============================================================================
#          PHP 8.2 / 8.3 / 8.4 compatible fork
#          Based on Poralix da-postgresql 0.2.1
#   ==============================================================================
#         Written by Alex Grebenschikov, Poralix, www.poralix.com
#         Maintained by OpenIaaS (https://github.com/OpenIaaS/da-postgresql)
#         Copyright 2022 by Alex Grebenschikov, Poralix, www.poralix.com
#   ==============================================================================
#         Distributed under Apache License Version 2.0, January 2004
#                                          http://www.apache.org/licenses/
#
######################################################################################

$created=false;
$sso_config_file = PLUGIN_SSO_DIR . '/user.'. $USER .'.pgpass.conf';

if (isset($_POST) && $_POST)
{
    $dbuser_suffix = (isset($_POST['dbuser']) && $_POST['dbuser']) ? preg_replace('/[^A-Za-z0-9_]/', '', (string)$_POST['dbuser']) : '';
    $dbname_suffix = (isset($_POST['dbname']) && $_POST['dbname']) ? preg_replace('/[^A-Za-z0-9_]/', '', (string)$_POST['dbname']) : '';
    $dbuser = $dbuser_suffix !== '' ? $USER ."_". $dbuser_suffix : false;
    $dbname = $dbname_suffix !== '' ? $USER ."_". $dbname_suffix : false;
    $dbpass = (isset($_POST['dbpass']) && $_POST['dbpass']) ? trim((string)$_POST['dbpass']) : false;
    $dbowner = false;

    if (!$dbuser || !$dbname || !$dbpass || !is_valid_pg_ident($dbuser) || !is_valid_pg_ident($dbname))
    {
        $is_error = true;
        $error_message = $da->get_lang('ERROR_MESSAGE_FAILED_CREATE_DB');
        $error_details = $da->get_lang('ERROR_DETAILS_FAILED_CREATE_DB');
        return;
    }

    if (intval($PGSQL_USER_USAGE) >= intval($PGSQL_USER_LIMIT))
    {
        $is_error = true;
        $error_message = $da->get_lang('ERROR_MESSAGE_PGSQL_LIMIT_HIT');
        $error_details = $da->get_lang('ERROR_DETAILS_PGSQL_LIMIT_HIT');
        $action_file = sprintf("%s/%s/%s.php", PLUGIN_ACTION_DIR, FILE_TYPE, 'error');
        return;
    }
    else
    {
        if ($pg->testServer())
        {
            $pg_dbusers = $pg->getUsersList($USER);
            if (!is_array($pg_dbusers)) {
                $pg_dbusers = array();
            }

            if (!in_array($USER, $pg_dbusers))
            {
                $session_password = false;
                $da_sess_data = array();
                $sess_id = isset($_SERVER['SESSION_ID']) ? preg_replace('/[^A-Za-z0-9._-]/', '', (string)$_SERVER['SESSION_ID']) : '';
                $da_sess_file = '/usr/local/directadmin/data/sessions/da_sess_'. $sess_id;
                if ($sess_id && is_file($da_sess_file) && ($da_sess_data = parse_ini_file($da_sess_file)))
                {
                    $session_username = (isset($da_sess_data['username']) && $da_sess_data['username']) ? $da_sess_data['username'] : false;
                    $session_password = (isset($da_sess_data['passwd']) && $da_sess_data['passwd']) ? base64_decode($da_sess_data['passwd']) : false;
                    if ($session_password && $session_username && ($session_username == $USER))
                    {
                        $pg->createUser($USER, $session_password);
                    }
                    else
                    {
                        $session_password = randomPassword();
                        $pg->createUser($USER, $session_password);
                    }
                }
                else
                {
                    $session_password = randomPassword();
                    $pg->createUser($USER, $session_password);
                }
                _save_pg_user_credentials($sso_config_file, [
                    'dbhost' => PG_HOST,
                    'dbport' => PG_PORT,
                    'dbname' => '*',
                    'dbuser' => $USER,
                    'dbpass' => $session_password
                ]);

            }

            if ($dbname == $dbuser)
            {
                $dbowner = $dbuser;
                $dbowner_passwd = $dbpass;
                if (!in_array($dbuser, $pg_dbusers)) $pg->createUser($dbuser, $dbpass);
                $created = $pg->createDatabase($dbname, $dbowner);
                $pg->grantRole2Role($dbowner, $USER);
                $pg->createGrants($dbname, $dbuser);
            }
            else
            {
                $dbowner = $dbname;
                $dbowner_passwd = false;
                if (!in_array($dbowner, $pg_dbusers)) $pg->createUser($dbowner, $dbowner_passwd);
                if (!in_array($dbuser, $pg_dbusers)) $pg->createUser($dbuser, $dbpass);
                $created = $pg->createDatabase($dbname, $dbowner);
                $pg->grantRole2Role($dbowner, $USER);
                $pg->grantRole2Role($dbowner, $dbuser);
                $pg->grantRole2Database($dbuser, $dbname);
                $pg->createGrants($dbname, $dbuser);
            }
        }
    }
}

if ($created !== false)
{
    $is_error = false;
    $error_message = false;
    $error_details = false;
    $message_ok = sprintf($da->get_lang('OK_MESSAGE_CREATED_DB'), h($dbuser), h($dbpass), h($dbname), h(PG_HOST), (int)PG_PORT);
}
else
{
    $is_error = true;
    $error_message = $da->get_lang('ERROR_MESSAGE_FAILED_CREATE_DB');
    $error_details = $da->get_lang('ERROR_DETAILS_FAILED_CREATE_DB') . "<br>Details: ". h($pg->getLastError());
}
