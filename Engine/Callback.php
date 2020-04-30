<?php
if(_SYSTEM_DEBUG_MODE_ === true) {
    Logger::getLogger(_SYSTEM_LOGGER_NAME_);
    set_error_handler('setErrorHandler');
    set_exception_handler('setExceptionHandler');
}

function setErrorHandler($code, $message, $file, $line) {
    $error = array(
        "code" =>  $code, 
        "message" => $message, 
        "file" => $file, 
        "line" => $line
    );
    Logger::logWrite(_SYSTEM_LOGGER_NAME_, $error);
    if ($code == E_USER_WARNING) {
        json_encode([
            "status" => false,
            "error" => $message,
            "data" => $error
        ]);
    }
    if (_SYSTEM_DEBUG_VD_ === true && $code != E_USER_WARNING) var_dump($error);
    if ($code == E_USER_ERROR) exit(1);
    return true;
}

function setExceptionHandler($error) {
    $error = array(
        "code" =>  $error->getCode(), 
        "message" => $error->getMessage(),
        "file" => $error->getFile(), 
        "line" => $error->getLine()
    );
    if ($error['code'] == E_USER_WARNING) {
        json_encode([
            "status" => false,
            "error" => $error['message'],
            "data" => $error
        ]);
    }
    Logger::logWrite(_SYSTEM_LOGGER_NAME_, $error);
    if (_SYSTEM_DEBUG_VD_ === true && $error['code'] != E_USER_WARNING) var_dump($error);
    if ($error['code'] == E_ERROR) exit(1);
}