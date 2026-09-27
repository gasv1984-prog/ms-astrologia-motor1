<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';$admin=require_admin();$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    try{if(!password_verify((string)($_POST['current_password']??''),$admin['password_hash'])){throw new RuntimeException('La contrasena actual no coincide.');}$username=trim((string)($_POST['username']??''));$new=(string)($_POST['new_password']??'');if(strlen($username)<3){throw new RuntimeException('El usuario es demasiado corto.');}if($new!==''&&strlen($new)<10){throw new RuntimeException('La nueva contrasena debe tener al menos 10 caracteres.');}$hash=$new!==''?password_hash($new,PASSWORD_DEFAULT):$admin['password_hash'];$stmt=db()->prepare('UPDATE admins SET username=?,password_hash=? WHERE id=?');$stmt->execute([$username,$hash,$admin['id']]);flash('success','Perfil actualizado.');redirect('admin/perfil.php');}catch(Throwable $e){$error=$e->getMessage();}
}
render_header('Perfil administrador','admin-page',true);
?><section class="center-card"><div class="eyebrow">Seguridad</div><h1>Perfil administrador</h1><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><form method="post" class="stack-form"><?=csrf_field()?><label>Usuario<input name="username" required value="<?=e($admin['username'])?>"></label><label>Contrasena actual<input type="password" name="current_password" required></label><label>Nueva contrasena <small>Dejala vacia para conservarla</small><input type="password" name="new_password" minlength="10"></label><button class="primary-button">Guardar perfil</button></form></section><?php render_footer();
