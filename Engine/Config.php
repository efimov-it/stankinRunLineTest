<?php
Core::disableDirectCall();


class Config {
    private static $className = "Config";
    
    public static function getClassName() {
        return self::$className;
    }
}