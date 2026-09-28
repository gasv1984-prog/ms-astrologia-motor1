<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$errors = [];
$values = $_POST;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $values['service_type'] = ($_GET['servicio'] ?? '') === 'horoscopo_personalizado' ? 'personal_horoscope' : 'natal_chart';
    $values['horoscope_period'] = 'monthly';
    $values['horoscope_date'] = date('Y-m-d');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $required = ['full_name','email','phone','birth_date','birth_time','birthplace','latitude','longitude','timezone','payment_method','request_password'];
    foreach ($required as $field) {
        if (trim((string)($_POST[$field] ?? '')) === '') { $errors[] = 'Completa todos los campos obligatorios.'; break; }
    }
    if (!filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL)) { $errors[] = 'El correo electrónico no es válido.'; }
    $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
    if (strlen($phone) < 7 || strlen($phone) > 15) { $errors[] = 'El teléfono no es válido.'; }
    if (!in_array($_POST['payment_method'] ?? '', ['nequi','daviplata','llave'], true)) { $errors[] = 'Selecciona un medio de pago válido.'; }
    if (strlen((string)($_POST['request_password'] ?? '')) < 8) { $errors[] = 'La contraseña debe tener al menos 8 caracteres.'; }
    $serviceType = (string)($_POST['service_type'] ?? 'natal_chart');
    if (!in_array($serviceType, ['natal_chart', 'personal_horoscope'], true)) { $errors[] = 'Selecciona un servicio válido.'; }
    $horoscopePeriod = null;
    $horoscopeDate = null;
    $horoscopeFocus = null;
    if ($serviceType === 'personal_horoscope') {
        $horoscopePeriod = (string)($_POST['horoscope_period'] ?? 'monthly');
        $horoscopeDate = (string)($_POST['horoscope_date'] ?? '');
        $horoscopeFocus = mb_substr(trim((string)($_POST['horoscope_focus'] ?? '')), 0, 500);
        if (!in_array($horoscopePeriod, ['daily', 'weekly', 'monthly'], true)) { $errors[] = 'Selecciona el período del horóscopo personalizado.'; }
        $dateCheck = DateTimeImmutable::createFromFormat('!Y-m-d', $horoscopeDate);
        if (!$dateCheck || $dateCheck->format('Y-m-d') !== $horoscopeDate) { $errors[] = 'Selecciona una fecha válida para el horóscopo.'; }
    }
    $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($latitude === false || $longitude === false || abs((float)$latitude) > 90 || abs((float)$longitude) > 180) { $errors[] = 'Las coordenadas no son válidas.'; }
    if (($_POST['consent'] ?? '') !== 'yes') { $errors[] = 'Debes autorizar el tratamiento de los datos.'; }
    if (!$errors) {
        $publicId = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
        $stmt = db()->prepare('INSERT INTO service_requests (public_id,full_name,email,phone,birth_date,birth_time,birthplace,latitude,longitude,timezone,country_code,admin1_code,city_id,notes,service_type,horoscope_period,horoscope_date,horoscope_focus,payment_method,request_password_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$publicId,trim($_POST['full_name']),strtolower(trim($_POST['email'])),$phone,$_POST['birth_date'],$_POST['birth_time'],trim($_POST['birthplace']),(float)$latitude,(float)$longitude,trim($_POST['timezone']),($_POST['country_code'] ?: null),($_POST['admin1_code'] ?: null),($_POST['city_id'] ?: null),trim((string)($_POST['notes'] ?? '')),$serviceType,$horoscopePeriod,$horoscopeDate,$horoscopeFocus,$_POST['payment_method'],password_hash($_POST['request_password'], PASSWORD_DEFAULT)]);
        redirect('recibida.php?id=' . rawurlencode($publicId));
    }
}
render_header('Solicita tu lectura astrológica', 'public-page');
?><section class="request-shell"><div class="intro-panel"><div class="eyebrow">Astrología precisa y personal</div><h1>Tu cielo,<br><em>en el instante exacto.</em></h1><p>Solicita tu carta natal o un horóscopo personalizado con tránsitos. La solicitud queda protegida y solo el administrador puede procesarla.</p><dl class="precision-list"><div><dt>Efemérides</dt><dd>Swiss Ephemeris oficial</dd></div><div><dt>Coordenadas</dt><dd>Latitud y longitud exactas</dd></div><div><dt>Zona horaria</dt><dd>Formato IANA</dd></div><div><dt>Privacidad</dt><dd>Seguimiento con contraseña</dd></div></dl><div class="orbit-visual"><span class="orbit orbit-one"></span><span class="orbit orbit-two"></span><span class="orbit orbit-three"></span><span class="orbit-core">☉</span><i></i><b></b></div></div>
<div class="form-panel"><div class="form-heading"><span class="step">01</span><div><h2>Solicitar lectura</h2><p>Elige el servicio y completa tus datos con cuidado.</p></div></div>
<?php if ($errors): ?><div class="alert error"><strong>Revisa la información</strong><ul><?php foreach (array_unique($errors) as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="request-form" method="post" data-service-request><?= csrf_field() ?>
<fieldset class="service-choice full"><legend>¿Qué deseas solicitar?</legend><div class="payment-options" data-service-options><?php foreach (['natal_chart'=>['Carta natal completa','Lectura profunda de tu mapa de nacimiento'],'personal_horoscope'=>['Horóscopo personalizado','Tus tránsitos para una fecha o período']] as $value=>$copy): ?><label class="payment-option"><input type="radio" name="service_type" value="<?= e($value) ?>" required <?= ($values['service_type'] ?? 'natal_chart') === $value ? 'checked' : '' ?>><span><strong><?= e($copy[0]) ?></strong><small><?= e($copy[1]) ?></small></span></label><?php endforeach; ?></div></fieldset>
<div class="personal-horoscope-fields full" data-personal-horoscope-fields><label>Período<select name="horoscope_period"><option value="daily" <?= ($values['horoscope_period'] ?? '') === 'daily' ? 'selected' : '' ?>>Diario</option><option value="weekly" <?= ($values['horoscope_period'] ?? '') === 'weekly' ? 'selected' : '' ?>>Semanal</option><option value="monthly" <?= ($values['horoscope_period'] ?? 'monthly') === 'monthly' ? 'selected' : '' ?>>Mensual</option></select></label><label>Fecha de referencia<input type="date" name="horoscope_date" value="<?= e($values['horoscope_date'] ?? date('Y-m-d')) ?>"></label><label class="full">Tema que deseas consultar <span class="optional">opcional</span><textarea name="horoscope_focus" rows="3" maxlength="500" placeholder="Relaciones, trabajo, decisiones, propósito…"><?= e($values['horoscope_focus'] ?? '') ?></textarea></label><p class="full hint">El administrador calculará tus tránsitos personales sobre tu carta natal para el período elegido.</p></div>
<label class="full">Nombre completo<input name="full_name" required maxlength="120" value="<?= e($values['full_name'] ?? '') ?>"></label><label>Correo electrónico<input type="email" name="email" required value="<?= e($values['email'] ?? '') ?>"></label><label>Teléfono / WhatsApp<input name="phone" required value="<?= e($values['phone'] ?? '') ?>"></label><label>Fecha de nacimiento<input type="date" name="birth_date" required value="<?= e($values['birth_date'] ?? '') ?>"></label><label>Hora exacta<input type="time" name="birth_time" required value="<?= e($values['birth_time'] ?? '') ?>"></label>
<div class="full location-picker" data-location-picker data-geo-ready="true" data-country="<?= e($values['country_code'] ?? 'CO') ?>" data-admin1="<?= e($values['admin1_code'] ?? '') ?>" data-city="<?= e($values['city_id'] ?? '') ?>"><div class="location-heading"><div><strong>Lugar de nacimiento</strong><small>Selecciona para completar coordenadas y zona horaria.</small></div><button type="button" class="text-button manual-toggle">Ingresar manualmente</button></div><div class="location-selects"><label>Pais<select name="country_code" class="country-select"><option>Cargando…</option></select></label><label>Departamento / estado<select name="admin1_code" class="admin1-select" disabled></select></label><label class="full">Municipio / ciudad<select name="city_id" class="city-select" disabled></select></label></div><p class="location-status"></p></div>
<label class="full manual-field">Lugar confirmado<input name="birthplace" required readonly value="<?= e($values['birthplace'] ?? '') ?>"></label><label class="manual-field">Latitud<input type="number" step="0.000001" name="latitude" required readonly value="<?= e($values['latitude'] ?? '') ?>"></label><label class="manual-field">Longitud<input type="number" step="0.000001" name="longitude" required readonly value="<?= e($values['longitude'] ?? '') ?>"></label><label class="full manual-field">Zona horaria IANA<input name="timezone" required readonly value="<?= e($values['timezone'] ?? '') ?>"></label><label class="full">Notas <span class="optional">opcional</span><textarea name="notes" rows="3"><?= e($values['notes'] ?? '') ?></textarea></label>
<fieldset class="payment-fields full"><legend>Medio de pago</legend><div class="payment-options"><?php foreach (['nequi'=>'Nequi','daviplata'=>'Daviplata','llave'=>'Llave'] as $value=>$label): ?><label class="payment-option"><input type="radio" name="payment_method" value="<?= e($value) ?>" required <?= ($values['payment_method'] ?? '') === $value ? 'checked' : '' ?>><span><strong><?= e($label) ?></strong><small>Confirmación manual</small></span></label><?php endforeach; ?></div></fieldset><label class="full">Contraseña de seguimiento<input type="password" name="request_password" required minlength="8"></label><label class="consent full"><input type="checkbox" name="consent" value="yes" required><span>Autorizo el tratamiento de estos datos para gestionar mi solicitud.</span></label><button class="primary-button full"><span>Enviar solicitud</span><span>→</span></button></form><p class="data-credit">Datos geográficos: GeoNames, licencia CC BY 4.0.</p></div></section><?php render_footer();
