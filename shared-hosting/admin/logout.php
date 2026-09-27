<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf(); }
$_SESSION=[]; if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);} session_destroy(); redirect('admin/login.php');
