function exportarTablaExcel(selector, nombre) {
    var tabla = document.querySelector(selector);
    if (!tabla) { alert('No se encontro la tabla'); return; }
    var html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"></head><body>' + tabla.outerHTML + '</body></html>';
    var blob = new Blob(['\ufeff' + html], { type: 'application/vnd.ms-excel;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = nombre + '.xls';
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 100);
}

function imprimirTablaPDF(selector, titulo) {
    var tabla = document.querySelector(selector);
    if (!tabla) { alert('No se encontro la tabla'); return; }
    var contenido = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' + titulo + '</title>';
    contenido += '<style>body{font-family:Arial,Helvetica,sans-serif;padding:20px;color:#222;}';
    contenido += 'h1{font-size:18px;color:#5b9bd5;margin:0 0 4px;}';
    contenido += '.fecha{font-size:12px;color:#666;margin-bottom:14px;}';
    contenido += 'table{width:100%;border-collapse:collapse;font-size:11px;}';
    contenido += 'th,td{border:1px solid #999;padding:6px;text-align:left;}';
    contenido += 'th{background:#eef2f7;}';
    contenido += '.footer-print{display:none;}';
    contenido += '@media print{.no-print{display:none;}}</style></head><body>';
    contenido += '<h1>' + titulo + '</h1>';
    contenido += '<p class="fecha">Fecha de impresion: ' + new Date().toLocaleDateString() + ' ' + new Date().toLocaleTimeString() + '</p>';
    contenido += tabla.outerHTML;
    contenido += '<p class="no-print" style="margin-top:16px;font-size:12px;">Para guardar en PDF elija "Guardar como PDF" en el dialogo de impresion.</p>';
    contenido += '</body></html>';
    var w = window.open('', '_blank', 'width=1000,height=700');
    if (!w) { alert('Permite las ventanas emergentes para imprimir'); return; }
    w.document.open();
    w.document.write(contenido);
    w.document.close();
    setTimeout(function () { w.focus(); w.print(); }, 400);
}
