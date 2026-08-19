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

if (!defined('IN_DA_PLUGIN') || (IN_DA_PLUGIN !==true)){die("You're not allowed to view this page!");}

class postgresql
{
    private $_ERROR=false;
    private $_ERROR_TEXT=array();

    private $_PG_HOST;
    private $_PG_PORT;
    private $_PG_DB;
    private $_PG_USER;
    private $_PG_PASSWORD;

    private $_PG_CONN=false;
    private $_PG_LAST_ERROR;
    private $_PG_QUERIES=array();

    private $_CONNECT_DB;
    private $_PG_PERSISTENT = false;
    private $query = false;

    function __construct($input)
    {
        $this->_PG_QUERIES = array();
        $this->_ERROR_TEXT = array();
        if ($this->_PG_CONN) $this->_disconnect();

        $user = (isset($input['user']) && $input['user']) ? $input['user'] : false;
        $password = (isset($input['password']) && $input['password']) ? $input['password'] : false;
        $dbname = (isset($input['dbname']) && $input['dbname']) ? $input['dbname'] : false;
        $host = (isset($input['host']) && $input['host']) ? $input['host'] : 'localhost';
        $port = (isset($input['port']) && intval($input['port'])) ? intval($input['port']) : 5432;

        $this->setDBuser($user);
        $this->setDBpassword($password);
        $this->setDBhost($host);
        $this->setDBport($port);
        $this->setDBname($dbname);
    }

    private function conninfo_quote($value)
    {
        return "'" . str_replace(array("\\", "'"), array("\\\\", "\\'"), (string)$value) . "'";
    }

    private function ident($name)
    {
        $name = (string)$name;
        if ($this->_PG_CONN) {
            return pg_escape_identifier($this->_PG_CONN, $name);
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function lit($value)
    {
        if ($this->_PG_CONN) {
            return pg_escape_literal($this->_PG_CONN, (string)$value);
        }
        return "'" . str_replace(array("\\", "'"), array("\\\\", "''"), (string)$value) . "'";
    }

    private function connected_dbname()
    {
        if (!$this->_PG_CONN) {
            return false;
        }
        return @pg_dbname($this->_PG_CONN);
    }

    function testServer()
    {
        $conn = $this->_connect();
        $ok = (bool)$conn;
        $this->_disconnect();
        return $ok;
    }

    function getConnectedDBname()
    {
        $conn = $this->_connect();
        if (!$conn) {
            return false;
        }
        $dbname = pg_dbname($conn);
        $this->_disconnect();
        return $dbname;
    }

    function doDeleteDB($dbname)
    {
        if (!is_valid_pg_ident($dbname)) {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'Invalid database name';
            return false;
        }
        $conn = $this->_connect();
        if ($conn)
        {
            $id = $this->ident($dbname);
            $this->setQuery("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ".$this->lit($dbname)." AND pid <> pg_backend_pid();");
            $this->runQuery();
            $this->setQuery("DROP DATABASE IF EXISTS {$id};");
            if ($result = $this->runQuery())
            {
                $this->setQuery("DROP USER IF EXISTS {$id};");
                $this->runQuery();
                return $result;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Failed to delete database: '. (string)$this->connected_dbname();
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Failed to connect to PostgreSQL server';
        return false;
    }

    function doReindexDB($dbname)
    {
        if (!is_valid_pg_ident($dbname)) {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'Invalid database name';
            return false;
        }
        $conn = $this->_connect();
        if ($conn)
        {
            $this->setQuery('REINDEX DATABASE '.$this->ident($dbname).';');
            if ($result = $this->runQuery())
            {
                return $result;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Failed to reindex database: '. (string)$this->connected_dbname();
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Failed to connect to PostgreSQL server';
        return false;
    }

    function doVacuumDB()
    {
        $conn = $this->_connect();
        if ($conn)
        {
            $this->setQuery('VACUUM;');
            if ($result = $this->runQuery())
            {
                return $result;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Failed to vaccum database: '. (string)$this->connected_dbname();
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Failed to connect to PostgreSQL server';
        return false;
    }

    function getDatabasesCount($owner=false)
    {
        $conn = $this->_connect();
        if ($conn)
        {
            if ($owner)
            {
                if (!is_valid_pg_ident($owner)) {
                    return 0;
                }
                $this->setQuery('SELECT COUNT(datname) AS "Count" FROM pg_catalog.pg_database WHERE datname = '.$this->lit($owner).' OR datname LIKE '.$this->lit($owner.'_%').';');
            }
            else
            {
                $this->setQuery('SELECT COUNT(datname) AS "Count" FROM pg_catalog.pg_database;');
            }
            if ($result = $this->runQuery())
            {
                if ($row = pg_fetch_array($result, null, PGSQL_ASSOC))
                {
                    return $row['Count'];
                }
                else
                {
                    $this->_disconnect();
                    $this->_ERROR = true;
                    $this->_ERROR_TEXT[] = 'Failed to count databases';
                    return false;
                }
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Failed to get list of databases';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Failed to connect to PostgreSQL server';
        return false;
    }

    function getDatabasesSize($owner=false)
    {
        $conn = $this->_connect();
        if ($conn)
        {
            if ($owner)
            {
                if (!is_valid_pg_ident($owner)) {
                    return '0 bytes';
                }
                $this->setQuery('SELECT pg_size_pretty(COALESCE(SUM(pg_database_size(datname)),0)) AS "Size" FROM pg_catalog.pg_database WHERE datname = '.$this->lit($owner).' OR datname LIKE '.$this->lit($owner.'_%').';');
            }
            else
            {
                $this->setQuery('SELECT pg_size_pretty(COALESCE(SUM(pg_database_size(datname)),0)) AS "Size" FROM pg_catalog.pg_database;');
            }
            if ($result = $this->runQuery())
            {
                if ($row = pg_fetch_array($result, null, PGSQL_ASSOC))
                {
                    return $row['Size'];
                }
                else
                {
                    $this->_disconnect();
                    $this->_ERROR = true;
                    $this->_ERROR_TEXT[] = 'Failed to count size of databases';
                    return false;
                }
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Failed to get list of databases';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Failed to connect to PostgreSQL server';
        return false;
    }

    function getUsersList($user=false)
    {
        $conn = $this->_connect();
        if ($conn)
        {
            if ($user)
            {
                if (!is_valid_pg_ident($user)) {
                    return array();
                }
                $this->setQuery('SELECT u.usename AS "User" FROM pg_catalog.pg_user u WHERE u.usename NOT LIKE '.$this->lit($user.'_sso_%').'  AND (u.usename = '.$this->lit($user).' OR u.usename LIKE '.$this->lit($user.'_%').');');
            }
            else
            {
                $this->setQuery('SELECT u.usename AS "User" FROM pg_catalog.pg_user u;');
            }
            if ($result = $this->runQuery())
            {
                $data = array();
                while ($row = pg_fetch_array($result, null, PGSQL_ASSOC))
                {
                    $data[] = $row['User'];
                }
                return $data;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Failed to get list of users';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Failed to connect to PostgreSQL server';
        return false;
    }


    function getPrivilegesList($dbase)
    {
        if (!is_valid_pg_ident($dbase)) {
            return array();
        }
        $conn = $this->_connect();
        if ($conn)
        {
            $this->setQuery("SELECT datacl AS acl FROM pg_catalog.pg_database WHERE datname=".$this->lit($dbase).";");
            if ($result = $this->runQuery())
            {
                $data = array();
                if ($row = pg_fetch_row($result, 0))
                {
                    $acl = isset($row[0]) ? (string)$row[0] : '';
                    if ($acl !== '') {
                        $tmp = explode(",", substr(substr($acl, 1), 0, -1));
                        sort($tmp);
                        $id=0;
                        foreach ($tmp as $_row)
                        {
                            $parts = explode("=", (string)$_row, 2);
                            $user = isset($parts[0]) ? $parts[0] : '';
                            if ($user)
                            {
                                $data[] = [
                                    'id'             => $id,
                                    'user'           => $user,
                                    'password'       => true,
                                    'privileges'     => '',
                                ];
                                $id++;
                            }
                        }
                    }
                }
                return $data;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Failed to get list of privileges';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Failed to connect to PostgreSQL server';
        return false;
    }

    function getDatabasesList($owner=false)
    {
        $conn = $this->_connect();
        if ($conn)
        {
            if ($owner)
            {
                if (!is_valid_pg_ident($owner)) {
                    return array();
                }
                $this->setQuery('SELECT d.datname as "Name", pg_catalog.pg_get_userbyid(d.datdba) as "Owner", pg_size_pretty(pg_database_size(d.datname)) as "Size" FROM pg_catalog.pg_database d WHERE d.datname = '.$this->lit($owner).' OR d.datname LIKE '.$this->lit($owner.'_%').' ORDER BY d.datname ASC;');
            }
            else
            {
                $this->setQuery('SELECT d.datname as "Name", pg_catalog.pg_get_userbyid(d.datdba) as "Owner", pg_size_pretty(pg_database_size(d.datname)) as "Size" FROM pg_catalog.pg_database d ORDER BY d.datname ASC;');
            }
            if ($result = $this->runQuery())
            {
                $data = array();
                $id = 1;
                while ($row = pg_fetch_array($result, null, PGSQL_ASSOC))
                {
                    $data[] = [
                        'id'    => $id,
                        'name'  => $row['Name'],
                        'owner' => $row['Owner'],
                        'size'  => $row['Size'],
                        ];
                    $id++;
                }
                return $data;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Failed to get list of databases';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Failed to connect to PostgreSQL server';
        return false;
    }

    function getGrantedDatabasesList($dbuser)
    {
        if (!is_valid_pg_ident($dbuser)) {
            return array();
        }
        $conn = $this->_connect();
        if ($conn)
        {
            if (strpos($dbuser, "_") !== false)
            {
                $parts = explode("_", $dbuser, 2);
                $sysuser = $parts[0];
            }
            else
            {
                $sysuser = $dbuser;
            }
            $this->setQuery('SELECT datname as "Name" FROM pg_database WHERE has_database_privilege('.$this->lit($dbuser).', datname, \'CONNECT\') and datistemplate = false and datname like '.$this->lit($sysuser.'_%'));
            if ($result = $this->runQuery())
            {
                $data = array();
                while ($row = pg_fetch_array($result, null, PGSQL_ASSOC))
                {
                    $data[] = $row['Name'];
                }
                return $data;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Failed to get list of databases';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Failed to connect to PostgreSQL server';
        return false;
    }

    function changeUserPassword($dbuser, $dbpassword)
    {
        if (!is_valid_pg_ident($dbuser)) {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'Invalid role name';
            return false;
        }
        $conn = $this->_connect();
        if ($conn)
        {
            $this->setQuery("ALTER USER ".$this->ident($dbuser)." WITH LOGIN PASSWORD ".$this->lit($dbpassword).";");
            if ($this->runQuery())
            {
                $this->_disconnect();
                return true;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Query error: Failed to change a password for role';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Connection error: Failed to change a password for role';
        return false;
    }

    function grantRole2Role($role, $dbuser)
    {
        if (!is_valid_pg_ident($role) || !is_valid_pg_ident($dbuser)) {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'Invalid role name';
            return false;
        }
        $conn = $this->_connect();
        if ($conn)
        {
            $this->setQuery("GRANT ".$this->ident($role)." TO ".$this->ident($dbuser).";");
            if ($this->runQuery())
            {
                $this->_disconnect();
                return true;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Query error: Failed to grant role';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Connection error: Failed to grant role';
        return false;
    }

    function revokeRoleFromRole($role, $dbuser)
    {
        if (!is_valid_pg_ident($role) || !is_valid_pg_ident($dbuser)) {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'Invalid role name';
            return false;
        }
        $conn = $this->_connect();
        if ($conn)
        {
            $this->setQuery("REVOKE ".$this->ident($role)." FROM ".$this->ident($dbuser).";");
            if ($this->runQuery())
            {
                $this->_disconnect();
                return true;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Query error: Failed to revoke role';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Connection error: Failed to revoke role';
        return false;
    }

    function grantRole2Database($dbuser, $dbname)
    {
        if (!is_valid_pg_ident($dbuser) || !is_valid_pg_ident($dbname)) {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'Invalid name';
            return false;
        }
        $conn = $this->_connect();
        if ($conn)
        {
            $this->setQuery("GRANT ALL ON DATABASE ".$this->ident($dbname)." TO ".$this->ident($dbuser).";");
            if ($this->runQuery())
            {
                $this->_disconnect();
                return true;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Query error: Failed to grant role';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Connection error: Failed to grant role';
        return false;
    }

    function revokeRoleFromDatabase($dbuser, $dbname)
    {
        if (!is_valid_pg_ident($dbuser) || !is_valid_pg_ident($dbname)) {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'Invalid name';
            return false;
        }
        $this->setPersistent(true);
        $prev = $this->_PG_DB;
        $this->setDBname($dbname);
        $conn = $this->_connect();
        if ($conn)
        {
            $this->setQuery("REVOKE ALL ON DATABASE ".$this->ident($dbname)." FROM ".$this->ident($dbuser).";");
            $this->runQuery();
            $this->setQuery("REVOKE SELECT ON ALL TABLES IN SCHEMA public FROM ".$this->ident($dbuser).";");
            $this->runQuery();
            $this->setQuery("REVOKE SELECT ON ALL TABLES IN SCHEMA pg_catalog FROM ".$this->ident($dbuser).";");
            $this->runQuery();
            $this->setQuery("REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM ".$this->ident($dbuser).";");
            $this->runQuery();
            $this->_disconnect();
            $this->setDBname($prev);
            return true;
        }
        $this->setDBname($prev);
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Connection error: Failed to revoke privileges role';
        return false;
    }

    function removeUser($dbuser)
    {
        if (!is_valid_pg_ident($dbuser)) {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'Invalid role name';
            return false;
        }
        $conn = $this->_connect();
        if ($conn)
        {
            $this->setQuery("DROP USER ".$this->ident($dbuser).";");
            if ($this->runQuery())
            {
                $this->_disconnect();
                return true;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Query error: Failed to drop role';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Connection error: Failed to drop role';
        return false;
    }

    function createUser($dbuser, $dbpassword=false)
    {
        if (!is_valid_pg_ident($dbuser)) {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'Invalid role name';
            return false;
        }
        $conn = $this->_connect();
        if ($conn)
        {
            if ($dbpassword !== false)
            {
                $this->setQuery("CREATE USER ".$this->ident($dbuser)." WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE INHERIT ENCRYPTED PASSWORD ".$this->lit($dbpassword).";");
            }
            else
            {
                $this->setQuery("CREATE ROLE ".$this->ident($dbuser)." WITH NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;");
            }

            if ($this->runQuery())
            {
                $this->_disconnect();
                return true;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Query error: Failed to create role';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Connection error: Failed to create role';
        return false;
    }

    function createGrants($dbname, $dbuser)
    {
        if (!is_valid_pg_ident($dbname) || !is_valid_pg_ident($dbuser)) {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'Invalid name';
            return false;
        }
        $prev = $this->_PG_DB;
        $this->setPersistent(true);
        $this->setDBname($dbname);
        $conn = $this->_connect();
        if ($conn)
        {
            $u = $this->ident($dbuser);
            $d = $this->ident($dbname);
            $this->setQuery("GRANT CONNECT, TEMPORARY ON DATABASE {$d} TO {$u};");
            if (!$this->runQuery())
            {
                $this->_disconnect();
                $this->setDBname($prev);
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Failed to grant CONNECT on database';
                return false;
            }
            $this->setQuery("GRANT ALL PRIVILEGES ON DATABASE {$d} TO {$u};");
            if (!$this->runQuery())
            {
                $this->_disconnect();
                $this->setDBname($prev);
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Failed to grant permissions on database';
                return false;
            }
            $this->setQuery("GRANT ALL ON SCHEMA public TO {$u};");
            $this->runQuery();
            $this->setQuery("GRANT ALL ON ALL TABLES IN SCHEMA public TO {$u};");
            $this->runQuery();
            $this->setQuery("GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO {$u};");
            $this->runQuery();
            $this->setQuery("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO {$u};");
            $this->runQuery();
            $this->_disconnect();
            $this->setDBname($prev);
            return true;
        }
        $this->setDBname($prev);
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Connection error: Failed to create User DB';
        return false;
    }

    function createDatabase($dbname, $owner)
    {
        if (!is_valid_pg_ident($dbname) || !is_valid_pg_ident($owner)) {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'Invalid name';
            return false;
        }
        $conn = $this->_connect();
        if ($conn)
        {
            $d = $this->ident($dbname);
            $o = $this->ident($owner);
            $this->setQuery("CREATE DATABASE {$d} OWNER {$o};");
            if ($this->runQuery())
            {
                $this->setQuery("REVOKE ALL ON DATABASE {$d} FROM PUBLIC;");
                $this->runQuery();
                $this->setQuery("GRANT CONNECT, TEMPORARY ON DATABASE {$d} TO {$o};");
                $this->runQuery();
                $this->setQuery("GRANT ALL ON DATABASE {$d} TO {$o};");
                $this->runQuery();
                $this->_disconnect();
                return true;
            }
            else
            {
                $this->_disconnect();
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Query error: Failed to create DB';
                return false;
            }
        }
        $this->_ERROR = true;
        $this->_ERROR_TEXT[] = 'Connection error: Failed to create User DB';
        return false;
    }

    function createUserDB($dbuser, $dbname, $dbpassword)
    {
        $this->setPersistent(true);
        $createdUser = $this->createUser($dbuser, $dbpassword);
        $createdDB = $this->createDatabase($dbname, $dbuser);
        $createdGrants = $this->createGrants($dbname, $dbuser);
        $this->_disconnect(true);
        return ($createdUser && $createdDB && $createdGrants) ? true : false;
    }

    function grantUserOnDB($dbuser, $dbname)
    {
        $this->setPersistent(true);
        $createdGrants = $this->createGrants($dbname, $dbuser);
        $this->_disconnect(true);
        return ($createdGrants) ? true : false;
    }

    function createNewUser($dbuser, $dbname, $dbpassword)
    {
        $this->setPersistent(true);
        $createdUser = $this->createUser($dbuser, $dbpassword);
        $createdGrants = $this->grantUserOnDB($dbuser, $dbname);
        $this->_disconnect(true);
        return ($createdUser && $createdGrants) ? true : false;
    }


    function getLastError()
    {
        return $this->_PG_LAST_ERROR;
    }

    function getErrors()
    {
        return ['is_error' => $this->_ERROR, 'details' => $this->_ERROR_TEXT];
    }

    function getQueries()
    {
        return $this->_PG_QUERIES;
    }

    private function runQuery()
    {
        if (!$this->_PG_CONN) {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'No PostgreSQL connection';
            return false;
        }
        if ($query = $this->getQuery())
        {
            $result = @pg_query($this->_PG_CONN, $query);
            if ($result)
            {
                $this->setQuery(false);
                return $result;
            }
            else
            {
                $this->setQuery(false);
                $this->_PG_LAST_ERROR = pg_last_error($this->_PG_CONN);
                $this->_ERROR = true;
                $this->_ERROR_TEXT[] = 'Failed to run query, error: '.$this->_PG_LAST_ERROR;
                return false;
            }
        }
        else
        {
            $this->_ERROR = true;
            $this->_ERROR_TEXT[] = 'Can not run empty query';
            return false;
        }
    }

    private function setQuery($str)
    {
        if ($str) {
            $dbname = $this->_PG_CONN ? (string)@pg_dbname($this->_PG_CONN) : '';
            $this->_PG_QUERIES[] = sprintf("[%s][%s]: %s", $dbname, (string)$this->_CONNECT_DB, $str);
        }
        $this->query = $str;
    }

    private function setDBhost($str)
    {
        $this->_PG_HOST = $str;
    }

    private function setDBport($str)
    {
        $this->_PG_PORT = $str;
    }

    private function setDBname($str)
    {
        $this->_PG_DB = $str;
    }

    private function setDBuser($str)
    {
        $this->_PG_USER = $str;
    }

    private function setDBpassword($str)
    {
        $this->_PG_PASSWORD = $str;
    }

    private function setPersistent($bool)
    {
        $this->_PG_PERSISTENT = ($bool) ? true : false;
    }


    private function getQuery()
    {
        return $this->query;
    }

    private function getDBhost()
    {
        return $this->_PG_HOST;
    }

    private function getDBport()
    {
        return $this->_PG_PORT;
    }

    private function getDBname()
    {
        return $this->_PG_DB;
    }

    private function getDBuser()
    {
        return $this->_PG_USER;
    }

    private function getDBpassword()
    {
        return $this->_PG_PASSWORD;
    }

    private function _connect()
    {
        $conn = false;
        $user = $this->getDBuser();
        $password = $this->getDBpassword();
        $host = $this->getDBhost();
        $port = $this->getDBport();
        $dbname = $this->getDBname();

        if ($this->_PG_CONN && $this->_PG_PERSISTENT) {
            if ($dbname && $dbname !== '*' && $this->connected_dbname() !== $dbname) {
                $this->_disconnect(true);
            } else {
                return $this->_PG_CONN;
            }
        }

        if ($user && $password && $host)
        {
            $parts = array(
                'user='.$this->conninfo_quote($user),
                'password='.$this->conninfo_quote($password),
                'host='.$this->conninfo_quote($host),
                'port='.$this->conninfo_quote((string)intval($port)),
                'connect_timeout=8',
            );
            if ($dbname && ($dbname !== '*')) {
                $parts[] = 'dbname='.$this->conninfo_quote($dbname);
            }
            $this->_CONNECT_DB = implode(' ', $parts);
            $conn = @pg_connect($this->_CONNECT_DB);
        }
        $this->_PG_CONN=$conn;
        return $this->_PG_CONN;
    }

    private function _disconnect($force=false)
    {
        if ($force === true)
        {
            if ($this->_PG_CONN) {
                @pg_close($this->_PG_CONN);
                $this->_PG_CONN = false;
            }
        }
        else
        {
            if ($this->_PG_CONN && ($this->_PG_PERSISTENT == false)) {
                @pg_close($this->_PG_CONN);
                $this->_PG_CONN = false;
            }
        }
    }
}
// END
