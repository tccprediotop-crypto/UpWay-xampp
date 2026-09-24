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
    ['ativo' => 'login', 'mostrar_navegacao' => false]
);
?>
<div class="row justify-content-center">
    <div class="col-lg-6 col-xl-5">
        <div class="text-center mb-4">
            <div class="auth-seal"><i class="bi bi-box-seam"></i></div>
            <h2 class="auth-title">Bem-vindo de volta!</h2>
            <p class="soft-muted mb-0">Faça login para continuar</p>
        </div>

        <section class="form-shell">
            <form method="post" action="autenticar.php">
                <div class="mb-3">
                    <label for="email" class="form-label fw-semibold">E-mail</label>
                    <div class="input-icon">
                        <i class="bi bi-envelope"></i>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            placeholder="nome@condominio.com"
                            required
                        >
                    </div>
                </div>
                <div class="mb-4">
                    <label for="senha" class="form-label fw-semibold">Senha</label>
                    <div class="input-icon">
                        <i class="bi bi-lock"></i>
                        <input
                            type="password"
                            class="form-control"
                            id="senha"
                            name="senha"
                            placeholder="Sua senha segura"
                            required
                        >
                        <button type="button" class="pw-toggle" data-target="senha" aria-label="Mostrar senha">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-uw w-100">Entrar</button>
            </form>

            <div class="auth-foot">
                Ainda não tem uma conta? <a href="cadastro_morador.php">Cadastre-se</a>
                <?php if ($totalOperadores === 0): ?>
                    <div class="mt-2">
                        <a class="fw-semibold" href="cadastro_porteiro.php">Criar a primeira conta administrativa</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
<div class="row justify-content-center mt-4">
    <div class="col-lg-8">
        <ul class="list-clean soft-muted security-notes">
            <li><i class="bi bi-shield-lock me-1"></i> Prepared Statements em toda consulta sensível</li>
            <li><i class="bi bi-key me-1"></i> Senha protegida com <code>password_hash()</code></li>
            <li><i class="bi bi-hourglass-split me-1"></i> Bloqueio temporário após tentativas inválidas</li>
        </ul>
    </div>
</div>
<?php renderizarFimPagina(); ?>
