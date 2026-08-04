<?php
$titulo = 'Movimientos';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$search = $_GET['search'] ?? '';
$tipo_id = isset($_GET['tipo_id']) ? (int)$_GET['tipo_id'] : 0;
$almacen_id = isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0;
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$where = "WHERE 1=1";
$params = [];
if ($tipo_id) { $where .= " AND m.tipo_movimiento_id=?"; $params[] = $tipo_id; }
if ($almacen_id) { $where .= " AND (m.almacen_origen_id=? OR m.almacen_destino_id=?)"; $params[] = $almacen_id; $params[] = $almacen_id; }
if ($desde) { $where .= " AND DATE(m.created_at)>=?"; $params[] = $desde; }
if ($hasta) { $where .= " AND DATE(m.created_at)<=?"; $params[] = $hasta; }
if ($search) { $where .= " AND (m.folio LIKE ? OR p.nombre LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$count_sql = "SELECT COUNT(*) FROM movimientos m JOIN productos p ON p.id=m.producto_id $where";
$total = $pdo->prepare($count_sql);
$total->execute($params);
$total_rows = $total->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$sql = "SELECT m.*, tm.nombre as tipo_nombre, tm.codigo as tipo_codigo, tm.signo,
               p.nombre as producto, p.codigo as prod_codigo, um.codigo as umedida,
               a_or.nombre as origen, a_des.nombre as destino, pr.nombre as proveedor
        FROM movimientos m
        JOIN tipos_movimiento tm ON tm.id = m.tipo_movimiento_id
        JOIN productos p ON p.id = m.producto_id
        JOIN unidades_medida um ON um.id = p.unidad_medida_id
        LEFT JOIN almacenes a_or ON a_or.id = m.almacen_origen_id
        LEFT JOIN almacenes a_des ON a_des.id = m.almacen_destino_id
        LEFT JOIN proveedores pr ON pr.id = m.proveedor_id
        $where ORDER BY m.created_at DESC LIMIT $limit OFFSET $offset";

$movimientos = $pdo->prepare($sql);
$movimientos->execute($params);

$tipos = $pdo->query("SELECT * FROM tipos_movimiento ORDER BY nombre")->fetchAll();
$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();

require 'includes/header.php';
?>
<div class="card">
    <div class="card-header"><h2>Movimientos</h2></div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Folio o producto..." value="<?=h($search)?>">
        <select name="tipo_id">
            <option value="">Todos los tipos</option>
            <?php foreach ($tipos as $t): ?>
            <option value="<?=$t['id']?>" <?=$tipo_id==$t['id']?'selected':''?>><?=h($t['nombre'])?></option>
            <?php endforeach; ?>
        </select>
        <select name="almacen_id">
            <option value="">Todos los almacenes</option>
            <?php foreach ($almacenes as $a): ?>
            <option value="<?=$a['id']?>" <?=$almacen_id==$a['id']?'selected':''?>><?=h($a['nombre'])?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="desde" value="<?=$desde?>">
        <input type="date" name="hasta" value="<?=$hasta?>">
        <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
        <a href="movimientos.php" class="btn btn-sm btn-secondary">Limpiar</a>
    </form>
    <div class="table-wrapper">
        <table>
            <tr><th>Folio</th><th>Tipo</th><th>Producto</th><th>Cantidad</th><th>Origen</th><th>Destino</th><th>Proveedor</th><th>Ref.</th><th>Fecha</th></tr>
            <?php foreach ($movimientos as $m): ?>
            <tr>
                <td><?=h($m['folio'])?></td>
                <td><span class="badge badge-info"><?=h($m['tipo_nombre'])?></span></td>
                <td><?=h($m['producto'])?> (<?=h($m['prod_codigo'])?>)</td>
                <td class="<?=$m['signo']<0?'text-danger':'text-success'?> fw-bold"><?=($m['signo']<0?'-':'+').h($m['cantidad'])?> <?=h($m['umedida'])?></td>
                <td><?=h($m['origen'] ?? '-')?></td>
                <td><?=h($m['destino'] ?? '-')?></td>
                <td><?=h($m['proveedor'] ?? '-')?></td>
                <td><?=h($m['referencia'] ?? '-')?></td>
                <td><?=$m['created_at']?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php for ($i=1; $i<=$total_pages; $i++): ?>
        <a href="?page=<?=$i?>&search=<?=urlencode($search)?>&tipo_id=<?=$tipo_id?>&almacen_id=<?=$almacen_id?>&desde=<?=$desde?>&hasta=<?=$hasta?>" class="<?=$i==$page?'active':''?>"><?=$i?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php require 'includes/footer.php'; ?>
