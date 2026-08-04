<?php
$titulo = 'Transferencia entre Almacenes';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $folio = 'TRF-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $origen_id = (int)$_POST['origen_id'];
        $destino_id = (int)$_POST['destino_id'];
        $producto_id = (int)$_POST['producto_id'];
        $cantidad = str_replace(',','', $_POST['cantidad']);
        $observaciones = trim($_POST['observaciones'] ?? '');

        if ($origen_id === $destino_id) throw new Exception('El almacen origen y destino deben ser diferentes');

        // Verificar stock
        $inv = $pdo->prepare("SELECT id, cantidad FROM inventario WHERE almacen_id=? AND producto_id=?");
        $inv->execute([$origen_id, $producto_id]);
        $existente = $inv->fetch();

        if (!$existente || $existente['cantidad'] < $cantidad) {
            throw new Exception("Stock insuficiente en origen. Disponible: " . ($existente['cantidad'] ?? 0));
        }

        // Obtener tipo TRANSFERENCIA
        $tm = $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo='TRANSFERENCIA'")->fetch();

        // Registrar movimiento
        $stmt = $pdo->prepare("INSERT INTO movimientos (tipo_movimiento_id, folio, almacen_origen_id, almacen_destino_id, producto_id, cantidad, observaciones, usuario_id) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$tm['id'], $folio, $origen_id, $destino_id, $producto_id, $cantidad, $observaciones, $_SESSION['usuario_id']]);

        // Restar de origen
        $pdo->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE id=?")->execute([$cantidad, $existente['id']]);

        // Sumar a destino
        $inv_dest = $pdo->prepare("SELECT id FROM inventario WHERE almacen_id=? AND producto_id=?");
        $inv_dest->execute([$destino_id, $producto_id]);
        $dest_exist = $inv_dest->fetch();

        if ($dest_exist) {
            $pdo->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=?")->execute([$cantidad, $dest_exist['id']]);
        } else {
            $pdo->prepare("INSERT INTO inventario (almacen_id, producto_id, cantidad) VALUES (?,?,?)")->execute([$destino_id, $producto_id, $cantidad]);
        }

        $pdo->commit();
        alert('success', "Transferencia registrada. Folio: $folio");
        redirect('movimientos.php');

    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
$productos = $pdo->query("SELECT id, codigo, nombre FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll();

require 'includes/header.php';
?>
<div class="card" style="max-width: 600px;">
    <div class="card-header"><h2>🔄 Transferir entre Almacenes</h2></div>
    <form method="post" onsubmit="return confirm('¿Confirmar transferencia?')">
        <div class="form-row">
            <div class="form-group">
                <label>Origen *</label>
                <select name="origen_id" required>
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($almacenes as $a): ?>
                    <option value="<?=$a['id']?>"><?=h($a['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Destino *</label>
                <select name="destino_id" required>
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($almacenes as $a): ?>
                    <option value="<?=$a['id']?>"><?=h($a['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Producto *</label>
            <select name="producto_id" required>
                <option value="">-- Seleccionar --</option>
                <?php foreach ($productos as $p): ?>
                <option value="<?=$p['id']?>"><?=h($p['codigo'])?> - <?=h($p['nombre'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Cantidad *</label>
            <input type="number" step="0.01" name="cantidad" required>
        </div>
        <div class="form-group">
            <label>Observaciones</label>
            <textarea name="observaciones" rows="2" placeholder="Motivo de la transferencia"></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-warning">🔄 Transferir</button>
            <a href="movimientos.php" class="btn btn-secondary">Ver movimientos</a>
        </div>
    </form>
</div>
<?php require 'includes/footer.php'; ?>
