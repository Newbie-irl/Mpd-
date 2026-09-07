<?php
session_start();

// 1. Wipe all session data on the server
$_SESSION = [];

// 2. Remove the session cookie from the browser too.
//    Without this, some browsers/proxies hang on to the old session id
//    and a stale session can "come back" on the next request.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 3. Destroy the session on the server
session_destroy();

// 4. Start a fresh session just so we can flash a message on the login page
session_start();
$_SESSION['success'] = "You have been logged out.";

header('Location: login.php');
exit;