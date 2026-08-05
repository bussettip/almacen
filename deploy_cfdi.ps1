# Despliegue del modulo CFDI al VPS.
# Copia los archivos de facturacion al contenedor almacen_web,
# instala las extensiones soap/xsl (requeridas por Finkok y cfdiutils),
# ejecuta composer install y crea los directorios de CFDI con permisos.
#
# Uso:  powershell -ExecutionPolicy Bypass -File deploy_cfdi.ps1

$remote = "root@86.38.205.231"
$container = "almacen_web"
$cmd = "docker exec -i -u root $container tee /var/www/html/{0} > /dev/null"

# ssh no esta garantizado en el PATH ni en System32 si PowerShell es de 32 bits.
# Probar rutas conocidas (Sysnative da acceso a la carpeta real de 64 bits desde x86).
$ssh = $null
$candidatos = @(
    "C:\Windows\System32\OpenSSH\ssh.exe",
    "C:\Windows\Sysnative\OpenSSH\ssh.exe"
)
foreach ($c in $candidatos) {
    if (Test-Path $c) { $ssh = $c; break }
}
if (-not $ssh) {
    $cmd = Get-Command ssh -ErrorAction SilentlyContinue
    if ($cmd) { $ssh = $cmd.Source }
}
if (-not $ssh) {
    Write-Host "No se encontro ssh.exe. Instala el cliente OpenSSH (App Opciones) o indica la ruta en el script." -ForegroundColor Red
    exit 1
}
Write-Host "Usando ssh: $ssh"

# Archivos del modulo CFDI: origen local -> destino en el contenedor
$files = @{
    "configuracion.php"        = "configuracion.php"
    "clientes.php"             = "clientes.php"
    "productos.php"            = "productos.php"
    "ventas.php"               = "ventas.php"
    "cfdi_descargar.php"       = "cfdi_descargar.php"
    "sat_descargar.php"        = "sat_descargar.php"
    "sat_descargar_zip.php"    = "sat_descargar_zip.php"
    "sat_debug.php"            = "sat_debug.php"
    "sat_test_conn.php"        = "sat_test_conn.php"
    "sat_probar_flow.php"      = "sat_probar_flow.php"
    "facturas_sat.php"         = "facturas_sat.php"
    "includes\sat_helper.php"  = "includes/sat_helper.php"
    "factura.php"              = "factura.php"
    "prefactura.php"           = "prefactura.php"
    "composer.json"            = "composer.json"
    "composer.lock"            = "composer.lock"
    "includes\cfdi_helper.php" = "includes/cfdi_helper.php"
    ".gitignore"               = ".gitignore"
}

Write-Host "== Copiando archivos al contenedor $container =="
foreach ($f in $files.Keys) {
    $dest = $files[$f]
    Write-Host "-> $dest"
    Get-Content $f -Raw | & $ssh $remote ($cmd -f $dest)
    if ($LASTEXITCODE -ne 0) { Write-Host "ERROR al copiar $dest" -ForegroundColor Red; exit 1 }
}

Write-Host "== Instalando extensiones soap/xsl (ver salida) =="
$installExt = "docker exec -u root $container bash -c 'apt-get update -qq && apt-get install -y -qq libxml2-dev libxslt1-dev && docker-php-ext-install soap xsl && docker-php-ext-enable soap xsl'"
& $ssh $remote $installExt
if ($LASTEXITCODE -ne 0) { Write-Host "ERROR instalando extensiones" -ForegroundColor Red; exit 1 }

Write-Host "== Creando directorios CFDI y permisos =="
$dirs = "docker exec -u root $container bash -c 'mkdir -p /var/www/html/uploads/csd /var/www/html/uploads/cfdi && chown -R www-data:www-data /var/www/html/uploads'"
& $ssh $remote $dirs
if ($LASTEXITCODE -ne 0) { Write-Host "ERROR creando directorios" -ForegroundColor Red; exit 1 }

Write-Host "== Recargando Apache para cargar soap/xsl =="
$reload = "docker exec -u root $container apachectl graceful"
& $ssh $remote $reload
Write-Host "(si la recarga falla, la extension se carga al reiniciar el contenedor)"

Write-Host "== composer install (instala cfdiutils/credentials/finkok) =="
& $ssh $remote "docker exec -u root $container composer install --working-dir=/var/www/html --no-dev --no-interaction --no-progress --prefer-dist"
if ($LASTEXITCODE -ne 0) { Write-Host "ERROR en composer install" -ForegroundColor Red; exit 1 }

Write-Host "== Verificacion de extensiones y dependencias =="
& $ssh $remote "docker exec $container php -m | grep -iE 'soap|xsl'"
& $ssh $remote "docker exec $container ls /var/www/html/vendor/autoload.php"

Write-Host ""
Write-Host "Despliegue CFDI completado." -ForegroundColor Green
Write-Host "Solo falta: subir CSD real y credenciales Finkok desde Configuracion." -ForegroundColor Yellow
