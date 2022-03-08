<?php

namespace Pumuckly\Testing;

final class ARRAYS {

    public static function check(&$arr, $key = null, $chk_type = 'array', $chk_array_noempty = true) {
        if ((!is_array($arr))||(empty($arr))||(count($arr)==0)) { return false; }
        if ((empty($key))&&(is_null($key))&&($key!==0)) { return true; }

        if (((!empty($key))||($key===0))&&(array_key_exists($key, $arr))) {
            if (!$chk_type) { return true; }
            elseif ($chk_type === true) {
                if ((is_array($arr[$key]))||(is_resource($arr[$key]))||(is_object($arr[$key]))) { return true; }
                if (!empty($arr[$key])) { return true; }
                else { return false; }
            }
            $chk_func = 'is_'.strtolower($chk_type);
            if ((function_exists($chk_func))&&($chk_func($arr[$key]))) {
                if (($chk_array_noempty)&&(strtolower($chk_type)=='array')) {
                    if (!empty($arr[$key])) { return true; }
                } else { return true;  }
            }
        }
        return false;
    }

    public static function get(&$arr, $key, $default = null, $check_empty = true, $enable_array = true, $level=32) {
        if ((!is_array($arr))||($level <= 0)||((empty($key))&&($key !== 0))) { return $default; }
        if (is_array($key)) {
            $skey = array_shift($key);
            if (!self::check($arr, $skey)) {
                return self::get($arr, $skey, $default, $check_empty, $enable_array, ($level-1));
            }
            if ((empty($key))&&($enable_array)) { $key = $skey; }
            else {
                return self::get($arr[$skey], $key, $default, $check_empty, $enable_array, ($level-1));
            }
        }
        if (self::check($arr, $key, false)) {
            if ($check_empty) {
                if ((is_array($arr[$key]))&&(count($arr[$key]) == 0)) { return false; }
                elseif (empty($arr[$key])) { return false; }
            }
            return $arr[$key];
        }
        return $default;
    }

    public static function firstIn(&$source, $searchValue, $subkey=false) {
        if ((is_array($searchValue))||(!is_array($source))) { return false; }
        if ((empty($subkey))&&(!in_array($searchValue, $source))) { return false; }
        foreach ($source as $skey => $sval) {
            if (!empty($subkey)) { $sval = ARRAYS::get($sval, $subkey, false); }
            if ($sval == $searchValue) { return $skey; } 
        }
        return false;
    }

}