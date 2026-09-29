<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$horoscopePreview = published_horoscopes(null, 3);
render_header('Miguel Salazar · Numerología y astrología', 'landing-page unified-home');
?>
<section class="ms-home-hero" aria-labelledby="home-title">
  <div class="ms-home-hero-bg" aria-hidden="true"></div>
  <div class="ms-home-hero-content reveal">
    <img class="ms-home-logo" src="<?= e(url('assets/logo-ms-numerologia.png?v=0.8.1')) ?>" alt="Miguel Salazar">
    <div class="eyebrow">Numerología · Astrología · Crecimiento personal</div>
    <h1 id="home-title">Miguel Salazar</h1>
    <p class="ms-home-lead">Dos caminos para comprender tus ciclos, reconocer tus talentos y tomar decisiones con mayor claridad.</p>
    <div class="consultation-choices" aria-label="Elige tu consulta">
      <a class="consultation-choice numerology-choice" href="https://wa.me/573137009000?text=Hola%20Miguel%2C%20quiero%20solicitar%20una%20consulta%20de%20numerolog%C3%ADa" target="_blank" rel="noreferrer">
        <span class="choice-icon">№</span><span><small>Camino de vida y ciclos</small><strong>Consulta de numerología</strong></span><b>→</b>
      </a>
      <a class="consultation-choice astrology-choice" href="<?= e(url('solicitar.php')) ?>">
        <span class="choice-icon">✦</span><span><small>Carta calculada con precisión</small><strong>Consulta de carta astral</strong></span><b>→</b>
      </a>
    </div>
    <div class="home-quick-links"><a href="<?= e(url('mi-solicitud.php')) ?>">Consultar una solicitud</a><a href="<?= e(url('horoscopo.php')) ?>">Horóscopo</a><a href="https://wa.me/573137009000?text=Hola%20Miguel%2C%20necesito%20orientaci%C3%B3n%20para%20elegir%20mi%20consulta" target="_blank" rel="noreferrer">Hablar con Miguel</a></div>
  </div>
</section>

<section class="ms-guide-section reveal">
  <div class="ms-guide-photo"><img src="<?= e(url('assets/miguel-pointing.png?v=0.8.1')) ?>" alt="Miguel Salazar, numerólogo y astrólogo"></div>
  <div class="ms-guide-copy"><div class="eyebrow">Una orientación personal</div><h2>Elige la lectura que responde a tu momento.</h2><p>La numerología interpreta la vibración de tu nombre y tu fecha. La carta astral calcula la posición real del cielo en el instante y lugar exactos de tu nacimiento.</p>
    <div class="method-grid">
      <article><span>01</span><h3>Numerología</h3><p>Camino de vida, talentos, desafíos, propósito y ciclo personal actual.</p><a href="https://wa.me/573137009000?text=Hola%20Miguel%2C%20quiero%20mi%20consulta%20de%20numerolog%C3%ADa" target="_blank" rel="noreferrer">Solicitar por WhatsApp →</a></article>
      <article><span>02</span><h3>Carta astral</h3><p>Ascendente, casas Placidus, planetas, nodos, aspectos y una lectura dirigida a ti.</p><a href="<?= e(url('solicitar.php')) ?>">Crear solicitud →</a></article>
    </div>
  </div>
</section>

<section class="precision-band reveal">
  <div><strong>Swiss Ephemeris</strong><span>Cálculo astronómico verificable</span></div><div><strong>Casas Placidus</strong><span>Fecha, hora, coordenadas y zona horaria</span></div><div><strong>Lectura personal</strong><span>Sol, Luna, planetas, ángulos y nodos</span></div><div><strong>Entrega privada</strong><span>Resultado, PDF y conversación con Aurita</span></div>
</section>

<section class="horoscope-home reveal">
  <div class="horoscope-home-heading"><div><div class="eyebrow">Horóscopo general</div><h2>El cielo de hoy, signo por signo.</h2></div><p>Lecturas para los doce signos con orientación diferenciada para sus tres decanatos. El administrador controla su generación y publicación.</p></div>
  <?php if ($horoscopePreview): ?><div class="horoscope-preview-grid"><?php foreach ($horoscopePreview as $item): ?><a href="<?= e(url('horoscopo.php?sign=' . $item['sign'])) ?>"><span><?= e(zodiac_signs()[$item['sign']][1] ?? '✦') ?></span><small><?= e(zodiac_signs()[$item['sign']][0] ?? ucfirst($item['sign'])) ?></small><strong><?= e($item['title']) ?></strong><em><?= e($item['period_label']) ?></em><b>Consultar →</b></a><?php endforeach; ?></div><?php else: ?><div class="horoscope-coming"><span>✦</span><div><strong>Las nuevas lecturas aparecerán aquí.</strong><p>El administrador puede generar y publicar los doce signos desde su panel.</p></div></div><?php endif; ?>
  <div class="button-row"><a class="secondary-button horoscope-all-link" href="<?= e(url('horoscopo.php')) ?>">Consultar todos los signos →</a><a class="primary-button" href="<?= e(url('solicitar.php?servicio=horoscopo_personalizado')) ?>">Solicitar horóscopo personalizado →</a></div>
</section>

<section class="lottery-section reveal" id="resultados">
  <div class="lottery-heading"><div><div class="eyebrow">Servicio informativo</div><h2>Resultados oficiales de loterías</h2></div><p>Consulta los resultados publicados para Colombia. Los datos se cargan desde el servicio público usado por la página original.</p></div>
  <div class="lottery-filters"><label>Fecha del sorteo<input type="date" id="lottery-date"></label><label>Lotería o chance<select id="lottery-filter"><option value="ALL">Mostrar todas</option></select></label><button class="primary-button" type="button" id="lottery-refresh"><span>Consultar resultados</span><span>↻</span></button></div>
  <div id="lottery-status" class="lottery-status" role="status">Selecciona una fecha para consultar.</div><div id="lottery-results" class="lottery-grid" aria-live="polite"></div>
</section>

<section class="payment-confirmation reveal" id="confirmar-pago">
  <div class="payment-orbit" aria-hidden="true"><span></span><span></span></div><div><div class="eyebrow">Confirmación directa</div><h2>¿Ya realizaste el pago?</h2><p>Envía el comprobante junto con tu nombre y código de solicitud. Cuando el pago sea confirmado recibirás por correo y WhatsApp el enlace privado a tu lectura, el PDF y Aurita.</p></div>
  <a class="whatsapp-button" href="https://wa.me/573137009000?text=Hola%20Miguel%2C%20quiero%20confirmar%20el%20pago%20de%20mi%20consulta.%20Adjunto%20el%20comprobante." target="_blank" rel="noreferrer"><span class="whatsapp-icon">◉</span><span>Confirmar pago por WhatsApp<small>+57 313 700 9000</small></span><b>→</b></a>
</section>

<section class="media-showcase reveal">
  <div class="media-heading"><div class="eyebrow">Comunidad MS</div><h2>Videos y orientación para tu crecimiento.</h2><p>Conoce el contenido de Miguel Salazar y sigue las publicaciones de numerología, astrología y ciclos personales.</p></div>
  <div class="media-grid">
    <article class="youtube-panel"><div class="video-frame"><iframe src="https://www.youtube.com/embed/wum8hs6AV2w?si=LoQ65fAvcCpJ7Qkg" title="Video de Miguel Salazar en YouTube" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div><div class="media-card-copy"><span>YouTube</span><h3>Canal oficial MS Numerología</h3><p>Videos, predicciones y herramientas para acompañar tu proceso.</p><a class="youtube-button" href="https://www.youtube.com/@MSnumerologia" target="_blank" rel="noreferrer">Visitar el canal →</a></div></article>
    <article class="tiktok-panel"><div class="tiktok-copy"><span>TikTok</span><h3>@msnumerologia</h3><p>Consulta el perfil y descubre nuevos videos breves de Miguel.</p><a class="tiktok-button" href="https://www.tiktok.com/@msnumerologia" target="_blank" rel="noreferrer">Abrir TikTok →</a></div><blockquote class="tiktok-embed" cite="https://www.tiktok.com/@msnumerologia" data-unique-id="msnumerologia" data-embed-type="creator"><section><a target="_blank" href="https://www.tiktok.com/@msnumerologia?refer=creator_embed">@msnumerologia</a></section></blockquote></article>
  </div>
  <div class="social-links"><a href="https://www.instagram.com/msnumerologia/" target="_blank" rel="noreferrer">Instagram</a><a href="https://www.facebook.com/MiguelSalazarNumerologo/" target="_blank" rel="noreferrer">Facebook</a><a href="https://twitter.com/msnumerologia" target="_blank" rel="noreferrer">X</a></div>
</section>
<script async src="https://www.tiktok.com/embed.js"></script>

<a class="floating-whatsapp" href="https://wa.me/573137009000?text=Hola%20Miguel%2C%20quiero%20informaci%C3%B3n%20sobre%20una%20consulta" target="_blank" rel="noreferrer" aria-label="Consultar por WhatsApp">◉<span>WhatsApp</span></a>
<script>
(() => {
  const dateInput = document.getElementById('lottery-date'); const filter = document.getElementById('lottery-filter'); const refresh = document.getElementById('lottery-refresh'); const status = document.getElementById('lottery-status'); const results = document.getElementById('lottery-results');
  if (!dateInput || !filter || !refresh || !status || !results) return;
  dateInput.value = new Date().toISOString().slice(0, 10); let rows = [];
  const safe = value => String(value ?? '').replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character]));
  function draw() { const selected = filter.value; const visible = selected === 'ALL' ? rows : rows.filter(row => row.lottery === selected); results.innerHTML = visible.map(row => `<article class="lottery-card"><small>${safe(row.date)}</small><h3>${safe(row.lottery)}</h3><strong>${safe(row.result)}</strong>${row.series && row.series !== 'null' ? `<span>Serie ${safe(row.series)}</span>` : ''}</article>`).join(''); status.textContent = visible.length ? `${visible.length} resultados publicados.` : 'No hay resultados publicados para la selección.'; }
  async function load() { status.textContent = 'Consultando resultados…'; results.innerHTML = ''; try { const response = await fetch(`https://api-resultadosloterias.com/api/results/${dateInput.value}`, {headers: {'Accept':'application/json'}}); if (!response.ok) throw new Error('HTTP ' + response.status); const payload = await response.json(); const seen = new Set(); rows = (Array.isArray(payload.data) ? payload.data : []).filter(row => { if (!row.lottery || String(row.lottery).startsWith('5ta-')) return false; const key = `${row.lottery}|${row.result}`; if (seen.has(key)) return false; seen.add(key); return true; }); filter.innerHTML = '<option value="ALL">Mostrar todas</option>'; [...new Set(rows.map(row => row.lottery))].sort().forEach(name => { const option = document.createElement('option'); option.value = name; option.textContent = name; filter.appendChild(option); }); draw(); } catch (error) { rows = []; status.textContent = 'El servicio de resultados no está disponible en este momento. Intenta nuevamente más tarde.'; } }
  refresh.addEventListener('click', load); filter.addEventListener('change', draw); dateInput.addEventListener('change', load); load();
})();
</script>
<?php render_footer();
