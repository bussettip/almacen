<?php
$titulo = 'Escanear QR';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$producto_id = isset($_GET['producto_id']) ? (int)$_GET['producto_id'] : 0;
$producto = null;
if ($producto_id) {
    $stmt = $pdo->prepare("SELECT p.*, c.nombre as categoria, u.codigo as umedida FROM productos p LEFT JOIN categorias c ON c.id=p.categoria_id JOIN unidades_medida u ON u.id=p.unidad_medida_id WHERE p.id=?");
    $stmt->execute([$producto_id]);
    $producto = $stmt->fetch();
}

require 'includes/header.php';
?>
<style>
.tab-bar { display:flex; gap:0; margin-bottom:20px; border-bottom:2px solid #dce1e8; }
.tab-bar button {
    padding:10px 20px; border:none; background:none; font-size:.9rem; font-weight:600;
    cursor:pointer; color:#888; border-bottom:2px solid transparent; margin-bottom:-2px;
    transition:all .2s;
}
.tab-bar button:hover { color:#5b9bd5; }
.tab-bar button.active { color:#5b9bd5; border-bottom-color:#5b9bd5; }
.tab-content { display:none; }
.tab-content.active { display:block; }
.scanner-input {
    font-size:1.2rem; text-align:center; letter-spacing:2px;
    padding:16px; border:2px dashed #5b9bd5; border-radius:8px;
    width:100%; max-width:400px; margin:0 auto; display:block;
}
.scanner-input:focus { outline:none; border-color:#4a8ac7; background:#f0f7ff; }
</style>

<div class="card" style="max-width:600px;margin:0 auto;">
    <div class="card-header"><h2>📷 Escanear Codigo</h2></div>

    <!-- Pestanas -->
    <div class="tab-bar">
        <button class="active" onclick="switchTab('escaner')">🔌 Escaner fisico</button>
        <button onclick="switchTab('camara')">📷 Camara web</button>
    </div>

    <!-- Pestana: Escaner fisico (keyboard wedge) -->
    <div id="tab-escaner" class="tab-content active">
        <p style="text-align:center;color:#888;font-size:.85rem;margin-bottom:12px;">
            Conecta tu escaner USB y apunta al codigo QR. El codigo se capturara automaticamente.
        </p>
        <input type="text" id="scanner-input" class="scanner-input"
               placeholder="Esperando escaner..." autofocus>
        <p style="text-align:center;color:#999;font-size:.8rem;margin-top:8px;">
            O escribe el codigo manualmente y presiona Enter
        </p>
        <div id="scanner-result" style="display:none;margin-top:16px;">
            <hr>
            <h3>Producto detectado</h3>
            <div id="scanner-producto-info" style="margin:12px 0;"></div>
            <div class="form-actions" id="scanner-result-actions"></div>
        </div>
    </div>

    <!-- Pestana: Camara web -->
    <div id="tab-camara" class="tab-content">
        <div style="text-align:center;margin-bottom:16px;">
            <div id="qr-reader" style="width:100%;max-width:400px;margin:0 auto;"></div>
            <p style="color:#888;font-size:.85rem;margin-top:8px;" id="qr-status">Iniciando camara...</p>
        </div>
        <div id="camara-result" style="display:none;">
            <hr>
            <h3>Producto detectado</h3>
            <div id="camara-producto-info" style="margin:12px 0;"></div>
            <div class="form-actions" id="camara-result-actions"></div>
        </div>
        <div style="text-align:center;margin-top:8px;">
            <button onclick="reiniciarCamara()" class="btn btn-sm btn-secondary">🔄 Escanear otro</button>
        </div>
    </div>

    <div style="text-align:center;margin-top:16px;padding-top:16px;border-top:1px solid #eef2f7;">
        <a href="entrada.php" class="btn btn-success"> Entrada manual</a>
        <a href="salida.php" class="btn btn-danger">📤 Salida manual</a>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const productos = <?=json_encode($pdo->query("SELECT id, codigo, nombre, COALESCE(codigo_qr, codigo) as qr_data FROM productos WHERE activo=1")->fetchAll())?>;

// ===== Cambio de pestanas =====
function switchTab(tab) {
    document.querySelectorAll('.tab-bar button').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelector(`.tab-bar button[onclick*='${tab}']`).classList.add('active');
    document.getElementById(`tab-${tab}`).classList.add('active');

    if (tab === 'escaner') {
        document.getElementById('scanner-input').focus();
        try { if (html5QrCode) html5QrCode.stop(); } catch(e) {}
    } else {
        if (!html5QrCode) iniciarCamara();
    }
}

// ===== Escaner fisico (keyboard wedge) =====
const scannerInput = document.getElementById('scanner-input');
let scannerTimer = null;

scannerInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        procesarCodigo(this.value.trim());
        this.value = '';
        return;
    }
    // Detectar entrada rapida del escaner (keyboard wedge)
    clearTimeout(scannerTimer);
    scannerTimer = setTimeout(() => {
        if (this.value.length > 3) {
            procesarCodigo(this.value.trim());
            this.value = '';
        }
    }, 150); // Si deja de escribir por 150ms, asume que es un escaneo completo
});

function procesarCodigo(codigo) {
    if (!codigo) return;

    const prod = productos.find(p => p.codigo === codigo || p.qr_data === codigo || p.id.toString() === codigo);
    const div = document.getElementById('scanner-result');
    div.style.display = 'block';

    if (prod) {
        document.getElementById('scanner-producto-info').innerHTML = `
            <p><strong>Codigo:</strong> ${prod.codigo}</p>
            <p><strong>Nombre:</strong> ${prod.nombre}</p>
        `;
        document.getElementById('scanner-result-actions').innerHTML = `
            <a href="entrada.php?producto_id=${prod.id}" class="btn btn-success"> Entrada</a>
            <a href="salida.php?producto_id=${prod.id}" class="btn btn-danger">📤 Salida</a>
            <a href="inventario.php?producto_id=${prod.id}" class="btn btn-info">📊 Ver stock</a>
        `;
    } else {
        document.getElementById('scanner-producto-info').innerHTML = `
            <p style="color:#ef476f;">Producto no encontrado: <strong>${codigo}</strong></p>`;
        document.getElementById('scanner-result-actions').innerHTML = `
            <a href="productos.php?action=create&codigo=${encodeURIComponent(codigo)}" class="btn btn-primary">+ Crear producto</a>
        `;
    }
    // Feedback sonoro/vibratorio
    if (navigator.vibrate) navigator.vibrate(100);
}

// ===== Camara web =====
let html5QrCode = null;

function procesarCamara(decodedText) {
    document.getElementById('qr-status').textContent = '[OK] ¡Codigo detectado!';
    document.getElementById('qr-status').style.color = '#06d6a0';

    const prod = productos.find(p => p.codigo === decodedText || p.qr_data === decodedText || p.id.toString() === decodedText);
    const div = document.getElementById('camara-result');
    div.style.display = 'block';

    if (prod) {
        document.getElementById('camara-producto-info').innerHTML = `
            <p><strong>Codigo:</strong> ${prod.codigo}</p>
            <p><strong>Nombre:</strong> ${prod.nombre}</p>`;
        document.getElementById('camara-result-actions').innerHTML = `
            <a href="entrada.php?producto_id=${prod.id}" class="btn btn-success"> Entrada</a>
            <a href="salida.php?producto_id=${prod.id}" class="btn btn-danger">📤 Salida</a>
            <a href="inventario.php?producto_id=${prod.id}" class="btn btn-info">📊 Ver stock</a>`;
    } else {
        document.getElementById('camara-producto-info').innerHTML = `
            <p style="color:#ef476f;">Producto no encontrado: <strong>${decodedText}</strong></p>`;
        document.getElementById('camara-result-actions').innerHTML = `
            <a href="productos.php?action=create&codigo=${encodeURIComponent(decodedText)}" class="btn btn-primary">+ Crear producto</a>`;
    }
    try { if (html5QrCode) html5QrCode.stop(); } catch(e) {}
    if (navigator.vibrate) navigator.vibrate(100);
}

function iniciarCamara() {
    try {
        html5QrCode = new Html5Qrcode("qr-reader");
        html5QrCode.start(
            { facingMode: "environment" },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            function(text) { procesarCamara(text); },
            function() {}
        ).then(() => {
            document.getElementById('qr-status').textContent = '📷 Camara activa. Apunta al codigo QR.';
        }).catch(err => {
            document.getElementById('qr-status').textContent = ' Error: ' + err;
            document.getElementById('qr-status').style.color = '#ef476f';
        });
    } catch(e) {
        document.getElementById('qr-status').textContent = ' Navegador no compatible: ' + e.message;
        document.getElementById('qr-status').style.color = '#ef476f';
    }
}

function reiniciarCamara() {
    document.getElementById('camara-result').style.display = 'none';
    try { if (html5QrCode) html5QrCode.stop(); } catch(e) {}
    iniciarCamara();
}

// Iniciar camara si esta seleccionada al cargar
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('scanner-input').focus();
});
</script>

<?php require 'includes/footer.php'; ?>
