<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require MS_ROOT . '/inc/astrology.php';
require_admin();
$current = astrology_config();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $mode = (string)($_POST['mode'] ?? 'github_pages');
        if (!in_array($mode, ['github_pages', 'self_hosted', 'rapidapi'], true)) {
            throw new RuntimeException('Selecciona un tipo de motor válido.');
        }
        $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
        if (in_array($mode, ['github_pages', 'self_hosted'], true) && !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Escribe la URL completa del motor.');
        }
        if ($mode === 'github_pages') {
            $host = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
            if ((string)parse_url($baseUrl, PHP_URL_SCHEME) !== 'https' || !str_ends_with($host, '.github.io')) {
                throw new RuntimeException('El motor gratuito debe usar una dirección HTTPS de GitHub Pages.');
            }
        }
        $plainKey = trim((string)($_POST['api_key'] ?? ''));
        if ($mode === 'rapidapi' && $plainKey === '' && empty($current['encrypted_api_key'])) {
            throw new RuntimeException('RapidAPI requiere una clave.');
        }
        $encrypted = $plainKey !== '' ? encrypt_secret($plainKey) : ($current['encrypted_api_key'] ?? null);
        $stmt = db()->prepare('INSERT INTO astrology_configs (id,mode,base_url,encrypted_api_key) VALUES (1,?,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode),base_url=VALUES(base_url),encrypted_api_key=VALUES(encrypted_api_key)');
        $stmt->execute([$mode, $baseUrl, $encrypted]);
        flash('success', 'Motor astrológico guardado. Ya puedes generar cartas natales.');
        redirect('admin/astrologia.php');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect('admin/astrologia.php');
    }
}
$current = astrology_config();
render_header('Motor astrológico · Administración', 'admin-page', true);
?>
<section class="admin-shell narrow-shell">
  <div class="page-heading"><div><div class="eyebrow">Cálculo preciso de la carta</div><h1>Motor astrológico</h1></div><p>La carta se calcula con datos astronómicos; la inteligencia artificial solo redacta la interpretación después.</p></div>
  <article class="panel profile-panel astrology-engine-panel">
    <form method="post" class="stack-form"><?= csrf_field() ?>
      <label>Tipo de motor
        <select name="mode" data-astrology-mode>
          <option value="github_pages" <?= ($current['mode'] ?? 'github_pages') === 'github_pages' ? 'selected' : '' ?>>GitHub Pages + WebAssembly (gratuito y sin VPS)</option>
          <option value="self_hosted" <?= ($current['mode'] ?? '') === 'self_hosted' ? 'selected' : '' ?>>Servidor propio gratuito (VPS)</option>
          <option value="rapidapi" <?= ($current['mode'] ?? '') === 'rapidapi' ? 'selected' : '' ?>>Astrologer API mediante RapidAPI</option>
        </select>
      </label>
      <label>URL del servidor propio
        <input type="url" name="base_url" value="<?= e($current['base_url'] ?? 'https://gasv1984-prog.github.io/ms-astrologia-motor1/motor') ?>" placeholder="https://gasv1984-prog.github.io/ms-astrologia-motor1/motor">
        <small>Para GitHub Pages usa la dirección publicada del motor. En modo servidor propio no agregues <code>/api/v5</code>.</small>
      </label>
      <label>Clave del motor <small><?= !empty($current['encrypted_api_key']) ? '— hay una clave guardada; déjalo vacío para conservarla' : '— opcional para servidor propio' ?></small>
        <input type="password" name="api_key" autocomplete="new-password" placeholder="Clave privada o X-RapidAPI-Key">
      </label>
      <button class="primary-button"><span>Guardar motor astrológico</span><span>→</span></button>
    </form>
    <div class="notice"><strong>Recomendado:</strong> GitHub Pages sirve Swiss Ephemeris WebAssembly y el navegador realiza el cálculo tropical con casas Placidus, sin VPS ni clave de pago. El servidor propio permanece disponible como alternativa y RapidAPI puede tener costo. Todas las etiquetas y resultados se guardan en <strong>español</strong>.</div>
  </article>
</section>
<?php render_footer();
