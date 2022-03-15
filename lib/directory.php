<?php

namespace Pumuckly\Testing;

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // error was suppressed with the @-operator
    if (0 === error_reporting()) {
        return false;
    }
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

final class DIRECTORY {

    protected static $_mode = 02777;

    public static function create($path, $recursive = false, $base = false) {
        $ds = DIRECTORY_SEPARATOR;
        $dsq = preg_quote($ds, '/');
        $path = preg_replace("/".$dsq."\$/is", '', $path);
        if ($base!==false) { $base = preg_replace("/".$dsq."\$/is", '', $base); }
        if (is_dir($path)) { return true; }
        if (mkdir($path, self::$_mode, $recursive)) {
            $tmp = $path;
            while ((strlen($tmp)>10)&&((!$base)||(($base!=='')&&($tmp !== $base)&&($tmp!==$base.DS)))) {
                @chmod($tmp, self::$_mode);
                $tmp = preg_replace("/".$dsq."[^".$dsq."]+(".$dsq.")?\$/is", '', $tmp);
            }
            if (is_dir($path)) { return true; }
        }
        return false;
    }

    public static function delete($path, $recursive = false, $base = false, $background = false) {
        if (empty($base)) { return false; }
        if (!is_dir($path)) { return false; }

        $ds = DIRECTORY_SEPARATOR;
        $dsq = preg_quote($ds, '/');
        $path = preg_replace("/".$dsq."\$/is", '', $path);
        if ($base!==false) { $base = preg_replace("/".$dsq."\$/is", '', $base); }
        if (($base!==false)&&(strpos($path, $base)!==0)) { return false; }

        $have_file = false;
        try {
            $files = scandir($path);
            foreach ($files as $file) {
                if (($file == '.')||($file == '..')) { continue; }
                $fname = $path . DIRECTORY_SEPARATOR . $file;
                if (!is_readable($fname)) {
                    $have_file = true;
                    break;
                }
                if ((is_dir($fname))&&(!is_link($fname))) {
                    self::delete($fname, $recursive, $base, false);
                }
                else {
                    @unlink($fname);
                }
                if ((is_file($fname))||(is_dir($fname))||(is_link($fname))) {
                    $have_file = true;
                    break;
                }
            }
        }
        catch (\ErrorException $ex) { $have_file = true; }
        catch (\Exception $ex) { $have_file = true; }
        if (!$have_file) { @rmdir($path); }
        if (!is_dir($path)) { return true; }
        if ($background===true) { @touch($path.'.delete.flag'); }
        return false;
    }

}