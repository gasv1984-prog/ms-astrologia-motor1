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
  <p>Selecciona tu signo y encuentra una lectura creada con IA, revisada y publicada por el administrador de MS Astrología.</p>
</section>
<nav class="zodiac-filter" aria-label="Seleccionar signo">
  <a class="<?= $selected === '' ? 'active' : '' ?>" href="<?= e(url('horoscopo.php')) ?>"><span>✦</span><small>Todos</small></a>
  <?php foreach ($signs as $key => [$name, $symbol]): ?><a class="<?= $selected === $key ? 'active' : '' ?>" href="?sign=<?= e($key) ?>"><span><?= e($symbol) ?></span><small><?= e($name) ?></small></a><?php endforeach; ?>
</nav>
<section class="horoscope-public-grid">
  <?php if (!$items): ?><div class="empty-state horoscope-empty"><span>☾</span><h2>Aún no hay una lectura publicada<?= $selected !== '' ? ' para ' . e($signs[$selected][0]) : '' ?>.</h2><p>Vuelve pronto para consultar una nueva guía.</p></div><?php endif; ?>
  <?php foreach ($items as $item): ?>
    <article class="horoscope-public-card reveal">
      <div class="horoscope-card-top"><span class="zodiac-large"><?= e($signs[$item['sign']][1] ?? '✦') ?></span><div><small><?= e($signs[$item['sign']][0] ?? ucfirst($item['sign'])) ?></small><span><?= e($item['period_label']) ?></span></div></div>
      <h2><?= e($item['title']) ?></h2>
      <div class="rich-text"><?= render_markdown($item['content']) ?></div>
      <div class="horoscope-card-footer"><span><?= e($item['period_type'] === 'daily' ? 'Lectura diaria' : ($item['period_type'] === 'monthly' ? 'Lectura mensual' : 'Lectura semanal')) ?></span><span>Orientación simbólica, no determinista</span></div>
    </article>
  <?php endforeach; ?>
</section>
<?php render_footer();
