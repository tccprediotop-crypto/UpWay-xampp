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
    ['ativo' => 'cadastro_porteiro']
);
?>
<div class="row g-4">
    <div class="col-lg-5">
        <section class="surface-card h-100">
            <div class="card-label">Governanca de acesso</div>
            <h2 class="section-title">Primeira conta administrativa</h2>
            <p class="soft-muted">
                Este formulario existe apenas para o bootstrap inicial do UpWay. A primeira conta criada aqui
                recebe o perfil ADMIN e passa a controlar a criacao, edicao e exclusao das demais contas operacionais.
            </p>
            <div class="divider-soft"></div>
            <span class="status-pill <?= $modoBootstrap ? 'status-success' : 'status-warning'; ?>">
                <?= $modoBootstrap ? 'Bootstrap disponivel' : 'Bootstrap encerrado'; ?>
            </span>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="surface-card form-shell h-100">
            <?php if (!$podeCadastrar): ?>
                <h2 class="section-title">Cadastro publico indisponivel</h2>
                <p class="soft-muted">
                    Ja existe pelo menos uma conta operacional ativa no UpWay. Use o login para acessar a area interna.
                </p>
                <a href="login.php" class="btn btn-dark btn-pill">Ir para o login</a>
            <?php else: ?>
                <h2 class="section-title">Criar conta admin</h2>
                <form method="post" class="row g-3">
                    <div class="col-12">
                        <label for="nome" class="form-label fw-semibold">Nome completo</label>
                        <input type="text" class="form-control" id="nome" name="nome" required>
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label fw-semibold">E-mail</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="col-md-6">
                        <label for="telefone" class="form-label fw-semibold">Telefone</label>
                        <input type="text" class="form-control" id="telefone" name="telefone">
                    </div>
                    <div class="col-md-6">
                        <label for="senha" class="form-label fw-semibold">Senha</label>
                        <input type="password" class="form-control" id="senha" name="senha" required>
                    </div>
                    <div class="col-md-6">
                        <label for="confirmacao_senha" class="form-label fw-semibold">Confirmar senha</label>
                        <input type="password" class="form-control" id="confirmacao_senha" name="confirmacao_senha" required>
                    </div>
                    <div class="col-12 d-grid d-sm-flex gap-2">
                        <button type="submit" class="btn btn-dark btn-pill px-4">Salvar conta admin</button>
                        <a href="login.php" class="btn btn-outline-dark btn-pill">Voltar</a>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php renderizarFimPagina(); ?>
