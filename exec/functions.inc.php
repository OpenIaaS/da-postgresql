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

define("PLUGIN_NAME",        "PostgreSQL Manager for Directadmin");
define("PLUGIN_VERSION",     "0.3.0");
define("PLUGIN_DIR",         "/usr/local/directadmin/plugins/postgresql");
define("PLUGIN_PGCONF_FILE", "/usr/local/directadmin/plugins/postgresql/pgpass.conf");
define("PLUGIN_IMAGES_DIR",  "/usr/local/directadmin/plugins/postgresql/images");
define("PLUGIN_DATA_DIR",    "/usr/local/directadmin/plugins/postgresql/data");
define("PLUGIN_JS_DIR",      "/usr/local/directadmin/plugins/postgresql/data/_js");
define("PLUGIN_CSS_DIR",     "/usr/local/directadmin/plugins/postgresql/data/_css");
define("PLUGIN_TPL_DIR",     "/usr/local/directadmin/plugins/postgresql/data/_tpl");
define("PLUGIN_SSO_DIR",     "/usr/local/directadmin/plugins/postgresql/data/sso");
define("PLUGIN_EXEC_DIR",    "/usr/local/directadmin/plugins/postgresql/exec");
define("PLUGIN_ACTION_DIR",  "/usr/local/directadmin/plugins/postgresql/exec/actions");
define("PLUGIN_TOOLS_DIR",   "/usr/local/directadmin/plugins/postgresql/exec/tools");
define("PLUGIN_LANG_DIR",    "/usr/local/directadmin/plugins/postgresql/lang");
define("PLUGIN_LOGS_DIR",    "/usr/local/directadmin/plugins/postgresql/logs");
define("PLUGIN_UPLOAD_DIR",  "/home/tmp/pgsql_restore");

define("PLUGIN_MOVE_BIN",    "/usr/local/directadmin/plugins/postgresql/exec/move_uploaded_file");
define("PLUGIN_RESTORE_BIN", "/usr/local/directadmin/plugins/postgresql/exec/dbrestore.sh");

define("PLUGIN_TPL_BODY",    "/usr/local/directadmin/plugins/postgresql/data/_tpl/body.html");
define("PLUGIN_TPL_MAIN",    "/usr/local/directadmin/plugins/postgresql/data/_tpl/main.html");
define("PLUGIN_TPL_ERROR",   "/usr/local/directadmin/plugins/postgresql/data/_tpl/error.html");
define("PLUGIN_CSS_FILE",    "/usr/local/directadmin/plugins/postgresql/data/_css/plugins.css");
define("PLUGIN_JS_FILE",     "/usr/local/directadmin/plugins/postgresql/data/_js/plugins.js");

define("TIME_DATE_FORMAT",   "H:i d-m-Y");

$_POST = array();
$_GET = array();

if (isset($_SERVER["SKIN_NAME"]) && strtolower((string)$_SERVER["SKIN_NAME"]) == "evolution") {
    define('EVOLUTION_SKIN', true);
} else {
    define('EVOLUTION_SKIN', false);
}

function plugin_da_language()
{
    $lang = isset($_SERVER["LANGUAGE"]) ? (string)$_SERVER["LANGUAGE"] : 'en';
    $lang = strtolower($lang);
    return $lang !== '' ? $lang : 'en';
}

function h($value)
{
    if ($value === false || $value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_valid_pg_ident($name)
{
    return is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 && strlen($name) <= 63;
}

function parse_input()
{
    global $_POST, $_GET;
    if (isset($_SERVER['REQUEST_METHOD']) && ($_SERVER['REQUEST_METHOD'] == 'POST')
                && isset($_SERVER['POST']) && $_SERVER['REQUEST_METHOD'])
    {
        parse_str((string)$_SERVER['POST'], $_POST);
    }
    if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'])
    {
        parse_str((string)$_SERVER['QUERY_STRING'], $_GET);
    }
    // magic_quotes_gpc was removed in PHP 8.0 — never call get_magic_quotes_gpc()/each()
    return true;
}


function gen_uuid()
{
    try {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    } catch (Exception $e) {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

function randomPassword($length=20)
{
    $length = max(12, (int)$length);
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    $alphaLength = strlen($alphabet) - 1;
    $pass = '';
    for ($i = 0; $i < $length; $i++)
    {
        $pass .= $alphabet[random_int(0, $alphaLength)];
    }
    return $pass;
}

function set_task_custom($task)
{
    global $USER, $is_error;
    if ($is_error) return false;
    if (defined('PLUGIN_TASKQ_FILE') && $task)
    {
        $new_content = "{$task}\n";
        $task_file = sprintf(PLUGIN_TASKQ_FILE, $USER);
        if (!is_file($task_file.'.lock') && !is_file($task_file))
        {
            return file_put_contents($task_file, $new_content, LOCK_EX);
        }
        else
        {
            return false;
        }
    }
    return false;
}


function array2options($input, $selected, $values_only = true)
{
    $HTML_code = '';
    if ($input && is_array($input))
    {
        if ($values_only == true)
        {
            foreach ($input as $value)
            {
                $HTML_selected = ($value == $selected) ? ' selected' : '';
                $HTML_code .= '<option'.$HTML_selected.'>'.h(trim((string)$value)).'</option>';
            }
        }
        else
        {
            foreach ($input as $key => $value)
            {
                $HTML_selected = ($key == $selected) ? ' selected' : '';
                $HTML_code .= '<option value="'.h($key).'"'.$HTML_selected.'>'.h(trim((string)$value)).'</option>';
            }
        }
    }
    return $HTML_code;
}


function array2checkboxes($input, $name)
{
    $HTML_code = '';
    $n = 0;
    if ($input)
    {
        foreach ($input as $value)
        {
            $n++;
            $bg = ($n%2) ? 'bg-light' : '';
            $safe_name = h($name);
            $safe_value = h($value);
            $HTML_code .= '<div class="text-dark '.$bg.'"><label class="my-3 form-check-label"><input class="form-check-input" type="checkbox" value="'.$safe_value.'" id="'.$safe_name.'_'.$n.'" name="'.$safe_name.'[]">&nbsp;'.$safe_value.'</label></div>';
        }
    }
    else
    {
        $HTML_code = '<div class="text-dark bg-light p-3">Nothing to restore yet</div>';
    }
    return $HTML_code;
}


function do_output($HTML, $display=true)
{
    if ($display) print $HTML."\n";
            else return $HTML."\n";
}


function generate_page($data)
{
    global $is_error;
    $output = '';

    if ( defined('IN_JSON_OUTPUT') && (IN_JSON_OUTPUT == true))
    {
        $is_ok = ($is_error) ? false : true;
        $is_error = ($is_error) ? true : false;
        $is_cached = false;
        $message_text = isset($data['MESSAGE_HTML']) ? nl2br(trim((string)$data['MESSAGE_HTML'])) : '';
        $error_text = isset($data['ERROR_HTML']) ? nl2br(trim((string)$data['ERROR_HTML'])) : '';
        $data = isset($data['OUTPUT_DATA']) ? $data['OUTPUT_DATA'] : [];
        $timestamp = date(TIME_DATE_FORMAT);
        $output = json_encode([
            'is_ok'      => $is_ok,
            'error'      => $is_error,
            'is_cached'  => $is_cached,
            'message'    => ($is_error) ? $error_text : $message_text,
            'data'       => $data,
            'timestamp'  => $timestamp
        ]);
    }
    else
    {
        $uri = isset($_SERVER["REQUEST_URI"]) ? (string)$_SERVER["REQUEST_URI"] : '';
        $data['PLUGIN_BASE_URL'] = (strpos($uri, "CMD_PLUGINS_ADMIN") === false) ? "/CMD_PLUGINS/postgresql" : "/CMD_PLUGINS_ADMIN/postgresql";
        $data['CSS_PLUGIN_CODE'] = _get_css(PLUGIN_CSS_FILE);
        $data['JS_PLUGIN_CODE'] = _get_js(PLUGIN_JS_FILE);
        $data['USERNAME'] = isset($_SERVER['USER']) ? $_SERVER['USER'] : '';
        $output = _get_tpl(PLUGIN_TPL_MAIN, $data);
    }
    return $output;
}


function error_message($title, $details)
{
    $HTML_code = _get_tpl(PLUGIN_TPL_ERROR, [
            'TITLE'   => $title,
            'DETAILS' => $details,
        ]);
    return $HTML_code;
}


function _get_css($file)
{
    $content = false;
    if (is_file($file))
    {
        $content = file_get_contents($file);
    }
    return $content;
}


function _get_js($file)
{
    $content = false;
    if (is_file($file))
    {
        $content = file_get_contents($file);
    }
    return $content;
}


function _get_tpl($file, $tokens=array())
{
    $HTML_code = "";
    if (is_file($file))
    {
        $HTML_code = file_get_contents($file);
        if ($tokens && is_array($tokens))
        {
            foreach ($tokens as $key => $val)
            {
                if (is_array($val) || is_object($val)) {
                    continue;
                }
                if ($val === false || $val === null) {
                    $val = '';
                }
                $HTML_code = str_replace('|'.strtoupper((string)$key).'|', (string)$val, $HTML_code);
            }
        }
    }
    else
    {
        $HTML_code = "Error: Template not found...";
    }
    return $HTML_code;
}

function _get_pg_credentials()
{
    $ok = false;
    if (defined('PLUGIN_PGCONF_FILE') && PLUGIN_PGCONF_FILE && is_file(PLUGIN_PGCONF_FILE))
    {
        $conf = _get_pg_user_credentials(PLUGIN_PGCONF_FILE);
        if (is_array($conf)) {
            if (!empty($conf['dbhost'])) { define('PG_HOST', $conf['dbhost']); } else { define('PG_HOST', 'localhost'); }
            if (!empty($conf['dbport'])) { define('PG_PORT', $conf['dbport']); } else { define('PG_PORT', '5432'); }
            if (!empty($conf['dbname'])) { define('PG_DB', $conf['dbname']); } else { define('PG_DB', false); }
            if (!empty($conf['dbuser'])) { define('PG_USER', $conf['dbuser']); } else { define('PG_USER', false); }
            if (!empty($conf['dbpass'])) { define('PG_PASSWORD', $conf['dbpass']); } else { define('PG_PASSWORD', false); }
            $ok = !empty($conf['dbuser']) && !empty($conf['dbpass']);
        }
    }
    if (!defined('PG_HOST')) define('PG_HOST', 'localhost');
    if (!defined('PG_PORT')) define('PG_PORT', '5432');
    if (!defined('PG_DB')) define('PG_DB', false);
    if (!defined('PG_USER')) define('PG_USER', false);
    if (!defined('PG_PASSWORD')) define('PG_PASSWORD', false);
    return $ok;
}

function _get_pg_user_credentials($file)
{
    $return = false;
    if (!is_file($file) || !is_readable($file)) {
        return false;
    }
    $contents = file_get_contents($file);
    if ($contents === false || $contents === '') {
        return false;
    }
    $content = explode("\n", $contents);
    $line = isset($content[0]) ? $content[0] : '';
    $parts = explode(":", $line);
    if (count($parts) < 5) {
        return false;
    }
    // password may contain colons — host:port:db:user:password
    $host = $parts[0];
    $port = $parts[1];
    $db = $parts[2];
    $user = $parts[3];
    $password = implode(':', array_slice($parts, 4));
    $return = [
        'dbhost' => $host,
        'dbport' => $port,
        'dbname' => $db,
        'dbuser' => $user,
        'dbpass' => $password
    ];
    return $return;
}

function _save_pg_user_credentials($file, $input)
{
    // localhost:5432:*:diradmin:sEcrEt
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $content = '';
    $content .= (isset($input['dbhost']) && $input['dbhost']) ? $input['dbhost'] : '*';
    $content .= (isset($input['dbport']) && $input['dbport']) ? ':'.$input['dbport'] : ':*';
    $content .= (isset($input['dbname']) && $input['dbname']) ? ':'.$input['dbname'] : ':*';
    if (isset($input['dbuser']) && $input['dbuser']) $content .= ':'.$input['dbuser']; else return false;
    if (isset($input['dbpass']) && $input['dbpass']) $content .= ':'.$input['dbpass']; else return false;
    if (@file_put_contents($file, $content, LOCK_EX))
    {
        @chmod($file, 0600);
        return true;
    }
    return false;
}

function format_table_list($input, $template, $transform=false)
{
    global $da;
    $HTML_result = '';
    if (!is_array($input)) {
        return $HTML_result;
    }
    foreach ($input as $row)
    {
        if (!is_array($row)) {
            continue;
        }
        $CONTENT = array();
        $id = 0;
        $prop = $row;
        foreach($row as $key => $val)
        {
            $original_val = h($val);
            if ($val !== false)
            {
                if ($transform && isset($transform[$key]))
                {
                    $val = str_replace("|VAL|", h($val), $transform[$key]);
                    foreach($prop as $p_key => $p_val)
                    {
                        $val = str_replace("|VAL_".strtoupper((string)$p_key)."|", h($p_val), $val);
                    }
                }
                else
                {
                    $val = h($val);
                }
            }
            else
            {
                $val = '';
            }
            if ($id === 0)
            {
                $CONTENT["TH1_INPUT"] = $val;
                $CONTENT["TH1_INPUT_ORIGINAL"] = $original_val;
                $CONTENT["TH1_INPUT_CLASS"] = "px_th_". strtolower((string)$key);
            }
            else
            {
                $CONTENT["TD".$id."_INPUT"] = $val;
                $CONTENT["TD".$id."_INPUT_ORIGINAL"] = $original_val;
                $CONTENT["TD".$id."_INPUT_CLASS"] = "px_td_". strtolower((string)$key);
            }
            $id++;
        }
        $HTML_result .= _get_tpl(PLUGIN_TPL_DIR . '/'.$template.'.html', $CONTENT);
    }
    return $HTML_result;
}
// END
