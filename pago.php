<?php
session_start();
require 'db.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['logueado']) || $_SESSION['logueado'] !== true || !isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

// Si el usuario ya pagó, redirigir a cliente.php
if (isset($_SESSION['pagado']) && $_SESSION['pagado'] === true) {
    header("Location: cliente.php");
    exit();
}

// Verificar token de sesión (solo para usuarios no admin)
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

// Manejar inactividad (30 minutos)
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 1800)) {
    if (!isset($_SESSION['es_admin']) || !$_SESSION['es_admin']) {
        $clean_stmt = mysqli_prepare($conexion, "UPDATE usuarios SET session_token = NULL WHERE id = ?");
        mysqli_stmt_bind_param($clean_stmt, "i", $_SESSION['usuario_id']);
        mysqli_stmt_execute($clean_stmt);
    }
    session_destroy();
    header("Location: index.php");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

// Actualizar último acceso
$now = date('Y-m-d H:i:s');
$update_sql = "UPDATE usuarios SET ultimo_acceso = ? WHERE id = ?";
$update_stmt = mysqli_prepare($conexion, $update_sql);
mysqli_stmt_bind_param($update_stmt, "si", $now, $_SESSION['usuario_id']);
mysqli_stmt_execute($update_stmt);

// Datos del usuario para WhatsApp
$usuario_data = "Nombre: " . $_SESSION['usuario_nombre'] . "%0A" .
                "Email: " . $_SESSION['usuario_correo'] . "%0A" .
                "ID Usuario: " . $_SESSION['usuario_id'];
$whatsapp_link = "https://wa.me/573000000000?text=Hola,%20quiero%20realizar%20el%20pago%20de%20mi%20suscripción%0A%0A" . $usuario_data;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pago Requerido</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
    // Función para manejar el cierre de sesión
    function cerrarSesion() {
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
        navigator.sendBeacon('index.php?clean_token=1', 'user_id=<?php echo $_SESSION['usuario_id']; ?>');
    }

    // Manejar cierre de pestaña/ventana
    window.addEventListener('beforeunload', function(e) {
        const esNavegacionInterna = e.target.activeElement?.tagName === 'A' && 
                                  e.target.activeElement?.href?.startsWith(window.location.origin);
        
        if (!esNavegacionInterna) {
            limpiarTokenAlSalir();
        }
    });
    </script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="bg-white p-8 rounded-xl shadow-xl w-full max-w-md">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-bold text-gray-800">Hola, <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?></h1>
                <a href="#" onclick="cerrarSesion(); return false;" class="text-red-500 hover:text-red-700">
                    <i class="fas fa-sign-out-alt"></i> Cerrar sesión
                </a>
            </div>
            
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-blue-500 mt-1"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-blue-700">
                            Para activar tu suscripción y acceder al software, realiza el pago de $50,000 COP.
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="bg-gradient-to-r from-blue-500 to-blue-600 p-6 rounded-lg text-white mb-8">
                <h3 class="text-xl font-bold mb-3">Plan Premium</h3>
                <p class="text-3xl font-bold mb-2">$50,000 COP</p>
                <p class="text-blue-100 mb-4">Pago único mensual</p>
                <ul class="space-y-2">
                    <li class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        Acceso completo al software
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        Soporte prioritario 24/7
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        Asesorías personalizadas
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        Actualizaciones incluidas
                    </li>
                </ul>
            </div>
            
            <a href="<?php echo $whatsapp_link; ?>" target="_blank" class="w-full bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center mb-4">
                <i class="fab fa-whatsapp mr-2 text-xl"></i> Realizar pago por WhatsApp
            </a>
            
            <div class="relative flex justify-center mt-6 group">
                <p class="text-sm text-gray-500 flex items-center cursor-pointer">
                    <i class="fas fa-info-circle mr-1"></i> Políticas del servicio
                </p>
                <div class="absolute bottom-full mb-2 hidden group-hover:block w-64 bg-white p-4 rounded-lg shadow-lg border border-gray-200 z-10">
                    <h4 class="font-semibold text-gray-800 mb-2">Términos del servicio:</h4>
                    <p class="text-xs text-gray-600">
                        Este software es escalable y está en constante mejora. Al adquirir el plan, 
                        aceptas que podrás acceder a todas las actualizaciones futuras 
                        adicionales durante el periodo de tu suscripción activa.
                    </p>
                    <div class="absolute w-4 h-4 bg-white transform rotate-45 -bottom-1 left-1/2 -translate-x-1/2 border-r border-b border-gray-200"></div>
                </div>
            </div>
        </div>
    </div>
    <footer class="w-full bg-gray-800 text-white text-center py-4 mt-10 fixed bottom-0">
    <p class="text-sm">
        © 2025 Todos los derechos reservados. 
        <a href="https://tusitio.com" class="text-blue-400 hover:underline">
            Hector Jose Chamorro Nuñez
        </a>
    </p>
</footer>

</body>
</html>