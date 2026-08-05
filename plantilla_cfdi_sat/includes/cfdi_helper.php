<?php
require_once __DIR__ . '/../vendor/autoload.php';

use CfdiUtils\CfdiCreator40;
use CfdiUtils\Certificado\Certificado;
use PhpCfdi\Credentials\Credential;
use PhpCfdi\Finkok\FinkokEnvironment;
use PhpCfdi\Finkok\FinkokSettings;
use PhpCfdi\Finkok\QuickFinkok;

function cfdiMigrar($pdo)
{
    foreach ([
        "ALTER TABLE config_empresa ADD COLUMN razon_social VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE config_empresa ADD COLUMN regimen_fiscal VARCHAR(10) DEFAULT NULL",
        "ALTER TABLE config_empresa ADD COLUMN codigo_postal VARCHAR(5) DEFAULT NULL",
        "ALTER TABLE config_empresa ADD COLUMN serie_cfdi VARCHAR(10) DEFAULT 'F'",
        "ALTER TABLE config_empresa ADD COLUMN folio_cfdi INT DEFAULT 0",
        "ALTER TABLE config_empresa ADD COLUMN csd_cer VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE config_empresa ADD COLUMN csd_key VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE config_empresa ADD COLUMN csd_password VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE config_empresa ADD COLUMN finkok_user VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE config_empresa ADD COLUMN finkok_password VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE config_empresa ADD COLUMN finkok_ambiente ENUM('pruebas','produccion') DEFAULT 'pruebas'",
        "ALTER TABLE clientes ADD COLUMN uso_cfdi VARCHAR(10) DEFAULT NULL",
        "ALTER TABLE clientes ADD COLUMN regimen_fiscal_receptor VARCHAR(10) DEFAULT NULL",
        "ALTER TABLE clientes ADD COLUMN codigo_postal VARCHAR(5) DEFAULT NULL",
        "ALTER TABLE productos ADD COLUMN clave_prod_serv VARCHAR(8) DEFAULT '01010101'",
        "ALTER TABLE productos ADD COLUMN clave_unidad VARCHAR(3) DEFAULT 'H87'",
        "ALTER TABLE productos ADD COLUMN objeto_impuesto VARCHAR(2) DEFAULT '02'",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) {}
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS facturas_emitidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('venta','exportacion') DEFAULT 'venta',
        venta_id INT,
        uuid VARCHAR(36),
        serie VARCHAR(10),
        folio VARCHAR(20),
        rfc_receptor VARCHAR(13),
        total DECIMAL(12,2) DEFAULT 0,
        xml_path VARCHAR(255),
        estatus ENUM('precfdi','timbrado','error') DEFAULT 'precfdi',
        mensaje TEXT,
        creado_por INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_venta (venta_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function cfdiConfig($pdo)
{
    $sql = "SELECT * FROM config_empresa WHERE id=1";
    $cfg = $pdo->query($sql)->fetch();
    if (!$cfg) return null;
    return $cfg;
}

function cfdiFormaPagoMap($forma_pago)
{
    $forma = strtolower(trim($forma_pago ?? ''));
    switch ($forma) {
        case 'contado':   return ['forma' => '01', 'metodo' => 'PUE'];
        case 'tarjeta':   return ['forma' => '04', 'metodo' => 'PUE'];
        case 'transferencia': return ['forma' => '03', 'metodo' => 'PUE'];
        case 'credito':   return ['forma' => '99', 'metodo' => 'PPD'];
        default:          return ['forma' => '99', 'metodo' => 'PUE'];
    }
}

function cfdiClienteFiscal($pdo, $cliente)
{
    $tipo = $cliente['tipo'] ?? 'persona_fisica';
    $uso = $cliente['uso_cfdi'] ?: ($tipo === 'persona_moral' ? 'G01' : 'G03');
    $reg = $cliente['regimen_fiscal_receptor'] ?: ($tipo === 'persona_moral' ? '601' : '612');
    $cp = $cliente['codigo_postal'] ?? '';
    return ['uso' => $uso, 'regimen' => $reg, 'cp' => $cp];
}

function cfdiGenerarIngreso($pdo, $venta_id)
{
    $cfg = cfdiConfig($pdo);
    if (!$cfg) return ['ok' => false, 'mensaje' => 'Configuracion de empresa no encontrada'];

    $errores = [];
    if (empty($cfg['razon_social']) && empty($cfg['nombre'])) $errores[] = 'Falta razon social / nombre del emisor';
    if (empty($cfg['rfc'])) $errores[] = 'Falta RFC del emisor';
    if (empty($cfg['regimen_fiscal'])) $errores[] = 'Falta regimen fiscal del emisor (configuracion.php)';
    if (empty($cfg['codigo_postal'])) $errores[] = 'Falta codigo postal del emisor (configuracion.php)';
    if (empty($cfg['csd_cer']) || empty($cfg['csd_key'])) $errores[] = 'Faltan archivos CSD (.cer/.key) en configuracion.php';
    if ($errores) return ['ok' => false, 'mensaje' => implode('; ', $errores)];

    $venta = $pdo->prepare("SELECT v.*, c.nombre, c.rfc, c.tipo, c.uso_cfdi, c.regimen_fiscal_receptor, c.codigo_postal FROM ventas v JOIN clientes c ON c.id=v.cliente_id WHERE v.id=?");
    $venta->execute([$venta_id]);
    $venta = $venta->fetch();
    if (!$venta) return ['ok' => false, 'mensaje' => 'Venta no encontrada'];

    if (empty($venta['rfc'])) return ['ok' => false, 'mensaje' => 'El cliente no tiene RFC registrado (requerido para CFDI)'];

    $cliente_fiscal = cfdiClienteFiscal($pdo, $venta);
    if (empty($cliente_fiscal['cp'])) return ['ok' => false, 'mensaje' => 'El cliente no tiene codigo postal fiscal registrado'];
    if (empty($venta['nombre'])) return ['ok' => false, 'mensaje' => 'El cliente no tiene razon social registrada'];

    $detalles = $pdo->prepare("SELECT d.*, p.codigo, p.nombre as producto, p.clave_prod_serv, p.clave_unidad, p.objeto_impuesto, u.codigo as umedida FROM venta_detalle d JOIN productos p ON p.id=d.producto_id JOIN unidades_medida u ON u.id=p.unidad_medida_id WHERE d.venta_id=?");
    $detalles->execute([$venta_id]);
    $detalles = $detalles->fetchAll();
    if (empty($detalles)) return ['ok' => false, 'mensaje' => 'La venta no tiene conceptos'];

    $fecha = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
    $pm = cfdiFormaPagoMap($venta['forma_pago']);
    $folio = (int)$cfg['folio_cfdi'] + 1;
    $serie = $cfg['serie_cfdi'] ?: 'F';

    $creator = new CfdiCreator40([
        'Serie' => $serie,
        'Folio' => (string)$folio,
        'Fecha' => $fecha->format('c'),
        'FormaPago' => $pm['forma'],
        'MetodoPago' => $pm['metodo'],
        'TipoDeComprobante' => 'I',
        'Exportacion' => '01',
        'LugarExpedicion' => $cfg['codigo_postal'],
        'Moneda' => 'MXN',
    ]);

    $emisor = $creator->comprobante()->getEmisor();
    $emisor['Rfc'] = strtoupper(trim($cfg['rfc']));
    $emisor['Nombre'] = $cfg['razon_social'] ?: $cfg['nombre'];
    $emisor['RegimenFiscal'] = $cfg['regimen_fiscal'];

    $receptor = $creator->comprobante()->getReceptor();
    $receptor['Rfc'] = strtoupper(trim($venta['rfc']));
    $receptor['Nombre'] = $venta['nombre'];
    $receptor['DomicilioFiscalReceptor'] = $cliente_fiscal['cp'];
    $receptor['RegimenFiscalReceptor'] = $cliente_fiscal['regimen'];
    $receptor['UsoCFDI'] = $cliente_fiscal['uso'];

    $descuento_total = 0;
    $subtotal_total = 0;
    $descuento_global = (float)$venta['descuento'];
    $conceptos = [];

    foreach ($detalles as $d) {
        $descuento_linea = (float)$d['descuento'];
        $importe_bruto = (float)$d['subtotal'] + $descuento_linea;
        $cantidad = (float)$d['cantidad'];
        $valor_unitario = $importe_bruto / $cantidad;
        $descuento_total += $descuento_linea;
        $subtotal_total += $importe_bruto;

        $imp = $creator->comprobante()->addConcepto([
            'ClaveProdServ' => $d['clave_prod_serv'] ?: '01010101',
            'NoIdentificacion' => $d['codigo'],
            'Cantidad' => rtrim(rtrim(number_format($cantidad, 6, '.', ''), '0'), '.'),
            'ClaveUnidad' => $d['clave_unidad'] ?: 'H87',
            'Unidad' => $d['umedida'],
            'Descripcion' => $d['producto'],
            'ValorUnitario' => rtrim(rtrim(number_format($valor_unitario, 6, '.', ''), '0'), '.'),
            'Importe' => number_format($importe_bruto, 2, '.', ''),
            'ObjetoImp' => $d['objeto_impuesto'] ?: '02',
        ]);
        $conceptos[] = ['concepto' => $imp, 'importe_bruto' => $importe_bruto, 'descuento_linea' => $descuento_linea];
        if ($descuento_linea > 0) {
            $imp['Descuento'] = number_format($descuento_linea, 2, '.', '');
        }
        $traslado = null;
        if (($d['objeto_impuesto'] ?: '02') === '02') {
            $impuestos_concepto = $imp->addImpuestos([]);
            $traslados = $impuestos_concepto->addTraslados([]);
            $traslado = $traslados->addTraslado([
                'Base' => number_format($importe_bruto - $descuento_linea, 2, '.', ''),
                'Impuesto' => '002',
                'TipoFactor' => 'Tasa',
                'TasaOCuota' => '0.160000',
                'Importe' => number_format(round(($importe_bruto - $descuento_linea) * 0.16, 2), 2, '.', ''),
            ]);
        }
        $conceptos[count($conceptos) - 1]['traslado'] = $traslado;
    }

    if ($descuento_global > 0 && $subtotal_total > 0) {
        foreach ($conceptos as $c) {
            $importe_bruto = $c['importe_bruto'];
            $descuento_linea = $c['descuento_linea'];
            $desc_prorrata = round($descuento_global * ($importe_bruto / $subtotal_total), 2);
            if ($desc_prorrata <= 0) continue;
            $imp = $c['concepto'];
            $imp['Descuento'] = number_format($descuento_linea + $desc_prorrata, 2, '.', '');
            $base = $importe_bruto - $descuento_linea - $desc_prorrata;
            if ($base < 0) $base = 0;
            if ($c['traslado']) {
                $c['traslado']['Base'] = number_format($base, 2, '.', '');
                $c['traslado']['Importe'] = number_format(round($base * 0.16, 2), 2, '.', '');
            }
        }
    }

    $descuento_comprobante = round($descuento_total + $descuento_global, 2);
    $base_iva = round($subtotal_total - $descuento_comprobante, 2);
    $iva = round($base_iva * 0.16, 2);
    $total = round($base_iva + $iva, 2);

    $creator->comprobante()['SubTotal'] = number_format($subtotal_total, 2, '.', '');
    if ($descuento_comprobante > 0) {
        $creator->comprobante()['Descuento'] = number_format($descuento_comprobante, 2, '.', '');
    }
    $impuestos = $creator->comprobante()->addImpuestos([
        'TotalImpuestosTrasladados' => number_format($iva, 2, '.', ''),
        'TotalImpuestosRetenidos' => '0',
    ]);
    $creator->comprobante()['Total'] = number_format($total, 2, '.', '');

    $cer_path = __DIR__ . '/../uploads/csd/' . $cfg['csd_cer'];
    $key_path = __DIR__ . '/../uploads/csd/' . $cfg['csd_key'];

    if (!file_exists($cer_path) || !file_exists($key_path)) {
        return ['ok' => false, 'mensaje' => 'Archivos CSD no encontrados en uploads/csd/'];
    }

    try {
        $certificado = new Certificado($cer_path);
        $creator->putCertificado($certificado);
        $credential = Credential::openFiles($cer_path, $key_path, $cfg['csd_password'] ?? '');
        $creator->addSello($credential->privateKey()->pem(), $cfg['csd_password'] ?? '');
    } catch (Exception $e) {
        return ['ok' => false, 'mensaje' => 'Error al sellar: ' . $e->getMessage()];
    }

    return [
        'ok' => true,
        'creator' => $creator,
        'xml' => $creator->asXml(),
        'folio' => $folio,
        'serie' => $serie,
        'rfc_receptor' => strtoupper(trim($venta['rfc'])),
        'total' => $total,
    ];
}

function cfdiTimbrarFinkok($pdo, $precfdiXml)
{
    $cfg = cfdiConfig($pdo);
    if (!$cfg || empty($cfg['finkok_user']) || empty($cfg['finkok_password'])) {
        return ['ok' => false, 'mensaje' => 'Credenciales Finkok no configuradas', 'no_creds' => true];
    }
    if (!extension_loaded('soap')) {
        return ['ok' => false, 'mensaje' => 'La extension SOAP no esta instalada en el servidor'];
    }
    try {
        $ambiente = $cfg['finkok_ambiente'] === 'produccion'
            ? FinkokEnvironment::makeProduction()
            : FinkokEnvironment::makeDevelopment();
        $settings = new FinkokSettings($cfg['finkok_user'], $cfg['finkok_password'], $ambiente);
        $finkok = new QuickFinkok($settings);
        $resultado = $finkok->stamp($precfdiXml);
        if ($resultado->hasAlerts()) {
            $msgs = [];
            foreach ($resultado->alerts() as $alerta) {
                $msgs[] = $alerta->id() . ' - ' . $alerta->message();
            }
            return ['ok' => false, 'mensaje' => implode('; ', $msgs)];
        }
        return [
            'ok' => true,
            'uuid' => $resultado->uuid(),
            'xml' => $resultado->xml(),
            'mensaje' => 'CFDI timbrado correctamente',
        ];
    } catch (Exception $e) {
        return ['ok' => false, 'mensaje' => 'Error Finkok: ' . $e->getMessage()];
    }
}

function cfdiGuardarEmitida($pdo, $tipo, $venta_id, $uuid, $serie, $folio, $rfc_receptor, $total, $xml, $estatus, $mensaje, $usuario_id)
{
    $dir = __DIR__ . '/../uploads/cfdi/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nom = ($uuid ?: 'precfdi') . '-' . $venta_id . '.xml';
    file_put_contents($dir . $nom, $xml);
    $pdo->prepare("INSERT INTO facturas_emitidas (tipo,venta_id,uuid,serie,folio,rfc_receptor,total,xml_path,estatus,mensaje,creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$tipo, $venta_id, $uuid ?: null, $serie, $folio, $rfc_receptor, $total, $nom, $estatus, $mensaje, $usuario_id]);
    return $pdo->lastInsertId();
}

function cfdiMarcarFolioUsado($pdo, $folio)
{
    $cfg = cfdiConfig($pdo);
    if (!$cfg) return;
    if ((int)$folio > (int)$cfg['folio_cfdi']) {
        $pdo->prepare("UPDATE config_empresa SET folio_cfdi=? WHERE id=1")->execute([(int)$folio]);
    }
}
