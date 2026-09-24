<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/includes/layout.php';

$pdo = obterConexao();
$totalOperadores = (int) $pdo
    ->query("SELECT COUNT(*) FROM users WHERE role IN ('PORTER', 'ADMIN') AND is_active = 1")
    ->fetchColumn();

$modoBootstrap = $totalOperadores === 0;
$podeCadastrar = $modoBootstrap;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$podeCadastrar) {
        definirFlash('danger', 'O bootstrap publico ja foi encerrado. Use uma conta administrativa para gerenciar os outros acessos.');
        header('Location: login.php');
        exit;
    }

    $nome = trim($_POST['nome'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $telefone = trim($_POST['telefone'] ?? '');
    $senha = (string) ($_POST['senha'] ?? '');
    $confirmacao = (string) ($_POST['confirmacao_senha'] ?? '');

    if ($nome === '' || $email === '' || $senha === '' || $confirmacao === '') {
        definirFlash('warning', 'Preencha todos os campos obrigatorios para criar a conta administrativa.');
        header('Location: cadastro_porteiro.php');
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        definirFlash('warning', 'Informe um e-mail valido.');
        header('Location: cadastro_porteiro.php');
        exit;
    }

    if ($senha !== $confirmacao) {
        definirFlash('warning', 'A confirmacao de senha da conta admin nao confere.');
        header('Location: cadastro_porteiro.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);

        if ($stmt->fetch()) {
            definirFlash('danger', 'Ja existe um usuario com este e-mail.');
            header('Location: cadastro_porteiro.php');
            exit;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, phone, password_hash, role)
             VALUES (:full_name, :email, :phone, :password_hash, :role)'
        );

        $stmt->execute([
            ':full_name' => $nome,
            ':email' => $email,
            ':phone' => $telefone !== '' ? $telefone : null,
            ':password_hash' => password_hash($senha, PASSWORD_DEFAULT),
            ':role' => 'ADMIN',
        ]);

        $adminId = (int) $pdo->lastInsertId();
        registrarAuditoria($pdo, $adminId, 'ADMIN_BOOTSTRAPPED', 'USER', $adminId, [
            'email' => $email,
        ]);

        definirFlash('success', 'Conta admin criada com sucesso. Faca login para continuar.');
        header('Location: login.php');
        exit;
    } catch (Throwable $erro) {
        definirFlash('danger', 'Nao foi possivel cadastrar a conta administrativa.');
        header('Location: cadastro_porteiro.php');
        exit;
    }
}

renderizarInicioPagina(
    'Bootstrap da administracao',
    'Fluxo publico para criar a primeira conta ADMIN do condominio. Depois disso, novos acessos passam a ser gerenciados internamente.',
    ['ativo' => 'cadastro_porteiro', 'mostrar_navegacao' => false]
);
?>
<div class="row justify-content-center">
    <div class="col-lg-7 col-xl-6">
        <?php if (!$podeCadastrar): ?>
            <div class="text-center mb-4">
                <h2 class="auth-title">Cadastro publico indisponivel</h2>
                <p class="soft-muted mb-0">Ja existe pelo menos uma conta operacional ativa no UpWay.</p>
            </div>
            <section class="surface-card text-center">
                <p class="soft-muted">
                    Use o login para acessar a area interna e gerenciar a equipe por la.
                </p>
                <a href="login.php" class="btn btn-uw px-5">Ir para o login</a>
            </section>
        <?php else: ?>
            <div class="text-center mb-4">
                <div class="auth-seal"><i class="bi bi-box-seam"></i></div>
                <h2 class="auth-title">Criar conta admin</h2>
                <p class="soft-muted mb-1">A primeira conta recebe perfil ADMIN e passa a controlar a equipe.</p>
                <span class="status-pill status-success">Bootstrap disponivel</span>
            </div>
            <section class="form-shell">
                <form method="post" class="row g-3">
                    <div class="col-12">
                        <label for="nome" class="form-label fw-semibold">Nome completo</label>
                        <div class="input-icon">
                            <i class="bi bi-person-badge"></i>
                            <input type="text" class="form-control" id="nome" name="nome" placeholder="Nome do administrador" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label fw-semibold">E-mail</label>
                        <div class="input-icon">
                            <i class="bi bi-envelope"></i>
                            <input type="email" class="form-control" id="email" name="email" placeholder="admin@condominio.com" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="telefone" class="form-label fw-semibold">Telefone</label>
                        <div class="input-icon">
                            <i class="bi bi-telephone"></i>
                            <input type="text" class="form-control" id="telefone" name="telefone" placeholder="(11) 99999-9999">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="senha" class="form-label fw-semibold">Senha</label>
                        <div class="input-icon">
                            <i class="bi bi-lock"></i>
                            <input type="password" class="form-control" id="senha" name="senha" required>
                            <button type="button" class="pw-toggle" data-target="senha" aria-label="Mostrar senha">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="confirmacao_senha" class="form-label fw-semibold">Confirmar senha</label>
                        <div class="input-icon">
                            <i class="bi bi-lock"></i>
                            <input type="password" class="form-control" id="confirmacao_senha" name="confirmacao_senha" required>
                            <button type="button" class="pw-toggle" data-target="confirmacao_senha" aria-label="Mostrar senha">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-uw w-100">Salvar conta admin</button>
                    </div>
                </form>
                <div class="auth-foot">
                    Voltar para o <a href="login.php">login</a>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
<?php renderizarFimPagina(); ?>
