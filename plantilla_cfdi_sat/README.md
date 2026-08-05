# Plantilla CFDI 4.0 + Descarga SAT

Carpeta para copiar en un proyecto nuevo. Sustituye los nombres y rutas según tu proyecto.

## Qué incluye

```
plantilla_cfdi_sat/
├── README.md                    # Este archivo: pasos de instalación y personalización
├── composer.plantilla.json      # Dependencias Composer listas (renombrar a composer.json)
├── includes/
│   ├── cfdi_helper.php          # Generación/sellado/timbrado CFDI emitidas (renombrar $pdo a tu conexión)
│   ├── sat_helper.php           # Scraper SAT: login CIEC + captcha + descarga recibidas
│   └── sat_gs_ca.pem            # Certificado intermedio GlobalSign (portal CFDI) - NO borrar
└── web/
    ├── facturas_sat.php         # Página de descarga SAT (login + captcha 2 pasos)
    ├── facturas_todo_zip.php    # ZIP con todos los XML (emitidas + recibidas)
    ├── sat_descargar.php        # Descarga XML/PDF individual
    ├── sat_descargar_zip.php    # ZIP de recibidas
    ├── sat_test_conn.php        # Diagnóstico de conectividad
    └── sat_log.php              # Visor de auditoría
```

## Dependencias del proyecto destino (framework base)

Las páginas de `web/` y los helpers asumen que tu proyecto ya tiene:

| Símbolo | Qué espera | Dónde se define en el proyecto de origen (almacen) |
|---|---|---|
| `$pdo` | Conexión PDO a MySQL (global) | `config.php` |
| `verificarPermiso('pagina')` | Control de acceso por rol | `includes/auth.php` |
| `require 'includes/auth.php'` | Inicia sesión y aplica permisos | `includes/auth.php` |
| `require 'includes/header.php'` / `footer.php` | Layout HTML del sistema | `includes/header.php` / `footer.php` |
| `h($texto)` | Escapar HTML (`htmlspecialchars`) | `includes/funciones.php` o similar |
| `moneda($n)` | Formatear monto con signo y decimales | `includes/funciones.php` o similar |
| `alert($tipo, $msg)` | Mostrar mensaje flash (bootstrap) | `includes/funciones.php` o similar |
| `redirect($url)` | Redirección HTTP | `includes/funciones.php` o similar |
| Clases CSS `btn`, `card`, `table-wrapper` | Estilos Bootstrap del proyecto | Layout |

Si tu proyecto (p. ej. CVL en Next.js/Node) no es PHP, la lógica de
`sat_helper.php` (login CIEC, captcha, descarga) es el contrato a reimplementar
en el backend equivalente: las librerías `phpcfdi/cfdi-sat-scraper` son solo para PHP.

> Los archivos `ventas.php`, `compras.php`, `configuracion.php`, `factura.php`, `prefactura.php`,
> `cfdi_descargar.php` y `deploy_cfdi.ps1` dependen de la estructura de tu proyecto
> (tablas ventas/venta_detalle, compras, clientes, productos). Se reutilizan las funciones de
> `cfdi_helper.php` / `sat_helper.php` en tus propias vistas. Ver guía completa:
> `docs/CFDI_SAT_GUIA.md` del proyecto de origen (almacen).

## Pasos de instalación

1. **Copia** la carpeta `plantilla_cfdi_sat/` dentro de tu proyecto.
2. **Composer**: renombra `composer.plantilla.json` → `composer.json` y ejecuta
   `composer install --no-dev --prefer-dist`.
3. **Extensiones PHP**: `soap`, `xsl`, `zip`, `openssl`, `curl` (en Docker:
   `docker-php-ext-install soap xsl zip`).
4. **Tablas**: ejecuta los `CREATE TABLE`/`ALTER TABLE` documentados en la guía
   (`facturas_emitidas`, `sat_cfdi`, `facturas`, columnas CFDI en `config_empresa`, `clientes`, `productos`).
5. **Configuración del emisor**: RFC, razón social, régimen fiscal, CP, serie, folio, CSD y Finkok.
6. **Uploads**: crea `uploads/csd/`, `uploads/cfdi/`, `uploads/facturas_sat/` (y protégelos con `.gitignore`).

## Personalización rápida

| Cambio | Dónde |
|---|---|
| Nombre de la variable de BD | `$pdo` en ambos helpers (usa `global $pdo`) |
| Ruta de uploads | Constante `__DIR__ . '/../uploads'` en helpers |
| RFC receptor por defecto | `config_empresa.rfc` en `facturas_sat.php` |
| Permisos de página | La llamada `verificarPermiso('facturas_sat')` de tu sistema de roles |
| Forma de pago / método | `cfdiFormaPagoMap()` en `cfdi_helper.php` |
| Serie / folio | `config_empresa.serie_cfdi` y `folio_cfdi` |
| Timbrado | `cfdiTimbrarFinkok()` (o cambia el PAC en esa función) |

## Errores típicos y su solución

- **`SSL certificate problem: unable to get local issuer certificate`** en
  `portalcfdi.facturaelectronica.sat.gob.mx`: el SAT no envía el intermedio GlobalSign.
  Solución ya integrada: `sat_gs_ca.pem` + `sat_ca_bundle()`.
- **Archivos que no se ven tras descargar**: usa rutas absolutas con `__DIR__` (no confíes en el CWD de Apache).
- **`divCaptcha` ausente**: el SAT bloqueó tu IP por reintentos; espera 10-30 min o cambia de IP.
- **El contenedor pierde archivos al recrearse**: monta un volumen para `uploads/` o re-corre el deploy.
