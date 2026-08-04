<?php
$titulo = 'Ubicaciones';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$almacen_id = isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $codigo = trim($_POST['codigo']);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $tipo = $_POST['tipo'];
        $padre_id = !empty($_POST['padre_id']) ? (int)$_POST['padre_id'] : null;
        $a_id = (int)$_POST['almacen_id'];
        $activo = isset($_POST['activo']) ? 1 : 0;

        try { $pdo->exec("ALTER TABLE ubicaciones ADD COLUMN responsable_id INT DEFAULT NULL AFTER tipo"); } catch (Exception $e) {}
        $responsable_id = !empty($_POST['responsable_id']) ? (int)$_POST['responsable_id'] : null;

        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO ubicaciones (almacen_id,codigo,descripcion,tipo,responsable_id,padre_id,activo) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$a_id,$codigo,$descripcion,$tipo,$responsable_id,$padre_id,$activo]);
            alert('success', 'Ubicacion creada');
        } else {
            $stmt = $pdo->prepare("UPDATE ubicaciones SET codigo=?,descripcion=?,tipo=?,responsable_id=?,padre_id=?,activo=? WHERE id=?");
            $stmt->execute([$codigo,$descripcion,$tipo,$responsable_id,$padre_id,$activo,$id]);
            alert('success', 'Ubicacion actualizada');
        }
        redirect("ubicaciones.php?almacen_id=$a_id");
    } catch (PDOException $e) {
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

if ($action === 'delete' && $id) {
    $stmt = $pdo->prepare("SELECT almacen_id FROM ubicaciones WHERE id=?");
    $stmt->execute([$id]); $u = $stmt->fetch();
    $a_id = $u['almacen_id'];
    $pdo->prepare("DELETE FROM ubicaciones WHERE id=?")->execute([$id]);
    alert('success', 'Ubicacion eliminada');
    redirect("ubicaciones.php?almacen_id=$a_id");
}

$ubic = null;
if ($id && $action === 'edit') {
    $stmt = $pdo->prepare("SELECT * FROM ubicaciones WHERE id=?");
    $stmt->execute([$id]); $ubic = $stmt->fetch();
    if (!$ubic) { alert('danger', 'No encontrada'); redirect('ubicaciones.php'); }
    $almacen_id = $ubic['almacen_id'];
}

$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();

if (!$almacen_id && $almacenes) $almacen_id = $almacenes[0]['id'];

// Construir arbol jerarquico
$responsable_filtro = isset($_GET['responsable_id']) ? (int)$_GET['responsable_id'] : 0;
if ($responsable_filtro === -1) {
    $ubicaciones_raw = $pdo->prepare("SELECT u.*, p.codigo as padre_codigo, a.nombre as almacen_nombre FROM ubicaciones u LEFT JOIN ubicaciones p ON p.id = u.padre_id JOIN almacenes a ON a.id = u.almacen_id WHERE u.almacen_id = ? AND u.responsable_id IS NULL ORDER BY u.codigo");
    $ubicaciones_raw->execute([$almacen_id]);
} elseif ($responsable_filtro) {
    $ubicaciones_raw = $pdo->prepare("SELECT u.*, p.codigo as padre_codigo, a.nombre as almacen_nombre FROM ubicaciones u LEFT JOIN ubicaciones p ON p.id = u.padre_id JOIN almacenes a ON a.id = u.almacen_id WHERE u.almacen_id = ? AND u.responsable_id = ? ORDER BY u.codigo");
    $ubicaciones_raw->execute([$almacen_id, $responsable_filtro]);
} else {
    $ubicaciones_raw = $pdo->prepare("SELECT u.*, p.codigo as padre_codigo, a.nombre as almacen_nombre FROM ubicaciones u LEFT JOIN ubicaciones p ON p.id = u.padre_id JOIN almacenes a ON a.id = u.almacen_id WHERE u.almacen_id = ? ORDER BY u.codigo");
    $ubicaciones_raw->execute([$almacen_id]);
}
$ubicaciones_raw = $ubicaciones_raw->fetchAll();

function buildTree($items, $padre_id = null, $depth = 0) {
    $result = [];
    foreach ($items as $item) {
        if ($item['padre_id'] === $padre_id) {
            $item['depth'] = $depth;
            $children = buildTree($items, $item['id'], $depth + 1);
            if ($children) {
                $item['children'] = $children;
            } else {
                $item['children'] = [];
            }
            $result[] = $item;
        }
    }
    return $result;
}

function flattenTree($tree) {
    $result = [];
    foreach ($tree as $node) {
        $result[] = $node;
        if (!empty($node['children'])) {
            $result = array_merge($result, flattenTree($node['children']));
        }
    }
    return $result;
}

$ubicaciones_tree = buildTree($ubicaciones_raw);
$ubicaciones = flattenTree($ubicaciones_tree);

$ids_hijos = [];
$stack = $ubicaciones_tree;
while ($stack) {
    $node = array_shift($stack);
    if (!empty($node['children'])) {
        $ids_hijos[$node['id']] = true;
        $stack = array_merge($stack, $node['children']);
    }
}

$ubicaciones_op = $pdo->prepare("SELECT * FROM ubicaciones WHERE almacen_id=? AND activo=1 ORDER BY codigo");
$ubicaciones_op->execute([$almacen_id]);

require 'includes/header.php';

if ($action === 'create' || $action === 'edit'):
    $isEdit = $action === 'edit';
    $u = $ubic;
?>
<div class="card" style="max-width: 500px;">
    <div class="card-header"><h2><?=$isEdit?'Editar':'Nueva'?> Ubicacion</h2></div>
    <form method="post">
        <input type="hidden" name="almacen_id" value="<?=$isEdit ? $u['almacen_id'] : $almacen_id?>">
        <div class="form-group">
            <label>Almacen</label>
            <select name="almacen_id" <?=$isEdit?'disabled':''?>>
                <?php foreach ($almacenes as $a): ?>
                <option value="<?=$a['id']?>" <?=($isEdit ? $u['almacen_id'] : $almacen_id)==$a['id']?'selected':''?>><?=h($a['nombre'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Codigo *</label>
                <input type="text" name="codigo" required value="<?=h($isEdit ? $u['codigo'] : '')?>" placeholder="Ej: RACK-A-01">
            </div>
            <div class="form-group">
                <label>Tipo</label>
                <select name="tipo">
                    <?php foreach (['pasillo','rack','estante','nivel','contenedor','zona'] as $t): ?>
                    <option value="<?=$t?>" <?=($isEdit && $u['tipo']===$t)?'selected':''?>><?=ucfirst($t)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Descripcion</label>
            <input type="text" name="descripcion" value="<?=h($isEdit ? $u['descripcion'] : '')?>">
        </div>
        <div class="form-group">
            <label>Responsable</label>
            <select name="responsable_id">
                <option value="">-- Sin responsable --</option>
                <?php $emps = $pdo->query("SELECT id, nombre, apellido FROM empleados WHERE activo=1 ORDER BY nombre")->fetchAll(); foreach ($emps as $emp): ?>
                <option value="<?=$emp['id']?>" <?=($isEdit && $u['responsable_id']==$emp['id'])?'selected':''?>><?=h($emp['nombre'].' '.$emp['apellido'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Ubicacion padre</label>
            <select name="padre_id">
                <option value="">-- Raiz --</option>
                <?php foreach ($ubicaciones_op as $ub): ?>
                <option value="<?=$ub['id']?>" <?=($isEdit && $u['padre_id']==$ub['id'])?'selected':''?>><?=h($ub['codigo'])?> (<?=h($ub['tipo'])?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="activo" <?=checked($isEdit ? $u['activo'] : 1)?>> Activo</label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?=$isEdit?'Actualizar':'Crear'?></button>
            <a href="ubicaciones.php?almacen_id=<?=$isEdit ? $u['almacen_id'] : $almacen_id?>" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Ubicaciones por Almacen</h2>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <form method="get" style="display:inline">
                <input type="hidden" name="responsable_id" value="<?=$responsable_filtro?>">
                <select name="almacen_id" onchange="this.form.submit()">
                    <?php foreach ($almacenes as $a): ?>
                    <option value="<?=$a['id']?>" <?=$almacen_id==$a['id']?'selected':''?>><?=h($a['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <form method="get" style="display:inline">
                <input type="hidden" name="almacen_id" value="<?=$almacen_id?>">
                <select name="responsable_id" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <option value="-1" <?=$responsable_filtro==-1?'selected':''?>>Sin responsable</option>
                    <?php $emps = $pdo->query("SELECT id, nombre, apellido FROM empleados WHERE activo=1 ORDER BY nombre")->fetchAll(); foreach ($emps as $emp): ?>
                    <option value="<?=$emp['id']?>" <?=$responsable_filtro==$emp['id']?'selected':''?>><?=h($emp['nombre'].' '.$emp['apellido'])?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="ubicaciones.php?action=create&almacen_id=<?=$almacen_id?><?=$responsable_filtro?'&responsable_id='.$responsable_filtro:''?>" class="btn btn-primary">+ Nueva</a>
        </div>
    </div>
    <div class="table-wrapper">
        <table>
            <tr><th style="width:28px"></th><th>Codigo</th><th>Tipo</th><th>Responsable</th><th>Descripcion</th><th>Ruta completa</th><th>Activo</th><th>Acciones</th></tr>
            <?php $cont=0; $tipos_orden = ['zona','pasillo','rack','estante','nivel','contenedor']; foreach ($ubicaciones as $u): $cont++;
            $full_path = $u['codigo']; $p_id = $u['padre_id'];
            // Build full path
            $path_parts = [$u['codigo']];
            $t = $p_id;
            $max_loop = 20;
            while ($t && $max_loop--) {
                $pr = $pdo->prepare("SELECT id, codigo, padre_id FROM ubicaciones WHERE id=?");
                $pr->execute([$t]); $pr = $pr->fetch();
                if ($pr) { array_unshift($path_parts, $pr['codigo']); $t = $pr['padre_id']; } else break;
            }
            $ruta = implode(' > ', $path_parts);
            $has_children = isset($ids_hijos[$u['id']]);
            ?>
            <tr data-id="<?=$u['id']?>" data-parent-id="<?=$u['padre_id']??''?>">
                <td style="text-align:center;cursor:pointer;user-select:none;font-size:.85rem;"><?php if ($has_children): ?><span class="tt" data-id="<?=$u['id']?>" onclick="tt(this,<?=$u['id']?>)">-</span><?php endif; ?></td>
                <td style="padding-left:<?=12 + ($u['depth']??0)*20?>px;font-weight:<?=$u['depth']===0?'700':'400'?>;"><?php if($u['depth']>0):?><span style="color:#bbb;margin-right:4px;">&#9492;</span><?php endif; ?><?=h($u['codigo'])?></td>
                <td><span class="badge badge-info"><?=h($u['tipo'])?></span></td>
                <td style="font-size:.8rem;"><?php
                    if ($u['responsable_id']) {
                        $er = $pdo->prepare("SELECT nombre, apellido FROM empleados WHERE id=?");
                        $er->execute([$u['responsable_id']]); $en = $er->fetch();
                        echo $en ? h($en['nombre'].' '.$en['apellido']) : '<span style="color:#999">-</span>';
                    } else { echo '<span style="color:#999">-</span>'; }
                ?></td>
                <td style="font-size:.85rem;"><?=h($u['descripcion'])?></td>
                <td style="font-size:.75rem;color:#888;"><?=h($ruta)?></td>
                <td><span class="badge <?=$u['activo']?'badge-success':'badge-secondary'?>"><?=$u['activo']?'Si':'No'?></span></td>
                <td class="table-actions">
                    <a href="ubicaciones.php?action=edit&id=<?=$u['id']?>" class="btn btn-sm btn-warning">Editar</a>
                    <a href="ubicaciones.php?action=delete&id=<?=$u['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar?')">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<script>
function tt(el,id) {
    var close = el.textContent === '-';
    el.textContent = close ? '+' : '-';
    var kids = document.querySelectorAll('tr[data-parent-id="'+id+'"]');
    for (var i=0;i<kids.length;i++) {
        var r = kids[i];
        if (close) { r.style.display = 'none'; hD(r.getAttribute('data-id')); }
        else { r.style.display = ''; }
    }
}
function hD(id) {
    var kids = document.querySelectorAll('tr[data-parent-id="'+id+'"]');
    for (var i=0;i<kids.length;i++) {
        var r = kids[i];
        r.style.display = 'none';
        var ch = r.querySelector('.tt');
        if (ch) { ch.textContent = '+'; hD(r.getAttribute('data-id')); }
    }
}
</script>
<?php require 'includes/footer.php'; ?>
