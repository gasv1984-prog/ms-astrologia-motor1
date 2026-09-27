<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_name('msastro_installer');
session_start();

$root = __DIR__;
$configPath = $root . '/config.php';
$schemaPath = $root . '/database/schema.sql';
$geoPath = $root . '/database/geodata.sql.gz';
$existing = is_file($configPath) ? require $configPath : [];

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function installerPdo(array $values): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $values['db_host'],
        (int)$values['db_port'],
        $values['db_name']
    );
    return new PDO($dsn, $values['db_user'], $values['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function applySchema(PDO $pdo, string $path): void
{
    $schema = file_get_contents($path);
    if ($schema === false) {
        throw new RuntimeException('No se encontro database/schema.sql. Vuelve a subir el paquete completo.');
    }
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
        $pdo->exec($statement);
    }
}

function importGeodata(PDO $pdo, string $path): int
{
    if (!function_exists('gzopen')) {
        throw new RuntimeException('Activa la extension ZLib de PHP para importar GeoNames.');
    }
    $handle = gzopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('No se pudo abrir database/geodata.sql.gz.');
    }
    $buffer = '';
    $statements = 0;
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
                $statements++;
            }
        }
        if (trim($buffer) !== '') {
            $pdo->exec($buffer);
            $statements++;
        }
    } finally {
        gzclose($handle);
    }
    return $statements;
}

function writeConfig(string $path, array $data): void
{
    $content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($data, true) . ";\n";
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo escribir config.php. Revisa los permisos de public_html.');
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('No se pudo activar config.php. Revisa los permisos de public_html.');
    }
    @chmod($path, 0640);
}

function existingInstallation(array $config): bool
{
    if (!$config || empty($config['db'])) {
        return false;
    }
    try {
        $pdo = installerPdo([
            'db_host' => $config['db']['host'] ?? 'localhost',
            'db_port' => $config['db']['port'] ?? 3306,
            'db_name' => $config['db']['name'] ?? '',
            'db_user' => $config['db']['user'] ?? '',
            'db_password' => $config['db']['password'] ?? '',
        ]);
        $table = $pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn();
        return $table && (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

$installed = existingInstallation(is_array($existing) ? $existing : []);
$error = null;
$success = null;
$defaultUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'msastrologia.xyz');
$existingUrl = (string)($existing['app_url'] ?? '');
$existingDbName = (string)($existing['db']['name'] ?? '');
$existingDbUser = (string)($existing['db']['user'] ?? '');
$existingMail = (string)($existing['mail_from'] ?? '');
$values = [
    'app_url' => filter_var($existingUrl, FILTER_VALIDATE_URL) ? rtrim($existingUrl, '/') : rtrim($defaultUrl, '/'),
    'db_host' => (string)($existing['db']['host'] ?? 'localhost'),
    'db_port' => (int)($existing['db']['port'] ?? 3306),
    'db_name' => $existingDbName === '' || str_contains($existingDbName, 'u000000000') ? 'u638339419_msastrologia' : $existingDbName,
    'db_user' => $existingDbUser === '' || str_contains($existingDbUser, 'u000000000') ? 'u638339419_msastrologia' : $existingDbUser,
    'admin_username' => 'admin',
    'mail_from' => str_contains($existingMail, 'tudominio.com') ? '' : $existingMail,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    $postedToken = (string)($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $postedToken)) {
        $error = 'La sesion del instalador vencio. Recarga la pagina e intenta de nuevo.';
    } else {
        foreach ($values as $key => $default) {
            if (isset($_POST[$key])) {
                $values[$key] = is_int($default) ? (int)$_POST[$key] : trim((string)$_POST[$key]);
            }
        }
        $dbPassword = (string)($_POST['db_password'] ?? '');
        $adminPassword = (string)($_POST['admin_password'] ?? '');
        try {
            if (!filter_var($values['app_url'], FILTER_VALIDATE_URL) || !str_starts_with($values['app_url'], 'https://')) {
                throw new RuntimeException('La URL debe ser completa y comenzar con https://');
            }
            if ($values['db_name'] === '' || $values['db_user'] === '' || $dbPassword === '') {
                throw new RuntimeException('Completa el nombre, usuario y contrasena de MySQL.');
            }
            if (strlen($values['admin_username']) < 3 || strlen($adminPassword) < 12) {
                throw new RuntimeException('El administrador necesita un usuario de 3 caracteres y una contrasena de al menos 12 caracteres.');
            }
            if (!is_writable($root) && !is_writable($configPath)) {
                throw new RuntimeException('public_html no permite crear config.php. Ajusta sus permisos desde el Administrador de archivos.');
            }

            @set_time_limit(360);
            $connection = [
                'db_host' => $values['db_host'],
                'db_port' => $values['db_port'],
                'db_name' => $values['db_name'],
                'db_user' => $values['db_user'],
                'db_password' => $dbPassword,
            ];
            $pdo = installerPdo($connection);
            applySchema($pdo, $schemaPath);

            $stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
            $stmt->execute([$values['admin_username']]);
            $adminId = $stmt->fetchColumn();
            if ($adminId) {
                $update = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
                $update->execute([password_hash($adminPassword, PASSWORD_DEFAULT), $adminId]);
            } else {
                $insert = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
                $insert->execute([$values['admin_username'], password_hash($adminPassword, PASSWORD_DEFAULT)]);
                $adminId = (int)$pdo->lastInsertId();
            }

            $geoCount = (int)$pdo->query('SELECT COUNT(*) FROM places')->fetchColumn();
            if ($geoCount < 200000) {
                importGeodata($pdo, $geoPath);
                $geoCount = (int)$pdo->query('SELECT COUNT(*) FROM places')->fetchColumn();
            }

            $secret = bin2hex(random_bytes(32));
            $newConfig = [
                'app_url' => rtrim($values['app_url'], '/'),
                'app_secret' => $secret,
                'db' => [
                    'host' => $values['db_host'],
                    'port' => (int)$values['db_port'],
                    'name' => $values['db_name'],
                    'user' => $values['db_user'],
                    'password' => $dbPassword,
                ],
                'mail_from' => $values['mail_from'],
                'default_country_dial_code' => '57',
            ];
            writeConfig($configPath, $newConfig);
            $_SESSION['admin_id'] = (int)$adminId;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $installed = true;
            $success = 'Instalacion terminada. Se importaron ' . number_format($geoCount, 0, ',', '.') . ' localidades.';
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalar MS Astrologia</title><link rel="stylesheet" href="assets/styles.css"><link rel="icon" href="assets/ms-logo.png"></head>
<body><header class="topbar"><a class="brand" href="./"><img class="brand-logo" src="assets/ms-logo.png" alt="Miguel Salazar Colombia"></a></header><main>
<section class="center-card"><div class="eyebrow">Instalador automatico</div><h1>MS Astrologia</h1>
<?php if ($error): ?><div class="alert error"><strong>No se pudo instalar</strong><br><?= h($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?= h($success) ?></div><?php endif; ?>
<?php if ($installed): ?><p>La base, el administrador, el secreto y GeoNames ya estan configurados.</p><a class="primary-button" href="admin/index.php"><span>Abrir administrador</span><span>→</span></a>
<?php else: ?><p>Completa una sola vez los datos creados en Hostinger. El instalador generara <code>config.php</code>, las tablas, GeoNames y el administrador.</p>
<form method="post" class="stack-form" autocomplete="off"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<label>URL del sitio<input name="app_url" required value="<?= h($values['app_url']) ?>"></label>
<label>Servidor MySQL<input name="db_host" required value="<?= h($values['db_host']) ?>"></label>
<label>Puerto MySQL<input type="number" name="db_port" required value="<?= h($values['db_port']) ?>"></label>
<label>Nombre completo de la base<input name="db_name" required value="<?= h($values['db_name']) ?>"></label>
<label>Usuario completo de MySQL<input name="db_user" required value="<?= h($values['db_user']) ?>"></label>
<label>Contrasena MySQL<input type="password" name="db_password" required autocomplete="new-password"><small>Es la contrasena creada en Bases de datos de Hostinger.</small></label>
<label>Usuario administrador<input name="admin_username" required minlength="3" value="<?= h($values['admin_username']) ?>"></label>
<label>Contrasena del administrador<input type="password" name="admin_password" required minlength="12" autocomplete="new-password"><small>Debe ser distinta de la contrasena MySQL.</small></label>
<label>Correo remitente <small>opcional</small><input type="email" name="mail_from" value="<?= h($values['mail_from']) ?>" placeholder="resultados@msastrologia.xyz"></label>
<button class="primary-button"><span>Instalar todo automaticamente</span><span>→</span></button></form>
<p class="data-credit">La importacion de 235.877 localidades puede tardar uno o dos minutos. No cierres la pagina durante el proceso.</p><?php endif; ?></section>
</main><footer><span>MS Astrologia</span><span>Instalacion segura para Hostinger</span></footer></body></html>
