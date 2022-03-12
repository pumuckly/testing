<?php

namespace Pumuckly\Testing;

class PCURL {

    protected $useragent = false;
    protected $threads_max = 8;

    protected $_threads = [];
    protected $_results = [];
    protected $_links = [];

    public function __construct($conf) {
        $this->useragent = ARRAYS::get($conf, 'useragent');
        if (empty($this->useragent)) { $this->useragent = false; }

        $max_threads = ARRAYS::get($conf, 'links-threads');
        if ($max_threads > 0) { $this->threads_max = $max_threads; }

        $this->_threads = [];
        $this->_results = [];
        $this->_links = [];
    }

    public function __destruct() {
        $this->close();
    }

    public function close() {
        $this->clearThreads();
        unset($this->_results);
        unset($this->_links);
        $this->_results = [];
        $this->_links = [];
    }

    public function getUrl($url, $exclude=[]) {
        $res = [];
        try {
            $params = ['exception'=>true];
            if (!empty($this->useragent)) {
                $params['useragent'] = $this->useragent;
            }
            if (substr($url,-1,1) === '/') { $url = substr($url,0,strlen($url)-1); }
            $method = 'http'.((strpos($url, 'https')===0)?'s':'').':';

            $res['base'] = $url;
            $res['links'] = [];
            $res['data'] = ['start'=>microtime(true),'length'=>0,'counts'=>0];

            $content = HTTP::getOtherSiteContent($url, false, $params, 20);
            $res['timer'] = HTTP::getRunTime($url);
            $res['data']['length'] = strlen($content);

            $enabled_links = ['stylesheet','icon','manifest'];

            $p = preg_match_all("/<(script|img|link)\s+([^>]+)>/is", $content, $out, PREG_SET_ORDER);
            if (($p !== false)&&(count($out)>0)) {
                foreach ($out as $r) {
                    $key = strtolower($r[1]);
                    $link = '';
                    if ($key == 'link') {
                        $rel = '';
                        if (preg_match("/rel=[\"]([^\"]+)\"/is",$r[2],$rm)) { $rel = strtolower(trim($rm[1])); }
                        elseif (preg_match("/rel=[']([^']+)'/is",$r[2],$rm)) { $rel = strtolower(trim($rm[1])); }
                        if (in_array($rel,$enabled_links)) {
                            $key = $rel;
                            if (preg_match("/href=\"([^\"]+)\"/is",$r[2],$rm)) { $link = trim($rm[1]); }
                            elseif (preg_match("/href='([^']+)'/is",$r[2],$rm)) { $link = trim($rm[1]); }
                        }
                    } else {
                        if (preg_match("/src=\"([^\"]+)\"/is",$r[2],$rm)) { $link = trim($rm[1]); }
                        elseif (preg_match("/src='([^']+)'/is",$r[2],$rm)) { $link = trim($rm[1]); }
                    }
                    if (!empty($link)) {
                        if (substr($link,0,1)=='/') {
                            if (substr($link,1,1)=='/') { $link = $method.$link; }
                            else { $link = $url.$link; }
                        }
                        elseif (substr($link,0,5) == 'data:') { $link = false; }
                        elseif (substr($link,0,5) == 'http:') { /* http link */ }
                        elseif (substr($link,0,6) == 'https:') { /* https link */ }
                        else { $link = $url.'/'.$link; }
                    }

                    if ((!empty($link))&&(ARRAYS::check($exclude))) {
                        foreach ($exclude as $blocked) {
                            if (strpos(strtolower($link), strtolower($blocked))!==false) { $link = false; break; }
                        }
                    }

                    if ((!empty($link))&&(strpos($link,'http')===0)) {
                        if (!array_key_exists($link, $res['links'])) { $res['links'][$link] = $key; }
                    }
                }
            }
            unset($content);
            $res['data']['counts'] = count($res['links']);
        }
        catch (\Exception $ex) {
            $res['error'] = $ex->getMessage();
        }
        return $res;
    }

    protected function getResultsLength() {
        $res = 0;
        if (!ARRAYS::check($this->_results)) { return $res; }
        foreach ($this->_results as &$result) {
            $length = ARRAYS::get($result, 'length');
            if ((!empty($length))&&($length > 0)) { $res += $length; }
        }
        return $res;
    }

    protected function getResultsTiming() {
        $res = 0.0;
        if (!ARRAYS::check($this->_results)) { return $res; }
        foreach ($this->_results as &$result) {
            $timing = ARRAYS::get($result, 'timing');
            if ((!empty($timing))&&($timing > $res)) { $res = $timing; }
        }
        return $res;
    }

    protected function getResultsCount() {
        $res = 0;
        if (!ARRAYS::check($this->_results)) { return $res; }
        foreach ($this->_results as &$result) {
            $timing = ARRAYS::get($result, 'timing');
            $length = ARRAYS::get($result, 'length');
            if ((!empty($timing))&&(!empty($length))&&($timing > 0)&&($length>0)) { $res++; }
        }
        return $res;
    }

    protected function getResultsErrors() {
        $res = 0;
        if (!ARRAYS::check($this->_results)) { return $res; }
        foreach ($this->_results as &$result) {
            $errors = ARRAYS::get($result, 'errors');
            if ($errors===true) { $res++; }
        }
        return $res;
    }

    protected function haveLink() {
        if (count($this->_links)==0) { return false; }
        foreach ($this->_links as $link => $data) {
            $status = ARRAYS::get($data, 'status');
            if ($status === false) { return $link; }
        }
        return false;
    }

    public function getLinks(&$links, $base='', $max_repeat=1) {
        $res = [];
        try {
            $this->_results = [];
            $this->_links = [];
            $this->clearThreads();

            $start = microtime(true);
            $timer = 0.0 - $start;

            if (count($links)>0) {
                  foreach ($links as $link => $type) {
                      if (!array_key_exists($link, $this->_links)) {
                          $this->_links[$link] = ['type'=>$type, 'status'=>false];
                      }
                  }
            }
            while ($link = $this->haveLink()) {
                $this->_links[$link]['status'] = 1;
                $type = ARRAYS::get($this->_links, [$link, 'type']);
                $this->setLinkThread($link, $type, $max_repeat);
            }
            $have_thread = $this->waitThreads();
            $this->clearThreads();

            $timer += microtime(true);

            $res['timer'] = $timer;
            $res['data'] = [
                  'start'=>$start,
                  'length'=>$this->getResultsLength(),
                  'maxtime'=>$this->getResultsTiming(),
                  'counts'=>$this->getResultsCount(),
                  'errors'=>$this->getResultsErrors(),
            ];
        }
        catch (\Exception $ex) { $res['error'] = $ex->getMessage(); }
        return $res;
    }

    protected function clearThreads() {
        if (count($this->_threads)==0) { return $this; }
        foreach ($this->_threads as $id => &$thread) {
            if (($thread['future']!==false)&&(is_object($thread['future']))) { $thread['runtime']->kill(); }
            unset($this->_threads[$id]);
        }
        return $this;
    }

    protected function nextThread() {
        $max = 0;
        foreach ($this->_threads as $id => &$thread) { if ($id>$max) { $max = $id; } }
        return $max+1;
    }

    protected function setResult($result) {
        $url = ARRAYS::get($result, 'links');
        if (array_key_exists($url, $this->_links)) {
            $this->_links[$url]['status'] = 2;
        }

        $links = ARRAYS::get($result, 'links');
        if (ARRAYS::check($links)) {
            foreach ($links as $link) {
                if (!array_key_exists($link, $this->_links)) {
                    $this->_links[$link] = ['type'=>'postprocess', 'status'=>false];
                }
            }
            unset($result['links']);
        }
        $this->_results[] = $result;
    }

    protected function waitThreads($limit = 0, $timeout = 60) {
        if (count($this->_threads) >= $limit) {
            $watchdog = 1000000*$timeout; //default 30 secound
            while ((count($this->_threads)>0)&&(count($this->_threads) >= $limit)&&($watchdog>0)) {
                $ts = 0.0-microtime(true);
                foreach ($this->_threads as $i => &$thread) {
                    if ($thread['future'] === false) {
                        unset($this->_threads[$i]);
                        continue;
                    }
                    if ($thread['future']->done()) {
                        $this->setResult($thread['future']->value());
                        unset($thread['future']);
                        unset($thread['runtime']);
                        unset($this->_threads[$i]);
                        continue;
                    }
                }
                $ts += microtime(true);
                $watchdog-=$ts;
            }
            if ($watchdog <= 0) { throw new \Exception('Terminated processing: Waiting for free thread node not run in time (within 30 seconds.'); }
        }
        return count($this->_threads);
    }

    protected function setLinkThread($link, $type, $max_repeat=1) {
        //wait for freeing a thread
        $this->waitThreads($this->threads_max);

        $i = $this->nextThread();
        $params = ['exception'=>true];
        if (!empty($this->useragent)) { $params['useragent'] = $this->useragent; }

        $callfunc = function() {
              include_once('vendor/pumuckly/testing/autoload.php');
              return call_user_func_array('Pumuckly\\Testing\\HTTP::getUrlStat', func_get_args());
        };

        $this->_threads[$i] = ['runtime' => new \parallel\Runtime(), 'future'=>false];
        $this->_threads[$i]['future'] = 
              $this->_threads[$i]['runtime']->run($callfunc, [
                    'thread'=>['id'=>$i, 'parent'=>getmypid(), 'log'=>FILE::logGetParam()], 
                    'data'=>['url'=>$link, 'type'=>$type, 'max_repeat'=>$max_repeat, 'params'=>$params]
              ]);
    }

}