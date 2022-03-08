<?php

namespace Pumuckly\Testing;

class TEST {

    protected $_config = [];

    public function __construct($cfg_file) {
        $this->_config = FILE::parseFileJSON($cfg_file);
        if (!is_array($this->_config)) { throw new \Exception('Wrong configuration file!'); }
        if ((!array_key_exists('db',$this->_config))||(!is_array($this->_config['db']))||(count($this->_config['db'])<4)) { throw new \Exception('No database configuration!'); }
        if ((!array_key_exists('imap',$this->_config))||(!is_array($this->_config['imap']))||(count($this->_config['imap'])<4)) { throw new \Exception('No mail configuration!'); }
        if ((!array_key_exists('job',$this->_config))||(!is_array($this->_config['job']))||(count($this->_config['job'])<4)) { throw new \Exception('No jobs configuration!'); }
        if ((!array_key_exists('steps',$this->_config['job']))||(!is_array($this->_config['job']['steps']))||(count($this->_config['job']['steps'])==0)) { throw new \Exception('No steps  configuration!'); }

        $this->_config['steps'] = $this->_config['job']['steps'];
        unset($this->_config['job']['steps']);
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

    public function start() {
        $fork = new FORK($this->_config);
    }

}