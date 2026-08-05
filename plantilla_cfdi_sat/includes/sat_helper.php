<?php
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Registro de auditoria del flujo SAT. Escribe en uploads/sat_log.txt
 * para diagnosticar paso a paso que hace el SAT.
 */
function sat_log(string $msg): void
{
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($dir . '/sat_log.txt', $linea, FILE_APPEND);
}

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\SessionCookieJar;
use GuzzleHttp\RequestOptions;
use PhpCfdi\CfdiSatScraper\Filters\DownloadType;
use PhpCfdi\CfdiSatScraper\QueryByFilters;
use PhpCfdi\CfdiSatScraper\ResourceType;
use PhpCfdi\CfdiSatScraper\SatHttpGateway;
use PhpCfdi\CfdiSatScraper\SatScraper;
use PhpCfdi\CfdiSatScraper\Sessions\Ciec\CiecSessionManager;
use PhpCfdi\ImageCaptchaResolver\CaptchaImage;

/**
 * Crea un SatScraper para autenticarse contra el SAT con RFC + CIEC.
 * Usa un SessionCookieJar (persistido en la sesion PHP) para conservar
 * la sesion del SAT entre peticiones web (necesario para el captcha
 * interactivo que se resuelve en dos pasos).
 */
function sat_crear_scraper(string $rfc, string $ciec): SatScraper
{
    // El resolver solo se usa si el login interno se dispara por accidente;
    // el flujo interactivo usa requestCaptchaImage()/loginPostLoginData().
    $sessionManager = CiecSessionManager::create($rfc, $ciec, new SatCaptchaNuncaResolver());

    $cookieJar = new SessionCookieJar('sat_cookie_jar', true);
    $client = new Client([
        RequestOptions::COOKIES => $cookieJar,
        // Los servidores del SAT usan suites TLS antiguas
        'curl' => [CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1'],
        'timeout' => 60,
        'connect_timeout' => 30,
        // El portal CFDI no entrega el certificado intermedio (GlobalSign RSA OV SSL CA 2018);
        // usar un bundle CA combinado (sistema + intermedio) para no fallar en TLS.
        RequestOptions::VERIFY => sat_ca_bundle(),
    ]);
    $gateway = new SatHttpGateway($client, $cookieJar);
    $sessionManager->setHttpGateway($gateway);

    return new SatScraper($sessionManager, $gateway);
}

/**
 * Devuelve la ruta a un bundle CA combinado (raices del sistema + el
 * certificado intermedio GlobalSign que usa el portal CFDI del SAT).
 * Lo genera la primera vez en uploads/ca_bundle_sat.pem.
 */
function sat_ca_bundle(): string
{
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $destino = $dir . '/ca_bundle_sat.pem';

    if (is_file($destino)) {
        return $destino;
    }

    // 1) Certificado intermedio que viaja con la app
    $gs = __DIR__ . '/sat_gs_ca.pem';
    $contenido = is_file($gs) ? file_get_contents($gs) : '';

    // 2) Raices del sistema (bundle de OpenSSL/cURL de la distro)
    foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'] as $sistema) {
        if (is_file($sistema)) {
            $contenido .= PHP_EOL . file_get_contents($sistema);
            break;
        }
    }

    // 3) Si no hay bundle del sistema, dejar la CA del sistema por defecto de PHP
    if (trim($contenido) === '') {
        return $destino;
    }

    @file_put_contents($destino, $contenido);
    return $destino;
}

/**
 * Solicita la imagen del captcha del login del SAT.
 * Devuelve el objeto CaptchaImage para mostrarlo al usuario.
 *
 * @throws PhpCfdi\CfdiSatScraper\Sessions\Ciec\CiecLoginException
 */
function sat_obtener_captcha(SatScraper $scraper): CaptchaImage
{
    $sessionManager = $scraper->getSessionManager();
    if (!$sessionManager instanceof CiecSessionManager) {
        throw new RuntimeException('Manejador de sesion inesperado');
    }
    return $sessionManager->requestCaptchaImage();
}

/**
 * Envia la clave CIEC + la respuesta del captcha al SAT para iniciar sesion.
 * Si la respuesta es incorrecta lanza CiecLoginException.
 *
 * @throws PhpCfdi\CfdiSatScraper\Sessions\Ciec\CiecLoginException
 */
function sat_enviar_captcha(SatScraper $scraper, string $respuesta): void
{
    $sessionManager = $scraper->getSessionManager();
    if (!$sessionManager instanceof CiecSessionManager) {
        throw new RuntimeException('Manejador de sesion inesperado');
    }
    $sessionManager->loginPostLoginData($respuesta);
}

/**
 * Descarga los CFDI recibidos del periodo (la sesion ya debe estar iniciada).
 * Guarda los XML y PDF en uploads/facturas_sat/ y devuelve los metadatos
 * con las rutas de cada archivo (archivo_xml / archivo_pdf).
 *
 * @return array<int, array<string, string>>
 */
function sat_descargar_recibidos(SatScraper $scraper, string $desde, string $hasta): array
{
    $query = new QueryByFilters(
        new DateTimeImmutable($desde),
        new DateTimeImmutable($hasta),
        DownloadType::recibidos()
    );

    $lista = $scraper->listByPeriod($query);
    sat_log("listByPeriod OK - CFDI encontrados: " . count($lista) . " (desde=$desde hasta=$hasta)");

    $rel = 'uploads/facturas_sat/' . date('Y-m');
    $dir = __DIR__ . '/../' . $rel;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $scraper->resourceDownloader(ResourceType::xml(), $lista, 10)->saveTo($dir, true, 0777);
    $scraper->resourceDownloader(ResourceType::pdf(), $lista, 10)->saveTo($dir, true, 0777);
    sat_log("Descarga XML/PDF completada en $dir");

    $resultados = [];
    foreach ($lista as $metadata) {
        $m = $metadata->getData();
        $uuid = $m['uuid'] ?? '';
        $m['archivo_xml'] = $uuid !== '' ? $rel . '/' . $uuid . '.xml' : '';
        $m['archivo_pdf'] = $uuid !== '' ? $rel . '/' . $uuid . '.pdf' : '';
        $resultados[] = $m;
    }
    return $resultados;
}

/**
 * Registra un CFDI descargado en la tabla sat_cfdi evitando duplicados.
 * Devuelve true si se inserto, false si el UUID ya existia.
 */
function sat_registrar_cfdi(array $m, string $archivo, string $archivoPdf = ''): bool
{
    global $pdo;
    $uuid = $m['uuid'] ?? '';
    if ($uuid === '') {
        return false;
    }
    $existe = $pdo->prepare("SELECT COUNT(*) FROM sat_cfdi WHERE uuid=?");
    $existe->execute([$uuid]);
    if ((int)$existe->fetchColumn() > 0) {
        return false;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO sat_cfdi (uuid, rfc_emisor, nombre_emisor, rfc_receptor, nombre_receptor, fecha_emision, total, estado, archivo, archivo_pdf, usuario_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    );
    $fecha = substr((string)($m['fechaEmision'] ?? ''), 0, 10);
    $fecha = $fecha !== '' ? $fecha : null;
    $stmt->execute([
        $uuid,
        $m['rfcEmisor'] ?? '',
        $m['nombreEmisor'] ?? '',
        $m['rfcReceptor'] ?? '',
        $m['nombreReceptor'] ?? '',
        $fecha,
        isset($m['total']) ? (float)$m['total'] : 0,
        $m['estadoComprobante'] ?? '',
        $archivo,
        $archivoPdf,
        $_SESSION['usuario_id'] ?? null,
    ]);
    return true;
}

/**
 * Busca una compra candidata para vincular un CFDI.
 * Devuelve el id de la compra o null si no hay match confiable.
 */
function sat_buscar_compra(string $rfcEmisor, string $fechaEmision, float $total): ?int
{
    global $pdo;
    if ($rfcEmisor === '') {
        return null;
    }
    $prov = $pdo->prepare("SELECT id FROM proveedores WHERE rfc=? AND activo=1 LIMIT 1");
    $prov->execute([$rfcEmisor]);
    $proveedor = $prov->fetch();
    if (!$proveedor) {
        return null;
    }

    $mes = substr((string)$fechaEmision, 0, 7);
    $sql = "SELECT id FROM compras WHERE proveedor_id=? AND estatus!='cancelada' AND DATE_FORMAT(fecha_compra,'%Y-%m')=?";
    $params = [$proveedor['id'], $mes];

    // Match adicional por monto del total de la compra
    $sql .= " AND ABS(COALESCE(total,0) - ?) < 0.01";
    $params[] = $total;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidatas = $stmt->fetchAll();

    if (count($candidatas) === 1) {
        return (int)$candidatas[0]['id'];
    }
    // Si hay varias del mismo monto y mes, preferir la mas reciente sin factura
    if (count($candidatas) > 1) {
        $ids = array_column($candidatas, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $fact = $pdo->prepare("SELECT compra_id FROM facturas WHERE compra_id IN ($in)");
        $fact->execute($ids);
        $conFactura = array_map('intval', $fact->fetchAll(PDO::FETCH_COLUMN));
        $sinFactura = array_values(array_filter($ids, fn($id) => !in_array((int)$id, $conFactura, true)));
        if (count($sinFactura) === 1) {
            return (int)$sinFactura[0];
        }
    }
    return null;
}

/**
 * Inserta el CFDI en la tabla facturas vinculado a una compra
 * (misma estructura que la importacion manual de compras.php).
 */
function sat_vincular_a_compra(array $m, int $compraId, string $archivo): bool
{
    global $pdo;
    $existe = $pdo->prepare("SELECT COUNT(*) FROM facturas WHERE uuid=?");
    $existe->execute([$m['uuid'] ?? '']);
    if ((int)$existe->fetchColumn() > 0) {
        return false;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO facturas (compra_id, folio_factura, uuid, rfc_emisor, rfc_receptor, monto, fecha_factura, archivo)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $fecha = substr((string)($m['fechaEmision'] ?? ''), 0, 10);
    $stmt->execute([
        $compraId,
        '',
        $m['uuid'] ?? '',
        $m['rfcEmisor'] ?? '',
        $m['rfcReceptor'] ?? '',
        isset($m['total']) ? (float)$m['total'] : 0,
        $fecha !== '' ? $fecha : null,
        $archivo,
    ]);
    return true;
}

/**
 * Resolvedor que nunca resuelve: previene que el login interno del SAT
 * consuma el captcha automaticamente (el flujo web lo resuelve a mano).
 */
class SatCaptchaNuncaResolver implements \PhpCfdi\ImageCaptchaResolver\CaptchaResolverInterface
{
    public function resolve(\PhpCfdi\ImageCaptchaResolver\CaptchaImageInterface $image): \PhpCfdi\ImageCaptchaResolver\CaptchaAnswerInterface
    {
        throw new \PhpCfdi\ImageCaptchaResolver\UnableToResolveCaptchaException($this, $image);
    }
}
