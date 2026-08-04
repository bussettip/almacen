<?php
$titulo = 'Bancos';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$pdo->exec("CREATE TABLE IF NOT EXISTS cuentas_bancarias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    banco VARCHAR(100) NOT NULL,
    titular VARCHAR(255) NOT NULL,
    numero_cuenta VARCHAR(50),
    clabe VARCHAR(50),
    tipo_cuenta ENUM('cheques','ahorro','inversion','efectivo') DEFAULT 'cheques',
    saldo DECIMAL(14,2) DEFAULT 0,
    moneda VARCHAR(10) DEFAULT 'MXN',
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS movimientos_bancarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cuenta_id INT NOT NULL,
    tipo ENUM('deposito','retiro','transferencia_salida','transferencia_entrada') NOT NULL,
    concepto VARCHAR(255),
    monto DECIMAL(14,2) NOT NULL,
    saldo_anterior DECIMAL(14,2) NOT NULL,
    saldo_nuevo DECIMAL(14,2) NOT NULL,
    referencia VARCHAR(100),
    cuenta_destino_id INT,
    fecha_movimiento DATE DEFAULT (CURRENT_DATE),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cuenta_id) REFERENCES cuentas_bancarias(id) ON DELETE CASCADE,
    FOREIGN KEY (cuenta_destino_id) REFERENCES cuentas_bancarias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_cuenta') {
    $banco = trim($_POST['banco']);
    $titular = trim($_POST['titular']);
    $numero_cuenta = trim($_POST['numero_cuenta'] ?? '');
    $clabe = trim($_POST['clabe'] ?? '');
    $tipo_cuenta = $_POST['tipo_cuenta'] ?? 'cheques';
    $saldo = (float)str_replace(',','', $_POST['saldo'] ?? 0);
    $moneda = trim($_POST['moneda'] ?? 'MXN');
    $activo = isset($_POST['activo']) ? 1 : 0;
    $pdo->prepare("INSERT INTO cuentas_bancarias (banco,titular,numero_cuenta,clabe,tipo_cuenta,saldo,moneda,activo) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$banco,$titular,$numero_cuenta?:null,$clabe?:null,$tipo_cuenta,$saldo,$moneda,$activo]);
    if ($saldo > 0) {
        $cid = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO movimientos_bancarios (cuenta_id,tipo,concepto,monto,saldo_anterior,saldo_nuevo,fecha_movimiento) VALUES (?,'deposito','Saldo inicial',?,0,?,CURDATE())")
            ->execute([$cid,$saldo,$saldo]);
    }
    alert('success', 'Cuenta creada');
    redirect('bancos.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_cuenta' && $id) {
    $banco = trim($_POST['banco']);
    $titular = trim($_POST['titular']);
    $numero_cuenta = trim($_POST['numero_cuenta'] ?? '');
    $clabe = trim($_POST['clabe'] ?? '');
    $tipo_cuenta = $_POST['tipo_cuenta'] ?? 'cheques';
    $moneda = trim($_POST['moneda'] ?? 'MXN');
    $activo = isset($_POST['activo']) ? 1 : 0;
    $pdo->prepare("UPDATE cuentas_bancarias SET banco=?,titular=?,numero_cuenta=?,clabe=?,tipo_cuenta=?,moneda=?,activo=? WHERE id=?")
        ->execute([$banco,$titular,$numero_cuenta?:null,$clabe?:null,$tipo_cuenta,$moneda,$activo,$id]);
    alert('success', 'Cuenta actualizada');
    redirect('bancos.php');
}

if ($action === 'delete_cuenta' && $id) {
    $pdo->prepare("DELETE FROM cuentas_bancarias WHERE id=?")->execute([$id]);
    alert('success', 'Cuenta eliminada');
    redirect('bancos.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'movimiento' && $id) {
    try {
        $pdo->beginTransaction();
        $cuenta = $pdo->prepare("SELECT * FROM cuentas_bancarias WHERE id=?");
        $cuenta->execute([$id]); $cuenta = $cuenta->fetch();
        if (!$cuenta) throw new Exception("Cuenta no encontrada");

        $tipo = $_POST['tipo'];
        $monto = (float)str_replace(',','', $_POST['monto']);
        $concepto = trim($_POST['concepto'] ?? '');
        $referencia = trim($_POST['referencia'] ?? '');
        $fecha = $_POST['fecha_movimiento'] ?? date('Y-m-d');

        if ($monto <= 0) throw new Exception("Monto debe ser mayor a 0");

        $saldo_anterior = (float)$cuenta['saldo'];

        if ($tipo === 'deposito') {
            $saldo_nuevo = $saldo_anterior + $monto;
            $pdo->prepare("INSERT INTO movimientos_bancarios (cuenta_id,tipo,concepto,monto,saldo_anterior,saldo_nuevo,referencia,fecha_movimiento) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$id,$tipo,$concepto,$monto,$saldo_anterior,$saldo_nuevo,$referencia?:null,$fecha]);
        } elseif ($tipo === 'retiro') {
            if ($monto > $saldo_anterior) throw new Exception("Saldo insuficiente: disponible $$saldo_anterior");
            $saldo_nuevo = $saldo_anterior - $monto;
            $pdo->prepare("INSERT INTO movimientos_bancarios (cuenta_id,tipo,concepto,monto,saldo_anterior,saldo_nuevo,referencia,fecha_movimiento) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$id,$tipo,$concepto,$monto,$saldo_anterior,$saldo_nuevo,$referencia?:null,$fecha]);
        } elseif ($tipo === 'transferencia') {
            $destino_id = (int)$_POST['cuenta_destino_id'];
            if ($destino_id === $id) throw new Exception("No puedes transferir a la misma cuenta");
            if ($monto > $saldo_anterior) throw new Exception("Saldo insuficiente: disponible $$saldo_anterior");
            $destino = $pdo->prepare("SELECT * FROM cuentas_bancarias WHERE id=?");
            $destino->execute([$destino_id]); $destino = $destino->fetch();
            if (!$destino) throw new Exception("Cuenta destino no encontrada");

            $saldo_nuevo = $saldo_anterior - $monto;
            $saldo_destino_ant = (float)$destino['saldo'];
            $saldo_destino_new = $saldo_destino_ant + $monto;

            $pdo->prepare("INSERT INTO movimientos_bancarios (cuenta_id,tipo,concepto,monto,saldo_anterior,saldo_nuevo,referencia,cuenta_destino_id,fecha_movimiento) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$id,'transferencia_salida',$concepto?:'Transferencia enviada',$monto,$saldo_anterior,$saldo_nuevo,$referencia?:null,$destino_id,$fecha]);
            $pdo->prepare("INSERT INTO movimientos_bancarios (cuenta_id,tipo,concepto,monto,saldo_anterior,saldo_nuevo,referencia,cuenta_destino_id,fecha_movimiento) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$destino_id,'transferencia_entrada',$concepto?:'Transferencia recibida',$monto,$saldo_destino_ant,$saldo_destino_new,$referencia?:null,$id,$fecha]);
            $pdo->prepare("UPDATE cuentas_bancarias SET saldo=? WHERE id=?")->execute([$saldo_destino_new,$destino_id]);
        } else {
            throw new Exception("Tipo invalido");
        }

        $pdo->prepare("UPDATE cuentas_bancarias SET saldo=? WHERE id=?")->execute([$saldo_nuevo,$id]);
        $pdo->commit();
        alert('success', 'Movimiento registrado');
        redirect("bancos.php?action=detalle&id=$id");
    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger', 'Error: '.$e->getMessage());
    }
}

$cuentas = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM movimientos_bancarios WHERE cuenta_id=c.id) as num_movs FROM cuentas_bancarias c ORDER BY c.banco, c.titular")->fetchAll();
$total_saldos = array_sum(array_column($cuentas, 'saldo'));
$cuentas_activas = $pdo->query("SELECT id, banco, titular, moneda FROM cuentas_bancarias WHERE activo=1 ORDER BY banco")->fetchAll();

require 'includes/header.php';
?>

<?php if ($action === 'create_cuenta'): ?>
<div class="card" style="max-width:600px">
    <div class="card-header"><h2>Nueva Cuenta Bancaria</h2></div>
    <form method="post">
        <div class="form-row">
            <div class="form-group">
                <label>Banco *</label>
                <input type="text" name="banco" required placeholder="Ej: BBVA, Santander, HSBC">
            </div>
            <div class="form-group">
                <label>Tipo de cuenta</label>
                <select name="tipo_cuenta">
                    <option value="cheques">Cheques</option>
                    <option value="ahorro">Ahorro</option>
                    <option value="inversion">Inversion</option>
                    <option value="efectivo">Efectivo / Caja</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Titular *</label>
            <input type="text" name="titular" required>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Numero de cuenta</label>
                <input type="text" name="numero_cuenta">
            </div>
            <div class="form-group">
                <label>CLABE</label>
                <input type="text" name="clabe" maxlength="18">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Saldo inicial</label>
                <input type="number" step="0.01" name="saldo" value="0">
            </div>
            <div class="form-group">
                <label>Moneda</label>
                <select name="moneda">
                    <option value="MXN">MXN - Peso Mexicano</option>
                    <option value="USD">USD - Dolar</option>
                    <option value="EUR">EUR - Euro</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="activo" checked> Activa</label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Crear cuenta</button>
            <a href="bancos.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php elseif ($action === 'edit_cuenta' && $id):
    $c = $pdo->prepare("SELECT * FROM cuentas_bancarias WHERE id=?");
    $c->execute([$id]); $c = $c->fetch();
    if (!$c) { alert('danger', 'No encontrada'); redirect('bancos.php'); }
?>
<div class="card" style="max-width:600px">
    <div class="card-header"><h2>Editar Cuenta</h2></div>
    <form method="post">
        <div class="form-row">
            <div class="form-group">
                <label>Banco *</label>
                <input type="text" name="banco" required value="<?=h($c['banco'])?>">
            </div>
            <div class="form-group">
                <label>Tipo</label>
                <select name="tipo_cuenta">
                    <?php foreach (['cheques','ahorro','inversion','efectivo'] as $tc): ?>
                    <option value="<?=$tc?>" <?=$c['tipo_cuenta']===$tc?'selected':''?>><?=ucfirst($tc)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Titular</label>
            <input type="text" name="titular" value="<?=h($c['titular'])?>">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Cuenta</label>
                <input type="text" name="numero_cuenta" value="<?=h($c['numero_cuenta']??'')?>">
            </div>
            <div class="form-group">
                <label>CLABE</label>
                <input type="text" name="clabe" value="<?=h($c['clabe']??'')?>" maxlength="18">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Moneda</label>
                <select name="moneda">
                    <?php foreach (['MXN','USD','EUR'] as $m): ?>
                    <option value="<?=$m?>" <?=$c['moneda']===$m?'selected':''?>><?=$m?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <label><input type="checkbox" name="activo" <?=checked($c['activo'])?>> Activa</label>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Actualizar</button>
            <a href="bancos.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php elseif ($action === 'detalle' && $id):
    $c = $pdo->prepare("SELECT * FROM cuentas_bancarias WHERE id=?");
    $c->execute([$id]); $c = $c->fetch();
    if (!$c) { alert('danger', 'No encontrada'); redirect('bancos.php'); }
    $movs = $pdo->prepare("SELECT m.*, c.banco as destino_banco, c.titular as destino_titular FROM movimientos_bancarios m LEFT JOIN cuentas_bancarias c ON c.id=m.cuenta_destino_id WHERE m.cuenta_id=? ORDER BY m.created_at DESC LIMIT 50");
    $movs->execute([$id]);
    $tot_ingresos = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM movimientos_bancarios WHERE cuenta_id=? AND tipo IN ('deposito','transferencia_entrada')");
    $tot_ingresos->execute([$id]); $tot_ingresos = (float)$tot_ingresos->fetchColumn();
    $tot_egresos = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM movimientos_bancarios WHERE cuenta_id=? AND tipo IN ('retiro','transferencia_salida')");
    $tot_egresos->execute([$id]); $tot_egresos = (float)$tot_egresos->fetchColumn();
?>
<div class="card" style="max-width:900px">
    <div class="card-header">
        <h2><?=h($c['banco'])?> - <?=h($c['titular'])?></h2>
        <span class="badge badge-<?=$c['activo']?'success':'secondary'?>"><?=$c['activo']?'Activa':'Inactiva'?></span>
    </div>
    <div class="card-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));margin-bottom:12px;">
        <div class="stat-card"><div class="number" style="font-size:1.3rem;"><?=moneda($c['saldo'])?></div><div class="label">Saldo actual</div></div>
        <div class="stat-card"><div class="number" style="font-size:1.1rem;color:#06d6a0;"><?=moneda($tot_ingresos)?></div><div class="label">Total ingresos</div></div>
        <div class="stat-card"><div class="number" style="font-size:1.1rem;color:#ef476f;"><?=moneda($tot_egresos)?></div><div class="label">Total egresos</div></div>
    </div>
    <table>
        <tr><td><strong>Cuenta:</strong> <?=h($c['numero_cuenta'] ?? '-')?></td><td><strong>CLABE:</strong> <?=h($c['clabe'] ?? '-')?></td></tr>
        <tr><td><strong>Tipo:</strong> <?=ucfirst($c['tipo_cuenta'])?></td><td><strong>Moneda:</strong> <?=h($c['moneda'])?></td></tr>
    </table>

    <h3 style="margin:16px 0 8px;">Registrar movimiento</h3>
    <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;background:#f5f8fc;padding:12px;border-radius:8px;">
        <div>
            <label style="font-size:.75rem;">Tipo</label>
            <select name="tipo" id="tipo_mov" onchange="cambiarTipoMov()">
                <option value="deposito">+ Deposito</option>
                <option value="retiro">- Retiro</option>
                <option value="transferencia">Transferencia</option>
            </select>
        </div>
        <div id="destino_field" style="display:none;">
            <label style="font-size:.75rem;">Cuenta destino</label>
            <select name="cuenta_destino_id">
                <option value="">-- Seleccionar --</option>
                <?php foreach ($cuentas_activas as $ca): if ((int)$ca['id'] === (int)$id) continue; ?>
                <option value="<?=$ca['id']?>"><?=h($ca['banco'])?> - <?=h($ca['titular'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:.75rem;">Monto</label>
            <input type="number" step="0.01" name="monto" required placeholder="0.00" style="width:120px;">
        </div>
        <div style="flex:1;">
            <label style="font-size:.75rem;">Concepto</label>
            <input type="text" name="concepto" placeholder="Concepto..." style="width:100%;">
        </div>
        <div>
            <label style="font-size:.75rem;">Referencia</label>
            <input type="text" name="referencia" placeholder="Folio/Ref" style="width:120px;">
        </div>
        <div>
            <label style="font-size:.75rem;">Fecha</label>
            <input type="date" name="fecha_movimiento" value="<?=date('Y-m-d')?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Registrar</button>
    </form>

    <h3 style="margin:16px 0 8px;">Movimientos recientes</h3>
    <div class="table-wrapper">
        <table>
            <tr><th>Fecha</th><th>Tipo</th><th>Concepto</th><th>Monto</th><th>Saldo</th><th>Destino</th><th>Referencia</th></tr>
            <?php if (!count($movs)): ?>
            <tr><td colspan="7" style="text-align:center;color:#999;padding:20px;">Sin movimientos</td></tr>
            <?php else: foreach ($movs as $m): ?>
            <tr>
                <td><?=$m['fecha_movimiento']?></td>
                <td><span class="badge badge-<?=$m['tipo']==='deposito'||$m['tipo']==='transferencia_entrada'?'success':'danger'?>">
                    <?php if ($m['tipo']==='deposito'): ?>+ Deposito<?php elseif ($m['tipo']==='retiro'): ?>- Retiro<?php elseif ($m['tipo']==='transferencia_salida'): ?>Salida<?php else: ?>Entrada<?php endif; ?>
                </span></td>
                <td><?=h($m['concepto'] ?: '-')?></td>
                <td style="font-weight:600;color:<?=($m['tipo']==='deposito'||$m['tipo']==='transferencia_entrada')?'#06d6a0':'#ef476f'?>"><?=moneda($m['monto'])?></td>
                <td><?=moneda($m['saldo_nuevo'])?></td>
                <td style="font-size:.8rem;"><?=h($m['destino_banco'] ? $m['destino_banco'].' - '.$m['destino_titular'] : '-')?></td>
                <td style="font-size:.8rem;"><?=h($m['referencia'] ?: '-')?></td>
            </tr>
            <?php endforeach; endif; ?>
        </table>
    </div>
    <div class="form-actions" style="margin-top:12px;">
        <a href="bancos.php" class="btn btn-secondary"><- Volver</a>
    </div>
</div>
<script>
function cambiarTipoMov() {
    const t = document.getElementById('tipo_mov').value;
    document.getElementById('destino_field').style.display = t === 'transferencia' ? 'inline-block' : 'none';
}
cambiarTipoMov();
</script>

<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Cuentas Bancarias</h2>
        <div class="table-actions">
            <button type="button" class="btn btn-success btn-sm" onclick="exportarTablaExcel('#tabla-listado','cuentas_bancarias')"> Exportar Excel</button>
            <button type="button" class="btn btn-info btn-sm" onclick="imprimirTablaPDF('#tabla-listado','Cuentas Bancarias')"> Imprimir PDF</button>
            <a href="bancos.php?action=create_cuenta" class="btn btn-primary btn-sm">+ Nueva cuenta</a>
        </div>
    </div>
    <div class="card-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:12px;">
        <div class="stat-card"><div class="number" style="font-size:1.4rem;"><?=count($cuentas)?></div><div class="label">Cuentas</div></div>
        <div class="stat-card"><div class="number" style="font-size:1.4rem;color:#5b9bd5;"><?=moneda($total_saldos)?></div><div class="label">Saldo total</div></div>
    </div>
    <div class="table-wrapper">
        <table id="tabla-listado">
            <tr><th>Banco</th><th>Titular</th><th>Cuenta</th><th>Tipo</th><th>Moneda</th><th>Saldo</th><th>Movs</th><th>Activa</th><th>Acciones</th></tr>
            <?php if (!count($cuentas)): ?>
            <tr><td colspan="9" style="text-align:center;color:#999;padding:30px;">No hay cuentas registradas. <a href="bancos.php?action=create_cuenta">Crear primera cuenta</a></td></tr>
            <?php else: foreach ($cuentas as $c): ?>
            <tr>
                <td><strong><?=h($c['banco'])?></strong></td>
                <td><?=h($c['titular'])?></td>
                <td style="font-size:.8rem;font-family:monospace;"><?=h($c['numero_cuenta'] ?? '-')?></td>
                <td><?=ucfirst($c['tipo_cuenta'])?></td>
                <td><?=h($c['moneda'])?></td>
                <td style="font-weight:700;color:<?=$c['saldo']>=0?'#06d6a0':'#ef476f'?>"><?=moneda($c['saldo'])?></td>
                <td><?=$c['num_movs']?></td>
                <td><span class="badge badge-<?=$c['activo']?'success':'secondary'?>"><?=$c['activo']?'Si':'No'?></span></td>
                <td class="table-actions">
                    <a href="bancos.php?action=detalle&id=<?=$c['id']?>" class="btn btn-sm btn-info">Movimientos</a>
                    <a href="bancos.php?action=edit_cuenta&id=<?=$c['id']?>" class="btn btn-sm btn-warning">Editar</a>
                    <a href="bancos.php?action=delete_cuenta&id=<?=$c['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('Eliminar cuenta?')">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
