<?php
// Router for the PHP built-in server (see klms-entrypoint.sh).
//
// Why this exists in the docker setup and not in a native deploy:
// In a native deploy (Apache + mod_php, or nginx + php-fpm) the WEB SERVER
// serves static files (CSS/JS/images) natively with correct MIME types, and only
// routes dynamic requests to the front controller. The PHP built-in server
// (php -S), which we use here because the php:8.4-cli base image ships no
// php-fpm, does NOT do that split on its own: with a router script it sends
// EVERY request through the front controller, so /build/app.css would come back
// as text/html (the browser then refuses to apply it as CSS, and SRI sha384
// hashes no longer match). Without a router script it serves static files
// natively but 404s on every dynamic route.
//
// This router gives us both: existing static files are served natively by
// php -S (correct MIME types, real bytes — required for CSS to apply and SRI
// integrity to match); everything else is routed through the Symfony front
// controller (index.php).

$base = '/app/public';
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));

if ($uri !== '/' && $uri !== '' && $uri[0] === '/') {
    $file = realpath($base . $uri);
    if ($file !== false && str_starts_with($file, $base . '/') && is_file($file)) {
        return false;
    }
}

// php -S sets SCRIPT_FILENAME to THIS router script. Symfony's
// autoload_runtime.php does `require SCRIPT_FILENAME` and expects the app
// callable — on a warm worker (after the first require_once no longer returns
// true) it would re-require the router (which returns an int) and die with a
// TypeError. Point it at the front controller so the runtime resolves the app.
$_SERVER['SCRIPT_FILENAME'] = $base . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

// TLS termination happens at the reverse proxy (Traefik/nginx) in front of the
// container; php -S itself only ever sees plain HTTP. Without this, Symfony
// thinks the scheme is http: framework.session.cookie_secure=auto then drops
// the session cookie on every HTTPS login (same fix as the VM vhost's
// `SetEnvIf X-Forwarded-Proto https HTTPS=on`). Also honour the proxy's
// forwarded host so generated URLs match the public host.
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = 443;
}

require $base . '/index.php';
