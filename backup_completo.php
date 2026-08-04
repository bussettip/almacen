<?php
require_once __DIR__ . '/includes/config.php';

$backup_dir = '/var/www/html/backups';
$timestamp = date('Y-m-d_H-i-s');

if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// --- 1. Backup DB ---
$db_file = "$backup_dir/db_control_almacenes_$timestamp.sql";
$dbh = getDB();

$fh = fopen($db_file, 'w');
fwrite($fh, "-- Backup Control de Almacenes\n");
fwrite($fh, "-- Fecha: " . date('Y-m-d H:i:s') . "\n");
fwrite($fh, "-- ===========================================\n\n");
fwrite($fh, "CREATE DATABASE IF NOT EXISTS `control_almacenes` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n");
fwrite($fh, "USE `control_almacenes`;\n\n");
fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

$dbh->exec("USE control_almacenes");

$tables = $dbh->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    fwrite($fh, "-- Tabla: $table\n");
    $create = $dbh->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
    fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");
    fwrite($fh, $create['Create Table'] . ";\n\n");

    $rows = $dbh->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 0) {
        $columns = array_keys($rows[0]);
        foreach ($rows as $row) {
            $vals = array_map(function($v) use ($dbh) {
                return $v === null ? 'NULL' : $dbh->quote($v);
            }, array_values($row));
            fwrite($fh, "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $vals) . ");\n");
        }
        fwrite($fh, "\n");
    }
}

fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
fwrite($fh, "-- FIN DB\n");
fclose($fh);

echo "✔ DB: " . filesize($db_file) . " bytes\n";

// --- 2. Backup archivos web (excluyendo backups) ---
$web_file = "$backup_dir/web_control_almacenes_$timestamp.tar.gz";
$source = '/var/www/html';

// Usar php tar si no hay tar command
exec("tar czf $web_file --exclude='backups' --exclude='backup*.php' -C " . dirname($source) . " " . basename($source) . " 2>&1", $output, $code);

if ($code === 0) {
    echo "✔ Web: " . filesize($web_file) . " bytes\n";
} else {
    echo "⚠ Web tar fallo: " . implode("\n", $output) . "\n";
}

// --- 3. Resumen ---
echo "\n=== BACKUP COMPLETO ===\n";
echo "Destino: $backup_dir/\n";
echo " DB: db_control_almacenes_$timestamp.sql\n";
echo " WEB: web_control_almacenes_$timestamp.tar.gz\n";
echo "========================\n";
echo "Para descargar:\n";
echo " https://almacen.bussettip.com.mx/backups/db_control_almacenes_$timestamp.sql\n";
echo " https://almacen.bussettip.com.mx/backups/web_control_almacenes_$timestamp.tar.gz\n";
