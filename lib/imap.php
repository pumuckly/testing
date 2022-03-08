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
    protected $_links = [];

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
            if ((!array_key_exists('subject',$conf))||(!ARRAYS::check($conf['subject']))) { throw new \Exception('Wrong config for IMAP, no subject filter'); }
            if ((!array_key_exists('links',$conf))||(!ARRAYS::check($conf['links']))) { throw new \Exception('Wrong config for IMAP, no links filter'); }

            $this->_connect = '{'.$conf['host'].':'.$conf['port'].'/imap/ssl}INBOX';
            $this->_user = $conf['user'];
            $this->_pass = $conf['pass'];
            $this->_email = $conf['email'];
            $this->_sender = $conf['sender'];
            $this->_subject = $conf['subject'];
            $this->_links = $conf['links'];
            $this->_timer = time()-3600; //time in the past for first run
            $this->_jobs = [];
        }
        catch (\Exception $ex) {
            throw new \Exception('Unable to init email. Error: '.$ex->getMessage());
        }
    }

    public function __destruct() {
        $this->close();
    }

    protected function isCon($isDie=true) {
        if ((empty($this->_link))||(!is_resource($this->_link))) {
            if ($isDie) {
                if ((empty($this->_connect))||(empty($this->_user))||(empty($this->_pass))) { throw new \Exception('Mail configuration error'); }
                $this->_link = imap_open($this->_connect, $this->_user, $this->_pass);
                if (!is_resource($this->_link)) { throw new \Exception('Could not connect to Gmail: ' . imap_last_error()); }
                return true;
            }
            return false;
        }
        return true;
    }

    protected function close() {
       if ($this->isCon(false)) {
            imap_close($this->_link);
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
        if ((time() - $this->_timer) < 60) { return false; }
        $this->_timer = time();
        FILE::debug('Checking emails...',2);
        $this->check();
        $this->close();
    }

    public function check() {
        if (!$this->isCon()) { return false; }
        $emails = imap_search($this->_link, 'UNSEEN FROM "'.$this->_sender.'"');
        $error = imap_last_error();
        if (!empty($error)) { throw new \Exception('IMAP error: '.$error); }
        if (empty($emails)) { return false; }
        rsort($emails);
        $changed = false;
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

            $subject_key = ARRAYS::firstIn($this->_subject, $subject, 'title');
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
            if (empty($db_field)) { continue; }

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
        }
        if ($changed) { imap_expunge($this->_link); }
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