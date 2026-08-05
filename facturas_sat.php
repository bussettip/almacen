<?php
$titulo = 'Facturas del SAT';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));
require_once 'includes/sat_helper.php';

use PhpCfdi\CfdiSatScraper\Sessions\Ciec\CiecLoginException;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sat_cfdi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) UNIQUE,
        rfc_emisor VARCHAR(20),
        nombre_emisor VARCHAR(255),
        rfc_receptor VARCHAR(20),
        nombre_receptor VARCHAR(255),
        fecha_emision DATE,
        total DECIMAL(12,2) DEFAULT 0,
        estado VARCHAR(50),
        archivo VARCHAR(255),
        compra_id INT,
        usuario_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    // Si la tabla compras aun no existe, crear sat_cfdi sin la llave foranea
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sat_cfdi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            uuid VARCHAR(36) UNIQUE,
            rfc_emisor VARCHAR(20),
            nombre_emisor VARCHAR(255),
            rfc_receptor VARCHAR(20),
            nombre_receptor VARCHAR(255),
            fecha_emision DATE,
            total DECIMAL(12,2) DEFAULT 0,
            estado VARCHAR(50),
            archivo VARCHAR(255),
            compra_id INT,
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e2) {}
}

// Migracion: columna archivo_pdf (para guardar el PDF descargado del SAT)
try { $pdo->exec("ALTER TABLE sat_cfdi ADD COLUMN archivo_pdf VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}

$cfg = $pdo->query("SELECT rfc FROM config_empresa WHERE id=1")->fetch();
$rfc_empresa = $cfg ? trim((string)($cfg['rfc'] ?? '')) : '';

$descargados = [];
$error = '';
$mostrar_captcha = false;
$captcha_img = '';
$captcha_mime = 'image/png';
$cienc_expirada = false;

// Limpiar estado de captcha si se pide refrescarlo
if (isset($_GET['refresh']) && $_GET['refresh'] === '1') {
    unset($_SESSION['sat_captcha_image'], $_SESSION['sat_captcha_mime'], $_SESSION['sat_captcha_espera']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ciec = trim($_POST['ciec'] ?? '');
    $desde = trim($_POST['desde'] ?? '');
    $hasta = trim($_POST['hasta'] ?? '');
    $captcha = trim($_POST['captcha'] ?? '');

    if ($rfc_empresa === '') {
        $error = 'Configura el RFC de la empresa en Configuracion antes de descargar.';
    } elseif ($ciec === '') {
        $error = 'Ingresa la clave CIEC.';
    } elseif ($desde === '' || $hasta === '') {
        $error = 'Indica el periodo (fecha inicial y final).';
    } elseif ($desde > $hasta) {
        $error = 'La fecha inicial no puede ser mayor que la fecha final.';
    } else {
        $scraper = null;
        try {
            $scraper = sat_crear_scraper($rfc_empresa, $ciec);
            sat_log("Scraper creado para RFC=$rfc_empresa");

            // PASO 1: no hay respuesta de captcha aun -> pedir el captcha
            if (empty($_SESSION['sat_captcha_espera'])) {
                if ($captcha === '') {
                    $img = sat_obtener_captcha($scraper);
                    sat_log("Captcha solicitado OK (mime=" . $img->getMimeType() . ")");
                    $_SESSION['sat_captcha_espera'] = true;
                    $_SESSION['sat_captcha_image'] = $img->asBase64();
                    $_SESSION['sat_captcha_mime'] = $img->getMimeType();
                    $mostrar_captcha = true;
                    $captcha_img = $_SESSION['sat_captcha_image'];
                    $captcha_mime = $_SESSION['sat_captcha_mime'];
                } else {
                    $error = 'El captcha no era necesario; intenta de nuevo.';
                    unset($_SESSION['sat_captcha_espera'], $_SESSION['sat_captcha_image'], $_SESSION['sat_captcha_mime']);
                }
            } else {
                // PASO 2: el usuario ya vio el captcha, enviar la respuesta
                if ($captcha === '') {
                    $mostrar_captcha = true;
                    $captcha_img = $_SESSION['sat_captcha_image'] ?? '';
                    $captcha_mime = $_SESSION['sat_captcha_mime'] ?? 'image/png';
                    $error = 'Escribe el texto del captcha para continuar.';
                } else {
                    try {
                        sat_enviar_captcha($scraper, $captcha);
                        sat_log("Captcha enviado y sesion iniciada");
                    } catch (CiecLoginException $e) {
                        sat_log("Captcha rechazado por el SAT: " . $e->getMessage());
                        unset($_SESSION['sat_captcha_espera'], $_SESSION['sat_captcha_image'], $_SESSION['sat_captcha_mime']);
                        throw $e;
                    }

                    // Sesion iniciada: descargar CFDI recibidos del periodo
                    $descargados = sat_descargar_recibidos($scraper, $desde, $hasta);

                    unset($_SESSION['sat_captcha_espera'], $_SESSION['sat_captcha_image'], $_SESSION['sat_captcha_mime']);

                    $vinculados = 0;
                    $insertados = [];
                    foreach ($descargados as $m) {
                        $uuid = $m['uuid'] ?? '';
                        $archivo = $m['archivo_xml'] ?? '';
                        $archivo_pdf = $m['archivo_pdf'] ?? '';
                        if ($archivo !== '' && !file_exists(__DIR__ . '/' . $archivo)) { $archivo = ''; }
                        if ($archivo_pdf !== '' && !file_exists(__DIR__ . '/' . $archivo_pdf)) { $archivo_pdf = ''; }

                        if (sat_registrar_cfdi($m, $archivo, $archivo_pdf)) {
                            $idNuevo = (int)$pdo->lastInsertId();
                            sat_log("Insertado CFDI id=$idNuevo uuid=$uuid archivo=$archivo pdf=$archivo_pdf");
                            $insertados[] = [
                                'id' => $idNuevo,
                                'uuid' => $uuid,
                                'nombre_emisor' => $m['nombreEmisor'] ?? '',
                                'rfc_emisor' => $m['rfcEmisor'] ?? '',
                                'fecha_emision' => substr((string)($m['fechaEmision'] ?? ''), 0, 10),
                                'total' => isset($m['total']) ? (float)$m['total'] : 0,
                                'estado' => $m['estadoComprobante'] ?? '',
                                'archivo' => $archivo,
                                'archivo_pdf' => $archivo_pdf,
                            ];
                            $compraId = sat_buscar_compra(
                                $m['rfcEmisor'] ?? '',
                                $m['fechaEmision'] ?? '',
                                isset($m['total']) ? (float)$m['total'] : 0
                            );
                            if ($compraId) {
                                if (sat_vincular_a_compra($m, $compraId, $archivo)) {
                                    $pdo->prepare("UPDATE sat_cfdi SET compra_id=? WHERE uuid=?")
                                        ->execute([$compraId, $uuid]);
                                    $vinculados++;
                                }
                            }
                        }
                    }
                    if (count($descargados) === 0) {
                        $error = 'No se encontraron CFDI recibidos en el periodo.';
                    } else {
                        alert('success', count($descargados) . ' CFDI descargados, ' . $vinculados . ' vinculados a compras.');
                    }
                }
            }
        } catch (CiecLoginException $e) {
            sat_log("CiecLoginException: " . $e->getMessage());
            // Diagnosticar la respuesta del SAT: si no trae captcha es probable bloqueo por intentos
            $html = $e->getContents();
            $msg = $e->getMessage();
            if (stripos($html, 'divCaptcha') === false) {
                $msg .= ' | La pagina del SAT no mostro captcha: posible bloqueo temporal por varios intentos. Espera 10-30 min y reintenta, o usa otra IP.';
            }
            if ($mostrar_captcha && !empty($_SESSION['sat_captcha_image'])) {
                $error = 'El captcha o la clave CIEC son incorrectos. Intenta de nuevo.';
            } else {
                $error = 'No se pudo iniciar sesion en el SAT: ' . $msg;
            }
        } catch (Throwable $e) {
            sat_log("Error general: " . get_class($e) . ": " . $e->getMessage());
            $error = 'Error al descargar: ' . $e->getMessage();
        }
    }
} elseif (!empty($_SESSION['sat_captcha_espera']) && !empty($_SESSION['sat_captcha_image'])) {
    // Captcha pendiente de un intento anterior
    $mostrar_captcha = true;
    $captcha_img = $_SESSION['sat_captcha_image'];
    $captcha_mime = $_SESSION['sat_captcha_mime'] ?? 'image/png';
}

$recientes = $pdo->query(
    "SELECT s.*, c.folio AS compra_folio, p.nombre AS compra_proveedor
     FROM sat_cfdi s
     LEFT JOIN compras c ON c.id = s.compra_id
     LEFT JOIN proveedores p ON p.id = c.proveedor_id
     ORDER BY s.created_at DESC LIMIT 50"
)->fetchAll();

require 'includes/header.php';
?>
<div class="card" style="max-width: 720px;">
    <div class="card-header"><h2>Descargar CFDI del SAT</h2></div>
    <p style="padding:0 16px;font-size:.85rem;color:#555;">
        Descarga las facturas <strong>recibidas</strong> (proveedores) del periodo indicado.
        El RFC del receptor se toma de <strong>Configuracion</strong>.
        Los CFDI se vinculan automaticamente a las compras cuando el RFC del proveedor, mes y total coinciden.
    </p>
    <form method="post">
        <div class="form-row">
            <div class="form-group">
                <label>RFC (receptor)</label>
                <input type="text" value="<?=h($rfc_empresa)?>" readonly>
            </div>
            <div class="form-group">
                <label>Clave CIEC *</label>
                <input type="password" name="ciec" required autocomplete="off"
                       placeholder="Clave del SAT"
                       value="<?=h($_POST['ciec'] ?? '')?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Fecha inicial *</label>
                <input type="date" name="desde" required value="<?=h($_POST['desde'] ?? '')?>">
            </div>
            <div class="form-group">
                <label>Fecha final *</label>
                <input type="date" name="hasta" required value="<?=h($_POST['hasta'] ?? '')?>">
            </div>
        </div>
        <?php if ($mostrar_captcha): ?>
        <div class="form-row">
            <div class="form-group">
                <label>Captcha del SAT *</label>
                <div style="margin-bottom:8px;">
                    <?php if ($captcha_img): ?>
                    <img src="data:<?=$captcha_mime?>;base64,<?=$captcha_img?>"
                         alt="Captcha del SAT" style="border:1px solid #ccc;border-radius:6px;">
                    <?php endif; ?>
                </div>
                <input type="text" name="captcha" autocomplete="off" placeholder="Escribe el texto del captcha">
            </div>
        </div>
        <?php endif; ?>
        <div style="padding:0 16px 16px;display:flex;gap:8px;align-items:center;">
            <button type="submit" class="btn btn-primary">
                <?= $mostrar_captcha ? 'Enviar captcha y descargar' : 'Solicitar captcha' ?>
            </button>
            <?php if ($mostrar_captcha): ?>
            <a href="facturas_sat.php?refresh=1" class="btn btn-secondary">Nuevo captcha</a>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($mostrar_captcha): ?>
    <p style="padding:0 16px 16px;font-size:.8rem;color:#c0392b;">
        El SAT solicito un captcha. Escribelo arriba y pulsa "Enviar captcha y descargar".
    </p>
    <?php endif; ?>
</div>

<?php if (!empty($insertados)): ?>
<div class="card">
    <div class="card-header"><h2>Recien descargados (<?=count($insertados)?>)</h2></div>
    <div class="table-wrapper">
        <table id="tabla-descargados">
            <tr>
                <th>UUID</th>
                <th>Emisor</th>
                <th>RFC</th>
                <th>Fecha</th>
                <th>Total</th>
                <th>Estado</th>
                <th>Descargar</th>
            </tr>
            <?php foreach ($insertados as $r): ?>
            <tr>
                <td style="font-size:.75rem;"><?=h($r['uuid'])?></td>
                <td><?=h($r['nombre_emisor'] ?: '-')?></td>
                <td><?=h($r['rfc_emisor'] ?: '-')?></td>
                <td><?=h($r['fecha_emision'] ?? '-')?></td>
                <td><?=moneda($r['total'])?></td>
                <td><?=h($r['estado'] ?: '-')?></td>
                <td>
                    <?php if ($r['archivo']): ?>
                        <a href="sat_descargar.php?id=<?=(int)$r['id']?>&tipo=xml" class="btn btn-sm btn-info" title="Descargar XML">XML</a>
                    <?php endif; ?>
                    <?php if ($r['archivo_pdf']): ?>
                        <a href="sat_descargar.php?id=<?=(int)$r['id']?>&tipo=pdf" class="btn btn-sm btn-danger" title="Descargar PDF">PDF</a>
                    <?php endif; ?>
                    <?php if (!$r['archivo'] && !$r['archivo_pdf']): ?>
                        <span style="color:#999;">Sin archivos</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($recientes): ?>
<div class="card">
    <div class="card-header">
        <h2>Descargas recientes (<?=count($recientes)?>)</h2>
        <div class="table-actions">
            <button type="button" class="btn btn-success btn-sm" onclick="exportarTablaExcel('#tabla-recientes','facturas_sat')"> Exportar Excel</button>
            <button type="button" class="btn btn-info btn-sm" onclick="imprimirTablaPDF('#tabla-recientes','Descargas recientes SAT')"> Imprimir PDF</button>
            <a href="sat_descargar_zip.php" class="btn btn-warning btn-sm">Descargar recibidas (ZIP)</a>
            <a href="facturas_todo_zip.php" class="btn btn-dark btn-sm">Descargar todas (emitidas + recibidas) ZIP</a>
        </div>
    </div>
    <div class="table-wrapper">
        <table id="tabla-recientes">
            <tr>
                <th>UUID</th>
                <th>Emisor</th>
                <th>RFC</th>
                <th>Fecha</th>
                <th>Total</th>
                <th>Estado</th>
                <th>Vinculo</th>
                <th>Archivos</th>
            </tr>
            <?php foreach ($recientes as $r): ?>
            <tr>
                <td style="font-size:.75rem;"><?=h($r['uuid'])?></td>
                <td><?=h($r['nombre_emisor'] ?: '-')?></td>
                <td><?=h($r['rfc_emisor'] ?: '-')?></td>
                <td><?=h($r['fecha_emision'] ?? '-')?></td>
                <td><?=moneda($r['total'])?></td>
                <td><?=h($r['estado'] ?: '-')?></td>
                <td>
                    <?php if ($r['compra_id']): ?>
                        <a href="compras.php?action=detalle&id=<?=(int)$r['compra_id']?>">Compra <?=h($r['compra_folio'] ?: $r['compra_id'])?></a>
                    <?php else: ?>
                        <span style="color:#999;">Sin vinculo</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['archivo']): ?>
                        <a href="sat_descargar.php?id=<?=(int)$r['id']?>&tipo=xml" class="btn btn-sm btn-info" title="Descargar XML">XML</a>
                    <?php endif; ?>
                    <?php if ($r['archivo_pdf']): ?>
                        <a href="sat_descargar.php?id=<?=(int)$r['id']?>&tipo=pdf" class="btn btn-sm btn-danger" title="Descargar PDF">PDF</a>
                    <?php endif; ?>
                    <?php if (!$r['archivo'] && !$r['archivo_pdf']): ?>
                        <span style="color:#999;">-</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
