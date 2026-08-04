<?php
$titulo = 'Empleados';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

// Crear tablas si no existen
$pdo->exec("CREATE TABLE IF NOT EXISTS categorias_empleados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT NULL,
    activo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS empleados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(20) UNIQUE NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    email VARCHAR(100) NULL,
    telefono VARCHAR(30) NULL,
    categoria_id INT NULL,
    activo TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (categoria_id) REFERENCES categorias_empleados(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Seed categorias si estan vacias
$count = $pdo->query("SELECT COUNT(*) FROM categorias_empleados")->fetchColumn();
if ($count == 0) {
    $pdo->exec("INSERT INTO categorias_empleados (nombre, descripcion) VALUES
        ('Almacenista', 'Encargado de almacen y preparacion de pedidos'),
        ('Supervisor', 'Supervisor de operaciones de almacen'),
        ('Operador', 'Operador de maquinaria y equipo'),
        ('Administrativo', 'Personal administrativo y de oficina'),
        ('Chofer', 'Conductor de vehiculos de carga'),
        ('Seguridad', 'Personal de seguridad')");
}

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create') {
        $codigo = trim($_POST['codigo']);
        $nombre = trim($_POST['nombre']);
        $apellido = trim($_POST['apellido']);
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $categoria_id = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
        $activo = isset($_POST['activo']) ? 1 : 0;

        $stmt = $pdo->prepare("INSERT INTO empleados (codigo, nombre, apellido, email, telefono, categoria_id, activo) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$codigo, $nombre, $apellido, $email, $telefono, $categoria_id, $activo]);
        alert('success', "Empleado $codigo creado");
        redirect('empleados.php');
    }

    if ($action === 'edit' && $id) {
        $codigo = trim($_POST['codigo']);
        $nombre = trim($_POST['nombre']);
        $apellido = trim($_POST['apellido']);
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $categoria_id = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
        $activo = isset($_POST['activo']) ? 1 : 0;

        $stmt = $pdo->prepare("UPDATE empleados SET codigo=?, nombre=?, apellido=?, email=?, telefono=?, categoria_id=?, activo=? WHERE id=?");
        $stmt->execute([$codigo, $nombre, $apellido, $email, $telefono, $categoria_id, $activo, $id]);
        alert('success', 'Empleado actualizado');
        redirect('empleados.php');
    }
}

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM empleados WHERE id=?")->execute([$id]);
    alert('info', 'Empleado eliminado');
    redirect('empleados.php');
}

$search = $_GET['search'] ?? '';
$sql = "SELECT e.*, c.nombre as categoria_nom FROM empleados e LEFT JOIN categorias_empleados c ON c.id=e.categoria_id WHERE 1=1";
$params = [];
if ($search) {
    $sql .= " AND (e.nombre LIKE ? OR e.apellido LIKE ? OR e.codigo LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$sql .= " ORDER BY e.apellido, e.nombre";
$empleados = $pdo->prepare($sql);
$empleados->execute($params);

$categorias = $pdo->query("SELECT * FROM categorias_empleados WHERE activo=1 ORDER BY nombre")->fetchAll();

if ($action === 'edit' && $id) {
    $row = $pdo->prepare("SELECT * FROM empleados WHERE id=?");
    $row->execute([$id]); $row = $row->fetch();
    if (!$row) { alert('danger', 'No encontrado'); redirect('empleados.php'); }
}

require 'includes/header.php';
?>

<?php if ($action === 'create' || ($action === 'edit' && isset($row))): ?>
<div class="card" style="max-width:600px">
    <div class="card-header"><h2><?=$action==='create'?'Nuevo':'Editar'?> Empleado</h2></div>
    <form method="post">
        <div class="form-row">
            <div class="form-group">
                <label>Codigo *</label>
                <input type="text" name="codigo" required value="<?=h($action==='edit'?$row['codigo']:'EMP-'.strtoupper(substr(uniqid(),-5)))?>">
            </div>
            <div class="form-group">
                <label>Categoria</label>
                <select name="categoria_id">
                    <option value="">-- Sin categoria --</option>
                    <?php foreach ($categorias as $c): ?>
                    <option value="<?=$c['id']?>" <?=($action==='edit'&&$row['categoria_id']==$c['id'])?'selected':''?>><?=h($c['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Nombre *</label>
                <input type="text" name="nombre" required value="<?=h($action==='edit'?$row['nombre']:'')?>">
            </div>
            <div class="form-group">
                <label>Apellido *</label>
                <input type="text" name="apellido" required value="<?=h($action==='edit'?$row['apellido']:'')?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?=h($action==='edit'?$row['email']:'')?>">
            </div>
            <div class="form-group">
                <label>Telefono</label>
                <input type="text" name="telefono" value="<?=h($action==='edit'?$row['telefono']:'')?>">
            </div>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="activo" <?=checked($action==='edit'?$row['activo']:1)?>> Activo</label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?=$action==='create'?'Crear':'Actualizar'?></button>
            <a href="empleados.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Empleados</h2>
        <div>
            <a href="categorias_empleados.php" class="btn btn-info btn-sm">Categorias</a>
            <a href="empleados.php?action=create" class="btn btn-primary">+ Nuevo</a>
        </div>
    </div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Buscar nombre, apellido o codigo..." value="<?=h($search)?>">
        <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
    </form>
    <div class="table-wrapper">
        <table>
            <tr><th>Codigo</th><th>Nombre</th><th>Apellido</th><th>Categoria</th><th>Email</th><th>Telefono</th><th>Estado</th><th>Acciones</th></tr>
            <?php foreach ($empleados as $e): ?>
            <tr>
                <td><?=h($e['codigo'])?></td>
                <td><?=h($e['nombre'])?></td>
                <td><?=h($e['apellido'])?></td>
                <td><?=h($e['categoria_nom'] ?? '--')?></td>
                <td><?=h($e['email'] ?? '--')?></td>
                <td><?=h($e['telefono'] ?? '--')?></td>
                <td><span class="badge badge-<?=$e['activo']?'success':'secondary'?>"><?=$e['activo']?'Activo':'Inactivo'?></span></td>
                <td class="table-actions">
                    <a href="empleados.php?action=edit&id=<?=$e['id']?>" class="btn btn-sm btn-warning">Editar</a>
                    <a href="empleados.php?action=delete&id=<?=$e['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar?')">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
