<?php
$titulo = 'Configuracion';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$pdo->exec("CREATE TABLE IF NOT EXISTS config_empresa (
    id INT PRIMARY KEY DEFAULT 1,
    nombre VARCHAR(255) NOT NULL DEFAULT 'Mi Empresa',
    logo VARCHAR(255) DEFAULT NULL,
    direccion TEXT,
    telefono VARCHAR(50),
    email VARCHAR(255),
    rfc VARCHAR(20),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("INSERT IGNORE INTO config_empresa (id, nombre) VALUES (1, 'Mi Empresa')");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $direccion = trim($_POST['direccion'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $rfc = trim($_POST['rfc'] ?? '');
    $logo_actual = $_POST['logo_actual'] ?? '';

    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','gif','svg'])) {
            $dir = __DIR__.'/uploads/empresa/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $nom = 'logo.'.$ext;
            move_uploaded_file($_FILES['logo']['tmp_name'], $dir.$nom);
            if ($logo_actual && $logo_actual !== $nom && file_exists($dir.$logo_actual)) @unlink($dir.$logo_actual);
            $logo_actual = $nom;
        }
    }

    $pdo->prepare("UPDATE config_empresa SET nombre=?,logo=?,direccion=?,telefono=?,email=?,rfc=? WHERE id=1")
        ->execute([$nombre,$logo_actual?:null,$direccion?:null,$telefono?:null,$email?:null,$rfc?:null]);
    alert('success', 'Configuracion guardada');
    redirect('configuracion.php');
}

$cfg = $pdo->query("SELECT * FROM config_empresa WHERE id=1")->fetch();
$dir_logo = __DIR__.'/uploads/empresa/';
$logo_url = $cfg['logo'] && file_exists($dir_logo.$cfg['logo']) ? 'uploads/empresa/'.$cfg['logo'] : '';

require 'includes/header.php';
?>
<div class="card" style="max-width:650px">
    <div class="card-header"><h2>Configuracion de la Empresa</h2></div>
    <form method="post" enctype="multipart/form-data">
        <div class="form-group">
            <label>Nombre de la empresa *</label>
            <input type="text" name="nombre" required value="<?=h($cfg['nombre'])?>">
        </div>
        <div class="form-group">
            <label>Logo</label>
            <input type="hidden" name="logo_actual" value="<?=h($cfg['logo'] ?? '')?>">
            <input type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml">
            <?php if ($logo_url): ?>
            <div style="margin-top:8px">
                <img src="<?=$logo_url?>" alt="Logo" style="max-height:80px;border-radius:6px;border:1px solid #ddd;">
            </div>
            <?php endif; ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>RFC</label>
                <input type="text" name="rfc" value="<?=h($cfg['rfc'] ?? '')?>">
            </div>
            <div class="form-group">
                <label>Telefono</label>
                <input type="text" name="telefono" value="<?=h($cfg['telefono'] ?? '')?>">
            </div>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?=h($cfg['email'] ?? '')?>">
        </div>
        <div class="form-group">
            <label>Direccion</label>
            <textarea name="direccion" rows="2"><?=h($cfg['direccion'] ?? '')?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Guardar</button>
            <a href="dashboard.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<?php require 'includes/footer.php'; ?>
