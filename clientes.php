<?php
$titulo = 'Clientes';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

require_once 'includes/cfdi_helper.php';
cfdiMigrar($pdo);

$pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) UNIQUE,
    nombre VARCHAR(255) NOT NULL,
    rfc VARCHAR(20),
    email VARCHAR(255),
    telefono VARCHAR(50),
    direccion TEXT,
    ciudad VARCHAR(100),
    estado VARCHAR(100),
    pais VARCHAR(100) DEFAULT 'Mexico',
    tipo ENUM('persona_fisica','persona_moral') DEFAULT 'persona_fisica',
    contacto_nombre VARCHAR(255),
    notas TEXT,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$count = $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
if ($count == 0) {
    $pdo->exec("INSERT INTO clientes (codigo,nombre,rfc,email,telefono,direccion,ciudad,estado,pais,tipo,contacto_nombre,notas,activo) VALUES
        ('CLI-001','Comercializadora del Norte SA de CV','COM190101XXX','ventas@comercialnorte.com','81-2345-6789','Av. Constitucion 500, Col. Centro','Monterrey','Nuevo Leon','Mexico','persona_moral','Luis Fernandez','Cliente mayorista principal',1),
        ('CLI-002','Distribuidora Peninsular S. de RL','DPN200102YYY','info@dppeninsular.mx','999-123-4567','Calle 60 250, Col. Garcia Gineres','Merida','Yucatan','Mexico','persona_moral','Ana Ku','Distribuye en la peninsula',1),
        ('CLI-003','Electronica Global SA','EGL210203ZZZ','compras@electronicaglobal.com','55-3456-7890','Av. Insurgentes Sur 1234, Col. Del Valle','Ciudad de Mexico','CDMX','Mexico','persona_moral','Carlos Mendez','Importa electronicos de Asia',1),
        ('CLI-004','Juan Carlos Hernandez Lopez','JUHL890101H23','jcarlos.hdz@gmail.com','33-1234-5678','Privada del Bosque 45, Col. Jardines','Guadalajara','Jalisco','Mexico','persona_fisica','','Cliente de tienda en linea',1),
        ('CLI-005','Textiles del Bajio SA de CV','TBA210505KKK','contacto@textilesbajio.com','442-987-6543','Blvd. Torres Landa 789','Queretaro','Queretaro','Mexico','persona_moral','Patricia Rojas','Compra telas para maquila',1),
        ('CLI-006','Fresh Foods Corp.','FFC220101LLL','purchasing@freshfoods.com','+1-305-555-0199','1200 NW 78th Ave, Suite 300','Miami','Florida','Estados Unidos','persona_moral','John Smith','Importa alimentos perecederos',1),
        ('CLI-007','Maria Elena Vargas Torres','MEVT920202M22','me.vargas@outlook.com','664-123-4567','Rio Papaloapan 2344, Col. Santa Fe','Tijuana','Baja California','Mexico','persona_fisica','','Cliente frecuente de exportacion',1),
        ('CLI-008','Tecnologia Aplicada SA','TEC190404NNN','info@tecnologiaaplicada.com','81-1234-5678','Av. Vasconcelos 1500 Ote.','San Pedro Garza Garcia','Nuevo Leon','Mexico','persona_moral','Roberto Elizondo','Soluciones industriales',1)");
}

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $codigo = trim($_POST['codigo'] ?? '');
        $nombre = trim($_POST['nombre']);
        $rfc = trim($_POST['rfc'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $ciudad = trim($_POST['ciudad'] ?? '');
        $estado = trim($_POST['estado'] ?? '');
        $pais = trim($_POST['pais'] ?? 'Mexico');
        $tipo = $_POST['tipo'] ?? 'persona_fisica';
        $contacto_nombre = trim($_POST['contacto_nombre'] ?? '');
        $notas = trim($_POST['notas'] ?? '');
        $uso_cfdi = trim($_POST['uso_cfdi'] ?? '');
        $regimen_fiscal_receptor = trim($_POST['regimen_fiscal_receptor'] ?? '');
        $codigo_postal = trim($_POST['codigo_postal'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO clientes (codigo,nombre,rfc,email,telefono,direccion,ciudad,estado,pais,tipo,contacto_nombre,notas,uso_cfdi,regimen_fiscal_receptor,codigo_postal,activo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$codigo?:null,$nombre,$rfc?:null,$email?:null,$telefono?:null,$direccion?:null,$ciudad?:null,$estado?:null,$pais,$tipo,$contacto_nombre?:null,$notas?:null,$uso_cfdi?:null,$regimen_fiscal_receptor?:null,$codigo_postal?:null,$activo]);
            alert('success', 'Cliente creado');
        } else {
            $stmt = $pdo->prepare("UPDATE clientes SET codigo=?,nombre=?,rfc=?,email=?,telefono=?,direccion=?,ciudad=?,estado=?,pais=?,tipo=?,contacto_nombre=?,notas=?,uso_cfdi=?,regimen_fiscal_receptor=?,codigo_postal=?,activo=? WHERE id=?");
            $stmt->execute([$codigo?:null,$nombre,$rfc?:null,$email?:null,$telefono?:null,$direccion?:null,$ciudad?:null,$estado?:null,$pais,$tipo,$contacto_nombre?:null,$notas?:null,$uso_cfdi?:null,$regimen_fiscal_receptor?:null,$codigo_postal?:null,$activo,$id]);
            alert('success', 'Cliente actualizado');
        }
        redirect('clientes.php');
    } catch (PDOException $e) {
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM clientes WHERE id=?")->execute([$id]);
    alert('success', 'Cliente eliminado');
    redirect('clientes.php');
}

$cli = null;
if ($id && ($action === 'edit' || $action === 'delete')) {
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id=?");
    $stmt->execute([$id]); $cli = $stmt->fetch();
    if (!$cli) { alert('danger', 'No encontrado'); redirect('clientes.php'); }
}

$search = $_GET['search'] ?? '';
$sql = "SELECT * FROM clientes WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND (nombre LIKE ? OR rfc LIKE ? OR email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY nombre";
$clientes = $pdo->prepare($sql);
$clientes->execute($params);

require 'includes/header.php';

if ($action === 'create' || $action === 'edit'):
    $isEdit = $action === 'edit';
    $c = $cli;
?>
<div class="card" style="max-width: 700px;">
    <div class="card-header"><h2><?=$isEdit?'Editar':'Nuevo'?> Cliente</h2></div>
    <form method="post">
        <div class="form-row">
            <div class="form-group">
                <label>Codigo</label>
                <input type="text" name="codigo" value="<?=h($isEdit ? $c['codigo'] : '')?>">
            </div>
            <div class="form-group">
                <label>Tipo</label>
                <select name="tipo">
                    <option value="persona_fisica"<?=$isEdit && $c['tipo']==='persona_fisica'?' selected':''?>>Persona Fisica</option>
                    <option value="persona_moral"<?=$isEdit && $c['tipo']==='persona_moral'?' selected':''?>>Persona Moral</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Nombre / Razon social *</label>
            <input type="text" name="nombre" required value="<?=h($isEdit ? $c['nombre'] : '')?>">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>RFC</label>
                <input type="text" name="rfc" value="<?=h($isEdit ? $c['rfc'] : '')?>">
            </div>
            <div class="form-group">
                <label>Contacto</label>
                <input type="text" name="contacto_nombre" value="<?=h($isEdit ? $c['contacto_nombre'] : '')?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Uso CFDI</label>
                <select name="uso_cfdi">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ([
                        'G01' => 'G01 - Adquisicion de mercancias',
                        'G02' => 'G02 - Devoluciones, descuentos y bonificaciones',
                        'G03' => 'G03 - Gastos en general',
                        'I01' => 'I01 - Construcciones',
                        'I02' => 'I02 - Mobilario y equipo de oficina',
                        'I03' => 'I03 - Equipo de transporte',
                        'I04' => 'I04 - Equipo de computo y accesorios',
                        'I05' => 'I05 - Dados, troqueles, moldes, matrices y herramental',
                        'I06' => 'I06 - Comunicaciones telefonicas',
                        'I07' => 'I07 - Comunicaciones satelitales',
                        'I08' => 'I08 - Otra maquinaria y equipo',
                        'D01' => 'D01 - Honorarios medicos, dentales y gastos hospitalarios',
                        'P01' => 'P01 - Por definir',
                    ] as $clave => $desc): ?>
                    <option value="<?=$clave?>" <?=($isEdit && $c['uso_cfdi'] ?? '') === $clave ? 'selected' : ''?>><?=$desc?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Regimen fiscal (receptor)</label>
                <select name="regimen_fiscal_receptor">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ([
                        '601' => '601 - General de Ley Personas Morales',
                        '603' => '603 - Personas Morales con Fines no Lucrativos',
                        '606' => '606 - Plataformas Tecnologicas',
                        '608' => '608 - Demas Personas Morales y Fiscas',
                        '610' => '610 - Residentes en el Extranjero sin Establecimiento Permanente en Mexico',
                        '611' => '611 - Ingresos por Dividendos (socios y accionistas)',
                        '612' => '612 - Personas Fisicas con Actividades Empresariales y Profesionales',
                        '614' => '614 - Fiscas con Actividades no Empresariales',
                        '616' => '616 - Sin obligaciones fiscales',
                        '621' => '621 - Incorporacion Fiscal',
                        '625' => '625 - Regimen de las Actividades Empresariales con Ingresos a traves de Plataformas Tecnologicas',
                        '626' => '626 - Regimen Simplificado de Confianza',
                    ] as $clave => $desc): ?>
                    <option value="<?=$clave?>" <?=($isEdit && $c['regimen_fiscal_receptor'] ?? '') === $clave ? 'selected' : ''?>><?=$desc?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Codigo postal fiscal</label>
            <input type="text" name="codigo_postal" maxlength="5" value="<?=h($isEdit ? $c['codigo_postal'] : '')?>">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?=h($isEdit ? $c['email'] : '')?>">
            </div>
            <div class="form-group">
                <label>Telefono</label>
                <input type="text" name="telefono" value="<?=h($isEdit ? $c['telefono'] : '')?>">
            </div>
        </div>
        <div class="form-group">
            <label>Direccion</label>
            <textarea name="direccion" rows="2"><?=h($isEdit ? $c['direccion'] : '')?></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Ciudad</label>
                <input type="text" name="ciudad" value="<?=h($isEdit ? $c['ciudad'] : '')?>">
            </div>
            <div class="form-group">
                <label>Estado</label>
                <input type="text" name="estado" value="<?=h($isEdit ? $c['estado'] : '')?>">
            </div>
            <div class="form-group">
                <label>Pais</label>
                <input type="text" name="pais" value="<?=h($isEdit ? $c['pais'] : 'Mexico')?>">
            </div>
        </div>
        <div class="form-group">
            <label>Notas</label>
            <textarea name="notas" rows="2"><?=h($isEdit ? $c['notas'] : '')?></textarea>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="activo" <?=checked($isEdit ? $c['activo'] : 1)?>> Activo</label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?=$isEdit?'Actualizar':'Crear'?></button>
            <a href="clientes.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Clientes</h2>
        <div class="table-actions">
            <button type="button" class="btn btn-success btn-sm" onclick="exportarTablaExcel('#tabla-listado','clientes')"> Exportar Excel</button>
            <button type="button" class="btn btn-info btn-sm" onclick="imprimirTablaPDF('#tabla-listado','Clientes')"> Imprimir PDF</button>
            <a href="clientes.php?action=create" class="btn btn-primary btn-sm">+ Nuevo</a>
        </div>
    </div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Buscar nombre, RFC o email..." value="<?=h($search)?>">
        <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
    </form>
    <div class="table-wrapper">
        <table id="tabla-listado">
            <tr><th>Codigo</th><th>Nombre</th><th>Tipo</th><th>RFC</th><th>Contacto</th><th>Email</th><th>Telefono</th><th>Pais</th><th>Activo</th><th>Acciones</th></tr>
            <?php foreach ($clientes as $c): ?>
            <tr>
                <td><?=h($c['codigo'] ?? '-')?></td>
                <td><?=h($c['nombre'])?></td>
                <td><?=$c['tipo']==='persona_moral'?'Moral':'Fisica'?></td>
                <td><?=h($c['rfc'] ?? '-')?></td>
                <td><?=h($c['contacto_nombre'] ?? '-')?></td>
                <td><?=h($c['email'] ?? '-')?></td>
                <td><?=h($c['telefono'] ?? '-')?></td>
                <td><?=h($c['pais'])?></td>
                <td><span class="badge <?=$c['activo']?'badge-success':'badge-secondary'?>"><?=$c['activo']?'Si':'No'?></span></td>
                <td class="table-actions">
                    <a href="clientes.php?action=edit&id=<?=$c['id']?>" class="btn btn-sm btn-warning">Editar</a>
                    <a href="clientes.php?action=delete&id=<?=$c['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar?')">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
