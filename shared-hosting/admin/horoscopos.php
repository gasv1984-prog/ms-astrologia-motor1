<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require MS_ROOT . '/inc/ai.php';
require_admin();

$signs = zodiac_signs();
$periods = ['daily' => 'Diario', 'weekly' => 'Semanal', 'monthly' => 'Mensual'];
$configs = db()->query('SELECT provider, model FROM ai_configs ORDER BY provider')->fetchAll();

function general_horoscope_prompt(string $signName, string $periodName, string $periodLabel, string $focus): string
{
    return "Escribe un horóscopo GENERAL en español para el público de signo {$signName}. Período: {$periodName} ({$periodLabel}). "
        . ($focus !== '' ? "Enfoque editorial: {$focus}. " : '')
        . "Aclara de forma natural que es una orientación colectiva por signo solar y no una lectura de carta natal individual. Usa un tono cálido, elegante, simbólico y práctico. "
        . "Diferencia obligatoriamente los tres decanatos del signo. Usa estos títulos exactos, cada uno en una línea independiente: PANORAMA GENERAL; PRIMER DECANATO · 0°00′ A 9°59′; SEGUNDO DECANATO · 10°00′ A 19°59′; TERCER DECANATO · 20°00′ A 29°59′; VÍNCULOS; TRABAJO Y RECURSOS; BIENESTAR; PREGUNTA PARA INTEGRAR. "
        . "En cada decanato ofrece una orientación diferente y coherente con el período; no repitas el mismo texto cambiando palabras. Explica que el grado exacto del Sol natal permite saber cuál corresponde. "
        . "No hagas afirmaciones deterministas, diagnósticos médicos, predicciones financieras garantizadas ni generes miedo. "
        . "No uses Markdown: no escribas almohadillas, asteriscos, tablas ni bloques de código. Entrega entre 550 y 800 palabras.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        $sign = (string)($_POST['sign'] ?? '');
        $periodType = (string)($_POST['period_type'] ?? 'weekly');
        $periodLabel = trim((string)($_POST['period_label'] ?? ''));
        if (($sign !== 'all' && !isset($signs[$sign])) || !isset($periods[$periodType]) || $periodLabel === '') {
            throw new RuntimeException('Selecciona signo, período y fecha de vigencia.');
        }

        if ($action === 'generate') {
            $provider = (string)($_POST['provider'] ?? '');
            $ai = active_ai_config($provider);
            if (!$ai) {
                throw new RuntimeException('Configura primero el proveedor de IA seleccionado.');
            }
            $focus = trim((string)($_POST['focus'] ?? ''));
            $targets = $sign === 'all' ? array_keys($signs) : [$sign];
            if (count($targets) > 1 && function_exists('set_time_limit')) { @set_time_limit(0); }
            $apiKey = decrypt_secret($ai['encrypted_api_key']);
            $drafts = [];
            $lastId = 0;
            foreach ($targets as $targetSign) {
                $signName = $signs[$targetSign][0];
                $prompt = general_horoscope_prompt($signName, $periods[$periodType], $periodLabel, $focus);
                $content = clean_ai_text(ai_generate($provider, $apiKey, $ai['model'], $prompt));
                $title = $signName . ' · ' . $periodLabel . ' · Tres decanatos';
                $drafts[] = [$targetSign, $periodType, $periodLabel, $title, $content, $provider, $ai['model']];
            }
            db()->beginTransaction();
            try {
                $stmt = db()->prepare("INSERT INTO horoscopes(sign,period_type,period_label,title,content,status,ai_provider,ai_model) VALUES(?,?,?,?,?,'draft',?,?)");
                foreach ($drafts as $draft) {
                    $stmt->execute($draft);
                    $lastId = (int)db()->lastInsertId();
                }
                db()->commit();
            } catch (Throwable $databaseError) {
                if (db()->inTransaction()) { db()->rollBack(); }
                throw $databaseError;
            }
            flash('success', count($targets) === 12 ? 'Se generaron 12 borradores, cada uno con sus tres decanatos. Revísalos antes de publicarlos.' : 'Borrador generado con sus tres decanatos. Revísalo antes de publicarlo.');
            redirect('admin/horoscopos.php' . (count($targets) === 1 ? '?edit=' . $lastId : ''));
        }

        if ($action === 'save') {
            if (!isset($signs[$sign])) { throw new RuntimeException('Selecciona un signo válido para guardar.'); }
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $content = clean_ai_text((string)($_POST['content'] ?? ''));
            $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            if ($id < 1 || strlen($title) < 4 || strlen($content) < 80) {
                throw new RuntimeException('El título o el contenido del horóscopo está incompleto.');
            }
            $publishedAt = $status === 'published' ? 'COALESCE(published_at,NOW())' : 'NULL';
            $stmt = db()->prepare("UPDATE horoscopes SET sign=?,period_type=?,period_label=?,title=?,content=?,status=?,published_at={$publishedAt} WHERE id=?");
            $stmt->execute([$sign, $periodType, $periodLabel, $title, $content, $status, $id]);
            flash('success', $status === 'published' ? 'Horóscopo publicado en la página.' : 'Borrador guardado.');
            redirect('admin/horoscopos.php?edit=' . $id);
        }
        throw new RuntimeException('Acción no válida.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect('admin/horoscopos.php');
    }
}

$items = db()->query('SELECT * FROM horoscopes ORDER BY id DESC LIMIT 40')->fetchAll();
$editId = (int)($_GET['edit'] ?? 0);
render_header('Horóscopos · Administración', 'admin-page', true);
?>
<section class="admin-shell horoscope-admin-shell">
  <div class="page-heading"><div><div class="eyebrow">Contenido editorial con IA</div><h1>Horóscopos</h1></div><p>Genera un signo o los doce de una vez. Cada lectura distingue sus tres decanatos.</p></div>
  <section class="panel horoscope-generator">
    <div class="panel-heading"><span class="step">01</span><div><h2>Generar nuevos borradores</h2><p>La IA redacta por signo y decanato; el administrador conserva el control editorial y la publicación.</p></div></div>
    <?php if (!$configs): ?><div class="alert error">Primero configura OpenAI o Gemini en <a href="<?= e(url('admin/ia.php')) ?>">Inteligencia artificial</a>.</div><?php else: ?>
    <form method="post" class="horoscope-form-grid"><?= csrf_field() ?>
      <label>Signo<select name="sign" required><option value="all">✦ Todos los signos (12)</option><?php foreach ($signs as $key => [$name, $symbol]): ?><option value="<?= e($key) ?>"><?= e($symbol . ' ' . $name) ?></option><?php endforeach; ?></select></label>
      <label>Período<select name="period_type"><option value="daily">Diario</option><option value="weekly" selected>Semanal</option><option value="monthly">Mensual</option></select></label>
      <label>Vigencia<input name="period_label" required value="Semana del <?= e(date('d/m/Y')) ?>" placeholder="Semana del 28/09 al 04/10"></label>
      <label>Proveedor<select name="provider"><?php foreach ($configs as $ai): ?><option value="<?= e($ai['provider']) ?>"><?= e(ucfirst($ai['provider']) . ' · ' . $ai['model']) ?></option><?php endforeach; ?></select></label>
      <label class="full">Enfoque editorial <small>opcional</small><input name="focus" maxlength="240" placeholder="Ejemplo: cambios de ciclo, paciencia y comunicación consciente"></label>
      <p class="full hint">“Todos los signos” realiza 12 generaciones de IA y crea un borrador independiente para cada signo, con primer, segundo y tercer decanato.</p>
      <button class="primary-button full" name="action" value="generate"><span>Generar horóscopos con IA</span><span>✦</span></button>
    </form><?php endif; ?>
  </section>

  <section class="horoscope-admin-list">
    <div class="panel-heading"><span class="step">02</span><div><h2>Revisar y publicar</h2><p>Los borradores no aparecen al público hasta que selecciones Publicado.</p></div></div>
    <?php if (!$items): ?><div class="empty-state"><span>☾</span><h2>Aún no hay horóscopos</h2><p>Genera el primer borrador desde el formulario superior.</p></div><?php endif; ?>
    <?php foreach ($items as $item): $open = $editId === (int)$item['id']; ?>
      <details class="panel horoscope-editor" <?= $open ? 'open' : '' ?>><summary><span class="zodiac-mini"><?= e($signs[$item['sign']][1] ?? '✦') ?></span><span><strong><?= e($item['title']) ?></strong><small><?= e($periods[$item['period_type']] ?? $item['period_type']) ?> · <?= e($item['period_label']) ?></small></span><span class="status status-<?= $item['status'] === 'published' ? 'completed' : 'pending' ?>"><?= e($item['status'] === 'published' ? 'Publicado' : 'Borrador') ?></span></summary>
        <form method="post" class="stack-form horoscope-editor-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($item['id']) ?>">
          <div class="horoscope-form-grid"><label>Signo<select name="sign"><?php foreach ($signs as $key => [$name, $symbol]): ?><option value="<?= e($key) ?>" <?= $item['sign'] === $key ? 'selected' : '' ?>><?= e($symbol . ' ' . $name) ?></option><?php endforeach; ?></select></label><label>Período<select name="period_type"><?php foreach ($periods as $key => $name): ?><option value="<?= e($key) ?>" <?= $item['period_type'] === $key ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></label><label>Vigencia<input name="period_label" value="<?= e($item['period_label']) ?>" required></label><label>Estado<select name="status"><option value="draft" <?= $item['status'] === 'draft' ? 'selected' : '' ?>>Borrador</option><option value="published" <?= $item['status'] === 'published' ? 'selected' : '' ?>>Publicado</option></select></label></div>
          <label>Título<input name="title" value="<?= e($item['title']) ?>" required></label>
          <label>Contenido<textarea name="content" rows="18" required><?= e($item['content']) ?></textarea></label>
          <div class="editor-meta">Generado con <?= e(ucfirst((string)$item['ai_provider'])) ?> · <?= e($item['ai_model']) ?></div>
          <button class="primary-button" name="action" value="save"><span>Guardar cambios</span><span>→</span></button>
        </form>
      </details>
    <?php endforeach; ?>
  </section>
</section>
<?php render_footer();
