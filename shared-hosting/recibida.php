<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$item = request_by_public_id(trim((string)($_GET['id'] ?? '')));
if (!$item) {
    http_response_code(404);
    exit('Solicitud no encontrada.');
}
$paymentLabel = ucfirst((string)($item['payment_method'] ?? 'pago seleccionado'));
$serviceLabel = ($item['service_type'] ?? 'natal_chart') === 'personal_horoscope' ? 'horóscopo personalizado' : 'carta natal';
$whatsappMessage = sprintf(
    'Hola Miguel, quiero confirmar el pago de mi %s, solicitud %s, por %s. Mi nombre es %s. Adjunto el comprobante.',
    $serviceLabel,
    (string)$item['public_id'],
    $paymentLabel,
    (string)$item['full_name']
);
$whatsappUrl = 'https://wa.me/573137009000?text=' . rawurlencode($whatsappMessage);
render_header('Solicitud recibida');
?>
<section class="center-card success-card">
  <div class="success-symbol">✦</div>
  <div class="eyebrow">Solicitud recibida</div>
  <h1>Gracias, <?= e($item['full_name']) ?>.</h1>
  <p>Recibimos tu solicitud de <strong><?= e($serviceLabel) ?></strong>.</p>
  <p>Guarda este código. Para consultar el estado usarás este código y el mismo correo electrónico de la solicitud:</p>
  <div class="request-code"><?= e($item['public_id']) ?></div>
  <div class="payment-next-step">
    <strong>Siguiente paso</strong>
    <p>Envía el comprobante por WhatsApp. El mensaje ya incluye tu código y el medio de pago; solo debes adjuntar la imagen.</p>
  </div>
  <div class="hero-actions received-actions">
    <a class="whatsapp-button" href="<?= e($whatsappUrl) ?>" target="_blank" rel="noreferrer"><span class="whatsapp-icon">◉</span><span>Confirmar pago por WhatsApp<small>+57 313 700 9000</small></span><b>→</b></a>
    <a class="secondary-button" href="<?= e(url('mi-solicitud.php?id=' . rawurlencode((string)$item['public_id']))) ?>">Consultar estado</a>
    <a class="text-link" href="<?= e(url()) ?>">Volver al inicio</a>
  </div>
</section>
<?php render_footer();
