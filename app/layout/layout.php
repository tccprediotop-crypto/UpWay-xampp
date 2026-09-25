<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/funcoes.php';

function linksNavegacao(): array
{
    $papel = $_SESSION['usuario_role'] ?? null;

    if ($papel === 'RESIDENT') {
        return [
            'dashboard' => ['label' => 'Dashboard', 'href' => 'morador_dashboard.php'],
            'token' => ['label' => 'Meus tokens', 'href' => 'morador_token.php'],
        ];
    }

    if (in_array($papel, ['PORTER', 'ADMIN'], true)) {
        return [
            'dashboard' => ['label' => 'Overview', 'href' => 'porteiro_dashboard.php'],
            'nova_encomenda' => ['label' => 'Nova encomenda', 'href' => 'porteiro_nova_encomenda.php'],
            'retirada' => ['label' => 'Retirada', 'href' => 'porteiro_retirada.php'],
            'porteiros' => ['label' => 'Porteiros', 'href' => 'porteiro_porteiros.php'],
        ];
    }

    return [];
}

function renderizarInicioPagina(string $titulo, string $subtitulo, array $opcoes = []): void
{
    $ativo = $opcoes['ativo'] ?? '';
    $mostrarNavegacao = $opcoes['mostrar_navegacao'] ?? true;
    $flash = consumirFlash();
    $links = linksNavegacao();
    $logado = isset($_SESSION['usuario_id']);
    $usuarioNome = $_SESSION['usuario_nome'] ?? 'Visitante';
    $usuarioPapel = $_SESSION['usuario_role'] ?? 'PUBLIC';
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo); ?> | UpWay</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="site-header-inner">
            <a class="brand-mini" href="index.php">
                <span class="wordmark-mini">Up<span>Way</span></span>
                <span class="tagline-mini">Seu caminho, mais fácil.</span>
            </a>

            <?php if ($mostrarNavegacao && $links !== []): ?>
                <nav class="nav nav-pills nav-soft">
                    <?php foreach ($links as $chave => $link): ?>
                        <a
                            class="nav-link <?= $ativo === $chave ? 'active' : ''; ?>"
                            href="<?= e($link['href']); ?>"
                        >
                            <?= e($link['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <?php if ($logado): ?>
                <div class="header-actions">
                    <div class="profile-chip">
                        <span class="profile-dot"></span>
                        <div>
                            <strong><?= e($usuarioNome); ?></strong>
                            <small><?= e($usuarioPapel); ?></small>
                        </div>
                    </div>
                    <a class="btn btn-outline-dark btn-pill" href="logout.php">Sair</a>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="page-body">
        <div class="page-head">
            <div class="eyebrow">UpWay / Gestão de encomendas</div>
            <h1 class="page-title"><?= e($titulo); ?></h1>
            <p class="page-subtitle"><?= e($subtitulo); ?></p>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= e($flash['tipo']); ?> alert-soft mb-4" role="alert">
                <?= e($flash['mensagem']); ?>
            </div>
        <?php endif; ?>
    <?php
}

function renderizarFimPagina(): void
{
    ?>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
    document.querySelectorAll('.pw-toggle').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var campo = document.getElementById(botao.dataset.target || '');
            var icone = botao.querySelector('i');

            if (!campo) {
                return;
            }

            var visivel = campo.type === 'text';
            campo.type = visivel ? 'password' : 'text';

            if (icone) {
                icone.className = visivel ? 'bi bi-eye' : 'bi bi-eye-slash';
            }

            botao.setAttribute('aria-label', visivel ? 'Mostrar senha' : 'Ocultar senha');
        });
    });
    </script>
</body>
</html>
    <?php
}
