<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$signs = zodiac_signs();
$selected = (string)($_GET['sign'] ?? '');
if ($selected !== '' && !isset($signs[$selected])) {
    $selected = '';
}
$items = published_horoscopes($selected !== '' ? $selected : null, $selected !== '' ? 20 : 12);
render_header('Horóscopo · MS Astrología', 'horoscope-page');
?>
<section class="horoscope-hero">
  <div class="eyebrow">Guía simbólica del momento</div>
  <h1>Consulta tu <em>horóscopo.</em></h1>
  <p>Selecciona tu signo y consulta el panorama general junto con la orientación específica para cada uno de sus tres decanatos.</p>
  <div class="button-row"><a class="primary-button" href="<?= e(url('solicitar.php?servicio=horoscopo_personalizado')) ?>"><span>Solicitar horóscopo personalizado</span><span>→</span></a></div>
</section>
<section class="panel horoscope-personal-cta"><div><div class="eyebrow">Una lectura solo para ti</div><h2>¿Necesitas revisar tus tránsitos personales?</h2><p>La opción personalizada calcula tu carta natal, identifica el decanato mediante el grado exacto del Sol y lo integra con tus casas, aspectos y tránsitos.</p></div><a class="secondary-button" href="<?= e(url('solicitar.php?servicio=horoscopo_personalizado')) ?>">Solicitar lectura personal →</a></section>
<section class="decanate-guide" aria-labelledby="decanate-title"><div><div class="eyebrow">Cómo consultar</div><h2 id="decanate-title">Los tres decanatos de cada signo</h2><p>Busca el grado de tu Sol en tu carta natal. El decanato matiza el signo, pero no reemplaza la lectura completa de casas, aspectos y tránsitos.</p></div><ol><li><strong>Primer decanato</strong><span>0°00′–9°59′</span></li><li><strong>Segundo decanato</strong><span>10°00′–19°59′</span></li><li><strong>Tercer decanato</strong><span>20°00′–29°59′</span></li></ol></section>
<nav class="zodiac-filter" aria-label="Seleccionar signo">
  <a class="<?= $selected === '' ? 'active' : '' ?>" href="<?= e(url('horoscopo.php')) ?>"><span>✦</span><small>Todos</small></a>
  <?php foreach ($signs as $key => [$name, $symbol]): ?><a class="<?= $selected === $key ? 'active' : '' ?>" href="?sign=<?= e($key) ?>"><span><?= e($symbol) ?></span><small><?= e($name) ?></small></a><?php endforeach; ?>
</nav>
<section class="horoscope-public-grid">
  <?php if (!$items): ?><div class="empty-state horoscope-empty"><span>☾</span><h2>Aún no hay una lectura publicada<?= $selected !== '' ? ' para ' . e($signs[$selected][0]) : '' ?>.</h2><p>Vuelve pronto para consultar una nueva guía.</p></div><?php endif; ?>
  <?php foreach ($items as $item): ?>
    <article class="horoscope-public-card reveal">
      <div class="horoscope-card-top"><span class="zodiac-large"><?= e($signs[$item['sign']][1] ?? '✦') ?></span><div><small><?= e($signs[$item['sign']][0] ?? ucfirst($item['sign'])) ?></small><span><?= e($item['period_label']) ?> · incluye los tres decanatos</span></div></div>
      <h2><?= e($item['title']) ?></h2>
      <div class="rich-text"><?= render_markdown($item['content']) ?></div>
      <div class="horoscope-card-footer"><span><?= e($item['period_type'] === 'daily' ? 'Lectura diaria' : ($item['period_type'] === 'monthly' ? 'Lectura mensual' : 'Lectura semanal')) ?></span><span>Orientación simbólica, no determinista</span></div>
    </article>
  <?php endforeach; ?>
</section>
<?php render_footer();
