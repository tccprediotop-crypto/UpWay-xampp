<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/funcoes.php';

if (isset($_SESSION['usuario_role'])) {
    header('Location: ' . obterRedirectPorPapel($_SESSION['usuario_role']));
    exit;
}

header('Location: login.php');
exit;
