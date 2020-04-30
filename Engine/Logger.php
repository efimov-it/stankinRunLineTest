<?php
class Logger {
    private $name;
    private $path;
    private $file_stream;

    private static $loggers = array();

    private function __construct($name, $path){
        $this->name = $name;
        $this->path = $path;
 
        $this->start();
    }
 
    public function start() {
        if (!defined('_SYSTEM_LOG_FOLDER_')) {
            $error = "[LOGGER] The directory for system logs is not specified.";
            error_log($error, 0);
            return false;
        }

        $folder = _SYSTEM_LOG_FOLDER_ . DIRECTORY_SEPARATOR . date("Y.m.d");
        $filepath = ''; 

        if ($this->path !== null) {
            $filepath = $folder . DIRECTORY_SEPARATOR . $this->path;
            if (strtoupper(substr(php_uname('s'), 0, 3)) === 'WIN') $filepath = str_replace('/', '\\', $filepath); 
            $this->file_stream = fopen($filepath, 'a+');
        } else {
            if (!is_dir($folder))  mkdir($folder, 0777, true);
            $filepath = $folder . DIRECTORY_SEPARATOR . $this->name . date("H") . ".log";
            if (strtoupper(substr(php_uname('s'), 0, 3)) === 'WIN') $filepath = str_replace('/', '\\', $filepath);
            $this->file_stream = fopen($filepath, 'a+');
        }

        if ($this->file_stream === false) {
            $error = "[LOGGER] Can't open: '$filepath' does not exist";
            error_log($error, 0);
        }

        return true;
    }

    public function stop() {
        self::__destruct();
    }
 
    public static function getLogger($name, $path = null) {
        if(!isset(self::$loggers[$name])) {
            self::$loggers[$name] = new self($name, $path);
        }
 
        return self::$loggers[$name];
    }

    public static function logWrite($name, $message) {
        $logger = self::getLogger($name);
        $logger->log($message);
    }
 
    public function log($message) {
        $log = '[' . date('H:i:s', time()) . '] ';

        if(func_num_args() > 1 || !is_string($message)){
            $params = func_get_args();
            $num = func_num_args();
            $log .= "print_r output:\n";

            for ($i = 0; $i < $num; $i++)
                $log .= "\$object[$i] => \n" . call_user_func_array(array($this, "printObject"), array($params[$i])) . "\n"; 
        } else 
            $log .= $message . "\n";
 
        return $this->_write($log);
    }
 
    public function printObject($obj) {
        ob_start();
        print_r($obj);
        $ob = ob_get_contents();
        ob_clean();

        return $ob;
    }
 
    protected function _write($string) {
        $status = fwrite($this->file_stream, $string);
        
        if ($status === false) return false;
        return true;
    }
 
    public function __destruct(){
        if ($this->file_stream) {
            fclose($this->file_stream);
            $this->file_stream = null;
        }
    }

    public function getName() {
        return $this->name;
    }

    public function getPath() {
        return $this->path;
    }

    public function getFilestream() {
        return $this->file_stream;
    }

    private function __clone() {}
    private function __sleep() {}
    private function __wakeup(){}
}