<?php
$titulo = 'Proveedores';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $rfc = trim($_POST['rfc'] ?? '');
        $nombre = trim($_POST['nombre']);
        $contacto = trim($_POST['contacto'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO proveedores (rfc,nombre,contacto,telefono,email,direccion,activo) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$rfc?:null,$nombre,$contacto,$telefono,$email,$direccion,$activo]);
            alert('success', 'Proveedor creado');
        } else {
            $stmt = $pdo->prepare("UPDATE proveedores SET rfc=?,nombre=?,contacto=?,telefono=?,email=?,direccion=?,activo=? WHERE id=?");
            $stmt->execute([$rfc?:null,$nombre,$contacto,$telefono,$email,$direccion,$activo,$id]);
            alert('success', 'Proveedor actualizado');
        }
        redirect('proveedores.php');
    } catch (PDOException $e) {
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM proveedores WHERE id=?")->execute([$id]);
    alert('success', 'Proveedor eliminado');
    redirect('proveedores.php');
}

$prov = null;
if ($id && ($action === 'edit' || $action === 'delete')) {
    $stmt = $pdo->prepare("SELECT * FROM proveedores WHERE id=?");
    $stmt->execute([$id]); $prov = $stmt->fetch();
    if (!$prov) { alert('danger', 'No encontrado'); redirect('proveedores.php'); }
}

$search = $_GET['search'] ?? '';
$sql = "SELECT * FROM proveedores WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND (nombre LIKE ? OR rfc LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY nombre";
$proveedores = $pdo->prepare($sql);
$proveedores->execute($params);

require 'includes/header.php';

if ($action === 'create' || $action === 'edit'):
    $isEdit = $action === 'edit';
    $p = $prov;
?>
<div class="card" style="max-width: 600px;">
    <div class="card-header"><h2><?=$isEdit?'Editar':'Nuevo'?> Proveedor</h2></div>
    <form method="post">
        <div class="form-row">
            <div class="form-group">
                <label>RFC</label>
                <input type="text" name="rfc" value="<?=h($isEdit ? $p['rfc'] : '')?>">
            </div>
            <div class="form-group">
                <label>Telefono</label>
                <input type="text" name="telefono" value="<?=h($isEdit ? $p['telefono'] : '')?>">
            </div>
        </div>
        <div class="form-group">
            <label>Nombre / Razon social *</label>
            <input type="text" name="nombre" required value="<?=h($isEdit ? $p['nombre'] : '')?>">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Contacto</label>
                <input type="text" name="contacto" value="<?=h($isEdit ? $p['contacto'] : '')?>">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?=h($isEdit ? $p['email'] : '')?>">
            </div>
        </div>
        <div class="form-group">
            <label>Direccion</label>
            <textarea name="direccion" rows="2"><?=h($isEdit ? $p['direccion'] : '')?></textarea>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="activo" <?=checked($isEdit ? $p['activo'] : 1)?>> Activo</label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?=$isEdit?'Actualizar':'Crear'?></button>
            <a href="proveedores.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Proveedores</h2>
        <a href="proveedores.php?action=create" class="btn btn-primary">+ Nuevo</a>
    </div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Buscar..." value="<?=h($search)?>">
        <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
    </form>
    <div class="table-wrapper">
        <table>
            <tr><th>RFC</th><th>Nombre</th><th>Contacto</th><th>Telefono</th><th>Email</th><th>Activo</th><th>Acciones</th></tr>
            <?php foreach ($proveedores as $p): ?>
            <tr>
                <td><?=h($p['rfc'] ?? '-')?></td>
                <td><?=h($p['nombre'])?></td>
                <td><?=h($p['contacto'])?></td>
                <td><?=h($p['telefono'])?></td>
                <td><?=h($p['email'])?></td>
                <td><span class="badge <?=$p['activo']?'badge-success':'badge-secondary'?>"><?=$p['activo']?'Si':'No'?></span></td>
                <td class="table-actions">
                    <a href="proveedores.php?action=edit&id=<?=$p['id']?>" class="btn btn-sm btn-warning">Editar</a>
                    <a href="proveedores.php?action=delete&id=<?=$p['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar?')">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
