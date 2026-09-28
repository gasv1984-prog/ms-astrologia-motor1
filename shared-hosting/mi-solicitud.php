<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$item = null; $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $candidate = request_by_public_id(strtoupper(trim((string)($_POST['public_id'] ?? ''))));
    if (!$candidate || !password_verify((string)($_POST['password'] ?? ''), $candidate['request_password_hash'])) {
        $error = 'Código o contraseña incorrectos.';
    } else { $item = $candidate; }
}
render_header('Consultar solicitud');
?><section class="center-card track-card"><div class="eyebrow">Seguimiento privado</div><h1>Consulta tu solicitud</h1><?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($item): $serviceLabel = ($item['service_type'] ?? 'natal_chart') === 'personal_horoscope' ? 'Horóscopo personalizado' : 'Carta natal'; ?><div class="tracking-result"><span class="status status-<?= e($item['status']) ?>"><?= e(ucfirst($item['status'])) ?></span><h2><?= e($item['full_name']) ?></h2><dl class="data-list"><div><dt>Servicio</dt><dd><?= e($serviceLabel) ?></dd></div><div><dt>Código</dt><dd><?= e($item['public_id']) ?></dd></div><div><dt>Pago</dt><dd><?= e(ucfirst($item['payment_method'])) ?> · <?= e(ucfirst($item['payment_status'])) ?></dd></div><div class="wide"><dt>Lugar</dt><dd><?= e($item['birthplace']) ?></dd></div><div><dt>Recibida</dt><dd><?= e(substr($item['created_at'],0,10)) ?></dd></div></dl><?php if (result_ready($item)): ?><p>Tu lectura está lista y el pago fue confirmado.</p><a class="primary-button" href="<?= e(result_url($item)) ?>"><span>Abrir resultado y Aurita</span><span>→</span></a><?php else: ?><p>Tu solicitud está siendo procesada. Regresa más tarde para consultar el avance.</p><?php endif; ?></div>
<?php else: ?><p>Ingresa el código recibido y la contraseña que creaste.</p><form method="post" class="stack-form"><?= csrf_field() ?><label>Código de solicitud<input name="public_id" required autofocus></label><label>Contraseña<input type="password" name="password" required></label><button class="primary-button"><span>Consultar estado</span><span>→</span></button></form><?php endif; ?></section><?php render_footer();
