<?php
require_once __DIR__ . '/mqtt.php';

function getProductosSurtir($tipo, $id, $pdo) {
    $prods = [];
    if ($tipo === 'tienda') {
        $det = $pdo->prepare("SELECT d.cantidad, p.codigo, p.nombre, p.id as producto_id FROM ordenes_compra_detalle d JOIN productos p ON p.id=d.producto_id WHERE d.orden_compra_id=?");
        $det->execute([$id]);
        foreach ($det as $d) {
            $ubi = $pdo->prepare("SELECT u.codigo as ubicacion, ANY_VALUE(a.nombre) as almacen, SUM(i.cantidad) as stock FROM inventario i JOIN ubicaciones u ON u.id=i.ubicacion_id JOIN almacenes a ON a.id=i.almacen_id WHERE i.producto_id=? AND i.cantidad>0 GROUP BY i.ubicacion_id, u.codigo ORDER BY stock DESC");
            $ubi->execute([$d['producto_id']]);
            $ubicaciones = $ubi->fetchAll();
            $prods[] = [
                'codigo'=>$d['codigo'],
                'nombre'=>$d['nombre'],
                'cantidad'=>(float)$d['cantidad'],
                'ubicaciones'=>array_map(function($u){return ['codigo'=>$u['ubicacion'],'almacen'=>$u['almacen'],'stock'=>(float)$u['stock']];}, $ubicaciones),
            ];
        }
    } else {
        $det = $pdo->prepare("SELECT d.cantidad, p.codigo, p.nombre, p.id as producto_id FROM venta_detalle d JOIN productos p ON p.id=d.producto_id WHERE d.venta_id=?");
        $det->execute([$id]);
        foreach ($det as $d) {
            $ubi = $pdo->prepare("SELECT u.codigo as ubicacion, ANY_VALUE(a.nombre) as almacen, SUM(i.cantidad) as stock FROM inventario i JOIN ubicaciones u ON u.id=i.ubicacion_id JOIN almacenes a ON a.id=i.almacen_id WHERE i.producto_id=? AND i.cantidad>0 GROUP BY i.ubicacion_id, u.codigo ORDER BY stock DESC");
            $ubi->execute([$d['producto_id']]);
            $ubicaciones = $ubi->fetchAll();
            $prods[] = [
                'codigo'=>$d['codigo'],
                'nombre'=>$d['nombre'],
                'cantidad'=>(float)$d['cantidad'],
                'ubicaciones'=>array_map(function($u){return ['codigo'=>$u['ubicacion'],'almacen'=>$u['almacen'],'stock'=>(float)$u['stock']];}, $ubicaciones),
            ];
        }
    }
    return $prods;
}

function getFolio($tipo, $id, $pdo) {
    if ($tipo === 'tienda') {
        $r = $pdo->prepare("SELECT folio FROM ordenes_compra WHERE id=?");
        $r->execute([$id]); $r = $r->fetch();
        return $r ? $r['folio'] : '';
    }
    $r = $pdo->prepare("SELECT folio FROM ventas WHERE id=?");
    $r->execute([$id]); $r = $r->fetch();
    return $r ? $r['folio'] : '';
}

function getEmpleadoNombre($id, $pdo) {
    $r = $pdo->prepare("SELECT nombre, apellido FROM empleados WHERE id=?");
    $r->execute([$id]); $r = $r->fetch();
    return $r ? $r['nombre'].' '.$r['apellido'] : '';
}

function notificarResponsablesUbicacion($tipo, $ordenId, $folio, $productos, $pdo) {
    $notificados = [];
    foreach ($productos as $p) {
        $ubi = $pdo->prepare("SELECT u.id, u.codigo, u.responsable_id, e.nombre, e.apellido
                              FROM inventario i
                              JOIN ubicaciones u ON u.id=i.ubicacion_id
                              LEFT JOIN empleados e ON e.id=u.responsable_id
                              WHERE i.producto_id=? AND i.cantidad>0 AND u.responsable_id IS NOT NULL
                              GROUP BY u.id");
        $ubi->execute([$p['producto_id']]);
        foreach ($ubi as $u) {
            if (!isset($notificados[$u['responsable_id']])) {
                $notificados[$u['responsable_id']] = [
                    'id' => $u['responsable_id'],
                    'nombre' => $u['nombre'].' '.$u['apellido'],
                    'ubicaciones' => [],
                ];
            }
            $notificados[$u['responsable_id']]['ubicaciones'][$u['codigo']] = $u['codigo'];
        }
    }
    $first = true;
    foreach ($notificados as &$n) {
        $prodsFiltrados = [];
        foreach ($productos as $p) {
            $prodsFiltrados[] = [
                'codigo' => $p['codigo'],
                'nombre' => $p['nombre'],
                'cantidad' => $p['cantidad'],
            ];
        }
        // Auto-asignar el primer empleado responsable a la orden
        if ($first) {
            $first = false;
            if ($tipo === 'tienda') {
                try { $pdo->prepare("UPDATE ordenes_compra SET surtidor_id=?, surtir_estatus='en_surtido' WHERE id=? AND surtidor_id IS NULL")->execute([$n['id'], $ordenId]); } catch (Exception $e) {}
            } else {
                try { $pdo->prepare("UPDATE ventas SET surtidor_id=?, estatus=IF(estatus='pendiente','surtido',estatus) WHERE id=? AND surtidor_id IS NULL")->execute([$n['id'], $ordenId]); } catch (Exception $e) {}
            }
        }
        $payload = [
            'accion' => 'nueva_orden',
            'tipo' => $tipo,
            'folio' => $folio,
            'pedido' => $folio,
            'orden_id' => $ordenId,
            'empleado_id' => $n['id'],
            'empleado' => $n['nombre'],
            'ubicaciones' => array_values($n['ubicaciones']),
            'productos' => $prodsFiltrados,
            'timestamp' => date('c'),
        ];
        file_put_contents(__DIR__ . '/../surtir_ultimo.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        mqttPublish("surtir/empleado/{$n['id']}", $payload);
    }
}
