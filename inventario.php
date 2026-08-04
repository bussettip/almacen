<?php
$titulo = 'Inventario';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$almacen_id = isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0;
$producto_id = isset($_GET['producto_id']) ? (int)$_GET['producto_id'] : 0;
$search = $_GET['search'] ?? '';
$stock_bajo = isset($_GET['stock_bajo']) ? (int)$_GET['stock_bajo'] : 0;

// Costo total por almacen
$costos = $pdo->query("
    SELECT a.id, a.nombre,
           SUM(i.cantidad * p.precio_compra) as costo_total,
           SUM(i.cantidad) as total_items,
           COUNT(DISTINCT i.producto_id) as total_productos
    FROM inventario i
    JOIN productos p ON p.id = i.producto_id
    JOIN almacenes a ON a.id = i.almacen_id
    WHERE i.cantidad > 0
    GROUP BY a.id, a.nombre
    ORDER BY a.nombre
")->fetchAll();

$gran_total = array_sum(array_column($costos, 'costo_total'));

$sql = "SELECT i.*, p.codigo as prod_codigo, p.nombre as producto, p.stock_minimo, p.stock_maximo, p.precio_compra,
               a.nombre as almacen, u.codigo as ubicacion, um.codigo as umedida
        FROM inventario i
        JOIN productos p ON p.id = i.producto_id
        JOIN almacenes a ON a.id = i.almacen_id
        LEFT JOIN ubicaciones u ON u.id = i.ubicacion_id
        JOIN unidades_medida um ON um.id = p.unidad_medida_id
        WHERE 1=1";
$params = [];

if ($almacen_id) { $sql .= " AND i.almacen_id = ?"; $params[] = $almacen_id; }
if ($producto_id) { $sql .= " AND i.producto_id = ?"; $params[] = $producto_id; }
if ($search) { $sql .= " AND (p.nombre LIKE ? OR p.codigo LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($stock_bajo) { $sql .= " AND i.cantidad <= p.stock_minimo AND p.stock_minimo > 0"; }

$sql .= " ORDER BY a.nombre, p.nombre";
$stmt_inv = $pdo->prepare($sql);
$stmt_inv->execute($params);
$inventario = $stmt_inv->fetchAll();

$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
$productos = $pdo->query("SELECT id, codigo, nombre FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll();

$num_bajo = $pdo->query("SELECT COUNT(*) FROM inventario i JOIN productos p ON p.id=i.producto_id WHERE i.cantidad <= p.stock_minimo AND p.stock_minimo > 0")->fetchColumn();

require 'includes/header.php';
?>

<!-- Resumen de costos por almacen -->
<div class="card">
    <div class="card-header"><h2> Valor del Inventario por Almacen</h2></div>
    <div class="card-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
        <?php foreach ($costos as $c): ?>
        <div class="stat-card">
            <div class="number" style="font-size:1.3rem;"><?=moneda($c['costo_total'])?></div>
            <div class="label"><?=h($c['nombre'])?></div>
            <div style="font-size:.75rem;color:#999;"><?=$c['total_productos']?> prod / <?=$c['total_items']?> pzas</div>
        </div>
        <?php endforeach; ?>
        <div class="stat-card" style="background:#f0f7ff;border-color:#5b9bd5;">
            <div class="number" style="font-size:1.5rem;color:#5b9bd5;"><?=moneda($gran_total)?></div>
            <div class="label" style="font-weight:700;">GRAN TOTAL</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Inventario <?=$stock_bajo?"<span style='color:#ef476f;font-size:.9rem;'>(Stock bajo)</span>":''?></h2>
        <div>
            <a href="asignar.php" class="btn btn-primary">+ Asignar ubicacion</a>
        </div>
    </div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Buscar producto..." value="<?=h($search)?>">
        <select name="almacen_id">
            <option value="">Todos los almacenes</option>
            <?php foreach ($almacenes as $a): ?>
            <option value="<?=$a['id']?>" <?=$almacen_id==$a['id']?'selected':''?>><?=h($a['nombre'])?></option>
            <?php endforeach; ?>
        </select>
        <select name="producto_id">
            <option value="">Todos los productos</option>
            <?php foreach ($productos as $p): ?>
            <option value="<?=$p['id']?>" <?=$producto_id==$p['id']?'selected':''?>><?=h($p['codigo'])?> - <?=h($p['nombre'])?></option>
            <?php endforeach; ?>
        </select>
        <label style="display:flex;align-items:center;gap:4px;font-size:.85rem;white-space:nowrap;">
            <input type="checkbox" name="stock_bajo" value="1" <?=$stock_bajo?'checked':''?> onchange="this.form.submit()">
            Stock bajo <?=$num_bajo>0?"($num_bajo)":''?>
        </label>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
        <a href="inventario.php" class="btn btn-sm btn-secondary">Limpiar</a>
    </form>
    <div class="table-wrapper">
        <table>
            <tr>
                <th>Almacen</th><th>Codigo</th><th>Producto</th><th>Ubicacion</th>
                <th>Lote</th><th>Caducidad</th><th>Cantidad</th><th>UM</th><th>Costo unit.</th><th>Valor total</th><th>Stock min</th>
            </tr>
            <?php foreach ($inventario as $i):
                $bajo = $i['cantidad'] <= $i['stock_minimo'] && $i['stock_minimo'] > 0;
                $valor = $i['cantidad'] * $i['precio_compra'];
            ?>
            <tr style="<?=$bajo?'background:#ffe8ec;':''?>">
                <td><?=h($i['almacen'])?></td>
                <td><?=h($i['prod_codigo'])?></td>
                <td><a href="productos.php?action=edit&id=<?=$i['producto_id']?>"><?=h($i['producto'])?></a></td>
                <td><?=h($i['ubicacion'] ?? '-')?></td>
                <td><?=h($i['lote'] ?? '-')?></td>
                <td><?=h($i['fecha_caducidad'] ?? '-')?></td>
                <td class="<?=$bajo?'fw-bold':''?>" style="<?=$bajo?'color:#ef476f;':''?>"><?=h($i['cantidad'])?></td>
                <td><?=h($i['umedida'])?></td>
                <td><?=moneda($i['precio_compra'])?></td>
                <td><?=moneda($valor)?></td>
                <td><?=$i['stock_minimo'] ?: '-'?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (count($inventario) === 0): ?>
            <tr><td colspan="11" style="text-align:center;padding:40px;color:#999;">No hay registros de inventario</td></tr>
            <?php endif; ?>
        </table>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
