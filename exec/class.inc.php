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

class da
{
    private $DA_BIN="/usr/local/directadmin/directadmin";
    private $DACONF_BIN=PLUGIN_EXEC_DIR."/daconf";

    private $_CONF=array();
    private $_CONF_CUSTOM=array();
    private $_DEFAULT_CONF=array();
    private $_LANG=array();
    private $_GET_VARS=array();
    private $_POST_VARS=array();
    private $_ERROR=false;
    private $_ERROR_TEXT="";
    private $_USERNAME="";
    private $_USER_CONF_FILE="";
    private $_USER_CONF=array();
    private $_USER_DOMAINS_FILE="";
    private $_USER_DOMAINS=array();
    private $_EXEC_LEVEL;
    private $_DA_CONF=array();

    function __construct()
    {
        $this->_init_user();
        $this->_USER_CONF=$this->_load_conf_data($this->_USER_CONF_FILE);
        $this->_LANG=$this->_load_language();
    }

    public function get_username()
    {
        return $this->_USERNAME;
    }

    public function get_user_domains()
    {
        $domains = array();
        if ($content = $this->get_file($this->_USER_DOMAINS_FILE))
        {
            if ($rows = explode("\n", $content))
            {
                sort($rows);
                foreach ($rows as $row)
                {
                    $row = trim($row);
                    if (!$row) continue;
                    if (!in_array($row, $domains)) $domains[] = $row;
                }
            }
        }
        return $domains;
    }

    public function get_var_post($search, $default=false)
    {
        $var=(isset($this->_POST_VARS[$search])) ? $this->_POST_VARS[$search] : false;
        return ($var) ? $var : (($default) ? $default : false);
    }

    public function get_lang($search)
    {
        return (isset($this->_LANG[$search])) ? $this->_LANG[$search] : $search;
    }

    public function get_file($filename)
    {
        return is_file($filename) ? file_get_contents($filename) : false;
    }

    public function get_conf($search)
    {
        if (isset($this->_CONF_CUSTOM[$search])) {
            return $this->_CONF_CUSTOM[$search];
        }
        return isset($this->_CONF[$search]) ? $this->_CONF[$search] : null;
    }

    public function get_confs()
    {
        return $this->_CONF;
    }

    public function get_custom_confs()
    {
        return $this->_CONF_CUSTOM;
    }

    public function get_user_data($search)
    {
        return (isset($this->_USER_CONF[$search])) ? $this->_USER_CONF[$search] : NULL;
    }

    public function get_da_conf($search)
    {
        if (!isset($this->_DA_CONF) || !is_array($this->_DA_CONF) || !$this->_DA_CONF)
        {
            $loaded=$this->_load_da_conf();
            $this->_DA_CONF = is_array($loaded) ? $loaded : array();
        }
        return (isset($this->_DA_CONF[$search])) ? $this->_DA_CONF[$search] : false;
    }

    public function da_send_message($subject,$message)
    {
        $action="notify";
        $user=$this->get_username();
        $subject=urlencode(htmlspecialchars((string)$subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $message=urlencode(htmlspecialchars((string)$message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $content = "action={$action}&value=users&users=select1%3D{$user}&subject={$subject}&message={$message}\n";
        $file = '/usr/local/directadmin/data/task.queue.cb';
        return file_put_contents($file, $content);
    }

    public function filter_content($content, $filters=array())
    {
        if ($filters)
        {
            foreach ($filters as $key => $val)
            {
                $content = str_replace($key, $val, $content);
            }
        }
        return $content;
    }

    private function _load_conf_data($file)
    {
        $data=array();
        if (is_file($file)){
            $parsed=@parse_ini_file($file,false,INI_SCANNER_RAW);
            if (is_array($parsed)) {
                $data=$parsed;
            }
        }
        return $data;
    }

    private function _load_da_conf()
    {
        $_da_conf = array();
        if (function_exists('exec') && is_file($this->DACONF_BIN))
        {
            $out = array();
            $res = 1;
            exec($this->DACONF_BIN . " | sort", $out, $res);
            if (($res === 0) && (is_array($out)))
            {
                foreach($out as $row)
                {
                    if (strpos($row, "=") !== false)
                    {
                        $parts = explode("=", $row, 2);
                        $key = isset($parts[0]) ? $parts[0] : '';
                        $val = isset($parts[1]) ? $parts[1] : '';
                        if ($key !== '') {
                            $_da_conf[$key] = $val;
                        }
                    }
                }
                return $_da_conf;
            }
        }
        return false;
    }

    private function _init_user()
    {
        $this->_USERNAME=(isset($_SERVER['USER']) && $_SERVER['USER']) ? $_SERVER['USER'] : false;
        $user = $this->_USERNAME ? $this->_USERNAME : '';
        $this->_USER_CONF_FILE="/usr/local/directadmin/data/users/".$user."/user.conf";
        $this->_USER_DOMAINS_FILE="/usr/local/directadmin/data/users/".$user."/domains.list";
        return ($this->_USERNAME) ? true : false;
    }

    private function _load_language($force_lang=false)
    {
        $DEFAULT_LANG=array();
        $USER_LANG=array();
        $DEFAULT_LANG=$this->_load_conf_data(PLUGIN_LANG_DIR."/lang_en.php");
        $selected_lang=($force_lang !== false) ? strtolower((string)$force_lang) : plugin_da_language();
        if ($selected_lang != "en") {
            $USER_LANG=$this->_load_conf_data(PLUGIN_LANG_DIR."/lang_".$selected_lang.".php");
        }
        return array_merge((array)$DEFAULT_LANG, (array)$USER_LANG);
    }
}
// END
