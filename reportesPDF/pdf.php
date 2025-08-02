<?php
session_start();
require_once '../reportesPDF/vendor/autoload.php';
require '../db.php';

if (!isset($_GET['generar_pdf'])) {
    header("Location: reportesRegistros.php");
    exit();
}

ini_set('memory_limit', '2048M');
ini_set('max_execution_time', 3600);
set_time_limit(3600);

define('BATCH_SIZE', 10);

function generarPDFIndividual($conexion, $id_registro, $counter = 0) {
    $sql = "SELECT nombre_jefe_hogar, documento_jefe_hogar, fecha, nombre_comunidad, nombre_salida, nombre_entrega, imagen, tipo_de_rfpp 
            FROM registro_salidas r
            LEFT JOIN imagen_registro i ON r.id_registro = i.id_registro
            WHERE r.id_registro = ?";
    
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_registro);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    $registro = mysqli_fetch_assoc($resultado);

    if (!$registro) return null;

    $nombre_base = $registro['nombre_jefe_hogar'] . '-' . $registro['documento_jefe_hogar'];
    $nombre_archivo = $nombre_base . ($counter > 0 ? "($counter)" : "") . '.pdf';

    // Manejo de imágenes basado en tu estructura de datos
    $imagen_html = '';
    if (!empty($registro['imagen'])) {
        $ruta_imagen = $registro['imagen'];
        if (strpos($ruta_imagen, './') !== 0 && strpos($ruta_imagen, '/') !== 0) {
            $ruta_imagen = './' . $ruta_imagen;
        }
        
        if (file_exists($ruta_imagen)) {
            $imagen_html = '<img src="'.$ruta_imagen.'" style="width:100%;height:530px;object-fit:contain;display:block;">';
        } else {
            $ruta_alternativa = '../' . ltrim($ruta_imagen, './');
            if (file_exists($ruta_alternativa)) {
                $imagen_html = '<img src="'.$ruta_alternativa.'" style="width:100%;height:620px;object-fit:contain;display:block;">';
            }
        }
    }

    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte de Entrega</title>
  <style>
    body { 
        font-family: Arial, sans-serif; 
        margin: 0; 
        padding: 10px; 
        line-height: 1.4;
        font-size: 13px;
    }
    .header-container {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 10px;
        position: relative;
        width: 100%;
        /* Ajusta esta posición vertical según necesites */
        top: -20px; 
        max-width: none;
    }
    .logos-left-container {
        display: flex;
        align-items: center;
        gap: 0;
        min-width: 600px;
        /* Ajusta esta posición vertical para mover los logos hacia abajo */
        margin-top: 30px;
    }

    .logo-florero {
        width: 100px;
        height: auto;
    }

    /* Logo "bienestar" pegado al de "colombia" */
    .bienestar-logo {
        width: 110px;
        height: 76px;
        margin-left: 373px;
        margin-right: -18px;
    }

    .colombia-logo {
        width: 100px;
        height: 40px;
        margin-left: 10px;
    }
    
    /* Ajustes para el texto del encabezado */
    .header-text {
        text-align: center; /* Cambiado de center a right para alinear a la derecha */
        flex-grow: 1;
        width: 500px;
        margin: 0 20px;
        /* Ajusta estas propiedades para mover el texto más arriba */
        position: relative;
        top: -10px; /* Puedes aumentar o disminuir este valor según necesites */
        right: -18; /* Alinea a la derecha */
    }
    
    /* Ajustes individuales para cada línea de texto */
    .association {
        font-size: 15px;
        margin-bottom: 3px;
        /* Puedes ajustar el margin-top si necesitas mover más arriba */
        margin-top: -60px;
    }
    .nit {
        font-size: 13px;
        margin-bottom: 5px;
        font-weight: bold;
        /* Ajuste similar para esta línea */
        margin-top: -8px;
    }
    .contract {
        font-size: 13px;
        margin: 10px 0;
        font-weight: bold;
        /* Ajuste similar para esta línea */
        margin-top: -8px;
    }
    
   .divider {
    border-top: 1px solid #000;
    margin: 10px 0 10px 0; /* Ajusta el primer valor (arriba) para moverlo */
    position: relative;   /* Opcional: permite ajustes finos con top */
    top: -48px;           /* Mueve el divisor hacia arriba */
}
    .title {
    font-size: 20px;
    text-align: center;
    font-weight: bold;
    position: absolute;
    top: 100px; /* 👈 Baja el texto */
    left: 50%;
    transform: translateX(-50%);
    
    /* Subrayado real del texto, adaptado a múltiples líneas */
    text-decoration: underline;
    text-decoration-color: #000000;

    max-width: 80%;
    word-wrap: break-word;
    white-space: normal;

    font-style: italic;
    color: #000000;
    line-height: 1.4;
}


    .details-table {
    width: 100%;
    border-collapse: collapse;
    margin: 10px 0 20px 0; /* 👈 Aquí se baja */
    font-size: 13px;
    font-style: italic;
}

.details-table td {
    padding: 5px;
    border: 1px solid #000;
    font-style: italic;
}

    .bold-text {
        font-weight: bold;
    }
    .imagen-container {
        text-align: center;
        margin: 15px 0;
        padding: 5px;
        page-break-inside: avoid;
    }
    .single-page-content {
        page-break-inside: avoid;
        page-break-after: avoid;
        page-break-before: avoid;
    }
</style>
</head>
<body>
    <div class="single-page-content">
        <div class="header-container">
            <!-- Contenedor para todos los logos (florero + bienestar + colombia) -->
            <div class="logos-left-container">
                <img src="/logos/florero.jpeg" class="logo-florero">
                <img src="/logos/bienestar.jpg" class="bienestar-logo">
                <img src="/logos/COLOMBIA_POTNCIA.jpg" class="colombia-logo">
            </div>            
            <!-- Texto del encabezado - Ajusta los valores de top y margin-top en las clases CSS para posicionar exactamente como quieras -->
            <div class="header-text">
                <div class="association">Asociación de Autoridades Tradicionales Indígenas Wayuu</div>
                <div class="nit">"ANAINJAK WAKUAIPA NIT: 839000405-3"</div>
                <div class="contract">CONTRATO: 44000852025</div>
            </div>
        </div>
        
        <div class="divider"></div>

        <div class="title">'.htmlspecialchars($registro['nombre_salida'] ?? 'INICIATIVA COMUNITARIA ENTREGAS DE HILOS').'</div>
       
        <table class="details-table">
            <tr>
                <td><span class="bold-text">NUMERO DE CONTRATO: 44000852024</span></td>
            </tr>
            <tr>
                <td><span class="bold-text">FECHA DE ENTREGA: '.date('d/m/Y', strtotime($registro['fecha'])).'</span></td>
            </tr>
            <tr>
                <td><span class="bold-text">COMUNIDAD: '.htmlspecialchars($registro['nombre_comunidad'] ?? 'No especificada').'</span></td>
            </tr>
            <tr>
                <td><span class="bold-text">QUIEN ENTREGA: '.htmlspecialchars($registro['nombre_entrega'] ?? 'ASOCIACION ANAINAJAK WAKUAIPA').'</span></td>
            </tr>
            <tr>
                <td><span class="bold-text">JEFE DEL HOGAR: '.htmlspecialchars($registro['nombre_jefe_hogar']).' - '.htmlspecialchars($registro['documento_jefe_hogar']).'</span></td>
            </tr>';

    // Mostrar tipo_de_rfpp solo si existe y no está vacío
    if (!empty($registro['tipo_de_rfpp'])) {
        $html .= '
            <tr>
                <td><span class="bold-text">TIPO DE RFPP: '.htmlspecialchars($registro['tipo_de_rfpp']).'</span></td>
            </tr>';
    }

    $html .= '
        </table>';

    if (!empty($imagen_html)) {
        $html .= '
        <div class="imagen-container">
            '.$imagen_html.'
        </div>';
    }
    
    $html .= '
    </div>
    </body>
</html>';

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('tempDir', sys_get_temp_dir());
    $options->set('chroot', realpath('../'));
    $options->set('isFontSubsettingEnabled', true);
    $options->set('isJavascriptEnabled', false);
    
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return [
        'content' => $dompdf->output(),
        'filename' => $nombre_archivo
    ];
}

if (isset($_GET['seleccionados'])) {
    $ids = json_decode($_GET['seleccionados']);
    $ids = array_filter($ids, 'is_numeric');
    
    if (empty($ids)) {
        header("Location: reportesRegistros.php");
        exit();
    }

    $zip = new ZipArchive();
    $zipFileName = tempnam(sys_get_temp_dir(), 'pdfs_') . '.zip';
    
    if ($zip->open($zipFileName, ZipArchive::CREATE) !== TRUE) {
        die("No se pudo crear el archivo ZIP");
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="reportes_entrega_' . date('Y-m-d') . '.zip"');
    header('Cache-Control: no-cache, must-revalidate');
    
    ob_implicit_flush(true);
    ob_end_flush();
    
    $nombres_archivos = [];
    $processed = 0;
    $total = count($ids);
    $batch_count = 0;
    
    foreach (array_chunk($ids, BATCH_SIZE) as $batch) {
        $batch_count++;
        
        foreach ($batch as $id) {
            $counter = 0;
            do {
                $pdfData = generarPDFIndividual($conexion, $id, $counter);
                $counter++;
            } while ($pdfData && in_array($pdfData['filename'], $nombres_archivos) && $counter < 100);
            
            if ($pdfData) {
                $zip->addFromString($pdfData['filename'], $pdfData['content']);
                $nombres_archivos[] = $pdfData['filename'];
                unset($pdfData);
            }
            
            $processed++;
            echo "<script>parent.updateProgress($processed, $total, $batch_count);</script>";
            usleep(100000);
        }
        
        $zip->close();
        $zip->open($zipFileName);
    }

    $zip->close();
    readfile($zipFileName);
    unlink($zipFileName);
    echo "<script>parent.downloadComplete();</script>";
    exit();
}

if (isset($_GET['id_registro'])) {
    $pdfData = generarPDFIndividual($conexion, $_GET['id_registro']);
    if ($pdfData) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $pdfData['filename'] . '"');
        echo $pdfData['content'];
    }
    exit();
}

header("Location: reportesRegistros.php");
exit();
?>