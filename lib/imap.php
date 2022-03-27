<?php

namespace Pumuckly\Testing;

class IMAP {

    protected $_link = null;
    protected $_db = null;

    protected $_email = '';
    protected $_sender = '';
    protected $_timer = 0;
    protected $_connect = '';
    protected $_user = '';
    protected $_pass = '';

    protected $_subject = [];
    protected $_subject_keys = [];
    protected $_links = [];

    protected $_waiting_time = 30;
    protected $_process_error = false;

    protected $_jobs = [];

    public function __construct($conf) {
        try {
            if ((!is_array($conf))||(count($conf)<4)) { throw new \Exception('Wrong config for IMAP'); }
            if ((!array_key_exists('host',$conf))||(empty($conf['host']))) { throw new \Exception('Wrong config for IMAP, no host'); }
            if ((!array_key_exists('port',$conf))||(empty($conf['port']))) { throw new \Exception('Wrong config for IMAP, no port'); }
            if ((!array_key_exists('user',$conf))||(empty($conf['user']))) { throw new \Exception('Wrong config for IMAP, no user'); }
            if ((!array_key_exists('pass',$conf))||(empty($conf['pass']))) { throw new \Exception('Wrong config for IMAP, no pass'); }
            if ((!array_key_exists('email',$conf))||(empty($conf['email']))) { throw new \Exception('Wrong config for IMAP, no email address'); }
            if ((!array_key_exists('sender',$conf))||(empty($conf['sender']))) { throw new \Exception('Wrong config for IMAP, no sender address'); }
            if ((!array_key_exists('waiting',$conf))||(empty($conf['waiting']))&&(!is_numeric($conf['waiting']))) { throw new \Exception('Wrong config for IMAP, no waiting time'); }
            if ((!array_key_exists('subject',$conf))||(!ARRAYS::check($conf['subject']))) { throw new \Exception('Wrong config for IMAP, no subject filter'); }
            if ((!array_key_exists('links',$conf))||(!ARRAYS::check($conf['links']))) { throw new \Exception('Wrong config for IMAP, no links filter'); }

            $this->_connect = '{'.$conf['host'].':'.$conf['port'].'/imap/ssl}INBOX';
            $this->_user = $conf['user'];
            $this->_pass = $conf['pass'];
            $this->_email = $conf['email'];
            $this->_sender = $conf['sender'];
            $this->_subject = $conf['subject'];
            $this->_links = $conf['links'];
            $this->_waiting_time = $conf['waiting'];

            $this->_timer = time()-3600; //time in the past for first run
            $this->_jobs = [];

            $this->_subject_keys = [];
            if (ARRAYS::check($this->_subject)) {
                foreach ($this->_subject as $key => $sdata) {
                    $subject = ARRAYS::get($sdata, 'title');
                    if (empty($subject)) { continue; }

                    if (!is_array($subject)) {
                        $this->_subject_keys[$subject] = ['key'=>$key, 'field'=>false, 'value'=>false];
                    } else {
                        foreach ($subject as $title) {
                            $this->_subject_keys[$title] = ['key'=>$key, 'field'=>false, 'value'=>false];
                        }
                    }
                }
            }
        }
        catch (\Exception $ex) {
            throw new \Exception('Unable to init email. Error: '.$ex->getMessage());
        }
    }

    public function __destruct() {
        $this->close();
    }

    protected function isCon($isDie=true) {
        try {
            if ((empty($this->_link))||(!is_resource($this->_link))) {
                if ($isDie) {
                    if ((empty($this->_connect))||(empty($this->_user))||(empty($this->_pass))) { throw new \Exception('Mail configuration error'); }
                    $this->_link = imap_open($this->_connect, $this->_user, $this->_pass);
                    if (!is_resource($this->_link)) { throw new \Exception('Could not connect to Gmail: ' . imap_last_error()); }
                    return true;
                }
                return false;
            }
        } catch (\Exception $ex) {
            $last_error = imap_last_error().' / '.$ex->getMessage();
            FILE::debug('IMAP connection error: '.$last_error,5);
            $errors = imap_errors();
            FILE::debug($errors,5);
            $errors = imap_alerts();
            FILE::debug($errors,5);
            throw new \Exception('IMAP Error: '.$last_error);
        }
        return true;
    }

    protected function close() {
       if ($this->isCon(false)) {
            try { imap_close($this->_link); }
            catch (\Exception $ex) {}
        }
        unset($this->_link);
        $this->_link = false;
        return $this;
    }

    public function getEmail($key = false) {
        $res = $this->_email;
        if (!empty($key)) { $res = str_replace('@','+'.$key.'@',$res); }
        return $res;
    }

    protected function getDB() {
        if (is_null($this->_db)) {
            $this->_db = DB::getInstance();
        }
        return $this->_db;
    }

    public function setJobs($jobs) {
        $this->_jobs = $jobs;

        if (ARRAYS::check($this->_subject_keys)) {
            foreach ($this->_subject_keys as $subject => $code) {
                $subject_key = ARRAYS::get($code, 'key');
                if (empty($subject_key)) { continue; }

                $db_field = false;
                $db_value = false;
                foreach ($this->_jobs as $key => $data) {
                    $fkey = ARRAYS::get($data, 'method');
                    if ($fkey != $subject_key) { continue; }
                    $db_field = $key;
                    $value_key = ARRAYS::get($data, 'data');
                    if ((!empty($value_key))&&(!ARRAYS::check($value_key))) { $db_value = $key.$value_key; }
                    break;
                }
                $this->_subject_keys[$subject]['field'] = $db_field;
                $this->_subject_keys[$subject]['value'] = $db_value;
            }
        }
        return $this;
    }

    public function getCodeFromEmail($email) {
        $code = '';
        $src = $this->_email;
        $start = 0;
        $start_code = false;
        while (strlen($email)>0) {
            $ch = substr($email,0,1);
            $och = substr($src,0,1);
            $email = substr($email,1);
            if ($ch == $och) { $start++; $src = substr($src,1); }
            elseif (($start>0)&&($ch == '+')) { $start_code = true; }
            elseif ($start_code) { $code .= $ch; }
        }
        if ((!empty($email))||(!empty($src))) { $code = false; }
        return $code;
    }

    public function process() {
        $waits = $this->_waiting_time - (time() - $this->_timer);
        if ($waits > 0) {
            FILE::debug('Email processing done. Waiting for '.$waits.' sec for check nexts',1);
            sleep($waits);
        }
        $this->_timer = time();
        FILE::debug('Checking emails...',4);
        try {
            $this->check();
            $this->close();
            $this->_timer = time();
        }
        catch (\Exception $ex) {
            FILE::debug('IMAP Error: '.$ex->getMessage(),5);
        }
    }

    public function check() {
        if (!$this->isCon()) { return false; }
        $this->_process_error = false;
        $changed = false;
        try {
            $emails = imap_search($this->_link, 'UNSEEN FROM "'.$this->_sender.'"');
            $lerror = imap_last_error();
            if (!empty($lerror)) { throw new \Exception('IMAP error: '.$lerror); }
            if (empty($emails)) { return false; }

            rsort($emails);
            if (count($emails) > 25) { FILE::debug('Found more than 25 emails. Total: '.count($emails),3); }

            foreach ($emails as $mail_id) {
                $overview = imap_fetch_overview($this->_link, $mail_id, 0);
                if ((!is_array($overview))||(!array_key_exists(0, $overview))) { continue; }

                $from = $to = $subject = '';
                $_from = imap_mime_header_decode($overview[0]->from);
                foreach ($_from as $obj) { $from .= $obj->text; }
                unset($_form);
                $_to = imap_mime_header_decode($overview[0]->to);
                foreach ($_to as $obj) { $to .= $obj->text; }
                unset($_to);
                $_subject = imap_mime_header_decode($overview[0]->subject);
                foreach ($_subject as $obj) { $subject .= $obj->text; }
                unset($_subject);

                $subject_key = ARRAYS::get($this->_subject_keys, [$subject, 'key']);
                if (empty($subject_key)) { continue; }

                $db_field = ARRAYS::get($this->_subject_keys, [$subject, 'field']);
                $db_value = ARRAYS::get($this->_subject_keys, [$subject, 'value']);
                if (empty($db_field)) { continue; }
                if (empty($db_value)) { $db_value = false; }

                $code = $this->getCodeFromEmail($to);
                $time = gmdate('Y-m-d H:i:s', $overview[0]->udate);

                $message = imap_fetchbody($this->_link, $mail_id,1);
                if ($message) { $message = quoted_printable_decode($message); }

                $link = false;
                if (ARRAYS::check($this->_links, $subject_key)) {
                    $link = '';
                    if (preg_match_all("/<a\s+[^>]+>/is", $message, $res, PREG_SET_ORDER)) {
                        foreach ($res as $links) {
                            $found = 0;
                            foreach ($this->_links[$subject_key] as $attr => $regex) {
                                //TODO: check attribute name too not just value only
                                if (preg_match("/".str_replace("/","\/", $regex)."/is", $links[0])) { $found++; }
                            }
                            if ($found == count($this->_links[$subject_key])) {
                                //TODO: check ' too not only "
                                if (preg_match("/href=\"([^\"]+)\"/", $links[0], $rm)) {
                                    $link = $rm[1];
                                }
                            }
                        }
                    }
                }

                $values = [$db_field=>$time];
                if (!empty($db_value)) { $values[$db_value] = $link; }

                $status = ARRAYS::get($this->_subject, [$subject_key, 'status']);
                $status_check = $status_false = false;
                if (ARRAYS::check($status)) {
                    $status_check = ARRAYS::get($status, 'check');
                    $status_false = ARRAYS::get($status, 'false');
                    $status = ARRAYS::get($status, 'true');
                    if (empty($status_check)) { $status_check = false; }
                    if (empty($status_false)) { $status_false = false; }
                }
                if (empty($status)) { $status = ''; }

                $dbdata = $this->getDB()->getByCode($code, $status_check);
                $db_id = ARRAYS::get($dbdata, 'id');
                if (empty($db_id)) { break; }

                if ($status_check) {
                    $db_field =  ARRAYS::get($dbdata, $status_check);
                    if (empty($db_field)) { $status = $status_false; }
                }

                $db_status = ARRAYS::get($dbdata, 'status');
                if ((!empty($status))&&($db_status == $status)) { $status = false; }
                if (!empty($status)) { $values['status'] = $status; }

                $this->getDB()->update($db_id, $values);
                unset($status);
                unset($values);

                imap_mail_move($this->_link, $mail_id, 'processed');
                $changed = true;
                FILE::debug('email processed: from: '.$from.'; to: '.$to.'; subject: '.$subject_key.'; code: '.$code.'; arrived: '.$time.'; link: '.$link, 0);
                print 'email processed: from: '.$from.'; to: '.$to.'; subject: '.$subject_key.'; code: '.$code.'; arrived: '.$time.'; link: '.$link."\n";
            }
        }
        catch (\Exception $ex) {
            $this->_process_error = $ex->getMessage();
            FILE::debug('Mail processing error: '.$this->_process_error,5);
            if (preg_match("/IMAP connection lost/", $this->_process_error)) {
                $this->close();
            } else {
                print $this->_process_error."\n";
            }
        }

        try {
          if (($changed)&&(!empty($this->_link))) { imap_expunge($this->_link); }
        }
        catch (\Exception $ex) {
            $this->_process_error = $ex->getMessage();
            FILE::debug('Mail processing error: '.$this->_process_error,5);
        }
    }

    public function searchKey($key, $subject_filter) {
        if (!$this->isCon()) { return false; }
        if (empty($key)) { return false; }
        if (empty($subject_filter)) { return false; }
        $email = $this->getEmail($key);

        $emails = imap_search($this->_link, 'UNSEEN FROM "'.$this->_sender.'" TO "'.$key.'"');
        if (empty($emails)) { return false; }
        foreach ($emails as $mail_id) {
            $overview = imap_fetch_overview($this->_link,$mail_id,0);
            if ((!is_array($overview))||(!array_key_exists(0, $overview))) { continue; }
            $from = $to = $subject = '';
            //$message = imap_fetchbody($this->_link,$mail_id,2);
            $_from = imap_mime_header_decode($overview[0]->from);
            foreach ($_from as $obj) { $from .= $obj->text; }
            $_to = imap_mime_header_decode($overview[0]->to);
            foreach ($_to as $obj) { $to .= $obj->text; }
            $_subject = imap_mime_header_decode($overview[0]->subject);
            foreach ($_subject as $obj) { $subject .= $obj->text; }

            if ($subject_filter !== $subject) { continue; }

            print $subject."\n";
            print $from."\n";
            print $to."\n";
            print $overview[0]->date."\n";
            print $overview[0]->message_id."\n";
            print $overview[0]->size."\n";
            print $overview[0]->seen."\n";
            print gmdate('Y-m-d H:i:s', $overview[0]->udate)."\n";
            print "\n";
        }
    }

}