<?php
// Folders
define("_SYSTEM_ROOT_FOLDER_", $_SERVER['DOCUMENT_ROOT']);
define("_SYSTEM_CORE_ROOT_", _SYSTEM_ROOT_FOLDER_ . DIRECTORY_SEPARATOR . "Engine");

// Debug
define('_SYSTEM_DEBUG_MODE_', true);
define('_SYSTEM_DEBUG_VD_', true);
define('_SYSTEM_ERROR_HANDLER_', true);

// Logger
define("_SYSTEM_LOG_FOLDER_", _SYSTEM_CORE_ROOT_ . DIRECTORY_SEPARATOR . "Logs");
define("_SYSTEM_LOGGER_NAME_", 'system-log');

// Errors code
define("E_USER_API", 111);

// Database connection
define("DB_HOST", "localhost");
define("DB_USER", "postgres");
define("DB_PASS", "99919639");
define("DB_DATA", "stankin_db");

// Secure

define("SECURE_KEY", "ivnix_secure_key");