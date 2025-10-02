<?php

declare(strict_types=1);

class ADB {
    
    const BIN_LINUX = 'adb';
    const BIN_DARWIN = 'adb-darwin';
    const BIN_WINDOWS = 'adb.exe';

    const CONNECT_TYPE_DEVICE = 'device';
    const CONNECT_TYPE_RECOVERY = 'recovery';
    const CONNECT_TYPE_SIDELOAD = 'sideload';
    const CONNECT_TYPE_RESCUE = 'rescue';
    const CONNECT_TYPE_UNAUTHORIZED = 'unauthorized';
    const CONNECT_TYPE_OFFLINE = 'offline';

    public static $logs;
    public $bin;
    public $device;
    private $devices;

    function __construct($host='127.0.0.1', $port='5555', $bin_path = 'deps/platform-tools/') {
        self::$logs = array();

        switch (PHP_OS_FAMILY) {
            case 'Windows':
                $this -> bin = self::BIN_WINDOWS;
                self::addLog('Windows detected');
                break;
            case 'Darwin':
                $this -> bin = self::BIN_DARWIN;
                self::addLog('Darwin detected');
                break;
            default:
                $this -> bin = self::BIN_LINUX;
                self::addLog('Linux detected');
        }

		if ($bin_path !== '') {
            $this -> bin = '"' . $bin_path . $this -> bin . '"';
            self::addLog('Setting bin to ' . $this -> bin);
		}

        $this->_startServer();

        self::addLog('Setting device to ' . $this->device);
        $this->device = $host . ':' . $port;

        self::addLog('Connecting ' . $this->device);
        $this->_runAdb('connect ' . $this->device);

        $this->_runAdb('-s ' . $this->device . ' root');

        self::addLog('Refreshing device list');
        $this->_refreshDeviceList();

    }

    public function __destruct() {
        $this->_runAdb('disconnect');
        $this->_killServer();
    }

    protected static function addLog($message) {
        array_push(self::$logs, '[' . date('Y-m-d H:i:s') . ']=' . $message);
    }

    public static function showLog() {
        print_r(self::$logs);
    }

    /***
       *
       * Private stuff, allows to do whatever in relation to ADB API
       *
       ***/

    private function _refreshDeviceList() {
        $this -> devices = array();
        $result = $this->_runAdb('devices -l');
        if ($this->_judgeOutput($result)) {
            array_shift($result[0]); // List of devices attached
            foreach ($result[0] as $key => $value) {
                $value = preg_replace('`[ \t]+`is', ' ', $value);
                $device = explode(' ', $value);
                $temp = array('serial' => '', 'status' => '', 'transport' => '');
                switch ($device[1]) {
                    case self::CONNECT_TYPE_DEVICE:
                    case self::CONNECT_TYPE_RECOVERY:
                        $transport = str_replace('transport_id:', '', $device[5]);
                        $temp['manufacturer'] = $this->_runAdb('-t ' . $transport . ' shell getprop ro.product.manufacturer')[0][0];
                        $temp['brand'] = $this->_runAdb('-t ' . $transport . ' shell getprop ro.product.brand')[0][0];
                        $temp['board'] = $this->_runAdb('-t ' . $transport . ' shell getprop ro.product.board')[0][0];
                        $temp['name'] = $this->_runAdb('-t ' . $transport . ' shell getprop ro.product.name')[0][0];
                    case self::CONNECT_TYPE_SIDELOAD:
                    case self::CONNECT_TYPE_RESCUE:
                        $temp['serial'] = $device[0];
                        $temp['status'] = $device[1];
                        $temp['product'] = str_replace('product:', '', $device[2]);
                        $temp['model'] = str_replace('model:', '', $device[3]);
                        $temp['device'] = str_replace('device:', '', $device[4]);
                        $temp['transport'] = str_replace('transport_id:', '', $device[5]);
                        break;
                    case self::CONNECT_TYPE_UNAUTHORIZED:
                    case self::CONNECT_TYPE_OFFLINE:
                        $temp['serial'] = $device[0];
                        $temp['status'] = $device[1];
                        $temp['transport'] = str_replace('transport_id:', '', $device[2]);
                        break;
                }
                $this -> devices[] = $temp;
            }
        }
        return $this->devices;
    }

    private function _startServer() {
        return $this->_runAdbJudge('start-server');
    }

    private function _killServer($force = false) {
        if ($force) {
            if (PHP_OS_FAMILY !== 'Windows') {
                echo('Force termination is not implemented on non-Windows systems, fallbacking to normal.' . PHP_EOL);
            } else {
                return $this->_judgeOutput($this->_execShell('taskkill /f /im ' . $this -> bin));
            }
        }
        return $this->_runAdbJudge('kill-server');
    }

    protected function restartServer($force = false) {
        $this->_killServer($force);
        return _startServer();
    }

    private function _sendInput($type = '', $args = '') {
        return $this->_runAdb('-s ' . $this->device . ' shell input ' . $type . ' ' . $args);
    }

    protected function _setScreenSize($size = 'reset') {
        return $this->_runAdbJudge('-s ' . $this->device . ' shell wm size ' . $size);
    }

    protected function _getScreenSize() {
        $o = $this->_runAdb('-s ' . $this->device . ' shell wm size');
        return $this->_judgeOutput($o) ? array(str_replace('Physical size: ', '', $o[0][0]), isset($output[0][1]) ? str_replace('Override size: ', '', $o[0][1]) : '')[0] : false;
    }

    protected function _setScreenDensity($size = 'reset') {
        return $this->_runAdbJudge('-s ' . $this->device . ' shell wm density ' . $size);
    }

    protected function _getScreenDensity() {
        $o = $this->_runAdb('-s ' . $this->device . ' shell wm density');
        return $this->_judgeOutput($o) ? array(str_replace('Physical density: ', '', $o[0][0]), isset($o[0][1]) ? str_replace('Override density: ', '', $o[0][1]) : '') : false;
    }

    protected function _ScreenshotPNG() {
        $o = $this->_runAdb('-s ' . $this->device . ' exec-out screencap -p /sdcard/capture.png', true);
        return $this->_judgeOutput($o) ? $o[0] : false;
    }

    protected function _dlScreenshotPNG($dir, $name='capture.png') {
        $o = $this->_runAdb('-s ' . $this->device . ' pull /sdcard/capture.png ' . dirname(__FILE__) . '/' . $dir . '/' . $name);
        return $this->_judgeOutput($o) ? substr($o[0][0], 8) : false;
    }

    protected function _rmScreenshotPNG() {
        $o = $this->_runAdb('-s ' . $this->device . ' shell rm -f /sdcard/capture.png');
        return $this->_judgeOutput($o) ? substr($o[0][0], 8) : false;
    }    

    protected function _tap($x, $y) {
        $this->_sendInput('tap', $x . ' ' . $y);
    }
    
    protected function _swipe($x1, $y1, $x2, $y2) {
        $this->_sendInput('swipe', $x1 . ' ' . $y1 . ' ' . $x2 . ' ' . $y2 . ' ' . rand(900, 920));
    }

    protected function _getPackage($package) {
        $o = $this->_runAdb('-s ' . $this->device . ' shell pm path ' . $package);
        return $this->_judgeOutput($o) ? substr($o[0][0], 8) : false;
    }

    protected function _getCurrentActivity() {
        $o = $this->_runAdb('-s ' . $this->device . ' shell "dumpsys window | grep mCurrentFocus"');
        if (!$this->_judgeOutput($o)) {
            return array(false, false);
        }
        if (str_contains($o[0][0], ' mCurrentFocus=Window')) {
            if (preg_match('`Window\{(.*)\}`', $o[0][0], $matches)) {
				$matches = explode(' ', $matches[1]);
				$o = explode('/', $matches[count($matches) - 1]);
				return array($o[0], isset($o[1]) ? $o[1] : false);
			}
        }
        return array(false, false);
    }

    private function _getScreenState() {
        $o = $this->_runAdb('-s ' . $this->device . ' shell "dumpsys window policy | grep screenState"');
        if (!$this->_judgeOutput($o)) {
            return false;
        }
        return str_contains($o[0][0], 'SCREEN_STATE_ON');
    }

    private function _openDocumentUI($path = '') {
        // Content workaround from https://mlgmxyysd.meowcat.org/2021/02/18/android-r-saf-data/
        return $this->_runAdbJudge('-s ' . $this->device . ' shell am start -a android.intent.action.VIEW -c android.intent.category.DEFAULT -t vnd.android.document/' . ($path === '' ? 'root' : 'directory -d content://com.android.externalstorage.documents/tree/primary:' . $path . '/document/primary:' . $path) . ' com.android.documentsui/.files.FilesActivity');
    }

    private function _clearLogcat() {
        return $this->_runAdb('-s ' . $this->device . ' logcat -c');
    }

    private function _runAdb($command, $raw = false) {
        //self::addLog('Command being run: ' . $command);
        $result = $this->_execShell($this -> bin . ' '  . $command, $raw);
        //self::addLog('Result: ' . print_r($result, true));
        return $result;
    }

    private function _runAdbJudge($command) {
        return $this->_judgeOutput($this->_runAdb($command));
    }

    private function _judgeOutput($output, $target = 0) {
        return isset($output[1]) && $output[1] === $target ? true : false;
    }

    private function _execShell($command, $raw = false) {
        ob_start();
        passthru($command . ' 2>&1', $errorlevel);
        $output = ob_get_contents();
        ob_end_clean();
        return array($raw ? $output : explode(PHP_EOL, rtrim($output)), $errorlevel);
    }

    /***
       *
       * Public stuff, high level function exposed to the scanner or whatever
       *
       ***/

    public function getDeviceList() {
        return $this->devices;
    }

}
