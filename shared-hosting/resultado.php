<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require MS_ROOT . '/inc/astrology.php';
$publicId = trim((string)($_GET['id'] ?? '')); $token = (string)($_GET['token'] ?? ''); $item = request_by_public_id($publicId);
if (!$item || !verify_result_token($publicId,$token)) { http_response_code(404); exit('Resultado no encontrado.'); }
if (!result_ready($item)) { http_response_code(403); exit('El resultado aun no esta habilitado.'); }
$stmt = db()->prepare('SELECT role,content FROM aurita_messages WHERE request_id=? ORDER BY id'); $stmt->execute([$item['id']]); $messages=$stmt->fetchAll();
$isPersonalHoroscope = ($item['service_type'] ?? 'natal_chart') === 'personal_horoscope';
$chartData = json_decode((string)($item['chart_data'] ?? ''), true);
$importantPositions = [];
if (is_array($chartData)) {
    $angleNames = ['ascendant'=>'Ascendente (ASC)','descendant'=>'Descendente (DSC)','medium_coeli'=>'Medio Cielo (MC)','imum_coeli'=>'Bajo Cielo (IC)'];
    foreach (($chartData['angles'] ?? []) as $point) {
        $key = (string)($point['key'] ?? '');
        if (isset($angleNames[$key])) { $importantPositions[] = [$angleNames[$key], $point, null]; }
    }
    $planetNames = ['sun'=>'Sol','moon'=>'Luna','mercury'=>'Mercurio','venus'=>'Venus','mars'=>'Marte','jupiter'=>'Júpiter','saturn'=>'Saturno','uranus'=>'Urano','neptune'=>'Neptuno','pluto'=>'Plutón','true_north_node'=>'Nodo Norte verdadero','true_south_node'=>'Nodo Sur verdadero'];
    foreach (($chartData['planets'] ?? []) as $point) {
        $key = (string)($point['key'] ?? '');
        if (isset($planetNames[$key])) { $importantPositions[] = [$planetNames[$key], $point, $point['house'] ?? null]; }
    }
}
render_header('Resultado de ' . $item['full_name'], 'result-page');
?><section class="result-hero"><div><div class="eyebrow"><?= $isPersonalHoroscope ? 'Horóscopo personalizado privado' : 'Carta natal privada' ?></div><h1><?= e($item['full_name']) ?></h1><p><?= e($item['birthplace']) ?> · <?= e($item['birth_date']) ?> <?= e(substr($item['birth_time'],0,5)) ?><?php if ($isPersonalHoroscope): ?> · Tránsitos <?= e($item['horoscope_date']) ?><?php endif; ?></p></div><button class="secondary-button no-print" onclick="window.print()">Descargar / guardar PDF</button></section>
<section class="result-layout"><article class="reading-card"><div class="rich-text"><?= render_markdown((string)$item['ai_interpretation']) ?></div></article><aside class="chart-summary"><h2><?= $isPersonalHoroscope ? 'Tu carta y tránsitos' : 'Tu carta natal' ?></h2><?php if (!empty($item['chart_svg'])): ?><div class="chart-view-actions no-print"><button type="button" class="secondary-button" data-chart-expand>⛶ Ampliar carta</button></div><div class="chart-frame chart-svg"><?= sanitize_chart_svg((string)$item['chart_svg']) ?></div><?php endif; ?><dl class="data-list"><div><dt>Ubicación</dt><dd><?= e($item['birthplace']) ?></dd></div><div><dt>Latitud</dt><dd><?= e($item['latitude']) ?></dd></div><div><dt>Longitud</dt><dd><?= e($item['longitude']) ?></dd></div><div><dt>Zona horaria</dt><dd><?= e($item['timezone']) ?></dd></div><?php if ($isPersonalHoroscope): ?><div><dt>Período</dt><dd><?= e(['daily'=>'Diario','weekly'=>'Semanal','monthly'=>'Mensual'][$item['horoscope_period']] ?? 'Personalizado') ?></dd></div><?php endif; ?></dl><?php if ($importantPositions): ?><section class="important-positions"><h3>Ubicaciones importantes</h3><dl><?php foreach ($importantPositions as [$label,$point,$house]): $sign=$point['sign'] ?? []; ?><div><dt><?= e($label) ?></dt><dd><?= e(sprintf('%02d° %02d′ %02d″ %s', (int)($sign['degree'] ?? 0), (int)($sign['minute'] ?? 0), (int)($sign['second'] ?? 0), (string)($sign['name'] ?? ''))) ?><?= $house ? ' · Casa ' . e($house) : '' ?><?= !empty($point['retrograde']) ? ' ℞' : '' ?></dd></div><?php endforeach; ?></dl></section><?php endif; ?><?php if ($item['chart_context']): ?><details><summary>Datos técnicos calculados</summary><pre><?= e($item['chart_context']) ?></pre></details><?php endif; ?></aside></section>
<section class="aurita-card no-print" data-aurita data-public-id="<?= e($publicId) ?>" data-token="<?= e($token) ?>"><div class="eyebrow">Conversacion privada</div><h2>Pregunta a Aurita</h2><p>Aurita responde sobre la lectura ya preparada, sin reemplazar asesoramiento profesional.</p><div class="chat-history"><?php foreach ($messages as $message): ?><div class="chat-message <?= e($message['role']) ?>"><strong><?= $message['role']==='user'?'Tu':'Aurita' ?></strong><div class="rich-text"><?= $message['role']==='assistant'?render_markdown($message['content']):e($message['content']) ?></div></div><?php endforeach; ?></div><form class="aurita-form"><textarea maxlength="1200" required placeholder="Escribe una pregunta sobre tu lectura..."></textarea><button class="primary-button">Preguntar</button><p class="form-feedback"></p></form></section><?php render_footer();
