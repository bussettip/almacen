$remote = "root@86.38.205.231"
$cmd = "docker exec -i -u root almacen_web tee /var/www/html/{0} > /dev/null"

$files = @{
    "productos.php" = "productos.php"
    "tienda.php" = "tienda.php"
    "ventas.php" = "ventas.php"
    "compras.php" = "compras.php"
    "impuestos.php" = "impuestos.php"
    "prefactura.php" = "prefactura.php"
    "qr_lookup.php" = "qr_lookup.php"
    "placeholder_img.php" = "placeholder_img.php"
    "seed_productos_imagenes.php" = "seed_productos_imagenes.php"
    "seed_usuarios.php" = "seed_usuarios.php"
    "reset_admin.php" = "reset_admin.php"
    "setup.php" = "setup.php"
    "includes\auth.php" = "includes/auth.php"
    "includes\header.php" = "includes/header.php"
}

foreach ($f in $files.Keys) {
    $dest = $files[$f]
    Write-Host "-> $dest"
    Get-Content $f -Raw | ssh $remote ($cmd -f $dest)
}