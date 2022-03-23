<?php

namespace Pumuckly\Testing;

class DB {

    private static $_instance = null;
    private static $_config = [];

    private $_link = null;
    private $_main = 'performance_test';

    private $_init = [];
    private $_state_checks = [];
    private $_next_statuses = [];
    private $_last_state = false;

    private function __construct() {
        try {
            if ((!is_array(self::$_config))||(count(self::$_config)<4)) { throw new \Exception('Wrong config for DB'); }
            if ((!array_key_exists('host',self::$_config))||(empty(self::$_config['host']))) { throw new \Exception('Wrong config for DB, no host'); }
            if ((!array_key_exists('user',self::$_config))||(empty(self::$_config['user']))) { throw new \Exception('Wrong config for DB, no user'); }
            if ((!array_key_exists('pass',self::$_config))||(empty(self::$_config['pass']))) { throw new \Exception('Wrong config for DB, no pass'); }
            if ((!array_key_exists('port',self::$_config))||(empty(self::$_config['port']))) { throw new \Exception('Wrong config for DB, no port'); }
            if ((!array_key_exists('base',self::$_config))||(empty(self::$_config['base']))) { throw new \Exception('Wrong config for DB, no base'); }

            if ((array_key_exists('main',self::$_config))&&(!empty(self::$_config['main']))) { $this->_main = self::$_config['main']; }
            if ((array_key_exists('states',self::$_config))&&(!empty(self::$_config['states']))&&(is_array(self::$_config['states']))) { $this->_state_checks = self::$_config['states']; }
            if ((array_key_exists('statuses',self::$_config))&&(!empty(self::$_config['statuses']))&&(is_array(self::$_config['statuses']))) { $this->_next_statuses = self::$_config['statuses']; }
            if ((array_key_exists('init',self::$_config))&&(!empty(self::$_config['init']))&&(is_array(self::$_config['init']))) { $this->_init = self::$_config['init']; }

            if (!$this->isCon()) {
                throw new \Exception('Could not connect to database! Error: '.mysqli_connect_error());
            }
            FILE::debug('Current DB character set: '. $this->_link->character_set_name(),1);
        }
        catch (\Exception $ex) {
            throw new \Exception('Unable connect to DataBase. Error: '.$ex->getMessage());
        }
    }

    public function __destruct() {
        $this->close();
    }

    private function close($force = true) {
        if (is_object($this->_link)) {
            if (($force)&&(method_exists($this->_link,'close'))) {
                $this->_link->close();
            }

            if (!empty($this->_link->thread_id)) {
                $this->_link->kill($this->_link->thread_id);
            }
            if (is_object($this->_link)) {
                unset($this->_link);
            }
        }
        $this->_link = null;
    }

    private function reconnect() {
        if (empty($this->_link)) {
            $this->_link = new \mysqli(self::$_config['host'], self::$_config['user'], self::$_config['pass'], self::$_config['base'], self::$_config['port']);
            $this->_link->set_charset('utf8');
            if (mysqli_connect_errno()) { throw new \Exception('DB Connect failed: '.mysqli_connect_error()); }
        }
        if (!$this->_link->thread_id) { throw new \Exception('Connection lost!'); }
    }

    private function isCon() {
        if ((is_object($this->_link))&&(!$this->_link->ping())) {
            $this->close(false);
        }
        if ((empty($this->_link))||(!is_object($this->_link))) {
            $this->reconnect();
        }
        return (!empty($this->_link->thread_id));
    }

    public static function getInstance($conf = false) {
        if (!self::$_instance) {
            if (ARRAYS::check($conf)) {
                self::$_config = [];
                foreach ($conf as $ckey => $cval) { self::$_config[$ckey] = $cval; }
            }
            elseif (ARRAYS::check(self::$_config)) {
                $conf = [];
                foreach (self::$_config as $ckey => $cval) { $conf[$ckey] = $cval; }
            }
            if (!ARRAYS::check($conf)) { throw new \Exception('No DB configuration found!'); }
            self::$_instance = new DB($conf);
        }
        return self::$_instance;
    }

    public function beginTransaction() {
        if (!$this->isCon()) { return false; }
        $this->_link->begin_transaction();
        return $this;
    }

    public function commit() {
        $this->_link->commit();
        return $this;
    }

    public function rollback() {
        $this->_link->rollback();
        return $this;
    }

    public function init() {
        if (!$this->isCon()) { return false; }
        if (!ARRAYS::check($this->_init,'prefix','string')) { return false; }
        $from = ARRAYS::get($this->_init,'start');
        if (empty($from)) { return false; }
        $to = ARRAYS::get($this->_init,'end');
        if (empty($to)) { return false; }
        $digit = ARRAYS::get($this->_init,'digit');
        if ($from >= $to) { return false; }
        if (empty($digit)) { $digit = 0; }

        $key = ARRAYS::get($this->_init,'prefix').'{id}'.ARRAYS::get($this->_init,'suffix');

        $this->_link->begin_transaction();
        try {
            for ($i = $from; $i <=$to; $i++) {
                $id = $i;
                if ($digit > 0) { $id = str_pad($i, $digit, '0', STR_PAD_LEFT); }
                $code = str_replace('{id}',$id, $key);
                $query = 'SELECT `id` FROM `'.$this->_main.'` WHERE (`code`=\''.$code.'\')';
                $res = $this->_link->query($query);
                if (empty($res)) { throw new \Exception('Unable to run query on datatable');  }
                if ($res->num_rows > 0) { continue; } //record exists
                $query = 'INSERT INTO `'.$this->_main.'` (`id`,`code`) VALUES (NULL, \''.$code.'\')';
                $res = $this->_link->query($query);
                if (empty($res)) { throw new \Exception('Unable to isert record to datatable'); }
            }
            $this->_link->commit();
        }
        catch (\mysqli_sql_exception $ex) { $this->_link->rollback(); }
    }

    public function getLastState() {
        if (empty($this->_last_state)) {
            foreach ($this->_next_statuses AS $val) { $this->_last_state = $val; }
        }
        return $this->_last_state;
    }

    public function getNextStatus($status) {
        if (empty($status)) { return false; }
        $new = ARRAYS::get($this->_next_statuses, $status);
        if (!empty($new)) { return $new; }
        return false;
    }

    public function getField($id, $field) {
        if (!$this->isCon()) { return false; }
        $query = 'SELECT `id`, `code`, `'.$field.'` FROM `'.$this->_main.'` WHERE (`id`=\''.$id.'\') ORDER BY `id` LIMIT 1 OFFSET 0';
        $res = $this->_link->query($query);
        if (empty($res)) { return false; }
        $ret = $res->fetch_assoc();
        unset($res);
        if (ARRAYS::check($ret, $field, false)) { return $ret[$field]; }
        return false;
    }

    public function update($id, $values) {
        if (!ARRAYS::check($values)) { return false; }
        if (!$this->isCon()) { return false; }
        if ((!is_numeric($id))||($id <=0)) { throw new \Exception('Wrong record ID for update!'); }

        $fields = '';
        foreach ($values AS $field => $value) {
            if (empty($field)) { continue; }
            if ($fields) { $fields .= ', '; }
            $fields .= '`'.$field.'`=';
            if ((empty($value))&&($value !== '0')) { $fields .= 'NULL'; }
            else { $fields .= '\''.$this->_link->real_escape_string($value).'\''; }
        }
        $query = 'UPDATE `'.$this->_main.'` SET '.$fields.' WHERE (`id`=\''.$id.'\')';
        $res = $this->_link->query($query);
        if (!$res) { throw new \Exception('Unable to update record: '.$id.' - '.$query); }

        return true;
    }

    public function getByCode($code, $field = false) {
        if ((empty($code))||(!preg_match("/^[a-zA-Z0-9]+\$/", $code))) { throw new \Exception('Wrong key code: '.$code); }
        if (!$this->isCon()) { return false; }
        $exrtra_field = '';
        if (!empty($field)) { $exrtra_field = ', `'.$field.'`'; }
        $res = $this->_link->query('SELECT `id`, `code`, `status`'.$exrtra_field.' FROM `'.$this->_main.'` WHERE `code`=\''.$this->_link->real_escape_string($code).'\' ORDER BY `id` LIMIT 1 OFFSET 0');
        if (empty($res)) { return false; }
        $data = $res->fetch_assoc();
        $id = ARRAYS::get($data, 'id');
        if ((!is_numeric($id))||($id <=0)) { throw new \Exception('Could not found code: '.$code); }
        return $data;
    }

    public function getNext($exclude_ids = [], $engine=false, $processed = 0, $processed_max = 0) {
        if (!$this->isCon()) { return false; }

        $results = [];
        $query_begin = 'SELECT `id`, `code`, `status` FROM `'.$this->_main.'` WHERE ';
        if (ARRAYS::check($exclude_ids)) { $query_begin .= '(`id` NOT IN (\''.implode('\',\'',$exclude_ids).'\')) AND '; }
        $query_end = ' ORDER BY `id` LIMIT 1 OFFSET 0';

        //get one new record
        if (($processed_max > 0)&&($processed<$processed_max)) {
            $query = $query_begin. '((`status`=\'new\') AND (`started_at` IS NULL))'.$query_end;
            $res = $this->_link->query($query);
            if (!empty($res)) { $results['new'] = $res->fetch_assoc(); }
            unset($res);
        }

        if ($engine !== 'parallel') {
            //get one sent record
            $query = $query_begin.
                     '(`status`=\'received\') AND (`started_at` IS NOT NULL) AND (`started_at` <= NOW()-10)'.
                     ((ARRAYS::check($this->_state_checks, 'received', 'string')) ? ' AND (`'.ARRAYS::get($this->_state_checks, 'received').'` IS NOT NULL)' : '').
                     $query_end;
            $res = $this->_link->query($query);
            if (!empty($res)) { $results['received'] = $res->fetch_assoc(); }
            unset($res);

            //get one received record
            $query = $query_begin.
                     '(`status`=\'processed\') AND (`started_at` IS NOT NULL) AND (`started_at` <= NOW()-10)'.
                     ((ARRAYS::check($this->_state_checks, 'processed', 'string')) ? ' AND (`'.ARRAYS::get($this->_state_checks, 'processed').'` IS NOT NULL)' : '').
                     $query_end;
            $res = $this->_link->query($query);
            if (!empty($res)) { $results['processed'] = $res->fetch_assoc(); }
            unset($res);
        }

        foreach ($results as $key => $res_data) {
            if (!ARRAYS::check($res_data)) { unset($results[$key]); }
        }
        if (empty($results)) { return false; }

        $res_idx = array_rand($results);
        $ret = $results[$res_idx];
        $ret['_is_new_code_'] = false;
        unset($results);
        //update selected record -> set status and started_at
        $status = ARRAYS::get($ret, 'status');
        if ((!empty($status))&&(in_array($status, ['new']))) {
            $status = $this->getNextStatus($status);
            $this->update(ARRAYS::get($ret, 'id'), ['status'=>$status, 'started_at'=>gmdate('Y-m-d H:i:s', time())]);
            $ret['_is_new_code_'] = true;
        }
        FILE::debug('loaded next record: '.ARRAYS::get($ret, 'id'),0);
        return $ret;
    }

}