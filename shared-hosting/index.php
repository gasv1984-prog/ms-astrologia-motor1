<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$horoscopePreview = published_horoscopes(null, 3);
render_header('MS Astrología · Miguel Salazar', 'landing-page');
?>
<section class="brand-hero cinematic-hero" data-cinematic>
  <div class="cinematic-light cinematic-light-a" aria-hidden="true"></div>
  <div class="cinematic-light cinematic-light-b" aria-hidden="true"></div>
  <div class="cinematic-grain" aria-hidden="true"></div>
  <div class="brand-hero-copy reveal">
    <div class="eyebrow">Astrología para tu vida real</div>
    <h1>Tu mapa.<br><em>Tu ritmo.</em><br>Tu cielo.</h1>
    <p>Comprende tus ciclos, reconoce tu potencial y recibe una guía hecha con precisión, belleza y calidez.</p>
    <div class="hero-actions">
      <a class="primary-button" href="<?= e(url('solicitar.php')) ?>"><span>Solicitar mi carta</span><span>→</span></a>
      <a class="secondary-button" href="<?= e(url('mi-solicitud.php')) ?>">Consultar mi carta</a>
    </div>
  </div>
  <div class="brand-hero-art reveal" data-parallax="0.08" aria-label="Rueda zodiacal decorativa">
    <div class="zodiac-stage">
      <svg class="zodiac-svg" viewBox="0 0 600 600" role="img" aria-labelledby="zodiac-title">
        <title id="zodiac-title">Rueda zodiacal animada de MS Astrología</title>
        <defs><radialGradient id="zodiac-core" cx="38%" cy="32%"><stop offset="0" stop-color="#f3dda7"/><stop offset="1" stop-color="#cba85e"/></radialGradient><filter id="zodiac-glow"><feGaussianBlur stdDeviation="4" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>
        <circle class="zodiac-halo" cx="300" cy="300" r="276"/>
        <g class="zodiac-orbit zodiac-orbit-outer">
          <circle cx="300" cy="300" r="248"/><circle cx="300" cy="300" r="205"/><circle cx="300" cy="300" r="145" class="dashed"/>
          <?php for ($angle = 0; $angle < 360; $angle += 30): ?><line x1="300" y1="52" x2="300" y2="155" transform="rotate(<?= $angle ?> 300 300)"/><?php endfor; ?>
        </g>
        <g class="zodiac-symbols" filter="url(#zodiac-glow)"><text x="300" y="75">♈</text><text x="418" y="108">♉</text><text x="500" y="192">♊</text><text x="530" y="310">♋</text><text x="500" y="428">♌</text><text x="418" y="512">♍</text><text x="300" y="545">♎</text><text x="182" y="512">♏</text><text x="100" y="428">♐</text><text x="70" y="310">♑</text><text x="100" y="192">♒</text><text x="182" y="108">♓</text></g>
        <g class="zodiac-orbit zodiac-orbit-inner"><circle cx="300" cy="300" r="124"/><circle cx="300" cy="300" r="104" class="dashed"/></g>
        <circle class="zodiac-core" cx="300" cy="300" r="92" fill="url(#zodiac-core)"/>
        <text class="zodiac-ms" x="300" y="305">MS</text><text class="zodiac-caption" x="300" y="340">CARTA NATAL</text>
        <g class="zodiac-stars"><circle cx="85" cy="98" r="3"/><circle cx="515" cy="150" r="2"/><circle cx="525" cy="455" r="3"/><circle cx="115" cy="470" r="2"/></g>
      </svg>
    </div>
  </div>
</section>

<div class="landing-ticker" aria-label="Especialidades"><span>Carta astral precisa</span><span>✦</span><span>Numerología consciente</span><span>✦</span><span>Orientación personal</span></div>

<section class="landing-section services-cinema" id="servicios">
  <div class="landing-heading reveal">
    <div><div class="eyebrow">Servicios</div><h2>Una lectura para cada momento.</h2></div>
    <p>Los datos de fecha, hora y lugar se usan para preparar una lectura personal. La interpretación combina técnica astrológica, contexto y una conversación cercana.</p>
  </div>
  <div class="service-grid service-grid-detailed">
    <article class="service-card reveal" data-tilt>
      <span class="service-number">01</span><span class="service-symbol">☉</span>
      <small>Carta natal</small><h3>Tu mapa de origen</h3>
      <p>Una lectura integral de tu mapa de nacimiento: identidad, mundo emocional, vínculos, vocación, recursos y patrones de crecimiento.</p>
      <div class="service-meta"><strong>Ideal para</strong><p>Comprenderte mejor, tomar decisiones o iniciar un proceso personal.</p></div>
      <ul class="service-includes"><li>Ejes esenciales de la carta</li><li>Fortalezas y desafíos</li><li>Preguntas prácticas para integrar</li></ul>
      <a href="<?= e(url('solicitar.php')) ?>">Solicitar lectura →</a>
    </article>
    <article class="service-card reveal" data-tilt>
      <span class="service-number">02</span><span class="service-symbol">∞</span>
      <small>Sinastría</small><h3>El lenguaje del vínculo</h3>
      <p>Compara dos cartas para observar afinidades, necesidades afectivas, comunicación, atracción, tensiones y aprendizajes compartidos.</p>
      <div class="service-meta"><strong>Ideal para</strong><p>Parejas, vínculos familiares o relaciones significativas.</p></div>
      <ul class="service-includes"><li>Puntos de encuentro</li><li>Dinámicas de comunicación</li><li>Claves para cuidar el vínculo</li></ul>
      <a href="https://wa.me/573137009000?text=Hola%20Miguel%2C%20quiero%20consultar%20una%20sinastria" target="_blank" rel="noreferrer">Consultar servicio →</a>
    </article>
    <article class="service-card reveal" data-tilt>
      <span class="service-number">03</span><span class="service-symbol">↻</span>
      <small>Retorno solar</small><h3>Los temas de tu nuevo año</h3>
      <p>Lectura del cielo de tu cumpleaños para reconocer el tono del año, sus áreas protagonistas, oportunidades y decisiones importantes.</p>
      <div class="service-meta"><strong>Ideal para</strong><p>Cumpleaños, cierres de ciclo y planificación del nuevo año.</p></div>
      <ul class="service-includes"><li>Tema central del año</li><li>Áreas de expansión y cuidado</li><li>Orientación por ciclos</li></ul>
      <a href="https://wa.me/573137009000?text=Hola%20Miguel%2C%20quiero%20consultar%20un%20retorno%20solar" target="_blank" rel="noreferrer">Consultar servicio →</a>
    </article>
    <article class="service-card reveal" data-tilt>
      <span class="service-number">04</span><span class="service-symbol">№</span>
      <small>Numerología</small><h3>Ritmos, talentos y propósito</h3>
      <p>Explora la vibración simbólica de tu nombre y fecha: talentos, motivaciones, aprendizajes y ciclos que acompañan tu proceso.</p>
      <div class="service-meta"><strong>Ideal para</strong><p>Clarificar propósito, transiciones o proyectos personales.</p></div>
      <ul class="service-includes"><li>Camino de vida</li><li>Talentos y aprendizajes</li><li>Ciclo personal actual</li></ul>
      <a href="https://wa.me/573137009000?text=Hola%20Miguel%2C%20quiero%20consultar%20numerologia" target="_blank" rel="noreferrer">Consultar servicio →</a>
    </article>
  </div>
</section>

<section class="horoscope-home reveal">
  <div class="horoscope-home-heading"><div><div class="eyebrow">Nuevo · Actualizado por el administrador</div><h2>El cielo de hoy, signo por signo.</h2></div><p>Lecturas generales para los doce signos con orientación diferenciada para sus tres decanatos.</p></div>
  <?php if ($horoscopePreview): ?><div class="horoscope-preview-grid"><?php foreach ($horoscopePreview as $item): ?><a href="<?= e(url('horoscopo.php?sign=' . $item['sign'])) ?>"><span><?= e(zodiac_signs()[$item['sign']][1] ?? '✦') ?></span><small><?= e(zodiac_signs()[$item['sign']][0] ?? ucfirst($item['sign'])) ?></small><strong><?= e($item['title']) ?></strong><em><?= e($item['period_label']) ?></em><b>Consultar →</b></a><?php endforeach; ?></div><?php else: ?><div class="horoscope-coming"><span>✦</span><div><strong>Próximamente encontrarás aquí las lecturas publicadas.</strong><p>El administrador puede generar el primer horóscopo desde su panel.</p></div></div><?php endif; ?>
  <div class="button-row"><a class="secondary-button horoscope-all-link" href="<?= e(url('horoscopo.php')) ?>">Consultar todos los signos →</a><a class="primary-button" href="<?= e(url('solicitar.php?servicio=horoscopo_personalizado')) ?>">Solicitar horóscopo personalizado →</a></div>
</section>

<section class="payment-confirmation reveal" id="confirmar-pago">
  <div class="payment-orbit" aria-hidden="true"><span></span><span></span></div>
  <div>
    <div class="eyebrow">Confirmación directa</div>
    <h2>¿Ya realizaste el pago?</h2>
    <p>Envía el comprobante por WhatsApp junto con tu nombre y código de solicitud. Miguel validará el pago y habilitará la entrega cuando tu lectura esté lista.</p>
  </div>
  <a class="whatsapp-button" href="https://wa.me/573137009000?text=Hola%20Miguel%2C%20quiero%20confirmar%20el%20pago%20de%20mi%20lectura.%20Adjunto%20el%20comprobante." target="_blank" rel="noreferrer"><span class="whatsapp-icon">◉</span><span>Confirmar pago por WhatsApp<small>+57 313 700 9000</small></span><b>→</b></a>
</section>

<section class="about-miguel reveal">
  <div class="miguel-logo"><img src="<?= e(url('assets/ms-logo.png')) ?>" alt="Miguel Salazar Colombia"></div>
  <div><div class="eyebrow">Miguel Salazar</div><h2>Una lectura rigurosa, humana y sin determinismos.</h2><p>Un espacio de autoconocimiento con lecturas pensadas para conversar contigo, no para encasillarte.</p><div class="hero-actions"><a class="primary-button" href="<?= e(url('solicitar.php')) ?>"><span>Comenzar solicitud</span><span>→</span></a><a class="secondary-button" href="https://wa.me/573137009000?text=Hola%20Miguel%2C%20quiero%20conocer%20tus%20servicios" target="_blank" rel="noreferrer">Hablar por WhatsApp</a></div></div>
</section>
<?php render_footer();
