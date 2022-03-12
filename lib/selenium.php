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

class SELENIUM {

    private $_server = false;
    private $_types = [];

    private $_driver = null;
    private $_type = '';

    private $_user_dir = '';
    private $_dl_dir = '';
    
    public function __construct($conf) {
        $this->_server = ARRAYS::get($conf, 'server');
        if (empty($this->_server)) { throw new \Exception('Can not setup Selenium server URL'); }
        $this->_types = ARRAYS::get($conf, 'types');
        if (!ARRAYS::check($this->_types)) { $this->_types = ['chrome']; }

        $self_signed = (!empty(ARRAYS::get($conf, 'selfsigned'))) ? true : false;
        $type_idx = array_rand($this->_types);

        $this->initDriver($this->_types[$type_idx], $self_signed);
    }

    public function __destruct() {
        $this->close();
    }

    public function close() {
        $this->closeDriver();
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
    }

    protected function initDriver($type, $self_signed = false) {
        try {
            $this->closeDriver();

            $this->_dl_dir = '/tmp/selenium/downloads';
            $this->_user_dir = '/tmp/selenium/cookies/'.$type.'.'.microtime(true).mt_rand(100000,150000);

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

    public function screenshoot() {
        $fname = DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'screenshoot_'.microtime(true).mt_rand(10000,99999).'.png';
        $this->_driver->takeScreenshot($fname);
        @chmod($fname, 02666);
        return $fname;
    }

    public function download($xpath, $submit) {
        $res = [];
        try {
            $timer_init = 0.0 - microtime(true);
            $url = false;
            if (empty($xpath)) { throw new \Exception('No xpath for file download'); }

            $element = $this->_driver->findElement(WebDriverBy::xpath($xpath));
            if ((!isset($element))||(empty($element))) { throw new \Exception('No A node found by xpath!'); }
            $url = $element->getAttribute('href');
            unset($element);
FILE::debug('Call DL URL: '.$url, 5);

            if (empty($url)) { throw new \Exception('No URL specified!'); }

            FILE::debug('Call URL: '.$url, 0);
            $filename = 'dl.'.microtime(true).mt_rand(100000,200000).'.tmp';
            $timer_init = 0.0 - microtime(true);
            HTTP::getOtherSiteContent($url, false, ['exception'=>true,'binary'=>true,'saveas'=>$filename], 30);
            $timer = $timer_init + microtime(true);

            $http_timer = HTTP::getRunTime($url);
            if ($http_timer > 0) { $timer = $http_timer; }

            $file = HTTP::getSavedFileName($filename);
            if (is_file($file)) {
                $res['filesize'] = filesize($file);
                unlink($file); 
            }
        }
        catch (\Exception $ex) {
            $timer = $timer_init + microtime(true);
            $res['error'] = $ex->getMessage(); 
        }
        $res['timer'] = $timer;
FILE::debug($res, 5);
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
            if ($noError) { $this->_driver->manage()->timeouts()->implicitlyWait(2); }
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

    public function wait($xpath, $submit) {
        $driver = &$this->_driver;
/*
        $this->_driver->wait(10,125)->until(
//            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::xpath($xpath))
            function () use (&$driver, $xpath, $submit) {
                if (!is_object($driver)) { throw new \Exception('No WebDriver'); }
                $res = false;
                try {
                    $elements = $driver->findElements(WebDriverBy::xpath($xpath));
                    $res = (count($elements) > 0);
                    unset($elements);
                } catch (\Exception $ex) { $res = false; }
                if (empty($res)) {
                    try {
                        $element = $driver->findElement(WebDriverBy::xpath($submit));
                        $element->click();
                        unset($element);
                    } catch (\Exception $ex) { $res = false; }
                }
                $fname = DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'screenshoot_'.microtime(true).'.png';
                $driver->takeScreenshot($fname);
                @chmod($fname, 02666);
                return $res;
            },
            'Error: Waiting timeout exceeded.'
        );
*/
    }

}