<?php
// Asigna imagenes placeholder a productos sin imagen
$host = getenv('DB_HOST') ?: 'mysql_db1';
$db   = getenv('DB_NAME') ?: 'control_almacenes';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'Corporativo.2012';
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$productos = $pdo->query("SELECT id, codigo FROM productos WHERE imagen IS NULL OR imagen = ''")->fetchAll();
if (empty($productos)) { echo "Todos los productos ya tienen imagen.\n"; exit; }

$cols = ['#5b9bd5','#70ad47','#ed7d31','#ffc000','#4472c4','#a5a5a5','#264478','#9b59b6','#e74c3c','#1abc9c','#f39c12','#2ecc71'];
$dir = __DIR__ . '/uploads/productos';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$updated = 0;
foreach ($productos as $p) {
    $nom_archivo = 'placeholder_' . $p['id'] . '.png';
    $ruta = $dir . '/' . $nom_archivo;

    $size = 300;
    $img = imagecreatetruecolor($size, $size);
    $color_idx = abs(crc32($p['codigo'])) % count($cols);
    $bg = hex2rgb($cols[$color_idx]);
    $bg_color = imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);
    imagefill($img, 0, 0, $bg_color);

    $fg_color = imagecolorallocate($img, 255, 255, 255);
    $font_size = 5;
    $text = $p['codigo'];
    $tw = imagefontwidth($font_size) * strlen($text);
    $th = imagefontheight($font_size);
    $x = ($size - $tw) / 2;
    $y = ($size - $th) / 2;
    imagestring($img, $font_size, $x, $y, $text, $fg_color);

    imagepng($img, $ruta);
    imagedestroy($img);

    $stmt = $pdo->prepare("UPDATE productos SET imagen = ? WHERE id = ?");
    $stmt->execute([$nom_archivo, $p['id']]);
    $updated++;
    echo "Producto #{$p['id']} ({$p['codigo']}): $nom_archivo\n";
}

echo "\n$updated producto(s) actualizados con imagen placeholder.\n";

function hex2rgb($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) == 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}
