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
    ['ativo' => 'cadastro_morador']
);
?>
<div class="row g-4">
    <div class="col-lg-5">
        <section class="surface-card h-100">
            <div class="card-label">Experiência simplificada</div>
            <h2 class="section-title">Onboarding leve para o condomínio</h2>
            <p class="soft-muted">
                O fluxo foi desenhado para eliminar cadastro manual de estrutura.
                O morador só informa torre e apartamento e o UpWay resolve o vínculo de unidade.
            </p>
            <div class="divider-soft"></div>
            <ul class="list-clean soft-muted">
                <li>Torre e apartamento são normalizados automaticamente.</li>
                <li>Senha salva apenas como hash irreversível no banco.</li>
                <li>Pronto para receber notificações in-app, WhatsApp e Push.</li>
            </ul>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="surface-card form-shell h-100">
            <h2 class="section-title">Criar conta de morador</h2>
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
                    <label for="telefone" class="form-label fw-semibold">Telefone / WhatsApp</label>
                    <input type="text" class="form-control" id="telefone" name="telefone" placeholder="(11) 99999-9999">
                </div>
                <div class="col-md-6">
                    <label for="torre" class="form-label fw-semibold">Torre</label>
                    <input type="text" class="form-control" id="torre" name="torre" placeholder="Torre A" required>
                </div>
                <div class="col-md-6">
                    <label for="apartamento" class="form-label fw-semibold">Apartamento</label>
                    <input type="text" class="form-control" id="apartamento" name="apartamento" placeholder="1204" required>
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
                    <button type="submit" class="btn btn-dark btn-pill px-4">Cadastrar morador</button>
                    <a href="login.php" class="btn btn-outline-dark btn-pill">Voltar ao login</a>
                </div>
            </form>
        </section>
    </div>
</div>
<?php renderizarFimPagina(); ?>
