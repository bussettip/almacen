# Despliegue del modulo CFDI al VPS.
# Copia los archivos de facturacion al contenedor almacen_web,
# instala las extensiones soap/xsl (requeridas por Finkok y cfdiutils),
# ejecuta composer install y crea los directorios de CFDI con permisos.
#
# Uso:  powershell -ExecutionPolicy Bypass -File deploy_cfdi.ps1

$remote = "root@86.38.205.231"
$container = "almacen_web"
$cmd = "docker exec -i -u root $container tee /var/www/html/{0} > /dev/null"

# Archivos del modulo CFDI: origen local -> destino en el contenedor
$files = @{
    "configuracion.php"        = "configuracion.php"
    "clientes.php"             = "clientes.php"
    "productos.php"            = "productos.php"
    "ventas.php"               = "ventas.php"
    "cfdi_descargar.php"       = "cfdi_descargar.php"
    "composer.json"            = "composer.json"
    "composer.lock"            = "composer.lock"
    "includes\cfdi_helper.php" = "includes/cfdi_helper.php"
    ".gitignore"               = ".gitignore"
}

Write-Host "== Copiando archivos al contenedor $container =="
foreach ($f in $files.Keys) {
    $dest = $files[$f]
    Write-Host "-> $dest"
    Get-Content $f -Raw | ssh $remote ($cmd -f $dest)
    if ($LASTEXITCODE -ne 0) { Write-Host "ERROR al copiar $dest" -ForegroundColor Red; exit 1 }
}

Write-Host "== Instalando extensiones soap/xsl y creando directorios =="
$setup = "docker exec -u root $container bash -lc 'apt-get update -qq && apt-get install -y -qq libxml2-dev libxslt1-dev >/dev/null 2>&1 && docker-php-ext-install soap xsl >/dev/null 2>&1 && docker-php-ext-enable soap xsl >/dev/null 2>&1 && mkdir -p /var/www/html/uploads/csd /var/www/html/uploads/cfdi && chown -R www-data:www-data /var/www/html/uploads && apachectl restart >/dev/null 2>&1'"
ssh $remote $setup
if ($LASTEXITCODE -ne 0) { Write-Host "ERROR en el setup de extensiones" -ForegroundColor Red; exit 1 }

Write-Host "== composer install (instala cfdiutils/credentials/finkok) =="
ssh $remote "docker exec -u root $container composer install --working-dir=/var/www/html --no-dev --no-interaction --no-progress --prefer-dist"
if ($LASTEXITCODE -ne 0) { Write-Host "ERROR en composer install" -ForegroundColor Red; exit 1 }

Write-Host "== Verificacion de extensiones y dependencias =="
ssh $remote "docker exec $container php -m | grep -iE 'soap|xsl'"
ssh $remote "docker exec $container php -r 'require \"/var/www/html/vendor/autoload.php\"; echo \"Finkok/cfdi SDK OK\n\";'"

Write-Host ""
Write-Host "Despliegue CFDI completado." -ForegroundColor Green
Write-Host "Solo falta: subir CSD real y credenciales Finkok desde Configuracion." -ForegroundColor Yellow
