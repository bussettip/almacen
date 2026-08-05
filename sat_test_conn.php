<?php
// Diagnostico de conectividad con el SAT desde este servidor.
header('Content-Type: text/plain; charset=utf-8');
echo "IP saliente: ";
$ip = @file_get_contents('https://api.ipify.org');
echo $ip !== false ? $ip : '(no disponible)';
echo PHP_EOL . PHP_EOL;

$urls = [
    'Login SAT' => 'https://cfdiau.sat.gob.mx/nidp/app/login?id=SATUPCFDiCon&sid=0&option=credential&sid=0',
];

foreach ($urls as $nombre => $url) {
    echo "== $nombre ==" . PHP_EOL;
    echo "URL: $url" . PHP_EOL;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ],
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP: " . $code . PHP_EOL;
    if ($resp === false) {
        echo "ERROR curl: " . $err . PHP_EOL;
    } else {
        echo "Largo respuesta: " . strlen($resp) . " bytes" . PHP_EOL;
        echo "Tiene divCaptcha: " . (stripos($resp, 'divCaptcha') !== false ? 'SI' : 'NO') . PHP_EOL;
        if (stripos($resp, 'divCaptcha') === false) {
            $txt = trim(strip_tags($resp));
            $txt = preg_replace('/\s+/', ' ', $txt);
            echo "Contenido (primeros 400): " . mb_substr($txt, 0, 400) . PHP_EOL;
        }
    }
    echo PHP_EOL;
}
