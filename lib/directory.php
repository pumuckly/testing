<?php

namespace Pumuckly\Testing;

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

}