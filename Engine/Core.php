<?php
if (!defined('_IVNIXSECURE_')) die('Access denied.');

interface StorableObject {
    public static function getClassName();
}

class Core implements StorableObject {
    private static $className = "Core";

    private static $instance = null;
    private static $objects = array();

    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getObject($key) {
        if ( is_object(self::$objects[$key])) {
            return self::$objects[$key];	
        }
    }

    public function __get($key) {
        if ( is_object(self::$objects[$key]))
            return self::$objects[$key];
    }

    public function addObject($key, $object_link) {
        if (!isset(self::$objects[$key])) {
            $realpath = _SYSTEM_CORE_ROOT_ . DIRECTORY_SEPARATOR . $object_link;
            require_once realpath($realpath);
            self::$objects[$key] = new $key();

        } else {
            Logger::logWrite(_SYSTEM_LOGGER_NAME_, "Attempt to redefine an object. Key: " . $key . ", Link: " . _SYSTEM_CORE_ROOT_ . DIRECTORY_SEPARATOR . $object_link);
        }
    }

    public function __set($key, $object) {
        $this->addObject($key, $object);
    }

    public static function disableDirectCall() {
        if (!defined('_IVNIXSECURE_')) die('Access denied.');
    }

    public static function getClassName() {
        return self::$className;
    }

    public function loadCore() {
        $this->addObject('Config', 'Config.php');
        $this->addObject('Database', 'Database.php');
        $this->addObject('HTMLFilter', 'HTMLFilter.php');
    }

    private function __clone() {}
    private function __construct() {
        $this->loadCore();
    }
    private function __wakeup(){}
    private function __sleep() {}
}

