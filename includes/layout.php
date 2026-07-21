<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

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

    return [
        'login' => ['label' => 'Login', 'href' => 'login.php'],
        'cadastro_morador' => ['label' => 'Cadastro morador', 'href' => 'cadastro_morador.php'],
        'cadastro_porteiro' => ['label' => 'Bootstrap portaria', 'href' => 'cadastro_porteiro.php'],
    ];
}

function renderizarInicioPagina(string $titulo, string $subtitulo, array $opcoes = []): void
{
    $ativo = $opcoes['ativo'] ?? '';
    $mostrarNavegacao = $opcoes['mostrar_navegacao'] ?? true;
    $flash = consumirFlash();
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
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="bg-orb orb-left"></div>
    <div class="bg-orb orb-right"></div>
    <main class="page-shell">
        <div class="container py-4 py-lg-5">
            <div class="app-frame">
                <header class="topbar">
                    <div>
                        <div class="eyebrow">UpWay / Gestão de encomendas</div>
                        <h1 class="page-title"><?= e($titulo); ?></h1>
                        <p class="page-subtitle mb-0"><?= e($subtitulo); ?></p>
                    </div>
                    <div class="topbar-actions">
                        <div class="profile-chip">
                            <span class="profile-dot"></span>
                            <div>
                                <strong><?= e($usuarioNome); ?></strong>
                                <small><?= e($usuarioPapel); ?></small>
                            </div>
                        </div>
                        <?php if (isset($_SESSION['usuario_id'])): ?>
                            <a class="btn btn-outline-dark btn-pill" href="logout.php">Sair</a>
                        <?php endif; ?>
                    </div>
                </header>

                <?php if ($mostrarNavegacao): ?>
                    <nav class="nav nav-pills nav-soft mb-4">
                        <?php foreach (linksNavegacao() as $chave => $link): ?>
                            <a
                                class="nav-link <?= $ativo === $chave ? 'active' : ''; ?>"
                                href="<?= e($link['href']); ?>"
                            >
                                <?= e($link['label']); ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>

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
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
    <?php
}
