<?php
session_start();
require 'db.php';

// Verificación básica de sesión
if (!isset($_SESSION['logueado']) || !$_SESSION['logueado'] || !$_SESSION['pagado']) {
    header("Location: index.php");
    exit();
}

// Verificación de token de sesión
if (isset($_SESSION['session_token']) && isset($_SESSION['usuario_id'])) {
    $sql = "SELECT session_token FROM usuarios WHERE id = ?";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['usuario_id']);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    
    if ($resultado && mysqli_num_rows($resultado) > 0) {
        $datos = mysqli_fetch_assoc($resultado);
        if ($datos['session_token'] !== $_SESSION['session_token']) {
            // Token no coincide - posible sesión duplicada
            $update_sql = "UPDATE usuarios SET session_token = NULL WHERE id = ?";
            $update_stmt = mysqli_prepare($conexion, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "i", $_SESSION['usuario_id']);
            mysqli_stmt_execute($update_stmt);
            session_destroy();
            header("Location: index.php");
            exit();
        }
    } else {
        // Usuario no encontrado
        session_destroy();
        header("Location: index.php");
        exit();
    }
} else {
    // No hay token de sesión
    session_destroy();
    header("Location: index.php");
    exit();
}

// Actualizar último acceso
$now = date('Y-m-d H:i:s');
$update_sql = "UPDATE usuarios SET ultimo_acceso = ? WHERE id = ?";
$update_stmt = mysqli_prepare($conexion, $update_sql);
mysqli_stmt_bind_param($update_stmt, "si", $now, $_SESSION['usuario_id']);
mysqli_stmt_execute($update_stmt);

// Formatear fechas para mostrar
$fecha_inicio = date('d/m/Y', strtotime($_SESSION['fecha_inicio_pago']));
$fecha_fin = date('d/m/Y', strtotime($_SESSION['fecha_fin_pago']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Área de Cliente</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    // Función para manejar el cierre de sesión
    function cerrarSesion() {
        // Limpiar token via AJAX
        fetch('index.php?clean_token=1', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'user_id=<?php echo $_SESSION['usuario_id']; ?>'
        }).then(() => {
            window.location.href = 'index.php';
        });
    }

    // Función para limpiar token al cerrar pestaña/ventana
    function limpiarTokenAlSalir() {
        // Usamos sendBeacon porque es más confiable para peticiones al cerrar la pestaña
        navigator.sendBeacon('index.php?clean_token=1', 'user_id=<?php echo $_SESSION['usuario_id']; ?>');
    }

    // Manejar cierre de pestaña/ventana
    window.addEventListener('beforeunload', function(e) {
        // Verificar si el evento fue causado por un enlace interno
        const esNavegacionInterna = e.target.activeElement?.tagName === 'A' && 
                                  e.target.activeElement?.href?.startsWith(window.location.origin);
        
        // Solo cerrar sesión si no es un clic en un enlace interno
        if (!esNavegacionInterna) {
            limpiarTokenAlSalir();
        }
    });
    </script>
</head>
<body class="bg-gray-100">
    <!-- Navbar superior con información del usuario -->
    <nav class="bg-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-4">
                    <span class="font-semibold text-gray-700">ID: <?php echo $_SESSION['usuario_id']; ?></span>
                    <span class="font-semibold text-gray-700">Usuario: <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?></span>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="flex flex-col text-right">
                        <span class="text-sm text-gray-500">Inicio: <?php echo $fecha_inicio; ?></span>
                        <span class="text-sm text-gray-500">Fin: <?php echo $fecha_fin; ?></span>
                    </div>
                    <a href="#" onclick="cerrarSesion(); return false;" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-md text-sm font-medium">
                        Cerrar Sesión
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Contenido principal -->
    <div class="min-h-screen pt-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white p-8 rounded-lg shadow-md">
                <h1 class="text-3xl font-bold mb-6 text-center">Bienvenido al Área de Clientes</h1>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <h2 class="text-xl font-semibold mb-2">Información de Pago</h2>
                        <p><span class="font-medium">ID Usuario:</span> <?php echo $_SESSION['usuario_id']; ?></p>
                        <p><span class="font-medium">Periodo:</span> <?php echo $fecha_inicio; ?> al <?php echo $fecha_fin; ?></p>
                        <p><span class="font-medium">Estado:</span> <span class="text-green-600">Pagado</span></p>
                    </div>
                    <div class="bg-white p-4 rounded-lg border border-gray-200">
                        <h3 class="text-md font-medium text-gray-700 mb-3">Soporte al cliente</h3>
                        <a 
                            href="https://wa.me/[TU_NUMERO]?text=Hola,%20necesito%20ayuda" 
                            target="_blank"
                            class="inline-flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white text-sm font-normal py-2 px-3 rounded transition-colors duration-200"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-6.29-3.51c.174.386.512.822.942 1.175.418.342.913.523 1.41.523.215 0 .432-.028.633-.085.466-.123.88-.394 1.19-.784.274-.342.482-.744.6-1.184.13-.495.153-.987.07-1.487-.056-.36-.222-.706-.479-.983a1.419 1.419 0 0 0-1.009-.42c-.35 0-.701.119-.983.42-.326.344-.52.828-.522 1.314v.03c-.01.368.086.734.276 1.05h-.01z"/>
                                <path d="M12 22a9.96 9.96 0 0 1-7.071-2.929A9.96 9.96 0 0 1 2 12a9.96 9.96 0 0 1 2.929-7.071A9.96 9.96 0 0 1 12 2a9.96 9.96 0 0 1 7.071 2.929A9.96 9.96 0 0 1 22 12a9.96 9.96 0 0 1-2.929 7.071A9.96 9.96 0 0 1 12 22zm0-18c-4.411 0-8 3.589-8 8s3.589 8 8 8 8-3.589 8-8-3.589-8-8-8z"/>
                            </svg>
                            WhatsApp
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <br><br>
        <div class="bg-green-50 p-6 rounded-lg shadow-md border border-green-100 transition-all hover:shadow-lg">
            <a href="registroSL/registro.php" class="block text-lg font-medium text-green-700 hover:text-green-800 transition-colors flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd" />
                </svg>
                Realizar registro
            </a>
            <br>
            <a href="registroSL/registro_UsuariosN.php" class="block text-lg font-medium text-green-700 hover:text-green-800 transition-colors flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd" />
                </svg>
                 Realizar registro de usuarios nuevos
            </a>
            <br>
            <a href="reportesPDF/reportesRegistros.php" class="block text-lg font-medium text-green-700 hover:text-green-800 transition-colors flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd" />
                </svg>
                Descargar reportes de registros PDF
            </a>
        </div>
    </div>
</body>
</html>