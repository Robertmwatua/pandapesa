<?php
// Azure Linux App Service may route separate requests to different workers.
// Keep file-backed PHP sessions in App Service's shared persistent /home path.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $onAzureAppService = getenv('WEBSITE_INSTANCE_ID') !== false;
    if ($onAzureAppService) {
        $sharedSessionPath = '/home/php_sessions';
        if (!is_dir($sharedSessionPath)) {
            @mkdir($sharedSessionPath, 0700, true);
        }
        if (is_dir($sharedSessionPath) && is_writable($sharedSessionPath)) {
            session_save_path($sharedSessionPath);
        } else {
            error_log('Shared PHP session directory is unavailable: ' . $sharedSessionPath);
        }
    }
    session_start();
}
