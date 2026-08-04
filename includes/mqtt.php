<?php
function mqttPublish($topic, $payload) {
    $host = getenv('MQTT_HOST') ?: 'localhost';
    $port = getenv('MQTT_PORT') ?: 1883;
    $user = getenv('MQTT_USER') ?: '';
    $pass = getenv('MQTT_PASS') ?: '';
    $prefix = getenv('MQTT_PREFIX') ?: 'almacen/';
    $fullTopic = $prefix . $topic;

    $cmd = "mosquitto_pub -h " . escapeshellarg($host) . " -p " . (int)$port;
    if ($user) $cmd .= " -u " . escapeshellarg($user);
    if ($pass) $cmd .= " -P " . escapeshellarg($pass);
    $cmd .= " -t " . escapeshellarg($fullTopic);
    $cmd .= " -m " . escapeshellarg(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $cmd .= " -r"; // retained
    $cmd .= " 2>/dev/null";

    $out = null; $code = null;
    exec($cmd, $out, $code);
    return $code === 0;
}

function mqttPublishSurtir($tipo, $ordenId, $folio, $empleadoId, $empleadoNombre, $productos, $accion) {
    $payload = [
        'accion' => $accion,
        'tipo' => $tipo,
        'folio' => $folio,
        'orden_id' => $ordenId,
        'empleado_id' => $empleadoId,
        'empleado' => $empleadoNombre,
        'productos' => $productos,
        'timestamp' => date('c'),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    @file_put_contents(__DIR__ . '/../surtir_ultimo.json', $json);
    $seguro = preg_replace('/[^A-Za-z0-9_-]/', '_', "{$tipo}_{$folio}");
    @file_put_contents(__DIR__ . "/../surtir_{$seguro}.json", $json);
    // Publicar a topico general y a topico por empleado
    mqttPublish("surtir/{$tipo}/{$folio}", $payload);
    mqttPublish("surtir/empleado/{$empleadoId}", $payload);
}
