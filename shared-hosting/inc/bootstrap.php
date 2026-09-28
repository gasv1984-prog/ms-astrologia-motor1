<?php
declare(strict_types=1);

define('MS_ROOT', dirname(__DIR__));

$configFile = MS_ROOT . '/config.php';
if (!is_file($configFile)) {
    header('Location: /install.php', true, 303);
    exit;
}

$config = require $configFile;
if (!is_array($config) || empty($config['app_secret']) || str_contains((string)$config['app_secret'], 'CAMBIA_')) {
    header('Location: /install.php', true, 303);
    exit;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_name('msastro_shared');
session_start();

function cfg(string $key, mixed $default = null): mixed
{
    global $config;
    return $config[$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $db = cfg('db', []);
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'] ?? 'localhost',
        (int)($db['port'] ?? 3306),
        $db['name'] ?? ''
    );
    $pdo = new PDO($dsn, $db['user'] ?? '', $db['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => true,
    ]);
    // Hostinger puede conservar utf8mb4_general_ci como collation de la sesión,
    // aunque las tablas de la aplicación usan utf8mb4_unicode_ci. Forzamos una
    // sola collation para que parámetros y literales sean comparables.
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    return $pdo;
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string)cfg('app_url', ''), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)), true, 303);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function require_csrf(): void
{
    $token = (string)($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('La sesion vencio o el formulario no es valido. Vuelve a intentarlo.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, password_hash FROM admins WHERE id = ?');
    $stmt->execute([(int)$_SESSION['admin_id']]);
    return $stmt->fetch() ?: null;
}

function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        redirect('admin/login.php');
    }
    return $admin;
}

function request_by_public_id(string $publicId): ?array
{
    $stmt = db()->prepare('SELECT * FROM service_requests WHERE public_id = ?');
    $stmt->execute([$publicId]);
    return $stmt->fetch() ?: null;
}

function zodiac_signs(): array
{
    return [
        'aries' => ['Aries', '♈'], 'tauro' => ['Tauro', '♉'], 'geminis' => ['Géminis', '♊'],
        'cancer' => ['Cáncer', '♋'], 'leo' => ['Leo', '♌'], 'virgo' => ['Virgo', '♍'],
        'libra' => ['Libra', '♎'], 'escorpio' => ['Escorpio', '♏'], 'sagitario' => ['Sagitario', '♐'],
        'capricornio' => ['Capricornio', '♑'], 'acuario' => ['Acuario', '♒'], 'piscis' => ['Piscis', '♓'],
    ];
}

function published_horoscopes(?string $sign = null, int $limit = 12): array
{
    $limit = max(1, min(50, $limit));
    if ($sign !== null && isset(zodiac_signs()[$sign])) {
        $stmt = db()->prepare("SELECT * FROM horoscopes WHERE status='published' AND sign=? ORDER BY published_at DESC, id DESC LIMIT {$limit}");
        $stmt->execute([$sign]);
        return $stmt->fetchAll();
    }
    $rows = db()->query("SELECT * FROM horoscopes WHERE status='published' ORDER BY published_at DESC, id DESC LIMIT 80")->fetchAll();
    $latest = [];
    foreach ($rows as $row) {
        if (!isset($latest[$row['sign']])) {
            $latest[$row['sign']] = $row;
        }
        if (count($latest) >= $limit) {
            break;
        }
    }
    return array_values($latest);
}

function result_ready(array $item): bool
{
    return $item['payment_status'] === 'paid' && trim((string)$item['ai_interpretation']) !== '';
}

function result_token(string $publicId): string
{
    return hash_hmac('sha256', 'resultado|' . $publicId, (string)cfg('app_secret'));
}

function verify_result_token(string $publicId, string $token): bool
{
    return $token !== '' && hash_equals(result_token($publicId), $token);
}

function result_url(array $item): string
{
    return url('resultado.php?id=' . rawurlencode($item['public_id']) . '&token=' . result_token($item['public_id']));
}

function encrypt_secret(string $plain): string
{
    $key = hash('sha256', (string)cfg('app_secret'), true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('No fue posible cifrar la clave.');
    }
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(string $encoded): string
{
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('La clave almacenada no es valida.');
    }
    $key = hash('sha256', (string)cfg('app_secret'), true);
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) {
        throw new RuntimeException('No fue posible descifrar la clave. Revisa APP_SECRET.');
    }
    return $plain;
}

function render_markdown(string $text): string
{
    $safe = e($text);
    $safe = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe) ?? $safe;
    $lines = preg_split('/\R/', $safe) ?: [];
    $html = '';
    $inList = false;
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+)$/', $trim, $m)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $level = min(4, strlen($m[1]) + 1);
            $html .= "<h{$level}>{$m[2]}</h{$level}>";
        } elseif (preg_match('/^(?:[-*]|\d+\.)\s+(.+)$/', $trim, $m)) {
            if (!$inList) { $html .= '<ul>'; $inList = true; }
            $html .= '<li>' . $m[1] . '</li>';
        } else {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<p>' . $trim . '</p>';
        }
    }
    if ($inList) { $html .= '</ul>'; }
    return $html;
}

function send_result_email(array $item): bool
{
    if (!result_ready($item) || !filter_var($item['email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = 'Tu lectura de MS Astrologia esta lista';
    $message = "Hola {$item['full_name']},\n\nTu pago fue confirmado y tu lectura esta disponible:\n" . result_url($item) . "\n\nConserva este enlace privado.";
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    if (cfg('mail_from')) {
        $headers[] = 'From: MS Astrologia <' . cfg('mail_from') . '>';
    }
    return @mail($item['email'], $subject, $message, implode("\r\n", $headers));
}

function render_header(string $title, string $bodyClass = '', bool $adminArea = false): void
{
    $admin = current_admin();
    $flash = take_flash();
    ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title><link rel="icon" href="<?= e(url('assets/ms-logo.png?v=0.5.1')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/styles.css?v=0.5.1')) ?>"><script defer src="<?= e(url('assets/app.js?v=0.5.1')) ?>"></script></head>
<body class="<?= e($bodyClass) ?>"><header class="topbar"><a class="brand" href="<?= e(url()) ?>"><img class="brand-logo" src="<?= e(url('assets/ms-logo.png?v=0.5.1')) ?>" alt="Miguel Salazar Colombia"><span><strong>msastrologia</strong><small>Cartas natales con precisión</small></span></a>
<?php if ($adminArea && $admin): ?><nav class="admin-nav"><a href="<?= e(url('admin/index.php')) ?>">Cartas natales</a><a href="<?= e(url('admin/astrologia.php')) ?>">Motor astrológico</a><a href="<?= e(url('admin/horoscopos.php')) ?>">Horóscopos</a><a href="<?= e(url('admin/ia.php')) ?>">Inteligencia artificial</a><a href="<?= e(url('admin/perfil.php')) ?>">Perfil</a><form action="<?= e(url('admin/logout.php')) ?>" method="post"><?= csrf_field() ?><button class="link-button">Salir</button></form></nav>
<?php else: ?><nav class="public-nav"><a href="<?= e(url('solicitar.php')) ?>">Solicitar carta</a><a href="<?= e(url('mi-solicitud.php')) ?>">Consultar carta</a><a href="<?= e(url('horoscopo.php')) ?>">Horóscopo</a><a href="<?= e(url('admin/login.php')) ?>">Administración</a></nav><?php endif; ?></header><main>
<?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div><?php endif; ?>
<?php
}

function render_footer(): void
{
    ?></main><footer><span>MS Astrologia</span><span>Orientacion simbolica y responsable</span></footer></body></html><?php
}

function auto_install_if_enabled(): void
{
    if (!cfg('auto_install', false)) {
        return;
    }
    $pdo = db();
    $lock = (bool)$pdo->query("SELECT GET_LOCK('msastrologia_auto_install', 30)")->fetchColumn();
    if (!$lock) {
        throw new RuntimeException('Otra instalacion esta en curso. Recarga la pagina en un minuto.');
    }
    try {
        $adminTable = $pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn();
        if (!$adminTable) {
            $schema = file_get_contents(MS_ROOT . '/database/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('No se encontro database/schema.sql.');
            }
            foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
                $pdo->exec($statement);
            }
        }

        $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($adminCount === 0) {
            $username = (string)cfg('bootstrap_admin_username', 'admin');
            $password = (string)cfg('bootstrap_admin_password', '');
            if (strlen($username) < 3 || strlen($password) < 12) {
                throw new RuntimeException('Las credenciales iniciales del administrador no son validas.');
            }
            $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        }

        $places = (int)$pdo->query('SELECT COUNT(*) FROM places')->fetchColumn();
        if ($places < 200000) {
            @set_time_limit(360);
            if (!function_exists('gzopen')) {
                throw new RuntimeException('La extension ZLib de PHP no esta disponible.');
            }
            $handle = gzopen(MS_ROOT . '/database/geodata.sql.gz', 'rb');
            if ($handle === false) {
                throw new RuntimeException('No se pudo abrir database/geodata.sql.gz.');
            }
            $buffer = '';
            try {
                while (!gzeof($handle)) {
                    $line = gzgets($handle);
                    if ($line === false) {
                        break;
                    }
                    $buffer .= $line;
                    if (str_ends_with(rtrim($line), ';')) {
                        $pdo->exec($buffer);
                        $buffer = '';
                    }
                }
                if (trim($buffer) !== '') {
                    $pdo->exec($buffer);
                }
            } finally {
                gzclose($handle);
            }
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('msastrologia_auto_install')");
    }
}

function ensure_database_collation(): void
{
    $pdo = db();
    $expected = 'utf8mb4_unicode_ci';
    $migration = 'utf8mb4_unicode_ci_v1';
    $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration = ? LIMIT 1');
    $check->execute([$migration]);
    if ($check->fetchColumn()) {
        return;
    }
    $managedTables = [
        'admins', 'schema_migrations', 'service_requests', 'astrology_configs', 'ai_configs',
        'aurita_messages', 'horoscopes', 'countries', 'admin1', 'places',
    ];

    // Evitamos comparaciones sobre information_schema dentro de SQL: algunos
    // servidores mezclan allí general_ci y unicode_ci y disparan el error 1267.
    $existingTables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $existingTables[(string)$table] = true;
    }
    $needsConversion = [];
    foreach ($managedTables as $table) {
        if (!isset($existingTables[$table])) {
            continue;
        }
        foreach ($pdo->query("SHOW FULL COLUMNS FROM `{$table}`")->fetchAll() as $column) {
            $collation = (string)($column['Collation'] ?? '');
            if ($collation !== '' && strcasecmp($collation, $expected) !== 0) {
                $needsConversion[$table] = true;
                break;
            }
        }
    }

    foreach (array_keys($needsConversion) as $table) {
        $pdo->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$expected}");
    }
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    $record = $pdo->prepare('INSERT IGNORE INTO schema_migrations (migration) VALUES (?)');
    $record->execute([$migration]);
}

function ensure_feature_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
      migration VARCHAR(100) PRIMARY KEY,
      applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS horoscopes (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      sign VARCHAR(20) NOT NULL,
      period_type ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
      period_label VARCHAR(120) NOT NULL,
      title VARCHAR(180) NOT NULL,
      content LONGTEXT NOT NULL,
      status ENUM('draft','published') NOT NULL DEFAULT 'draft',
      ai_provider VARCHAR(20) NULL,
      ai_model VARCHAR(120) NULL,
      published_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_horoscopes_public (status, sign, published_at),
      INDEX idx_horoscopes_period (period_type, period_label)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS astrology_configs (
      id TINYINT UNSIGNED PRIMARY KEY,
      mode ENUM('github_pages','self_hosted','rapidapi') NOT NULL DEFAULT 'github_pages',
      base_url VARCHAR(255) NOT NULL,
      encrypted_api_key TEXT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $engineMode = null;
    foreach (db()->query('SHOW COLUMNS FROM astrology_configs')->fetchAll() as $column) {
        if (($column['Field'] ?? '') === 'mode') {
            $engineMode = $column;
            break;
        }
    }
    if ($engineMode && !str_contains((string)$engineMode['Type'], 'github_pages')) {
        db()->exec("ALTER TABLE astrology_configs MODIFY mode ENUM('github_pages','self_hosted','rapidapi') NOT NULL DEFAULT 'github_pages'");
    }

    $columns = [];
    foreach (db()->query('SHOW COLUMNS FROM service_requests')->fetchAll() as $column) {
        $columns[(string)$column['Field']] = true;
    }
    $migrations = [
        'chart_svg' => 'ALTER TABLE service_requests ADD COLUMN chart_svg LONGTEXT NULL AFTER request_password_hash',
        'chart_data' => 'ALTER TABLE service_requests ADD COLUMN chart_data LONGTEXT NULL AFTER chart_svg',
        'chart_engine' => 'ALTER TABLE service_requests ADD COLUMN chart_engine VARCHAR(30) NULL AFTER chart_data',
        'chart_generated_at' => 'ALTER TABLE service_requests ADD COLUMN chart_generated_at DATETIME NULL AFTER chart_engine',
    ];
    foreach ($migrations as $name => $sql) {
        if (!isset($columns[$name])) {
            db()->exec($sql);
        }
    }
}

try {
    auto_install_if_enabled();
    ensure_feature_schema();
    ensure_database_collation();
} catch (Throwable $exception) {
    http_response_code(503);
    exit('No fue posible completar la instalacion automatica: ' . e($exception->getMessage()));
}
