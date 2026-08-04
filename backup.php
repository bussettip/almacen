<?php
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/plain; charset=utf-8');

$dbh = getDB();

$filename = 'backup_control_almacenes_' . date('Y-m-d_H-i-s') . '.sql';

header('Content-Disposition: attachment; filename="' . $filename . '"');

echo "-- Backup Control de Almacenes\n";
echo "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "-- ===========================================\n\n";

echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

$tables = $dbh->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    echo "-- Estructura: $table\n";
    $create = $dbh->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
    echo $create['Create Table'] . ";\n\n";

    $rows = $dbh->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 0) {
        $columns = array_keys($rows[0]);
        foreach ($rows as $row) {
            $vals = array_map(function($v) use ($dbh) {
                return $v === null ? 'NULL' : $dbh->quote($v);
            }, array_values($row));
            echo "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $vals) . ");\n";
        }
        echo "\n";
    }
}

echo "SET FOREIGN_KEY_CHECKS = 1;\n";
echo "-- FIN\n";
