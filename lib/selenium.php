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
use Facebook\WebDriver\WebDriverKeys;

class SELENIUM {

    private $_server = false;
    private $_proxy = false;
    private $_types = [];

    private $_driver = null;
    private $_type = '';

    private $_base_dir = '';
    private $_user_dir = '';
    private $_dl_dir = '';

    private $_session_id = false;
    private $_init_session = false;
    private $_self_signed = false;
    
    public function __construct($conf, $type_key=false, $session_id=false) {

        $this->_types = ARRAYS::get($conf, 'types');
        if (!ARRAYS::check($this->_types)) {
              $this->_types = ['chrome'=>'http://localhost:4444/', 'firefox'=>'http://localhost:4444/'];
        }
        if ((!empty($type_key))&&(!array_key_exists($type_key, $this->_types))) { $type_key = false; }

        $this->_server = ARRAYS::get($this->_types, [$type_key,'server']);
        if (empty($this->_server)) { throw new \Exception('Can not setup Selenium server URL'); }
        $this->_proxy = ARRAYS::get($this->_types, [$type_key,'proxy']);

        $this->_self_signed = (!empty(ARRAYS::get($conf, 'selfsigned'))) ? true : false;
        $base_dir = ARRAYS::get($conf, 'basedir');

        $this->_init_session = false;
        if (!empty($session_id)) { $this->_init_session = $session_id; }

        $type = $this->getTypeCode($type_key);

        $repeat = 2;
        $init = false;
        while ((0 <= $repeat--)&&($init !== true)) {
            $init = $this->initDriver($type, $base_dir);
        }
        if ($init !== true) { throw new \Exception($type.' selenium unable to connect to renderer'); }
    }

    public function __destruct() {
        if (empty($this->_init_session)) {
            $this->close();
        }
    }

    public function close() {
        $this->closeDriver();
    }

    public function getSessionID() {
        return $this->_session_id;
    }

    protected function getTypeCode($type_key = false) {
        $type_idx = array_rand($this->_types);
        $type = $this->_types[$type_idx];
        if ((!empty($type_key))&&(array_key_exists($type_key, $this->_types))) { $type = $type_key; }

        $this->_server = ARRAYS::get($this->_types, [$type,'server']);
        if (empty($this->_server)) { throw new \Exception('Can not setup Selenium server URL fot type: '.$type); }
        $this->_proxy = ARRAYS::get($this->_types, [$type,'proxy']);

        return $type;
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
        $this->_driver = null;

        if ((!empty($this->_user_dir))&&(is_dir($this->_user_dir))) {
            DIRECTORY::delete($this->_user_dir, true, $this->_base_dir, true);
            $this->_user_dir = false;
        }
        usleep(500000);
    }

    protected function getChromeOptions() {
        $options = new ChromeOptions();
        $prefs = ['download.default_directory' => $this->_dl_dir];
        $options->setExperimentalOption('prefs',$prefs);
        unset($prefs);

        $options->addArguments(['--start-maximized']);
        $options->addArguments(['--no-sandbox']);
        $options->addArguments(['--disable-setuid-sandbox']);
        $options->addArguments(['--remote-debugging-port=9222']);
        $options->addArguments(['--disable-dev-shm-using']);
        $options->addArguments(['--disable-gpu']);
        $options->addArguments(['--disable-extensions']);
        $options->addArguments(['start-maximized']);
        $options->addArguments(['disable-infobars']);
        $options->addArguments(['user-data-dir='.$this->_user_dir]);
        $options->addArguments(['applicationCacheEnabled=0']);
        //$options->addArguments(['pageLoadStrategy=0']);

        if (!empty($this->_self_signed)) {
            $options->addArguments(['--ignore-certificate-errors']);
            $options->addArguments(['acceptInsecureCerts=true']);
        }

        $proxy_host = ARRAYS::get($this->_proxy, 'host');
        $proxy_port = ARRAYS::get($this->_proxy, 'port');
        if (empty($proxy_port)) { $proxy_port = 3128; }
        if ((!empty($proxy_host))&&(!empty($proxy_port))) {
            $options->addArguments(['--proxy-server='.$proxy_host.':'.$proxy_port]);
        }

        $desiredCapabilities = DesiredCapabilities::chrome();
        $desiredCapabilities->setCapability(ChromeOptions::CAPABILITY, $options);
        unset($options);
        return $desiredCapabilities;
    }

    protected function getFirefoxOptions() {
        putenv('webdriver.firefox.profile=default');
        //putenv('webdriver.gecko.driver=/usr/bin/geckodriver');

        $profile = new FirefoxProfile();
        $profile->setPreference('webdriver.firefox.profile', 'default');
        //$profile->setPreference('webdriver.gecko.driver', '/usr/bin/geckodriver');

        $profile->setPreference('browser.startup.homepage', 'about:blank');
        $profile->setPreference('browser.download.folderList', 2);
        $profile->setPreference('browser.download.dir', $this->_dl_dir);
        $profile->setPreference('browser.helperApps.neverAsk.saveToDisk', 'application/pdf');

        ////disable caches
        $profile->setPreference('pdfjs.enabledCache.state', false);
        $profile->setPreference('network.http.use-cache', false);
        $profile->setPreference('browser.cache.disk.enable', false);
        $profile->setPreference('browser.cache.offline.enable', false);
        //$profile->setPreference('browser.cache.memory.enable', false);

        $proxy_host = ARRAYS::get($this->_proxy, 'host');
        $proxy_port = ARRAYS::get($this->_proxy, 'port');
        if (empty($proxy_port)) { $proxy_port = 3128; }
        if ((!empty($proxy_host))&&(!empty($proxy_port))) {
            $profile->setPreference('network.proxy.type', 1);
            $profile->setPreference('network.proxy.http'. $proxy_host);
            $profile->setPreference('network.proxy.http_port',$proxy_port);
        }

        $options = new FirefoxOptions();
        //$options->addArguments(['-headless']);
        $options->addArguments(['--remote-debugging-port=9222']);
        $options->addArguments(['--marionette=true']);

        $desiredCapabilities = DesiredCapabilities::firefox();
        if (!empty($this->_self_signed)) {
            //$profile->setPreference('accept_untrusted_certs',true);
            $desiredCapabilities->setCapability('acceptInsecureCerts', true);
            $desiredCapabilities->setCapability('acceptSslCerts', true);
        }
        $desiredCapabilities->setCapability(FirefoxOptions::CAPABILITY, $options);
        unset($options);
        $desiredCapabilities->setCapability(FirefoxDriver::PROFILE, $profile);
        unset($profile);
        return $desiredCapabilities;
    }

    protected function initDriver($type_id, $base_dir = '') {
        try {
            if (empty($this->_init_session)) {
                $this->closeDriver();
            }

            $type = false;
            if (preg_match("/^(firefox|chrome)/", $type_id, $tm)) { $type = ARRAYS::get($tm,1); }
            if (empty($type)) {  throw new \Exception('Unable to found website engine in key: '.$type_id); }

            $this->initBaseDir($base_dir);

            $this->_user_dir = false;

            $this->_dl_dir = $this->_base_dir.'downloads';
            if (!is_dir($this->_dl_dir)) { DIRECTORY::create($this->_dl_dir, true); }

            if (!empty($this->_init_session)) {
                $this->_driver = RemoteWebDriver::createBySessionID($this->_init_session, $this->_server);
                $this->_type = $type;
            }
            else {
                $this->_user_dir = $this->_base_dir.'cookies'.DIRECTORY_SEPARATOR.$type.'.'.microtime(true).mt_rand(100000,150000);
                if (!is_dir($this->_user_dir)) { DIRECTORY::create($this->_user_dir, true); }

                $desiredCapabilities = false;
                switch ($type) {
                    case 'chrome':
                            $this->_type = $type;
                            $desiredCapabilities = $this->getChromeOptions($this->_dl_dir, $this->_user_dir);
                        break;

                    case 'firefox':
                            $this->_type = $type;
                            $desiredCapabilities = $this->getFirefoxOptions($this->_dl_dir, $this->_user_dir);
                        break;

                    default:  break;
                }

                if (empty($desiredCapabilities)) { throw new \Exception('Unknown Selenium engine: '.$type); }

                $this->_driver = RemoteWebDriver::create($this->_server, $desiredCapabilities, 45000);
            }
            if ((!is_object($this->_driver))||(empty($this->_driver->getSessionID()))) {
                throw new \Exception('Unable to create/load Seleniun test environment ('.$type.')');
            }
            $this->_session_id = $this->_driver->getSessionID();
            FILE::debug($this->_session_id.' created ('.$this->_type.') page initialized.', 3);

            if (empty($this->_init_session)) {
                switch ($this->_type) {
                    case 'firefox':
                            $this->_driver->manage()->window()->maximize();
                        break;
                }
                //FILE::debug('new blank page ('.$this->_type.').', 3);
                //$this->_driver->get('about:blank');
            }

            if (!empty($this->_init_session)) { $this->clearCache(); }

            FILE::debug('initializing complete ('.$this->_type.') page initialized.', 3);
        }
        catch (\Exception $ex) {
            $error = $ex->getMessage();
            if (preg_match("/unable to connect to renderer/", $error)) {
                FILE::debug($type.' selenium connection error, retry after 3 sec: '.$error,5);
                sleep(5);
                return 'retry';
            } else {
                FILE::debug($type.' selenium error: '.$error,5);
                $this->closeDriver();
                throw new \Exception('Selenium error: '.$error);
            }
        }
        return true;
    }

    public function clearCache() {
        try {
    //        $cookies = $this->_driver->manage()->getCookies();
    //        var_dump($cookies);
    //        $this->_driver->manage()->deleteAllCookies();
    //        usleep(250000);
            switch ($this->_type) {
                case 'chrome':
                        // $this->_driver->get("chrome://settings/clearBrowserData");
                        // $element = $this->_driver->findElement(WebDriverBy::xpath("//settings-ui"));
                        // if (!empty($element)) { $element->sendKeys(WebDriverKeys::ENTER); }
                    break;

                case 'firefox':
                    break;
            }
        } catch (\Exception $ex) {
            $this->closeDriver();
            throw new \Exception("Selenium cache error: ".$ex->getMessage());
        }
    }

    public function screenshoot($filename=false) {
        usleep(250000);
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
            usleep(250000); //0.25 sec for render page
            $res['title'] = $this->_driver->getTitle();
        }
        catch (\Exception $ex) {
            $timer = $timer_init + microtime(true);
            $res['error'] = 'url error, screenshoot taken: '.$ex->getMessage();
            $this->screenshoot();
        }
        $res['timer'] = $timer;
        return $res;
    }

    public function clickXpath($xpath, $setValue=false, $noError=false) {
        $res = [];
        try {
            $element = $this->_driver->findElement(WebDriverBy::xpath($xpath));
            if ((!isset($element))||(empty($element))) { throw new \Exception('No xpath selected button found in the site!'); }

            usleep(250000); //0.25 sec for wait page
            FILE::debug('Clicking on: '.$xpath, 0);
            $timer_init = 0.0 - microtime(true);
            $element->click();
            if ($noError) {
                $this->_driver->manage()->timeouts()->implicitlyWait(2);
            }
            if (($setValue !== false)&&(!is_array($setValue))) {
                $this->_driver->manage()->timeouts()->implicitlyWait(1.5);
                $this->_driver->getKeyboard()->sendKeys($setValue);
                FILE::debug('Set value: '.$setValue, 0);
            }
            $timer = $timer_init + microtime(true);
            unset($element);
            usleep(250000); //0.25 sec for wait animation

            $res['title'] = $this->_driver->getTitle();
        }
        catch (\Exception $ex) {
            $timer = null;
            if ($noError) { return []; }
            $res['error'] = 'xpath error, screenshoot taken: '.$ex->getMessage();
            $this->screenshoot();
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