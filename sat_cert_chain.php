<?php
// Captura la cadena de certificados TLS del portal CFDI del SAT
// y la guarda en uploads/ca_sat.pem para usarla en la app.
header('Content-Type: text/plain; charset=utf-8');

$host = 'portalcfdi.facturaelectronica.sat.gob.mx';
$puerto = 443;

$ctx = stream_context_create([
    'ssl' => [
        'capture_peer_cert' => true,
        'capture_peer_cert_chain' => true,
        'allow_self_signed' => true,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'SNI_enabled' => true,
        'peer_name' => $host,
        'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
    ],
]);

$errno = 0;
$errstr = '';
$client = @stream_socket_client(
    "tls://$host:$puerto",
    $errno,
    $errstr,
    30,
    STREAM_CLIENT_CONNECT,
    $ctx
);

if (!$client) {
    echo "No se pudo conectar: [$errno] $errstr\n";
    exit;
}

$params = stream_context_get_params($client);
$chain = $params['options']['ssl']['peer_certificate_chain'] ?? [];

echo "Certificados en la cadena: " . count($chain) . "\n\n";

$pemTodas = '';
foreach ($chain as $i => $cert) {
    $info = openssl_x509_parse($cert);
    $subj = $info['subject']['CN'] ?? ($info['subject']['O'] ?? 'desconocido');
    $issuer = $info['issuer']['CN'] ?? ($info['issuer']['O'] ?? 'desconocido');
    $notAfter = date('Y-m-d', $info['validTo_time_t'] ?? 0);
    echo "Cert #$i:\n";
    echo "  Subject: $subj\n";
    echo "  Issuer : $issuer\n";
    echo "  Valido hasta: $notAfter\n";
    echo "  Self-signed: " . (($info['subject']['CN'] ?? '') === ($info['issuer']['CN'] ?? '') ? 'SI' : 'NO') . "\n\n";
    if (!openssl_x509_export($cert, $pem)) {
        echo "  (no se pudo exportar PEM)\n";
    }
    $pemTodas .= $pem . "\n";
}

// Guardar todas las CA (las que NO son el certificado del servidor) como bundle
$arch = __DIR__ . '/uploads/ca_sat.pem';
if (file_put_contents($arch, $pemTodas)) {
    echo "Cadena guardada en: uploads/ca_sat.pem (" . strlen($pemTodas) . " bytes)\n";
} else {
    echo "ERROR: no se pudo escribir uploads/ca_sat.pem\n";
}
