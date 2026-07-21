<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/verifica_sessao.php';
require_once __DIR__ . '/includes/layout.php';

requerPapel(['RESIDENT']);

$pdo = obterConexao();
$moradorId = (int) $_SESSION['usuario_id'];
$packageId = isset($_GET['package_id']) ? (int) $_GET['package_id'] : 0;
$tokenVisualizacao = null;
$dadosPacote = null;

if ($packageId > 0) {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT
                p.id,
                p.carrier,
                p.tracking_code,
                p.description,
                p.status,
                p.received_at,
                pt.id AS token_id,
                pt.token_code,
                pt.qr_payload,
                pt.expires_at,
                pt.used_at
             FROM packages p
             LEFT JOIN pickup_tokens pt ON pt.package_id = p.id
             WHERE p.id = :package_id
               AND p.resident_id = :resident_id
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([
            ':package_id' => $packageId,
            ':resident_id' => $moradorId,
        ]);
        $dadosPacote = $stmt->fetch();

        if ($dadosPacote === false) {
            throw new RuntimeException('Encomenda não encontrada para este morador.');
        }

        if ($dadosPacote['status'] === 'PICKED_UP') {
            throw new RuntimeException('Esta encomenda já foi retirada e não aceita novo token.');
        }

        $tokenValido = $dadosPacote['token_code'] !== null
            && $dadosPacote['used_at'] === null
            && strtotime((string) $dadosPacote['expires_at']) > time();

        if (!$tokenValido) {
            $tokenGerado = null;

            for ($tentativa = 0; $tentativa < 5; $tentativa++) {
                $novoToken = gerarTokenSeguro(8);
                $novoPayload = 'upway://pickup/' . $novoToken;
                $novaExpiracao = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                try {
                    if ($dadosPacote['token_id'] !== null) {
                        $stmt = $pdo->prepare(
                            'UPDATE pickup_tokens
                                SET token_code = :token_code,
                                    qr_payload = :qr_payload,
                                    expires_at = :expires_at,
                                    generated_at = NOW(),
                                    used_at = NULL,
                                    validated_by_user_id = NULL,
                                    generated_by_user_id = :generated_by_user_id
                              WHERE id = :id'
                        );
                        $stmt->execute([
                            ':token_code' => $novoToken,
                            ':qr_payload' => $novoPayload,
                            ':expires_at' => $novaExpiracao,
                            ':generated_by_user_id' => $moradorId,
                            ':id' => $dadosPacote['token_id'],
                        ]);
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO pickup_tokens (
                                package_id,
                                generated_by_user_id,
                                token_code,
                                qr_payload,
                                expires_at
                             ) VALUES (
                                :package_id,
                                :generated_by_user_id,
                                :token_code,
                                :qr_payload,
                                :expires_at
                             )'
                        );
                        $stmt->execute([
                            ':package_id' => $packageId,
                            ':generated_by_user_id' => $moradorId,
                            ':token_code' => $novoToken,
                            ':qr_payload' => $novoPayload,
                            ':expires_at' => $novaExpiracao,
                        ]);
                    }

                    $tokenGerado = [
                        'token_code' => $novoToken,
                        'qr_payload' => $novoPayload,
                        'expires_at' => $novaExpiracao,
                    ];
                    break;
                } catch (PDOException $erro) {
                    if ((int) ($erro->errorInfo[1] ?? 0) === 1062) {
                        continue;
                    }

                    throw $erro;
                }
            }

            if ($tokenGerado === null) {
                throw new RuntimeException('Não foi possível gerar um token exclusivo após múltiplas tentativas.');
            }

            $stmt = $pdo->prepare(
                "UPDATE packages
                    SET status = 'TOKEN_GENERATED'
                  WHERE id = :id"
            );
            $stmt->execute([':id' => $packageId]);

            registrarAuditoria($pdo, $moradorId, 'TOKEN_GENERATED', 'PACKAGE', $packageId, [
                'token_code' => $tokenGerado['token_code'],
            ]);

            $dadosPacote['status'] = 'TOKEN_GENERATED';
            $dadosPacote['token_code'] = $tokenGerado['token_code'];
            $dadosPacote['qr_payload'] = $tokenGerado['qr_payload'];
            $dadosPacote['expires_at'] = $tokenGerado['expires_at'];
            $dadosPacote['used_at'] = null;
        } elseif ($dadosPacote['status'] !== 'TOKEN_GENERATED') {
            $stmt = $pdo->prepare(
                "UPDATE packages
                    SET status = 'TOKEN_GENERATED'
                  WHERE id = :id"
            );
            $stmt->execute([':id' => $packageId]);
            $dadosPacote['status'] = 'TOKEN_GENERATED';
        }

        $pdo->commit();
        $tokenVisualizacao = $dadosPacote;
    } catch (Throwable $erro) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        definirFlash('danger', $erro->getMessage());
        header('Location: morador_token.php');
        exit;
    }
}

$stmt = $pdo->prepare(
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
$stmt->execute([':resident_id' => $moradorId]);
$encomendasAtivas = $stmt->fetchAll();

renderizarInicioPagina(
    'Central de tokens',
    'Gere ou reaproveite um token temporário para apresentar na retirada. O QR representa o payload seguro da entrega.',
    ['ativo' => 'token']
);
?>
<div class="row g-4">
    <div class="col-xl-7">
        <section class="surface-card h-100">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div>
                    <div class="card-label">Meus pacotes</div>
                    <h2 class="section-title mb-1">Selecione uma encomenda para emitir o token</h2>
                    <p class="soft-muted mb-0">Cada token expira em 15 minutos e fica associado a apenas um pacote.</p>
                </div>
                <a href="morador_dashboard.php" class="btn btn-outline-dark btn-pill">Voltar ao dashboard</a>
            </div>
            <div class="table-responsive">
                <table class="table table-soft align-middle">
                    <thead>
                        <tr>
                            <th>Transportadora</th>
                            <th>Rastreio</th>
                            <th>Status</th>
                            <th>Recebimento</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($encomendasAtivas === []): ?>
                            <tr>
                                <td colspan="5" class="soft-muted">Nenhuma encomenda disponível para token no momento.</td>
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
                                            <?= $encomenda['status'] === 'TOKEN_GENERATED' ? 'Ver token' : 'Gerar token'; ?>
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
    <div class="col-xl-5">
        <section class="surface-card h-100">
            <div class="card-label">Token ativo</div>
            <h2 class="section-title">Retirada segura</h2>

            <?php if ($tokenVisualizacao === null): ?>
                <div class="token-box">----</div>
                <p class="soft-muted mt-3 mb-0">
                    Escolha uma encomenda na tabela ao lado para gerar o código de retirada e seu QR correspondente.
                </p>
            <?php else: ?>
                <div class="token-box"><?= e($tokenVisualizacao['token_code']); ?></div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <div class="surface-card h-100">
                            <div class="card-label">Payload QR</div>
                            <div class="fw-semibold"><?= e($tokenVisualizacao['qr_payload']); ?></div>
                            <small class="soft-muted">
                                Expira em <?= e(date('d/m/Y H:i', strtotime($tokenVisualizacao['expires_at']))); ?>
                            </small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="surface-card h-100 text-center">
                            <div class="card-label">QR dinâmico</div>
                            <img
                                src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= e(rawurlencode($tokenVisualizacao['qr_payload'])); ?>"
                                alt="QR Code da retirada"
                                class="img-fluid rounded-4 border"
                            >
                        </div>
                    </div>
                </div>
                <div class="divider-soft"></div>
                <p class="soft-muted mb-0">
                    Pacote: <strong><?= e($tokenVisualizacao['carrier']); ?></strong>
                    <?= $tokenVisualizacao['tracking_code'] ? '• ' . e($tokenVisualizacao['tracking_code']) : ''; ?>
                </p>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php renderizarFimPagina(); ?>
