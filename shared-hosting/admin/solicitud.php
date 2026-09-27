<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require MS_ROOT . '/inc/ai.php';
require MS_ROOT . '/inc/astrology.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM service_requests WHERE id=?');
$stmt->execute([$id]);
$item = $stmt->fetch();
if (!$item) {
    http_response_code(404);
    exit('Carta natal no encontrada.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'payment') {
            $value = (string)($_POST['payment_status'] ?? '');
            if (!in_array($value, ['pending', 'paid', 'rejected'], true)) { throw new RuntimeException('Estado de pago no válido.'); }
            db()->prepare('UPDATE service_requests SET payment_status=? WHERE id=?')->execute([$value, $id]);
        } elseif ($action === 'status') {
            $value = (string)($_POST['status'] ?? '');
            if (!in_array($value, ['pending', 'calculated', 'completed', 'cancelled'], true)) { throw new RuntimeException('Estado no válido.'); }
            db()->prepare('UPDATE service_requests SET status=? WHERE id=?')->execute([$value, $id]);
        } elseif ($action === 'calculate_chart') {
            $chart = calculate_hosted_natal_chart($item);
            $query = db()->prepare("UPDATE service_requests SET chart_svg=?,chart_data=?,chart_engine=?,chart_generated_at=NOW(),chart_context=?,status='calculated' WHERE id=?");
            $query->execute([$chart['svg'], $chart['data_json'], $chart['engine'], $chart['context'], $id]);
        } elseif ($action === 'save_result') {
            $reading = trim((string)($_POST['ai_interpretation'] ?? ''));
            $context = trim((string)($_POST['chart_context'] ?? ''));
            if (mb_strlen($reading) < 30) { throw new RuntimeException('El resultado es demasiado corto.'); }
            db()->prepare("UPDATE service_requests SET chart_context=?,ai_interpretation=?,status='completed' WHERE id=?")->execute([$context, $reading, $id]);
        } elseif ($action === 'generate_ai') {
            $provider = (string)($_POST['provider'] ?? '');
            $ai = active_ai_config($provider);
            if (!$ai) { throw new RuntimeException('Configura primero ese proveedor de inteligencia artificial.'); }
            $context = trim((string)($_POST['chart_context'] ?? '')) ?: trim((string)($item['chart_context'] ?? ''));
            if ($context === '') { throw new RuntimeException('Primero genera la carta natal. La IA no debe inventar posiciones planetarias.'); }
            $prompt = "Redacta en español una lectura astrológica extensa, estructurada, cálida y responsable. Usa únicamente los datos técnicos suministrados; no calcules ni inventes posiciones. Evita afirmaciones deterministas. Incluye síntesis, Sol, Luna, Ascendente, casas, aspectos, fortalezas, tensiones y preguntas de reflexión.\n\nPERSONA: {$item['full_name']}\nNACIMIENTO: {$item['birth_date']} {$item['birth_time']}\nLUGAR: {$item['birthplace']} ({$item['latitude']}, {$item['longitude']}, {$item['timezone']})\nNOTAS: {$item['notes']}\n\nDATOS TÉCNICOS VERIFICADOS:\n{$context}";
            $reading = ai_generate($provider, decrypt_secret($ai['encrypted_api_key']), $ai['model'], $prompt);
            $query = db()->prepare("UPDATE service_requests SET chart_context=?,ai_interpretation=?,ai_provider=?,ai_model=?,status='completed' WHERE id=?");
            $query->execute([$context, $reading, $provider, $ai['model'], $id]);
        } elseif ($action === 'notify') {
            $stmt = db()->prepare('SELECT * FROM service_requests WHERE id=?'); $stmt->execute([$id]); $latest = $stmt->fetch();
            if (!result_ready($latest)) { throw new RuntimeException('Confirma el pago y guarda un resultado antes de notificar.'); }
            if (!send_result_email($latest)) { throw new RuntimeException('No fue posible enviar el correo. Usa los enlaces manuales.'); }
            db()->prepare('UPDATE service_requests SET notified_at=NOW() WHERE id=?')->execute([$id]);
        } else {
            throw new RuntimeException('Acción no válida.');
        }
        $stmt = db()->prepare('SELECT * FROM service_requests WHERE id=?'); $stmt->execute([$id]); $latest = $stmt->fetch();
        if (in_array($action, ['payment', 'save_result', 'generate_ai'], true) && result_ready($latest) && empty($latest['notified_at']) && send_result_email($latest)) {
            db()->prepare('UPDATE service_requests SET notified_at=NOW() WHERE id=?')->execute([$id]);
        }
        flash('success', $action === 'calculate_chart' ? 'Carta natal calculada y guardada correctamente.' : 'Cambios guardados correctamente.');
        redirect('admin/solicitud.php?id=' . $id);
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect('admin/solicitud.php?id=' . $id);
    }
}

$configs = db()->query('SELECT provider,model FROM ai_configs ORDER BY provider')->fetchAll();
$engine = astrology_config();
$ready = result_ready($item);
$resultUrl = $ready ? result_url($item) : '';
$whatsText = $ready ? 'Hola ' . $item['full_name'] . ', tu lectura de MS Astrología está lista: ' . $resultUrl : 'Hola ' . $item['full_name'] . ', estamos procesando tu carta natal ' . $item['public_id'] . '.';
$phone = preg_replace('/\D+/', '', $item['phone']);
if (strlen($phone) <= 10) { $phone = (string)cfg('default_country_dial_code', '57') . $phone; }
$whatsUrl = 'https://wa.me/' . $phone . '?text=' . rawurlencode($whatsText);
$mailUrl = 'mailto:' . rawurlencode($item['email']) . '?subject=' . rawurlencode('Tu carta natal de MS Astrología') . '&body=' . rawurlencode($whatsText);
$statusLabels = ['pending' => 'Pendiente', 'calculated' => 'Carta calculada', 'completed' => 'Completada', 'cancelled' => 'Cancelada'];
$paymentLabels = ['pending' => 'Pendiente', 'paid' => 'Pagado', 'rejected' => 'Rechazado'];
render_header('Carta natal ' . $item['public_id'], 'admin-page', true);
?>
<section class="admin-shell detail-shell">
  <a class="back-link" href="<?= e(url('admin/index.php')) ?>">← Volver a cartas natales</a>
  <div class="detail-heading">
    <div><div class="eyebrow">Carta natal <?= e($item['public_id']) ?></div><h1><?= e($item['full_name']) ?></h1><p><?= e($item['email']) ?> · <?= e($item['phone']) ?></p></div>
    <div class="status-controls">
      <form method="post" class="status-form"><?= csrf_field() ?><input type="hidden" name="action" value="payment"><label>Pago<select name="payment_status" onchange="this.form.submit()"><?php foreach ($paymentLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= $item['payment_status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label></form>
      <form method="post" class="status-form"><?= csrf_field() ?><input type="hidden" name="action" value="status"><label>Estado<select name="status" onchange="this.form.submit()"><?php foreach ($statusLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= $item['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label></form>
    </div>
  </div>
  <section class="detail-grid">
    <article class="panel data-panel">
      <div class="panel-heading"><span class="step">01</span><div><h2>Datos de nacimiento</h2><p>Base exacta del cálculo astronómico.</p></div></div>
      <dl class="data-list"><div><dt>Fecha</dt><dd><?= e($item['birth_date']) ?></dd></div><div><dt>Hora local</dt><dd><?= e(substr($item['birth_time'], 0, 5)) ?></dd></div><div class="wide"><dt>Lugar</dt><dd><?= e($item['birthplace']) ?></dd></div><div><dt>Latitud</dt><dd><?= e($item['latitude']) ?></dd></div><div><dt>Longitud</dt><dd><?= e($item['longitude']) ?></dd></div><div><dt>Zona horaria</dt><dd><?= e($item['timezone']) ?></dd></div></dl>
      <?php if ($item['notes']): ?><p><?= nl2br(e($item['notes'])) ?></p><?php endif; ?>
      <?php if ($engine): ?><form method="post"><?= csrf_field() ?><button class="primary-button" name="action" value="calculate_chart"><span><?= $item['chart_svg'] ? 'Recalcular carta natal' : 'Generar carta natal' ?></span><span>✦</span></button></form><?php else: ?><div class="alert info">Configura el <a href="<?= e(url('admin/astrologia.php')) ?>">motor astrológico</a> para habilitar el cálculo.</div><?php endif; ?>
    </article>
    <article class="panel chart-panel">
      <div class="panel-heading"><span class="step">02</span><div><h2>Carta calculada</h2><p>Tropical · casas Placidus · etiquetas en español.</p></div></div>
      <?php if (!empty($item['chart_svg'])): ?><div class="chart-frame chart-svg"><?= sanitize_chart_svg((string)$item['chart_svg']) ?></div><p class="chart-meta">Generada <?= e((string)$item['chart_generated_at']) ?> · motor <?= e($item['chart_engine'] === 'rapidapi' ? 'Astrologer API' : 'servidor propio') ?></p><?php else: ?><div class="empty-compact"><span>◎</span><p>Usa “Generar carta natal” para crear la rueda y habilitar la interpretación.</p></div><?php endif; ?>
    </article>
  </section>
  <section class="panel interpretation-panel">
    <div class="panel-heading"><span class="step">03</span><div><h2>Interpretación en español</h2><p>La IA interpreta la carta ya calculada; nunca inventa el cálculo.</p></div></div>
    <form method="post" class="stack-form"><?= csrf_field() ?><label>Datos técnicos verificados<textarea name="chart_context" rows="10" placeholder="Se completan automáticamente al generar la carta."><?= e($item['chart_context']) ?></textarea><small>Puedes añadir observaciones profesionales sin borrar los datos calculados.</small></label><label>Interpretación final<textarea name="ai_interpretation" rows="18"><?= e($item['ai_interpretation']) ?></textarea></label><div class="button-row"><button class="primary-button" name="action" value="save_result">Guardar interpretación</button><?php foreach ($configs as $ai): ?><button class="secondary-button" name="action" value="generate_ai" onclick="this.form.provider.value='<?= e($ai['provider']) ?>'">Generar con <?= e(ucfirst($ai['provider'])) ?></button><?php endforeach; ?><input type="hidden" name="provider" value=""></div></form>
    <?php if (!$configs): ?><p class="hint">Para generar el texto automáticamente, configura primero <a href="<?= e(url('admin/ia.php')) ?>">OpenAI o Gemini</a>.</p><?php endif; ?>
  </section>
  <section class="panel delivery-panel">
    <div class="panel-heading"><span class="step">04</span><div><h2>Entrega al cliente</h2><p>Correo, WhatsApp, PDF y acceso privado a Aurita.</p></div></div>
    <?php if ($ready): ?><p>El resultado está habilitado. <?= $item['notified_at'] ? 'Correo enviado el ' . e($item['notified_at']) : 'El correo automático aún no está registrado como enviado.' ?></p><div class="button-row"><a class="primary-button" href="<?= e($resultUrl) ?>" target="_blank">Abrir resultado</a><a class="secondary-button" href="<?= e($mailUrl) ?>">Correo manual</a><a class="secondary-button" href="<?= e($whatsUrl) ?>" target="_blank">WhatsApp</a><form method="post"><?= csrf_field() ?><button class="secondary-button" name="action" value="notify">Reintentar correo</button></form></div><?php else: ?><p>Confirma el pago y guarda la interpretación para habilitar el enlace privado.</p><?php endif; ?>
  </section>
</section>
<?php render_footer();
