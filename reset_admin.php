<?php
require 'config.php';
session_start();
$hash = password_hash('admin123', PASSWORD_DEFAULT);
$pdo->prepare("UPDATE usuarios SET password_hash=? WHERE email='admin@almacenes.com'")->execute([$hash]);
echo "Password de admin@almacenes.com restablecido a: admin123<br>";
echo "<a href='login.php'>Ir al login</a>";