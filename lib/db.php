<?php

namespace Pumuckly\Testing;

class DB {

    private static $_instance = null;
    private static $_config = [];

    private $_link = null;
    private $_main = 'performance_test';

    private $_state_checks = [];

    private function __construct() {
        try {
            if ((!is_array(self::$_config))||(count(self::$_config)<4)) { throw new \Exception('Wrong config for DB'); }
            if ((!array_key_exists('host',self::$_config))||(empty(self::$_config['host']))) { throw new \Exception('Wrong config for DB, no host'); }
            if ((!array_key_exists('user',self::$_config))||(empty(self::$_config['user']))) { throw new \Exception('Wrong config for DB, no user'); }
            if ((!array_key_exists('pass',self::$_config))||(empty(self::$_config['pass']))) { throw new \Exception('Wrong config for DB, no pass'); }
            if ((!array_key_exists('port',self::$_config))||(empty(self::$_config['port']))) { throw new \Exception('Wrong config for DB, no port'); }
            if ((!array_key_exists('base',self::$_config))||(empty(self::$_config['base']))) { throw new \Exception('Wrong config for DB, no base'); }
            if ((array_key_exists('main',self::$_config))&&(!empty(self::$_config['main']))) { $this->_main = self::$_config['main']; }
            if ((array_key_exists('states',self::$_config))&&(!empty(self::$_config['states']))) { $this->_state_checks = self::$_config['states']; }

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
            if ($force) { $this->_link->close(); }

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

    public function getNextStatus($status) {
        switch ($status) {
            case 'new': return 'used'; break;
            case 'used': return 'sent'; break;
            case 'sent': return 'imapcall'; break;
            case 'imapcall': return 'received'; break;
            case 'received': return 'processed'; break;
            case 'processed': return 'closed'; break;
        }
        return false;
    }

    public function getField($id, $field) {
        if (!$this->isCon()) { return false; }
        $query = 'SELECT `id`, `code`, `'.$field.'` FROM `'.$this->_main.'` WHERE (`id`=\''.$id.'\') ORDER BY `id` LIMIT 1 OFFSET 0';
        $res = $this->_link->query($query);
        if (empty($res)) { return false; }
        $ret = $res->fetch_assoc();
        unset($res);
        return ARRAYS::get($ret, $field, false);
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
            if (empty($value)) { $fields .= 'NULL'; }
            else { $fields .= '\''.$this->_link->real_escape_string($value).'\''; }
        }
        $query = 'UPDATE `'.$this->_main.'` SET '.$fields.' WHERE (`id`=\''.$id.'\')';
        $res = $this->_link->query($query);
        if (!$res) { throw new \Exception('Unable to update record: '.$id); }

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

    public function getNext($exclude_ids = []) {
        if (!$this->isCon()) { return false; }

        $results = [];
        $query_begin = 'SELECT `id`, `code`, `status` FROM `'.$this->_main.'` WHERE ';
        if (ARRAYS::check($exclude_ids)) { $query_begin .= '(`id` NOT IN (\''.implode('\',\'',$exclude_ids).'\')) AND '; }
        $query_end = ' ORDER BY `id` LIMIT 1 OFFSET 0';

        //get one new record
        $query = $query_begin. '((`status`=\'new\') AND (`started_at` IS NULL))'.$query_end;
        $res = $this->_link->query($query);
        if (!empty($res)) { $results['new'] = $res->fetch_assoc(); }
        unset($res);

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
                 ((ARRAYS::check($this->_state_checks, 'rprocessed', 'string')) ? ' AND (`'.ARRAYS::get($this->_state_checks, 'processed').'` IS NOT NULL)' : '').
                 $query_end;
        $res = $this->_link->query($query);
        if (!empty($res)) { $results['processed'] = $res->fetch_assoc(); }
        unset($res);

        foreach ($results as $key => $res_data) {
            if (!ARRAYS::check($res_data)) { unset($results[$key]); }
        }
        if (empty($results)) { return false; }

        $res_idx = array_rand($results);
        $ret = $results[$res_idx];
        unset($results);
        //update selected record -> set status and started_at
        $status = ARRAYS::get($ret, 'status');
        if ((!empty($status))&&(in_array($status, ['new']))) {
            $status = $this->getNextStatus($status);
            $this->update(ARRAYS::get($ret, 'id'), ['status'=>$status, 'started_at'=>gmdate('Y-m-d H:i:s', time())]);
        }

        return $ret;
    }

}