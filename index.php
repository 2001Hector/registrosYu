<?php
session_start();
include('db.php');

// Mostrar errores para desarrollo
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Manejar limpieza de token si viene por GET/POST
if (isset($_GET['clean_token'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
        // Verificar si no es admin antes de limpiar el token
        $check_sql = "SELECT correo FROM usuarios WHERE id = ?";
        $check_stmt = mysqli_prepare($conexion, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "i", $_POST['user_id']);
        mysqli_stmt_execute($check_stmt);
        $user_data = mysqli_fetch_assoc(mysqli_stmt_get_result($check_stmt));
        
        if ($user_data && $user_data['correo'] !== 'admin@admin.com') {
            $clean_stmt = mysqli_prepare($conexion, "UPDATE usuarios SET session_token = NULL WHERE id = ?");
            mysqli_stmt_bind_param($clean_stmt, "i", $_POST['user_id']);
            mysqli_stmt_execute($clean_stmt);
        }
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_GET['clean_token'])) {
    $usuario = trim($_POST['usuario']);
    $contrasena = trim($_POST['contraseña']);

    if (empty($usuario) || empty($contrasena)) {
        $error = "Usuario y contraseña son requeridos";
    } else {
        $sql = "SELECT id, nombre, contraseña, estado_pago, correo, fecha_inicio_pago, fecha_fin_pago, session_token FROM usuarios WHERE nombre = ? OR correo = ? LIMIT 1";
        $stmt = mysqli_prepare($conexion, $sql);
        mysqli_stmt_bind_param($stmt, "ss", $usuario, $usuario);
        mysqli_stmt_execute($stmt);
        $resultado = mysqli_stmt_get_result($stmt);

        if ($resultado && mysqli_num_rows($resultado) > 0) {
            $datos = mysqli_fetch_assoc($resultado);
            
            // Verificar si ya hay una sesión activa (excepto para admin)
            $es_admin = ($datos['correo'] === 'admin@admin.com' || $datos['nombre'] === 'admin@admin.com');
            
            if (!empty($datos['session_token']) && !$es_admin) {
                echo "<script>
                    alert('Ya tienes una sesión activa. No puedes iniciar sesión en múltiples lugares simultáneamente.');
                    window.location.href = 'index.php';
                </script>";
                exit();
            }

            if (password_verify($contrasena, $datos['contraseña'])) {
                // Para admin: siempre session_token = NULL
                // Para otros usuarios: generar token normal
                $session_token = $es_admin ? null : bin2hex(random_bytes(32));
                $now = date('Y-m-d H:i:s');
                
                // Actualizar la base de datos
                $update_sql = "UPDATE usuarios SET session_token = ?, ultimo_acceso = ? WHERE id = ?";
                $update_stmt = mysqli_prepare($conexion, $update_sql);
                mysqli_stmt_bind_param($update_stmt, "ssi", $session_token, $now, $datos['id']);
                mysqli_stmt_execute($update_stmt);
                
                // Configurar sesión
                $_SESSION['logueado'] = true;
                $_SESSION['usuario_id'] = $datos['id'];
                $_SESSION['usuario_nombre'] = $datos['nombre'];
                $_SESSION['usuario_correo'] = $datos['correo'];
                $_SESSION['fecha_inicio_pago'] = $datos['fecha_inicio_pago'];
                $_SESSION['fecha_fin_pago'] = $datos['fecha_fin_pago'];
                $_SESSION['pagado'] = (strtolower(trim($datos['estado_pago'])) === 'pagado');
                $_SESSION['session_token'] = $session_token;
                $_SESSION['es_admin'] = $es_admin;

                if ($_SESSION['es_admin']) {
                    header("Location: admin.php");
                    exit();
                }

                header("Location: " . ($_SESSION['pagado'] ? "cliente.php" : "pago.php"));
                exit();
            } else {
                $error = "Contraseña incorrecta";
            }
        } else {
            $error = "Usuario no encontrado";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center bg-gray-50">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <h2 class="text-2xl font-bold mb-6 text-center">Iniciar Sesión</h2>
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        <form method="POST" class="space-y-4">
            <div>
                <input type="text" name="usuario" placeholder="Usuario o correo" required
                    class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <input type="password" name="contraseña" placeholder="Contraseña" required
                    class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <button type="submit" 
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition duration-200">
                Entrar
            </button>
        </form>
        
        <!-- Botón de WhatsApp con tu número integrado -->
        <div class="mt-6 text-center">
            <a href="https://wa.me/573208320246?text=Tengo%20problemas%20con%20el%20inicio%20de%20sesión" 
               target="_blank"
               class="inline-flex items-center justify-center px-4 py-2 bg-green-500 hover:bg-green-600 text-white font-medium rounded-lg transition duration-200">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                </svg>
                Soporte
            </a>
        </div>
    </div>
</div>
    <footer class="w-full bg-gray-800 text-white text-center py-4 mt-10 fixed bottom-0">
    <p class="text-sm">
        © 2025 Todos los derechos reservados. ingeniero de Sistema : 
        <a href="https://2001hector.github.io/PerfilHectorP.github.io/" class="text-blue-400 hover:underline">
            Hector Jose Chamorro Nuñez
        </a>
    </p>
</footer>
</body>
</html>