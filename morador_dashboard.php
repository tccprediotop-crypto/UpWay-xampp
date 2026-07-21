<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/verifica_sessao.php';
require_once __DIR__ . '/includes/layout.php';

requerPapel(['RESIDENT']);

$pdo = obterConexao();
$moradorId = (int) $_SESSION['usuario_id'];

$stmtPendentes = $pdo->prepare(
    "SELECT
        p.id,
        p.carrier,
        p.tracking_code,
        p.status,
        p.received_at,
        pt.token_code,
        pt.expires_at
     FROM packages p
     LEFT JOIN pickup_tokens pt ON pt.package_id = p.id AND pt.used_at IS NULL
     WHERE p.resident_id = :resident_id
       AND p.status IN ('RECEIVED', 'NOTIFIED', 'TOKEN_GENERATED')
     ORDER BY p.received_at DESC"
);
$stmtPendentes->execute([':resident_id' => $moradorId]);
$encomendasAtivas = $stmtPendentes->fetchAll();

$stmtHistorico = $pdo->prepare('SELECT COUNT(*) FROM packages WHERE resident_id = :resident_id');
$stmtHistorico->execute([':resident_id' => $moradorId]);
$totalHistorico = (int) $stmtHistorico->fetchColumn();

$stmtTokens = $pdo->prepare(
    "SELECT COUNT(*)
       FROM packages p
       INNER JOIN pickup_tokens pt ON pt.package_id = p.id
      WHERE p.resident_id = :resident_id
        AND p.status = 'TOKEN_GENERATED'
        AND pt.used_at IS NULL
        AND pt.expires_at > NOW()"
);
$stmtTokens->execute([':resident_id' => $moradorId]);
$tokensAtivos = (int) $stmtTokens->fetchColumn();

$stmtNotificacoes = $pdo->prepare(
    'SELECT title, message, channel, created_at
       FROM notifications
      WHERE user_id = :user_id
      ORDER BY created_at DESC
      LIMIT 5'
);
$stmtNotificacoes->execute([':user_id' => $moradorId]);
$notificacoes = $stmtNotificacoes->fetchAll();

renderizarInicioPagina(
    'Dashboard do morador',
    'Uma visão clara das encomendas aguardando retirada, seus tokens ativos e os alertas mais recentes da portaria.',
    ['ativo' => 'dashboard']
);
?>
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <section class="metric-card">
            <div class="card-label">Pendentes</div>
            <div class="metric-value"><?= e((string) count($encomendasAtivas)); ?></div>
            <div class="metric-helper">Encomendas aguardando ação.</div>
        </section>
    </div>
    <div class="col-md-4">
        <section class="metric-card">
            <div class="card-label">Tokens ativos</div>
            <div class="metric-value"><?= e((string) $tokensAtivos); ?></div>
            <div class="metric-helper">Prontos para apresentar à portaria.</div>
        </section>
    </div>
    <div class="col-md-4">
        <section class="metric-card">
            <div class="card-label">Histórico total</div>
            <div class="metric-value"><?= e((string) $totalHistorico); ?></div>
            <div class="metric-helper">Encomendas já registradas em seu nome.</div>
        </section>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <section class="surface-card h-100">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                <div>
                    <div class="card-label">Encomendas ativas</div>
                    <h2 class="section-title mb-0">Gere o token quando estiver indo à portaria</h2>
                </div>
                <a href="morador_token.php" class="btn btn-dark btn-pill">Abrir central de tokens</a>
            </div>
            <div class="table-responsive">
                <table class="table table-soft align-middle">
                    <thead>
                        <tr>
                            <th>Transportadora</th>
                            <th>Rastreio</th>
                            <th>Status</th>
                            <th>Recebida em</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($encomendasAtivas === []): ?>
                            <tr>
                                <td colspan="5" class="soft-muted">Nenhuma encomenda ativa no momento.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($encomendasAtivas as $encomenda): ?>
                                <tr>
                                    <td><?= e($encomenda['carrier']); ?></td>
                                    <td><?= e($encomenda['tracking_code'] ?: 'Sem código'); ?></td>
                                    <td>
                                        <span class="status-pill <?= e(classeStatusPacote($encomenda['status'])); ?>">
                                            <?= e(formatarStatusPacote($encomenda['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?= e(date('d/m/Y H:i', strtotime($encomenda['received_at']))); ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-primary-soft btn-pill" href="morador_token.php?package_id=<?= e((string) $encomenda['id']); ?>">
                                            Gerar token
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="surface-card h-100">
            <div class="card-label">Notificações recentes</div>
            <h2 class="section-title">Alertas do sistema</h2>
            <ul class="list-clean">
                <?php if ($notificacoes === []): ?>
                    <li class="soft-muted">Ainda não há notificações para exibir.</li>
                <?php else: ?>
                    <?php foreach ($notificacoes as $notificacao): ?>
                        <li>
                            <div class="fw-semibold"><?= e($notificacao['title']); ?></div>
                            <div class="soft-muted"><?= e($notificacao['message']); ?></div>
                            <small class="soft-muted">
                                <?= e($notificacao['channel']); ?> • <?= e(date('d/m/Y H:i', strtotime($notificacao['created_at']))); ?>
                            </small>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </section>
    </div>
</div>
<?php renderizarFimPagina(); ?>
