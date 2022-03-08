<?php

namespace Pumuckly\Testing;

class FORK {

    protected $_config = [];
    protected $_threads = [];

    protected $_threads_max = 8;
    protected $_threads_nums = 8;

    protected $_process_max = false;
    protected $_processed = 0;

    protected $_mail = null;
    protected $_db = null;

    public function __construct($conf) {
        $this->_config = $conf;
        $this->_threads = [];

        $threads = $this->_threads_max;
        $cfg_threads = $this->getConfig('job','threads');
        if (($cfg_threads > 0)&&($cfg_threads < $threads)) { $threads = $cfg_threads; }
        $this->setThreads($threads);

        $max_recors = false;
        $cfg_max_records = $this->getConfig('job','processing');
        if ((!empty($cfg_max_records))&&($cfg_max_records > 0)) { $max_recors = $cfg_max_records; }
        $this->_process_max = $max_recors;

        $this->_db = DB::getInstance($this->_config['db']);
        $this->_mail = new IMAP($this->_config['imap']);

        $this->init();
        $this->run();
    }

    public function __destruct() {
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

    protected function getNextRecord($exclude_ids=[]) {
        FILE::debug('Get next record (Processed:  '.$this->_processed.' / '.$this->_process_max.')',1);

        if ((!empty($this->_process_max))&&($this->_processed >= $this->_process_max)) { $this->error('No more records allowed to processing',1); }

        $data = $this->_db->getNext($exclude_ids);
        if (!is_array($data)) { $this->error('Can not get more data from database',2); }

        $data['email'] = $this->_mail->getEmail($data['code']);
        $this->_processed++;
        return $data;
    }

    protected function init() {
        $this->initSteps();

        FILE::debug('Forking threads init with '. ($this->_threads_nums). ' threads',3);
        for ($i = 0; $i < $this->_threads_nums; $i++) {
            $this->_threads[$i+1] = ['runtime' => new \parallel\Runtime(), 'future'=>false, 'started'=>false, 'data'=>false];
        }
        return $this;
    }

    protected function initSteps() {
        $types = [];
        foreach ($this->_config['steps'] as $key => $step) {
            if (!ARRAYS::check($step)) { continue; }
            foreach ($step as $skey => $data) {
                if ($skey == '_') { continue; }
                $type = ARRAYS::get($data, 'type');
                if (empty($type)) { continue; }
                $res = ARRAYS::get($data, 'params');
                if (empty($res)) { $res = []; }
                $res['title'] = ARRAYS::get($data,'title');
                if ($type == 'imap') { $types[$type]['step_'.$key.$skey] = $res; }
            }
        }
        if (ARRAYS::check($types, 'imap')) {
            $this->_mail->setJobs($types['imap']);
        }
    }

    protected function loadOneThread(&$thread) {
        if ($thread['future'] !== false) { return false; } //already running a task on this thread?
        try {
            $ids = [];
            foreach ($this->_threads as $i => &$thr) {
                $id = ARRAYS::get($thr, ['data','id']);
                if ((!empty($id))&&(!in_array($id, $ids))) { $ids[] = $id; }
            }
            $thread['data'] = $this->getNextRecord($ids); //try to get one record from database
            $thread['started'] = false; //flag to need restart thread
        }
        catch (\Exception $ex) { return false; }
        return true;
    }

    protected function run() {
        $exit = false;
        while ((!$exit)&&(!empty($this->_threads))) {

            $this->_mail->process();


            foreach ($this->_threads as $i => &$thread) {
                if (empty($thread['data'])) {
                     $setNew = $this->loadOneThread($thread); //while has more record
                }
                if (($thread['future'] === false)&&($thread['started'] === false)&&(!empty($thread['data']))) {
                    $thread['started'] = true;
                    $callfunc = function() {
                        require_once('vendor/autoload.php');
                        include_once('vendor/pumuckly/testing/autoload.php');
                        return call_user_func_array('Pumuckly\Testing\PROCESS::run', func_get_args());
                    };
                    $thread['future'] = $thread['runtime']->run($callfunc, ['thread'=>['id'=>$i, 'parent'=>getmypid(), 'log'=>FILE::logGetParam()], 'config'=>$this->_config, 'data'=>$thread['data']]);
                    FILE::debug($i.' thread - started',2);
                    continue;
                }
                if ($thread['future'] === false) {
                    FILE::debug($i." thread - stopped",2);
                    unset($this->_threads[$i]);
                    continue;
                }
                if ($thread['future']->done()) {
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