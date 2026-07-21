<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/verifica_sessao.php';
require_once __DIR__ . '/includes/layout.php';

requerPapel(['PORTER', 'ADMIN']);

$pdo = obterConexao();
$usuarioId = (int) $_SESSION['usuario_id'];
$usuarioRole = (string) ($_SESSION['usuario_role'] ?? '');
$usuarioEhAdmin = $usuarioRole === 'ADMIN';

function carregarContaOperacional(PDO $pdo, int $usuarioId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, full_name, email, phone, role, is_active, created_at
           FROM users
          WHERE id = :id
            AND role IN ('PORTER', 'ADMIN')
          LIMIT 1"
    );
    $stmt->execute([':id' => $usuarioId]);

    $conta = $stmt->fetch();

    return $conta !== false ? $conta : null;
}

function encerrarSessaoAtualComMensagem(string $mensagem, string $tipo = 'success'): void
{
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
    definirFlash($tipo, $mensagem);
    header('Location: login.php');
    exit;
}

function resumoAdministrativo(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            SUM(CASE WHEN role = 'ADMIN' AND is_active = 1 THEN 1 ELSE 0 END) AS active_admins,
            SUM(CASE WHEN role IN ('ADMIN', 'PORTER') AND is_active = 1 THEN 1 ELSE 0 END) AS active_accounts
         FROM users"
    );

    $resumo = $stmt->fetch() ?: [];

    return [
        'active_admins' => (int) ($resumo['active_admins'] ?? 0),
        'active_accounts' => (int) ($resumo['active_accounts'] ?? 0),
    ];
}

$contaEdicaoId = $usuarioEhAdmin
    ? (isset($_GET['editar']) ? (int) $_GET['editar'] : 0)
    : $usuarioId;

if (!$usuarioEhAdmin && isset($_GET['editar']) && (int) $_GET['editar'] !== $usuarioId) {
    definirFlash('warning', 'Seu perfil pode editar apenas a propria conta.');
    header('Location: porteiro_porteiros.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'editar';
    $contaId = (int) ($_POST['porteiro_id'] ?? 0);

    if ($acao === 'criar') {
        if (!$usuarioEhAdmin) {
            definirFlash('danger', 'Apenas a conta admin pode cadastrar novos porteiros.');
            header('Location: porteiro_porteiros.php');
            exit;
        }

        $nome = trim($_POST['nome'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $telefone = trim($_POST['telefone'] ?? '');
        $senha = (string) ($_POST['senha'] ?? '');
        $confirmacao = (string) ($_POST['confirmacao_senha'] ?? '');
        $ativo = isset($_POST['is_active']) ? 1 : 0;

        if ($nome === '' || $email === '' || $senha === '' || $confirmacao === '') {
            definirFlash('warning', 'Preencha todos os campos obrigatorios para cadastrar um novo porteiro.');
            header('Location: porteiro_porteiros.php');
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            definirFlash('warning', 'Informe um e-mail valido.');
            header('Location: porteiro_porteiros.php');
            exit;
        }

        if ($senha !== $confirmacao) {
            definirFlash('warning', 'A confirmacao de senha nao confere.');
            header('Location: porteiro_porteiros.php');
            exit;
        }

        try {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);

            if ($stmt->fetch()) {
                definirFlash('danger', 'Ja existe um usuario com este e-mail.');
                header('Location: porteiro_porteiros.php');
                exit;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO users (full_name, email, phone, password_hash, role, is_active)
                 VALUES (:full_name, :email, :phone, :password_hash, :role, :is_active)'
            );
            $stmt->execute([
                ':full_name' => $nome,
                ':email' => $email,
                ':phone' => $telefone !== '' ? $telefone : null,
                ':password_hash' => password_hash($senha, PASSWORD_DEFAULT),
                ':role' => 'PORTER',
                ':is_active' => $ativo,
            ]);

            $novoPorteiroId = (int) $pdo->lastInsertId();
            registrarAuditoria($pdo, $usuarioId, 'PORTER_CREATED', 'USER', $novoPorteiroId, [
                'email' => $email,
                'created_by' => $_SESSION['usuario_email'] ?? null,
            ]);

            definirFlash('success', 'Novo porteiro cadastrado com sucesso.');
            header('Location: porteiro_porteiros.php');
            exit;
        } catch (Throwable $erro) {
            definirFlash('danger', 'Nao foi possivel cadastrar o novo porteiro.');
            header('Location: porteiro_porteiros.php');
            exit;
        }
    }

    $conta = carregarContaOperacional($pdo, $contaId);

    if ($conta === null) {
        definirFlash('danger', 'Conta operacional nao encontrada.');
        header('Location: porteiro_porteiros.php');
        exit;
    }

    $podeGerenciarConta = $usuarioEhAdmin || $contaId === $usuarioId;

    if (!$podeGerenciarConta) {
        definirFlash('danger', 'Seu perfil nao pode alterar outras contas operacionais.');
        header('Location: porteiro_porteiros.php');
        exit;
    }

    if ($acao === 'excluir') {
        try {
            if ((int) $conta['is_active'] === 0) {
                definirFlash('warning', 'Esta conta ja esta inativa.');
                header('Location: porteiro_porteiros.php');
                exit;
            }

            $resumo = resumoAdministrativo($pdo);

            if (
                $conta['role'] === 'ADMIN' &&
                $resumo['active_admins'] === 1 &&
                $resumo['active_accounts'] > 1
            ) {
                definirFlash('warning', 'Nao e possivel excluir a ultima conta admin enquanto houver outros acessos ativos.');
                header('Location: porteiro_porteiros.php');
                exit;
            }

            $stmt = $pdo->prepare(
                "UPDATE users
                    SET is_active = 0
                  WHERE id = :id
                    AND role IN ('ADMIN', 'PORTER')"
            );
            $stmt->execute([':id' => $contaId]);

            registrarAuditoria($pdo, $usuarioId, 'ACCOUNT_DELETED', 'USER', $contaId, [
                'target_role' => $conta['role'],
                'target_email' => $conta['email'],
                'self_delete' => $contaId === $usuarioId,
            ]);

            if ($contaId === $usuarioId) {
                encerrarSessaoAtualComMensagem('Sua conta foi excluida com seguranca.');
            }

            definirFlash('success', 'Conta excluida com sucesso.');
            header('Location: porteiro_porteiros.php');
            exit;
        } catch (Throwable $erro) {
            definirFlash('danger', 'Nao foi possivel excluir a conta selecionada.');
            header('Location: porteiro_porteiros.php');
            exit;
        }
    }

    $nome = trim($_POST['nome'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $telefone = trim($_POST['telefone'] ?? '');
    $senha = (string) ($_POST['senha'] ?? '');
    $confirmacao = (string) ($_POST['confirmacao_senha'] ?? '');
    $ativo = isset($_POST['is_active']) ? 1 : 0;

    if ($nome === '' || $email === '') {
        definirFlash('warning', 'Preencha nome e e-mail da conta operacional.');
        header('Location: porteiro_porteiros.php?editar=' . $contaId);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        definirFlash('warning', 'Informe um e-mail valido.');
        header('Location: porteiro_porteiros.php?editar=' . $contaId);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id
               FROM users
              WHERE email = :email
                AND id <> :id
              LIMIT 1'
        );
        $stmt->execute([
            ':email' => $email,
            ':id' => $contaId,
        ]);

        if ($stmt->fetch()) {
            definirFlash('danger', 'Este e-mail ja esta em uso por outro usuario.');
            header('Location: porteiro_porteiros.php?editar=' . $contaId);
            exit;
        }

        $resumo = resumoAdministrativo($pdo);

        if (
            $conta['role'] === 'ADMIN' &&
            (int) $conta['is_active'] === 1 &&
            $ativo === 0 &&
            $resumo['active_admins'] === 1 &&
            $resumo['active_accounts'] > 1
        ) {
            definirFlash('warning', 'Nao e possivel desativar a ultima conta admin enquanto houver outros acessos ativos.');
            header('Location: porteiro_porteiros.php?editar=' . $contaId);
            exit;
        }

        if ($senha !== '' || $confirmacao !== '') {
            if ($senha !== $confirmacao) {
                definirFlash('warning', 'A confirmacao de senha nao confere.');
                header('Location: porteiro_porteiros.php?editar=' . $contaId);
                exit;
            }

            $stmt = $pdo->prepare(
                "UPDATE users
                    SET full_name = :full_name,
                        email = :email,
                        phone = :phone,
                        password_hash = :password_hash,
                        is_active = :is_active
                  WHERE id = :id
                    AND role IN ('ADMIN', 'PORTER')"
            );
            $stmt->execute([
                ':full_name' => $nome,
                ':email' => $email,
                ':phone' => $telefone !== '' ? $telefone : null,
                ':password_hash' => password_hash($senha, PASSWORD_DEFAULT),
                ':is_active' => $ativo,
                ':id' => $contaId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE users
                    SET full_name = :full_name,
                        email = :email,
                        phone = :phone,
                        is_active = :is_active
                  WHERE id = :id
                    AND role IN ('ADMIN', 'PORTER')"
            );
            $stmt->execute([
                ':full_name' => $nome,
                ':email' => $email,
                ':phone' => $telefone !== '' ? $telefone : null,
                ':is_active' => $ativo,
                ':id' => $contaId,
            ]);
        }

        registrarAuditoria($pdo, $usuarioId, 'ACCOUNT_UPDATED', 'USER', $contaId, [
            'role' => $conta['role'],
            'email_before' => $conta['email'],
            'email_after' => $email,
            'password_updated' => $senha !== '',
            'self_update' => $contaId === $usuarioId,
        ]);

        if ($contaId === $usuarioId) {
            $_SESSION['usuario_nome'] = $nome;
            $_SESSION['usuario_email'] = $email;

            if ($ativo === 0) {
                encerrarSessaoAtualComMensagem('Sua conta foi desativada e o acesso foi encerrado.');
            }
        }

        definirFlash('success', 'Conta operacional atualizada com sucesso.');
        header('Location: porteiro_porteiros.php');
        exit;
    } catch (Throwable $erro) {
        definirFlash('danger', 'Nao foi possivel salvar a conta operacional.');
        header('Location: porteiro_porteiros.php?editar=' . $contaId);
        exit;
    }
}

$contaEdicao = null;

if ($contaEdicaoId > 0) {
    $contaEdicao = carregarContaOperacional($pdo, $contaEdicaoId);

    if ($contaEdicao === null) {
        definirFlash('danger', 'Conta selecionada para edicao nao foi encontrada.');
        header('Location: porteiro_porteiros.php');
        exit;
    }
}

$stmt = $pdo->query(
    "SELECT id, full_name, email, phone, role, is_active, created_at
       FROM users
      WHERE role IN ('PORTER', 'ADMIN')
      ORDER BY
          CASE WHEN role = 'ADMIN' THEN 0 ELSE 1 END,
          is_active DESC,
          full_name ASC"
);
$contasOperacionais = $stmt->fetchAll();

$modoEdicao = $contaEdicao !== null;
$contasAtivas = count(
    array_filter(
        $contasOperacionais,
        static fn(array $conta): bool => (int) $conta['is_active'] === 1
    )
);

renderizarInicioPagina(
    'Gestao de acessos da portaria',
    'A primeira conta do sistema e ADMIN. Ela cria novos porteiros, enquanto cada porteiro comum pode editar ou excluir apenas a propria conta.',
    ['ativo' => 'porteiros']
);
?>
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <section class="surface-card h-100">
            <div class="card-label">Governanca operacional</div>
            <h2 class="section-title">
                <?= $usuarioEhAdmin ? ($modoEdicao ? 'Editar conta operacional' : 'Cadastrar novo porteiro') : 'Minha conta operacional'; ?>
            </h2>
            <p class="soft-muted">
                <?= $usuarioEhAdmin
                    ? 'A conta admin centraliza o controle da equipe. Os novos cadastros criados aqui entram sempre com o perfil PORTER.'
                    : 'Seu perfil nao pode alterar outras contas. Nesta tela voce gerencia apenas os seus proprios dados de acesso.'; ?>
            </p>
            <div class="divider-soft"></div>
            <div class="mini-stat-list">
                <div class="mini-stat-item">
                    <span class="soft-muted">Contas ativas</span>
                    <strong><?= e((string) $contasAtivas); ?></strong>
                </div>
                <div class="mini-stat-item">
                    <span class="soft-muted">Contas operacionais</span>
                    <strong><?= e((string) count($contasOperacionais)); ?></strong>
                </div>
            </div>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="surface-card form-shell h-100">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div>
                    <div class="card-label">Formulario de acesso</div>
                    <h2 class="section-title mb-1">
                        <?php if (!$usuarioEhAdmin): ?>
                            Atualizar minha conta
                        <?php elseif ($modoEdicao): ?>
                            Atualizar conta selecionada
                        <?php else: ?>
                            Novo porteiro
                        <?php endif; ?>
                    </h2>
                    <p class="soft-muted mb-0">
                        <?php if (!$usuarioEhAdmin): ?>
                            Nome, e-mail, telefone e senha podem ser ajustados apenas para a sua propria conta.
                        <?php elseif ($modoEdicao): ?>
                            Edite dados cadastrais, status de acesso ou redefina a senha quando for necessario.
                        <?php else: ?>
                            Preencha os dados abaixo para liberar um novo acesso de porteiro comum.
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($usuarioEhAdmin && $modoEdicao): ?>
                    <a href="porteiro_porteiros.php" class="btn btn-outline-dark btn-pill">Novo cadastro</a>
                <?php endif; ?>
            </div>

            <form method="post" class="row g-3">
                <input type="hidden" name="acao" value="<?= $usuarioEhAdmin && !$modoEdicao ? 'criar' : 'editar'; ?>">
                <input type="hidden" name="porteiro_id" value="<?= e((string) ($contaEdicao['id'] ?? 0)); ?>">

                <div class="col-12">
                    <label for="nome" class="form-label fw-semibold">Nome completo</label>
                    <input
                        type="text"
                        class="form-control"
                        id="nome"
                        name="nome"
                        value="<?= e($contaEdicao['full_name'] ?? ''); ?>"
                        required
                    >
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label fw-semibold">E-mail</label>
                    <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        value="<?= e($contaEdicao['email'] ?? ''); ?>"
                        required
                    >
                </div>
                <div class="col-md-6">
                    <label for="telefone" class="form-label fw-semibold">Telefone</label>
                    <input
                        type="text"
                        class="form-control"
                        id="telefone"
                        name="telefone"
                        value="<?= e($contaEdicao['phone'] ?? ''); ?>"
                    >
                </div>
                <div class="col-md-6">
                    <label for="senha" class="form-label fw-semibold">
                        <?= $usuarioEhAdmin && !$modoEdicao ? 'Senha' : 'Nova senha'; ?>
                    </label>
                    <input
                        type="password"
                        class="form-control"
                        id="senha"
                        name="senha"
                        <?= $usuarioEhAdmin && !$modoEdicao ? 'required' : ''; ?>
                    >
                </div>
                <div class="col-md-6">
                    <label for="confirmacao_senha" class="form-label fw-semibold">
                        <?= $usuarioEhAdmin && !$modoEdicao ? 'Confirmar senha' : 'Confirmar nova senha'; ?>
                    </label>
                    <input
                        type="password"
                        class="form-control"
                        id="confirmacao_senha"
                        name="confirmacao_senha"
                        <?= $usuarioEhAdmin && !$modoEdicao ? 'required' : ''; ?>
                    >
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            value="1"
                            id="is_active"
                            name="is_active"
                            <?= !isset($contaEdicao['is_active']) || (int) $contaEdicao['is_active'] === 1 ? 'checked' : ''; ?>
                        >
                        <label class="form-check-label fw-semibold" for="is_active">
                            Conta ativa para login
                        </label>
                    </div>
                </div>
                <div class="col-12 d-grid d-sm-flex gap-2">
                    <button type="submit" class="btn btn-dark btn-pill px-4">
                        <?php if (!$usuarioEhAdmin || $modoEdicao): ?>
                            Salvar alteracoes
                        <?php else: ?>
                            Cadastrar porteiro
                        <?php endif; ?>
                    </button>
                    <?php if ($usuarioEhAdmin && $modoEdicao): ?>
                        <a href="porteiro_porteiros.php" class="btn btn-outline-dark btn-pill">Cancelar edicao</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    </div>
</div>

<section class="surface-card">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <div>
            <div class="card-label">Equipe cadastrada</div>
            <h2 class="section-title mb-0">Lista de contas operacionais</h2>
        </div>
        <span class="status-pill status-soft"><?= e((string) count($contasOperacionais)); ?> cadastro(s)</span>
    </div>

    <div class="table-responsive">
        <table class="table table-soft align-middle">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Perfil</th>
                    <th>E-mail</th>
                    <th>Telefone</th>
                    <th>Status</th>
                    <th>Criado em</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($contasOperacionais === []): ?>
                    <tr>
                        <td colspan="7" class="soft-muted">Nenhuma conta operacional cadastrada ate o momento.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($contasOperacionais as $conta): ?>
                        <?php
                        $podeEditarLinha = $usuarioEhAdmin || (int) $conta['id'] === $usuarioId;
                        $podeExcluirLinha = $usuarioEhAdmin || (int) $conta['id'] === $usuarioId;
                        ?>
                        <tr>
                            <td><?= e($conta['full_name']); ?></td>
                            <td>
                                <span class="status-pill <?= $conta['role'] === 'ADMIN' ? 'status-info' : 'status-soft'; ?>">
                                    <?= e($conta['role']); ?>
                                </span>
                            </td>
                            <td><?= e($conta['email']); ?></td>
                            <td><?= e($conta['phone'] ?: 'Nao informado'); ?></td>
                            <td>
                                <span class="status-pill <?= (int) $conta['is_active'] === 1 ? 'status-success' : 'status-warning'; ?>">
                                    <?= (int) $conta['is_active'] === 1 ? 'Ativo' : 'Inativo'; ?>
                                </span>
                            </td>
                            <td><?= e(date('d/m/Y H:i', strtotime($conta['created_at']))); ?></td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end flex-wrap gap-2">
                                    <?php if ($podeEditarLinha): ?>
                                        <a
                                            href="porteiro_porteiros.php?editar=<?= e((string) $conta['id']); ?>"
                                            class="btn btn-primary-soft btn-pill"
                                        >
                                            Editar
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($podeExcluirLinha): ?>
                                        <form
                                            method="post"
                                            onsubmit="return confirm('Confirma a exclusao desta conta?');"
                                            class="d-inline"
                                        >
                                            <input type="hidden" name="acao" value="excluir">
                                            <input type="hidden" name="porteiro_id" value="<?= e((string) $conta['id']); ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-pill">Excluir</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php renderizarFimPagina(); ?>
