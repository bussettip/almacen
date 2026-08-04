<?php
$titulo = 'Productos';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

require_once 'includes/cfdi_helper.php';
cfdiMigrar($pdo);

try { $pdo->exec("ALTER TABLE productos ADD COLUMN imagen VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$dir_img = 'uploads/productos/';
if (!is_dir($dir_img)) @mkdir($dir_img, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $codigo = trim($_POST['codigo']);
        $nombre = trim($_POST['nombre']);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $categoria_id = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
        $unidad_medida_id = (int)$_POST['unidad_medida_id'];
        $precio_compra = str_replace(',','', $_POST['precio_compra'] ?? 0);
        $precio_venta = str_replace(',','', $_POST['precio_venta'] ?? 0);
        $stock_minimo = str_replace(',','', $_POST['stock_minimo'] ?? 0);
        $stock_maximo = str_replace(',','', $_POST['stock_maximo'] ?? 0);
        $codigo_qr = trim($_POST['codigo_qr'] ?? '');
        $clave_prod_serv = trim($_POST['clave_prod_serv'] ?? '01010101');
        $clave_unidad = trim($_POST['clave_unidad'] ?? 'H87');
        $objeto_impuesto = trim($_POST['objeto_impuesto'] ?? '02');
        $activo = isset($_POST['activo']) ? 1 : 0;
        $imagen = $_POST['imagen_actual'] ?? '';

        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
                $nom = uniqid('prod_').'.'.$ext;
                move_uploaded_file($_FILES['imagen']['tmp_name'], $dir_img.$nom);
                if ($imagen && $imagen !== $nom && file_exists($dir_img.$imagen)) @unlink($dir_img.$imagen);
                $imagen = $nom;
            }
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO productos (codigo,nombre,descripcion,categoria_id,unidad_medida_id,precio_compra,precio_venta,stock_minimo,stock_maximo,codigo_qr,clave_prod_serv,clave_unidad,objeto_impuesto,activo,imagen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$codigo,$nombre,$descripcion,$categoria_id,$unidad_medida_id,$precio_compra,$precio_venta,$stock_minimo,$stock_maximo,$codigo_qr?:null,$clave_prod_serv?:null,$clave_unidad?:null,$objeto_impuesto?:null,$activo,$imagen?:null]);
            alert('success', 'Producto creado');
        } else {
            $stmt = $pdo->prepare("UPDATE productos SET codigo=?,nombre=?,descripcion=?,categoria_id=?,unidad_medida_id=?,precio_compra=?,precio_venta=?,stock_minimo=?,stock_maximo=?,codigo_qr=?,clave_prod_serv=?,clave_unidad=?,objeto_impuesto=?,activo=?,imagen=? WHERE id=?");
            $stmt->execute([$codigo,$nombre,$descripcion,$categoria_id,$unidad_medida_id,$precio_compra,$precio_venta,$stock_minimo,$stock_maximo,$codigo_qr?:null,$clave_prod_serv?:null,$clave_unidad?:null,$objeto_impuesto?:null,$activo,$imagen?:null,$id]);
            alert('success', 'Producto actualizado');
        }
        redirect('productos.php');
    } catch (PDOException $e) {
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM productos WHERE id=?")->execute([$id]);
    alert('success', 'Producto eliminado');
    redirect('productos.php');
}

$producto = null;
if ($id && ($action === 'edit' || $action === 'delete')) {
    $stmt = $pdo->prepare("SELECT * FROM productos WHERE id=?");
    $stmt->execute([$id]); $producto = $stmt->fetch();
    if (!$producto) { alert('danger', 'No encontrado'); redirect('productos.php'); }
}

$search = $_GET['search'] ?? '';
$cat_filtro = $_GET['categoria_id'] ?? '';
$sql = "SELECT p.*, c.nombre as categoria, u.codigo as umedida, COALESCE(p.codigo_qr, p.codigo) as qr_data FROM productos p LEFT JOIN categorias c ON c.id=p.categoria_id JOIN unidades_medida u ON u.id=p.unidad_medida_id WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND (p.nombre LIKE ? OR p.codigo LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($cat_filtro) { $sql .= " AND p.categoria_id=?"; $params[] = (int)$cat_filtro; }
$sql .= " ORDER BY p.nombre";
$productos = $pdo->prepare($sql);
$productos->execute($params);

// Ubicaciones de cada producto
$ubicaciones_por_prod = [];
$ids_prod = $pdo->query("SELECT GROUP_CONCAT(id) FROM productos WHERE 1=1".($search?" AND (nombre LIKE '%".$pdo->quote($search)."%' OR codigo LIKE '%".$pdo->quote($search)."%')":'').($cat_filtro?" AND categoria_id=".(int)$cat_filtro:''))->fetchColumn();
if ($ids_prod) {
    $st = $pdo->query("SELECT i.producto_id, a.nombre as almacen, u.codigo as ubicacion, SUM(i.cantidad) as cant FROM inventario i JOIN almacenes a ON a.id=i.almacen_id LEFT JOIN ubicaciones u ON u.id=i.ubicacion_id WHERE i.producto_id IN ($ids_prod) AND i.cantidad>0 GROUP BY i.producto_id, a.nombre, u.codigo ORDER BY a.nombre, u.codigo");
    foreach ($st as $r) { $ubicaciones_por_prod[$r['producto_id']][] = $r; }
}

$categorias = $pdo->query("SELECT * FROM categorias WHERE activo=1 ORDER BY nombre")->fetchAll();
$unidades = $pdo->query("SELECT * FROM unidades_medida ORDER BY nombre")->fetchAll();

require 'includes/header.php';

if ($action === 'create' || $action === 'edit'):
    $isEdit = $action === 'edit';
    $p = $producto;
?>
<div class="card" style="max-width: 700px;">
    <div class="card-header"><h2><?=$isEdit?'Editar':'Nuevo'?> Producto</h2></div>
    <form method="post" enctype="multipart/form-data">
        <div class="form-row">
            <div class="form-group">
                <label>Codigo / SKU *</label>
                <input type="text" name="codigo" id="codigo_sku" required value="<?=h($isEdit ? $p['codigo'] : '')?>" oninput="actualizarQR()">
            </div>
            <div class="form-group">
                <label>Dato para QR</label>
                <div style="display:flex;gap:6px;">
                    <input type="text" name="codigo_qr" id="codigo_qr" value="<?=h($isEdit ? $p['codigo_qr'] : '')?>" oninput="actualizarQR()" placeholder="vacio = SKU" style="flex:1;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('codigo_qr').value='';actualizarQR();document.getElementById('codigo_qr').value=document.getElementById('codigo_sku').value;actualizarQR()" title="Usar SKU">🔄</button>
                </div>
            </div>
        </div>
        <div style="text-align:center;margin-bottom:16px;position:relative;">
            <div style="display:inline-block;position:relative;">
                <img id="qr_preview" src="qr.php?data=<?=urlencode($isEdit ? ($p['codigo_qr']?:$p['codigo']) : '')?>&size=6" alt="QR" style="width:120px;height:120px;border-radius:8px;border:2px solid #dce1e8;padding:6px;">
                <a id="qr_download" href="qr.php?data=<?=urlencode($isEdit ? ($p['codigo_qr']?:$p['codigo']) : '')?>&size=10" download="qr-<?=$isEdit?$p['codigo']:'producto'?>.png" class="btn btn-sm btn-primary" style="position:absolute;bottom:-12px;right:-12px;border-radius:20px;padding:4px 10px;font-size:.75rem;">⬇ QR</a>
            </div>
            <div style="font-size:.75rem;color:#999;margin-top:12px;">El QR se genera automaticamente al escribir el SKU</div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Unidad de medida *</label>
                <select name="unidad_medida_id" required>
                    <?php foreach ($unidades as $u): ?>
                    <option value="<?=$u['id']?>" <?=($isEdit && $p['unidad_medida_id']==$u['id'])?'selected':''?>><?=h($u['codigo'])?> - <?=h($u['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Clave unidad SAT (c_ClaveUnidad)</label>
                <input type="text" name="clave_unidad" maxlength="3" value="<?=h($isEdit ? $p['clave_unidad'] : 'H87')?>" placeholder="H87">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Clave producto SAT (c_ClaveProdServ)</label>
                <input type="text" name="clave_prod_serv" maxlength="8" value="<?=h($isEdit ? $p['clave_prod_serv'] : '01010101')?>" placeholder="01010101">
            </div>
            <div class="form-group">
                <label>Objeto impuesto</label>
                <select name="objeto_impuesto">
                    <option value="02" <?=($isEdit && $p['objeto_impuesto'] ?? '') === '02' ? 'selected' : ''?>>02 - Si objeto de impuesto</option>
                    <option value="01" <?=($isEdit && $p['objeto_impuesto'] ?? '') === '01' ? 'selected' : ''?>>01 - No objeto de impuesto</option>
                    <option value="03" <?=($isEdit && $p['objeto_impuesto'] ?? '') === '03' ? 'selected' : ''?>>03 - Si objeto de impuesto y no obligado al desglose</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Nombre *</label>
            <input type="text" name="nombre" required value="<?=h($isEdit ? $p['nombre'] : '')?>">
        </div>
        <div class="form-group">
            <label>Descripcion</label>
            <textarea name="descripcion" rows="2"><?=h($isEdit ? $p['descripcion'] : '')?></textarea>
        </div>
        <div class="form-group">
            <label>Imagen</label>
            <input type="hidden" name="imagen_actual" value="<?=h($isEdit ? $p['imagen'] : '')?>">
            <input type="file" name="imagen" accept="image/jpeg,image/png,image/webp,image/gif">
            <?php if ($isEdit && $p['imagen']): ?>
            <div style="margin-top:8px"><img src="<?=$dir_img.h($p['imagen'])?>" alt="Vista previa" style="max-width:150px;max-height:150px;border-radius:6px;border:1px solid #ddd;"></div>
            <?php endif; ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Categoria</label>
                <select name="categoria_id">
                    <option value="">-- Sin categoria --</option>
                    <?php foreach ($categorias as $c): ?>
                    <option value="<?=$c['id']?>" <?=($isEdit && $p['categoria_id']==$c['id'])?'selected':''?>><?=h($c['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <label><input type="checkbox" name="activo" <?=checked($isEdit ? $p['activo'] : 1)?>> Activo</label>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Precio compra</label>
                <input type="number" step="0.01" name="precio_compra" value="<?=h($isEdit ? $p['precio_compra'] : '0')?>">
            </div>
            <div class="form-group">
                <label>Precio venta</label>
                <input type="number" step="0.01" name="precio_venta" value="<?=h($isEdit ? $p['precio_venta'] : '0')?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Stock minimo</label>
                <input type="number" step="0.01" name="stock_minimo" value="<?=h($isEdit ? $p['stock_minimo'] : '0')?>">
            </div>
            <div class="form-group">
                <label>Stock maximo</label>
                <input type="number" step="0.01" name="stock_maximo" value="<?=h($isEdit ? $p['stock_maximo'] : '0')?>">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?=$isEdit?'Actualizar':'Crear'?></button>
            <a href="productos.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
    <?php if ($isEdit):
    $loc_prod = $pdo->prepare("SELECT i.cantidad, i.lote, i.fecha_caducidad, a.nombre as almacen, u.codigo as ubicacion FROM inventario i JOIN almacenes a ON a.id=i.almacen_id LEFT JOIN ubicaciones u ON u.id=i.ubicacion_id WHERE i.producto_id=? AND i.cantidad>0 ORDER BY a.nombre, u.codigo");
    $loc_prod->execute([$id]); $loc_prod = $loc_prod->fetchAll();
    if (!empty($loc_prod)): ?>
    <div class="card" style="margin-top:16px;">
        <div class="card-header"><h3>Ubicaciones del producto</h3></div>
        <div class="table-wrapper">
            <table>
                <tr><th>Almacen</th><th>Pasillo/Estante</th><th>Cantidad</th><th>Lote</th><th>Caducidad</th></tr>
                <?php foreach ($loc_prod as $lp): ?>
                <tr><td><?=h($lp['almacen'])?></td><td><?=h($lp['ubicacion'] ?? 'Sin ubicacion')?></td><td><?=number_format($lp['cantidad'])?></td><td><?=h($lp['lote'] ?: '-')?></td><td><?=$lp['fecha_caducidad'] ?? '-'?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="form-actions" style="margin-top:8px;">
            <a href="inventario.php?producto_id=<?=$id?>" class="btn btn-sm btn-info">Ver inventario completo</a>
            <a href="asignar.php?producto_id=<?=$id?>" class="btn btn-sm btn-success">Asignar a ubicacion</a>
        </div>
    </div>
    <?php endif; endif; ?>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Productos</h2>
        <a href="productos.php?action=create" class="btn btn-primary">+ Nuevo</a>
    </div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Buscar por nombre o codigo..." value="<?=h($search)?>">
        <select name="categoria_id">
            <option value="">Todas las categorias</option>
            <?php foreach ($categorias as $c): ?>
            <option value="<?=$c['id']?>" <?=$cat_filtro==$c['id']?'selected':''?>><?=h($c['nombre'])?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
    </form>
    <div class="table-wrapper">
        <table>
            <tr><th>Imagen</th><th>Codigo</th><th>Nombre</th><th>Categoria</th><th>UM</th><th>Compra</th><th>Venta</th><th>Stock min</th><th>Ubicacion</th><th>QR</th><th>Activo</th><th>Acciones</th></tr>
            <?php foreach ($productos as $p): ?>
            <tr>
                <td><?php if ($p['imagen'] && file_exists($dir_img.$p['imagen'])): ?><img src="<?=$dir_img.h($p['imagen'])?>" alt="img" style="width:48px;height:48px;object-fit:cover;border-radius:4px;"><?php else: ?><img src="placeholder_img.php?codigo=<?=urlencode($p['codigo'])?>&size=48" alt="img" style="width:48px;height:48px;object-fit:cover;border-radius:4px;"><?php endif; ?></td>
                <td><?=h($p['codigo'])?></td>
                <td><?=h($p['nombre'])?></td>
                <td><?=h($p['categoria'] ?? '-')?></td>
                <td><?=h($p['umedida'])?></td>
                <td><?=moneda($p['precio_compra'])?></td>
                <td><?=moneda($p['precio_venta'])?></td>
                <td><?=h($p['stock_minimo'])?></td>
                <td style="font-size:.75rem;max-width:200px;"><?php $ubs = $ubicaciones_por_prod[$p['id']] ?? []; if (empty($ubs)): ?><span style="color:#ccc">-</span><?php else: foreach ($ubs as $i=>$ub): if($i>0): ?><br><?php endif; ?><strong><?=h($ub['almacen'])?></strong>: <?=h($ub['ubicacion']??'--')?> (<?=number_format($ub['cant'])?>)<?php endforeach; endif; ?></td>
                <td>
                    <a href="qr.php?data=<?=urlencode($p['qr_data'])?>" target="_blank">
                        <img src="qr.php?data=<?=urlencode($p['qr_data'])?>&size=3" alt="QR" style="width:40px;height:40px;border-radius:4px;border:1px solid #dce1e8;">
                    </a>
                </td>
                <td><span class="badge <?=$p['activo']?'badge-success':'badge-secondary'?>"><?=$p['activo']?'Si':'No'?></span></td>
                <td class="table-actions">
                    <a href="inventario.php?producto_id=<?=$p['id']?>" class="btn btn-sm btn-info">Stock</a>
                    <a href="productos.php?action=edit&id=<?=$p['id']?>" class="btn btn-sm btn-warning">Editar</a>
                    <a href="productos.php?action=delete&id=<?=$p['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar?')">Eliminar</a>
                    <a href="qr.php?data=<?=urlencode($p['qr_data'])?>&size=8" download="qr-<?=$p['codigo']?>.png" class="btn btn-sm btn-secondary">QR</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<script>
function actualizarQR() {
    const sku = document.getElementById('codigo_sku').value;
    const qr = document.getElementById('codigo_qr').value || sku;
    document.getElementById('qr_preview').src = 'qr.php?data=' + encodeURIComponent(qr) + '&size=6';
    const dl = document.getElementById('qr_download');
    dl.href = 'qr.php?data=' + encodeURIComponent(qr) + '&size=10';
    dl.download = 'qr-' + sku + '.png';
}
</script>
<?php require 'includes/footer.php'; ?>
