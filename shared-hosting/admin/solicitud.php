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
    exit('Solicitud no encontrada.');
}
$isPersonalHoroscope = ($item['service_type'] ?? 'natal_chart') === 'personal_horoscope';
$serviceName = $isPersonalHoroscope ? 'Horóscopo personalizado' : 'Carta natal';

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
        } elseif ($action === 'save_browser_chart') {
            $currentEngine = astrology_config();
            if (($currentEngine['mode'] ?? '') !== 'github_pages') {
                throw new RuntimeException('El motor activo no corresponde a GitHub Pages.');
            }
            $svg = sanitize_chart_svg((string)($_POST['chart_svg'] ?? ''));
            $dataJson = trim((string)($_POST['chart_data'] ?? ''));
            if ($dataJson === '' || strlen($dataJson) > 2_000_000) {
                throw new RuntimeException('Los datos técnicos de la carta no son válidos.');
            }
            $chartData = json_decode($dataJson, true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($chartData)
                || !is_array($chartData['subject'] ?? null)
                || !is_array($chartData['planets'] ?? null)
                || count($chartData['planets']) < 16
                || !is_array($chartData['houses']['cusps'] ?? null)
                || count($chartData['houses']['cusps']) < 13
                || !is_array($chartData['houses']['details'] ?? null)
                || count($chartData['houses']['details']) !== 12
                || !is_array($chartData['angles'] ?? null)
                || count($chartData['angles']) < 5
                || !is_array($chartData['aspects'] ?? null)
                || !is_array($chartData['distribution'] ?? null)
            ) {
                throw new RuntimeException('GitHub devolvió una carta incompleta.');
            }
            $subject = $chartData['subject'];
            if ((string)($subject['birth_date'] ?? '') !== (string)$item['birth_date']
                || substr((string)($subject['birth_time'] ?? ''), 0, 5) !== substr((string)$item['birth_time'], 0, 5)
                || abs((float)($subject['latitude'] ?? 999) - (float)$item['latitude']) > 0.0001
                || abs((float)($subject['longitude'] ?? 999) - (float)$item['longitude']) > 0.0001
            ) {
                throw new RuntimeException('Los datos calculados no coinciden con la solicitud.');
            }
            $pointsByKey = [];
            foreach ($chartData['planets'] as $point) {
                if (is_array($point) && isset($point['key'])) { $pointsByKey[(string)$point['key']] = $point; }
            }
            foreach ([['true_north_node', 'true_south_node'], ['mean_north_node', 'mean_south_node']] as [$northKey, $southKey]) {
                if (!isset($pointsByKey[$northKey], $pointsByKey[$southKey])) {
                    throw new RuntimeException('El cálculo no incluyó el eje nodal completo.');
                }
                $nodeDifference = fmod(((float)$pointsByKey[$southKey]['longitude'] - (float)$pointsByKey[$northKey]['longitude'] + 360.0), 360.0);
                if (abs($nodeDifference - 180.0) > 0.00001) {
                    throw new RuntimeException('El eje de los nodos no conserva la oposición exacta de 180° grados.');
                }
            }
            if ($isPersonalHoroscope && !is_array($chartData['transits'] ?? null)) {
                throw new RuntimeException('El motor no devolvió los tránsitos del horóscopo personalizado.');
            }
            $normalizedJson = json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $context = chart_context_for_ai($chartData);
            $query = db()->prepare("UPDATE service_requests SET chart_svg=?,chart_data=?,chart_engine='github_wasm',chart_generated_at=NOW(),chart_context=?,status='calculated' WHERE id=?");
            $query->execute([$svg, $normalizedJson, $context, $id]);
        } elseif ($action === 'save_result') {
            $reading = clean_ai_text((string)($_POST['ai_interpretation'] ?? ''));
            $context = trim((string)($_POST['chart_context'] ?? ''));
            if (mb_strlen($reading) < 30) { throw new RuntimeException('El resultado es demasiado corto.'); }
            db()->prepare("UPDATE service_requests SET chart_context=?,ai_interpretation=?,status='completed' WHERE id=?")->execute([$context, $reading, $id]);
        } elseif ($action === 'generate_ai') {
            $provider = (string)($_POST['provider'] ?? '');
            $ai = active_ai_config($provider);
            if (!$ai) { throw new RuntimeException('Configura primero ese proveedor de inteligencia artificial.'); }
            $context = trim((string)($_POST['chart_context'] ?? '')) ?: trim((string)($item['chart_context'] ?? ''));
            if ($context === '') { throw new RuntimeException('Primero genera el cálculo astrológico. La IA no debe inventar posiciones planetarias.'); }
            $prompt = service_interpretation_prompt($item, $context);
            $reading = clean_ai_text(ai_generate($provider, decrypt_secret($ai['encrypted_api_key']), $ai['model'], $prompt));
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
        flash('success', in_array($action, ['calculate_chart', 'save_browser_chart'], true) ? 'Cálculo astrológico verificado y guardado correctamente.' : 'Cambios guardados correctamente.');
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
$whatsText = $ready ? 'Hola ' . $item['full_name'] . ', tu lectura de MS Astrología está lista: ' . $resultUrl : 'Hola ' . $item['full_name'] . ', estamos procesando tu ' . mb_strtolower($serviceName) . ' ' . $item['public_id'] . '.';
$phone = preg_replace('/\D+/', '', $item['phone']);
if (strlen($phone) <= 10) { $phone = (string)cfg('default_country_dial_code', '57') . $phone; }
$whatsUrl = 'https://wa.me/' . $phone . '?text=' . rawurlencode($whatsText);
$mailUrl = 'mailto:' . rawurlencode($item['email']) . '?subject=' . rawurlencode('Tu ' . mb_strtolower($serviceName) . ' de MS Astrología') . '&body=' . rawurlencode($whatsText);
$statusLabels = ['pending' => 'Pendiente', 'calculated' => 'Carta calculada', 'completed' => 'Completada', 'cancelled' => 'Cancelada'];
$paymentLabels = ['pending' => 'Pendiente', 'paid' => 'Pagado', 'rejected' => 'Rechazado'];
render_header($serviceName . ' ' . $item['public_id'], 'admin-page', true);
?>
<section class="admin-shell detail-shell">
  <a class="back-link" href="<?= e(url('admin/index.php')) ?>">← Volver a solicitudes</a>
  <div class="detail-heading">
    <div><div class="eyebrow"><?= e($serviceName) ?> · <?= e($item['public_id']) ?></div><h1><?= e($item['full_name']) ?></h1><p><?= e($item['email']) ?> · <?= e($item['phone']) ?></p></div>
    <div class="status-controls">
      <form method="post" class="status-form"><?= csrf_field() ?><input type="hidden" name="action" value="payment"><label>Pago<select name="payment_status" onchange="this.form.submit()"><?php foreach ($paymentLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= $item['payment_status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label></form>
      <form method="post" class="status-form"><?= csrf_field() ?><input type="hidden" name="action" value="status"><label>Estado<select name="status" onchange="this.form.submit()"><?php foreach ($statusLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= $item['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label></form>
    </div>
  </div>
  <section class="detail-grid">
    <article class="panel data-panel">
      <div class="panel-heading"><span class="step">01</span><div><h2>Datos de nacimiento</h2><p>Base exacta del cálculo astronómico.</p></div></div>
      <dl class="data-list"><div><dt>Fecha</dt><dd><?= e($item['birth_date']) ?></dd></div><div><dt>Hora local</dt><dd><?= e(substr($item['birth_time'], 0, 5)) ?></dd></div><div class="wide"><dt>Lugar</dt><dd><?= e($item['birthplace']) ?></dd></div><div><dt>Latitud</dt><dd><?= e($item['latitude']) ?></dd></div><div><dt>Longitud</dt><dd><?= e($item['longitude']) ?></dd></div><div><dt>Zona horaria</dt><dd><?= e($item['timezone']) ?></dd></div></dl>
      <?php if ($isPersonalHoroscope): ?><dl class="data-list"><div><dt>Período</dt><dd><?= e(['daily'=>'Diario','weekly'=>'Semanal','monthly'=>'Mensual'][$item['horoscope_period']] ?? 'Personalizado') ?></dd></div><div><dt>Fecha de referencia</dt><dd><?= e($item['horoscope_date']) ?></dd></div><?php if ($item['horoscope_focus']): ?><div class="wide"><dt>Consulta</dt><dd><?= e($item['horoscope_focus']) ?></dd></div><?php endif; ?></dl><?php endif; ?>
      <?php if ($item['notes']): ?><p><?= nl2br(e($item['notes'])) ?></p><?php endif; ?>
      <?php if ($engine && $engine['mode'] === 'github_pages'):
        $chartSubject = [
          'name' => (string)$item['full_name'],
          'birth_date' => (string)$item['birth_date'],
          'birth_time' => substr((string)$item['birth_time'], 0, 8),
          'birthplace' => (string)$item['birthplace'],
          'latitude' => (float)$item['latitude'],
          'longitude' => (float)$item['longitude'],
          'timezone' => (string)$item['timezone'],
          'transit_date' => $isPersonalHoroscope ? (string)$item['horoscope_date'] : '',
          'horoscope_period' => $isPersonalHoroscope ? (string)$item['horoscope_period'] : '',
          'horoscope_focus' => $isPersonalHoroscope ? (string)$item['horoscope_focus'] : '',
        ];
      ?>
        <div class="github-chart-control" data-github-chart data-engine-url="<?= e(rtrim((string)$engine['base_url'], '/')) ?>">
          <button class="primary-button" type="button" data-chart-button disabled><span><?= $item['chart_svg'] ? ($isPersonalHoroscope ? 'Recalcular carta y tránsitos' : 'Recalcular carta natal') : ($isPersonalHoroscope ? 'Generar carta y tránsitos' : 'Generar carta natal') ?></span><span>✦</span></button>
          <p class="chart-engine-status" data-chart-status>Conectando con el motor gratuito de GitHub…</p>
          <iframe data-chart-frame title="Motor astrológico de GitHub" hidden referrerpolicy="no-referrer"></iframe>
          <script type="application/json" data-chart-subject><?= json_encode($chartSubject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
          <form method="post" data-chart-form hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_browser_chart">
            <textarea name="chart_svg"></textarea>
            <textarea name="chart_data"></textarea>
          </form>
        </div>
      <?php elseif ($engine): ?><form method="post"><?= csrf_field() ?><button class="primary-button" name="action" value="calculate_chart"><span><?= $item['chart_svg'] ? 'Recalcular datos astrológicos' : 'Generar datos astrológicos' ?></span><span>✦</span></button></form><?php else: ?><div class="alert info">Configura el <a href="<?= e(url('admin/astrologia.php')) ?>">motor astrológico</a> para habilitar el cálculo.</div><?php endif; ?>
    </article>
    <article class="panel chart-panel">
      <div class="panel-heading"><span class="step">02</span><div><h2><?= $isPersonalHoroscope ? 'Carta y tránsitos calculados' : 'Carta calculada' ?></h2><p>Tropical · casas Placidus · Swiss Ephemeris oficial · nodos verdadero y medio.</p></div></div>
      <?php if (!empty($item['chart_svg'])): ?><div class="chart-view-actions"><button type="button" class="secondary-button" data-chart-expand>⛶ Ampliar carta</button></div><div class="chart-frame chart-svg"><?= sanitize_chart_svg((string)$item['chart_svg']) ?></div><p class="chart-meta">Generada <?= e((string)$item['chart_generated_at']) ?> · motor <?= e($item['chart_engine'] === 'rapidapi' ? 'Astrologer API' : ($item['chart_engine'] === 'github_wasm' ? 'GitHub + Swiss Ephemeris' : 'servidor propio')) ?></p><?php else: ?><div class="empty-compact"><span>◎</span><p>Genera el cálculo para crear la rueda y habilitar la interpretación precisa.</p></div><?php endif; ?>
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
<?php if ($engine && $engine['mode'] === 'github_pages'): ?><script defer src="<?= e(url('assets/github-chart-client.js?v=0.8.3')) ?>"></script><?php endif; ?>
<?php render_footer();
