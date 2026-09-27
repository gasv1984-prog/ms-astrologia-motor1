<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';require MS_ROOT.'/inc/ai.php';require_admin();
$provider=in_array($_POST['provider']??'', ['openai','gemini'],true)?$_POST['provider']:null;$models=[];$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    try{
        if(!$provider){throw new RuntimeException('Proveedor no valido.');}$existing=active_ai_config($provider);$key=trim((string)($_POST['api_key']??''));if($key===''&&$existing){$key=decrypt_secret($existing['encrypted_api_key']);}
        $models=ai_models($provider,$key);
        if(($_POST['action']??'')==='save'){$model=(string)($_POST['model']??'');if(!in_array($model,$models,true)){throw new RuntimeException('Selecciona un modelo disponible.');}$stmt=db()->prepare('INSERT INTO ai_configs(provider,encrypted_api_key,model) VALUES(?,?,?) ON DUPLICATE KEY UPDATE encrypted_api_key=VALUES(encrypted_api_key),model=VALUES(model)');$stmt->execute([$provider,encrypt_secret($key),$model]);flash('success',ucfirst($provider).' verificado y guardado.');redirect('admin/ia.php');}
    }catch(Throwable $e){$error=$e->getMessage();}
}
$saved=[];foreach(db()->query('SELECT provider,model,updated_at FROM ai_configs')->fetchAll() as $row){$saved[$row['provider']]=$row;}
render_header('Inteligencia artificial','admin-page',true);
?><section class="admin-heading"><div><div class="eyebrow">Configuracion segura</div><h1>Proveedores de IA</h1><p>Las claves se comprueban contra el proveedor y se guardan cifradas.</p></div></section><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><section class="provider-grid"><?php foreach(['openai'=>'OpenAI','gemini'=>'Google Gemini'] as $id=>$label):$active=$provider===$id;$row=$saved[$id]??null;?><article class="provider-card"><h2><?=e($label)?></h2><?php if($row):?><p class="success-text">Configurado: <?=e($row['model'])?></p><?php endif;?><form method="post" class="stack-form"><?=csrf_field()?><input type="hidden" name="provider" value="<?=e($id)?>"><label>Clave API<input type="password" name="api_key" placeholder="<?= $row?'Dejar vacio para conservarla':'Pega la clave'?>"></label><?php if($active&&$models):?><label>Modelo<select name="model"><?php foreach($models as $model):?><option value="<?=e($model)?>" <?=($row['model']??'')===$model?'selected':''?>><?=e($model)?></option><?php endforeach;?></select></label><button class="primary-button" name="action" value="save">Guardar configuracion</button><?php else:?><button class="secondary-button" name="action" value="test">Comprobar clave y cargar modelos</button><?php endif;?></form></article><?php endforeach;?></section><?php render_footer();
