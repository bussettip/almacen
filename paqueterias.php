<?php
$titulo = 'Paqueterias';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$pdo->exec("CREATE TABLE IF NOT EXISTS paqueterias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    telefono VARCHAR(30),
    website VARCHAR(255),
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS zonas_paqueteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255),
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS costos_paqueteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paqueteria_id INT NOT NULL,
    zona_id INT NOT NULL,
    costo DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_paq_zona (paqueteria_id, zona_id),
    FOREIGN KEY (paqueteria_id) REFERENCES paqueterias(id) ON DELETE CASCADE,
    FOREIGN KEY (zona_id) REFERENCES zonas_paqueteria(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ---- Paqueteria CRUD ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_paq') {
    $nombre = trim($_POST['nombre']);
    $telefono = trim($_POST['telefono'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $pdo->prepare("INSERT INTO paqueterias (nombre,telefono,website) VALUES (?,?,?)")->execute([$nombre,$telefono?:null,$website?:null]);
    alert('success','Paqueteria creada'); redirect('paqueterias.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_paq' && $id) {
    $nombre = trim($_POST['nombre']);
    $telefono = trim($_POST['telefono'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $activo = isset($_POST['activo']) ? 1 : 0;
    $pdo->prepare("UPDATE paqueterias SET nombre=?,telefono=?,website=?,activo=? WHERE id=?")->execute([$nombre,$telefono?:null,$website?:null,$activo,$id]);
    alert('success','Paqueteria actualizada'); redirect('paqueterias.php');
}
if ($action === 'delete_paq' && $id) {
    $pdo->prepare("DELETE FROM paqueterias WHERE id=?")->execute([$id]);
    alert('success','Paqueteria eliminada'); redirect('paqueterias.php');
}

// ---- Zona CRUD ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_zona') {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion'] ?? '');
    $pdo->prepare("INSERT INTO zonas_paqueteria (nombre,descripcion) VALUES (?,?)")->execute([$nombre,$descripcion?:null]);
    alert('success','Zona creada'); redirect('paqueterias.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_zona' && $id) {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion'] ?? '');
    $activo = isset($_POST['activo']) ? 1 : 0;
    $pdo->prepare("UPDATE zonas_paqueteria SET nombre=?,descripcion=?,activo=? WHERE id=?")->execute([$nombre,$descripcion?:null,$activo,$id]);
    alert('success','Zona actualizada'); redirect('paqueterias.php');
}
if ($action === 'delete_zona' && $id) {
    $pdo->prepare("DELETE FROM zonas_paqueteria WHERE id=?")->execute([$id]);
    alert('success','Zona eliminada'); redirect('paqueterias.php');
}

// ---- Costo CRUD ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_costos') {
    if (!empty($_POST['costos'])) {
        $paqueteria_id = (int)$_POST['paqueteria_id'];
        $pdo->prepare("DELETE FROM costos_paqueteria WHERE paqueteria_id=?")->execute([$paqueteria_id]);
        $ins = $pdo->prepare("INSERT INTO costos_paqueteria (paqueteria_id,zona_id,costo) VALUES (?,?,?)");
        foreach ($_POST['costos'] as $zona_id => $costo) {
            $costo = (float)str_replace(',','',$costo);
            if ($costo > 0) $ins->execute([$paqueteria_id,(int)$zona_id,$costo]);
        }
    }
    alert('success','Costos guardados'); redirect('paqueterias.php');
}

$paqueterias = $pdo->query("SELECT * FROM paqueterias ORDER BY nombre")->fetchAll();
$zonas = $pdo->query("SELECT * FROM zonas_paqueteria ORDER BY nombre")->fetchAll();

require 'includes/header.php';
?>
<style>
.tabs{display:flex;gap:4px;margin-bottom:16px}
.tabs a{padding:8px 16px;background:#eef2f7;border-radius:6px 6px 0 0;text-decoration:none;color:#555;font-size:.85rem}
.tabs a.active{background:#5b9bd5;color:#fff}
.costo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
.costo-card{border:1px solid #ddd;border-radius:6px;padding:12px}
.costo-card h4{margin:0 0 8px;font-size:.9rem}
.costo-card label{font-size:.75rem;color:#888}
</style>

<div style="display:flex;justify-content:space-between;align-items:center"><h2>Paqueterias y Zonas</h2></div>

<div class="tabs">
    <a href="paqueterias.php" class="<?=!isset($_GET['action'])||strpos($action,'paq')===0?'active':''?>">Paqueterias</a>
    <a href="paqueterias.php?action=zonas" class="<?=$action==='zonas'||strpos($action,'zona')===0?'active':''?>">Zonas</a>
    <a href="paqueterias.php?action=costos" class="<?=$action==='costos'||strpos($action,'costo')===0?'active':''?>">Costos</a>
</div>

<?php if ($action==='zonas' || strpos($action,'zona')===0): $edit_zona = null; if ($action==='edit_zona'&&$id) { $stmt = $pdo->prepare("SELECT * FROM zonas_paqueteria WHERE id=?"); $stmt->execute([$id]); $edit_zona = $stmt->fetch(); } ?>
<div class="card">
    <div class="card-header"><h3><?=$edit_zona?'Editar':'Nueva'?> Zona</h3></div>
    <form method="post" action="paqueterias.php?action=<?=$edit_zona?'edit_zona&id='.$edit_zona['id']:'create_zona'?>" style="max-width:400px">
        <div class="form-group"><label>Nombre *</label><input type="text" name="nombre" required value="<?=h($edit_zona['nombre']??'')?>"></div>
        <div class="form-group"><label>Descripcion</label><input type="text" name="descripcion" value="<?=h($edit_zona['descripcion']??'')?>"></div>
        <?php if($edit_zona):?><div class="form-group"><label><input type="checkbox" name="activo" <?=$edit_zona['activo']?'checked':''?>> Activo</label></div><?php endif;?>
        <button type="submit" class="btn btn-primary"><?=$edit_zona?'Actualizar':'Crear'?></button>
        <?php if($edit_zona):?><a href="paqueterias.php?action=zonas" class="btn btn-secondary">Cancelar</a><?php endif;?>
    </form>
</div>
<div class="card" style="margin-top:12px">
    <div class="card-header"><h3>Zonas</h3></div>
    <table>
        <tr><th>Nombre</th><th>Descripcion</th><th>Activo</th><th>Acciones</th></tr>
        <?php foreach($zonas as $z):?>
        <tr>
            <td><?=h($z['nombre'])?></td>
            <td><?=h($z['descripcion']??'-')?></td>
            <td><span class="badge <?=$z['activo']?'badge-success':'badge-secondary'?>"><?=$z['activo']?'Si':'No'?></span></td>
            <td class="table-actions">
                <a href="paqueterias.php?action=edit_zona&id=<?=$z['id']?>" class="btn btn-sm btn-warning">Editar</a>
                <a href="paqueterias.php?action=delete_zona&id=<?=$z['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar zona?')">Eliminar</a>
            </td>
        </tr>
        <?php endforeach;?>
    </table>
</div>

<?php elseif($action==='costos' || strpos($action,'costo')===0): ?>
<div class="costo-grid">
    <?php foreach($paqueterias as $paq):
        $costos_paq = $pdo->prepare("SELECT zona_id,costo FROM costos_paqueteria WHERE paqueteria_id=?");
        $costos_paq->execute([$paq['id']]);
        $costos_map = [];
        foreach ($costos_paq->fetchAll() as $c) $costos_map[$c['zona_id']] = $c['costo'];
    ?>
    <div class="costo-card">
        <h4><?=h($paq['nombre'])?></h4>
        <form method="post" action="paqueterias.php?action=save_costos">
            <input type="hidden" name="paqueteria_id" value="<?=$paq['id']?>">
            <?php foreach($zonas as $z):?>
            <div style="margin-bottom:6px">
                <label><?=h($z['nombre'])?></label>
                <input type="text" name="costos[<?=$z['id']?>]" value="<?=number_format($costos_map[$z['id']]??0,2)?>" style="width:100px">
            </div>
            <?php endforeach;?>
            <button type="submit" class="btn btn-primary btn-sm">Guardar</button>
        </form>
    </div>
    <?php endforeach;?>
</div>

<?php else:
$edit_paq = null;
if ($action==='edit_paq'&&$id) { $stmt = $pdo->prepare("SELECT * FROM paqueterias WHERE id=?"); $stmt->execute([$id]); $edit_paq = $stmt->fetch(); }
?>
<div class="card">
    <div class="card-header"><h3><?=$edit_paq?'Editar':'Nueva'?> Paqueteria</h3></div>
    <form method="post" action="paqueterias.php?action=<?=$edit_paq?'edit_paq&id='.$edit_paq['id']:'create_paq'?>" style="max-width:400px">
        <div class="form-group"><label>Nombre *</label><input type="text" name="nombre" required value="<?=h($edit_paq['nombre']??'')?>"></div>
        <div class="form-group"><label>Telefono</label><input type="text" name="telefono" value="<?=h($edit_paq['telefono']??'')?>"></div>
        <div class="form-group"><label>Sitio web</label><input type="text" name="website" value="<?=h($edit_paq['website']??'')?>"></div>
        <?php if($edit_paq):?><div class="form-group"><label><input type="checkbox" name="activo" <?=$edit_paq['activo']?'checked':''?>> Activo</label></div><?php endif;?>
        <button type="submit" class="btn btn-primary"><?=$edit_paq?'Actualizar':'Crear'?></button>
        <?php if($edit_paq):?><a href="paqueterias.php" class="btn btn-secondary">Cancelar</a><?php endif;?>
    </form>
</div>
<div class="card" style="margin-top:12px">
    <div class="card-header"><h3>Paqueterias</h3></div>
    <table>
        <tr><th>Nombre</th><th>Telefono</th><th>Sitio web</th><th>Activo</th><th>Acciones</th></tr>
        <?php foreach($paqueterias as $p):?>
        <tr>
            <td><?=h($p['nombre'])?></td>
            <td><?=h($p['telefono']??'-')?></td>
            <td><?=$p['website']?'<a href="'.h($p['website']).'" target="_blank">'.h($p['website']).'</a>':'-'?></td>
            <td><span class="badge <?=$p['activo']?'badge-success':'badge-secondary'?>"><?=$p['activo']?'Si':'No'?></span></td>
            <td class="table-actions">
                <a href="paqueterias.php?action=edit_paq&id=<?=$p['id']?>" class="btn btn-sm btn-warning">Editar</a>
                <a href="paqueterias.php?action=delete_paq&id=<?=$p['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar paqueteria?')">Eliminar</a>
            </td>
        </tr>
        <?php endforeach;?>
    </table>
</div>
<?php endif;?>

<?php require 'includes/footer.php'; ?>
