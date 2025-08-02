<?php
require '../db.php';
session_start();

// Configuración para mostrar errores (quitar en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Verificar y actualizar tiempo de inactividad
if (isset($_SESSION['last_activity'])) {
    $inactive_time = 600; // 10 minutos en segundos
    $session_life = time() - $_SESSION['last_activity'];
    
    if ($session_life > $inactive_time) {
        // Limpiar session_token en la base de datos
        if (isset($_SESSION['usuario_id'])) {
            $conexion->query("UPDATE usuarios SET session_token = NULL WHERE id = {$_SESSION['usuario_id']}");
        }
        
        session_unset();
        session_destroy();
        header("Location: ../index.php?timeout=1");
        exit();
    }
}
$_SESSION['last_activity'] = time();

// 1. Verificación de sesión
if (!isset($_SESSION['logueado']) || !$_SESSION['logueado'] || 
    !isset($_SESSION['usuario_id']) || !isset($_SESSION['pagado']) || !$_SESSION['pagado']) {
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'session_expired']);
        exit();
    }
    header("Location: ../index.php");
    exit();
}

// 2. Procesar formulario principal de registro
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['registrar'])) {
        try {
            // Preparar datos (todos los campos son opcionales)
            $datos = [
                'usuario_id' => $_SESSION['usuario_id'],
                'nombre_nino' => trim($_POST['nombre_nino'] ?? ''),
                'tipo_cedula_nino' => trim($_POST['tipo_cedula_nino'] ?? ''),
                'cedula_nino' => trim($_POST['cedula_nino'] ?? ''),
                'fecha_nacimiento_nino' => !empty($_POST['fecha_nacimiento_nino']) ? $_POST['fecha_nacimiento_nino'] : null,
                'edad_nino' => !empty($_POST['edad_nino']) ? $_POST['edad_nino'] : null,
                'nombre_padre' => trim($_POST['nombre_padre'] ?? ''),
                'tipo_cedula_padre' => trim($_POST['tipo_cedula_padre'] ?? ''),
                'cedula_padre' => trim($_POST['cedula_padre'] ?? ''),
                'fecha_nacimiento_padre' => !empty($_POST['fecha_nacimiento_padre']) ? $_POST['fecha_nacimiento_padre'] : null,
                'edad_padre' => !empty($_POST['edad_padre']) ? $_POST['edad_padre'] : null,
                'parentesco' => trim($_POST['parentesco'] ?? ''),
                'fecha_de_entrega_racion_familiar' => !empty($_POST['fecha_de_entrega_racion_familiar']) ? $_POST['fecha_de_entrega_racion_familiar'] : null
            ];

            // Insertar datos principales
            $query = "INSERT INTO datos_familiares (
                        usuario_id, nombre_nino, tipo_cedula_nino, cedula_nino, 
                        fecha_nacimiento_nino, edad_nino, nombre_padre, tipo_cedula_padre, cedula_padre, 
                        fecha_nacimiento_padre, edad_padre, parentesco, fecha_de_entrega_racion_familiar
                      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conexion->prepare($query);
            if (!$stmt) {
                throw new Exception("Error al preparar la consulta: " . $conexion->error);
            }
            
            $stmt->bind_param("issssissssiss", 
                $datos['usuario_id'],
                $datos['nombre_nino'],
                $datos['tipo_cedula_nino'],
                $datos['cedula_nino'],
                $datos['fecha_nacimiento_nino'],
                $datos['edad_nino'],
                $datos['nombre_padre'],
                $datos['tipo_cedula_padre'],
                $datos['cedula_padre'],
                $datos['fecha_nacimiento_padre'],
                $datos['edad_padre'],
                $datos['parentesco'],
                $datos['fecha_de_entrega_racion_familiar']
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Error al guardar el registro: " . $stmt->error);
            }
            
            $datos_familiares_id = $stmt->insert_id;
            $stmt->close();
            
            // Procesar campos personalizados si existen
            if (!empty($_POST['custom_field_name'])) {
                foreach ($_POST['custom_field_name'] as $index => $nombre) {
                    $nombre = trim($nombre);
                    if (!empty($nombre)) {
                        $descripcion = trim($_POST['custom_field_desc'][$index] ?? '');
                        
                        $query = "INSERT INTO campos_personalizados (datos_familiares_id, nombre_campo, descripcion_campo) 
                                  VALUES (?, ?, ?)";
                        $stmt = $conexion->prepare($query);
                        if (!$stmt) {
                            throw new Exception("Error al preparar consulta de campos personalizados: " . $conexion->error);
                        }
                        $stmt->bind_param("iss", $datos_familiares_id, $nombre, $descripcion);
                        if (!$stmt->execute()) {
                            throw new Exception("Error al guardar campo personalizado: " . $stmt->error);
                        }
                        $stmt->close();
                    }
                }
            }
            
            $_SESSION['success'] = "Registro guardado exitosamente";
            header("Location: ".$_SERVER['PHP_SELF']);
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
            header("Location: ".$_SERVER['PHP_SELF']);
            exit();
        }
    } elseif (isset($_POST['personalizar'])) {
        // Redirigir a página de personalización
        header("Location: personalizar.php");
        exit();
    } elseif (isset($_POST['volver'])) {
        // Redirigir a página anterior
        header("Location: menu_principal.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Usuarios Familiares</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100 pb-16">
    <!-- Navbar con botones -->
    <nav class="bg-white shadow-md py-4 px-6">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-xl font-bold text-gray-800">Registro Familiar</h1>
            <div class="flex space-x-4">
                <form method="POST" class="inline">
                    <button type="submit" name="volver" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition">
                        <i class="fas fa-arrow-left mr-1"></i> Volver
                    </button>
                </form>
                <form method="POST" class="inline">
                    <button type="submit" name="personalizar" class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition">
                        <i class="fas fa-cog mr-1"></i> Personalizar
                    </button>
                </form>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8">
        <div class="max-w-3xl mx-auto bg-white rounded-lg shadow-md p-6">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="mb-4 p-4 bg-green-100 text-green-700 rounded">
                    <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="mb-4 p-4 bg-red-100 text-red-700 rounded">
                    <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <!-- Datos del niño -->
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4">Datos del Niño</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 mb-2">Nombre del Niño</label>
                            <input type="text" name="nombre_nino" class="w-full px-4 py-2 border rounded-lg">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Tipo de Cédula</label>
                            <input type="text" name="tipo_cedula_nino" class="w-full px-4 py-2 border rounded-lg">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Cédula</label>
                            <input type="text" name="cedula_nino" class="w-full px-4 py-2 border rounded-lg">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento_nino" class="w-full px-4 py-2 border rounded-lg" onchange="calcularEdad(this, 'edad_nino')">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Edad</label>
                            <input type="number" id="edad_nino" name="edad_nino" class="w-full px-4 py-2 border rounded-lg" readonly>
                        </div>
                    </div>
                </div>
                
                <!-- Datos del padre/tutor -->
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4">Datos del Padre/Tutor</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 mb-2">Nombre del Padre/Tutor</label>
                            <input type="text" name="nombre_padre" class="w-full px-4 py-2 border rounded-lg">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Tipo de Cédula</label>
                            <input type="text" name="tipo_cedula_padre" class="w-full px-4 py-2 border rounded-lg">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Cédula</label>
                            <input type="text" name="cedula_padre" class="w-full px-4 py-2 border rounded-lg">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento_padre" class="w-full px-4 py-2 border rounded-lg" onchange="calcularEdad(this, 'edad_padre')">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Edad</label>
                            <input type="number" id="edad_padre" name="edad_padre" class="w-full px-4 py-2 border rounded-lg" readonly>
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Parentesco</label>
                            <input type="text" name="parentesco" class="w-full px-4 py-2 border rounded-lg">
                        </div>
                    </div>
                </div>
                
                <!-- Campos personalizados dinámicos -->
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4">Campos Personalizados</h2>
                    <div id="customFieldsContainer"></div>
                    <button type="button" id="addCustomFieldBtn" class="mt-2 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition">
                        <i class="fas fa-plus mr-1"></i> Añadir Campo
                    </button>
                </div>
                
                <!-- Fecha de ración familiar -->
                <div class="mb-6 text-center">
                    <label class="block text-gray-700 mb-2">Fecha de Entrega de Ración Familiar</label>
                    <input type="date" name="fecha_de_entrega_racion_familiar" class="px-4 py-2 border rounded-lg">
                </div>
                
                <div class="flex justify-end gap-4">
                    <button type="reset" class="px-6 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition">
                        <i class="fas fa-undo mr-1"></i> Limpiar
                    </button>
                    <button type="submit" name="registrar" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition">
                        <i class="fas fa-save mr-1"></i> Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <footer class="w-full bg-gray-800 text-white text-center py-4 fixed bottom-0">
        <p class="text-sm">
            © 2025 Todos los derechos reservados. Ingeniero de Sistema: 
            <a href="https://2001hector.github.io/PerfilHectorP.github.io/" class="text-blue-400 hover:underline">
                Hector Jose Chamorro Nuñez
            </a>
        </p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('customFieldsContainer');
            const addBtn = document.getElementById('addCustomFieldBtn');
            
            addBtn.addEventListener('click', function() {
                const fieldHtml = `
                    <div class="custom-field-group mb-4 p-4 border rounded-lg bg-gray-50">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 mb-2">Nombre del Campo</label>
                                <input type="text" name="custom_field_name[]" class="w-full px-4 py-2 border rounded-lg">
                            </div>
                            <div>
                                <label class="block text-gray-700 mb-2">Descripción</label>
                                <input type="text" name="custom_field_desc[]" class="w-full px-4 py-2 border rounded-lg">
                            </div>
                        </div>
                        <button type="button" class="mt-2 px-3 py-1 bg-red-500 text-white rounded-lg hover:bg-red-600 transition remove-field">
                            <i class="fas fa-trash mr-1"></i> Eliminar
                        </button>
                    </div>
                `;
                
                const div = document.createElement('div');
                div.innerHTML = fieldHtml;
                container.appendChild(div);
                
                div.querySelector('.remove-field').addEventListener('click', function() {
                    container.removeChild(div);
                });
            });
        });

        function calcularEdad(fechaInput, edadInputId) {
            const fechaNacimiento = new Date(fechaInput.value);
            if (isNaN(fechaNacimiento.getTime())) return;
            
            const hoy = new Date();
            let edad = hoy.getFullYear() - fechaNacimiento.getFullYear();
            const mes = hoy.getMonth() - fechaNacimiento.getMonth();
            
            if (mes < 0 || (mes === 0 && hoy.getDate() < fechaNacimiento.getDate())) {
                edad--;
            }
            
            document.getElementById(edadInputId).value = edad;
        }
    </script>
</body>
</html>