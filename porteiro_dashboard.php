<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/verifica_sessao.php';
require_once __DIR__ . '/includes/layout.php';

requerPapel(['PORTER', 'ADMIN']);

$pdo = obterConexao();

$metricas = [
    'pendentes_retirada' => (int) $pdo
        ->query("SELECT COUNT(*) FROM packages WHERE status IN ('NOTIFIED', 'TOKEN_GENERATED')")
        ->fetchColumn(),
    'moradores' => (int) $pdo
        ->query("SELECT COUNT(*) FROM users WHERE role = 'RESIDENT' AND is_active = 1")
        ->fetchColumn(),
    'recebidas_hoje' => (int) $pdo
        ->query('SELECT COUNT(*) FROM packages WHERE DATE(received_at) = CURDATE()')
        ->fetchColumn(),
    'tokens_ativos' => (int) $pdo
        ->query("SELECT COUNT(*) FROM packages WHERE status = 'TOKEN_GENERATED'")
        ->fetchColumn(),
    'total_encomendas' => (int) $pdo
        ->query('SELECT COUNT(*) FROM packages')
        ->fetchColumn(),
];

$statusBrutos = [
    'RECEIVED' => 0,
    'NOTIFIED' => 0,
    'TOKEN_GENERATED' => 0,
    'PICKED_UP' => 0,
];

$stmt = $pdo->query('SELECT status, COUNT(*) AS total FROM packages GROUP BY status');
foreach ($stmt->fetchAll() as $linha) {
    $statusBrutos[$linha['status']] = (int) $linha['total'];
}

$stmt = $pdo->prepare(
    'SELECT
        p.id,
        p.carrier,
        p.tracking_code,
        p.status,
        p.received_at,
        u.full_name AS resident_name,
        t.name AS tower_name,
        un.unit_number
     FROM packages p
     INNER JOIN users u ON u.id = p.resident_id
     LEFT JOIN units un ON un.id = u.unit_id
     LEFT JOIN towers t ON t.id = un.tower_id
     ORDER BY p.received_at DESC
     LIMIT 8'
);
$stmt->execute();
$ultimasEncomendas = $stmt->fetchAll();

$proporcaoTokens = $metricas['total_encomendas'] > 0
    ? (int) round(($metricas['tokens_ativos'] / $metricas['total_encomendas']) * 360)
    : 0;

renderizarInicioPagina(
    'Overview da portaria',
    'Painel inspirado em dashboards minimalistas: visão rápida do volume operacional, tokens ativos e fila de entregas.',
    ['ativo' => 'dashboard']
);
?>
<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl-3">
        <section class="metric-card">
            <div class="card-label">Pendentes de retirada</div>
            <div class="metric-value"><?= e((string) $metricas['pendentes_retirada']); ?></div>
            <div class="metric-helper">Encomendas aguardando o morador na portaria.</div>
        </section>
    </div>
    <div class="col-md-6 col-xl-3">
        <section class="metric-card">
            <div class="card-label">Moradores cadastrados</div>
            <div class="metric-value"><?= e((string) $metricas['moradores']); ?></div>
            <div class="metric-helper">Base ativa pronta para receber notificações.</div>
        </section>
    </div>
    <div class="col-md-6 col-xl-3">
        <section class="metric-card">
            <div class="card-label">Pacotes recebidos hoje</div>
            <div class="metric-value"><?= e((string) $metricas['recebidas_hoje']); ?></div>
            <div class="metric-helper">Entradas registradas desde 00:00.</div>
        </section>
    </div>
    <div class="col-md-6 col-xl-3">
        <section class="metric-card">
            <div class="card-label">Tokens ativos</div>
            <div class="metric-value"><?= e((string) $metricas['tokens_ativos']); ?></div>
            <div class="metric-helper">Moradores a caminho para retirar.</div>
        </section>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-5">
        <section class="surface-card h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <div class="card-label">Ritmo de retirada</div>
                    <h2 class="section-title mb-0">Tokenização em andamento</h2>
                </div>
                <a href="porteiro_retirada.php" class="btn btn-primary-soft btn-pill">Validar retirada</a>
            </div>
            <div class="donut-indicator" style="--progress: <?= e((string) $proporcaoTokens); ?>deg;">
                <div class="text-center">
                    <strong><?= e((string) $metricas['tokens_ativos']); ?></strong>
                    <span class="soft-muted">ativos</span>
                </div>
            </div>
            <p class="soft-muted mb-0 text-center">
                Total de encomendas no estado <code>TOKEN_GENERATED</code> comparado ao histórico geral.
            </p>
        </section>
    </div>
    <div class="col-xl-7">
        <section class="surface-card h-100">
            <div class="card-label">Distribuição por status</div>
            <h2 class="section-title">Máquina de estados do UpWay</h2>
            <div class="mini-stat-list">
                <?php foreach ($statusBrutos as $status => $total): ?>
                    <?php
                    $percentual = $metricas['total_encomendas'] > 0
                        ? (int) round(($total / $metricas['total_encomendas']) * 100)
                        : 0;
                    ?>
                    <div class="mini-stat-item">
                        <div>
                            <div class="fw-semibold"><?= e(formatarStatusPacote($status)); ?></div>
                            <small class="soft-muted"><?= e((string) $total); ?> registro(s)</small>
                        </div>
                        <div class="bar-track">
                            <div class="bar-fill" style="width: <?= e((string) $percentual); ?>%;"></div>
                        </div>
                        <div class="fw-semibold"><?= e((string) $percentual); ?>%</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>

<section class="surface-card">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <div>
            <div class="card-label">Últimas movimentações</div>
            <h2 class="section-title mb-0">Encomendas cadastradas recentemente</h2>
        </div>
        <a href="porteiro_nova_encomenda.php" class="btn btn-dark btn-pill">Nova encomenda</a>
    </div>
    <div class="table-responsive">
        <table class="table table-soft align-middle">
            <thead>
                <tr>
                    <th>Morador</th>
                    <th>Unidade</th>
                    <th>Transportadora</th>
                    <th>Rastreio</th>
                    <th>Status</th>
                    <th>Recebimento</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($ultimasEncomendas === []): ?>
                    <tr>
                        <td colspan="6" class="soft-muted">Nenhuma encomenda cadastrada ainda.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ultimasEncomendas as $encomenda): ?>
                        <tr>
                            <td><?= e($encomenda['resident_name']); ?></td>
                            <td><?= e(trim(($encomenda['tower_name'] ?? '') . ' ' . ($encomenda['unit_number'] ?? ''))); ?></td>
                            <td><?= e($encomenda['carrier']); ?></td>
                            <td><?= e($encomenda['tracking_code'] ?: 'Sem código'); ?></td>
                            <td>
                                <span class="status-pill <?= e(classeStatusPacote($encomenda['status'])); ?>">
                                    <?= e(formatarStatusPacote($encomenda['status'])); ?>
                                </span>
                            </td>
                            <td><?= e(date('d/m/Y H:i', strtotime($encomenda['received_at']))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php renderizarFimPagina(); ?>
