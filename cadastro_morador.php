<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/includes/layout.php';

if (isset($_SESSION['usuario_role'])) {
    header('Location: ' . obterRedirectPorPapel($_SESSION['usuario_role']));
    exit;
}

$pdo = obterConexao();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $telefone = trim($_POST['telefone'] ?? '');
    $torre = normalizarIdentificador($_POST['torre'] ?? '');
    $apartamento = normalizarIdentificador($_POST['apartamento'] ?? '');
    $senha = (string) ($_POST['senha'] ?? '');
    $confirmacao = (string) ($_POST['confirmacao_senha'] ?? '');

    if (
        $nome === '' ||
        $email === '' ||
        $torre === '' ||
        $apartamento === '' ||
        $senha === '' ||
        $confirmacao === ''
    ) {
        definirFlash('warning', 'Preencha todos os campos obrigatórios do cadastro.');
        header('Location: cadastro_morador.php');
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        definirFlash('warning', 'Informe um e-mail válido.');
        header('Location: cadastro_morador.php');
        exit;
    }

    if ($senha !== $confirmacao) {
        definirFlash('warning', 'A confirmação de senha não confere.');
        header('Location: cadastro_morador.php');
        exit;
    }

    if (strlen($senha) < 8) {
        definirFlash('warning', 'Use uma senha com pelo menos 8 caracteres.');
        header('Location: cadastro_morador.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);

        if ($stmt->fetch()) {
            $pdo->rollBack();
            definirFlash('danger', 'Já existe um usuário cadastrado com este e-mail.');
            header('Location: cadastro_morador.php');
            exit;
        }

        // Auto-criação inteligente da torre informada pelo morador.
        $stmt = $pdo->prepare('SELECT id FROM towers WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $torre]);
        $torreId = $stmt->fetchColumn();

        if ($torreId === false) {
            $stmt = $pdo->prepare('INSERT INTO towers (name) VALUES (:name)');
            $stmt->execute([':name' => $torre]);
            $torreId = (int) $pdo->lastInsertId();
        } else {
            $torreId = (int) $torreId;
        }

        // A unidade é criada somente se ainda não existir para a torre selecionada.
        $stmt = $pdo->prepare(
            'SELECT id
               FROM units
              WHERE tower_id = :tower_id
                AND unit_number = :unit_number
              LIMIT 1'
        );
        $stmt->execute([
            ':tower_id' => $torreId,
            ':unit_number' => $apartamento,
        ]);
        $unidadeId = $stmt->fetchColumn();

        if ($unidadeId === false) {
            $stmt = $pdo->prepare(
                'INSERT INTO units (tower_id, unit_number)
                 VALUES (:tower_id, :unit_number)'
            );
            $stmt->execute([
                ':tower_id' => $torreId,
                ':unit_number' => $apartamento,
            ]);
            $unidadeId = (int) $pdo->lastInsertId();
        } else {
            $unidadeId = (int) $unidadeId;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users (unit_id, full_name, email, phone, password_hash, role)
             VALUES (:unit_id, :full_name, :email, :phone, :password_hash, :role)'
        );

        $stmt->execute([
            ':unit_id' => $unidadeId,
            ':full_name' => $nome,
            ':email' => $email,
            ':phone' => $telefone !== '' ? $telefone : null,
            ':password_hash' => password_hash($senha, PASSWORD_DEFAULT),
            ':role' => 'RESIDENT',
        ]);

        $moradorId = (int) $pdo->lastInsertId();
        registrarAuditoria($pdo, $moradorId, 'RESIDENT_REGISTERED', 'USER', $moradorId, [
            'tower' => $torre,
            'unit' => $apartamento,
        ]);

        $pdo->commit();
        definirFlash('success', 'Cadastro concluído. Agora você já pode fazer login.');
        header('Location: login.php');
        exit;
    } catch (Throwable $erro) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        definirFlash('danger', 'Não foi possível concluir o cadastro do morador.');
        header('Location: cadastro_morador.php');
        exit;
    }
}

renderizarInicioPagina(
    'Cadastro público do morador',
    'Moradores podem criar o próprio acesso e informar torre e apartamento. Se a unidade não existir, o sistema cria tudo automaticamente.',
    ['ativo' => 'cadastro_morador', 'mostrar_navegacao' => false]
);
?>
<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-7">
        <div class="text-center mb-4">
            <div class="auth-seal"><i class="bi bi-box-seam"></i></div>
            <h2 class="auth-title">Criar sua conta</h2>
            <p class="soft-muted mb-0">Preencha os dados abaixo para continuar</p>
        </div>

        <section class="form-shell">
            <form method="post" class="row g-3">
                <div class="col-12">
                    <label for="nome" class="form-label fw-semibold">Nome completo</label>
                    <div class="input-icon">
                        <i class="bi bi-person"></i>
                        <input type="text" class="form-control" id="nome" name="nome" placeholder="Seu nome completo" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label fw-semibold">E-mail</label>
                    <div class="input-icon">
                        <i class="bi bi-envelope"></i>
                        <input type="email" class="form-control" id="email" name="email" placeholder="nome@condominio.com" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="telefone" class="form-label fw-semibold">Telefone / WhatsApp</label>
                    <div class="input-icon">
                        <i class="bi bi-telephone"></i>
                        <input type="text" class="form-control" id="telefone" name="telefone" placeholder="(11) 99999-9999">
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="torre" class="form-label fw-semibold">Torre</label>
                    <div class="input-icon">
                        <i class="bi bi-building"></i>
                        <input type="text" class="form-control" id="torre" name="torre" placeholder="Torre A" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="apartamento" class="form-label fw-semibold">Apartamento</label>
                    <div class="input-icon">
                        <i class="bi bi-house-door"></i>
                        <input type="text" class="form-control" id="apartamento" name="apartamento" placeholder="1204" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="senha" class="form-label fw-semibold">Senha</label>
                    <div class="input-icon">
                        <i class="bi bi-lock"></i>
                        <input type="password" class="form-control" id="senha" name="senha" placeholder="Mínimo de 8 caracteres" required>
                        <button type="button" class="pw-toggle" data-target="senha" aria-label="Mostrar senha">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="confirmacao_senha" class="form-label fw-semibold">Confirmar senha</label>
                    <div class="input-icon">
                        <i class="bi bi-lock"></i>
                        <input type="password" class="form-control" id="confirmacao_senha" name="confirmacao_senha" placeholder="Repita a senha" required>
                        <button type="button" class="pw-toggle" data-target="confirmacao_senha" aria-label="Mostrar senha">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-uw w-100">Cadastrar</button>
                </div>
            </form>
            <div class="auth-foot">
                Já tem uma conta? <a href="login.php">Entrar</a>
            </div>
        </section>
    </div>
</div>
<div class="row justify-content-center mt-4">
    <div class="col-lg-8">
        <ul class="list-clean soft-muted security-notes">
            <li><i class="bi bi-magic me-1"></i> Torre e apartamento são criados automaticamente</li>
            <li><i class="bi bi-key me-1"></i> Senha salva apenas como hash irreversível</li>
            <li><i class="bi bi-bell me-1"></i> Pronto para notificações in-app, WhatsApp e Push</li>
        </ul>
    </div>
</div>
<?php renderizarFimPagina(); ?>
