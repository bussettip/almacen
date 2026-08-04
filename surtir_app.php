<?php
// surtir_app.php - App movil para empleados (surtido/entregado)
require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? '';

// --- API: Verificar empleado ---
if ($action === 'verificar_empleado') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false]); exit; }
    $r = $pdo->prepare("SELECT id, nombre, apellido FROM empleados WHERE id=? AND activo=1");
    $r->execute([$id]);
    $e = $r->fetch();
    echo json_encode($e ? ['ok'=>true, 'id'=>$e['id'], 'nombre'=>$e['nombre'], 'apellido'=>$e['apellido']] : ['ok'=>false]);
    exit;
}

// --- API: Obtener ordenes del empleado (incluye sin asignar) ---
if ($action === 'mis_ordenes') {
    header('Content-Type: application/json');
    $empleado_id = (int)($_GET['empleado_id'] ?? 0);
    if (!$empleado_id) { echo json_encode(['ok'=>false, 'error'=>'empleado_id requerido']); exit; }

    $ordenes = [];

    // Tienda (incluye sin asignar O asignadas a este empleado)
    $r = $pdo->prepare("SELECT o.id, o.folio, o.surtir_estatus as estatus, o.created_at, o.fecha_surtido,
                                a.nombre as almacen, 'tienda' as tipo, o.surtidor_id
                         FROM ordenes_compra o
                         JOIN almacenes a ON a.id=o.almacen_id
                         WHERE o.folio LIKE 'TDA-%' AND (o.surtidor_id IS NULL OR o.surtidor_id=?) AND o.surtir_estatus != 'entregado'
                         ORDER BY o.surtidor_id IS NULL DESC, FIELD(o.surtir_estatus,'pendiente','en_surtido','surtido'), o.created_at DESC");
    $r->execute([$empleado_id]);
    try { $pdo->exec("ALTER TABLE ordenes_compra_detalle ADD COLUMN descuento DECIMAL(12,2) DEFAULT 0 AFTER precio_unitario"); } catch (Exception $e) {}
    foreach ($r as $row) {
        try { $pdo->exec("ALTER TABLE ordenes_compra_detalle ADD COLUMN cantidad_surtida DECIMAL(12,2) NULL DEFAULT NULL AFTER cantidad"); } catch (Exception $e) {}
        $det = $pdo->prepare("SELECT d.cantidad, COALESCE(d.cantidad_surtida,0) as cantidad_surtida, p.codigo, p.nombre as producto, um.codigo as umedida,
                                     d.precio_unitario, COALESCE(d.descuento,0) as descuento
                              FROM ordenes_compra_detalle d
                              JOIN productos p ON p.id=d.producto_id
                              JOIN unidades_medida um ON um.id=p.unidad_medida_id
                              WHERE d.orden_compra_id=?");
        $det->execute([$row['id']]);
        $row['productos'] = $det->fetchAll();
        $ordenes[] = $row;
    }

    // Ventas (incluye sin asignar O asignadas a este empleado)
    $r = $pdo->prepare("SELECT v.id, v.folio, v.estatus, v.created_at, v.fecha_surtido,
                                a.nombre as almacen, cl.nombre as cliente, 'venta' as tipo, v.surtidor_id
                         FROM ventas v
                         JOIN almacenes a ON a.id=v.almacen_id
                         LEFT JOIN clientes cl ON cl.id=v.cliente_id
                         WHERE (v.surtidor_id IS NULL OR v.surtidor_id=?) AND v.estatus IN ('pendiente','surtido')
                         ORDER BY v.surtidor_id IS NULL DESC, FIELD(v.estatus,'pendiente','surtido'), v.created_at DESC");
    $r->execute([$empleado_id]);
    foreach ($r as $row) {
        $det = $pdo->prepare("SELECT d.cantidad, d.cantidad_surtida, p.codigo, p.nombre as producto, um.codigo as umedida,
                                     d.precio_unitario, COALESCE(d.descuento,0) as descuento
                              FROM venta_detalle d
                              JOIN productos p ON p.id=d.producto_id
                              JOIN unidades_medida um ON um.id=p.unidad_medida_id
                              WHERE d.venta_id=?");
        $det->execute([$row['id']]);
        $row['productos'] = $det->fetchAll();
        $ordenes[] = $row;
    }

    echo json_encode(['ok'=>true, 'empleado_id'=>$empleado_id, 'ordenes'=>$ordenes], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- API: Tomar pedido (auto-asignarse) ---
if ($action === 'tomar_pedido') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $tipo = $input['tipo'] ?? '';
    $folio = $input['folio'] ?? '';
    $empleado_id = (int)($input['empleado_id'] ?? 0);
    if (!$tipo || !$folio || !$empleado_id) {
        echo json_encode(['ok'=>false, 'error'=>'faltan datos']); exit;
    }
    try {
        if ($tipo === 'tienda') {
            $r = $pdo->prepare("UPDATE ordenes_compra SET surtidor_id=?, surtir_estatus='en_surtido' WHERE folio=? AND (surtidor_id IS NULL OR surtidor_id=?)");
            $r->execute([$empleado_id, $folio, $empleado_id]);
        } else {
            $r = $pdo->prepare("UPDATE ventas SET surtidor_id=? WHERE folio=? AND (surtidor_id IS NULL OR surtidor_id=?)");
            $r->execute([$empleado_id, $folio, $empleado_id]);
        }
        if ($r->rowCount() > 0) {
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false, 'error'=>'pedido no disponible']);
        }
    } catch (Exception $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#1a1a2e">
<meta name="apple-mobile-web-app-capable" content="yes">
<link rel="manifest" href="surtir_manifest.json">
<link rel="icon" href="uploads/empresa/surtir_192.png">
<link rel="apple-touch-icon" href="uploads/empresa/surtir_192.png">
<title>Surtir App</title>
<script src="https://unpkg.com/mqtt@5/dist/mqtt.min.js"></script>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('surtir_sw.js');
}
</script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f0f1a;color:#e0e0e0;min-height:100dvh}
#app{max-width:480px;margin:0 auto;padding:12px 12px 80px}

/* Login */
#login-screen{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:80dvh;gap:16px}
#login-screen h1{font-size:2rem;color:#4fc3f7;font-weight:800}
#login-screen p{color:#888;font-size:.9rem;text-align:center}
.login-input{background:#1e1e32;border:2px solid #333;color:#fff;font-size:1.5rem;padding:14px 20px;border-radius:12px;width:200px;text-align:center;outline:none;transition:border-color .2s}
.login-input:focus{border-color:#4fc3f7}
.login-btn{background:#4fc3f7;color:#000;border:none;font-size:1.1rem;font-weight:700;padding:12px 40px;border-radius:12px;cursor:pointer;transition:opacity .2s}
.login-btn:disabled{opacity:.4}
.error-msg{color:#ff6b6b;font-size:.85rem;display:none}

/* Header */
.app-header{display:flex;justify-content:space-between;align-items:center;padding:12px 0 16px;border-bottom:1px solid #222;margin-bottom:12px}
.app-header h1{font-size:1.3rem;color:#4fc3f7}
.app-header .user-info{font-size:.8rem;color:#888;text-align:right}
.app-header .user-info .name{color:#e0e0e0;font-weight:600}
.status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:4px}
.status-dot.online{background:#27ae60}
.status-dot.offline{background:#e74c3c}

/* Stats bar */
.stats{display:flex;gap:8px;margin-bottom:14px;font-size:.8rem}
.stat-box{flex:1;background:#1a1a2e;border-radius:10px;padding:10px;text-align:center}
.stat-box .num{font-size:1.5rem;font-weight:700;color:#4fc3f7}
.stat-box .lbl{color:#666;font-size:.7rem;text-transform:uppercase}

/* Order card */
.orden-card{background:#1a1a2e;border-radius:12px;padding:18px;margin-bottom:12px;border:1px solid #2a2a3e;transition:border-color .2s}
.orden-card:active{transform:scale(.99)}
.orden-card .top{display:flex;justify-content:space-between;align-items:start;margin-bottom:6px}
.orden-card .folio{font-weight:700;font-size:.95rem;color:#4fc3f7}
.orden-card .tipo-badge{font-size:.65rem;padding:2px 8px;border-radius:8px;text-transform:uppercase;font-weight:600}
.tipo-tienda{background:#2d2d5e;color:#818cf8}
.tipo-venta{background:#2d4a2d;color:#6ee7b7}
.orden-card .meta{font-size:.75rem;color:#666;margin-bottom:8px}
.orden-card .productos{background:#151525;border-radius:8px;padding:8px;font-size:.8rem;margin-bottom:10px}
.orden-card .productos table{width:100%;border-collapse:collapse}
.orden-card .productos .prod-table{border:1px solid #333;border-radius:6px}
.orden-card .productos .prod-table th{border-bottom:1px solid #333;border-right:1px solid #333;text-align:left;font-size:.7rem;color:#aaa;padding:5px 6px;text-transform:uppercase;background:#1e1e30}
.orden-card .productos .prod-table th:last-child{border-right:none}
.orden-card .productos .prod-table td{border-bottom:1px solid #2a2a3e;border-right:1px solid #2a2a3e;padding:6px;font-size:.82rem}
.orden-card .productos .prod-table td:last-child{border-right:none}
.orden-card .productos .prod-table tr:last-child td{border-bottom:none}
.orden-card .actions{display:flex;gap:8px}

.btn{flex:1;padding:12px;border:none;border-radius:10px;font-size:.85rem;font-weight:600;cursor:pointer;transition:opacity .15s;text-align:center}
.btn:active{opacity:.7}
.btn-success{background:#27ae60;color:#fff}
.btn-info{background:#4fc3f7;color:#000}
.btn-secondary{background:#333;color:#aaa}
.btn-danger{background:#e74c3c;color:#fff}
.btn:disabled{opacity:.3;pointer-events:none}

/* Estado badges */
.estado-badge{display:inline-block;font-size:.65rem;padding:2px 8px;border-radius:8px;font-weight:600}
.estado-pendiente{background:#3d3d1a;color:#fbbf24}
.estado-en_surtido{background:#1a2d3d;color:#4fc3f7}
.estado-surtido{background:#1a3d2a;color:#6ee7b7}
.estado-entregado{background:#2a2a2a;color:#999}

/* Alert toast */
.toast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:#27ae60;color:#fff;padding:10px 24px;border-radius:10px;font-size:.85rem;font-weight:600;z-index:100;opacity:0;transition:opacity .3s;pointer-events:none}
.toast.show{opacity:1}
.toast.error{background:#e74c3c}

/* Bottom nav */
.bottom-nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);max-width:480px;width:100%;background:#1a1a2e;border-top:1px solid #2a2a3e;display:flex;justify-content:space-around;padding:8px 0;z-index:50}
.bottom-nav button{background:none;border:none;color:#666;font-size:.7rem;cursor:pointer;padding:6px 16px;border-radius:8px;transition:all .2s;display:flex;flex-direction:column;align-items:center;gap:2px}
.bottom-nav button.active{color:#4fc3f7}
.bottom-nav button svg{width:22px;height:22px;fill:currentColor}
.bottom-nav .badge{background:#e74c3c;color:#fff;font-size:.6rem;padding:1px 6px;border-radius:8px;margin-left:4px}

/* Empty state */
.empty{text-align:center;padding:40px 20px;color:#555}
.empty svg{width:60px;height:60px;fill:#333;margin-bottom:12px}
.empty p{font-size:.9rem}

/* Loading */
.loading{text-align:center;padding:40px;color:#555}
.loading .spinner{width:32px;height:32px;border:3px solid #333;border-top-color:#4fc3f7;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 10px}
@keyframes spin{to{transform:rotate(360deg)}}

/* Pull to refresh hint */
.refresh-hint{text-align:center;font-size:.75rem;color:#444;padding:8px 0 4px}
</style>
</head>
<body>

<div id="app">

<!-- Login Screen -->
<div id="login-screen">
    <svg width="64" height="64" viewBox="0 0 24 24" fill="#4fc3f7"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9l1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
    <h1>Surtir App</h1>
    <p>Ingresa tu numero de empleado</p>
    <input type="number" id="emp-input" class="login-input" placeholder="000" inputmode="numeric" autofocus>
    <div class="error-msg" id="login-error">Empleado no encontrado</div>
    <button class="login-btn" id="login-btn" disabled>Entrar</button>
</div>

<!-- Main Screen -->
<div id="main-screen" style="display:none">
    <div class="app-header">
        <div style="display:flex;align-items:center;gap:10px">
            <svg width="32" height="32" viewBox="0 0 40 40"><rect width="40" height="40" rx="8" fill="#4fc3f7"/><text x="20" y="27" font-family="Arial,sans-serif" font-size="20" font-weight="700" fill="#1a1a2e" text-anchor="middle">B</text></svg>
            <div>
                <h1>Surtir</h1>
                <div style="font-size:.7rem;color:#666"><span class="status-dot offline" id="conn-dot"></span><span id="conn-text">Conectando...</span></div>
            </div>
        </div>
        <div class="user-info">
            <div class="name" id="user-name"></div>
            <div style="font-size:.65rem;color:#555" id="user-id"></div>
        </div>
    </div>

    <div class="stats">
        <div class="stat-box"><div class="num" id="stat-pendientes">0</div><div class="lbl">Pendientes</div></div>
        <div class="stat-box"><div class="num" id="stat-surtido">0</div><div class="lbl">Surtidos</div></div>
        <div class="stat-box"><div class="num" id="stat-entregado">0</div><div class="lbl">Entregados</div></div>
    </div>

    <div id="orders-container">
        <div class="loading" id="loading-orders">
            <div class="spinner"></div>
            <p>Cargando ordenes...</p>
        </div>
    </div>

    <div id="historial-container" style="display:none">
        <div style="margin-bottom:12px;font-size:.9rem;color:#888">Ultimas entregas</div>
        <div id="historial-list"></div>
    </div>
</div>

<!-- Bottom Nav -->
<div class="bottom-nav" id="bottom-nav" style="display:none">
    <button class="active" id="nav-ordenes" onclick="mostrarSeccion('ordenes')">
        <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zM7 10h2v7H7zm4-3h2v10h-2zm4 6h2v4h-2z"/></svg>
        Ordenes <span class="badge" id="nav-badge" style="display:none">0</span>
    </button>
    <button id="nav-historial" onclick="mostrarSeccion('historial')">
        <svg viewBox="0 0 24 24"><path d="M13 3c-4.97 0-9 4.03-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42C8.27 19.99 10.51 21 13 21c4.97 0 9-4.03 9-9s-4.03-9-9-9zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>
        Historial
    </button>
    <button onclick="refrescar()">
        <svg viewBox="0 0 24 24"><path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>
        Refrescar
    </button>
    <button onclick="cerrarSesion()">
        <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
        Salir
    </button>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
// ======== CONFIG ========
var EMPLEADO_ID = 0;
var EMPLEADO_NOM = '';
var mqttClient = null;
var ordenes = [];

// ======== LOGIN ========
var empInput = document.getElementById('emp-input');
var loginBtn = document.getElementById('login-btn');
var loginError = document.getElementById('login-error');

empInput.addEventListener('input', function() {
    loginBtn.disabled = this.value.trim() === '';
    loginError.style.display = 'none';
});

empInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !loginBtn.disabled) loginBtn.click();
});

loginBtn.addEventListener('click', async function() {
    var id = parseInt(empInput.value.trim());
    if (!id) return;
    loginBtn.disabled = true;
    loginBtn.textContent = 'Verificando...';
    try {
        var res = await fetch('surtir_app.php?action=verificar_empleado&id=' + id);
        var data = await res.json();
        if (data.ok) {
            EMPLEADO_ID = data.id;
            EMPLEADO_NOM = data.nombre + ' ' + data.apellido;
            document.getElementById('login-screen').style.display = 'none';
            document.getElementById('main-screen').style.display = 'block';
            document.getElementById('bottom-nav').style.display = 'flex';
            document.getElementById('user-name').textContent = EMPLEADO_NOM;
            document.getElementById('user-id').textContent = '#' + EMPLEADO_ID;
            conectarMQTT();
            cargarOrdenes();
        } else {
            loginError.style.display = 'block';
        }
    } catch(e) {
        loginError.textContent = 'Error de conexion: ' + e.message;
        loginError.style.display = 'block';
    }
    loginBtn.disabled = false;
    loginBtn.textContent = 'Entrar';
});

// ======== MQTT ========
function conectarMQTT() {
    var wsUrl = location.protocol === 'https:' ? 'wss://' : 'ws://';
    wsUrl += location.host + '/ws/mqtt';
    try {
        mqttClient = mqtt.connect(wsUrl, {
            clientId: 'surtir_app_' + EMPLEADO_ID + '_' + Math.random().toString(16).substr(2,6),
            clean: true
        });
        mqttClient.on('connect', function() {
            document.getElementById('conn-dot').className = 'status-dot online';
            document.getElementById('conn-text').textContent = 'Conectado';
            mqttClient.subscribe('almacen/surtir/empleado/' + EMPLEADO_ID, function(err) {
                if (err) console.error('Subscribe error:', err);
            });
        });
        mqttClient.on('message', function(topic, message) {
            try {
                var payload = JSON.parse(message.toString());
                var msg = payload.accion === 'nueva_orden' ? 'Nuevo pedido: ' : 'Novedad: ';
                msg += '#' + (payload.pedido || payload.folio || '');
                if (payload.ubicaciones && payload.ubicaciones.length) msg += ' - Ubic: ' + payload.ubicaciones.join(', ');
                mostrarToast(msg, '');
                cargarOrdenes();
            } catch(e) {}
        });
        mqttClient.on('reconnect', function() {
            document.getElementById('conn-dot').className = 'status-dot offline';
            document.getElementById('conn-text').textContent = 'Reconectando...';
        });
        mqttClient.on('offline', function() {
            document.getElementById('conn-dot').className = 'status-dot offline';
            document.getElementById('conn-text').textContent = 'Desconectado';
        });
        mqttClient.on('error', function(err) {
            console.error('MQTT error:', err);
            document.getElementById('conn-dot').className = 'status-dot offline';
            document.getElementById('conn-text').textContent = 'Error MQTT';
        });
        setTimeout(function() {
            if (!mqttClient || !mqttClient.connected) {
                document.getElementById('conn-text').textContent = 'MQTT no disponible (usando polling)';
            }
        }, 5000);
    } catch(e) {
        console.error('MQTT connect error:', e);
        document.getElementById('conn-text').textContent = 'MQTT no disponible';
    }
}

// ======== CARGAR ORDENES ========
async function cargarOrdenes() {
    var container = document.getElementById('orders-container');
    container.innerHTML = '<div class="loading"><div class="spinner"></div><p>Cargando...</p></div>';
    try {
        var res = await fetch('surtir_app.php?action=mis_ordenes&empleado_id=' + EMPLEADO_ID);
        var data = await res.json();
        if (!data.ok) { container.innerHTML = '<div class="empty"><p>Error al cargar</p></div>'; return; }
        ordenes = data.ordenes || [];
        renderizarOrdenes();
    } catch(e) {
        container.innerHTML = '<div class="empty"><p>Error de conexion</p></div>';
    }
}

function renderizarOrdenes() {
    var container = document.getElementById('orders-container');
    var stats = {pendientes: 0, surtido: 0, entregado: 0};
    ordenes.forEach(function(o) {
        var s = o.estatus || 'pendiente';
        if (s === 'entregado') stats.entregado++;
        else if (s === 'surtido') stats.surtido++;
        else stats.pendientes++;
    });
    document.getElementById('stat-pendientes').textContent = stats.pendientes;
    document.getElementById('stat-surtido').textContent = stats.surtido;
    document.getElementById('stat-entregado').textContent = stats.entregado;
    var badge = document.getElementById('nav-badge');
    if (stats.pendientes > 0) { badge.textContent = stats.pendientes; badge.style.display = 'inline'; }
    else badge.style.display = 'none';

    if (ordenes.length === 0) {
        container.innerHTML = '<div class="empty"><svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9l1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg><p>Sin ordenes asignadas</p></div>';
        return;
    }

    var html = '';
    ordenes.forEach(function(o) {
        var estadoLabel = (o.estatus || 'pendiente').replace('_',' ');
        var estadoClass = 'estado-' + (o.estatus || 'pendiente');
        var tipoClass = o.tipo === 'tienda' ? 'tipo-tienda' : 'tipo-venta';
        var asignadoLabel = o.surtidor_id ? '' : '<span class="estado-badge estado-pendiente" style="margin-left:4px">Sin asignar</span>';
        var itemsHtml = '';
        (o.productos || []).forEach(function(p) {
            var cant = parseFloat(p.cantidad || 0);
            var pv = parseFloat(p.precio_unitario || 0);
            var dcto = parseFloat(p.descuento || 0);
            itemsHtml += '<tr><td>' + p.producto + '</td><td style="text-align:center">' + cant + '</td><td style="text-align:right">$' + pv.toFixed(2) + '</td><td style="text-align:right">' + (dcto ? dcto + '%' : '-') + '</td></tr>';
        });

        var btnsHtml = '';
        if (!o.surtidor_id) {
            btnsHtml = '<button class="btn btn-info" onclick="tomarPedido(\'' + o.tipo + '\',\'' + o.folio + '\')">Tomar pedido</button>';
        } else if (o.estatus === 'entregado') {
            btnsHtml = '<button class="btn btn-secondary" disabled>Entregado</button>';
        } else if (o.estatus === 'surtido') {
            btnsHtml = '<button class="btn btn-info" onclick="accion(\'' + o.tipo + '\',\'' + o.folio + '\',\'entregado\')">Marcar entregado</button>';
        } else {
            btnsHtml = '<button class="btn btn-success" onclick="accion(\'' + o.tipo + '\',\'' + o.folio + '\',\'surtido\')">Marcar surtido</button>';
        }

        var clienteHtml = o.cliente ? ' | ' + o.cliente : '';
        html += '<div class="orden-card">'
            + '<div class="top">'
            + '<span class="folio">' + o.folio + '</span>'
            + '<span><span class="tipo-badge ' + tipoClass + '">' + o.tipo + '</span> <span class="estado-badge ' + estadoClass + '">' + estadoLabel + '</span>' + (asignadoLabel || '') + '</span>'
            + '</div>'
            + '<div class="meta">' + (o.almacen || '') + clienteHtml + ' | ' + (o.created_at || '').substr(0,16) + '</div>'
            + '<div class="productos"><table class="prod-table"><tr><th>Producto</th><th style="text-align:center">Cant</th><th style="text-align:right">Precio</th><th style="text-align:right">Descto.</th></tr>' + itemsHtml + '</table></div>'
            + '<div class="actions">' + btnsHtml + '</div>'
            + '</div>';
    });
    container.innerHTML = html;
}

// ======== ACCION ========
async function accion(tipo, folio, accion) {
    var btn = event && event.target ? event.target : null;
    if (btn) { btn.disabled = true; btn.textContent = 'Enviando...'; }
    try {
        var res = await fetch('surtir_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({accion: accion, tipo: tipo, folio: folio, empleado_id: EMPLEADO_ID})
        });
        var data = await res.json();
        if (data.ok) {
            mostrarToast('✓ ' + (accion === 'surtido' ? 'Surtido' : 'Entregado') + ' correctamente', '');
            guardarHistorial({folio: folio, tipo: tipo, accion: accion});
            cargarOrdenes();
        } else {
            mostrarToast('Error: ' + (data.error || 'desconocido'), 'error');
            if (btn) btn.disabled = false;
        }
    } catch(e) {
        mostrarToast('Error de conexion', 'error');
        if (btn) btn.disabled = false;
    }
}

// ======== TOMAR PEDIDO ========
async function tomarPedido(tipo, folio) {
    if (!confirm('Tomar este pedido?')) return;
    try {
        var res = await fetch('surtir_app.php?action=tomar_pedido', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({tipo: tipo, folio: folio, empleado_id: EMPLEADO_ID})
        });
        var data = await res.json();
        if (data.ok) {
            mostrarToast('Pedido asignado', '');
            cargarOrdenes();
        } else {
            mostrarToast('Error: ' + (data.error || 'desconocido'), 'error');
        }
    } catch(e) {
        mostrarToast('Error de conexion', 'error');
    }
}

// ======== REFRESCAR ========
function refrescar() {
    cargarOrdenes();
    mostrarToast('Actualizando...', '');
}

// ======== CERRAR SESION ========
function cerrarSesion() {
    if (mqttClient) { try { mqttClient.end(true); } catch(e) {} }
    EMPLEADO_ID = 0;
    ordenes = [];
    document.getElementById('main-screen').style.display = 'none';
    document.getElementById('bottom-nav').style.display = 'none';
    document.getElementById('login-screen').style.display = 'flex';
    document.getElementById('emp-input').value = '';
    document.getElementById('emp-input').focus();
    loginBtn.disabled = true;
}

// ======== SECCIONES (Ordenes / Historial) ========
function mostrarSeccion(seccion) {
    document.getElementById('orders-container').style.display = seccion === 'ordenes' ? 'block' : 'none';
    document.getElementById('historial-container').style.display = seccion === 'historial' ? 'block' : 'none';
    document.getElementById('nav-ordenes').className = seccion === 'ordenes' ? 'active' : '';
    document.getElementById('nav-historial').className = seccion === 'historial' ? 'active' : '';
    if (seccion === 'historial') renderizarHistorial();
}

function guardarHistorial(item) {
    var hist = JSON.parse(localStorage.getItem('surtir_historial') || '[]');
    item.timestamp = new Date().toISOString();
    hist.unshift(item);
    if (hist.length > 50) hist = hist.slice(0, 50);
    localStorage.setItem('surtir_historial', JSON.stringify(hist));
}

function renderizarHistorial() {
    var hist = JSON.parse(localStorage.getItem('surtir_historial') || '[]');
    var list = document.getElementById('historial-list');
    if (hist.length === 0) {
        list.innerHTML = '<div class="empty"><p>Sin historial</p></div>';
        return;
    }
    var html = '';
    hist.forEach(function(h) {
        var accionLabel = h.accion === 'entregado' ? 'Entregado' : 'Surtido';
        var color = h.accion === 'entregado' ? '#4fc3f7' : '#27ae60';
        html += '<div class="orden-card" style="border-left:3px solid ' + color + '">'
            + '<div class="top"><span class="folio">' + h.folio + '</span>'
            + '<span style="font-size:.75rem;color:#888">' + (h.timestamp || '').substr(0,16).replace('T',' ') + '</span></div>'
            + '<div class="meta" style="margin-bottom:0">' + h.tipo + ' | ' + accionLabel + '</div>'
            + '</div>';
    });
    list.innerHTML = html;
}

// ======== TOAST ========
function mostrarToast(msg, tipo) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast' + (tipo === 'error' ? ' error' : '');
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2500);
}
</script>
</body>
</html>