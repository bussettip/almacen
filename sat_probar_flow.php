<?php
// Diagnostico real del flujo SAT: reproduce el login/captcha que usa facturas_sat.php
// y muestra el error exacto. No descarga, solo prueba hasta obtener el captcha.
require 'includes/auth.php';
verificarPermiso('facturas_sat');
$titulo = 'Diagnostico real del flujo SAT';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rfc = trim($_POST['rfc'] ?? '');
    $ciec = trim($_POST['ciec'] ?? '');
    echo '<h3>Resultado</h3><pre style="font-size:.8rem;max-height:500px;overflow:auto;background:#222;color:#7fdb7f;padding:12px;border-radius:8px;">';
    if ($rfc === '' || $ciec === '') {
        echo "RFC o CIEC vacios.\n";
    } else {
        try {
            require_once 'includes/sat_helper.php';
            $scraper = sat_crear_scraper($rfc, $ciec);
            echo "PASO 1: scraper creado OK\n";

            $sessionManager = $scraper->getSessionManager();
            echo "PASO 2: pidiendo captcha...\n";
            $img = $sessionManager->requestCaptchaImage();
            echo "PASO 3: captcha obtenido OK - mime=" . $img->getMimeType() . " bytes=" . strlen($img->asBase64()) . "\n";
            echo "NOTA: el login del SAT funciona. El captcha SI se puede obtener.\n";
        } catch (Throwable $e) {
            echo "ERROR en flujo:\n";
            echo get_class($e) . ": " . $e->getMessage() . "\n\n";
            if (method_exists($e, 'getContents')) {
                $c = $e->getContents();
                echo "Contenido de la respuesta del SAT:\n";
                echo (is_string($c) ? $c : json_encode($c)) . "\n";
            }
        }
    }
    echo '</pre>';
    exit;
}
require 'includes/header.php';
?>
<div class="card" style="max-width:720px;">
    <div class="card-header"><h2>Probar flujo real SAT (captcha)</h2></div>
    <div style="padding:16px;">
        <p style="font-size:.85rem;color:#555;">Ejecuta el mismo código que usa facturas_sat.php: crea el scraper y solicita el captcha. Sirve para ver el error exacto del SAT si la descarga no funciona.</p>
        <form method="post">
            <div class="form-group"><label>RFC (receptor)</label><input type="text" name="rfc" value="<?=h($_POST['rfc'] ?? '')?>" required></div>
            <div class="form-group"><label>Clave CIEC</label><input type="text" name="ciec" value="<?=h($_POST['ciec'] ?? '')?>" required autocomplete="off"></div>
            <button type="submit" class="btn btn-primary">Probar</button>
        </form>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
