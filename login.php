<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/includes/layout.php';

if (isset($_SESSION['usuario_role'])) {
    header('Location: ' . obterRedirectPorPapel($_SESSION['usuario_role']));
    exit;
}

$pdo = obterConexao();
$totalOperadores = (int) $pdo
    ->query("SELECT COUNT(*) FROM users WHERE role IN ('PORTER', 'ADMIN') AND is_active = 1")
    ->fetchColumn();

renderizarInicioPagina(
    'Login unificado',
    'Acesse a operação da portaria ou a experiência do morador com autenticação segura e limitação de tentativas.',
    ['ativo' => 'login']
);
?>
<div class="row g-4 align-items-stretch">
    <div class="col-lg-5">
        <section class="surface-card h-100">
            <div class="eyebrow">Acesso seguro</div>
            <h2 class="section-title">Pronto para operar sem papel</h2>
            <p class="soft-muted">
                O UpWay substitui livros de portaria por uma jornada digital clara:
                encomenda recebida, morador notificado, token emitido e retirada validada.
            </p>
            <div class="divider-soft"></div>
            <ul class="list-clean soft-muted">
                <li>Prepared Statements em toda consulta sensível.</li>
                <li>Senha protegida com <code>password_hash()</code> e <code>password_verify()</code>.</li>
                <li>Bloqueio temporário após múltiplas tentativas inválidas.</li>
            </ul>
            <div class="divider-soft"></div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-dark btn-pill" href="cadastro_morador.php">Sou morador</a>
                <?php if ($totalOperadores === 0): ?>
                    <a class="btn btn-primary-soft btn-pill" href="cadastro_porteiro.php">Criar conta admin</a>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="surface-card form-shell h-100">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                <div>
                    <div class="card-label">Entrar no sistema</div>
                    <h2 class="section-title mb-1">Bem-vindo ao painel UpWay</h2>
                    <p class="soft-muted mb-0">Use o e-mail cadastrado e sua senha para continuar.</p>
                </div>
                <span class="status-pill status-soft">XAMPP / PHP 8.x / MySQL</span>
            </div>

            <form method="post" action="autenticar.php" class="row g-3">
                <div class="col-12">
                    <label for="email" class="form-label fw-semibold">E-mail</label>
                    <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        placeholder="nome@condominio.com"
                        required
                    >
                </div>
                <div class="col-12">
                    <label for="senha" class="form-label fw-semibold">Senha</label>
                    <input
                        type="password"
                        class="form-control"
                        id="senha"
                        name="senha"
                        placeholder="Sua senha segura"
                        required
                    >
                </div>
                <div class="col-12 d-grid d-sm-flex gap-2">
                    <button type="submit" class="btn btn-dark btn-pill px-4">Entrar</button>
                    <a href="cadastro_morador.php" class="btn btn-outline-dark btn-pill">Cadastrar morador</a>
                </div>
            </form>
        </section>
    </div>
</div>
<?php renderizarFimPagina(); ?>
