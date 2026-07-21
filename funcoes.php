<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function e(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function definirFlash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'] = [
        'tipo' => $tipo,
        'mensagem' => $mensagem,
    ];
}

function consumirFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function obterIpAtual(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function tabelaExiste(PDO $pdo, string $nomeTabela): bool
{
    static $cache = [];

    if (array_key_exists($nomeTabela, $cache)) {
        return $cache[$nomeTabela];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT 1
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name = :table_name
              LIMIT 1'
        );
        $stmt->execute([':table_name' => $nomeTabela]);
        $cache[$nomeTabela] = (bool) $stmt->fetchColumn();
    } catch (Throwable $erro) {
        $cache[$nomeTabela] = false;
    }

    return $cache[$nomeTabela];
}

function registrarAuditoria(
    PDO $pdo,
    ?int $autorId,
    string $acao,
    string $entidade,
    ?int $entidadeId,
    array $metadados = []
): void {
    if (!tabelaExiste($pdo, 'audit_logs')) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, metadata_json, ip_address)
         VALUES (:actor_user_id, :action, :entity_type, :entity_id, :metadata_json, :ip_address)'
    );

    $stmt->execute([
        ':actor_user_id' => $autorId,
        ':action' => $acao,
        ':entity_type' => $entidade,
        ':entity_id' => $entidadeId,
        ':metadata_json' => json_encode($metadados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip_address' => obterIpAtual(),
    ]);
}

function tentativasExcedidas(PDO $pdo, string $identificador, string $ip): bool
{
    if (!tabelaExiste($pdo, 'login_attempts')) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) 
           FROM login_attempts
          WHERE login_identifier = :login_identifier
            AND ip_address = :ip_address
            AND was_successful = 0
            AND created_at >= (NOW() - INTERVAL 15 MINUTE)'
    );

    $stmt->execute([
        ':login_identifier' => $identificador,
        ':ip_address' => $ip,
    ]);

    return (int) $stmt->fetchColumn() >= 5;
}

function registrarTentativaLogin(PDO $pdo, string $identificador, string $ip, bool $sucesso): void
{
    if (!tabelaExiste($pdo, 'login_attempts')) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (login_identifier, ip_address, was_successful)
         VALUES (:login_identifier, :ip_address, :was_successful)'
    );

    $stmt->execute([
        ':login_identifier' => $identificador,
        ':ip_address' => $ip,
        ':was_successful' => $sucesso ? 1 : 0,
    ]);
}

function gerarTokenSeguro(int $tamanho = 8): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $indiceMaximo = strlen($alfabeto) - 1;
    $token = '';

    for ($i = 0; $i < $tamanho; $i++) {
        $token .= $alfabeto[random_int(0, $indiceMaximo)];
    }

    return $token;
}

function dispararNotificacoes(PDO $pdo, array $morador, array $encomenda): void
{
    $canais = [
        'IN_APP' => 'SENT',
    ];

    if (!empty($morador['phone']) && (int) ($morador['whatsapp_enabled'] ?? 1) === 1) {
        $canais['WHATSAPP'] = 'PENDING';
    }

    if ((int) ($morador['browser_push_enabled'] ?? 0) === 1) {
        $canais['PUSH'] = 'PENDING';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, package_id, channel, title, message, delivery_status, metadata_json, sent_at)
         VALUES (:user_id, :package_id, :channel, :title, :message, :delivery_status, :metadata_json, :sent_at)'
    );

    foreach ($canais as $canal => $status) {
        $stmt->execute([
            ':user_id' => $morador['id'],
            ':package_id' => $encomenda['id'],
            ':channel' => $canal,
            ':title' => 'Nova encomenda disponível',
            ':message' => sprintf(
                'Sua encomenda da transportadora %s foi recebida na portaria.',
                $encomenda['carrier']
            ),
            ':delivery_status' => $status,
            ':metadata_json' => json_encode([
                'tracking_code' => $encomenda['tracking_code'],
                'pickup_link' => 'morador_token.php?package_id=' . $encomenda['id'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':sent_at' => $status === 'SENT' ? date('Y-m-d H:i:s') : null,
        ]);
    }
}

function obterRedirectPorPapel(string $papel): string
{
    if (in_array($papel, ['PORTER', 'ADMIN'], true)) {
        return 'porteiro_dashboard.php';
    }

    return 'morador_dashboard.php';
}

function formatarStatusPacote(string $status): string
{
    return match ($status) {
        'RECEIVED' => 'Recebida',
        'NOTIFIED' => 'Notificada',
        'TOKEN_GENERATED' => 'Token ativo',
        'PICKED_UP' => 'Retirada',
        default => $status,
    };
}

function classeStatusPacote(string $status): string
{
    return match ($status) {
        'RECEIVED' => 'status-soft',
        'NOTIFIED' => 'status-info',
        'TOKEN_GENERATED' => 'status-warning',
        'PICKED_UP' => 'status-success',
        default => 'status-soft',
    };
}

function normalizarIdentificador(string $valor): string
{
    $valor = trim($valor);

    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($valor, 'UTF-8');
    }

    return strtoupper($valor);
}
