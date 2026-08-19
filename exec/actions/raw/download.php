<?php
######################################################################################
#
#   Postgresql integration for DirectAdmin $ 0.2
#   ==============================================================================
#          Last modified: Mon Feb 10 12:44:48 +07 2020
#   ==============================================================================
#         Written by Alex Grebenschikov, Poralix, www.poralix.com
#         Copyright 2022 by Alex Grebenschikov, Poralix, www.poralix.com
#   ==============================================================================
#         Distributed under Apache License Version 2.0, January 2004
#                                          http://www.apache.org/licenses/
#
######################################################################################

$database = (isset($_GET['database']) && $_GET['database']) ? $_GET['database'] : false;
$databases = array();

if ($pg_user_databases = $pg->getDatabasesList($USER))
{
    foreach ($pg_user_databases as $row)
    {
        if (($USER === $row['owner']) || (strpos($row['owner'], $USER."_") === 0))
        {
            $databases[] = $row['name'];
        }
    }
    if (in_array($database, $databases))
    {
        $tmp_dir = sys_get_temp_dir();
        if ($tmp_dir === '' || $tmp_dir === false) {
            $tmp_dir = '/tmp';
        }
        $file = tempnam($tmp_dir, 'pgdump_');
        if ($file === false) {
            $is_error = true;
            $error_message = $da->get_lang('ERROR_MESSAGE_FAILED_DOWNLOAD');
            $error_details = $da->get_lang('ERROR_DETAILS_FAILED_DOWNLOAD');
        } else {
        putenv('PGPASSWORD=' . PG_PASSWORD);
        putenv('PGUSER=' . PG_USER);
        putenv('PGHOST=' . PG_HOST);
        putenv('PGPORT=' . PG_PORT);
        putenv('PGDATABASE=' . $database);
        $dump_bin = '/usr/bin/pg_dump';
        foreach (array('/usr/pgsql-16/bin/pg_dump','/usr/pgsql-15/bin/pg_dump','/usr/pgsql-14/bin/pg_dump','/usr/local/bin/pg_dump','/usr/bin/pg_dump') as $cand) {
            if (is_executable($cand)) { $dump_bin = $cand; break; }
        }
        $cmd = escapeshellcmd($dump_bin).' --dbname='.escapeshellarg($database).' --no-owner --no-privileges --clean --if-exists --inserts | gzip > '. escapeshellarg($file);
        @exec($cmd, $output, $rtval);
        if (is_file($file))
        {
            print "Content-Disposition: attachment; filename=".basename($database).".sql.gz\n";
            print "Expires: 0\n";
            print "Cache-Control: must-revalidate\n";
            print "Pragma: public\n";
            print "Content-Length: " . filesize($file)."\n";
            print "Content-type: application/x-gzip\n\n";
            readfile($file);
            unlink($file);
            putenv('PGPASSWORD');
            exit;
        }
        else
        {
            $is_error = true;
        }
        }
    }
    else
    {
        $is_error = true;
        $error_message = $da->get_lang('ERROR_MESSAGE_FAILED_DOWNLOAD');
        $error_details = $da->get_lang('ERROR_DETAILS_FAILED_OWNER');
    }
}
else
{
    $is_error = true;
    $error_message = $da->get_lang('ERROR_MESSAGE_FAILED_DOWNLOAD');
    $error_details = $da->get_lang('ERROR_DETAILS_FAILED_LIST_DATABASES');
}

if ($is_error)
{
    print "Cache-Control: no-cache, must-revalidate\n";
    print "Content-type: text/html\n\n";
    //var_dump($cmd, $result, $output, $tmp_dir, $file);
    if (!$error_message) $error_message = $da->get_lang('ERROR_MESSAGE_FAILED_DOWNLOAD');
    if (!$error_details) $error_details = $da->get_lang('ERROR_DETAILS_FAILED_DOWNLOAD');
}
