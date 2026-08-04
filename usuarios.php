<?php
$titulo = 'Usuarios';
require 'includes/auth.php';

// Solo admin
if ($usuario['rol_nombre'] !== 'admin') {
    alert('danger', 'Acceso denegado');
    redirect('dashboard.php');
}

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nombre = trim($_POST['nombre']);
        $email = trim($_POST['email']);
        $rol_id = (int)$_POST['rol_id'];
        $almacen_id = !empty($_POST['almacen_id']) ? (int)$_POST['almacen_id'] : null;
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($action === 'create') {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, password_hash, rol_id, almacen_id, activo) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$nombre, $email, $password, $rol_id, $almacen_id, $activo]);
            alert('success', 'Usuario creado');
        } else {
            if (!empty($_POST['password'])) {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, email=?, password_hash=?, rol_id=?, almacen_id=?, activo=? WHERE id=?");
                $stmt->execute([$nombre, $email, $password, $rol_id, $almacen_id, $activo, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, email=?, rol_id=?, almacen_id=?, activo=? WHERE id=?");
                $stmt->execute([$nombre, $email, $rol_id, $almacen_id, $activo, $id]);
            }
            alert('success', 'Usuario actualizado');
        }
        redirect('usuarios.php');
    } catch (PDOException $e) {
        alert('danger', 'Error: ' . ($e->getCode()==23000?'El email ya existe':$e->getMessage()));
    }
}

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM usuarios WHERE id=?")->execute([$id]);
    alert('success', 'Usuario eliminado');
    redirect('usuarios.php');
}

$user = null;
if ($id && ($action === 'edit' || $action === 'delete')) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id=?");
    $stmt->execute([$id]); $user = $stmt->fetch();
    if (!$user) { alert('danger', 'No encontrado'); redirect('usuarios.php'); }
}

$roles = $pdo->query("SELECT * FROM roles ORDER BY nombre")->fetchAll();
$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
$usuarios = $pdo->query("SELECT u.*, r.nombre as rol_nombre, a.nombre as almacen_nombre FROM usuarios u JOIN roles r ON r.id=u.rol_id LEFT JOIN almacenes a ON a.id=u.almacen_id ORDER BY u.nombre")->fetchAll();

require 'includes/header.php';

if ($action === 'create' || $action === 'edit'):
    $isEdit = $action === 'edit';
    $u = $user;
?>
<div class="card" style="max-width: 500px;">
    <div class="card-header"><h2><?=$isEdit?'Editar':'Nuevo'?> Usuario</h2></div>
    <form method="post">
        <div class="form-group">
            <label>Nombre *</label>
            <input type="text" name="nombre" required value="<?=h($isEdit ? $u['nombre'] : '')?>">
        </div>
        <div class="form-group">
            <label>Email *</label>
            <input type="email" name="email" required value="<?=h($isEdit ? $u['email'] : '')?>">
        </div>
        <div class="form-group">
            <label>Contrasena <?=$isEdit?'(dejar vacio para mantener)':''?> *</label>
            <input type="password" name="password" <?=$isEdit?'':'required'?>>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Rol</label>
                <select name="rol_id">
                    <?php foreach ($roles as $r): ?>
                    <option value="<?=$r['id']?>" <?=($isEdit && $u['rol_id']==$r['id'])?'selected':''?>><?=h($r['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Almacen (opcional)</label>
                <select name="almacen_id">
                    <option value="">-- Todos --</option>
                    <?php foreach ($almacenes as $a): ?>
                    <option value="<?=$a['id']?>" <?=($isEdit && $u['almacen_id']==$a['id'])?'selected':''?>><?=h($a['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="activo" <?=checked($isEdit ? $u['activo'] : 1)?>> Activo</label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?=$isEdit?'Actualizar':'Crear'?></button>
            <a href="usuarios.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Usuarios</h2>
        <a href="usuarios.php?action=create" class="btn btn-primary">+ Nuevo</a>
    </div>
    <div class="table-wrapper">
        <table>
            <tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Almacen</th><th>Ultimo acceso</th><th>Activo</th><th>Acciones</th></tr>
            <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?=h($u['nombre'])?></td>
                <td><?=h($u['email'])?></td>
                <td><span class="badge badge-info"><?=h($u['rol_nombre'])?></span></td>
                <td><?=h($u['almacen_nombre'] ?? 'Todos')?></td>
                <td><?=$u['ultimo_acceso'] ?? 'Nunca'?></td>
                <td><span class="badge <?=$u['activo']?'badge-success':'badge-secondary'?>"><?=$u['activo']?'Si':'No'?></span></td>
                <td class="table-actions">
                    <a href="usuarios.php?action=edit&id=<?=$u['id']?>" class="btn btn-sm btn-warning">Editar</a>
                    <a href="usuarios.php?action=delete&id=<?=$u['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar usuario?')">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
