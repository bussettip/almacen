<?php
$titulo = 'Factura';
require 'includes/auth.php';
require_once 'includes/cfdi_helper.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { alert('danger', 'Factura no especificada'); redirect('ventas.php'); }

$factura = $pdo->prepare("SELECT * FROM facturas_emitidas WHERE id=?");
$factura->execute([$id]); $factura = $factura->fetch();
if (!$factura) { alert('danger', 'Factura no encontrada'); redirect('ventas.php'); }
if ($factura['estatus'] !== 'timbrado') { alert('warning', 'Esta factura aun no esta timbrada'); redirect('ventas.php'); }

$venta = $pdo->prepare("SELECT v.*, c.nombre as cliente, c.rfc, c.direccion, c.ciudad, c.estado, c.pais, c.email, a.nombre as almacen, u.nombre as usuario FROM ventas v JOIN clientes c ON c.id=v.cliente_id JOIN almacenes a ON a.id=v.almacen_id JOIN usuarios u ON u.id=v.usuario_id WHERE v.id=?");
$venta->execute([$factura['venta_id']]); $venta = $venta->fetch();
if (!$venta) { alert('danger', 'Venta no encontrada'); redirect('ventas.php'); }

$detalles = $pdo->prepare("SELECT d.*, p.codigo, p.nombre as producto, um.codigo as umedida FROM venta_detalle d JOIN productos p ON p.id=d.producto_id JOIN unidades_medida um ON um.id=p.unidad_medida_id WHERE d.venta_id=?");
$detalles->execute([$factura['venta_id']]);

$empresa = $pdo->query("SELECT * FROM config_empresa WHERE id=1")->fetch();
$empresa_nombre = $empresa['razon_social'] ?: ($empresa['nombre'] ?? 'Control de Almacenes');
$empresa_logo = $empresa && $empresa['logo'] && file_exists(__DIR__.'/uploads/empresa/'.$empresa['logo']) ? 'uploads/empresa/'.$empresa['logo'] : '';
$empresa_datos = array_filter([$empresa['rfc'] ?? null, $empresa['direccion'] ?? null, $empresa['telefono'] ?? null, $empresa['email'] ?? null]);

$xml_path = __DIR__.'/uploads/cfdi/'.$factura['xml_path'];
$fecha_timbrado = null;
if (file_exists($xml_path)) {
    $xmlDoc = @simplexml_load_file($xml_path);
    if ($xmlDoc) {
        $xmlDoc->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');
        $tfd = $xmlDoc->xpath('//tfd:TimbreFiscalDigital');
        if ($tfd) { $fecha_timbrado = (string)$tfd[0]['FechaTimbrado']; }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura - <?=h($factura['serie'] ?? '')?><?=h($factura['folio'] ?? '')?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #eef2f7; padding: 40px; }
        .invoice { max-width: 800px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,.08); overflow: hidden; }
        .header { background: #5b9bd5; color: #fff; padding: 30px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.6rem; }
        .header .folio { font-size: 1rem; opacity: .8; }
        .info { padding: 30px; display: flex; justify-content: space-between; border-bottom: 2px solid #eef2f7; }
        .info h3 { font-size: .8rem; color: #888; text-transform: uppercase; margin-bottom: 6px; }
        .info p { font-size: .95rem; margin-bottom: 4px; }
        .info .cliente { max-width: 50%; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f4f7fb; text-align: left; padding: 12px 16px; font-size: .75rem; text-transform: uppercase; color: #5b9bd5; border-bottom: 2px solid #dce1e8; }
        td { padding: 10px 16px; border-bottom: 1px solid #eef2f7; font-size: .9rem; }
        .total-wrap { padding: 20px 30px; margin-left: auto; width: 300px; }
        .total-wrap tr td { border: none; padding: 6px 0; }
        .total-wrap .grand td { font-size: 1.1rem; font-weight: 700; color: #5b9bd5; border-top: 2px solid #5b9bd5; padding-top: 8px; }
        .cfdi-data { background: #f8fafc; padding: 16px 30px; border-top: 2px solid #eef2f7; font-size: .8rem; color: #444; }
        .cfdi-data div { margin-bottom: 2px; }
        .footer { text-align: center; padding: 20px; color: #a0aab8; font-size: .8rem; border-top: 1px solid #e8ecf1; }
        .actions { text-align: center; padding: 20px; }
        .btn { display: inline-block; padding: 10px 24px; background: #5b9bd5; color: #fff; border: none; border-radius: 6px; font-size: .9rem; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #4a8ac7; }
        @media print {
            body { background: #fff; padding: 0; }
            .invoice { box-shadow: none; border-radius: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="invoice">
        <div class="header">
            <div>
                <h1>Factura</h1>
                <div class="folio">Folio: <?=h($factura['serie'] ?? '')?><?=h($factura['folio'] ?? '')?></div>
            </div>
            <div style="text-align:right;">
                <div style="display:flex;align-items:center;justify-content:flex-end;gap:14px;">
                    <?php if ($empresa_logo): ?>
                        <img src="<?=$empresa_logo?>" alt="Logo" style="max-height:60px;max-width:160px;height:auto;border-radius:6px;">
                    <?php endif; ?>
                    <div style="text-align:right;">
                        <div style="font-size:1.1rem;font-weight:700;"><?=h($empresa_nombre)?></div>
                        <div style="font-size:.85rem;opacity:.8;"><?=h($factura['created_at'])?></div>
                    </div>
                </div>
                <?php foreach ($empresa_datos as $d): ?>
                <div style="font-size:.8rem;opacity:.8;margin-top:2px;"><?=h($d)?></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="info">
            <div class="cliente">
                <h3>Cliente</h3>
                <p><strong><?=h($venta['cliente'])?></strong></p>
                <?php if ($venta['rfc']): ?><p>RFC: <?=h($venta['rfc'])?></p><?php endif; ?>
                <?php if ($venta['direccion']): ?><p><?=h($venta['direccion'])?></p><?php endif; ?>
                <?php if ($venta['ciudad']): ?><p><?=h($venta['ciudad'])?><?=$venta['estado']?', '.h($venta['estado']):''?></p><?php endif; ?>
                <?php if ($venta['email']): ?><p>Email: <?=h($venta['email'])?></p><?php endif; ?>
            </div>
            <div>
                <h3>Venta</h3>
                <p>Fecha: <?=$venta['fecha_venta']?></p>
                <?php if ($venta['fecha_entrega']): ?><p>Entrega: <?=$venta['fecha_entrega']?></p><?php endif; ?>
                <p>Forma pago: <?=h(ucfirst($venta['forma_pago'] ?? '-'))?></p>
                <p>Almacen: <?=h($venta['almacen'])?></p>
            </div>
        </div>

        <table>
            <tr><th>Codigo</th><th>Producto</th><th style="text-align:center;">Cant.</th><th style="text-align:right;">Precio</th><th style="text-align:right;">Descto.</th><th style="text-align:right;">Subtotal</th></tr>
            <?php foreach ($detalles as $d): ?>
            <tr>
                <td><?=h($d['codigo'])?></td>
                <td><?=h($d['producto'])?></td>
                <td style="text-align:center;"><?=h($d['cantidad'])?> <?=h($d['umedida'])?></td>
                <td style="text-align:right;"><?=moneda($d['precio_unitario'])?></td>
                <td style="text-align:right;"><?=moneda($d['descuento'])?></td>
                <td style="text-align:right;"><?=moneda($d['subtotal'])?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <div style="display:flex;justify-content:flex-end;padding:10px 30px 30px;">
            <table class="total-wrap">
                <tr><td>Subtotal</td><td style="text-align:right;"><?=moneda($venta['subtotal'])?></td></tr>
                <tr><td>Descuento</td><td style="text-align:right;"><?=moneda($venta['descuento'])?></td></tr>
                <tr><td>IVA (16%)</td><td style="text-align:right;"><?=moneda($venta['impuesto'])?></td></tr>
                <tr class="grand"><td>Total</td><td style="text-align:right;"><?=moneda($factura['total'] ?? $venta['total'])?></td></tr>
            </table>
        </div>

        <div class="cfdi-data">
            <div><strong>UUID:</strong> <?=h($factura['uuid'])?></div>
            <div><strong>Rfc receptor:</strong> <?=h($factura['rfc_receptor'])?></div>
            <?php if ($fecha_timbrado): ?><div><strong>Timbrado:</strong> <?=h($fecha_timbrado)?></div><?php endif; ?>
            <div><strong>Serie-Folio:</strong> <?=h($factura['serie'] ?? '')?>-<?=h($factura['folio'] ?? '')?></div>
        </div>

        <div class="footer">
            <p>Generado por: <?=h($venta['usuario'])?> | <?=date('d/m/Y H:i')?></p>
        </div>
    </div>

    <div class="actions">
        <button class="btn" onclick="window.print()">Imprimir / Guardar PDF</button>
        <a href="ventas.php?action=detalle&id=<?=$factura['venta_id']?>" class="btn" style="background:#a0aab8;"><- Volver</a>
    </div>
</body>
</html>
