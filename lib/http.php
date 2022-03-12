<?php

namespace Pumuckly\Testing;

/*
 * curl extension for get content from url fuction if url is under follower redirections
 */
if (!function_exists("curl_exec_follow")) {
    function curl_exec_follow(/*resource*/ &$ch, /*bool*/ $curlopt_header = false, /*int*/ $redirects = 20) {
        if ($redirects <= 0) { return false; }
        if ((!ini_get('open_basedir'))&&(!ini_get('safe_mode'))) { return false; }

        $data = false;
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        @curl_setopt($ch, CURLOPT_HEADER, true);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_FORBID_REUSE, false);

        $is_error = $is_exit = $is_found = false;
        do {
            $data = @curl_exec($ch);
            if (@curl_errno($ch)) { $is_error = true; }
            else {
                $code = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($code != 301 && $code != 302) { $is_exit = true; }
                else {
                    $header_start = strpos($data, "\r\n")+2;
                    $headers = substr($data, $header_start, strpos($data, "\r\n\r\n", $header_start)+2-$header_start);
                    if (!preg_match("/\r\n(?:Location|URI): *(.*?) *\r\n/i", $headers, $matches)) { $is_found = true; }
                    @curl_setopt($ch, CURLOPT_URL, $matches[1]);
                }
            }
            --$redirects;
        } while (($redirects > 0)&&(!$is_error)&&(!$is_exit)&&(!$is_found));

        if ($redirects <= 0) {
            trigger_error('Too many redirects. When following redirects, libcurl hit the maximum amount.', E_USER_WARNING);
        }
        elseif ((!$curlopt_header)&&($data)) {
            if (strpos($data, "\r\n\r\n")===false) { $data = ""; }
            else { $data = substr($data, strpos($data, "\r\n\r\n")+4); }
        }

        return $data;
    }
}

final class HTTP {

    protected static $_debug = false;

    protected static $_temp_dir = DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR;
    protected static $_cookie_dir = DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'www'.DIRECTORY_SEPARATOR.'.cookie'.DIRECTORY_SEPARATOR;

    protected static $_last_curl = [];

    public static function setLastCurl($url, $key, $value) {
        if (!is_array(self::$_last_curl)) { self::$_last_curl = []; }
        if (!array_key_exists($url, self::$_last_curl)) { self::$_last_curl[$url] = []; }
        if (!array_key_exists($key, self::$_last_curl[$url])) { self::$_last_curl[$url][$key] = false; }
        self::$_last_curl[$url][$key] = $value;
    }

    public static function getLastCurl($url, $key, $clean = false) {
        if (!is_array(self::$_last_curl)) { self::$_last_curl = []; }
        if (!array_key_exists($url, self::$_last_curl)) { self::$_last_curl[$url] = []; }
        $res = null;
        if (array_key_exists($key, self::$_last_curl[$url])) {
            $res = self::$_last_curl[$url][$key];
            if ($clean) {
                unset(self::$_last_curl[$url][$key]);
                if (count(self::$_last_curl[$url])==0) { unset(self::$_last_curl[$url]); }
            }
        }
        return $res;
    }

    public static function cleanLastCurl($url) {
        if (!is_array(self::$_last_curl)) { self::$_last_curl = []; }
        if (array_key_exists($url, self::$_last_curl)) {
            unset(self::$_last_curl[$url]);
        }
    }

    public static function setError($url, $code=0, $message='') {
        self::setLastCurl($url, 'error', ['id'=>$code, 'str'=>$message]);
    }

    public static function getSavedFileName($filename) {
        return self::$_temp_dir.$filename;
    }

    public static function getRunTime($url, $clean = false) {
        return self::getLastCurl($url, 'run_time', $clean);
    }

    public static function getHttpCode($url, $clean = false) {
        return self::getLastCurl($url, 'http_code', $clean);
    }

    public static function getContentType($url, $clean = false) {
        return self::getLastCurl($url, 'content_type', $clean);
    }

    public static function getHeader($url, $clean = false) {
        return self::getLastCurl($url, 'header', $clean);
    }

    public static function getError($url, $numeric = true, $clean = false) {
        $res = self::getLastCurl($url, 'error', $clean);
        $id = 0;
        $str = '';
        if (is_array($res)) {
            if (array_key_exists('id', $res)) { $id = $res['id']; }
            if (array_key_exists('str', $res)) { $str = $res['str']; }
            if (empty($id)) { $id = ''; }
        }
        if ($id == 0) { if ($numeric) { return 0; } return false; }
        elseif ((!$numeric)&&(!empty($str))) { return $str; }
        return $id;
    }

    public static function getUrlStat() {
        if (func_num_args() !== 2) { return false; }

        $thread = func_get_arg(0);
        if (!ARRAYS::check($thread)) { return false; }
        $thread_id = ARRAYS::get($thread, 'id');
        $log_params = ARRAYS::get($thread, 'log');
        FILE::logSetParam($log_params);
        unset($log_params);

        $data = func_get_arg(1);
        $url = ARRAYS::get($data,'url');
        $type = ARRAYS::get($data,'type');
        $params = ARRAYS::get($data,'params');
        $max_repeat = ARRAYS::get($data,'max_repeat');
        unset($data);
        if ((empty($url))||(empty($type))) { return false; }
        $type = strtolower($type);
        if (!is_array($params)) { $params = []; }
        $params['exception'] = true;

        $res = ['url'=>$url, 'type'=>$type, 'errors'=>false];
        try {
            $error = false;
            $content = false;
            if (empty($max_repeat)) { $max_repeat = 1; }
            while ((empty($content))&&($max_repeat>0)) {
                $max_repeat--;
                try {
                    $content = self::getOtherSiteContent($url, false, $params, 60);
                }
                catch (\Exception $ex) {
                    $res['errors'] = true;

                    $err_code = 0;
                    $msg = $ex->getMessage();
                    if (preg_match("/;\s*error:\s*([0-9]+);/is",$msg,$m)) { $err_code = $m[1]; }

                    if (($err_code == 6)&&($max_repeat>0)) {
                        FILE::debug('Error found: '.$err_code.' - Waiting for to repeat get URL '.$url,5);
                        usleep(100000*mt_rand(10,50)); //when curl "Could not resolve host", wait for 1.0s - 5.0s randomly
                    }
                    else {
                        FILE::debug($err_code.' - '.$msg,5);
                        throw new \Exception($msg);
                    }
                }
            }
            if (empty($content)) { throw new \Exception('No content for url: '.$url); }

            $res['timing'] = self::getRunTime($url);
            $res['length'] = strlen($content);

            if ($type == 'stylesheet') {
                //TODO: parse stylesheet urls
            }
            unset($content);
        }
        catch (\Exception $ex) { $res['error'] = $ex->getMessage(); }
        return $res;
    }

    public static function getOtherSiteContent($url, $post_data=false, $params=[], $timeout = 15, $debug = false) {
        global $_GET, $_POST, $_SERVER, $HTTP_RAW_POST_DATA;
        $HTTP_RAW_POST_DATA = null;

        self::setError($url, 0, '');

        self::setLastCurl($url, 'content_type', '');
        self::setLastCurl($url, 'http_code', false);
        self::setLastCurl($url, 'header', []);
        self::setLastCurl($url, 'run_time', 0);
        self::setLastCurl($url, 'error', 0);
        self::setLastCurl($url, 'error_str', '');

        $ret = '';

        $exception = false;
        $proxy = [];
        $headers = [];
        $header_keys = [];
        $cookie_file = "";
        $save_as_file = "";
        $content_type = "";
        $config_auth = false;
        $send_json = false;
        $process_json = false;
        $get_header = false;
        $binary_transfer = false;
        $content_charset = 'UTF-8';

        if ($timeout <= 0) { $timeout = 15; }
        if (!is_array($params)) { $params = []; }

        if (!$url) {
            self::setError($url, -2, 'CURL No set URL to get content');
            return false;
        }

        $is_post = false;
        $post_method = "GET";
        if (($post_data !== false)&&(!is_null($post_data))&&((!is_array($post_data))||((is_array($post_data))&&(count($post_data)>0)))) { $is_post = true; $post_method = "POST"; }

        $useragent = 'PHP/'.phpversion();
        if (array_key_exists('HTTP_USER_AGENT',$_SERVER)) { $useragent = $_SERVER['HTTP_USER_AGENT']; }

        $default_port = 80;
        $auth_user = $auth_pass = $auth_bearer = '';
        $url_host = ''; $url_port = ''; $url_ssl = false;
        if (preg_match("/^http(s)?\:\/\/(([a-zA-Z0-9\-_]+)(\:([a-zA-Z0-9\-]+))?\@)?([a-z0-9\.\-]+)(\:([1-9][0-9]+))?(\/)?/i", $url, $urlm)) {
            if ((array_key_exists(3,$urlm))&&(trim($urlm[3]))) { $auth_user = trim($urlm[3]); }
            if ((array_key_exists(5,$urlm))&&(trim($urlm[5]))) { $auth_pass = trim($urlm[5]); }
            if ((array_key_exists(6,$urlm))&&(trim($urlm[6]))) { $url_host = trim($urlm[6]); }
            if ((array_key_exists(1,$urlm))&&($urlm[1]=='s')&&($url_host)) { $url_ssl = true; $default_port = 443; }
            if ((array_key_exists(8,$urlm))&&(is_numeric($urlm[8]))&&($urlm[8]*1>0)&&($urlm[8]!=$default_port)) { $url_port = $urlm[8]*1; }
        }

        if (!$url_host) {
            self::setError($url, -3, 'CURL No set URL host');
            return false;
        }

        if ((is_array($params))&&(count($params)>0)) {
            foreach ($params AS $p_key => $p_data) {
                switch (strtolower($p_key)) {
                    case "getheader": $get_header = true; break;
                    case "bearer": $auth_bearer = trim($p_data); break;
                    case "binary": if (!empty($p_data)) { $binary_transfer = true; } break;
                    case "exception": if (!empty($p_data)) { $exception = true; } break;
                    case "useragent": if (!empty($p_data)) { $useragent = $p_data; } break;
                    case "json":
                        if ($p_data == 'processonly') { $process_json = true; }
                        else { 
                            if ($p_data == 'process') { $process_json = true; }
                            $send_json = true; 
                        }
                        break;
                    case "proxy":
                        if ((is_array($p_data))&&(count($p_data)>0)) {
                            foreach ($p_data AS $h_key => $h_val) { $proxy[strtolower($h_key)] = $h_val; }
                        }
                        break;
                    case "method":
                        if ((!is_array($p_data))&&(trim($p_data)!="")) {
                            $post_method = strtoupper(trim($p_data));
                            if ($post_method == "GET") { $is_post = false; }
                        }
                        break;
                    case "cookie":
                        if ((!empty($p_data))&&(empty($cookie_file))) { $cookie_file = self::$_cookie_dir.$p_data.".cookie"; }
                        break;
                    case "saveas": case "save_as":
                        if (!empty($p_data)) {
                            $save_as_file = self::getSavedFileName($p_data);
                            if (empty($cookie_file)) { $cookie_file = self::$_cookie_dir.$url_host.".cookie"; }
                        }
                        break;
                    case "header":
                        if ((is_array($p_data))&&(count($p_data)>0)) {
                            foreach ($p_data AS $h_key => $h_val) {
                                if (!in_array(strtolower($h_key), $headers_set)) {
                                    if (strtolower($h_key)=="content-type") { $content_type = $h_val; }
                                    $headers_set[] = strtolower($h_key);
                                    $headers[] = $h_key.": ".$h_val;
                                }
                            }
                        }
                        break;
                    default: break;
                }
            }
        }

        if (($auth_user)||($auth_pass)) { $config_auth = $auth_user.":".$auth_pass; }
        if (($auth_bearer)&&(!in_array("authorization",$headers_set))) { $headers_set[] = "authorization"; $headers[] = "Authorization: Bearer ".$auth_bearer; }
        if (($is_post)&&(!$content_type)) { $content_type = "application/x-www-form-urlencoded"; $headers_set[] = "content-type"; $headers[] = "Content-type: ".$content_type; }
  
        if (($is_post)&&(((in_array("content-type", $headers_set))&&($content_type == "application/x-www-form-urlencoded")) || (is_array($post_data)))) {
            if ($send_json) { $post_data = json_encode($post_data); }
            elseif ((is_array($post_data))&&(count($post_data)>0)) {
                $is_simple_array = true;
                $check_counter = 0;
                foreach ($post_data as $p_key => $p_val) {
                    if (($p_key !== $check_counter)||(is_array($p_val))||(is_object($p_val))) { $is_simple_array = false; }
                    elseif ((!$p_val)||(strpos($p_val, '&')!==false)) { $is_simple_array = false; }
                    $check_counter++;
                    if (!$is_simple_array) { break; }
                }
                if ($is_simple_array) { $post_data = implode('&', $post_data); }
                else { $post_data = http_build_query($post_data, '', '&'); }
            }
        }

        $follow_redirect = false;
        $curlopt_header = false;

        $options = [];
        $options[CURLOPT_URL] = $url;
        $options[CURLOPT_USERAGENT] = $useragent;
        $options[CURLOPT_TIMEOUT] = $timeout;
        $options[CURLOPT_HTTPHEADER] = $headers;
        if (((!ini_get('open_basedir'))&&(!ini_get('safe_mode')))||(!$curlopt_header)) {
            $options[CURLOPT_HEADER] = $curlopt_header;
        }

        if (($url_port)&&($url_port != $default_port)) {
            $options[CURLOPT_PORT] = $url_port;
        }

        if ($config_auth) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $config_auth;
        }

        if (!$save_as_file) {
            $options[CURLOPT_VERBOSE] = (($debug) ? true : false);
            $options[CURLOPT_RETURNTRANSFER] = true;
        }

        if ((is_array($proxy)) && (count($proxy) > 0) && (array_key_exists('host', $proxy)) && (array_key_exists('port', $proxy)) && (array_key_exists('user', $proxy)) && (array_key_exists('pass', $proxy))) {
            $options[CURLOPT_PROXY] = $proxy['host'].":".$proxy['port'];
            $options[CURLOPT_PROXYPORT] = $proxy['port'];
            $options[CURLOPT_PROXYUSERPWD] = $proxy['user'].":".$proxy['pass'];
        }

        if ($cookie_file) {
            $options[CURLOPT_COOKIEJAR] = $cookie_file;
            if ((is_file($cookie_file)) && (filesize($cookie_file) > 0)) {
                $options[CURLOPT_COOKIEFILE] = $cookie_file;
            }
        }

        if (($is_post)&&($post_method)&&($post_method != "GET")) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_CUSTOMREQUEST] = $post_method;
            $options[CURLOPT_POSTFIELDS] = $post_data;
        }
        else {
            $options[CURLOPT_POST] = false;
            $options[CURLOPT_HTTPGET] = true;
            if ((ini_get('open_basedir'))||(ini_get('safe_mode'))) { $follow_redirect = true; }
            else { $options[CURLOPT_FOLLOWLOCATION] = true; }
        }

        if ($binary_transfer) { $options[CURLOPT_BINARYTRANSFER] = true; }

        if ($url_ssl) {
            $options[CURLOPT_SSL_VERIFYHOST] = false;
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            switch ($url_host) {
                default:
                    // //TODO: when need setup ssl certificate
                    // $options[CURLOPT_SSLCERT] = 'host.cert';
                    // $options[CURLOPT_SSLKEY] = 'host.key';
                    // $options[CURLOPT_SSLKEYPASSWD] = '';
                    break;
            }
        }
        $file_handler = false;
        if ($save_as_file) {
            $file_handler = fopen($save_as_file, "w");
            $options[CURLOPT_FILE] = $file_handler;
        }

        //call curl
        $run_time = 0.0 - microtime(true);
        $ch = curl_init();
        if ((!is_resource($ch))&&(!is_object($ch))) {
            self::setError($url, -1, 'CURL can not start on url: '.$url);
            return false;
        }

        curl_setopt_array($ch, $options);
        if (!$follow_redirect) { $response = curl_exec($ch); }
        elseif ($file_handler) { $response = curl_exec($ch); }
        else { $response = curl_exec_follow($ch, $curlopt_header); }
        $run_time += microtime(true);

        $response_header = curl_getinfo($ch);
        self::setError($url, curl_errno($ch), curl_error($ch));
        curl_close($ch);

        unset($options);

        if ($file_handler) {
            fclose($file_handler);
            chmod($save_as_file, 0666);
            if (($cookie_file)&&(is_file($cookie_file))) { unlink($cookie_file); }
        }

        $ret = ($HTTP_RAW_POST_DATA != '') ? $HTTP_RAW_POST_DATA : $response;

        self::setLastCurl($url, 'run_time', $run_time);
        self::setLastCurl($url, 'content_type', ((is_array($response_header))&&(array_key_exists('content_type', $response_header))) ? $response_header['content_type'] : '');
        self::setLastCurl($url, 'http_code', ((is_array($response_header))&&(array_key_exists('http_code', $response_header))) ? $response_header['http_code'] : false);
        self::setLastCurl($url, 'header', (($get_header)&&(is_array($response_header))) ? $response_header : []);

        $error_code = self::getError($url);
        $error_message = self::getError($url, false);
        if ((!empty($error_code))||($debug)||(self::$_debug)) {
            self::setLastCurl($url, 'error', $error_code);
            self::setLastCurl($url, 'error_str', $error_message);

            $err_msg = "CURL error: ".$post_method." ".$url_host." ".$error_message." (request: ".$url."; error: ".$error_code."; response length: ".strlen($ret).")";
            if ((!empty($error_code))&&(!empty($exception))) {
                if (($error_code == 28)&&(preg_match("/^Operation timed out after ([0-9]+) milliseconds/is", $error_message, $errm))) {
                    $rtime = round((array_get($errm,1)*1)/1000,2);
                    $err_msg = "CURL error: ".$post_method." ".$url_host." Operation Timed out after ".$rtime."s. (request: ".$url.")";
                }
                throw new \Exception($err_msg);
            }
            FILE::debug($err_msg,7);
            if (($debug)||(self::$_debug)) {
                FILE::debug($response_header,5);
                FILE::debug($ret,5);
            }
        }

        if (($process_json)&&(is_array($response_header))&&(array_key_exists('content_type', $response_header))) {
            if (preg_match("/^((application|text)\/(x-)?json)(;\s+charset=([A-Z0-9\_\-]+))?(;.*)?\$/",$response_header['content_type'], $rm)) {
                $encoding = 'UTF-8';
                if (array_key_exists(5,$rm)) { $encoding = trim(strtoupper($rm[5])); }
                if ($encoding !== 'UTF-8') {
                    self::setLastCurl($url, 'error', -6002);
                    self::setLastCurl($url, 'error_str',  'Only UTF-8 encoded content allowed.');
                    $ret = false;
                    return $ret;
                }
                try {
                    $res = json_decode($ret, true);
                    if (is_array($res)) { $ret = false; return $res; }
                }
                catch (\Exception $ex) {
                    self::setLastCurl($url. 'error', -6003);
                    self::setLastCurl($url, 'error_str', 'Could not decode JSON data.');
                    $ret = false;
                    return $ret;
                }
            }
            self::setLastCurl($url, 'error', -6001);
            self::setLastCurl($url, 'error_str'. 'Unknown JSON source received.');
        }
        return $ret;
    }

}