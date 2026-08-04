<?php
$titulo = 'Asignar Ubicacion';
require 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $almacen_id = (int)$_POST['almacen_id'];
        $producto_id = (int)$_POST['producto_id'];
        $ubicacion_id = !empty($_POST['ubicacion_id']) ? (int)$_POST['ubicacion_id'] : null;
        $lote = trim($_POST['lote'] ?? '');
        $fecha_caducidad = !empty($_POST['fecha_caducidad']) ? $_POST['fecha_caducidad'] : null;
        $cantidad = str_replace(',','', $_POST['cantidad'] ?? 0);

        // Verificar si ya existe la relacion
        $existe = $pdo->prepare("SELECT id, cantidad FROM inventario WHERE almacen_id=? AND producto_id=? AND ubicacion_id<=>? AND lote<=>?");
        $existe->execute([$almacen_id, $producto_id, $ubicacion_id, $lote ?: null]);
        $row = $existe->fetch();

        if ($row) {
            $pdo->prepare("UPDATE inventario SET cantidad = ?, fecha_caducidad = COALESCE(?, fecha_caducidad) WHERE id = ?")
                ->execute([$cantidad, $fecha_caducidad, $row['id']]);
            alert('success', 'Cantidad actualizada en la ubicacion existente');
        } else {
            $pdo->prepare("INSERT INTO inventario (almacen_id, ubicacion_id, producto_id, lote, fecha_caducidad, cantidad) VALUES (?,?,?,?,?,?)")
                ->execute([$almacen_id, $ubicacion_id, $producto_id, $lote ?: null, $fecha_caducidad, $cantidad]);
            alert('success', 'Producto asignado a la ubicacion correctamente');
        }
        redirect('inventario.php?almacen_id=' . $almacen_id);

    } catch (PDOException $e) {
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

$action = $_GET['action'] ?? 'assign';
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Si viene de editar inventario, cargar datos
$edit_row = null;
if ($action === 'edit' && $edit_id) {
    $stmt = $pdo->prepare("SELECT i.*, p.codigo as prod_codigo, p.nombre as producto, a.nombre as almacen_nombre
                           FROM inventario i
                           JOIN productos p ON p.id=i.producto_id
                           JOIN almacenes a ON a.id=i.almacen_id
                           WHERE i.id=?");
    $stmt->execute([$edit_id]);
    $edit_row = $stmt->fetch();
    if (!$edit_row) { alert('danger', 'Registro no encontrado'); redirect('inventario.php'); }
}

$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
$productos = $pdo->query("SELECT id, codigo, nombre FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll();

// Obtener todas las ubicaciones para JS dinamico
$todas_ubicaciones = $pdo->query("SELECT u.id, u.codigo, u.almacen_id FROM ubicaciones u WHERE u.activo=1 ORDER BY u.codigo")->fetchAll();

require 'includes/header.php';
?>

<div class="card" style="max-width: 600px; margin:0 auto;">
    <div class="card-header">
        <h2><?=$edit_row?'Editar asignacion':'Asignar producto a ubicacion'?></h2>
    </div>
    <form method="post">
        <?php if ($edit_row): ?>
        <input type="hidden" name="inventario_id" value="<?=$edit_row['id']?>">
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label>Almacen *</label>
                <select name="almacen_id" required id="selAlmacen" onchange="cargarUbicaciones()" <?=$edit_row?'disabled':''?>>
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($almacenes as $a): ?>
                    <option value="<?=$a['id']?>" <?=($edit_row && $edit_row['almacen_id']==$a['id'])?'selected':''?>><?=h($a['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($edit_row): ?>
                <input type="hidden" name="almacen_id" value="<?=$edit_row['almacen_id']?>">
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Producto *</label>
                <select name="producto_id" required <?=$edit_row?'disabled':''?>>
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($productos as $p): ?>
                    <option value="<?=$p['id']?>" <?=($edit_row && $edit_row['producto_id']==$p['id'])?'selected':''?>><?=h($p['codigo'])?> - <?=h($p['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($edit_row): ?>
                <input type="hidden" name="producto_id" value="<?=$edit_row['producto_id']?>">
                <?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Ubicacion</label>
                <select name="ubicacion_id" id="selUbicacion">
                    <option value="">-- Sin ubicacion --</option>
                    <?php if ($edit_row && $edit_row['ubicacion_id']): ?>
                    <?php
                        $ubic = $pdo->prepare("SELECT id, codigo FROM ubicaciones WHERE id=?");
                        $ubic->execute([$edit_row['ubicacion_id']]);
                        $ub = $ubic->fetch();
                    ?>
                    <option value="<?=$ub['id']?>" selected><?=h($ub['codigo'])?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Cantidad *</label>
                <input type="number" step="0.01" name="cantidad" required value="<?=h($edit_row ? $edit_row['cantidad'] : '0')?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Lote</label>
                <input type="text" name="lote" value="<?=h($edit_row ? $edit_row['lote'] : '')?>" placeholder="Opcional">
            </div>
            <div class="form-group">
                <label>Fecha de caducidad</label>
                <input type="date" name="fecha_caducidad" value="<?=h($edit_row ? $edit_row['fecha_caducidad'] : '')?>">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?=$edit_row?'Actualizar':'Asignar'?></button>
            <a href="inventario.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<!-- Lista de asignaciones actuales -->
<div class="card" style="margin-top:24px;">
    <div class="card-header"><h2>Asignaciones actuales</h2></div>
    <div class="table-wrapper">
        <table>
            <tr><th>Almacen</th><th>Producto</th><th>Ubicacion</th><th>Lote</th><th>Caducidad</th><th>Cantidad</th><th>Acciones</th></tr>
            <?php
            $asignaciones = $pdo->query("
                SELECT i.id, i.cantidad, i.lote, i.fecha_caducidad,
                       a.nombre as almacen, p.codigo as prod_codigo, p.nombre as producto,
                       u.codigo as ubicacion
                FROM inventario i
                JOIN almacenes a ON a.id=i.almacen_id
                JOIN productos p ON p.id=i.producto_id
                LEFT JOIN ubicaciones u ON u.id=i.ubicacion_id
                ORDER BY a.nombre, p.nombre, u.codigo
            ")->fetchAll();
            ?>
            <?php foreach ($asignaciones as $a): ?>
            <tr>
                <td><?=h($a['almacen'])?></td>
                <td><?=h($a['prod_codigo'])?> - <?=h($a['producto'])?></td>
                <td><?=h($a['ubicacion'] ?? '-')?></td>
                <td><?=h($a['lote'] ?? '-')?></td>
                <td><?=h($a['fecha_caducidad'] ?? '-')?></td>
                <td><?=h($a['cantidad'])?></td>
                <td class="table-actions">
                    <a href="asignar.php?action=edit&id=<?=$a['id']?>" class="btn btn-sm btn-warning">Editar</a>
                    <a href="inventario.php?producto_id=<?=$a['id']?>" class="btn btn-sm btn-info">Ver</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<script>
const ubicaciones = <?=json_encode($todas_ubicaciones)?>;
function cargarUbicaciones() {
    const almacenId = document.getElementById('selAlmacen').value;
    const sel = document.getElementById('selUbicacion');
    const selectedVal = sel.value;
    sel.innerHTML = '<option value="">-- Sin ubicacion --</option>';
    ubicaciones.filter(u => u.almacen_id == almacenId).forEach(u => {
        sel.innerHTML += `<option value="${u.id}" ${u.id==selectedVal?'selected':''}>${u.codigo}</option>`;
    });
}
<?php if ($edit_row): ?>document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('selAlmacen').value = <?=$edit_row['almacen_id']?>;
    cargarUbicaciones();
});<?php endif; ?>
</script>

<?php require 'includes/footer.php'; ?>
