# Guía de integración CFDI 4.0 + Descarga SAT (Experiencia proyecto Almacén)

Documentación basada en la implementación real del proyecto **almacen** (PHP 8.2 + MySQL + Docker).
Sirve para replicar el módulo en otros proyectos (p. ej. CVL) y como referencia de los problemas resueltos.

---

## 1. Conceptos clave

- **CFDI 4.0**: Comprobante Fiscal Digital por Internet (factura electrónica mexicana). Se genera en XML, se **sella** con el CSD del emisor (certificado + llave privada + contraseña), y se **timbra** en un PAC (aquí: Finkok) para obtener el UUID.
- **CFDI emitidas**: facturas que la empresa emite a sus clientes (ventas).
- **CFDI recibidas**: facturas que los proveedores emiten a la empresa (compras). Se obtienen del SAT con RFC + **CIEC** (Clave de Identificación Electrónica Confidencial), resolviendo un **captcha**.
- **CSD**: Certificado de Sello Digital (.cer, .key, contraseña).
- **PAC**: Proveedor Autorizado de Certificación (Finkok, en este proyecto).

## 2. Stack y dependencias

| Requisito | Detalle |
|---|---|
| PHP | >= 8.2 |
| Extensiones PHP | `soap`, `xsl`, `zip`, `openssl`, `curl`, `dom`, `simplexml` |
| MySQL | 8.x (MariaDB compatible) |
| Web server | Apache / PHP-FPM en contenedor (php:8.2-apache) |

Dependencias Composer (`composer.json`):

```json
{
    "require": {
        "php": ">=8.2",
        "phpcfdi/cfdi-sat-scraper": "^5.0",
        "phpcfdi/image-captcha-resolver": "^0.3.0",
        "guzzlehttp/guzzle": "^7.8",
        "php-http/guzzle7-adapter": "^1.0",
        "guzzlehttp/psr7": "^2.6",
        "eclipxe/cfdiutils": "^3.0",
        "phpcfdi/credentials": "^1.3",
        "phpcfdi/finkok": "^0.6"
    }
}
```

Instalar en el servidor: `composer install --no-dev --prefer-dist`.

En Docker (Dockerfile/entrypoint) agregar:

```bash
apt-get update && apt-get install -y libxml2-dev libxslt1-dev \
  && docker-php-ext-install soap xsl zip \
  && docker-php-ext-enable soap xsl zip
```

## 3. Estructura de archivos del módulo

```
proyecto/
├── includes/
│   ├── cfdi_helper.php      # Generación/sellado/timbrado de CFDI emitidas + migración BD
│   └── sat_helper.php       # Scraper SAT: login CIEC, captcha, descarga recibidas, bundle CA
├── ventas.php               # Acción "facturar" (orquesta emitidas)
├── compras.php              # Importación manual de XML de recibidas
├── facturas_sat.php         # Flujo web de descarga del SAT (login + captcha 2 pasos)
├── cfdi_descargar.php       # Descarga XML de emitidas
├── sat_descargar.php        # Descarga XML/PDF individual de recibidas
├── sat_descargar_zip.php    # ZIP de recibidas
├── facturas_todo_zip.php    # ZIP de todas (emitidas + recibidas) para presentar al SAT
├── factura.php              # Vista HTML imprimible de factura timbrada
├── prefactura.php           # Vista HTML imprimible de prefactura
├── configuracion.php        # RFC/CP/régimen emisor, CSD, credenciales Finkok, ambiente
└── uploads/
    ├── csd/                 # csd_cer.cer, csd_key.key (privados, gitignore)
    ├── cfdi/                # XML emitidos (gitignore)
    └── facturas_sat/        # XML/PDF recibidos por mes (YYYY-MM/)
```

## 4. Tablas de base de datos

### `config_empresa` (configuración del emisor, id=1)
`nombre, logo, direccion, telefono, email, rfc, razon_social, regimen_fiscal, codigo_postal, serie_cfdi, folio_cfdi, csd_cer, csd_key, csd_password, finkok_user, finkok_password, finkok_ambiente ('pruebas'|'produccion')`

### `facturas_emitidas`
`id, tipo, venta_id, uuid, serie, folio, rfc_receptor, total, xml_path, estatus ('precfdi'|'timbrado'|'error'), mensaje, creado_por, created_at`

### `sat_cfdi` (recibidas descargadas del SAT)
`id, uuid (UNIQUE), rfc_emisor, nombre_emisor, rfc_receptor, nombre_receptor, fecha_emision, total, estado, archivo (ruta XML), archivo_pdf (ruta PDF), compra_id, usuario_id, created_at`

### `facturas` (recibidas, importadas manualmente o vinculadas a compras)
`id, compra_id, folio_factura, uuid, rfc_emisor, rfc_receptor, monto, fecha_factura, archivo`

### Columnas CFDI agregadas a tablas existentes
- `clientes`: `uso_cfdi, regimen_fiscal_receptor, codigo_postal`
- `productos`: `clave_prod_serv (default '01010101'), clave_unidad (default 'H87'), objeto_impuesto (default '02')`

## 5. Emitidas: generar, sellar y timbrar

Flujo (`ventas.php` → acción `facturar`):

1. Evitar duplicados: `SELECT COUNT(*) FROM facturas_emitidas WHERE venta_id=?`.
2. `cfdiGenerarIngreso($pdo, $venta_id)`:
   - Valida config emisor (razón social, RFC, régimen, CP, CSD).
   - Construye `CfdiCreator40` (serie, folio+1, fecha `America/Mexico_City`, FormaPago, MetodoPago, TipoDeComprobante='I', Exportacion='01', LugarExpedicion, Moneda='MXN').
   - Conceptos desde `venta_detalle` con IVA 16% y descuentos (por línea y global prorrateado).
   - `putCertificado()` con el CSD .cer y sella con `Credential::openFiles($cer,$key,$password)->privateKey()->pem()` + `addSello()`.
3. `cfdiTimbrarFinkok($pdo, $precfdiXml)` → `QuickFinkok->stamp()`. Si no hay credenciales devuelve `no_creds=true` (queda como prefactura).
4. `cfdiGuardarEmitida(...)` → guarda XML en `uploads/cfdi/<uuid>-<venta_id>.xml` y registra en `facturas_emitidas` (estatus `timbrado` o `precfdi`).

Mapa de forma de pago: contado→01/PUE, tarjeta→04/PUE, transferencia→03/PUE, crédito→99/PPD.

## 6. Recibidas: descargar del SAT

### 6.1 Autenticación CIEC con captcha (2 pasos)
La sesión se conserva con `SessionCookieJar('sat_cookie_jar', true)` (persistida en la sesión PHP) para que el captcha valga entre peticiones:

```php
$cookieJar = new SessionCookieJar('sat_cookie_jar', true);
$client = new Client([
    RequestOptions::COOKIES => $cookieJar,
    'curl' => [CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1'],
    'timeout' => 60,
    'connect_timeout' => 30,
    RequestOptions::VERIFY => sat_ca_bundle(),
]);
$gateway = new SatHttpGateway($client, $cookieJar);
$sessionManager = CiecSessionManager::create($rfc, $ciec, $resolverNunca);
$sessionManager->setHttpGateway($gateway);
return new SatScraper($sessionManager, $gateway);
```

Pasos en `facturas_sat.php`:
1. POST con RFC + CIEC sin captcha → `requestCaptchaImage()` → mostrar imagen base64.
2. POST con la respuesta del captcha → `loginPostLoginData($captcha)`.
3. Con sesión iniciada → `listByPeriod(new QueryByFilters(desde, hasta, DownloadType::recibidos()))`.

**Importante:** el resolver debe ser uno que NUNCA resuelva automáticamente el captcha (el usuario lo teclea en el navegador).

### 6.2 Descargar y guardar XML/PDF
```php
$rel = 'uploads/facturas_sat/' . date('Y-m');
$dir = __DIR__ . '/../' . $rel;          // SIEMPRE ruta absoluta por __DIR__
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$scraper->resourceDownloader(ResourceType::xml(), $lista, 10)->saveTo($dir, true, 0777);
$scraper->resourceDownloader(ResourceType::pdf(), $lista, 10)->saveTo($dir, true, 0777);
```
Registrar cada CFDI en `sat_cfdi` (evitando duplicados por UUID) y opcionalmente vincular a la compra correspondiente (proveedor por RFC + mes + monto).

### 6.3 Problema resuelto: TLS del portal CFDI
El SAT **no envía el certificado intermedio** (`GlobalSign RSA OV SSL CA 2018`) y el contenedor no lo tenía → `SSL certificate problem: unable to get local issuer certificate` en `portalcfdi.facturaelectronica.sat.gob.mx` (el login `cfdiau.sat.gob.mx` sí funcionaba).

**Solución: bundle CA combinado.**
1. Descargar el intermedio (DER) y convertirlo a PEM:
   ```
   curl -sSL https://secure.globalsign.com/cacert/gsrsaovsslca2018.crt -o gsrsaovsslca2018.crt
   # convertir DER->PEM (con PHP o openssl)
   ```
2. Guardarlo en `includes/sat_gs_ca.pem` (PEM).
3. `sat_ca_bundle()` genera `uploads/ca_bundle_sat.pem` = intermedio + raíces del sistema (`/etc/ssl/certs/ca-certificates.crt`).
4. Pasar `RequestOptions::VERIFY => sat_ca_bundle()` al cliente Guzzle/curl.

Resultado esperado en la prueba de conectividad: portal CFDI `HTTP 200`.

### 6.4 Otros problemas resueltos
- **Rutas relativas vs Apache**: guardar archivos con rutas absolutas basadas en `__DIR__` (Apache del VPS no siempre tiene el CWD en el document root).
- **Bloqueo temporal del SAT**: tras varios intentos el SAT bloquea la IP (`divCaptcha` ausente en la respuesta). Esperar 10-30 min o usar otra IP. Detectar en el catch con `stripos($html, 'divCaptcha')`.
- **Columna nueva en BD existente**: usar `ALTER TABLE ... ADD COLUMN` con `try/catch` (idempotente) en cada carga de página.

## 7. Diagnóstico (herramientas del proyecto)

| Script | Qué hace |
|---|---|
| `sat_test_conn.php` | Prueba conectividad a login + portal CFDI (IP saliente, HTTP, errores TLS) |
| `sat_cert_chain.php` | Captura la cadena de certificados TLS del portal CFDI y la guarda en PEM |
| `sat_probar_flow.php` | Reproduce el flujo real de captcha (scraper + requestCaptchaImage) y muestra el error exacto |
| `sat_log.php` | Auditoría paso a paso del flujo (uploads/sat_log.txt): scraper creado, captcha, listByPeriod, inserts |
| `sat_debug.php` | Muestra archivos en disco, registros y conteos en sat_cfdi |

## 8. Deploy al servidor (VPS)

Script `deploy_cfdi.ps1`:
1. Copia los archivos del módulo al contenedor: `docker exec -i -u root almacen_web tee /var/www/html/<archivo>`.
2. Instala extensiones `soap`/`xsl` y hace `composer install`.
3. Crea directorios `uploads/csd`, `uploads/cfdi` con permisos `www-data`.
4. `apachectl graceful` y verifica `php -m | grep -iE 'soap|xsl'`.

**Atención con Windows + SSH:**
- `ssh` no siempre está en PATH; usar ruta absoluta:
  `C:\Windows\System32\OpenSSH\ssh.exe` (y fallback `C:\Windows\Sysnative\OpenSSH\ssh.exe` si PowerShell es 32-bit).
- La autenticación es interactiva por contraseña (`root@IP`).

**Atención con contenedores Docker:**
- Los archivos copiados con `docker exec ... tee` viven solo en el contenedor en ejecución. Si se recrea (`docker compose up -d`) **se pierden** → volver a correr el deploy.
- Para persistencia real de `uploads/`, configurar un volumen.

## 9. Checklist de puesta en producción

1. Subir CSD real (.cer + .key + contraseña) en Configuración.
2. Configurar razón social, RFC, régimen fiscal, CP, serie y folio inicial.
3. Configurar credenciales Finkok y ambiente (probar en `pruebas` primero).
4. Probar una venta y verificar UUID timbrado + XML guardado.
5. Probar descarga del SAT con RFC + CIEC + captcha y verificar registros en `sat_cfdi`.
6. Probar ZIP de todas las facturas (presentación ante el SAT).

## 10. Extensión futura
- **Exportaciones / comercio exterior**: el esquema de `facturas_emitidas.tipo='exportacion'` ya existe; reutilizar `cfdi_helper.php` agregando el complemento `ComercioExterior` con el mismo patrón de sellado/timbrado.
- **Validación XSD**: `eclipxe/cfdiutils` trae soporte para validar XML contra los XSD del SAT (aún no se invoca en la app).
- **Persistencia de uploads**: montar volumen Docker para CSD, XML emitidos y recibidos.
