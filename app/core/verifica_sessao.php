<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

function requerPapel(array $papeisPermitidos): void
{
    $papelAtual = $_SESSION['usuario_role'] ?? null;

    if ($papelAtual === null || !in_array($papelAtual, $papeisPermitidos, true)) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}
