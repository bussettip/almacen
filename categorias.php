<?php
$titulo = 'Categorias';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion'] ?? '');
    $padre_id = !empty($_POST['padre_id']) ? (int)$_POST['padre_id'] : null;
    $activo = isset($_POST['activo']) ? 1 : 0;

    try {
        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO categorias (nombre, descripcion, padre_id, activo) VALUES (?,?,?,?)");
            $stmt->execute([$nombre, $descripcion, $padre_id, $activo]);
            alert('success', 'Categoria creada');
        } else {
            $stmt = $pdo->prepare("UPDATE categorias SET nombre=?, descripcion=?, padre_id=?, activo=? WHERE id=?");
            $stmt->execute([$nombre, $descripcion, $padre_id, $activo, $id]);
            alert('success', 'Categoria actualizada');
        }
        redirect('categorias.php');
    } catch (PDOException $e) {
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM categorias WHERE id=?")->execute([$id]);
    alert('success', 'Categoria eliminada');
    redirect('categorias.php');
}

$cat = null;
if ($id && $action === 'edit') {
    $stmt = $pdo->prepare("SELECT * FROM categorias WHERE id=?");
    $stmt->execute([$id]); $cat = $stmt->fetch();
    if (!$cat) { alert('danger', 'No encontrada'); redirect('categorias.php'); }
}

$categorias = $pdo->query("SELECT c.*, p.nombre as padre_nombre FROM categorias c LEFT JOIN categorias p ON p.id=c.padre_id ORDER BY c.nombre")->fetchAll();

require 'includes/header.php';

if ($action === 'create' || $action === 'edit'):
    $isEdit = $action === 'edit';
    $c = $cat;
?>
<div class="card" style="max-width: 500px;">
    <div class="card-header"><h2><?=$isEdit?'Editar':'Nueva'?> Categoria</h2></div>
    <form method="post">
        <div class="form-group">
            <label>Nombre *</label>
            <input type="text" name="nombre" required value="<?=h($isEdit ? $c['nombre'] : '')?>">
        </div>
        <div class="form-group">
            <label>Categoria padre</label>
            <select name="padre_id">
                <option value="">-- Ninguna --</option>
                <?php foreach ($categorias as $cat_op): ?>
                <option value="<?=$cat_op['id']?>" <?=($isEdit && $c['padre_id']==$cat_op['id'])?'selected':''?>><?=h($cat_op['nombre'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Descripcion</label>
            <textarea name="descripcion" rows="2"><?=h($isEdit ? $c['descripcion'] : '')?></textarea>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="activo" <?=checked($isEdit ? $c['activo'] : 1)?>> Activo</label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?=$isEdit?'Actualizar':'Crear'?></button>
            <a href="categorias.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Categorias</h2>
        <a href="categorias.php?action=create" class="btn btn-primary">+ Nueva</a>
    </div>
    <div class="table-wrapper">
        <table>
            <tr><th>Nombre</th><th>Categoria padre</th><th>Activo</th><th>Acciones</th></tr>
            <?php foreach ($categorias as $c): ?>
            <tr>
                <td><?=h($c['nombre'])?></td>
                <td><?=h($c['padre_nombre'] ?? '-')?></td>
                <td><span class="badge <?=$c['activo']?'badge-success':'badge-secondary'?>"><?=$c['activo']?'Si':'No'?></span></td>
                <td class="table-actions">
                    <a href="categorias.php?action=edit&id=<?=$c['id']?>" class="btn btn-sm btn-warning">Editar</a>
                    <a href="categorias.php?action=delete&id=<?=$c['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar?')">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
