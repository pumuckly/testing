<?php

namespace Pumuckly\Testing;

final class PROCESS {

    protected static $_engine_detect = [
                          'openlink' => ['key'=>'selenium', 'model'=>'Pumuckly\\Testing\\SELENIUM'],
                          'gethtml'  => ['key'=>'parallel', 'model'=>'Pumuckly\Testing\\PCURL'],
                      ];

    public static function run() {
        if (func_num_args() !== 3) { return false; }

        $thread = func_get_arg(0);
        if (!ARRAYS::check($thread)) { return false; }
        $thread_id = ARRAYS::get($thread, 'id');
        $log_params = ARRAYS::get($thread, 'log');
        FILE::logSetParam($log_params);
        unset($log_params);

        $config = func_get_arg(1);
        if (!ARRAYS::check($config)) { return false; }

        $data = func_get_arg(2);
        $record_id = ARRAYS::get($data,'id');
        $status = ARRAYS::get($data,'status');
        if ((empty($record_id))||($record_id <= 0)) { return false; }

        $engine_code = false;
        $engine_cfg = [];
        $_engine = ARRAYS::get($config,['job','engine']);
        if (ARRAYS::check($_engine, 'selenium')) {
            $engine_cfg = ARRAYS::get($_engine,['selenium']);
            $engine_code = 'selenium';
            if (!ARRAYS::check($engine_cfg,'server','string')) { return false; }
        }
        elseif (ARRAYS::check($_engine, 'parallel')) {
            $engine_cfg = ARRAYS::get($_engine,['parallel']);
            $engine_code = 'parallel';
        }

        $db = DB::getInstance($config['db']);

        FILE::debug($thread_id.'. thread begin with record id: '.$record_id, 3);

        $terminate = false;
        $step_keys = [];
        foreach ($config['steps'] as $key => $substep) {

            $allowed_step = false;
            $allowed_statuses = ARRAYS::get($substep, ['_', 'status']);
            if ((ARRAYS::check($allowed_statuses))&&(in_array($status, $allowed_statuses))) {
                 $allowed_step = true;
            }

            if (empty($allowed_step)) { continue; }
            if ($terminate) { break; }

            $engine = false;
            foreach ($substep as $step => $task) {
                $type = ARRAYS::get($task, 'type');
                if (empty($type)) { continue; }
                if (!array_key_exists($type, self::$_engine_detect)) { continue; }
                $e_code = ARRAYS::get(self::$_engine_detect, [$type,'key']);
                if ($engine_code !== $e_code) { continue; }
                $model = ARRAYS::get(self::$_engine_detect, [$type,'model']);
                if (empty($model)) { continue; }
                $engine = new $model($engine_cfg);
                break;
            }

            try {
                $res = [];
                foreach ($substep as $step => $task) {
                    if ($step == '_') { continue; }
                    if ((!is_array($task))||(!array_key_exists('type', $task))||(empty($task['type']))||(!array_key_exists('params',$task))||(!is_array($task['params']))) { continue; }

                    $type = ARRAYS::get($task, 'type');
                    if (empty($type)) { continue; }
                    $type = strtolower($type);
                    $step_key = 'step_'.$key.$step;

                    $params = ARRAYS::get($task, 'params');
                    if (empty($params)) { $params = []; }
                    $params['title'] = ARRAYS::get($task, 'title');
                    $params['step'] = $step_key;
                    $params['last'] = $res;

                    $method = 'processStep'.ucfirst($type);
                    if (!method_exists('Pumuckly\Testing\PROCESS', $method)) { throw new \Exception('Unknown method: '.$method); }

                    $res = PROCESS::$method($db, $engine, $params, $data, $record_id, $engine_code);
                    if (!is_array($res)) { throw new \Exception('No result for step: '.$step_key); }

                    PROCESS::processResult($res, $db, $params, $record_id, $step_key, $type);
                    unset($params);
                }

                $_status = $db->getField($record_id, 'status');
                if (!empty($_status)) { $status = $_status; }
                $status = $db->getNextStatus($status);
                $db->update($record_id, ['status'=>$status]);

                $terminate = true;
            }
            catch (\Exception $ex) {
                if (isset($params)) { unset($params); }
                FILE::debug('Processing job error. Thread: '.$thread_id.' Error: '.$ex->getMessage(),4);
                $terminate = true;
            }
            if (isset($engine)) {
                if ((is_object($engine))&&(method_exists($engine, 'close'))) {
                    $engine->close();
                }
                unset($engine);
            }
print("itt: ".$data['id'].' / '.$data['code']."\n");
return false;
        }
        return true;
    }

    public static function processResult(&$result, &$db, &$params, $id, $step, $type) {
        $error = false;
        $error = ARRAYS::get($result, 'error');
        try {
            if (!empty($error)) { throw new \Exception('Error in type: \''.$type.'\' (\''.$step.'\'). Error: '.$error); }
            $update = [];
            $timer = ARRAYS::get($result, 'timer');
            if (!empty($timer)) { $update[$step] = $timer; }

            if (!in_array($type, ['imap'])) {
                $pdata = ARRAYS::get($params, 'data');
                if ((!empty($pdata))&&(!is_array($pdata))) { $pdata = ['default' => $pdata]; }

                $data = ARRAYS::get($result, 'data');
                if ((!empty($data))&&(!is_array($data))) { $data = ['default' => $data]; }

                if ((ARRAYS::check($pdata))&&(ARRAYS::check($data))) {
                    foreach ($pdata as $field => $key) {
                        if (!array_key_exists($field, $data)) { continue; }
                        $value = ARRAYS::get($data, $field);

                        $field = ARRAYS::get($params, ['fields', $field]);
                        $fvalues = false;
                        foreach ($field as $frow) {
                            $fvalues = ARRAYS::get($frow,'value');
                            if (!empty($fvalues)) { break; }
                        }
                        if ((ARRAYS::check($fvalues))&&(empty($value))&&(in_array(0,$fvalues))) { $value = '0'; }

                        if (is_array($value)) { continue; } //TODO: serialize array
                        $update[$step.$key] = $value;
                    }
                }
                unset($pdata);
                unset($data);
            }
            
            if (ARRAYS::check($update)) {
                $db->update($id, $update);
            }
            unset($update);
        }
        catch (\Exception $ex) { throw new \Exception('Result processing: '.$ex->getMessage()); }
    }

    public static function processStepOpenlink(&$db, &$engine, &$params, &$data, $id, $engine_code) {
        if ($engine_code !== 'selenium') { throw new \Exception('Selenium engine required!'); }
        $step = ARRAYS::get($params, 'step');
        $last = ARRAYS::get($params, 'last');

        $res = [];
        try {
            $link = ARRAYS::get($params, 'base');
            if (empty($link)) { $link = ARRAYS::get($last, 'base', false); }
            if ((empty($link))||($link === true)&&($link === 1)||($link == '1')) { throw new \Exception('Mo base URL set for step: '.$step); }

            $proc = $engine->getUrl($link);
            $res['timer'] = ARRAYS::get($proc, 'timer');
            $error = ARRAYS::get($proc, 'error');
            if (!empty($error)) { throw new \Exception($error); }
            $res['title'] = ARRAYS::get($proc, 'title');
            if (empty($res['title'])) { throw new \Exception('Unable to find title in the page'); }
        }
        catch (\Exception $ex) { $res['error'] = "Error: ".$ex->getMessage(); }
        return $res;
    }

    public static function processStepClick(&$db, &$engine, &$params, &$data, $id, $engine_code) {
        if ($engine_code !== 'selenium') { throw new \Exception('Selenium engine required!'); }
        $step = ARRAYS::get($params, 'step');
        $last = ARRAYS::get($params, 'last');

        $res = [];
        try {
            $xpath = ARRAYS::get($params, 'xpath');
            if (empty($xpath)) { throw new \Exception('Mo XPATH set for step: '.$step); }

            $proc = $engine->clickXpath($xpath);
            $res['timer'] = ARRAYS::get($proc, 'timer');
            $error = ARRAYS::get($proc, 'error');
            if (!empty($error)) { throw new \Exception($error); }
            $res['title'] = ARRAYS::get($proc, 'title');
            if (empty($res['title'])) { throw new \Exception('Unable to find title in the page'); }
        }
        catch (\Exception $ex) { $res['error'] = "Error: ".$ex->getMessage(); }
        return $res;
    }

    protected static function processStepSubmit(&$db, &$engine, &$params, &$data, $id, $engine_code) {
        if ($engine_code !== 'selenium') { throw new \Exception('Selenium engine required!'); }
        $step = ARRAYS::get($params, 'step');
        $last = ARRAYS::get($params, 'last');

        $res = [];
        try {
            $fields = ARRAYS::get($params, 'fields');
            if (!ARRAYS::check($fields)) { throw new \Exception('Not set fields for step: '.$step); }

            $submit = ARRAYS::get($params, ['submit']);
            $dl_xpath = ARRAYS::get($params, ['download']);
            $wait = ARRAYS::get($params, ['wait']);
            if ((!empty($dl_xpath))&&(($dl_xpath === true)||($dl_xpath === 1)||($dl_xpath === '1'))) { $dl_xpath = false; }

            if ((empty($submit))&&(empty($dl_xpath))) { throw new \Exception('Not set submit/get XPATH for step: '.$step); }

            foreach ($fields as $key => $xpaths) {
                if (!ARRAYS::check($xpaths)) { continue; }
                foreach ($xpaths as $xdata) {
                    $xpath = ARRAYS::get($xdata, 'xpath');
                    $value = ARRAYS::get($xdata, 'value');
                    if (empty($value)) { $value = false; }
                    $noerror = ARRAYS::get($xdata, 'noerror', false);
                    if ($noerror !== true) { $noerror = false; }

                    if ($value === true) {
                        $value = ARRAYS::get($data, $key);
                        if (empty($value)) { throw new \Exception('Can not set value to field: '.$key); }
                    }
                    if (ARRAYS::check($value)) {
                        $val_idx = array_rand($value);
                        $value = $value[$val_idx];
                        if (empty($value)) { $value = '0'; $xpath = str_replace('{{value}}', '0', $xpath); }
                        else { $xpath = str_replace('{{value}}', $value, $xpath); }
                        if (!ARRAYS::check($res,'data')) { $res['data'] = []; }
                        $res['data'][$key] = $value;
                        $value = false;
                    }
                    if (is_array($value)) { $value = false; }

                    $proc = $engine->clickXpath($xpath, $value, $noerror);
                }
            }
            $proc = [];
            $filesize = false;
            if (!empty($submit)) {
                $proc = $engine->clickXpath($submit);
                if (!empty($wait)) { $engine->wait($wait, 1); }
            }
            elseif (!empty($dl_xpath)) {
                $proc = $engine->download($dl_xpath);
                $filesize = ARRAYS::get($proc, 'filesize', false);
            }
            $res['timer'] = ARRAYS::get($proc, 'timer');
            if (!empty($filesize)) { $res['data'] = $filesize; }

            //$screenshoot = $engine->screenshoot();

            $error = ARRAYS::get($proc, 'error');
            if (!empty($error)) { throw new \Exception('XPATH error: '.$error); }
            $res['title'] = ARRAYS::get($proc, 'title');
            if (empty($res['title'])) { throw new \Exception('Unable to find title in the page'); }
        }
        catch (\Exception $ex) { $res['error'] = "Error: ".$ex->getMessage(); }
        return $res;
    }

    protected static function processStepImap(&$db, &$engine, &$params, &$data, $id, $engine_code) {
        $step = ARRAYS::get($params, 'step');
        $last = ARRAYS::get($params, 'last');
        $res = [];
        try {
            $date = $db->getField($id, $step);
            if (empty($date)) { throw new \Exception('IMAP Waiting for id: '.$id); }
            $res['date'] = $date;

            $data_key = ARRAYS::get($params, 'data');
            if ((!empty($data_key))&&(!ARRAYS::check($data_key))) {
                $link = $db->getField($id, $step.$data_key);
                if (empty($link)) { throw new \Exception('IMAP Waiting for id: '.$id); }
                $res['base'] = $link;
            }
        }
        catch (\Exception $ex) { $res['error'] = "Error: ".$ex->getMessage(); }
        return $res;
    }

    protected static function processStepGethtml(&$db, &$engine, &$params, &$data, $id, $engine_code) {
        if ($engine_code !== 'parallel') { throw new \Exception('Parallel CURL engine required!'); }
        $step = ARRAYS::get($params, 'step');
        $last = ARRAYS::get($params, 'last');

        $res = [];
        try {
            $waits = ARRAYS::get($params, 'waitfile');
            if ($waits > 0) {
                $waitfile = ARRAYS::get($data, 'waitfile');
                if (empty($waitfile)) { throw new \Exception("No waiting file specified!!"); }
                $watchdog = 10000*30; //30 secound
                while ((!is_file($waitfile))&&($watchdog>0)) { usleep(100); $watchdog--; }
                if (($watchdog <= 0)&&(!is_file($waitfile))) { throw new \Exception('Terminated processing: Waiting file not created in time (within 30 seconds.'); }
            }

            $base = ARRAYS::get($params, 'base');
            if (empty($base)) { throw new \Exception("No base URL specified!"); }
            $exclude = ARRAYS::get($params, 'exclude');
            if (!ARRAYS::check($exclude)) { $exclude = []; }

            $res = $engine->getUrl($base, $exclude);
        }
        catch (\Exception $ex) { $res['error'] = "Error: ".$ex->getMessage(); }
        return $res;
    }

    protected static function processStepGetlinks(&$db, &$engine, &$params, &$data, $id, $engine_code) {
        if ($engine_code !== 'parallel') { throw new \Exception('Parallel CURL engine required!'); }
        $step = ARRAYS::get($params, 'step');
        $last = ARRAYS::get($params, 'last');

        $res = [];
        try {
            $base = ARRAYS::get($params, 'base');
            if (empty($base)) { $base = ARRAYS::get($last, 'base'); }
            if (empty($base)) { throw new \Exception("No base URL specified!"); }
            $max_repeat = ARRAYS::get($params, 'max_repeat');
            if (empty($max_repeat)) { $max_repeat = 1; }

            $links = ARRAYS::get($params, 'links');
            if ($links === 'last') {
                $links = ARRAYS::get($last, 'links');
            }
            if (!ARRAYS::check($links)) { throw new \Exception('No more links'); }
            $res = $engine->getLinks($links, $base, $max_repeat);
        }
        catch (\Exception $ex) { $res['error'] = "Error: ".$ex->getMessage(); }
        return $res;
    }

}