<?php require_once __DIR__ . '/../config.php';
$rol = $usuario['rol_nombre'] ?? '';
try { $empresa = $pdo->query("SELECT nombre, logo FROM config_empresa WHERE id=1")->fetch(); } catch (Exception $e) { $empresa = ['nombre'=>'Almacenes','logo'=>null]; }
$empresa_nombre = $empresa ? $empresa['nombre'] : 'Almacenes';
$empresa_logo = $empresa && $empresa['logo'] && file_exists(__DIR__.'/../uploads/empresa/'.$empresa['logo']) ? 'uploads/empresa/'.$empresa['logo'] : '';
$perm = function($pagina) use ($rol) {
    $mapa = [
        'dashboard'     => ['admin','almacen','comercial','internacional','cliente'],
        'usuarios'      => ['admin'],
        'empleados'     => ['admin'],
        'clientes'      => ['admin','comercial'],
        'proveedores'   => ['admin','comercial'],
        'categorias'    => ['admin','almacen'],
        'bancos'        => ['admin','comercial'],
        'configuracion' => ['admin'],
        'almacenes'     => ['admin','almacen'],
        'productos'     => ['admin','almacen'],
        'inventario'    => ['admin','almacen'],
        'ubicaciones'   => ['admin','almacen'],
        'surtir'        => ['admin','almacen'],
        'rotacion'      => ['admin','almacen'],
        'movimientos'   => ['admin','almacen'],
        'entrada'       => ['admin','almacen'],
        'salida'        => ['admin','almacen'],
        'transferencia' => ['admin','almacen'],
        'scanner'       => ['admin','almacen'],
        'qr'            => ['admin','almacen'],
        'compras'       => ['admin','comercial'],
        'ventas'        => ['admin','comercial'],
        'ordenes'       => ['admin','comercial'],
        'tienda'        => ['admin','comercial','cliente'],
        'paqueterias'   => ['admin','comercial'],
        'impuestos'     => ['admin','comercial'],
        'facturas_sat'  => ['admin','comercial'],
        'importaciones' => ['admin','internacional'],
        'exportaciones' => ['admin','internacional'],
    ];
    $roles = $mapa[$pagina] ?? ['admin'];
    return in_array($rol, $roles);
};
$activo = function($pagina) { return basename($_SERVER['SCRIPT_NAME']) === "$pagina.php" ? 'active' : ''; };
$grp = function($items) use ($perm, $activo) {
    $r = '';
    foreach ($items as $pagina => $etiq) {
        if (!$perm($pagina)) continue;
        $r .= "<a href=\"$pagina.php\" class=\"{$activo($pagina)}\">$etiq</a>";
    }
    return $r;
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=h($titulo ?? 'Control de Almacenes')?></title>
    <link rel="stylesheet" href="css/styles.css">
    <style>
    .navbar { background: #5b9bd5; padding: 0; position: sticky; top: 0; z-index: 100; }
    .nav-inner { max-width: 100%; padding: 0 20px; margin: 0 auto; display: flex; align-items: stretch; }
    .nav-brand { color: #fff; font-weight: 700; font-size: 1rem; padding: 14px 18px; text-decoration: none; background: rgba(0,0,0,.1); display: flex; align-items: center; }
    .nav-links { display: flex; flex-wrap: wrap; }
    .nav-group { display: flex; position: relative; }
    .nav-group > .nav-head { color: rgba(255,255,255,.75); font-size: .65rem; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; padding: 14px 10px 14px 14px; cursor: default; user-select: none; display: flex; align-items: center; white-space: nowrap; }
    .nav-group > .nav-head::after { content: ''; display: inline-block; width: 4px; height: 4px; border: solid rgba(255,255,255,.5); border-width: 0 1.5px 1.5px 0; transform: rotate(45deg); margin-left: 5px; margin-top: -2px; }
    .nav-group .nav-sub { display: none; position: absolute; top: 100%; left: 0; background: #4a8bc7; border-radius: 0 0 8px 8px; padding: 6px 0; min-width: 160px; box-shadow: 0 6px 20px rgba(0,0,0,.2); z-index: 200; }
    .nav-group .nav-sub a { display: block; color: #fff; padding: 9px 18px; font-size: .82rem; text-decoration: none; white-space: nowrap; }
    .nav-group .nav-sub a:hover { background: rgba(0,0,0,.12); }
    .nav-group .nav-sub a.active { font-weight: 700; background: rgba(0,0,0,.15); }
    .nav-group:hover .nav-sub { display: block; }
    .nav-group:hover > .nav-head { color: #fff; }
    .nav-user { margin-left: auto; display: flex; align-items: center; gap: 10px; padding: 0 14px; }
    .nav-user span { font-size: .8rem; color: rgba(255,255,255,.8); }
    .nav-user a { color: #fff !important; font-weight: 600; font-size: .82rem; text-decoration: none; }
    .nav-user a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<nav class="navbar">
    <div class="nav-inner">
        <a href="dashboard.php" class="nav-brand"><?php if ($empresa_logo): ?><img src="<?=$empresa_logo?>" alt="" style="height:112px;width:auto;margin-right:12px;"><?php endif; ?><?=h($empresa_nombre)?></a>
        <div class="nav-links">
            <?php if ($perm('dashboard')): ?>
            <div class="nav-group">
                <span class="nav-head">Dashboard</span>
                <div class="nav-sub"><a href="dashboard.php" class="<?=$activo('dashboard')?>">Dashboard</a></div>
            </div>
            <?php endif; ?>
            <?php if ($perm('usuarios') || $perm('empleados') || $perm('clientes') || $perm('proveedores') || $perm('categorias') || $perm('bancos') || $perm('configuracion')): ?>
            <div class="nav-group">
                <span class="nav-head">Administracion</span>
                <div class="nav-sub"><?=$grp(['usuarios'=>'Usuarios','empleados'=>'Empleados','clientes'=>'Clientes','proveedores'=>'Proveedores','categorias'=>'Categorias','bancos'=>'Bancos','configuracion'=>'Configuracion'])?></div>
            </div>
            <?php endif; ?>
            <?php if ($perm('almacenes') || $perm('productos') || $perm('inventario') || $perm('movimientos') || $perm('entrada') || $perm('salida') || $perm('transferencia') || $perm('ubicaciones') || $perm('surtir') || $perm('rotacion')): ?>
            <div class="nav-group">
                <span class="nav-head">Almacen</span>
                <div class="nav-sub"><?=$grp(['almacenes'=>'Almacenes','productos'=>'Productos','inventario'=>'Inventario','surtir'=>'Surtir','rotacion'=>'Rotacion','movimientos'=>'Movimientos','entrada'=>'+ Entrada','salida'=>'- Salida','transferencia'=>'Transferir','ubicaciones'=>'Ubicaciones'])?></div>
            </div>
            <?php endif; ?>
            <?php if ($perm('compras') || $perm('ventas') || $perm('ordenes') || $perm('paqueterias') || $perm('impuestos') || $perm('facturas_sat')): ?>
            <div class="nav-group">
                <span class="nav-head">Contabilidad</span>
                <div class="nav-sub"><?=$grp(['compras'=>'Compras','ventas'=>'Ventas','ordenes'=>'Ordenes','paqueterias'=>'Paqueterias','impuestos'=>'Impuestos','facturas_sat'=>'SAT Facturas'])?></div>
            </div>
            <?php endif; ?>
            <?php if ($perm('tienda')): ?>
            <div class="nav-group">
                <span class="nav-head">Tienda</span>
                <div class="nav-sub"><a href="tienda.php" class="<?=$activo('tienda')?>">Tienda</a></div>
            </div>
            <?php endif; ?>
            <?php if ($perm('importaciones') || $perm('exportaciones')): ?>
            <div class="nav-group">
                <span class="nav-head">Importaciones</span>
                <div class="nav-sub"><?=$grp(['importaciones'=>'Importaciones','exportaciones'=>'Exportaciones'])?></div>
            </div>
            <?php endif; ?>
        </div>
        <div class="nav-user">
            <span><?=h($usuario['nombre'])?> (<?=h($usuario['rol_nombre'])?>)</span>
            <a href="logout.php">Salir</a>
        </div>
    </div>
</nav>
<main class="container">
    <?=showAlert()?>
