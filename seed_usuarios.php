<?php
$titulo = 'Seed Usuarios';
require 'includes/auth.php';
if ($usuario['rol_nombre'] !== 'admin') { alert('danger', 'Solo admin'); redirect('dashboard.php'); }

echo "<pre>";

// Roles
$roles = ['cliente','almacen','comercial','internacional'];
$stmt_r = $pdo->prepare("INSERT IGNORE INTO roles (nombre) VALUES (?)");
foreach ($roles as $r) $stmt_r->execute([$r]);
echo "Roles creados\n";

// Users
$users = [
    ['Cliente Demo',     'cliente@demo.com',     'cliente123',     'cliente'],
    ['Almacen Demo',     'almacen@demo.com',     'almacen123',     'almacen'],
    ['Comercial Demo',   'comercial@demo.com',   'comercial123',   'comercial'],
    ['Internacional Demo', 'internacional@demo.com', 'internacional123', 'internacional'],
];
$stmt_u = $pdo->prepare("INSERT IGNORE INTO usuarios (nombre, email, password_hash, rol_id, activo) VALUES (?,?,?,(SELECT id FROM roles WHERE nombre=?),1)");
foreach ($users as $u) {
    $hash = password_hash($u[2], PASSWORD_DEFAULT);
    $stmt_u->execute([$u[0], $u[1], $hash, $u[3]]);
    echo "Usuario {$u[0]} ({$u[3]}) creado\n";
}

echo "\nDone. \n\nUsuarios:\n";
foreach ($users as $u) echo "  {$u[1]} / {$u[2]}  -> {$u[3]}\n";
