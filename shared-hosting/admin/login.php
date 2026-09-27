<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
if (current_admin()) { redirect('admin/index.php'); }
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_csrf();
    try {
        $stmt=db()->prepare('SELECT * FROM admins WHERE username=?'); $stmt->execute([trim((string)($_POST['username']??''))]); $admin=$stmt->fetch();
        if (!$admin || !password_verify((string)($_POST['password']??''),$admin['password_hash'])) { $error='Usuario o contrasena incorrectos.'; }
        else { session_regenerate_id(true); $_SESSION['admin_id']=$admin['id']; redirect('admin/index.php'); }
    } catch (Throwable $exception) {
        $error='No hay conexion con la base de datos. Abre /install.php para repararla: '.$exception->getMessage();
    }
}
render_header('Administracion');
?><section class="login-shell"><div class="login-copy"><div class="eyebrow">Acceso privado</div><h1>Tu observatorio,<br><em>en orden.</em></h1><p>Gestiona solicitudes, pagos y resultados.</p></div><div class="login-card"><h2>Ingresar</h2><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><form method="post" class="stack-form"><?=csrf_field()?><label>Usuario<input name="username" required autofocus></label><label>Contrasena<input type="password" name="password" required></label><button class="primary-button"><span>Entrar</span><span>→</span></button></form></div></section><?php render_footer();
