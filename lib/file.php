<?php

namespace Pumuckly\Testing;

final class FILE {

    protected static $_debug = false;
    protected static $_CONFIG = [];

    public static function parseFileJSON($file) {
        if ((empty($file))||(!is_file($file))) { throw new \Exception($file.' can not open'); }
        $fh = fopen($file, 'r');
        if (!is_resource($fh)) { throw new \Exception($file.' not readable'); }
        $data = '';
        while ((!feof($fh))&&($row = fgets($fh, 8192))) { $data .= $row; }
        fclose($fh);
        $res = [];
        try { $res = json_decode($data, true); }
        catch (\Exception $ex) { $res = []; }
        if (isset($data)) { unset($data); }
        return $res;
    }

    public static function getConfig($key, $subkey = false) {
        if (!is_array(self::$_CONFIG)) { self::$_CONFIG = []; }
        if ((!is_array($key))&&($key)&&(array_key_exists($key, self::$_CONFIG))) {
            if (!$subkey) { return self::$_CONFIG[$key]; }
            elseif ((trim($subkey))&&(is_array(self::$_CONFIG[$key]))&&(array_key_exists(trim($subkey), self::$_CONFIG[$key]))) { 
                return self::$_CONFIG[$key][trim($subkey)];
            }
        }
        return false;
    }

    public static function setDebugFile($storage_root=false) {
        $ds = DIRECTORY_SEPARATOR;
        if (empty($storage_root)) { $storage_root = $ds.'tmp'.$ds.'php'.$ds.'log'.$ds; }
        $file = $storage_root.$ds.'default-'.time();

        self::$_CONFIG['debug_file'] = $file.'.log';
    }


    public static function getDebugFileName() {
        $file = self::getConfig('debug_file');
        if (empty($file)) { self::setDebugFile(); }
        return self::getConfig('debug_file');
    }

    public static function logInit($use_time = false, $debug = false) {
        if (!is_array(self::$_CONFIG)) { self::$_CONFIG = []; }
        self::$_CONFIG['_global_debug_run_time_'] = microtime(true);
        self::$_CONFIG['_debug_file_use_time'] = $use_time;
        self::$_debug = $debug;
    }

    public static function logSetParam($params = []) {
        if (!is_array(self::$_CONFIG)) { self::$_CONFIG = []; }
        self::$_debug = ARRAYS::get($params, 'debug');
        self::$_CONFIG['debug_file'] = ARRAYS::get($params, 'file');
        self::$_CONFIG['_debug_file_use_time'] = ARRAYS::get($params, 'use_time');
        self::$_CONFIG['_debug_file_header'] = ARRAYS::get($params, 'header');
        self::$_CONFIG['_debug_file_header_var'] = ARRAYS::get($params, 'header_var');
    }

    public static function logGetParam() {
        return [
            'debug' => self::$_debug,
            'file' => self::getConfig('debug_file'),
            'use_time' => self::getConfig('_debug_file_use_time'),
            'header' => self::getConfig('_debug_file_header'),
            'header_var' => self::getConfig('_debug_file_header_var'),
        ];
    }
    /*
     * $log_level  0: info 1-3: notify, 4-7:warning, 8:error, 9:critical
     */
    public static function debug($source, $log_level = 0, $filename = false) {
        if ((self::$_debug === true)||((self::$_debug !== false)&&(self::$_debug >= 0)&&($log_level !== false)&&($log_level >= 0)&&($log_level >= self::$_debug))) {
            self::log($source, $filename);
        }
    }

    public static function log($source, $filename = false, $recreate = false, $level = 4, $_last_level = 0, $_is_start = true, $_only_variable = true, $_use_separator=false) {
        if (!is_array(self::$_CONFIG)) { $_CONFIG = []; }
        if (!array_key_exists('_debug_time_level_', self::$_CONFIG)) { self::$_CONFIG['_debug_time_level_'] = []; }

        $use_time = (($_is_start)&&(!empty(self::getConfig('_debug_file_use_time')))) ? true: false;

        $set_header_key = 'debug_file_header';
        if ($_only_variable) { $set_header_key .= '_var'; }

        if (!array_key_exists($set_header_key, self::$_CONFIG)) { self::$_CONFIG[$set_header_key] = false; }

        $is_global_file = false;
        $file = self::getConfig('_global_debug_file_name_');
        if ((!empty($file))&&(is_file($file))) { $is_global_file = true; $recreate = false; }
        else {
            $file = self::getDebugFileName();
            $root = dirname($file);
            if ($filename) { $file = $root.DIRECTORY_SEPARATOR.$filename; }
            if (($file)&&(!is_dir(dirname($file)))) { DIRECTORY::Create(dirname($file),true,$root); }
        }

        if ((empty($file))||(!is_dir(dirname($file)))) { throw new \Exception('Cold not create log file: '.$file); }

        $tab_len = 3; $tab_key = " ";
        $newline = "\n";
        $time_key = gmdate("[Y-m-d H:i:s] ");
        if ((($level<=0)&&($_is_start))&&($level > 20)) { $level = 4; }
        $have_start_out = false;

        if (is_file($file)) { @chmod($file, 0664); }
        if ($_is_start) {
            if (($recreate)&&(is_file($file))) { unlink($file); }
            $called_url = '';

            $backtrack = '';
            $lastfile = '';
            if ((!$_only_variable)||($is_global_file)) {
                $res = debug_backtrace();
                if (($res)&&(ARRAYS::check($res,1))) {
                    $cnt = 0;
                    $ds = preg_quote(DIRECTORY_SEPARATOR,'/');
                    foreach ($res as $res_key => $res_data) {
                        if (!is_array($res_data)) { continue; }
                        $res_str = $line = "";
                        $res_file = ARRAYS::get($res_data, 'file');
                        $res_line = MATH::num(ARRAYS::get($res_data, 'line'));
                        $res_func = ARRAYS::get($res_data, 'function');
                        if ($res_file) {
                            $res_file = preg_replace("/^".$ds."var".$ds."www".$ds."[a-z0-9]+".$ds."\.code([a-z_]+)?".$ds."/",'',$res_file);
                            $backtrack .= $time_key.'Called file ('.$res_key.'): '.$res_file.$newline;
                        }
                        if ($res_line>0) { $line = ' (line: '.$res_line.') '; }
                        if ($res_func) { $res_str = ' - Called function: '.$res_func.'([...]);'.$line; }
                        if (($res_str)&&(!$_only_variable)) { $backtrack .= $time_key.$res_str.$newline; }
                        if (($res_file)&&($is_global_file)&&(empty($lastfile))&&(!preg_match("/^(debug_file|log)/",$res_func))) {
                            $lastfile = $time_key.
                                        (microtime(true) - self::getConfig('_global_debug_run_time_')).'s'.
                                        '; RAM: '.memory_get_usage(true).
                                        '; PID: '.getmypid().'/'.posix_getpgid(getmypid()).
                                        '; '.$res_file.
                                        ($res_line?'('.$res_line.')':'').
                                        ($res_func?': '.$res_func.'([...]);':'').$newline;
                        }
                    }
                }
                if ((!$_only_variable)&&($_use_separator)) { $backtrack .= str_pad('', 80, '-').$newline; }
            }

            $out = '';
            if ((empty(ARRAYS::get(self::$_CONFIG, $set_header_key)))&&($_use_separator)) {
                $out .= str_pad('', 80, '=').$newline;
                $out .= $time_key."Called URL: ".$called_url.$newline;
                $out .= str_pad('', 80, '-').$newline;
                $have_start_out = false;
                self::$_CONFIG[$set_header_key] = true;
                error_log($out, 3, $file);
            }
            if (($is_global_file)&&(!empty($lastfile))&&($_use_separator)) { error_log($lastfile, 3, $file); }

            $out = '';
            if (!$_only_variable) { $out .= $backtrack; }
            if ($out) { error_log($out, 3, $file); }

            if (is_array($source)) { error_log('array('.$newline, 3, $file); } //)
        }
        if (is_file($file)) { @chmod($file, 0664); }

        $spaces = str_pad("", $_last_level*$tab_len, $tab_key);
        $subspaces = str_pad("", ($_last_level+1)*$tab_len, $tab_key);

        if ((!is_array($source))&&(!is_object($source))) {
            error_log((($_is_start&&$use_time)?$time_key:'').$spaces.$source.$newline, 3, $file); 
        }
        elseif ($level > 0) {
            if ((is_object($source))&&($_is_start)) { error_log($spaces."// {This variable is an object: (".get_class($source).")!}".$newline, 3, $file); }
            foreach ($source as $subkey => $substr) {
                if (is_resource($substr)) { error_log($subspaces."\"".$subkey."\" => null, //{This variable is a resource object!}".$newline, 3, $file); }
                elseif ((!is_array($substr))&&(!is_object($substr))) {
                    $value = "\"".$substr."\"";
                    if ($substr === false) { $value = "false"; }
                    elseif ($substr === true) { $value = "true"; }
                    elseif (is_null($substr)) { $value = "NULL"; }
                    elseif (preg_match("/^(\-)?(0|[1-9])[0-9]*(\.[0-9]+)?\$/", $substr)) { $value = $substr; }
                    error_log($subspaces."\"".$subkey."\" => ".$value.",".$newline, 3, $file);
                }
                else {
                    $sskey = "array("; $ssend = ")";
                    if (is_object($substr)) { $sskey = "object(".get_class($substr).")["; $ssend = "]"; }
                    if ($_is_start) { $ssend .= ", "; } else { $ssend .= ","; }

                    error_log($subspaces."\"".$subkey."\" => ".$sskey.$newline, 3, $file);
                    self::log($substr, $filename, false, $level-1, $_last_level+1, false, $_only_variable, false);
                    error_log($subspaces.$ssend.$newline, 3, $file);
                }
            }
        }

        if ($_is_start) { //(
            if (is_array($source)) { error_log(');'.$newline, 3, $file); }
            if ($_use_separator) { error_log(str_pad('', 80, '-').$newline, 3, $file); }
        }
    }
}