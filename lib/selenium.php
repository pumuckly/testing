<?php

namespace Pumuckly\Testing;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\WebDriverCapabilityType;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Firefox\FirefoxDriver;
use Facebook\WebDriver\Firefox\FirefoxOptions;
use Facebook\WebDriver\Firefox\FirefoxProfile;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

class SELENIUM {

    private $_server = false;
    private $_types = [];

    private $_driver = null;
    private $_type = '';

    private $_base_dir = '';
    private $_user_dir = '';
    private $_dl_dir = '';
    
    public function __construct($conf) {
        $this->_server = ARRAYS::get($conf, 'server');
        if (empty($this->_server)) { throw new \Exception('Can not setup Selenium server URL'); }
        $this->_types = ARRAYS::get($conf, 'types');
        if (!ARRAYS::check($this->_types)) { $this->_types = ['chrome']; }

        $self_signed = (!empty(ARRAYS::get($conf, 'selfsigned'))) ? true : false;
        $type_idx = array_rand($this->_types);
        $base_dir = ARRAYS::get($conf, 'basedir');

        $this->initDriver($this->_types[$type_idx], $self_signed, $base_dir);
    }

    public function __destruct() {
        $this->close();
    }

    public function close() {
        $this->closeDriver();
    }

    protected function initBaseDir($base_dir) {
        if (empty($base_dir)) {
            $base_dir = DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'selenium';
        }
        $this->_base_dir = preg_replace("/".preg_quote(DIRECTORY_SEPARATOR,"/")."\$/is", '', $base_dir);
        if ((!empty($this->_base_dir))&&(!is_dir($this->_base_dir))) {
            DIRECTORY::create($this->_base_dir, true);
        }
        if (!preg_match("/".preg_quote(DIRECTORY_SEPARATOR,"/")."\$/is", $this->_base_dir)) { $this->_base_dir .= DIRECTORY_SEPARATOR; }
        return $this;
    }

    protected function closeDriver() {
        if ((isset($this->_driver))&&(is_object($this->_driver))) {
            $this->_driver->quit();
            unset($this->_driver);
        }
        if ((!empty($this->_dl_dir))&&(is_dir($this->_dl_dir))) {
            //TODO: remove completely
        }
        if ((!empty($this->_user_dir))&&(is_dir($this->_user_dir))) {
            //TODO: remove completely
        }
        $this->_driver = null;

        if ((!empty($this->_user_dir))&&(is_dir($this->_user_dir))) {
            DIRECTORY::delete($this->_user_dir, true, $this->_base_dir, true);
            $this->_user_dir = false;
        }
    }

    protected function initDriver($type, $self_signed = false, $base_dir = '') {
        try {
            $this->closeDriver();

            $this->initBaseDir($base_dir);

            $this->_dl_dir = $this->_base_dir.'downloads';
            $this->_user_dir = $this->_base_dir.'cookies'.DIRECTORY_SEPARATOR.$type.'.'.microtime(true).mt_rand(100000,150000);
            if (!is_dir($this->_dl_dir)) {
                DIRECTORY::create($this->_dl_dir, true);
            }
            if (!is_dir($this->_user_dir)) {
                DIRECTORY::create($this->_user_dir, true);
            }

            $desiredCapabilities = false;
            switch ($type) {
                case 'chrome':
                        $this->_type = $type;

                        $options = new ChromeOptions();
                        $prefs = [
                            'download.default_directory' => $this->_dl_dir,
                        ];
                        $options->setExperimentalOption('prefs',$prefs);
                        unset($prefs);

                        $options->addArguments(['--start-maximized']);
                        $options->addArguments(['--no-sandbox']);
                        $options->addArguments(['--disable-setuid-sandbox']);
                        $options->addArguments(['--remote-debugging-port=9222']);
                        $options->addArguments(['--disable-dev-shm-using']);
                        $options->addArguments(['--disable-extensions']);
                        $options->addArguments(['--disable-gpu']);
                        $options->addArguments(['start-maximized']);
                        $options->addArguments(['disable-infobars']);
                        $options->addArguments(['user-data-dir='.$this->_user_dir]);
                        $options->addArguments(['applicationCacheEnabled=0']);

                        if (!empty($self_signed)) {
                            $options->addArguments(['--ignore-certificate-errors']);
                            $options->addArguments(['acceptInsecureCerts=true']);
                        }

                        $desiredCapabilities = DesiredCapabilities::chrome();
                        $desiredCapabilities->setCapability(ChromeOptions::CAPABILITY, $options);
                        unset($options);
                    break;

                case 'firefox':
                        $this->_type = $type;

                        $profile = new FirefoxProfile();
                        $profile->setPreference('browser.download.folderList', 2);
                        $profile->setPreference('browser.download.dir', $this->_dl_dir);
                        $profile->setPreference('browser.helperApps.neverAsk.saveToDisk', 'application/pdf');
                        $profile->setPreference('pdfjs.enabledCache.state', false);

                        if (!empty($self_signed)) {
                            $profile->setPreference('accept_untrusted_certs',true);
                        }

                        $options = new FirefoxOptions();
                        //$options->addArguments(['-headless']);
                        $options->addArguments(["--remote-debugging-port=9222"]);

                        $desiredCapabilities = DesiredCapabilities::firefox();
                        //$desiredCapabilities->setCapability('acceptSslCerts', false);
                        $desiredCapabilities->setCapability(FirefoxDriver::PROFILE, $profile);
                        unset($profile);
                        $desiredCapabilities->setCapability(FirefoxOptions::CAPABILITY, $options);
                        unset($options);
                    break;

                default:  break;
            }

            if (empty($desiredCapabilities)) { throw new \Exception('Unknown Selenium engine: '.$type); }

            $this->_driver = RemoteWebDriver::create($this->_server, $desiredCapabilities);

            if ((!is_object($this->_driver))||(empty($this->_driver->getSessionID()))) { 
                throw new \Exception('Unable to create Seleniun test environment ('.$type.')');
            }
        }
        catch (\Exception $ex) {
            $this->closeDriver();
            throw new \Exception('Selenium error: '.$ex->getMessage());
        }
    }

    public function screenshoot($filename=false) {
        if (empty($filename)) { $filename = 'screenshoot_'.microtime(true).mt_rand(10000,99999).'.png'; }
        $fname = DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.$filename;
        $this->_driver->takeScreenshot($fname);
        @chmod($fname, 02666);
        return $fname;
    }

    public function download($xpath, $on_error=false) {
        $res = [];
        $url = '';
        try {
            $timer_init = 0.0 - microtime(true);
            $url = false;
            if (empty($xpath)) { throw new \Exception('No xpath for file download'); }

            $do_catch = false;
            try {
                $element = $this->_driver->findElement(WebDriverBy::xpath($xpath));
                if ((!isset($element))||(empty($element))) { throw new \Exception('No A node found by xpath!'); }
                $url = $element->getAttribute('href');
                unset($element);
            }
            catch (\Exception $ex) {
                $err_msg = $ex->getMessage();
                if (preg_match("/no such element:.+/is",$err_msg)) { $do_catch = $ex->getMessage(); }
                else { throw new \Exception($err_msg); }
            }

            if ((!$url)&&($do_catch)&&(ARRAYS::check($on_error))) {
                $have_error = false;
                foreach ($on_error as $err_key => $error) {
                    try {
                        $xpath = ARRAYS::get($error, 'xpath');
                        $xval = ARRAYS::get($error, 'value');
                        if ((empty($xpath))||(empty($xval))) { continue; }
                        $element = $this->_driver->findElement(WebDriverBy::xpath($xpath));
                        if ((!isset($element))||(empty($element))) { throw new \Exception('Node not found!'); }
                        $node_val = $element->getText();
                        if (strpos($node_val, $xval)!==false) {
                            $res['handled_error'] = $err_key;
                            $url = ARRAYS::get($error, ['handle','download']);
                            break;
                        }
                    }
                    catch (\Exception $ex) { $have_error = $ex->getMessage(); }
                }
                if (!empty($have_error)) { throw new \Exception("Last: ".$have_error); }
            }

            if (empty($url)) { throw new \Exception('No URL specified!'); }
            $res['title'] = $url;

            FILE::debug('Call URL: '.$url, 0);
            $filename = 'dl.'.microtime(true).mt_rand(100000,200000).'.tmp';
            $timer_init = 0.0 - microtime(true);
            HTTP::getOtherSiteContent($url, false, ['exception'=>true,'binary'=>true,'saveas'=>$filename], 90);
            $timer = $timer_init + microtime(true);
            FILE::debug('File downloaded in '.$timer, 0);

            $http_timer = HTTP::getRunTime($url);
            if ($http_timer > 0) { $timer = $http_timer; }
            FILE::debug('File download time corrected to '.$timer, 0);

            $file = HTTP::getSavedFileName($filename);
            FILE::debug('Downloaded into file: '.$file, 0);
            if (is_file($file)) {
                $res['filesize'] = filesize($file);
                FILE::debug('File downloaded. Size: '.$res['filesize'], 0);
                unlink($file); 
            }
        }
        catch (\Exception $ex) {
            $timer = $timer_init + microtime(true);
            $res['error'] = $ex->getMessage(); 
        }
        $res['timer'] = $timer;

        return $res;
    }

    public function getUrl($url) {
        $res = [];
        try {
            $timer_init = 0.0 - microtime(true);
            if (empty($url)) { throw new \Exception('No URL specified!'); }

            FILE::debug('Call URL: '.$url, 0);
            $timer_init = 0.0 - microtime(true);
            $this->_driver->get($url);
            $timer = $timer_init + microtime(true);
            $res['title'] = $this->_driver->getTitle();
        }
        catch (\Exception $ex) {
            $timer = $timer_init + microtime(true);
            $res['error'] = $ex->getMessage(); 
        }
        $res['timer'] = $timer;
        return $res;
    }

    public function clickXpath($xpath, $setValue=false, $noError=false) {
        $res = [];
        try {
            $element = $this->_driver->findElement(WebDriverBy::xpath($xpath));
            if ((!isset($element))||(empty($element))) { throw new \Exception('No xpath selected button found in the site!'); }

            FILE::debug('Clicking on: '.$xpath, 0);
            $timer_init = 0.0 - microtime(true);
            $element->click();
            if ($noError) {
                $this->_driver->manage()->timeouts()->implicitlyWait(2);
            }
            if (($setValue !== false)&&(!is_array($setValue))) {
                $this->_driver->manage()->timeouts()->implicitlyWait(1);
                $this->_driver->getKeyboard()->sendKeys($setValue);
                FILE::debug('Set value: '.$setValue, 0);
            }
            $timer = $timer_init + microtime(true);
            unset($element);

            $res['title'] = $this->_driver->getTitle();
        }
        catch (\Exception $ex) {
            $timer = null;
            if ($noError) { return []; }
            $res['error'] = $ex->getMessage(); 
        }
        $res['timer'] = $timer;
        return $res;
    }

    public function wait($xpath, $sleep = 1) {
        $driver = &$this->_driver;
        $this->_driver->wait(10,250)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::xpath($xpath))
        );
        $elements = $driver->findElements(WebDriverBy::xpath($xpath));
        if (count($elements) > 0) { usleep($sleep*1000000); }
        unset($elements);
    }

}