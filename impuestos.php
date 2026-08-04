<?php
$titulo = 'Impuestos a Declarar';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$pdo->exec("CREATE TABLE IF NOT EXISTS config_impuestos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    periodo_anio INT NOT NULL,
    periodo_mes INT NOT NULL,
    iva_tasa DECIMAL(5,3) DEFAULT 0.160,
    iva_trasladado DECIMAL(12,2) DEFAULT 0,
    iva_acreditable DECIMAL(12,2) DEFAULT 0,
    isr_tasa DECIMAL(5,3) DEFAULT 30.000,
    isr_estimado DECIMAL(12,2) DEFAULT 0,
    isr_retenido DECIMAL(12,2) DEFAULT 0,
    iva_retenido DECIMAL(12,2) DEFAULT 0,
    notas TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_periodo (periodo_anio,periodo_mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

try { $pdo->exec("ALTER TABLE config_impuestos ADD COLUMN isr_tasa DECIMAL(5,3) DEFAULT 30.000"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE config_impuestos ADD COLUMN isr_estimado DECIMAL(12,2) DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE config_impuestos ADD COLUMN isr_retenido DECIMAL(12,2) DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE config_impuestos ADD COLUMN iva_retenido DECIMAL(12,2) DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE config_impuestos ADD COLUMN notas TEXT"); } catch (Exception $e) {}

$isr_tasa_default = 30;
$isr_tasa = isset($_GET['isr_tasa']) ? (float)$_GET['isr_tasa'] : $isr_tasa_default;

$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');
$fecha_inicio = "$anio-$mes-01";
$fecha_fin = date('Y-m-t', strtotime($fecha_inicio));

$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$anios = range(date('Y'), date('Y') - 5, -1);

// --- Ventas del periodo (estatus entregado o surtido) ---
$ventas = $pdo->prepare("SELECT COUNT(*) as num, COALESCE(SUM(total),0) as total_ventas, COALESCE(SUM(subtotal - descuento),0) as base, COALESCE(SUM(impuesto),0) as iva FROM ventas WHERE estatus NOT IN ('cancelado') AND fecha_venta BETWEEN ? AND ?");
$ventas->execute([$fecha_inicio, $fecha_fin]); $v = $ventas->fetch();

// --- Compras del periodo ---
$compras = $pdo->prepare("SELECT COUNT(*) as num, COALESCE(SUM(total),0) as total FROM compras WHERE estatus NOT IN ('cancelada') AND fecha_compra BETWEEN ? AND ?");
$compras->execute([$fecha_inicio, $fecha_fin]); $c = $compras->fetch();
$iva_compras_estimado = $c['total'] / 1.16 * 0.16;

// --- Importaciones del periodo (fecha_pedido o fecha_llegada) ---
$importaciones = $pdo->prepare("SELECT COUNT(*) as num, COALESCE(SUM(total_mercancia + total_gastos),0) as total FROM importaciones WHERE estatus NOT IN ('cancelado') AND fecha_pedido BETWEEN ? AND ?");
$importaciones->execute([$fecha_inicio, $fecha_fin]); $im = $importaciones->fetch();
$iva_import_estimado = $im['total'] / 1.16 * 0.16;

// --- Exportaciones del periodo (tasa 0%) ---
$exportaciones = $pdo->prepare("SELECT COUNT(*) as num, COALESCE(SUM(total_mercancia + total_gastos),0) as total FROM exportaciones WHERE estatus NOT IN ('cancelado') AND fecha_pedido BETWEEN ? AND ?");
$exportaciones->execute([$fecha_inicio, $fecha_fin]); $ex = $exportaciones->fetch();

$iva_trasladado = $v['iva'];
$iva_acreditable = $iva_compras_estimado + $iva_import_estimado;
$iva_neto = $iva_trasladado - $iva_acreditable;
$resultado_iva = $iva_neto >= 0 ? 'A pagar' : 'A favor';
$iva_neto_abs = abs($iva_neto);

// --- ISR ---
$ingresos = $v['total_ventas'] + $ex['total'];
$deducciones = $c['total'] + $im['total'];
$utilidad_fiscal = $ingresos - $deducciones;
$isr_estimado = $utilidad_fiscal > 0 ? $utilidad_fiscal * $isr_tasa / 100 : 0;

// Guardar snapshot en config_impuestos
$pdo->prepare("INSERT INTO config_impuestos (periodo_anio,periodo_mes,iva_tasa,iva_trasladado,iva_acreditable,isr_tasa,isr_estimado) VALUES (?,?,0.160,?,?,?,?) ON DUPLICATE KEY UPDATE iva_trasladado=VALUES(iva_trasladado), iva_acreditable=VALUES(iva_acreditable), isr_tasa=VALUES(isr_tasa), isr_estimado=VALUES(isr_estimado)")
    ->execute([$anio, $mes, $iva_trasladado, $iva_acreditable, $isr_tasa, $isr_estimado]);

require 'includes/header.php';
?>
<div class="card" style="max-width:950px">
    <div class="card-header"><h2>Impuestos a Declarar</h2></div>

    <form class="search-bar" method="get">
        <select name="mes">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?=$m?>" <?=$m===$mes?'selected':''?>><?=$meses[$m]?></option>
            <?php endfor; ?>
        </select>
        <select name="anio">
            <?php foreach ($anios as $a): ?>
            <option value="<?=$a?>" <?=$a===$anio?'selected':''?>><?=$a?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Consultar</button>
    </form>

    <h3 style="margin:16px 0 8px;"><?=$meses[$mes]?> <?=$anio?></h3>

    <div class="form-row" style="gap:12px;flex-wrap:wrap;">
        <div class="card" style="flex:1;min-width:160px;padding:16px;text-align:center;background:#e3f2fd;">
            <div style="font-size:0.8rem;color:#666;">Ventas</div>
            <div style="font-size:1.3rem;font-weight:700;"><?=$v['num']?></div>
            <div style="font-size:0.9rem;"><?=moneda($v['total_ventas'])?></div>
        </div>
        <div class="card" style="flex:1;min-width:160px;padding:16px;text-align:center;background:#fff3cd;">
            <div style="font-size:0.8rem;color:#666;">Compras</div>
            <div style="font-size:1.3rem;font-weight:700;"><?=$c['num']?></div>
            <div style="font-size:0.9rem;"><?=moneda($c['total'])?></div>
        </div>
        <div class="card" style="flex:1;min-width:160px;padding:16px;text-align:center;background:#f3e5f5;">
            <div style="font-size:0.8rem;color:#666;">Importaciones</div>
            <div style="font-size:1.3rem;font-weight:700;"><?=$im['num']?></div>
            <div style="font-size:0.9rem;"><?=moneda($im['total'])?></div>
        </div>
        <div class="card" style="flex:1;min-width:160px;padding:16px;text-align:center;background:#e8f5e9;">
            <div style="font-size:0.8rem;color:#666;">Exportaciones (0%)</div>
            <div style="font-size:1.3rem;font-weight:700;"><?=$ex['num']?></div>
            <div style="font-size:0.9rem;"><?=moneda($ex['total'])?></div>
        </div>
    </div>

    <h3 style="margin:16px 0 8px;">IVA</h3>
    <table>
        <tr><td><strong>IVA Trasladado (Ventas)</strong></td><td style="text-align:right"><?=moneda($iva_trasladado)?></td><td style="color:#666;font-size:0.8rem;">16% de $<?=number_format($v['base'],2)?></td></tr>
        <tr><td><strong>IVA Acreditable Compras</strong></td><td style="text-align:right"><?=moneda($iva_compras_estimado)?></td><td style="color:#666;font-size:0.8rem;">Estimado 16% de $<?=number_format($c['total'],2)?></td></tr>
        <tr><td><strong>IVA Acreditable Importaciones</strong></td><td style="text-align:right"><?=moneda($iva_import_estimado)?></td><td style="color:#666;font-size:0.8rem;">Estimado 16% de $<?=number_format($im['total'],2)?></td></tr>
        <tr style="border-top:2px solid #333;font-weight:700;">
            <td>IVA Neto (<?=$resultado_iva?>)</td>
            <td style="text-align:right;color:<?=$iva_neto>=0?'#c62828':'#2e7d32'?>">
                <?=moneda($iva_neto_abs)?>
            </td>
            <td style="color:#666;font-size:0.8rem;"><?=$iva_neto>=0?'Impuesto a pagar':'Saldo a favor'?></td>
        </tr>
    </table>

    <h3 style="margin:16px 0 8px;">ISR</h3>
    <form method="get" style="display:inline">
        <input type="hidden" name="mes" value="<?=$mes?>">
        <input type="hidden" name="anio" value="<?=$anio?>">
        <table>
            <tr><td><strong>Ingresos (Ventas + Exportaciones)</strong></td><td style="text-align:right"><?=moneda($ingresos)?></td><td style="color:#666;font-size:0.8rem;">Ventas: <?=moneda($v['total_ventas'])?> + Export: <?=moneda($ex['total'])?></td></tr>
            <tr><td><strong>Deducciones (Compras + Importaciones)</strong></td><td style="text-align:right"><?=moneda($deducciones)?></td><td style="color:#666;font-size:0.8rem;">Compras: <?=moneda($c['total'])?> + Import: <?=moneda($im['total'])?></td></tr>
            <tr><td><strong>Utilidad fiscal</strong></td><td style="text-align:right"><?=moneda($utilidad_fiscal)?></td><td></td></tr>
            <tr><td><strong>Tasa ISR</strong></td>
                <td style="text-align:right">
                    <input type="number" step="0.1" min="0" max="100" name="isr_tasa" value="<?=$isr_tasa?>" style="width:80px;text-align:right" onchange="this.form.submit()"> %
                </td>
                <td style="color:#666;font-size:0.8rem;"></td>
            </tr>
            <tr style="border-top:2px solid #333;font-weight:700;">
                <td>ISR Estimado</td>
                <td style="text-align:right;color:<?=$isr_estimado>0?'#c62828':'#2e7d32'?>"><?=moneda($isr_estimado)?></td>
                <td style="color:#666;font-size:0.8rem;"><?=$utilidad_fiscal>0?'A pagar':'Sin utilidad'?></td>
            </tr>
        </table>
    </form>

    <h3 style="margin:16px 0 8px;">Ventas del periodo</h3>
    <div class="table-wrapper">
        <table>
            <tr><th>Folio</th><th>Cliente</th><th>Subtotal</th><th>Descuento</th><th>IVA</th><th>Total</th><th>Fecha</th></tr>
            <?php $rows = $pdo->prepare("SELECT v.*, c.nombre as cliente FROM ventas v JOIN clientes c ON c.id=v.cliente_id WHERE v.estatus NOT IN ('cancelado') AND v.fecha_venta BETWEEN ? AND ? ORDER BY v.fecha_venta");
            $rows->execute([$fecha_inicio, $fecha_fin]);
            foreach ($rows as $r): ?>
            <tr>
                <td><a href="ventas.php?action=detalle&id=<?=$r['id']?>"><?=h($r['folio'])?></a></td>
                <td><?=h($r['cliente'])?></td>
                <td style="text-align:right"><?=moneda($r['subtotal'])?></td>
                <td style="text-align:right"><?=moneda($r['descuento'])?></td>
                <td style="text-align:right"><?=moneda($r['impuesto'])?></td>
                <td style="text-align:right"><?=moneda($r['total'])?></td>
                <td><?=$r['fecha_venta']?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <h3 style="margin:16px 0 8px;">Compras del periodo</h3>
    <div class="table-wrapper">
        <table>
            <tr><th>Folio</th><th>Proveedor</th><th>Total</th><th>IVA estimado</th><th>Fecha</th></tr>
            <?php $rows = $pdo->prepare("SELECT c.*, p.nombre as proveedor FROM compras c JOIN proveedores p ON p.id=c.proveedor_id WHERE c.estatus NOT IN ('cancelada') AND c.fecha_compra BETWEEN ? AND ? ORDER BY c.fecha_compra");
            $rows->execute([$fecha_inicio, $fecha_fin]);
            foreach ($rows as $r): $iva_est = $r['total'] / 1.16 * 0.16; ?>
            <tr>
                <td><a href="compras.php?action=detalle&id=<?=$r['id']?>"><?=h($r['folio'])?></a></td>
                <td><?=h($r['proveedor'])?></td>
                <td style="text-align:right"><?=moneda($r['total'])?></td>
                <td style="text-align:right"><?=moneda($iva_est)?></td>
                <td><?=$r['fecha_compra']?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="form-actions" style="margin-top:16px;">
        <a href="javascript:window.print()" class="btn btn-primary"> Imprimir</a>
        <a href="dashboard.php" class="btn btn-secondary"><- Volver</a>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
