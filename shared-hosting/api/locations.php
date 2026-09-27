<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $action = (string)($_GET['action'] ?? 'countries');
    if ($action === 'countries') {
        $items = db()->query('SELECT c.code,c.name,COUNT(p.id) city_count FROM countries c LEFT JOIN places p ON p.country_code=c.code GROUP BY c.code,c.name HAVING city_count>0 ORDER BY c.name')->fetchAll();
    } elseif ($action === 'admin1') {
        $country = strtoupper((string)($_GET['country'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $country)) { throw new RuntimeException('Pais no valido.'); }
        $stmt = db()->prepare('SELECT a.code,a.name,COUNT(p.id) city_count FROM admin1 a LEFT JOIN places p ON p.country_code=a.country_code AND p.admin1_code=a.code WHERE a.country_code=? GROUP BY a.code,a.name HAVING city_count>0 ORDER BY a.name');
        $stmt->execute([$country]); $items = $stmt->fetchAll();
    } elseif ($action === 'places') {
        $country = strtoupper((string)($_GET['country'] ?? '')); $admin1 = (string)($_GET['admin1'] ?? '');
        if (!preg_match('/^[A-Z]{2}$/', $country) || $admin1 === '') { throw new RuntimeException('Division no valida.'); }
        $stmt = db()->prepare('SELECT id,name,latitude,longitude,timezone FROM places WHERE country_code=? AND admin1_code=? ORDER BY population DESC,name LIMIT 2500');
        $stmt->execute([$country,$admin1]); $items = $stmt->fetchAll();
    } else { throw new RuntimeException('Consulta no valida.'); }
    echo json_encode(['items'=>$items], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400); echo json_encode(['error'=>$e->getMessage(),'items'=>[]], JSON_UNESCAPED_UNICODE);
}
