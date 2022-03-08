<?php

spl_autoload_register(function ($class_name) {
    $class_name = str_replace("\\",DIRECTORY_SEPARATOR,strtolower($class_name));
    $file = 'vendor'.DIRECTORY_SEPARATOR.dirname($class_name).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.basename($class_name).'.php';
    if (is_file($file)) { include_once($file); }
});
