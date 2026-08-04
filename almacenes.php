<?php
$titulo = 'Almacenes';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create' || $action === 'edit') {
            $codigo = trim($_POST['codigo']);
            $nombre = trim($_POST['nombre']);
            $direccion = trim($_POST['direccion'] ?? '');
            $ciudad = trim($_POST['ciudad'] ?? '');
            $estado = trim($_POST['estado'] ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $activo = isset($_POST['activo']) ? 1 : 0;

            if ($action === 'create') {
                $stmt = $pdo->prepare("INSERT INTO almacenes (codigo, nombre, direccion, ciudad, estado, telefono, activo) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$codigo, $nombre, $direccion, $ciudad, $estado, $telefono, $activo]);
                alert('success', 'Almacen creado correctamente');
            } else {
                $stmt = $pdo->prepare("UPDATE almacenes SET codigo=?, nombre=?, direccion=?, ciudad=?, estado=?, telefono=?, activo=? WHERE id=?");
                $stmt->execute([$codigo, $nombre, $direccion, $ciudad, $estado, $telefono, $activo, $id]);
                alert('success', 'Almacen actualizado correctamente');
            }
            redirect('almacenes.php');
        }
        if ($action === 'delete' && $id) {
            $pdo->prepare("DELETE FROM almacenes WHERE id=?" )->execute([$id]);
            alert('success', 'Almacen eliminado');
            redirect('almacenes.php');
        }
    } catch (PDOException $e) {
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

$almacen = null;
if ($id && ($action === 'edit' || $action === 'delete')) {
    $stmt = $pdo->prepare("SELECT * FROM almacenes WHERE id=?");
    $stmt->execute([$id]);
    $almacen = $stmt->fetch();
    if (!$almacen) { alert('danger', 'Almacen no encontrado'); redirect('almacenes.php'); }
}

$search = $_GET['search'] ?? '';
$sql = "SELECT * FROM almacenes WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND (nombre LIKE ? OR codigo LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY nombre";
$almacenes = $pdo->prepare($sql);
$almacenes->execute($params);

require 'includes/header.php';

if ($action === 'create' || $action === 'edit'):
    $isEdit = $action === 'edit';
    $a = $almacen;
?>
<div class="card" style="max-width: 600px;">
    <div class="card-header"><h2><?=$isEdit?'Editar':'Nuevo'?> Almacen</h2></div>
    <form method="post">
        <div class="form-group">
            <label>Codigo *</label>
            <input type="text" name="codigo" required value="<?=h($isEdit ? $a['codigo'] : '')?>">
        </div>
        <div class="form-group">
            <label>Nombre *</label>
            <input type="text" name="nombre" required value="<?=h($isEdit ? $a['nombre'] : '')?>">
        </div>
        <div class="form-group">
            <label>Direccion</label>
            <textarea name="direccion" rows="2"><?=h($isEdit ? $a['direccion'] : '')?></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Ciudad</label>
                <input type="text" name="ciudad" value="<?=h($isEdit ? $a['ciudad'] : '')?>">
            </div>
            <div class="form-group">
                <label>Estado</label>
                <input type="text" name="estado" value="<?=h($isEdit ? $a['estado'] : '')?>">
            </div>
        </div>
        <div class="form-group">
            <label>Telefono</label>
            <input type="text" name="telefono" value="<?=h($isEdit ? $a['telefono'] : '')?>">
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="activo" <?=checked($isEdit ? $a['activo'] : 1)?>> Activo</label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?=$isEdit?'Actualizar':'Crear'?></button>
            <a href="almacenes.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Almacenes</h2>
        <a href="almacenes.php?action=create" class="btn btn-primary">+ Nuevo</a>
    </div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Buscar..." value="<?=h($search)?>">
        <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
    </form>
    <div class="table-wrapper">
        <table>
            <tr>
                <th>Codigo</th><th>Nombre</th><th>Ciudad</th><th>Estado</th><th>Telefono</th><th>Activo</th><th>Acciones</th>
            </tr>
            <?php foreach ($almacenes as $a): ?>
            <tr>
                <td><?=h($a['codigo'])?></td>
                <td><a href="inventario.php?almacen_id=<?=$a['id']?>"><?=h($a['nombre'])?></a></td>
                <td><?=h($a['ciudad'])?></td>
                <td><?=h($a['estado'])?></td>
                <td><?=h($a['telefono'])?></td>
                <td><span class="badge <?=$a['activo']?'badge-success':'badge-secondary'?>"><?=$a['activo']?'Si':'No'?></span></td>
                <td class="table-actions">
                    <a href="ubicaciones.php?almacen_id=<?=$a['id']?>" class="btn btn-sm btn-info">Ubicaciones</a>
                    <a href="almacenes.php?action=edit&id=<?=$a['id']?>" class="btn btn-sm btn-warning">Editar</a>
                    <a href="almacenes.php?action=delete&id=<?=$a['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este almacen?')">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
