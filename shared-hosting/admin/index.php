<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();
$status = (string)($_GET['status'] ?? 'all');
$allowed = ['all', 'pending', 'calculated', 'completed', 'cancelled'];
if (!in_array($status, $allowed, true)) { $status = 'all'; }
if ($status === 'all') {
    $stmt = db()->query('SELECT * FROM service_requests ORDER BY id DESC');
} else {
    $stmt = db()->prepare('SELECT * FROM service_requests WHERE status=? ORDER BY id DESC');
    $stmt->execute([$status]);
}
$items = $stmt->fetchAll();
$counts = ['all' => (int)db()->query('SELECT COUNT(*) FROM service_requests')->fetchColumn()];
foreach (array_slice($allowed, 1) as $value) {
    $query = db()->prepare('SELECT COUNT(*) FROM service_requests WHERE status=?');
    $query->execute([$value]);
    $counts[$value] = (int)$query->fetchColumn();
}
$statusLabels = ['all' => 'Todas', 'pending' => 'Pendientes', 'calculated' => 'Calculadas', 'completed' => 'Completadas', 'cancelled' => 'Canceladas'];
$itemStatusLabels = ['pending' => 'Pendiente', 'calculated' => 'Carta calculada', 'completed' => 'Completada', 'cancelled' => 'Cancelada'];
$paymentLabels = ['pending' => 'Pendiente', 'paid' => 'Pagado', 'rejected' => 'Rechazado'];
render_header('Solicitudes · Administración', 'admin-page', true);
?>
<section class="admin-shell">
  <div class="page-heading"><div><div class="eyebrow">Panel de control</div><h1>Solicitudes</h1></div><p>Calcula cartas natales y tránsitos, genera la interpretación en español y entrega cada lectura.</p></div>
  <div class="stat-grid"><?php foreach ($allowed as $value): ?><a class="stat-card <?= $status === $value ? 'active' : '' ?>" href="?status=<?= e($value) ?>"><span><?= e($statusLabels[$value]) ?></span><strong><?= e($counts[$value]) ?></strong></a><?php endforeach; ?></div>
  <div class="table-card"><?php if (!$items): ?><div class="empty-state"><span>☾</span><h2>No hay solicitudes en este estado</h2><p>Las nuevas solicitudes aparecerán aquí.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Persona</th><th>Servicio</th><th>Nacimiento</th><th>Estado</th><th>Pago</th><th></th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><strong><?= e($item['full_name']) ?></strong><small><?= e($item['public_id']) ?></small></td><td><?= ($item['service_type'] ?? 'natal_chart') === 'personal_horoscope' ? 'Horóscopo personal' : 'Carta natal' ?><?php if (($item['service_type'] ?? '') === 'personal_horoscope'): ?><small><?= e($item['horoscope_date']) ?></small><?php endif; ?></td><td><?= e($item['birth_date']) ?> · <?= e(substr($item['birth_time'], 0, 5)) ?><small><?= e($item['birthplace']) ?></small></td><td><span class="status status-<?= e($item['status']) ?>"><?= e($itemStatusLabels[$item['status']] ?? $item['status']) ?></span></td><td><?= e($item['payment_method']) ?><small><?= e($paymentLabels[$item['payment_status']] ?? $item['payment_status']) ?></small></td><td><a class="row-link" href="<?= e(url('admin/solicitud.php?id=' . $item['id'])) ?>">Abrir →</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</section>
<?php render_footer();
