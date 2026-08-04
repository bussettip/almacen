<?php
$titulo = 'Categorias de Empleados';
require 'includes/auth.php';

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create') {
        $nombre = trim($_POST['nombre']);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;
        $pdo->prepare("INSERT INTO categorias_empleados (nombre, descripcion, activo) VALUES (?,?,?)")->execute([$nombre, $descripcion, $activo]);
        alert('success', "Categoria $nombre creada");
        redirect('categorias_empleados.php');
    }
    if ($action === 'edit' && $id) {
        $nombre = trim($_POST['nombre']);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;
        $pdo->prepare("UPDATE categorias_empleados SET nombre=?, descripcion=?, activo=? WHERE id=?")->execute([$nombre, $descripcion, $activo, $id]);
        alert('success', 'Categoria actualizada');
        redirect('categorias_empleados.php');
    }
}

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM categorias_empleados WHERE id=?")->execute([$id]);
    alert('info', 'Categoria eliminada');
    redirect('categorias_empleados.php');
}

$search = $_GET['search'] ?? '';
$sql = "SELECT c.*, (SELECT COUNT(*) FROM empleados WHERE categoria_id=c.id) as total_empleados FROM categorias_empleados c WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND c.nombre LIKE ?"; $params[] = "%$search%"; }
$sql .= " ORDER BY c.nombre";
$rows = $pdo->prepare($sql); $rows->execute($params);

if ($action === 'edit' && $id) {
    $row = $pdo->prepare("SELECT * FROM categorias_empleados WHERE id=?");
    $row->execute([$id]); $row = $row->fetch();
    if (!$row) { alert('danger', 'No encontrada'); redirect('categorias_empleados.php'); }
}

require 'includes/header.php';
?>

<?php if ($action === 'create' || ($action === 'edit' && isset($row))): ?>
<div class="card" style="max-width:500px">
    <div class="card-header"><h2><?=$action==='create'?'Nueva':'Editar'?> Categoria</h2></div>
    <form method="post">
        <div class="form-group">
            <label>Nombre *</label>
            <input type="text" name="nombre" required value="<?=h($action==='edit'?$row['nombre']:'')?>">
        </div>
        <div class="form-group">
            <label>Descripcion</label>
            <textarea name="descripcion" rows="3"><?=h($action==='edit'?$row['descripcion']:'')?></textarea>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="activo" <?=checked($action==='edit'?$row['activo']:1)?>> Activo</label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?=$action==='create'?'Crear':'Actualizar'?></button>
            <a href="categorias_empleados.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Categorias de Empleados</h2>
        <div class="table-actions">
            <button type="button" class="btn btn-success btn-sm" onclick="exportarTablaExcel('#tabla-listado','categorias_empleados')"> Exportar Excel</button>
            <button type="button" class="btn btn-info btn-sm" onclick="imprimirTablaPDF('#tabla-listado','Categorias de Empleados')"> Imprimir PDF</button>
            <a href="categorias_empleados.php?action=create" class="btn btn-primary btn-sm">+ Nueva</a>
        </div>
    </div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Buscar categoria..." value="<?=h($search)?>">
        <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
    </form>
    <div class="table-wrapper">
        <table id="tabla-listado">
            <tr><th>Nombre</th><th>Descripcion</th><th>Empleados</th><th>Estado</th><th>Acciones</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?=h($r['nombre'])?></td>
                <td><?=h($r['descripcion'] ?? '--')?></td>
                <td><?=$r['total_empleados']?></td>
                <td><span class="badge badge-<?=$r['activo']?'success':'secondary'?>"><?=$r['activo']?'Activo':'Inactivo'?></span></td>
                <td class="table-actions">
                    <a href="categorias_empleados.php?action=edit&id=<?=$r['id']?>" class="btn btn-sm btn-warning">Editar</a>
                    <a href="categorias_empleados.php?action=delete&id=<?=$r['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar?')">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
