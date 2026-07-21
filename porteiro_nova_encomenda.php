<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/verifica_sessao.php';
require_once __DIR__ . '/includes/layout.php';

requerPapel(['PORTER', 'ADMIN']);

$pdo = obterConexao();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $moradorId = (int) ($_POST['morador_id'] ?? 0);
    $carrier = trim($_POST['carrier'] ?? '');
    $trackingCode = trim($_POST['tracking_code'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');

    if ($moradorId <= 0 || $carrier === '') {
        definirFlash('warning', 'Selecione o morador e a transportadora da encomenda.');
        header('Location: porteiro_nova_encomenda.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT id, full_name, phone, browser_push_enabled, whatsapp_enabled
               FROM users
              WHERE id = :id
                AND role = 'RESIDENT'
                AND is_active = 1
              LIMIT 1"
        );
        $stmt->execute([':id' => $moradorId]);
        $morador = $stmt->fetch();

        if ($morador === false) {
            $pdo->rollBack();
            definirFlash('danger', 'Morador não encontrado para associação da encomenda.');
            header('Location: porteiro_nova_encomenda.php');
            exit;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO packages (
                resident_id,
                received_by_user_id,
                tracking_code,
                carrier,
                description,
                status,
                received_at,
                notified_at
            ) VALUES (
                :resident_id,
                :received_by_user_id,
                :tracking_code,
                :carrier,
                :description,
                'NOTIFIED',
                NOW(),
                NOW()
            )"
        );

        $stmt->execute([
            ':resident_id' => $moradorId,
            ':received_by_user_id' => $_SESSION['usuario_id'],
            ':tracking_code' => $trackingCode !== '' ? $trackingCode : null,
            ':carrier' => $carrier,
            ':description' => $descricao !== '' ? $descricao : null,
        ]);

        $encomendaId = (int) $pdo->lastInsertId();

        $encomenda = [
            'id' => $encomendaId,
            'carrier' => $carrier,
            'tracking_code' => $trackingCode,
        ];

        dispararNotificacoes($pdo, $morador, $encomenda);
        registrarAuditoria($pdo, (int) $_SESSION['usuario_id'], 'PACKAGE_CREATED', 'PACKAGE', $encomendaId, [
            'resident_id' => $moradorId,
            'carrier' => $carrier,
            'tracking_code' => $trackingCode,
        ]);

        $pdo->commit();
        definirFlash('success', 'Encomenda registrada e morador notificado.');
        header('Location: porteiro_dashboard.php');
        exit;
    } catch (Throwable $erro) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        definirFlash('danger', 'Falha ao registrar a encomenda.');
        header('Location: porteiro_nova_encomenda.php');
        exit;
    }
}

$stmt = $pdo->query(
    "SELECT u.id, u.full_name, t.name AS tower_name, un.unit_number
       FROM users u
       LEFT JOIN units un ON un.id = u.unit_id
       LEFT JOIN towers t ON t.id = un.tower_id
      WHERE u.role = 'RESIDENT'
        AND u.is_active = 1
      ORDER BY t.name, un.unit_number, u.full_name"
);
$moradores = $stmt->fetchAll();

renderizarInicioPagina(
    'Nova encomenda',
    'Cadastro operacional da portaria com notificação imediata e transição automática para o estado NOTIFIED.',
    ['ativo' => 'nova_encomenda']
);
?>
<div class="row g-4">
    <div class="col-lg-5">
        <section class="surface-card h-100">
            <div class="card-label">Regra de negócio</div>
            <h2 class="section-title">Recebimento com notificação em paralelo</h2>
            <p class="soft-muted">
                Ao salvar uma encomenda, o UpWay grava a entrada física e já prepara as notificações
                in-app, WhatsApp e Push por meio de um dispatcher dummy pronto para futuras integrações HTTP.
            </p>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="surface-card form-shell h-100">
            <h2 class="section-title">Cadastrar pacote na portaria</h2>
            <form method="post" class="row g-3">
                <div class="col-12">
                    <label for="morador_id" class="form-label fw-semibold">Morador destinatário</label>
                    <select class="form-select" id="morador_id" name="morador_id" required>
                        <option value="">Selecione um morador</option>
                        <?php foreach ($moradores as $morador): ?>
                            <option value="<?= e((string) $morador['id']); ?>">
                                <?= e($morador['full_name'] . ' • ' . trim(($morador['tower_name'] ?? '') . ' ' . ($morador['unit_number'] ?? ''))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="carrier" class="form-label fw-semibold">Transportadora</label>
                    <input type="text" class="form-control" id="carrier" name="carrier" placeholder="Mercado Livre, Correios..." required>
                </div>
                <div class="col-md-6">
                    <label for="tracking_code" class="form-label fw-semibold">Código de rastreio</label>
                    <input type="text" class="form-control" id="tracking_code" name="tracking_code" placeholder="Opcional">
                </div>
                <div class="col-12">
                    <label for="descricao" class="form-label fw-semibold">Descrição</label>
                    <textarea class="form-control" id="descricao" name="descricao" rows="4" placeholder="Observações da encomenda, volume, fragilidade..."></textarea>
                </div>
                <div class="col-12 d-grid d-sm-flex gap-2">
                    <button type="submit" class="btn btn-dark btn-pill px-4">Registrar encomenda</button>
                    <a href="porteiro_dashboard.php" class="btn btn-outline-dark btn-pill">Voltar ao dashboard</a>
                </div>
            </form>
        </section>
    </div>
</div>
<?php renderizarFimPagina(); ?>
