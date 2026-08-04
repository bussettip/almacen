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

require_once 'includes/cfdi_helper.php';
cfdiMigrar($pdo);

$cfg = $pdo->query("SELECT * FROM config_empresa WHERE id=1")->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $razon_social = trim($_POST['razon_social'] ?? '');
    $regimen_fiscal = trim($_POST['regimen_fiscal'] ?? '');
    $codigo_postal = trim($_POST['codigo_postal'] ?? '');
    $serie_cfdi = trim($_POST['serie_cfdi'] ?? 'F');
    $direccion = trim($_POST['direccion'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $rfc = trim($_POST['rfc'] ?? '');
    $csd_password = trim($_POST['csd_password'] ?? '');
    $finkok_user = trim($_POST['finkok_user'] ?? '');
    $finkok_password = trim($_POST['finkok_password'] ?? '');
    $finkok_ambiente = ($_POST['finkok_ambiente'] ?? 'pruebas') === 'produccion' ? 'produccion' : 'pruebas';
    $logo_actual = $_POST['logo_actual'] ?? '';
    $csd_cer_actual = $cfg['csd_cer'] ?? null;
    $csd_key_actual = $cfg['csd_key'] ?? null;

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

    $dir_csd = __DIR__.'/uploads/csd/';
    if (!is_dir($dir_csd)) @mkdir($dir_csd, 0755, true);

    if (isset($_FILES['csd_cer']) && $_FILES['csd_cer']['error'] === UPLOAD_ERR_OK) {
        move_uploaded_file($_FILES['csd_cer']['tmp_name'], $dir_csd.'csd_cer.cer');
        $csd_cer_actual = 'csd_cer.cer';
    }
    if (isset($_FILES['csd_key']) && $_FILES['csd_key']['error'] === UPLOAD_ERR_OK) {
        move_uploaded_file($_FILES['csd_key']['tmp_name'], $dir_csd.'csd_key.key');
        $csd_key_actual = 'csd_key.key';
    }

    $pdo->prepare("UPDATE config_empresa SET nombre=?,razon_social=?,regimen_fiscal=?,codigo_postal=?,serie_cfdi=?,logo=?,direccion=?,telefono=?,email=?,rfc=?,csd_cer=?,csd_key=?,csd_password=?,finkok_user=?,finkok_password=?,finkok_ambiente=? WHERE id=1")
        ->execute([$nombre,$razon_social?:null,$regimen_fiscal?:null,$codigo_postal?:null,$serie_cfdi?:null,$logo_actual?:null,$direccion?:null,$telefono?:null,$email?:null,$rfc?:null,$csd_cer_actual,$csd_key_actual,$csd_password?:null,$finkok_user?:null,$finkok_password?:null,$finkok_ambiente]);
    alert('success', 'Configuracion guardada');
    redirect('configuracion.php');
}

$dir_logo = __DIR__.'/uploads/empresa/';
$logo_url = $cfg['logo'] && file_exists($dir_logo.$cfg['logo']) ? 'uploads/empresa/'.$cfg['logo'] : '';
$tiene_csd = $cfg['csd_cer'] && $cfg['csd_key'] && file_exists(__DIR__.'/uploads/csd/'.$cfg['csd_cer']) && file_exists(__DIR__.'/uploads/csd/'.$cfg['csd_key']);

require 'includes/header.php';
?>
<div class="card" style="max-width:800px">
    <div class="card-header"><h2>Configuracion de la Empresa</h2></div>
    <form method="post" enctype="multipart/form-data">
        <h3 style="margin:12px 0 8px;">Datos generales</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Nombre de la empresa *</label>
                <input type="text" name="nombre" required value="<?=h($cfg['nombre'])?>">
            </div>
            <div class="form-group">
                <label>Razon social</label>
                <input type="text" name="razon_social" value="<?=h($cfg['razon_social'] ?? '')?>" placeholder="Como aparece en el CSD">
            </div>
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
                <label>Regimen fiscal (SAT)</label>
                <select name="regimen_fiscal">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ([
                        '601' => 'General de Ley Personas Morales',
                        '603' => 'Personas Morales con Fines no Lucrativos',
                        '606' => 'Plataformas Tecnologicas',
                        '608' => 'Demas Personas Morales y Fiscas',
                        '610' => 'Residentes en el Extranjero sin Establecimiento Permanente en Mexico',
                        '611' => 'Ingresos por Dividendos (socios y accionistas)',
                        '612' => 'Personas Fisicas con Actividades Empresariales y Profesionales',
                        '614' => 'Fiscas con Actividades no Empresariales',
                        '615' => 'Reginen de los Ingresos por Intereses',
                        '616' => 'Sin obligaciones fiscales',
                        '621' => 'Incorporacion Fiscal',
                        '625' => 'Reginen de las Actividades Empresariales con Ingresos a traves de Plataformas Tecnologicas',
                        '626' => 'Regimen Simplificado de Confianza',
                    ] as $clave => $desc): ?>
                    <option value="<?=$clave?>" <?=($cfg['regimen_fiscal'] ?? '') === $clave ? 'selected' : ''?>><?=$clave?> - <?=$desc?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Codigo postal (lugar de expedicion)</label>
                <input type="text" name="codigo_postal" maxlength="5" value="<?=h($cfg['codigo_postal'] ?? '')?>">
            </div>
            <div class="form-group">
                <label>Serie CFDI</label>
                <input type="text" name="serie_cfdi" maxlength="10" value="<?=h($cfg['serie_cfdi'] ?? 'F')?>">
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

        <h3 style="margin:16px 0 8px;">Certificado de Sello Digital (CSD)</h3>
        <?php if ($tiene_csd): ?>
        <div class="alert alert-success">CSD cargado: <?=h($cfg['csd_cer'])?> + <?=h($cfg['csd_key'])?></div>
        <?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label>Archivo .cer</label>
                <input type="file" name="csd_cer" accept=".cer,.pem" <?= $tiene_csd ? '' : 'required' ?>>
            </div>
            <div class="form-group">
                <label>Archivo .key</label>
                <input type="file" name="csd_key" accept=".key,.pem" <?= $tiene_csd ? '' : 'required' ?>>
            </div>
            <div class="form-group">
                <label>Contrasena de la llave</label>
                <input type="password" name="csd_password" value="<?=h($cfg['csd_password'] ?? '')?>" placeholder="Clave privada del CSD">
            </div>
        </div>

        <h3 style="margin:16px 0 8px;">PAC Finkok</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Usuario (RFC o email Finkok)</label>
                <input type="text" name="finkok_user" value="<?=h($cfg['finkok_user'] ?? '')?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label>Contrasena</label>
                <input type="password" name="finkok_password" value="<?=h($cfg['finkok_password'] ?? '')?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label>Ambiente</label>
                <select name="finkok_ambiente">
                    <option value="pruebas" <?=($cfg['finkok_ambiente'] ?? 'pruebas') === 'pruebas' ? 'selected' : ''?>>Pruebas (sandbox)</option>
                    <option value="produccion" <?=($cfg['finkok_ambiente'] ?? '') === 'produccion' ? 'selected' : ''?>>Produccion</option>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Guardar</button>
            <a href="dashboard.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<?php require 'includes/footer.php'; ?>
