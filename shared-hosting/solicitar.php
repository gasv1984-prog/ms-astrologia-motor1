<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$errors = [];
$values = $_POST;
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
    $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($latitude === false || $longitude === false || abs((float)$latitude) > 90 || abs((float)$longitude) > 180) { $errors[] = 'Las coordenadas no son válidas.'; }
    if (($_POST['consent'] ?? '') !== 'yes') { $errors[] = 'Debes autorizar el tratamiento de los datos.'; }
    if (!$errors) {
        $publicId = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
        $stmt = db()->prepare('INSERT INTO service_requests (public_id,full_name,email,phone,birth_date,birth_time,birthplace,latitude,longitude,timezone,country_code,admin1_code,city_id,notes,payment_method,request_password_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$publicId,trim($_POST['full_name']),strtolower(trim($_POST['email'])),$phone,$_POST['birth_date'],$_POST['birth_time'],trim($_POST['birthplace']),(float)$latitude,(float)$longitude,trim($_POST['timezone']),($_POST['country_code'] ?: null),($_POST['admin1_code'] ?: null),($_POST['city_id'] ?: null),trim((string)($_POST['notes'] ?? '')),$_POST['payment_method'],password_hash($_POST['request_password'], PASSWORD_DEFAULT)]);
        redirect('recibida.php?id=' . rawurlencode($publicId));
    }
}
render_header('Solicita tu carta natal', 'public-page');
?><section class="request-shell"><div class="intro-panel"><div class="eyebrow">Carta natal precisa</div><h1>Tu cielo,<br><em>en el instante exacto.</em></h1><p>Envía tus datos de nacimiento. La solicitud queda protegida y solo el administrador puede procesarla.</p><dl class="precision-list"><div><dt>Coordenadas</dt><dd>Latitud y longitud exactas</dd></div><div><dt>Zona horaria</dt><dd>Formato IANA</dd></div><div><dt>Privacidad</dt><dd>Seguimiento con contraseña</dd></div></dl><div class="orbit-visual"><span class="orbit orbit-one"></span><span class="orbit orbit-two"></span><span class="orbit orbit-three"></span><span class="orbit-core">☉</span><i></i><b></b></div></div>
<div class="form-panel"><div class="form-heading"><span class="step">01</span><div><h2>Solicitar lectura</h2><p>Completa tus datos con cuidado.</p></div></div>
<?php if ($errors): ?><div class="alert error"><strong>Revisa la información</strong><ul><?php foreach (array_unique($errors) as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="request-form" method="post"><?= csrf_field() ?><label class="full">Nombre completo<input name="full_name" required maxlength="120" value="<?= e($values['full_name'] ?? '') ?>"></label><label>Correo electrónico<input type="email" name="email" required value="<?= e($values['email'] ?? '') ?>"></label><label>Teléfono / WhatsApp<input name="phone" required value="<?= e($values['phone'] ?? '') ?>"></label><label>Fecha de nacimiento<input type="date" name="birth_date" required value="<?= e($values['birth_date'] ?? '') ?>"></label><label>Hora exacta<input type="time" name="birth_time" required value="<?= e($values['birth_time'] ?? '') ?>"></label>
<div class="full location-picker" data-location-picker data-geo-ready="true" data-country="<?= e($values['country_code'] ?? 'CO') ?>" data-admin1="<?= e($values['admin1_code'] ?? '') ?>" data-city="<?= e($values['city_id'] ?? '') ?>"><div class="location-heading"><div><strong>Lugar de nacimiento</strong><small>Selecciona para completar coordenadas y zona horaria.</small></div><button type="button" class="text-button manual-toggle">Ingresar manualmente</button></div><div class="location-selects"><label>Pais<select name="country_code" class="country-select"><option>Cargando…</option></select></label><label>Departamento / estado<select name="admin1_code" class="admin1-select" disabled></select></label><label class="full">Municipio / ciudad<select name="city_id" class="city-select" disabled></select></label></div><p class="location-status"></p></div>
<label class="full manual-field">Lugar confirmado<input name="birthplace" required readonly value="<?= e($values['birthplace'] ?? '') ?>"></label><label class="manual-field">Latitud<input type="number" step="0.000001" name="latitude" required readonly value="<?= e($values['latitude'] ?? '') ?>"></label><label class="manual-field">Longitud<input type="number" step="0.000001" name="longitude" required readonly value="<?= e($values['longitude'] ?? '') ?>"></label><label class="full manual-field">Zona horaria IANA<input name="timezone" required readonly value="<?= e($values['timezone'] ?? '') ?>"></label><label class="full">Notas <span class="optional">opcional</span><textarea name="notes" rows="3"><?= e($values['notes'] ?? '') ?></textarea></label>
<fieldset class="payment-fields full"><legend>Medio de pago</legend><div class="payment-options"><?php foreach (['nequi'=>'Nequi','daviplata'=>'Daviplata','llave'=>'Llave'] as $value=>$label): ?><label class="payment-option"><input type="radio" name="payment_method" value="<?= e($value) ?>" required <?= ($values['payment_method'] ?? '') === $value ? 'checked' : '' ?>><span><strong><?= e($label) ?></strong><small>Confirmación manual</small></span></label><?php endforeach; ?></div></fieldset><label class="full">Contraseña de seguimiento<input type="password" name="request_password" required minlength="8"></label><label class="consent full"><input type="checkbox" name="consent" value="yes" required><span>Autorizo el tratamiento de estos datos para gestionar mi solicitud.</span></label><button class="primary-button full"><span>Enviar solicitud</span><span>→</span></button></form><p class="data-credit">Datos geográficos: GeoNames, licencia CC BY 4.0.</p></div></section><?php render_footer();
