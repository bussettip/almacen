<?php
// Script de instalacion: crea usuario admin por defecto
$host = getenv('DB_HOST') ?: 'mysql_db1';
$db   = getenv('DB_NAME') ?: 'control_almacenes';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'Corporativo.2012';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Crear roles base si no existen
    $pdo->exec("INSERT IGNORE INTO roles (id, nombre) VALUES (1, 'admin'), (2, 'gerente'), (3, 'almacenista'), (4, 'consulta'), (5, 'cliente'), (6, 'almacen'), (7, 'comercial'), (8, 'internacional')");

    // Crear tipos de movimiento base
    $pdo->exec("INSERT IGNORE INTO tipos_movimiento (id, codigo, nombre, signo) VALUES
        (1, 'ENTRADA', 'Entrada', 1),
        (2, 'SALIDA', 'Salida', -1),
        (3, 'TRANSFERENCIA', 'Transferencia', 0),
        (4, 'AJUSTE', 'Ajuste de inventario', 0),
        (5, 'DEVOLUCION', 'Devolucion', 1)");

    // Crear unidades de medida base
    $pdo->exec("INSERT IGNORE INTO unidades_medida (id, codigo, nombre) VALUES
        (1, 'pza', 'Pieza'),
        (2, 'kg', 'Kilogramo'),
        (3, 'm', 'Metro'),
        (4, 'l', 'Litro'),
        (5, 'caja', 'Caja'),
        (6, 'par', 'Par'),
        (7, 'rollo', 'Rollo'),
        (8, 'm²', 'Metro cuadrado'),
        (9, 'g', 'Gramo'),
        (10, 'bolsa', 'Bolsa')");

    // Verificar si existe admin
    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE email='admin@almacenes.com'");
    if ($stmt->fetchColumn() == 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO usuarios (nombre, email, password_hash, rol_id, activo) VALUES (?, ?, ?, (SELECT id FROM roles WHERE nombre='admin'), 1)")
             ->execute(['Administrador', 'admin@almacenes.com', $hash]);
        echo "[OK] Usuario admin creado:\n";
        echo "   Email: admin@almacenes.com\n";
        echo "   Pass:  admin123\n\n";
    } else {
        echo "[i]  El usuario admin ya existe\n\n";
    }

    // Verificar si hay datos de ejemplo
    $stmt = $pdo->query("SELECT COUNT(*) FROM almacenes");
    if ($stmt->fetchColumn() == 0) {
        // Almacenes de ejemplo
        $pdo->exec("INSERT INTO almacenes (codigo, nombre, ciudad, estado) VALUES
            ('ALM-001', 'Almacen Central', 'Ciudad de Mexico', 'CDMX'),
            ('ALM-002', 'Almacen Norte', 'Monterrey', 'Nuevo Leon'),
            ('ALM-003', 'Almacen Occidente', 'Guadalajara', 'Jalisco')");

        // Categorias de ejemplo
        $pdo->exec("INSERT INTO categorias (nombre) VALUES
            ('Electronicos'), ('Herramientas'), ('Material de oficina'), ('Seguridad')");

        // Productos de ejemplo
        $pdo->exec("INSERT INTO productos (codigo, nombre, categoria_id, unidad_medida_id, precio_compra, precio_venta, stock_minimo, stock_maximo) VALUES
            ('PRO-001', 'Camara de seguridad HD', 4, 1, 850.00, 1299.99, 5, 50),
            ('PRO-002', 'Taladro inalambrico 18V', 2, 1, 1200.00, 1899.99, 3, 30),
            ('PRO-003', 'Resma de papel carta', 3, 1, 85.00, 145.00, 20, 200),
            ('PRO-004', 'Mouse optico USB', 1, 1, 95.00, 189.99, 10, 100),
            ('PRO-005', 'Teclado inalambrico', 1, 1, 250.00, 449.99, 10, 80)");

        echo "[OK] Datos de ejemplo insertados\n";
    }

    echo "\n🌐 Abre http://localhost/control-almacenes/ en tu navegador\n";

} catch (PDOException $e) {
    die(" Error: " . $e->getMessage());
}
