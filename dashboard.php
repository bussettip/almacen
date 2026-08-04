<?php
$titulo = 'Dashboard';
require 'includes/auth.php';

// Estadisticas rapidas
$total_almacenes = $pdo->query("SELECT COUNT(*) FROM almacenes WHERE activo=1")->fetchColumn();
$total_productos = $pdo->query("SELECT COUNT(*) FROM productos WHERE activo=1")->fetchColumn();
$total_proveedores = $pdo->query("SELECT COUNT(*) FROM proveedores WHERE activo=1")->fetchColumn();
$total_movimientos_hoy = $pdo->query("SELECT COUNT(*) FROM movimientos WHERE DATE(created_at)=CURDATE()")->fetchColumn();

// Valor por almacen
$stmt = $pdo->query("
    SELECT a.id, a.nombre, a.codigo, COALESCE(SUM(i.cantidad),0) as total_items,
           COALESCE(SUM(i.cantidad * p.precio_compra),0) as valor_total
    FROM almacenes a
    LEFT JOIN inventario i ON i.almacen_id = a.id
    LEFT JOIN productos p ON p.id = i.producto_id
    WHERE a.activo = 1 GROUP BY a.id ORDER BY a.nombre
");
$almacenes_stats = $stmt->fetchAll();
$gran_total_inventario = array_sum(array_column($almacenes_stats, 'valor_total'));

// Movimientos por tipo (ultimos 30 dias)
$mov_por_tipo = $pdo->query("
    SELECT tm.nombre, COUNT(*) as total, tm.signo
    FROM movimientos m
    JOIN tipos_movimiento tm ON tm.id = m.tipo_movimiento_id
    WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY tm.nombre, tm.signo ORDER BY total DESC
")->fetchAll();

// Movimientos ultimos 7 dias
$mov_7dias = $pdo->query("
    SELECT DATE(created_at) as fecha, COUNT(*) as total
    FROM movimientos
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at) ORDER BY fecha
")->fetchAll();
// Rellenar dias faltantes con 0
$dias = [];
for ($i = 6; $i >= 0; $i--) {
    $f = date('Y-m-d', strtotime("-$i days"));
    $dias[$f] = 0;
}
foreach ($mov_7dias as $m) { $dias[$m['fecha']] = $m['total']; }

// Compras y Ventas mensuales (ultimos 6 meses)
$meses = [];
for ($i = 5; $i >= 0; $i--) {
    $meses[date('Y-m', strtotime("-$i months"))] = ['compras'=>0,'ventas'=>0];
}
$compras_mensual = $pdo->query("SELECT DATE_FORMAT(fecha_compra,'%Y-%m') as mes, SUM(total) as total FROM compras WHERE fecha_compra >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY mes")->fetchAll();
foreach ($compras_mensual as $c) { $meses[$c['mes']]['compras'] = (float)$c['total']; }
$ventas_mensual = $pdo->query("SELECT DATE_FORMAT(fecha_venta,'%Y-%m') as mes, SUM(total) as total FROM ventas WHERE fecha_venta >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY mes")->fetchAll();
foreach ($ventas_mensual as $v) { $meses[$v['mes']]['ventas'] = (float)$v['total']; }

// Importaciones mensuales
$importaciones_mensual = $pdo->query("SELECT DATE_FORMAT(fecha_pedido,'%Y-%m') as mes, SUM(total_mercancia + total_gastos) as total FROM importaciones WHERE fecha_pedido >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY mes")->fetchAll();
$imp_data = [];
foreach ($meses as $m => $v) { $imp_data[$m] = 0; }
foreach ($importaciones_mensual as $im) { $imp_data[$im['mes']] = (float)$im['total']; }

// Stock bajo
$stock_bajo = $pdo->query("
    SELECT p.codigo, p.nombre, i.cantidad, p.stock_minimo, a.nombre as almacen
    FROM inventario i
    JOIN productos p ON p.id = i.producto_id
    JOIN almacenes a ON a.id = i.almacen_id
    WHERE i.cantidad <= p.stock_minimo AND p.stock_minimo > 0
    ORDER BY (i.cantidad / p.stock_minimo) ASC LIMIT 8
")->fetchAll();

// Movimientos recientes
$movimientos_recientes = $pdo->query("
    SELECT m.id, m.folio, m.cantidad, m.created_at, tm.nombre as tipo,
           p.nombre as producto, a_or.nombre as origen, a_des.nombre as destino
    FROM movimientos m
    JOIN tipos_movimiento tm ON tm.id = m.tipo_movimiento_id
    JOIN productos p ON p.id = m.producto_id
    LEFT JOIN almacenes a_or ON a_or.id = m.almacen_origen_id
    LEFT JOIN almacenes a_des ON a_des.id = m.almacen_destino_id
    ORDER BY m.created_at DESC LIMIT 8
")->fetchAll();

function labelColor($i) {
    $colors = ['#5b9bd5','#06d6a0','#ef476f','#ffd166','#118ab2','#8338ec','#fb5607','#457b9d'];
    return $colors[$i % count($colors)];
}

require 'includes/header.php';
?>

<!-- KPIs -->
<div class="card-grid">
    <div class="stat-card"><div class="number"><?=$total_almacenes?></div><div class="label">Almacenes</div></div>
    <div class="stat-card"><div class="number"><?=$total_productos?></div><div class="label">Productos</div></div>
    <div class="stat-card"><div class="number"><?=$total_proveedores?></div><div class="label">Proveedores</div></div>
    <div class="stat-card"><div class="number"><?=$total_movimientos_hoy?></div><div class="label">Movimientos hoy</div></div>
</div>

<!-- Valor inventario -->
<div class="card">
    <div class="card-header"><h2> Valor de Inventario</h2></div>
    <div class="card-grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
        <?php foreach ($almacenes_stats as $a): ?>
        <div class="stat-card">
            <div class="number" style="font-size:1.2rem;"><?=moneda($a['valor_total'])?></div>
            <div class="label"><?=h($a['nombre'])?></div>
            <div style="font-size:.75rem;color:#999;"><?=$a['total_items']?> pzas</div>
        </div>
        <?php endforeach; ?>
        <div class="stat-card" style="background:#f0f7ff;border-color:#5b9bd5;">
            <div class="number" style="font-size:1.4rem;color:#5b9bd5;"><?=moneda($gran_total_inventario)?></div>
            <div class="label" style="font-weight:700;">TOTAL INVENTARIO</div>
        </div>
    </div>
</div>

<!-- Graficas -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
    <div class="card" style="padding:12px;">
        <div style="font-size:.85rem;font-weight:600;color:#5b9bd5;margin-bottom:6px;">📊 Valor por Almacen</div>
        <canvas id="chartValor" height="120"></canvas>
    </div>
    <div class="card" style="padding:12px;">
        <div style="font-size:.85rem;font-weight:600;color:#5b9bd5;margin-bottom:6px;">🍩 Movimientos (30 dias)</div>
        <canvas id="chartMovTipo" height="120"></canvas>
    </div>
    <div class="card" style="padding:12px;">
        <div style="font-size:.85rem;font-weight:600;color:#5b9bd5;margin-bottom:6px;">📈 Movimientos ultimos 7 dias</div>
        <canvas id="chartMov7d" height="120"></canvas>
    </div>
    <div class="card" style="padding:12px;">
        <div style="font-size:.85rem;font-weight:600;color:#5b9bd5;margin-bottom:6px;">[!] Stock bajo</div>
        <canvas id="chartStockBajo" height="120"></canvas>
    </div>
</div>

<!-- Graficas financieras -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;margin-bottom:20px;">
    <div class="card" style="padding:12px;">
        <div style="font-size:.85rem;font-weight:600;color:#ef476f;margin-bottom:6px;"> Compras (6 meses)</div>
        <canvas id="chartCompras" height="130"></canvas>
    </div>
    <div class="card" style="padding:12px;">
        <div style="font-size:.85rem;font-weight:600;color:#06d6a0;margin-bottom:6px;"> Ventas (6 meses)</div>
        <canvas id="chartVentas" height="130"></canvas>
    </div>
    <div class="card" style="padding:12px;">
        <div style="font-size:.85rem;font-weight:600;color:#5b9bd5;margin-bottom:6px;"> Flujo de Caja</div>
        <canvas id="chartFlujo" height="130"></canvas>
    </div>
    <div class="card" style="padding:12px;">
        <div style="font-size:.85rem;font-weight:600;color:#118ab2;margin-bottom:6px;"> Importaciones (6 meses)</div>
        <canvas id="chartImportaciones" height="130"></canvas>
    </div>
</div>

<!-- Almacenes -->
<div class="card">
    <div class="card-header"><h2>Almacenes</h2></div>
    <div class="table-wrapper">
        <table>
            <tr><th>Codigo</th><th>Nombre</th><th>Pzas</th><th>Valor inventario</th><th></th></tr>
            <?php foreach ($almacenes_stats as $a): ?>
            <tr>
                <td><?=h($a['codigo'])?></td>
                <td><a href="inventario.php?almacen_id=<?=$a['id']?>"><?=h($a['nombre'])?></a></td>
                <td><?=$a['total_items']?></td>
                <td><?=moneda($a['valor_total'])?></td>
                <td><a href="entrada.php?almacen_id=<?=$a['id']?>" class="btn btn-sm btn-success">Entrada</a>
                    <a href="salida.php?almacen_id=<?=$a['id']?>" class="btn btn-sm btn-danger">Salida</a>
                    <a href="asignar.php?almacen_id=<?=$a['id']?>" class="btn btn-sm btn-info">Ubicacion</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<!-- Movimientos recientes -->
<div class="card">
    <div class="card-header"><h2>Movimientos Recientes</h2></div>
    <div class="table-wrapper">
        <table>
            <tr><th>Folio</th><th>Tipo</th><th>Producto</th><th>Cantidad</th><th>Origen</th><th>Destino</th><th>Fecha</th></tr>
            <?php foreach ($movimientos_recientes as $m): ?>
            <tr>
                <td><?=h($m['folio'])?></td>
                <td><span class="badge badge-info"><?=h($m['tipo'])?></span></td>
                <td><?=h($m['producto'])?></td>
                <td><?=h($m['cantidad'])?></td>
                <td><?=h($m['origen'] ?? '-')?></td>
                <td><?=h($m['destino'] ?? '-')?></td>
                <td><?=$m['created_at']?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<?php if ($stock_bajo): ?>
<div class="card">
    <div class="card-header"><h2>[!] Stock Bajo</h2></div>
    <div class="table-wrapper">
        <table>
            <tr><th>Codigo</th><th>Producto</th><th>Stock</th><th>Minimo</th><th>Almacen</th></tr>
            <?php foreach ($stock_bajo as $s): ?>
            <tr>
                <td><?=h($s['codigo'])?></td>
                <td><?=h($s['nombre'])?></td>
                <td style="color:#ef476f;font-weight:700;"><?=h($s['cantidad'])?></td>
                <td><?=h($s['stock_minimo'])?></td>
                <td><?=h($s['almacen'])?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>

<script src="js/chart.umd.min.js"></script>
<script>
Chart.defaults.color = '#555';
Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";

// 1. Valor por almacen (barras)
new Chart(document.getElementById('chartValor'), {
    type: 'bar',
    data: {
        labels: [<?php foreach ($almacenes_stats as $a) { echo "'" . h($a['nombre']) . "',"; } ?>],
        datasets: [{
            label: 'Valor total',
            data: [<?php foreach ($almacenes_stats as $a) { echo $a['valor_total'] . ","; } ?>],
            backgroundColor: [<?php foreach ($almacenes_stats as $i => $a) { echo "'" . labelColor($i) . "',"; } ?>],
            borderRadius: 4, maxBarThickness: 40
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: true, aspectRatio: 2,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => '$' + ctx.parsed.y.toLocaleString() } } },
        scales: {
            y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.05)' }, ticks: { callback: v => '$' + (v/1000).toFixed(0) + 'k', font: { size: 9 } } },
            x: { grid: { display: false }, ticks: { font: { size: 8 } } }
        }
    }
});

// 2. Movimientos por tipo (dona)
new Chart(document.getElementById('chartMovTipo'), {
    type: 'doughnut',
    data: {
        labels: [<?php foreach ($mov_por_tipo as $m) { echo "'" . h($m['nombre']) . "',"; } ?>],
        datasets: [{
            data: [<?php foreach ($mov_por_tipo as $m) { echo $m['total'] . ","; } ?>],
            backgroundColor: [<?php foreach ($mov_por_tipo as $i => $m) { echo "'" . labelColor($i) . "',"; } ?>],
            borderWidth: 1
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: true, aspectRatio: 2,
        cutout: '60%',
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 6, font: { size: 8 } } } }
    }
});

// 3. Movimientos ultimos 7 dias (linea)
new Chart(document.getElementById('chartMov7d'), {
    type: 'line',
    data: {
        labels: [<?php foreach ($dias as $f => $c) { echo "'" . date('d/m', strtotime($f)) . "',"; } ?>],
        datasets: [{
            label: 'Movimientos',
            data: [<?php foreach ($dias as $c) { echo $c . ","; } ?>],
            borderColor: '#5b9bd5', backgroundColor: 'rgba(91,155,213,.1)',
            fill: true, tension: .3, pointRadius: 3, pointBackgroundColor: '#5b9bd5', borderWidth: 2
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: true, aspectRatio: 2,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 9 } }, grid: { color: 'rgba(0,0,0,.05)' } },
            x: { grid: { display: false }, ticks: { font: { size: 8 } } }
        }
    }
});

// 4. Stock bajo (barras horizontales)
const stockLabels = [<?php foreach ($stock_bajo as $s) { echo "'" . h($s['nombre']) . " (" . h($s['codigo']) . ")',"; } ?>];
const stockActual = [<?php foreach ($stock_bajo as $s) { echo $s['cantidad'] . ","; } ?>];
const stockMin = [<?php foreach ($stock_bajo as $s) { echo $s['stock_minimo'] . ","; } ?>];
if (stockLabels.length) {
    new Chart(document.getElementById('chartStockBajo'), {
        type: 'bar',
        data: {
            labels: stockLabels,
            datasets: [
                { label: 'Actual', data: stockActual, backgroundColor: '#ef476f', borderRadius: 3, maxBarThickness: 20 },
                { label: 'Minimo', data: stockMin, backgroundColor: '#ffd166', borderRadius: 3, maxBarThickness: 20 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: true, aspectRatio: 2,
            indexAxis: 'y',
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } } },
            scales: {
                x: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 9 } }, grid: { color: 'rgba(0,0,0,.05)' } },
                y: { grid: { display: false }, ticks: { font: { size: 7 } } }
            }
        }
    });
} else {
    document.getElementById('chartStockBajo').parentElement.innerHTML += '<p style="text-align:center;color:#999;padding:20px;">No hay productos con stock bajo.</p>';
}

// 5. Compras (6 meses)
const mesesLabels = [<?php foreach ($meses as $m => $v) { echo "'" . date('M', strtotime($m . '-01')) . "',"; } ?>];
const comprasData = [<?php foreach ($meses as $v) { echo $v['compras'] . ","; } ?>];
const ventasData = [<?php foreach ($meses as $v) { echo $v['ventas'] . ","; } ?>];
new Chart(document.getElementById('chartCompras'), {
    type: 'bar', data: { labels: mesesLabels, datasets: [{ label: 'Compras', data: comprasData, backgroundColor: '#ef476f', borderRadius: 4, maxBarThickness: 30 }] },
    options: { responsive: true, maintainAspectRatio: true, aspectRatio: 2, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => '$' + ctx.parsed.y.toLocaleString() } } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.05)' }, ticks: { callback: v => '$' + (v/1000).toFixed(0) + 'k', font: { size: 9 } } }, x: { grid: { display: false }, ticks: { font: { size: 8 } } } } }
});
new Chart(document.getElementById('chartVentas'), {
    type: 'bar', data: { labels: mesesLabels, datasets: [{ label: 'Ventas', data: ventasData, backgroundColor: '#06d6a0', borderRadius: 4, maxBarThickness: 30 }] },
    options: { responsive: true, maintainAspectRatio: true, aspectRatio: 2, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => '$' + ctx.parsed.y.toLocaleString() } } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.05)' }, ticks: { callback: v => '$' + (v/1000).toFixed(0) + 'k', font: { size: 9 } } }, x: { grid: { display: false }, ticks: { font: { size: 8 } } } } }
});

// 6. Flujo de caja
const flujoData = ventasData.map((v, i) => v - comprasData[i]);
new Chart(document.getElementById('chartFlujo'), {
    type: 'line',
    data: {
        labels: mesesLabels,
        datasets: [{
            label: 'Flujo neto',
            data: flujoData,
            borderColor: '#5b9bd5', backgroundColor: function(ctx) {
                const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 200);
                g.addColorStop(0, 'rgba(91,155,213,.25)');
                g.addColorStop(1, 'rgba(91,155,213,.02)');
                return g;
            },
            fill: true, tension: .3, pointRadius: 4, pointBackgroundColor: function(ctx) {
                return ctx.parsed.y >= 0 ? '#06d6a0' : '#ef476f';
            }, borderWidth: 2
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: true, aspectRatio: 2,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => (ctx.parsed.y >= 0 ? '+' : '') + '$' + ctx.parsed.y.toLocaleString()
                }
            }
        },
        scales: {
            y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.05)' }, ticks: { callback: v => '$' + (v/1000).toFixed(0) + 'k', font: { size: 9 } } },
            x: { grid: { display: false }, ticks: { font: { size: 9 } } }
        }
    }
});

// 7. Importaciones (6 meses)
const impLabels = [<?php foreach ($imp_data as $m => $v) { echo "'" . date('M', strtotime($m . '-01')) . "',"; } ?>];
const impData = [<?php foreach ($imp_data as $v) { echo $v . ","; } ?>];
new Chart(document.getElementById('chartImportaciones'), {
    type: 'bar',
    data: {
        labels: impLabels,
        datasets: [{
            label: 'Importaciones',
            data: impData,
            backgroundColor: '#118ab2',
            borderRadius: 4, maxBarThickness: 35
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: true, aspectRatio: 2,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => '$' + ctx.parsed.y.toLocaleString() } }
        },
        scales: {
            y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.05)' }, ticks: { callback: v => '$' + (v/1000).toFixed(0) + 'k', font: { size: 9 } } },
            x: { grid: { display: false }, ticks: { font: { size: 8 } } }
        }
    }
});
</script>

<?php require 'includes/footer.php'; ?>

