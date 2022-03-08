<?php

namespace Pumuckly\Testing;

final class MATH {

    public static function num($num) {
        $num = trim($num);
        $negCalc = 1;
        if (strpos($num,'-')===0) { $negCalc = -1; }
        $dotPos = strrpos($num, '.');
        $commaPos = strrpos($num, ',');
        $sep = (($dotPos > $commaPos) && $dotPos) ? $dotPos :
               ((($commaPos > $dotPos) && $commaPos) ? $commaPos : false);

        if (!$sep) {
            return $negCalc * intval(preg_replace("/[^0-9]+/", "", $num));
        }

        return $negCalc * floatval(
            preg_replace("/[^0-9]+/", "", substr($num, 0, $sep)) . '.' .
            preg_replace("/[^0-9]+/", "", substr($num, $sep+1, strlen($num)))
        );
    }

}