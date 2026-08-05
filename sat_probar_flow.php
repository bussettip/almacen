<?php
// Diagnostico real del flujo SAT: reproduce el login/captcha que usa facturas_sat.php
// y muestra el error exacto en texto claro. Guarda la respuesta del SAT en un archivo.
require 'includes/auth.php';
verificarPermiso('facturas_sat');
$titulo = 'Diagnostico real del flujo SAT';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rfc = trim($_POST['rfc'] ?? '');
    $ciec = trim($_POST['ciec'] ?? '');

    echo '<h3>Resultado</h3><div style="background:#fff;border:1px solid #ccc;border-radius:8px;padding:12px;font-family:monospace;font-size:.85rem;line-height:1.5;overflow:auto;">';

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
            echo "Tipo: " . get_class($e) . "\n";
            echo "Mensaje: " . $e->getMessage() . "\n\n";

            if (method_exists($e, 'getContents')) {
                $c = $e->getContents();
                if (is_string($c)) {
                    // Guardar la respuesta completa en un archivo para poder leerla
                    $arch = 'uploads/sat_respuesta_debug.txt';
                    file_put_contents(__DIR__ . '/' . $arch, $c);
                    echo "La respuesta del SAT se guardo en: " . $arch . "\n";
                    echo "Puedes abrirla en: <a href='$arch' target='_blank'>$arch</a>\n\n";

                    $txt = trim(strip_tags($c));
                    $txt = preg_replace('/\s+/', ' ', $txt);
                    echo "Contenido visible (texto plano, primeros 1000 chars):\n";
                    echo mb_substr($txt, 0, 1000) . "\n";
                }
            }
            if (method_exists($e, 'getHttpStatusCode')) {
                echo "\nHTTP status: " . $e->getHttpStatusCode() . "\n";
            }
        }
    }
    echo '</div>';
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
