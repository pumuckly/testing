<?php

namespace Pumuckly\Testing;

class FORK {

    const SELENIUM_MODEL = 'Pumuckly\\Testing\\SELENIUM';

    protected $_config = [];
    protected $_threads = [];

    protected $_threads_max = 8;
    protected $_threads_nums = 8;
    protected $_engine = false;

    protected $_protect_threads = true;
    protected $_process_max = false;
    protected $_processed = 0;

    protected $_step = false;
    protected $_mail = null;
    protected $_db = null;

    protected $_job_types = [];

    protected $_last_id = false;

    protected $_waitfile = false;
    protected $_waitfile_name = false;
    protected $_waitfile_max = 0;

    protected $_php_tmp = DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR;

    public function __construct($conf, $step_code = false) {
        $this->_config = $conf;
        $this->_threads = [];

        $this->_waitfile = false;
        $this->_waitfile_name = false;
        $this->_waitfile_max = 0;

        $this->_step = $step_code;

        $this->_engine = false;
        $engine = $this->getConfig('job','engine');
        if (ARRAYS::check($engine,'selenium')) { $this->_engine = 'selenium'; }
        elseif (ARRAYS::check($engine,'parallel')) { $this->_engine = 'parallel'; }

        $engine_types = ARRAYS::get($engine, [$this->_engine, 'types'], false);
        if (empty($engine_types)) { $engine_types = false; }

        $max_records = false;
        $cfg_max_records = $this->getConfig('job','processing');
        if ((!empty($cfg_max_records))&&($cfg_max_records > 0)) { $max_records = $cfg_max_records; }
        $this->_process_max = $max_records;

        $threads = $this->_threads_max;
        $cfg_threads = ARRAYS::get($engine, [$this->_engine,'threads']);
        if (($this->_engine === 'parallel')&&($cfg_threads > 0)) { $threads = $cfg_threads; $this->_threads_max = $threads; }
        elseif (($this->_engine === 'selenium')&&($cfg_threads > 0)&&($cfg_threads < $threads)) { $threads = $cfg_threads; }
        else { $threads = 1; }

        if (($this->_protect_threads)&&(ARRAYS::check($engine_types))&&(count($engine_types) < $threads)) { $threads = count($engine_types); }

        if (($this->_process_max!==false)&&($threads > $this->_process_max)) { $threads = $this->_process_max; }
        if (($threads>0)&&($threads < $this->_threads_max)) { $this->_threads_max = $threads; }

        $this->_last_id = 0;

        $this->setThreads($threads);
        $this->initSteps();
        $this->init($engine_types);

        $this->run();
    }

    public function __destruct() {
        if (ARRAYS::check($this->_threads)) {
            foreach ($this->_threads as $id => &$thread) {
                if (($thread['future']!==false)&&(is_object($thread['future']))) { $thread['runtime']->kill(); }

                $engine = ARRAYS::get($thread, 'engine');
                if ((!empty($engine))&&(is_object($engine))&&(method_exists($engine, 'close'))) {
                    $engine->close();
                }
                unset($this->_threads[$id]);
            }
        }
        $this->_threads = [];

        if (is_array($this->_config)) {
            $keys = array_keys($this->_config);
            foreach ($keys as $key) {
                if (array_key_exists($key, $this->_config)) { unset($this->_config[$key]); }
            }
            unset($keys);
        }
        $this->_config = [];
    }

    protected function &getConfig($key, $subkey) {
        if (($key)&&($subkey)&&(is_array($this->_config))&&(array_key_exists($key, $this->_config))&&(is_array($this->_config[$key]))&&(array_key_exists($subkey,$this->_config[$key]))) {
            return $this->_config[$key][$subkey];
        }
        return false;
    }


    protected function setThreads($threads) {
        if ((empty($threads))||($threads < 0)||($threads > $this->_threads_max)) { $threads = $this->_threads_max; }
        $this->_threads_nums = $threads;
        return $this;
    }

    protected function error($msg, $level=1) {
        FILE::debug($msg, $level);
        throw new \Exception($msg);
    }

    protected function initSteps() {
        $this->_job_types = [];
        foreach ($this->_config['steps'] as $key => $step) {
            if (!ARRAYS::check($step)) { continue; }
            foreach ($step as $skey => $data) {
                if ($skey == '_') { continue; }
                $type = ARRAYS::get($data, 'type');
                if (empty($type)) { continue; }
                $res = ARRAYS::get($data, 'params');
                if (empty($res)) { $res = []; }
                $res['title'] = ARRAYS::get($data,'title');
                if ($type == 'imap') {
                    $this->_job_types[$type]['step_'.$key.$skey] = $res;
                }
                if ($type == 'gethtml') {
                    $this->_waitfile_max = ARRAYS::get($data, ['params','waitfile']);
                    if (empty($this->_waitfile_max)) { $this->_waitfile_max = 0; }
                    elseif ($this->_waitfile_max > $this->_threads_max) { $this->_waitfile_max = $this->_threads_max; }
                }
            }
        }
        return $this;
    }

    protected function init($engine_types = false) {
        //Initialize Database
        $this->_db = DB::getInstance($this->_config['db']);
        if (is_object($this->_db)) {
            $this->_db->init();
        }

        //Initialize imap mail engine
        $this->_mail = null;
        if (ARRAYS::check($this->_config, 'imap')) {
            $this->_mail = new IMAP($this->_config['imap']);
            if ((is_object($this->_mail))&&(ARRAYS::check($this->_job_types, 'imap'))) {
                $this->_mail->setJobs($this->_job_types['imap']);
            }
        }

        FILE::debug('Forking threads init with '. ($this->_threads_nums). ' threads for processing '.$this->_process_max.' row',3);
        for ($i = 0; $i < $this->_threads_nums; $i++) {
            $type = false;
            if (ARRAYS::check($engine_types)) {
                $etypes = array_keys($engine_types);
                $type = $etypes[($i % count($etypes))];
            }
            $model = false;
            $engine_cfg = [];
            if (($this->_engine == 'selenium')&&($type)) {
                $engine = $this->getConfig('job','engine');
                $engine_cfg = ARRAYS::get($engine,['selenium']);
                if (!ARRAYS::check($engine_cfg,'types')) { $engine_cfg = []; }
                //else { $model = self::SELENIUM_MODEL; } //TODO: for speed optimizing by store browsers in memory
            }
            $this->_threads[$i+1] = [
                  'runtime' => new \parallel\Runtime(),
                  'future'=>false,
                  'started'=>false,
                  'data'=>false,
                  'type'=>$type,
                  'engine'=>(!empty($model)) ? new $model($engine_cfg, $type) : null
            ];
        }

        return $this;
    }

    protected function getNewWaitFile() {
        $file = $this->_php_tmp.'wait_file_'.microtime(true).mt_rand(100000,999999).'.tmp';
        return $file;
    }

    protected function initWaitFile() {
        $ids = [];
        foreach ($this->_threads as $i => &$thread) {
            $id = ARRAYS::get($thread, ['data','id']);
            if ((!empty($id))&&(!in_array($id, $ids))) { $ids[] = $id; }
        }

        if (($this->_waitfile_max > 0)&&(count($this->_threads)>0)) {
            if (empty($this->_waitfile)) {
                //no waiting file yet
                if (count($ids) == 0) {
                    //create name to send it to the next thread
                    $this->_waitfile_name = $this->getNewWaitFile();
                }
                elseif (($this->_waitfile_name)&&(count($ids) == $this->_waitfile_max)) {
                    if (!is_file($this->_waitfile_name)) {
                        touch($this->_waitfile_name);
                        if (!is_file($this->_waitfile_name)) { throw new \Exception('Unable to create waiting file: '.$this->_waitfile_name); }
                        chmod($this->_waitfile_name,0666);
                        $this->_waitfile = true;
                    }
                    unset($ids);
                    return false; //break processing
                }
            } else {
                //waiting file stored
                if (count($ids)>0) { unset($ids); return false; } //waiting for all parallel task ready

                if ($this->_waitfile_name) { //all thread freed clear waiting file
                    if (is_file($this->_waitfile_name)) { unlink($this->_waitfile_name); }
                    $this->_waitfile_name = false;
                }
                $this->_waitfile = false;
                unset($ids);
                return false; //skip with clean waitfile flag
            }
        }
        return $ids;
    }

    protected function getNextRecord($exclude_ids=[], $type = false) {
        FILE::debug('Get next record (Processed:  '.$this->_processed.' / '.$this->_process_max.' / '.$this->_step.' / '.$type.')',1);
        //if ((!empty($this->_process_max))&&($this->_processed >= $this->_process_max)) { $this->error('No more records allowed to processing',1); }

        $data = $this->_db->getNext($exclude_ids, $this->_engine, $this->_processed, $this->_process_max, $type, $this->_step);
        while ((!is_array($data))&&($this->_db->getSentRecords($this->_engine, $type) > 0)) {
            sleep(1);
            $data = $this->_db->getNext($exclude_ids, $this->_engine, $this->_processed, $this->_process_max, $type, $this->_step);
        }
        if (!is_array($data)) { $this->error('Can not get more data from database',2); }

        $data['waitfile'] = $this->_waitfile_name;

        if (is_object($this->_mail)) {
            $data['email'] = $this->_mail->getEmail($data['code']);
        }

        $is_new = ARRAYS::get($data, '_is_new_code_');
        if ($is_new) { $this->_processed++; }

        return $data;
    }


    protected function setThread(&$thread, &$ids) {
        if (!empty($thread['data'])) { return false; }
        if ($thread['future'] !== false) { return false; } //already running a task on this thread?
        try {
            $type = ARRAYS::get($thread, 'type');
            $thread['data'] = $this->getNextRecord($ids, $type); //try to get one record from database
            $engine = ARRAYS::get($thread, 'engine');
            if ((!empty($engine))&&(is_object($engine))&&(method_exists($engine, 'getSessionID'))&&(!empty($engine->getSessionID()))) {
                $thread['data']['session_id'] = $engine->getSessionID();
            }
            $thread['started'] = false; //flag to need restart thread
        }
        catch (\Exception $ex) {
            return false;
        }
        return true;
    }

    protected function run() {
        if ((is_object($this->_mail))&&($this->_step == 'mail')) {
            $this->processEmails();
        }
        else {
            $this->processThreads();
        }
    }

    protected function processEmails() {
        if ((!is_object($this->_mail))||($this->_step != 'mail')) { return false; }

        try {
            while ($this->_db->getSentRecords() > 0) {
                $this->_mail->process();
                //$this->_step = 'received';
                //$this->processThreads();
                //$this->_step = 'mail';
            }
        } catch (\Exception $ex) {
            FILE::debug('Error processing emails: '.$ex->getMessage(),6);
        }
    }

    protected function processThreads() {
        $exit = false;
        while ((!$exit)&&(!empty($this->_threads))) {
            foreach ($this->_threads as $i => &$thread) {
                $wait_threads = false;
                $ids = $this->initWaitFile();
                if (($ids === false)||(!is_array($ids))) { $wait_threads = true; }

                if (!$wait_threads) {
                    $setNew = $this->setThread($thread, $ids);
                    if (isset($ids)) { unset($ids); }

                    if ($setNew) {
                        $last_id = $this->_last_id;
                        $this->_last_id = ARRAYS::get($thread, ['data','id']);
                        if ($last_id == $this->_last_id) {
                            //TODO: need next record
                        }
                    }

                    if (($thread['future'] === false)&&($thread['started'] === false)&&(!empty($thread['data']))) {
                        $thread['started'] = true;
                        $callfunc = function() {
                            require_once('vendor/autoload.php');
                            include_once('vendor/pumuckly/testing/autoload.php');
                            return call_user_func_array('Pumuckly\\Testing\\PROCESS::run', func_get_args());
                        };
                        $n_step = ARRAYS::get($thread, ['data','_step_']);
                        if (empty($n_step)) { $n_step = $this->_step; }
                        $thread['future'] =
                                $thread['runtime']->run($callfunc, [
                                      'thread' => ['id'=>$i, 'parent'=>getmypid(), 'log'=>FILE::logGetParam(), 'type'=>ARRAYS::get($thread, 'type'), 'step'=>$n_step],
                                      'config' => $this->_config,
                                      'data' => $thread['data']
                                ]);
                        FILE::debug($i.' thread - started ('.$thread['data']['id'].'/'.$thread['data']['status'].')',2);
                        continue;
                    }
                    if ($thread['future'] === false) { // no need to use this thread anymore
                        FILE::debug($i." thread - stopped",8);
                        unset($this->_threads[$i]);
                        continue;
                    }
                }
                if (($thread['future'] !== false)&&($thread['future']->done())) {
                    $result = $thread['future']->value(); // processing result

                    FILE::debug($i.' thread - processed',2);
                    FILE::debug($i.' thread result:',1);
                    FILE::debug($result,1);

                    //free the thread for the next record
                    unset($thread['future']);
                    unset($thread['data']);
                    $thread['future'] = false;
                    $thread['data'] = false;
                    continue;
                }
            }
        }
    }

}