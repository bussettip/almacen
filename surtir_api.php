<?php
// API para que la app del telefono marque pedidos como surtidos/entregados
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/mqtt.php';
require_once __DIR__ . '/includes/surtir_common.php';

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Metodo no permitido']);
    exit;
}

// Leer JSON del body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'JSON invalido']);
    exit;
}

$accion = $input['accion'] ?? '';
$tipo = $input['tipo'] ?? '';
$folio = $input['folio'] ?? '';
$empleado_id = (int)($input['empleado_id'] ?? 0);
$productos = $input['productos'] ?? null; // array opcional de {codigo, cantidad_surtida}

if (!in_array($accion, ['surtido','entregado'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'accion invalida (surtido o entregado)']);
    exit;
}
if (!in_array($tipo, ['tienda','venta'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'tipo invalida (tienda o venta)']);
    exit;
}
if (!$folio || !$empleado_id) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'folio y empleado_id requeridos']);
    exit;
}

try {
    // Buscar la orden
    if ($tipo === 'tienda') {
        $r = $pdo->prepare("SELECT id, surtir_estatus, surtidor_id FROM ordenes_compra WHERE folio=?");
        $r->execute([$folio]);
        $orden = $r->fetch();
        if (!$orden) { echo json_encode(['ok'=>false,'error'=>'Orden no encontrada']); exit; }
        if ($orden['surtidor_id'] != $empleado_id) {
            echo json_encode(['ok'=>false,'error'=>'Esta orden no esta asignada a ti']);
            exit;
        }
        $orden_id = $orden['id'];

        if ($accion === 'surtido') {
            if ($orden['surtir_estatus'] === 'entregado') {
                echo json_encode(['ok'=>false,'error'=>'Ya esta entregado']);
                exit;
            }
            $pdo->prepare("UPDATE ordenes_compra SET surtir_estatus='surtido', fecha_surtido=CURDATE() WHERE id=?")->execute([$orden_id]);
            $pdo->prepare("UPDATE ordenes_compra_detalle SET cantidad_surtida=cantidad WHERE orden_compra_id=?")->execute([$orden_id]);
        } else {
            if ($orden['surtir_estatus'] !== 'surtido' && $orden['surtir_estatus'] !== 'en_surtido') {
                echo json_encode(['ok'=>false,'error'=>'Debe estar surtido primero']);
                exit;
            }
            $pdo->prepare("UPDATE ordenes_compra SET surtir_estatus='entregado' WHERE id=?")->execute([$orden_id]);
        }
    } else {
        $r = $pdo->prepare("SELECT id, estatus, surtidor_id FROM ventas WHERE folio=?");
        $r->execute([$folio]);
        $orden = $r->fetch();
        if (!$orden) { echo json_encode(['ok'=>false,'error'=>'Venta no encontrada']); exit; }
        if ($orden['surtidor_id'] != $empleado_id) {
            echo json_encode(['ok'=>false,'error'=>'Esta venta no esta asignada a ti']);
            exit;
        }
        $orden_id = $orden['id'];

        if ($accion === 'surtido') {
            if ($productos && is_array($productos)) {
                foreach ($productos as $p) {
                    $qty = (float)($p['cantidad_surtida'] ?? 0);
                    $pdo->prepare("UPDATE venta_detalle SET cantidad_surtida=? WHERE venta_id=? AND codigo=?")
                        ->execute([$qty, $orden_id, $p['codigo']]);
                }
            } else {
                $pdo->prepare("UPDATE venta_detalle SET cantidad_surtida=cantidad WHERE venta_id=?")->execute([$orden_id]);
            }
            $pdo->prepare("UPDATE ventas SET estatus='surtido', fecha_surtido=CURDATE() WHERE id=?")->execute([$orden_id]);
        } else {
            $pdo->prepare("UPDATE ventas SET estatus='entregado' WHERE id=?")->execute([$orden_id]);
        }
    }

    // Publicar MQTT confirmacion
    $empNom = '';
    $er = $pdo->prepare("SELECT nombre, apellido FROM empleados WHERE id=?");
    $er->execute([$empleado_id]);
    $ee = $er->fetch();
    if ($ee) $empNom = $ee['nombre'].' '.$ee['apellido'];

    $prods = getProductosSurtir($tipo, $orden_id, $pdo);
    mqttPublishSurtir($tipo, $orden_id, $folio, $empleado_id, $empNom, $prods, $accion);

    echo json_encode(['ok'=>true, 'accion'=>$accion, 'folio'=>$folio, 'tipo'=>$tipo, 'mensaje'=>"Pedido $accion correctamente"]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Error del servidor: '.$e->getMessage()]);
}
