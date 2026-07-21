<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/funcoes.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $parametros = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $parametros['path'],
        $parametros['domain'],
        $parametros['secure'],
        $parametros['httponly']
    );
}

session_destroy();
session_start();
definirFlash('success', 'Sessão encerrada com segurança.');
header('Location: login.php');
exit;
