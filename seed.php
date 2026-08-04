<?php
// ============================================================
// CARGA MASIVA DE DATOS DE EJEMPLO
// ============================================================

require 'config.php';

echo "=== Cargando datos de ejemplo ===\n\n";

try {
    $pdo->beginTransaction();

    // Limpiar datos existentes (fuera de la transaccion, DELETE es DML y funciona)
    $pdo->exec("DELETE FROM movimientos");
    $pdo->exec("DELETE FROM inventario");
    $pdo->exec("DELETE FROM ordenes_compra_detalle");
    $pdo->exec("DELETE FROM ordenes_compra");
    $pdo->exec("DELETE FROM ubicaciones");
    $pdo->exec("DELETE FROM productos");
    $pdo->exec("DELETE FROM categorias");
    $pdo->exec("DELETE FROM proveedores");
    $pdo->exec("DELETE FROM almacenes");
    // Resetear auto_increment
    $tables = ['almacenes','categorias','proveedores','productos','ubicaciones','ordenes_compra','ordenes_compra_detalle','movimientos'];
    foreach ($tables as $t) $pdo->exec("ALTER TABLE $t AUTO_INCREMENT = 1");

    $pdo->beginTransaction();

    // ============================================================
    // 1. ALMACENES
    // ============================================================
    $almacenes = [
        ['ALM-001', 'Almacen Central', 'Av. Reforma 123', 'Ciudad de Mexico', 'CDMX', '5551234567'],
        ['ALM-002', 'Almacen Norte', 'Carretera Monterrey 500', 'Monterrey', 'Nuevo Leon', '8187654321'],
        ['ALM-003', 'Almacen Occidente', 'Av. Vallarta 2000', 'Guadalajara', 'Jalisco', '3334567890'],
        ['ALM-004', 'Almacen Bajio', 'Blvd. Aeropuerto 150', 'Leon', 'Guanajuato', '4771230987'],
        ['ALM-005', 'Almacen Sureste', 'Periferico 789', 'Merida', 'Yucatan', '9995672345'],
    ];
    $stmt_a = $pdo->prepare("INSERT INTO almacenes (codigo, nombre, direccion, ciudad, estado, telefono, activo) VALUES (?,?,?,?,?,?,1)");
    foreach ($almacenes as $a) $stmt_a->execute($a);
    echo "✓ 5 almacenes\n";

    // ============================================================
    // 2. CATEGORIAS (jerarquicas)
    // ============================================================
    $cats = [
        [1, 'Electronicos', null],
        [2, 'Computo', 1],
        [3, 'Audio y Video', 1],
        [4, 'Herramientas', null],
        [5, 'Herramientas Electricas', 4],
        [6, 'Herramientas Manuales', 4],
        [7, 'Material de Oficina', null],
        [8, 'Papeleria', 7],
        [9, 'Mobiliario', 7],
        [10, 'Seguridad', null],
        [11, 'Camaras', 10],
        [12, 'Alertas', 10],
        [13, 'Refacciones Industriales', null],
        [14, 'Ferreteria', 4],
    ];
    $stmt_c = $pdo->prepare("INSERT INTO categorias (id, nombre, padre_id) VALUES (?,?,?)");
    foreach ($cats as $c) $stmt_c->execute($c);
    // Reseteamos auto_increment
    echo "✓ " . count($cats) . " categorias\n";

    // ============================================================
    // 3. PROVEEDORES
    // ============================================================
    $proveedores = [
        ['PROV-001', 'Distribuidora Tecnologica SA de CV', 'Carlos Lopez', '5551112233', 'ventas@ditech.mx', 'Av. Insurgentes 500, CDMX'],
        ['PROV-002', 'Herramientas del Norte SAPI', 'Maria Garcia', '8182223344', 'ventas@hernorte.mx', 'Av. Constitucion 300, Monterrey'],
        ['PROV-003', 'Office Depot Mexico', NULL, '8001234567', 'ventas@officedepot.mx', 'Carretera Mexico-Toluca 4000'],
        ['PROV-004', 'Seguridad Integral SA', 'Roberto Martinez', '5553334455', 'ventas@seguridadintegral.mx', 'Calz. Ignacio Zaragoza 800, CDMX'],
        ['PROV-005', 'Importadora Asia-Pacifico', 'Li Wei', '8184445566', 'ventas@asiapac.mx', 'Av. Industrial 1200, Apodaca NL'],
        ['PROV-006', 'Ferreteria y Mas SA', 'Jose Hernandez', '5555556677', 'ventas@ferremas.mx', 'Eje Central 200, CDMX'],
        ['PROV-007', 'Tecnologias del Bajio', 'Ana Padilla', '4777778899', 'ventas@tecbajio.mx', 'Blvd. Torres 789, Leon Gto'],
    ];
    $stmt_p = $pdo->prepare("INSERT INTO proveedores (rfc, nombre, contacto, telefono, email, direccion, activo) VALUES (?,?,?,?,?,?,1)");
    foreach ($proveedores as $p) $stmt_p->execute($p);
    echo "✓ " . count($proveedores) . " proveedores\n";

    // ============================================================
    // 4. PRODUCTOS
    // ============================================================
    $productos = [
        ['LCM-001', 'Laptop HP ProBook 450 G10', 2, 1, 12500.00, 16999.99, 3, 30],
        ['LCM-002', 'Laptop Dell Latitude 3540', 2, 1, 11800.00, 15999.99, 3, 25],
        ['MON-001', 'Monitor Samsung 27" 4K', 2, 1, 4200.00, 5899.99, 5, 40],
        ['MON-002', 'Monitor LG 24" FullHD', 2, 1, 2200.00, 3299.99, 5, 50],
        ['TCL-001', 'Teclado Logitech K120 USB', 2, 1, 180.00, 349.99, 10, 100],
        ['TCL-002', 'Teclado Inalambrico Logitech K580', 2, 1, 450.00, 749.99, 5, 60],
        ['MOU-001', 'Mouse Logitech M190', 2, 1, 220.00, 399.99, 10, 80],
        ['MOU-002', 'Mouse Inalambrico Microsoft', 2, 1, 280.00, 499.99, 10, 70],
        ['AUD-001', 'Audifonos Sony WH-1000XM5', 3, 1, 3200.00, 4999.99, 2, 20],
        ['AUD-002', 'Bocina JBL Flip 6', 3, 1, 1500.00, 2499.99, 3, 25],
        ['TLD-001', 'Taladro DeWalt 18V 1/2"', 5, 1, 1800.00, 2799.99, 3, 20],
        ['TLD-002', 'Taladro Bosch Professional GSB 18V', 5, 1, 2500.00, 3899.99, 2, 15],
        ['SIER-001', 'Sierra Circular DeWalt 7-1/4"', 5, 1, 2200.00, 3499.99, 2, 15],
        ['ESM-001', 'Esmeril Angular Bosch 4-1/2"', 5, 1, 950.00, 1499.99, 5, 30],
        ['MART-001', 'Martillo de Una 16oz', 6, 1, 120.00, 219.99, 15, 80],
        ['DES-001', 'Juego de Desarmadores 6pzas', 6, 1, 150.00, 289.99, 10, 60],
        ['PIN-001', 'Pinza de Corte 7"', 6, 1, 180.00, 329.99, 10, 50],
        ['CIN-001', 'Cinta Metrica 5m Stanley', 6, 1, 95.00, 179.99, 15, 100],
        ['PAP-001', 'Resma Papel Carta Office Depot', 8, 2, 75.00, 135.00, 30, 300],
        ['PAP-002', 'Resma Papel Bond Oficio', 8, 2, 85.00, 155.00, 20, 200],
        ['LAP-001', 'Lapiz HB Caja 12pzas', 8, 1, 25.00, 55.00, 30, 200],
        ['PLU-001', 'Plumones para Pizarron 4 colores', 8, 1, 35.00, 69.99, 20, 150],
        ['FOLD-001', 'Folders T/Carta Caja 50pzas', 8, 1, 45.00, 89.99, 15, 100],
        ['SIL-001', 'Silla Ejecutiva Tela Negra', 9, 1, 1800.00, 2999.99, 2, 20],
        ['ESC-001', 'Escritorio Metalico 1.50m', 9, 1, 2500.00, 3999.99, 2, 15],
        ['ARCH-001', 'Archivero Metalico 3 Gavetas', 9, 1, 3200.00, 4999.99, 2, 10],
        ['CAM-001', 'Camara Dahua IP 2MP Exterior', 11, 1, 850.00, 1299.99, 5, 40],
        ['CAM-002', 'Camara Hikvision PTZ 4MP', 11, 1, 3500.00, 5299.99, 2, 15],
        ['DVR-001', 'DVR Hikvision 8 Canales', 11, 1, 2200.00, 3399.99, 3, 20],
        ['SEN-001', 'Sensor de Movimiento Infrarrojo', 12, 1, 180.00, 329.99, 10, 80],
        ['ALM-001', 'Alarma Sismica Digital', 12, 1, 450.00, 749.99, 5, 30],
        ['BAL-001', 'Balasto Electrico 400W', 13, 1, 320.00, 549.99, 5, 40],
        ['FOC-001', 'Foco LED Industrial 100W', 13, 1, 85.00, 159.99, 10, 100],
        ['CBL-001', 'Cable THW #12 100m', 14, 4, 450.00, 699.99, 5, 30],
        ['CBL-002', 'Cable UTP Cat6 305m', 14, 4, 1800.00, 2799.99, 2, 15],
        ['TUB-001', 'Tubo Conduit 1/2" 3m', 14, 1, 65.00, 129.99, 20, 150],
        ['CNT-001', 'Contactor Electrico 40A', 13, 1, 280.00, 489.99, 5, 40],
        ['TER-001', 'Termostato Industrial', 13, 1, 550.00, 899.99, 3, 20],
    ];
    $stmt_pr = $pdo->prepare("INSERT INTO productos (codigo, nombre, categoria_id, unidad_medida_id, precio_compra, precio_venta, stock_minimo, stock_maximo, activo) VALUES (?,?,?,?,?,?,?,?,1)");
    foreach ($productos as $pr) $stmt_pr->execute($pr);
    echo "✓ " . count($productos) . " productos\n";

    // ============================================================
    // 5. UBICACIONES (jerarquia por almacen)
    // ============================================================
    try {
        for ($a_id = 1; $a_id <= 5; $a_id++) {
            $zona = "ZONA-$a_id";
            $pdo->prepare("INSERT INTO ubicaciones (almacen_id, codigo, descripcion, tipo, activo) VALUES (?,?,?,?,1)")
                ->execute([$a_id, $zona, "Zona principal almacen $a_id", 'zona']);
            $zona_id = $pdo->lastInsertId();

            for ($r = 1; $r <= 4; $r++) {
                $rack = "RACK-{$a_id}-$r";
                $pdo->prepare("INSERT INTO ubicaciones (almacen_id, codigo, descripcion, tipo, padre_id, activo) VALUES (?,?,?,?,?,1)")
                    ->execute([$a_id, $rack, "Rack $r", 'rack', $zona_id]);
                $rack_id = $pdo->lastInsertId();

                for ($e = 1; $e <= 3; $e++) {
                    $estante = "EST-{$a_id}-{$r}-{$e}";
                    $pdo->prepare("INSERT INTO ubicaciones (almacen_id, codigo, descripcion, tipo, padre_id, activo) VALUES (?,?,?,?,?,1)")
                        ->execute([$a_id, $estante, "Estante $e del rack $r", 'estante', $rack_id]);
                }
            }
        }
        echo "✓ 5 zonas, 20 racks, 60 estantes\n";
    } catch (Exception $e) {
        echo " Error en ubicaciones: " . $e->getMessage() . "\n";
        throw $e;
    }

    // ============================================================
    // 6. INVENTARIO INICIAL (3 almacenes principales)
    // ============================================================
    // Mapa de producto_id segun el orden de insercion
    $prod_map = []; $stmt_pm = $pdo->query("SELECT id, codigo FROM productos ORDER BY id");
    foreach ($stmt_pm as $pr) $prod_map[$pr['codigo']] = $pr['id'];

    $inventario_inicial = [
        // Almacen 1 (Central) - 20 productos
        [1, $prod_map['LCM-001'], 'LOTE-A001', '2027-06-01', 12],
        [1, $prod_map['LCM-002'], 'LOTE-A002', '2027-05-01', 8],
        [1, $prod_map['MON-001'], null, null, 15],
        [1, $prod_map['MON-002'], null, null, 22],
        [1, $prod_map['TCL-001'], null, null, 45],
        [1, $prod_map['MOU-001'], null, null, 60],
        [1, $prod_map['AUD-001'], null, null, 5],
        [1, $prod_map['TLD-001'], 'LOTE-H001', '2028-01-01', 10],
        [1, $prod_map['PAP-001'], 'PAP-2026', null, 150],
        [1, $prod_map['FOLD-001'], null, null, 80],
        [1, $prod_map['CAM-001'], 'CAM-2026A', null, 25],
        [1, $prod_map['SEN-001'], null, null, 55],
        [1, $prod_map['ALM-001'], null, null, 18],
        [1, $prod_map['FOC-001'], null, null, 75],
        [1, $prod_map['CBL-001'], 'CBL-001A', null, 20],
        [1, $prod_map['CBL-002'], 'CBL-002B', null, 8],
        [1, $prod_map['CNT-001'], null, null, 22],
        [1, $prod_map['TER-001'], null, null, 10],
        [1, $prod_map['CIN-001'], null, null, 65],
        [1, $prod_map['DES-001'], null, null, 35],
        // Almacen 2 (Norte) - 15 productos
        [2, $prod_map['LCM-001'], 'LOTE-N001', '2027-04-01', 6],
        [2, $prod_map['MON-002'], null, null, 18],
        [2, $prod_map['TCL-002'], null, null, 30],
        [2, $prod_map['AUD-002'], null, null, 12],
        [2, $prod_map['TLD-001'], 'LOTE-NH01', '2028-03-01', 8],
        [2, $prod_map['SIER-001'], 'LOTE-NH02', '2028-02-01', 5],
        [2, $prod_map['MART-001'], null, null, 40],
        [2, $prod_map['PIN-001'], null, null, 30],
        [2, $prod_map['PAP-001'], 'PAP-2026N', null, 100],
        [2, $prod_map['PLU-001'], null, null, 80],
        [2, $prod_map['CAM-001'], 'CAM-2026B', null, 15],
        [2, $prod_map['BAL-001'], null, null, 20],
        [2, $prod_map['CBL-001'], 'CBL-N001', null, 12],
        [2, $prod_map['TUB-001'], null, null, 80],
        [2, $prod_map['SIL-001'], null, null, 4],
        // Almacen 3 (Occidente) - 12 productos
        [3, $prod_map['LCM-001'], 'LOTE-O001', '2027-08-01', 4],
        [3, $prod_map['MON-001'], null, null, 10],
        [3, $prod_map['MOU-002'], null, null, 35],
        [3, $prod_map['TLD-001'], 'LOTE-OH01', '2028-01-01', 6],
        [3, $prod_map['TLD-002'], 'LOTE-OH02', '2028-06-01', 4],
        [3, $prod_map['PAP-001'], 'PAP-2026O', null, 200],
        [3, $prod_map['LAP-001'], null, null, 120],
        [3, $prod_map['ARCH-001'], null, null, 5],
        [3, $prod_map['CAM-002'], 'CAM-O001', '2026-12-01', 3],
        [3, $prod_map['DVR-001'], 'DVR-O001', null, 8],
        [3, $prod_map['CBL-002'], 'CBL-O001', null, 5],
        [3, $prod_map['TER-001'], null, null, 8],
    ];

    $stmt_i = $pdo->prepare("INSERT INTO inventario (almacen_id, producto_id, lote, fecha_caducidad, cantidad) VALUES (?,?,?,?,?)");
    foreach ($inventario_inicial as $inv) $stmt_i->execute($inv);
    echo "✓ " . count($inventario_inicial) . " registros de inventario\n";

    // ============================================================
    // 7. MOVIMIENTOS (registro historico)
    // ============================================================
    $movs = [
        // Entradas de compra
        ['ENTRADA', 'ENT-20260601-A1', 1, null, 'LCM-001', 12, 'OC-001', 'Compra inicial Laptops'],
        ['ENTRADA', 'ENT-20260601-A2', 1, null, 'MON-001', 15, 'OC-001', 'Compra inicial Monitores'],
        ['ENTRADA', 'ENT-20260601-A3', 1, null, 'TLD-001', 10, 'OC-002', 'Compra inicial Taladros'],
        ['ENTRADA', 'ENT-20260601-A4', 1, null, 'PAP-001', 150, 'OC-003', 'Compra inicial Papel'],
        ['ENTRADA', 'ENT-20260601-A5', 1, null, 'CAM-001', 25, 'OC-004', 'Compra inicial Camaras'],
        ['ENTRADA', 'ENT-20260602-N1', 2, null, 'LCM-001', 6, 'OC-005', 'Compra sucursal Norte'],
        ['ENTRADA', 'ENT-20260602-O1', 3, null, 'LCM-001', 4, 'OC-006', 'Compra sucursal Occidente'],
        // Salidas (ventas / consumo)
        ['SALIDA', 'SAL-20260603-1', null, 1, 'LCM-001', 2, 'VTA-001', 'Venta mostrador'],
        ['SALIDA', 'SAL-20260603-2', null, 1, 'PAP-001', 20, 'VTA-002', 'Venta oficina'],
        ['SALIDA', 'SAL-20260603-3', null, 1, 'CAM-001', 3, 'VTA-003', 'Venta instalacion'],
        ['SALIDA', 'SAL-20260604-1', null, 1, 'TLD-001', 1, 'VTA-004', 'Venta a contratista'],
        ['SALIDA', 'SAL-20260604-2', null, 1, 'MON-002', 10, 'VTA-005', 'Venta mayoreo'],
        ['SALIDA', 'SAL-20260605-1', null, 2, 'PAP-001', 30, 'VTA-006', 'Consumo interno Admin'],
        // Transferencias
        ['TRANSFERENCIA', 'TRF-20260605-1', 1, 2, 'LCM-001', 2, 'TRF-001', 'Reabastecer Norte'],
        ['TRANSFERENCIA', 'TRF-20260606-1', 1, 3, 'TLD-001', 2, 'TRF-002', 'Reabastecer Occidente'],
        ['TRANSFERENCIA', 'TRF-20260606-2', 2, 3, 'CAM-001', 3, 'TRF-003', 'Cobertura proyecto Merida'],
    ];

    $tipos_mov = $pdo->query("SELECT codigo, id FROM tipos_movimiento")->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt_m = $pdo->prepare("INSERT INTO movimientos (tipo_movimiento_id, folio, almacen_origen_id, almacen_destino_id, producto_id, cantidad, referencia, observaciones, usuario_id, created_at) VALUES (?,?,?,?,?,?,?,?,1, DATE_SUB(NOW(), INTERVAL ? DAY))");

    foreach ($movs as $i => $m) {
        $dias_atras = count($movs) - $i;
        $stmt_m->execute([
            $tipos_mov[$m[0]],
            $m[1],
            $m[2] ?: null,
            $m[3] ?: null,
            $prod_map[$m[4]],
            $m[5],
            $m[6],
            $m[7],
            $dias_atras
        ]);
    }
    echo "✓ " . count($movs) . " movimientos historicos\n";

    // ============================================================
    // 8. ORDENES DE COMPRA
    // ============================================================
    $ordenes = [
        ['OC-20260601', 1, 1, '2026-06-15', 'recibida', 265400.00],
        ['OC-20260602', 2, 2, '2026-06-20', 'recibida', 75000.00],
        ['OC-20260603', 3, 1, '2026-06-18', 'recibida', 11250.00],
        ['OC-20260604', 4, 1, '2026-06-25', 'recibida', 21250.00],
        ['OC-20260605', 5, 2, '2026-06-22', 'recibida', 70800.00],
        ['OC-20260606', 3, 3, '2026-06-28', 'recibida', 47200.00],
        ['OC-20260610', 1, 4, '2026-07-01', 'pendiente', 0],
        ['OC-20260611', 6, 5, '2026-07-05', 'pendiente', 0],
    ];

    $stmt_oc = $pdo->prepare("INSERT INTO ordenes_compra (folio, proveedor_id, almacen_id, fecha_orden, fecha_entrega, estatus, total, usuario_id) VALUES (?,?,?, CURDATE(), ?, ?, ?, 1)");
    try {
        foreach ($ordenes as $oc) $stmt_oc->execute($oc);
        echo "✓ " . count($ordenes) . " ordenes de compra\n";
    } catch (Exception $e) {
        echo " Error en ordenes: " . $e->getMessage() . "\n";
        throw $e;
    }

    $pdo->commit();
    echo "\n[OK] ¡DATOS CARGADOS EXITOSAMENTE!\n\n";

} catch (Exception $e) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Exception $x) {}
    echo " Error: " . $e->getMessage() . "\n";
}
