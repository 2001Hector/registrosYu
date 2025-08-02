<?php
// Configuración para mostrar errores (quitar en producción)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Configuración de sesión segura
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

// Incluir archivo de conexión a la base de datos
require __DIR__.'/../db.php';

// Verificar conexión a la base de datos
if (!isset($conexion) || !($conexion instanceof mysqli)) {
    die("Error de conexión a la base de datos");
}

// Verificar sesión de usuario
if (!isset($_SESSION['logueado']) || !$_SESSION['logueado'] || !$_SESSION['pagado']) {
    header("Location: index.php");
    exit();
}

// Verificar token de sesión para usuarios no admin
if (!isset($_SESSION['es_admin']) || !$_SESSION['es_admin']) {
    $sql = "SELECT session_token FROM usuarios WHERE id = ?";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['usuario_id']);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    
    if ($resultado && mysqli_num_rows($resultado) > 0) {
        $datos = mysqli_fetch_assoc($resultado);
        if ($datos['session_token'] !== $_SESSION['session_token']) {
            // Token no coincide - limpiar y redirigir
            $clean_stmt = mysqli_prepare($conexion, "UPDATE usuarios SET session_token = NULL WHERE id = ?");
            mysqli_stmt_bind_param($clean_stmt, "i", $_SESSION['usuario_id']);
            mysqli_stmt_execute($clean_stmt);
            session_destroy();
            header("Location: index.php");
            exit();
        }
    }
}

// Actualizar último acceso
$now = date('Y-m-d H:i:s');
$update_sql = "UPDATE usuarios SET ultimo_acceso = ? WHERE id = ?";
$update_stmt = mysqli_prepare($conexion, $update_sql);
mysqli_stmt_bind_param($update_stmt, "si", $now, $_SESSION['usuario_id']);
mysqli_stmt_execute($update_stmt);

// Variables de estado
$mostrar_exito = false;
$modo_offline = false;

// Función para verificar conexión a internet
function hayConexionInternet() {
    $conectado = @fsockopen("www.google.com", 80, $errno, $errstr, 2); 
    if ($conectado) {
        fclose($conectado);
        return true;
    }
    return false;
}

// Función para guardar datos en almacenamiento local (servidor)
function guardarEnLocalStorage($datos) {
    $directorio = 'local_storage/';
    if (!file_exists($directorio)) {
        mkdir($directorio, 0777, true);
    }
    
    $archivo = $directorio . $_SESSION['usuario_id'] . '_pendientes.json';
    $pendientes = [];
    
    if (file_exists($archivo)) {
        $pendientes = json_decode(file_get_contents($archivo), true);
        if (!is_array($pendientes)) {
            $pendientes = [];
        }
    }
    
    $pendientes[] = $datos;
    file_put_contents($archivo, json_encode($pendientes));
}

// Función para sincronizar registros pendientes
function sincronizarPendientes($conexion) {
    $archivo = 'local_storage/' . $_SESSION['usuario_id'] . '_pendientes.json';
    
    if (file_exists($archivo)) {
        $pendientes = json_decode(file_get_contents($archivo), true);
        $exitos = [];
        
        foreach ($pendientes as $key => $registro) {
            // Insertar el registro principal
            $query = "INSERT INTO registro_salidas (id_usuario, nombre_comunidad, nombre_entrega, nombre_jefe_hogar, documento_jefe_hogar, nombre_salida, fecha, tipo_de_rfpp, modo_offline) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";
            $stmt = mysqli_prepare($conexion, $query);
            mysqli_stmt_bind_param($stmt, 'isssssss', $_SESSION['usuario_id'], 
                $registro['nombre_comunidad'], $registro['nombre_entrega'], 
                $registro['nombre_jefe_hogar'], $registro['documento_jefe_hogar'], 
                $registro['nombre_salida'], $registro['fecha'], $registro['tipo_rfpp']);
            
            if (mysqli_stmt_execute($stmt)) {
                $id_registro = mysqli_insert_id($conexion);
                mysqli_stmt_close($stmt);
                
                // Procesar la imagen si existe
                if (!empty($registro['imagen_data'])) {
                    $directorio = '../uploads/';
                    if (!file_exists($directorio)) {
                        mkdir($directorio, 0777, true);
                    }
                    
                    $nombre_unico = uniqid() . '_' . $registro['imagen_nombre'];
                    $ruta_final = $directorio . $nombre_unico;
                    
                    // Guardar la imagen
                    file_put_contents($ruta_final, base64_decode($registro['imagen_data']));
                    
                    // Insertar información de la imagen
                    $query_img = "INSERT INTO imagen_registro (imagen, fecha_imagen, id_registro) VALUES (?, ?, ?)";
                    $stmt_img = mysqli_prepare($conexion, $query_img);
                    $ruta_db = 'uploads/' . $nombre_unico;
                    mysqli_stmt_bind_param($stmt_img, 'ssi', $ruta_db, $registro['fecha'], $id_registro);
                    mysqli_stmt_execute($stmt_img);
                    mysqli_stmt_close($stmt_img);
                }
                
                $exitos[] = $key;
            }
        }
        
        // Eliminar registros sincronizados
        if (!empty($exitos)) {
            foreach ($exitos as $key) {
                unset($pendientes[$key]);
            }
            file_put_contents($archivo, json_encode(array_values($pendientes)));
        }
    }
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar y sanitizar datos
    $nombre_comunidad = mysqli_real_escape_string($conexion, $_POST['nombre_comunidad'] ?? '');
    $nombre_entrega = mysqli_real_escape_string($conexion, $_POST['nombre_entrega'] ?? '');
    $nombre_jefe_hogar = mysqli_real_escape_string($conexion, $_POST['nombre_jefe_hogar'] ?? '');
    $documento_jefe_hogar = mysqli_real_escape_string($conexion, $_POST['documento_jefe_hogar'] ?? '');
    $nombre_salida = mysqli_real_escape_string($conexion, $_POST['nombre_salida'] ?? '');
    $fecha = date('Y-m-d');
    
    // Procesar RFPP
    $tipo_rfpp = null;
    if (isset($_POST['tiene_rfpp']) && $_POST['tiene_rfpp'] === 'si') {
        $tipo_rfpp = mysqli_real_escape_string($conexion, $_POST['tipo_rfpp'] ?? '');
    }

    // Verificar conexión
    if (hayConexionInternet()) {
        // Intentar sincronizar pendientes primero
        sincronizarPendientes($conexion);
        
        // Insertar registro principal
        $query = "INSERT INTO registro_salidas (id_usuario, nombre_comunidad, nombre_entrega, nombre_jefe_hogar, documento_jefe_hogar, nombre_salida, fecha, tipo_de_rfpp, modo_offline) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)";
        $stmt = mysqli_prepare($conexion, $query);
        mysqli_stmt_bind_param($stmt, 'isssssss', $_SESSION['usuario_id'], $nombre_comunidad, $nombre_entrega, $nombre_jefe_hogar, $documento_jefe_hogar, $nombre_salida, $fecha, $tipo_rfpp);
        
        if (mysqli_stmt_execute($stmt)) {
            $id_registro = mysqli_insert_id($conexion);
            mysqli_stmt_close($stmt);

            // Procesar imagen si se subió
            if (!empty($_FILES['imagen']['tmp_name'])) {
                $directorio = '../uploads/';
                if (!file_exists($directorio)) {
                    mkdir($directorio, 0777, true);
                }

                $nombre_archivo = $_FILES['imagen']['name'];
                $extension = strtolower(pathinfo($nombre_archivo, PATHINFO_EXTENSION));

                // Validar formato
                $formatosPermitidos = ['jpg', 'jpeg', 'png'];
                if (in_array($extension, $formatosPermitidos)) {
                    $imagen_info = getimagesize($_FILES['imagen']['tmp_name']);
                    if ($imagen_info[0] < $imagen_info[1]) { // Validar orientación vertical
                        $nombre_unico = uniqid() . '_' . basename($nombre_archivo);
                        $ruta_final = $directorio . $nombre_unico;
                        
                        if (move_uploaded_file($_FILES['imagen']['tmp_name'], $ruta_final)) {
                            $query_img = "INSERT INTO imagen_registro (imagen, fecha_imagen, id_registro) VALUES (?, ?, ?)";
                            $stmt_img = mysqli_prepare($conexion, $query_img);
                            $ruta_db = 'uploads/' . $nombre_unico;
                            mysqli_stmt_bind_param($stmt_img, 'ssi', $ruta_db, $fecha, $id_registro);
                            mysqli_stmt_execute($stmt_img);
                            mysqli_stmt_close($stmt_img);
                        }
                    }
                }
            }

            $mostrar_exito = true;
        }
    } else {
        // Modo offline - guardar localmente
        $datos_offline = [
            'nombre_comunidad' => $nombre_comunidad,
            'nombre_entrega' => $nombre_entrega,
            'nombre_jefe_hogar' => $nombre_jefe_hogar,
            'documento_jefe_hogar' => $documento_jefe_hogar,
            'nombre_salida' => $nombre_salida,
            'fecha' => $fecha,
            'tipo_rfpp' => $tipo_rfpp,
            'timestamp' => time()
        ];

        // Procesar imagen para almacenamiento offline
        if (!empty($_FILES['imagen']['tmp_name'])) {
            $imagen_data = file_get_contents($_FILES['imagen']['tmp_name']);
            $datos_offline['imagen_nombre'] = $_FILES['imagen']['name'];
            $datos_offline['imagen_data'] = base64_encode($imagen_data);
        }

        guardarEnLocalStorage($datos_offline);
        $modo_offline = true;
        $mostrar_exito = true;
    }
    
    // Limpiar formulario
    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            document.getElementById("registroForm").reset();
            selectedFile = null;
            renderPreview();
            document.getElementById("tipo_rfpp_container").style.display = "none";
        });
    </script>';
}

// Obtener datos para autocompletado
$query_familias = "SELECT cedula_padre, nombre_padre FROM datos_familiares WHERE usuario_id = ?";
$stmt_familias = mysqli_prepare($conexion, $query_familias);
mysqli_stmt_bind_param($stmt_familias, "i", $_SESSION['usuario_id']);
mysqli_stmt_execute($stmt_familias);
$resultado_familias = mysqli_stmt_get_result($stmt_familias);
$familias = [];
while ($fila = mysqli_fetch_assoc($resultado_familias)) {
    $familias[] = $fila;
}

// Obtener historial de registros (online y pendientes)
$historial = [];
$query_historial = "SELECT 
    id_registro, 
    nombre_comunidad, 
    nombre_entrega, 
    nombre_jefe_hogar, 
    documento_jefe_hogar, 
    nombre_salida, 
    fecha, 
    IFNULL(modo_offline, 0) AS modo_offline 
    FROM registro_salidas 
    WHERE id_usuario = ? 
    ORDER BY fecha DESC, id_registro DESC";

$stmt_historial = mysqli_prepare($conexion, $query_historial);
if (!$stmt_historial) {
    die("Error al preparar la consulta: " . mysqli_error($conexion));
}

mysqli_stmt_bind_param($stmt_historial, "i", $_SESSION['usuario_id']);
if (!mysqli_stmt_execute($stmt_historial)) {
    die("Error al ejecutar la consulta: " . mysqli_stmt_error($stmt_historial));
}

$resultado_historial = mysqli_stmt_get_result($stmt_historial);
while ($fila = mysqli_fetch_assoc($resultado_historial)) {
    // Asegurar que modo_offline tenga un valor por defecto
    $fila['modo_offline'] = isset($fila['modo_offline']) ? (int)$fila['modo_offline'] : 0;
    $historial[] = $fila;
}


// Obtener registros pendientes
$pendientes = [];
$archivo_pendientes = 'local_storage/' . $_SESSION['usuario_id'] . '_pendientes.json';
if (file_exists($archivo_pendientes)) {
    $pendientes = json_decode(file_get_contents($archivo_pendientes), true);
    foreach ($pendientes as $pendiente) {
        $historial[] = [
            'id' => 'pendiente_' . $pendiente['timestamp'],
            'nombre_comunidad' => $pendiente['nombre_comunidad'],
            'nombre_entrega' => $pendiente['nombre_entrega'],
            'nombre_jefe_hogar' => $pendiente['nombre_jefe_hogar'],
            'documento_jefe_hogar' => $pendiente['documento_jefe_hogar'],
            'nombre_salida' => $pendiente['nombre_salida'],
            'fecha' => $pendiente['fecha'],
            'modo_offline' => 2 // 2 = pendiente de sincronización
        ];
    }
}

// Ordenar historial por fecha
usort($historial, function($a, $b) {
    return strtotime($b['fecha']) - strtotime($a['fecha']);
});
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Salidas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/11.4.24/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .modal-zoom { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9); overflow: auto; }
        .modal-content-zoom { margin: auto; display: block; width: 80%; max-width: 1200px; max-height: 90vh; margin-top: 5vh; }
        .close-zoom { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; transition: 0.3s; cursor: pointer; }
        .close-zoom:hover { color: #bbb; }
        .autocomplete { position: relative; display: inline-block; width: 100%; }
        .autocomplete-items { position: absolute; border: 1px solid #d4d4d4; border-bottom: none; border-top: none; z-index: 99; top: 100%; left: 0; right: 0; max-height: 200px; overflow-y: auto; }
        .autocomplete-items div { padding: 10px; cursor: pointer; background-color: #fff; border-bottom: 1px solid #d4d4d4; }
        .autocomplete-items div:hover { background-color: #e9e9e9; }
        .autocomplete-active { background-color: DodgerBlue !important; color: #ffffff; }
        .historial-item { transition: all 0.3s ease; }
        .historial-item:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
        .online-status { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .online { background-color: #10B981; }
        .offline { background-color: #EF4444; }
        .pending { background-color: #F59E0B; }
        .tab { padding: 10px 20px; cursor: pointer; border-bottom: 2px solid transparent; }
        .tab-active { border-bottom: 2px solid #3B82F6; color: #3B82F6; font-weight: 500; }
        .sync-counter { position: fixed; bottom: 20px; right: 20px; background-color: #3B82F6; color: white; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; font-weight: bold; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); cursor: pointer; z-index: 100; }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Navbar -->
    <nav class="bg-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-4">
                    <span class="font-semibold text-gray-700">ID: <?= $_SESSION['usuario_id'] ?></span>
                    <span class="font-semibold text-gray-700">Usuario: <?= htmlspecialchars($_SESSION['usuario_nombre']) ?></span>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="../cliente.php" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-md text-sm font-medium">Volver</a>
                    <a href="#" onclick="cerrarSesion(); return false;" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-md text-sm font-medium">Cerrar Sesión</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Contenido principal -->
    <div class="min-h-screen pt-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Pestañas -->
            <div class="flex border-b mb-6">
                <div id="tab-registro" class="tab tab-active" onclick="mostrarTab('registro')">Nuevo Registro</div>
                <div id="tab-historial" class="tab" onclick="mostrarTab('historial')">Historial</div>
            </div>
            
            <!-- Sección de registro -->
            <div id="seccion-registro" class="bg-white p-8 rounded-lg shadow-md">
                <h1 class="text-3xl font-bold mb-6 text-center">Registro de Salidas</h1>
                
                <?php if ($mostrar_exito): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                title: '<?= $modo_offline ? "Modo Offline Activado" : "¡Éxito!" ?>',
                                text: '<?= $modo_offline ? "El registro se ha guardado localmente. Se sincronizará automáticamente cuando se detecte conexión a internet." : "El registro se ha guardado correctamente." ?>',
                                icon: '<?= $modo_offline ? "info" : "success" ?>',
                                confirmButtonText: 'Aceptar'
                            });
                        });
                    </script>
                <?php endif; ?>

                <form id="registroForm" action="registro.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <!-- Sección de imagen -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Imagen del registro</label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                            <div class="space-y-1 text-center w-full">
                                <div class="flex text-sm text-gray-600 justify-center">
                                    <label for="imagen" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none">
                                        <span>Subir imagen</span>
                                        <input id="imagen" name="imagen" type="file" accept="image/jpeg, image/png" class="sr-only">
                                    </label>
                                    <p class="pl-1">o arrastrar y soltar</p>
                                </div>
                                <p class="text-xs text-gray-500">Formatos admitidos: PNG, JPG, JPEG</p>
                                <p class="text-xs text-gray-500">La imagen debe ser vertical (altura mayor que ancho)</p>
                            </div>
                        </div>
                        
                        <!-- Previsualización de imagen -->
                        <div id="preview-container" class="mt-4">
                            <p class="text-sm text-gray-500 text-center">No hay imagen seleccionada</p>
                        </div>
                    </div>

                    <!-- Sección de datos básicos -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="nombre_comunidad" class="block text-sm font-medium text-gray-700">Nombre de la Comunidad</label>
                            <input type="text" id="nombre_comunidad" name="nombre_comunidad" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label for="nombre_entrega" class="block text-sm font-medium text-gray-700">Nombre de quien entrega</label>
                            <input type="text" id="nombre_entrega" name="nombre_entrega" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="autocomplete">
                            <label for="documento_jefe_hogar" class="block text-sm font-medium text-gray-700">Documento del Jefe de Hogar</label>
                            <input type="text" id="documento_jefe_hogar" name="documento_jefe_hogar" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500" autocomplete="off">
                        </div>
                        <div>
                            <label for="nombre_jefe_hogar" class="block text-sm font-medium text-gray-700">Nombre del Jefe de Hogar</label>
                            <input type="text" id="nombre_jefe_hogar" name="nombre_jefe_hogar" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label for="nombre_salida" class="block text-sm font-medium text-gray-700">Nombre de la Salida</label>
                            <input type="text" id="nombre_salida" name="nombre_salida" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label for="fecha" class="block text-sm font-medium text-gray-700">Fecha</label>
                            <input type="date" id="fecha" name="fecha" value="<?= date('Y-m-d') ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>

                    <!-- Sección de RFPP -->
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">¿Tiene tipo de RFPP?</label>
                            <div class="mt-1 flex space-x-4">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="tiene_rfpp" value="si" class="h-4 w-4 text-blue-600 focus:ring-blue-500" onchange="document.getElementById('tipo_rfpp_container').style.display = 'block'">
                                    <span class="ml-2 text-gray-700">Sí</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="tiene_rfpp" value="no" class="h-4 w-4 text-blue-600 focus:ring-blue-500" onchange="document.getElementById('tipo_rfpp_container').style.display = 'none'" checked>
                                    <span class="ml-2 text-gray-700">No</span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="tipo_rfpp_container" style="display: none;">
                            <label for="tipo_rfpp" class="block text-sm font-medium text-gray-700">Tipo de RFPP</label>
                            <select id="tipo_rfpp" name="tipo_rfpp" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="Tipo A">A</option>
                                <option value="Tipo C">C</option>
                            </select>
                        </div>
                    </div>

                    <!-- Botón de envío -->
                    <div class="flex justify-center">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                            Guardar Registro
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Sección de historial -->
            <div id="seccion-historial" class="bg-white p-8 rounded-lg shadow-md hidden">
                <h1 class="text-3xl font-bold mb-6 text-center">Historial de Registros</h1>
                
                <div class="mb-4 flex justify-between items-center">
                    <div>
                        <span class="online-status online"></span> <span class="text-sm">En línea</span>
                        <span class="online-status offline ml-4"></span> <span class="text-sm">Offline</span>
                        <span class="online-status pending ml-4"></span> <span class="text-sm">Pendiente</span>
                    </div>
                    <div id="pendientes-count" class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-medium">
                        <?= count($pendientes) > 0 ? count($pendientes)." pendientes" : "Todo sincronizado" ?>
                    </div>
                </div>
                
                <div class="space-y-4">
                    <?php if (empty($historial)): ?>
                        <p class="text-center text-gray-500">No hay registros aún</p>
                    <?php else: ?>
                        <?php foreach ($historial as $registro): ?>
                            <div class="historial-item bg-white border rounded-lg p-4 shadow-sm">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <div class="flex items-center">
                                            <?php if ($registro['modo_offline'] == 0): ?>
                                                <span class="online-status online"></span>
                                            <?php elseif ($registro['modo_offline'] == 1): ?>
                                                <span class="online-status offline"></span>
                                            <?php else: ?>
                                                <span class="online-status pending"></span>
                                            <?php endif; ?>
                                            <h3 class="font-semibold"><?= htmlspecialchars($registro['nombre_comunidad']) ?></h3>
                                        </div>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <span class="font-medium">Entrega:</span> <?= htmlspecialchars($registro['nombre_entrega']) ?> | 
                                            <span class="font-medium">Jefe de Hogar:</span> <?= htmlspecialchars($registro['nombre_jefe_hogar']) ?> (<?= htmlspecialchars($registro['documento_jefe_hogar']) ?>)
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            <span class="font-medium">Salida:</span> <?= htmlspecialchars($registro['nombre_salida']) ?>
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-xs bg-gray-100 px-2 py-1 rounded"><?= $registro['fecha'] ?></span>
                                        <?php if (strpos($registro['id'], 'pendiente_') === 0): ?>
                                            <button onclick="intentarSincronizarIndividual('<?= $registro['id'] ?>')" class="mt-2 bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs hover:bg-blue-200">
                                                Intentar ahoraa
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para zoom de imagen -->
    <div id="zoomModal" class="modal-zoom">
        <span class="close-zoom">&times;</span>
        <img class="modal-content-zoom" id="zoomedImage">
    </div>
    
    <!-- Contador de sincronización -->
    <?php if (count($pendientes) > 0): ?>
        <div id="sync-counter" class="sync-counter" title="Registros pendientes de sincronizar" onclick="mostrarTab('historial')">
            <?= count($pendientes) ?>
        </div>
    <?php endif; ?>

    <footer class="w-full bg-gray-800 text-white text-center py-4">
        <p class="text-sm">
            © 2025 Todos los derechos reservados. Ingeniero de Sistema: 
            <a href="https://2001hector.github.io/PerfilHectorP.github.io/" class="text-blue-400 hover:underline">
                Hector Jose Chamorro Nuñez
            </a>
        </p>
    </footer>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/11.4.24/sweetalert2.min.js"></script>
    <script>
        // Variables globales
        let selectedFile = null;
        const fileInput = document.getElementById('imagen');
        const previewContainer = document.getElementById('preview-container');
        const familias = <?= json_encode($familias) ?>;
        let syncInterval;
        
        // Mostrar previsualización de imagen
        fileInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            
            if (!file) {
                selectedFile = null;
                renderPreview();
                return;
            }
            
            // Validar formato
            const forbiddenExtensions = ['webp', 'svg', 'tiff', 'tif', 'bmp'];
            const fileExtension = file.name.split('.').pop().toLowerCase();
            
            if (forbiddenExtensions.includes(fileExtension) || !file.type.match('image.*')) {
                mostrarError('Formato no permitido. Use solo imágenes PNG, JPG o JPEG.');
                return;
            }
            
            // Validar dimensiones
            const img = new Image();
            img.onload = function() {
                if (this.width >= this.height) {
                    mostrarError('La imagen debe ser vertical (la altura mayor que el ancho).');
                } else {
                    selectedFile = file;
                    renderPreview();
                }
            };
            img.onerror = function() {
                mostrarError('El archivo seleccionado no es una imagen válida.');
            };
            img.src = URL.createObjectURL(file);
        });
        
        function mostrarError(mensaje) {
            Swal.fire({
                title: 'Error',
                text: mensaje,
                icon: 'error',
                confirmButtonText: 'Entendido'
            });
            fileInput.value = '';
            selectedFile = null;
            renderPreview();
        }
        
        function renderPreview() {
            previewContainer.innerHTML = '';
            
            if (!selectedFile) {
                previewContainer.innerHTML = '<p class="text-sm text-gray-500 text-center">No hay imagen seleccionada</p>';
                return;
            }
            
            const reader = new FileReader();
            
            reader.onload = function(e) {
                const previewDiv = document.createElement('div');
                previewDiv.className = 'flex flex-col items-center';
                
                const img = document.createElement('img');
                img.src = e.target.result;
                img.className = 'w-64 h-64 object-contain rounded-md cursor-zoom-in';
                img.alt = 'Previsualización de imagen';
                img.onclick = function() {
                    openZoomModal(e.target.result);
                };
                
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'mt-2 bg-red-500 text-white rounded-md px-3 py-1 text-sm hover:bg-red-600';
                removeBtn.innerHTML = 'Eliminar imagen';
                removeBtn.onclick = function() {
                    selectedFile = null;
                    fileInput.value = '';
                    renderPreview();
                };
                
                const fileName = document.createElement('p');
                fileName.className = 'text-xs text-gray-500 mt-1';
                fileName.textContent = selectedFile.name;
                
                previewDiv.appendChild(img);
                previewDiv.appendChild(removeBtn);
                previewDiv.appendChild(fileName);
                previewContainer.appendChild(previewDiv);
            };
            
            reader.readAsDataURL(selectedFile);
        }
        
        function openZoomModal(imgSrc) {
            const modal = document.getElementById('zoomModal');
            const modalImg = document.getElementById('zoomedImage');
            modal.style.display = "block";
            modalImg.src = imgSrc;
            
            if (window.innerWidth > 768) {
                modalImg.style.width = 'auto';
                modalImg.style.height = '90vh';
                modalImg.style.maxWidth = '100%';
            } else {
                modalImg.style.width = '100%';
                modalImg.style.height = 'auto';
            }
        }
        
        document.getElementsByClassName('close-zoom')[0].onclick = function() {
            document.getElementById('zoomModal').style.display = "none";
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('zoomModal');
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
        
        // Autocompletado
        function autocomplete(inp, arr) {
            let currentFocus;
            
            inp.addEventListener("input", function(e) {
                let val = this.value;
                closeAllLists();
                if (!val) { return false; }
                currentFocus = -1;
                
                const a = document.createElement("DIV");
                a.setAttribute("id", this.id + "autocomplete-list");
                a.setAttribute("class", "autocomplete-items");
                this.parentNode.appendChild(a);
                
                for (let i = 0; i < arr.length; i++) {
                    if (arr[i].cedula_padre.substr(0, val.length).toUpperCase() === val.toUpperCase()) {
                        const b = document.createElement("DIV");
                        b.innerHTML = "<strong>" + arr[i].cedula_padre.substr(0, val.length) + "</strong>";
                        b.innerHTML += arr[i].cedula_padre.substr(val.length);
                        b.innerHTML += "<input type='hidden' value='" + arr[i].cedula_padre + "'>";
                        b.innerHTML += "<small class='text-gray-500 ml-2'>" + arr[i].nombre_padre + "</small>";
                        
                        b.addEventListener("click", function() {
                            inp.value = this.getElementsByTagName("input")[0].value;
                            document.getElementById('nombre_jefe_hogar').value = arr[i].nombre_padre;
                            closeAllLists();
                        });
                        a.appendChild(b);
                    }
                }
            });
            
            inp.addEventListener("keydown", function(e) {
                let x = document.getElementById(this.id + "autocomplete-list");
                if (x) x = x.getElementsByTagName("div");
                if (e.keyCode == 40) { // Flecha abajo
                    currentFocus++;
                    addActive(x);
                } else if (e.keyCode == 38) { // Flecha arriba
                    currentFocus--;
                    addActive(x);
                } else if (e.keyCode == 13) { // Enter
                    e.preventDefault();
                    if (currentFocus > -1 && x) x[currentFocus].click();
                }
            });
            
            function addActive(x) {
                if (!x) return false;
                removeActive(x);
                if (currentFocus >= x.length) currentFocus = 0;
                if (currentFocus < 0) currentFocus = (x.length - 1);
                x[currentFocus].classList.add("autocomplete-active");
            }
            
            function removeActive(x) {
                for (let i = 0; i < x.length; i++) {
                    x[i].classList.remove("autocomplete-active");
                }
            }
            
            function closeAllLists(elmnt) {
                const x = document.getElementsByClassName("autocomplete-items");
                for (let i = 0; i < x.length; i++) {
                    if (elmnt != x[i] && elmnt != inp) {
                        x[i].parentNode.removeChild(x[i]);
                    }
                }
            }
            
            document.addEventListener("click", function(e) {
                closeAllLists(e.target);
            });
        }
        
        // Inicializar autocompletado
        autocomplete(document.getElementById("documento_jefe_hogar"), familias);
        
        // Validar formulario
        document.getElementById('registroForm').addEventListener('submit', function(e) {
            if (!selectedFile) {
                e.preventDefault();
                Swal.fire({
                    title: 'Advertencia',
                    text: 'Debe subir una imagen del registro.',
                    icon: 'warning',
                    confirmButtonText: 'Entendido'
                });
            }
        });
        
        // Funciones de sesión
        function cerrarSesion() {
            fetch('../index.php?clean_token=1', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'user_id=<?= $_SESSION['usuario_id'] ?>'
            }).then(() => {
                window.location.href = '../index.php';
            });
        }

        function limpiarTokenAlSalir() {
            navigator.sendBeacon('../index.php?clean_token=1', 'user_id=<?= $_SESSION['usuario_id'] ?>');
        }

        window.addEventListener('beforeunload', function(e) {
            const esNavegacionInterna = e.target.activeElement?.tagName === 'A' && 
                                      e.target.activeElement?.href?.startsWith(window.location.origin);
            
            if (!esNavegacionInterna) {
                limpiarTokenAlSalir();
            }
        });
        
        // Funciones de pestañas
        function mostrarTab(tab) {
            document.getElementById('seccion-registro').classList.add('hidden');
            document.getElementById('seccion-historial').classList.add('hidden');
            document.getElementById('tab-registro').classList.remove('tab-active');
            document.getElementById('tab-historial').classList.remove('tab-active');
            
            document.getElementById('seccion-' + tab).classList.remove('hidden');
            document.getElementById('tab-' + tab).classList.add('tab-active');
            
            if (tab === 'historial') {
                iniciarSincronizacionAutomatica();
            } else {
                detenerSincronizacionAutomatica();
            }
        }
        
        // Sincronización
        function intentarSincronizarIndividual(idPendiente) {
            Swal.fire({
                title: 'Sincronizando',
                text: 'Intentando enviar el registro al servidor...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                    
                    fetch('registro.php?sincronizar=' + idPendiente, {
                        method: 'POST'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: 'Éxito',
                                text: 'El registro se ha sincronizado correctamente.',
                                icon: 'success',
                                confirmButtonText: 'Aceptar'
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                title: 'Error',
                                text: data.message || 'No se pudo sincronizar el registro.',
                                icon: 'error',
                                confirmButtonText: 'Aceptar'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            title: 'Error',
                            text: 'Ocurrió un error al intentar sincronizar.',
                            icon: 'error',
                            confirmButtonText: 'Aceptar'
                        });
                    });
                }
            });
        }
        
        function iniciarSincronizacionAutomatica() {
            // Verificar cada 30 segundos si hay conexión y pendientes
            syncInterval = setInterval(() => {
                const pendientesCount = document.getElementById('pendientes-count');
                if (pendientesCount && pendientesCount.textContent.includes('pendientes')) {
                    verificarConexionYSincronizar();
                }
            }, 30000);
        }
        
        function detenerSincronizacionAutomatica() {
            if (syncInterval) {
                clearInterval(syncInterval);
            }
        }
        
        function verificarConexionYSincronizar() {
            fetch('https://www.google.com', { mode: 'no-cors' })
                .then(() => {
                    // Hay conexión - intentar sincronizar
                    fetch('registro.php?sincronizar_pendientes=1', {
                        method: 'POST'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.count > 0) {
                            // Mostrar notificación
                            if (Notification.permission === 'granted') {
                                new Notification('Sincronización completada', {
                                    body: `Se han sincronizado ${data.count} registros.`,
                                    icon: '/icon.png'
                                });
                            }
                            // Recargar para actualizar vista
                            window.location.reload();
                        }
                    });
                })
                .catch(() => {
                    // No hay conexión, no hacer nada
                });
        }
        
        // Iniciar sincronización automática al cargar
        if (document.getElementById('pendientes-count')?.textContent.includes('pendientes')) {
            iniciarSincronizacionAutomatica();
        }
        
        // Solicitar permiso para notificaciones
        if (Notification.permission !== 'denied') {
            Notification.requestPermission();
        }
    </script>
</body>
</html>