<?php
// Azure Linux App Service may route separate requests to different workers.
// Keep file-backed PHP sessions in App Service's shared persistent /home path.
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Use App Service's persistent /home share when available. Checking the
    // path directly also covers deployments where Azure's instance env vars
    // aren't exposed to PHP.
    if (is_dir('/home') && is_writable('/home')) {
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

    // Keep the login cookie valid across the whole site and both custom-domain
    // hostnames. Honor Azure's forwarded HTTPS header behind its proxy.
    session_name('PANDAPESA_SESSID');
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    $cookieDomain = ($host === 'pandapesa.me' || substr($host, -strlen('.pandapesa.me')) === '.pandapesa.me')
        ? '.pandapesa.me'
        : '';
    $forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $cookieDomain,
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
