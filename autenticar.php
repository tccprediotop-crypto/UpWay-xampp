<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/includes/funcoes.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));
$senha = (string) ($_POST['senha'] ?? '');
$ipAtual = obterIpAtual();

if ($email === '' || $senha === '') {
    definirFlash('warning', 'Informe e-mail e senha para continuar.');
    header('Location: login.php');
    exit;
}

try {
    $pdo = obterConexao();

    if (tentativasExcedidas($pdo, $email, $ipAtual)) {
        registrarAuditoria($pdo, null, 'LOGIN_THROTTLED', 'USER', null, [
            'email' => $email,
            'ip' => $ipAtual,
        ]);
        definirFlash('danger', 'Muitas tentativas inválidas. Aguarde 15 minutos antes de tentar novamente.');
        header('Location: login.php');
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT id, full_name, email, password_hash, role, is_active
           FROM users
          WHERE email = :email
          LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    if ($usuario === false || (int) $usuario['is_active'] !== 1 || !password_verify($senha, $usuario['password_hash'])) {
        registrarTentativaLogin($pdo, $email, $ipAtual, false);
        registrarAuditoria($pdo, $usuario['id'] ?? null, 'LOGIN_FAILED', 'USER', $usuario['id'] ?? null, [
            'email' => $email,
            'ip' => $ipAtual,
        ]);
        definirFlash('danger', 'Credenciais inválidas. Revise os dados e tente novamente.');
        header('Location: login.php');
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['usuario_nome'] = $usuario['full_name'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['usuario_role'] = $usuario['role'];

    registrarTentativaLogin($pdo, $email, $ipAtual, true);
    registrarAuditoria($pdo, (int) $usuario['id'], 'LOGIN_SUCCESS', 'USER', (int) $usuario['id'], [
        'email' => $email,
        'ip' => $ipAtual,
    ]);

    definirFlash('success', 'Autenticação realizada com sucesso.');
    header('Location: ' . obterRedirectPorPapel($usuario['role']));
    exit;
} catch (Throwable $erro) {
    definirFlash('danger', 'O banco parece incompleto. Importe novamente o script upway_db.sql para criar as tabelas que faltam.');
    header('Location: login.php');
    exit;
}
