<?php
define('_IVNIXSECURE_', true);
require_once 'Engine/Handler.php';

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && ($error['type'] == E_ERROR || $error['type'] == E_PARSE || $error['type'] == E_COMPILE_ERROR)) {
        $error_msg = "PHP Fatal: " . $error['message'] . " in " . preg_replace('/(.*)\/(.*)/', "$2", $error['file']) . ":" . $error['line'];
        $response = array(
            'data' => ob_get_contents(),
            'success' => false,
            'error' => $error_msg
        );
        ob_clean();
        header('HTTP/1.1 200 Ok');
        header("Access-Control-Allow-Origin: *");
        Logger::logWrite(_SYSTEM_LOGGER_NAME_, $response);
        echo json_encode($response);
        exit(1);
    }
});

if($_SERVER['REQUEST_METHOD'] == 'OPTIONS'){
    ob_clean();
    header("Access-Control-Allow-Origin: *");
    header("Content-type: application/json; charset=utf-8");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Authorization");
    header("Access-Control-Request-Method: POST");
    return true;
}

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    ob_clean();
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: *");
    header("Access-Control-Expose-Headers: Authorization");
    header("Content-Type: application/json");
    header("Cache-Control: no-cache, must-revalidate");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
}

$ix_core = Core::getInstance();
$ix_core->API = 'API.php';
$ix_core->API->listenInput();

return true;