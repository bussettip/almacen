<?php
$pdo = new PDO("mysql:host=mysql_db1;dbname=control_almacenes;charset=utf8mb4","root","Corporativo.2012");
$pdo->exec("ALTER TABLE ordenes_compra MODIFY fecha_orden date NOT NULL DEFAULT (CURRENT_DATE)");
echo "OK";
