<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/verifica_sessao.php';
require_once __DIR__ . '/includes/layout.php';

requerPapel(['PORTER', 'ADMIN']);

$pdo = obterConexao();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenInformado = normalizarIdentificador($_POST['token'] ?? '');

    if ($tokenInformado === '') {
        definirFlash('warning', 'Informe ou escaneie um token para validar a retirada.');
        header('Location: porteiro_retirada.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT
                pt.id AS token_id,
                pt.package_id,
                pt.expires_at,
                pt.used_at,
                p.status,
                p.tracking_code,
                p.carrier,
                u.id AS resident_id,
                u.full_name AS resident_name,
                t.name AS tower_name,
                un.unit_number
             FROM pickup_tokens pt
             INNER JOIN packages p ON p.id = pt.package_id
             INNER JOIN users u ON u.id = p.resident_id
             LEFT JOIN units un ON un.id = u.unit_id
             LEFT JOIN towers t ON t.id = un.tower_id
             WHERE pt.token_code = :token_code
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([':token_code' => $tokenInformado]);
        $registro = $stmt->fetch();

        if ($registro === false) {
            throw new RuntimeException('Token não localizado.');
        }

        if ($registro['used_at'] !== null) {
            throw new RuntimeException('Este token já foi utilizado.');
        }

        if (strtotime($registro['expires_at']) <= time()) {
            throw new RuntimeException('Este token expirou.');
        }

        if ($registro['status'] !== 'TOKEN_GENERATED') {
            throw new RuntimeException('A encomenda não está apta para retirada.');
        }

        $stmt = $pdo->prepare(
            'UPDATE pickup_tokens
                SET used_at = NOW(),
                    validated_by_user_id = :validated_by_user_id
              WHERE id = :id'
        );
        $stmt->execute([
            ':validated_by_user_id' => $_SESSION['usuario_id'],
            ':id' => $registro['token_id'],
        ]);

        $stmt = $pdo->prepare(
            "UPDATE packages
                SET status = 'PICKED_UP',
                    picked_up_at = NOW()
              WHERE id = :id
                AND status = 'TOKEN_GENERATED'"
        );
        $stmt->execute([':id' => $registro['package_id']]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('A encomenda foi alterada por outra operação.');
        }

        registrarAuditoria($pdo, (int) $_SESSION['usuario_id'], 'TOKEN_VALIDATED', 'PACKAGE', (int) $registro['package_id'], [
            'token_code' => $tokenInformado,
            'resident_id' => $registro['resident_id'],
        ]);

        $pdo->commit();
        definirFlash(
            'success',
            sprintf(
                'Retirada confirmada para %s (%s %s).',
                $registro['resident_name'],
                $registro['tower_name'] ?? 'Torre',
                $registro['unit_number'] ?? 'Unidade'
            )
        );
        header('Location: porteiro_retirada.php');
        exit;
    } catch (Throwable $erro) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        registrarAuditoria($pdo, isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null, 'TOKEN_VALIDATION_FAILED', 'TOKEN', null, [
            'token_code' => $tokenInformado,
            'reason' => $erro->getMessage(),
        ]);
        definirFlash('danger', $erro->getMessage());
        header('Location: porteiro_retirada.php');
        exit;
    }
}

$stmt = $pdo->query(
    "SELECT
        p.id,
        p.carrier,
        p.tracking_code,
        pt.token_code,
        pt.expires_at,
        u.full_name AS resident_name,
        t.name AS tower_name,
        un.unit_number
     FROM packages p
     INNER JOIN pickup_tokens pt ON pt.package_id = p.id
     INNER JOIN users u ON u.id = p.resident_id
     LEFT JOIN units un ON un.id = u.unit_id
     LEFT JOIN towers t ON t.id = un.tower_id
     WHERE p.status = 'TOKEN_GENERATED'
       AND pt.used_at IS NULL
       AND pt.expires_at > NOW()
     ORDER BY pt.generated_at DESC"
);
$filaAtiva = $stmt->fetchAll();

renderizarInicioPagina(
    'Validação de retirada',
    'Fluxo seguro com scanner de QR por câmera, fallback manual e transação atômica para evitar reutilização do mesmo token.',
    ['ativo' => 'retirada', 'compacto' => true]
);
?>
<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <section class="surface-card h-100">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div>
                    <div class="card-label">Scanner de QR Code</div>
                    <h2 class="section-title mb-1">Leitura por câmera do dispositivo</h2>
                    <p class="soft-muted mb-0">
                        Se o navegador suportar <code>BarcodeDetector</code>, o código é lido automaticamente e enviado ao validador.
                    </p>
                </div>
                <span id="scannerStatus" class="status-pill status-soft">Aguardando permissão da câmera</span>
            </div>
            <div class="scanner-shell">
                <video id="scannerVideo" class="scanner-video" playsinline muted></video>
                <div class="scanner-overlay">
                    <div class="scanner-frame"></div>
                </div>
            </div>
        </section>
    </div>
    <div class="col-xl-5">
        <section class="surface-card form-shell h-100">
            <div class="card-label">Fallback manual</div>
            <h2 class="section-title">Digitar token alfanumérico</h2>
            <form method="post" id="tokenForm" class="row g-3">
                <div class="col-12">
                    <label for="token" class="form-label fw-semibold">Token da encomenda</label>
                    <div class="input-icon">
                        <i class="bi bi-qr-code-scan"></i>
                        <input
                            type="text"
                            class="form-control"
                            id="token"
                            name="token"
                            placeholder="Ex.: AB23CD45"
                            maxlength="16"
                            required
                        >
                    </div>
                </div>
                <div class="col-12 d-grid gap-2">
                    <button type="submit" class="btn btn-dark btn-pill">Validar retirada</button>
                    <small class="soft-muted">
                        A validação usa transação com <code>beginTransaction()</code>, <code>commit()</code> e <code>rollBack()</code>.
                    </small>
                </div>
            </form>
        </section>
    </div>
</div>

<section class="surface-card">
    <div class="card-label">Fila ativa</div>
    <h2 class="section-title">Encomendas prontas para retirada</h2>
    <div class="table-responsive">
        <table class="table table-soft align-middle">
            <thead>
                <tr>
                    <th>Morador</th>
                    <th>Unidade</th>
                    <th>Transportadora</th>
                    <th>Rastreio</th>
                    <th>Token</th>
                    <th>Expira em</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($filaAtiva === []): ?>
                    <tr>
                        <td colspan="6" class="soft-muted">Nenhuma encomenda com token ativo no momento.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($filaAtiva as $item): ?>
                        <tr>
                            <td><?= e($item['resident_name']); ?></td>
                            <td><?= e(trim(($item['tower_name'] ?? '') . ' ' . ($item['unit_number'] ?? ''))); ?></td>
                            <td><?= e($item['carrier']); ?></td>
                            <td><?= e($item['tracking_code'] ?: 'Sem código'); ?></td>
                            <td><code><?= e($item['token_code']); ?></code></td>
                            <td><?= e(date('d/m/Y H:i', strtotime($item['expires_at']))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
const video = document.getElementById('scannerVideo');
const statusChip = document.getElementById('scannerStatus');
const tokenInput = document.getElementById('token');
const tokenForm = document.getElementById('tokenForm');

function atualizarStatus(texto, classe) {
    statusChip.textContent = texto;
    statusChip.className = 'status-pill ' + classe;
}

function extrairToken(texto) {
    const bruto = texto.trim();
    if (bruto.toLowerCase().startsWith('upway://pickup/')) {
        return bruto.split('/').pop().toUpperCase();
    }
    return bruto.toUpperCase();
}

async function iniciarScanner() {
    if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
        atualizarStatus('Scanner nativo indisponível', 'status-warning');
        return;
    }

    try {
        const detector = new BarcodeDetector({ formats: ['qr_code'] });
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' },
            audio: false
        });

        video.srcObject = stream;
        await video.play();
        atualizarStatus('Scanner ativo', 'status-success');

        const loop = async () => {
            if (video.readyState >= 2) {
                const codigos = await detector.detect(video);

                if (codigos.length > 0) {
                    const token = extrairToken(codigos[0].rawValue || '');

                    if (token !== '') {
                        tokenInput.value = token;
                        atualizarStatus('QR detectado e pronto para validação', 'status-info');
                        stream.getTracks().forEach(track => track.stop());
                        tokenForm.submit();
                        return;
                    }
                }
            }

            requestAnimationFrame(loop);
        };

        requestAnimationFrame(loop);
    } catch (erro) {
        atualizarStatus('Permissão negada ou câmera indisponível', 'status-warning');
    }
}

iniciarScanner();
</script>
<?php renderizarFimPagina(); ?>
