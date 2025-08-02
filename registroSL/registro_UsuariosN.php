<?php
require '../db.php';
session_start();

// Configuración para mostrar errores (quitar en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar'])) {
    try {
        // Validar datos básicos
        if (empty($_POST['nombre_nino']) || empty($_POST['nombre_padre'])) {
            throw new Exception("Los nombres del niño y del padre son obligatorios");
        }

        // Preparar datos
        $datos = [
            'usuario_id' => $_SESSION['usuario_id'],
            'nombre_nino' => trim($_POST['nombre_nino']),
            'tipo_cedula_nino' => trim($_POST['tipo_cedula_nino'] ?? ''),
            'cedula_nino' => trim($_POST['cedula_nino'] ?? ''),
            'fecha_nacimiento_nino' => $_POST['fecha_nacimiento_nino'] ?? null,
            'edad_nino' => $_POST['edad_nino'] ?? null,
            'nombre_padre' => trim($_POST['nombre_padre']),
            'tipo_cedula_padre' => trim($_POST['tipo_cedula_padre'] ?? ''),
            'cedula_padre' => trim($_POST['cedula_padre'] ?? ''),
            'fecha_nacimiento_padre' => $_POST['fecha_nacimiento_padre'] ?? null,
            'edad_padre' => $_POST['edad_padre'] ?? null,
            'parentesco' => trim($_POST['parentesco'] ?? ''),
            'fecha_racion_familiar' => $_POST['fecha_racion_familiar'] ?? null
        ];

        // Insertar datos principales
        $query = "INSERT INTO datos_familiares (usuario_id, nombre_nino, tipo_cedula_nino, cedula_nino, 
                  fecha_nacimiento_nino, edad_nino, nombre_padre, tipo_cedula_padre, cedula_padre, 
                  fecha_nacimiento_padre, edad_padre, parentesco, fecha_racion_familiar) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
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
            $datos['fecha_racion_familiar']
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
                    $stmt->bind_param("iss", $datos_familiares_id, $nombre, $descripcion);
                    if (!$stmt->execute()) {
                        error_log("Error al guardar campo personalizado: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }
        }
        
        $_SESSION['success'] = "Registro guardado exitosamente";
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    }
}

// 3. Verificación de token de sesión
if (isset($_SESSION['session_token']) && isset($_SESSION['usuario_id'])) {
    $sql = "SELECT session_token FROM usuarios WHERE id = ?";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['usuario_id']);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    
    if ($resultado && mysqli_num_rows($resultado) > 0) {
        $datos = mysqli_fetch_assoc($resultado);
        if ($datos['session_token'] !== $_SESSION['session_token']) {
            $update_sql = "UPDATE usuarios SET session_token = NULL WHERE id = ?";
            $update_stmt = mysqli_prepare($conexion, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "i", $_SESSION['usuario_id']);
            mysqli_stmt_execute($update_stmt);
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'invalid_token']);
                exit();
            }
            header("Location: ../index.php");
            exit();
        }
    }
}

// 4. Manejo de inactividad
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 1800)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $clean_stmt = mysqli_prepare($conexion, "UPDATE usuarios SET session_token = NULL WHERE id = ?");
        mysqli_stmt_bind_param($clean_stmt, "i", $_SESSION['usuario_id']);
        mysqli_stmt_execute($clean_stmt);
        session_destroy();
        header("Location: ../index.php");
        exit();
    }
}
$_SESSION['LAST_ACTIVITY'] = time();

// 5. Actualizar último acceso
$now = date('Y-m-d H:i:s');
$update_sql = "UPDATE usuarios SET ultimo_acceso = ? WHERE id = ?";
$update_stmt = mysqli_prepare($conexion, $update_sql);
mysqli_stmt_bind_param($update_stmt, "si", $now, $_SESSION['usuario_id']);
mysqli_stmt_execute($update_stmt);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Usuarios Familiares</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    // Función para cerrar sesión
    function cerrarSesion() {
        fetch('personalizar.php?clean_token=1', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'user_id=<?php echo $_SESSION['usuario_id']; ?>'
        }).then(response => {
            window.location.href = '../index.php';
        }).catch(error => {
            console.error('Error de red:', error);
            window.location.href = '../index.php';
        });
    }

    // Función para calcular edad automáticamente
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
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-4">Sistema de Registro</h1>
            <div class="flex items-center gap-3 mb-6">
                <a href="../cliente.php"
                   class="inline-flex items-center px-4 py-2 bg-green-600 text-white font-medium rounded-lg shadow hover:bg-green-700 transition duration-200">
                    <i class="fas fa-arrow-left mr-2"></i> Volver
                </a>
                <a href="#" onclick="cerrarSesion(); return false;"
                   class="inline-flex items-center px-4 py-2 bg-red-500 text-white font-medium rounded-lg shadow hover:bg-red-600 transition duration-200">
                    <i class="fas fa-sign-out-alt mr-2"></i> Cerrar sesión
                </a>
            </div>
        </div>
        
        <div class="max-w-3xl mx-auto bg-white rounded-lg shadow-md p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Registro de Usuario Familiar</h1>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="mb-4 p-4 bg-green-100 text-green-700 rounded">
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="mb-4 p-4 bg-red-100 text-red-700 rounded">
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <!-- Datos básicos del niño -->
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4">Datos del Niño</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 mb-2">Nombre del Niño*</label>
                            <input type="text" name="nombre_nino" required
                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   value="<?= isset($_POST['nombre_nino']) ? htmlspecialchars($_POST['nombre_nino']) : '' ?>">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Tipo de Cédula</label>
                            <input type="text" name="tipo_cedula_nino" 
                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="Ej: V, E, P, J"
                                   value="<?= isset($_POST['tipo_cedula_nino']) ? htmlspecialchars($_POST['tipo_cedula_nino']) : '' ?>">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Cédula del Niño</label>
                            <input type="text" name="cedula_nino" 
                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   value="<?= isset($_POST['cedula_nino']) ? htmlspecialchars($_POST['cedula_nino']) : '' ?>">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento_nino" id="fecha_nacimiento_nino" 
                                   onchange="calcularEdad(this, 'edad_nino')" 
                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   value="<?= isset($_POST['fecha_nacimiento_nino']) ? htmlspecialchars($_POST['fecha_nacimiento_nino']) : '' ?>">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Edad</label>
                            <input type="number" name="edad_nino" id="edad_nino" readonly
                                   class="w-full px-4 py-2 border rounded-lg bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   value="<?= isset($_POST['edad_nino']) ? htmlspecialchars($_POST['edad_nino']) : '' ?>">
                        </div>
                    </div>
                </div>
                
                <!-- Datos del padre/tutor -->
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4">Datos del Padre/Tutor</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 mb-2">Nombre del Padre/Tutor*</label>
                            <input type="text" name="nombre_padre" required
                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   value="<?= isset($_POST['nombre_padre']) ? htmlspecialchars($_POST['nombre_padre']) : '' ?>">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Tipo de Cédula</label>
                            <input type="text" name="tipo_cedula_padre" 
                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="Ej: V, E, P, J"
                                   value="<?= isset($_POST['tipo_cedula_padre']) ? htmlspecialchars($_POST['tipo_cedula_padre']) : '' ?>">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Cédula del Padre/Tutor</label>
                            <input type="text" name="cedula_padre" 
                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   value="<?= isset($_POST['cedula_padre']) ? htmlspecialchars($_POST['cedula_padre']) : '' ?>">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento_padre" id="fecha_nacimiento_padre" 
                                   onchange="calcularEdad(this, 'edad_padre')" 
                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   value="<?= isset($_POST['fecha_nacimiento_padre']) ? htmlspecialchars($_POST['fecha_nacimiento_padre']) : '' ?>">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Edad</label>
                            <input type="number" name="edad_padre" id="edad_padre" readonly
                                   class="w-full px-4 py-2 border rounded-lg bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   value="<?= isset($_POST['edad_padre']) ? htmlspecialchars($_POST['edad_padre']) : '' ?>">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Parentesco</label>
                            <input type="text" name="parentesco" 
                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   value="<?= isset($_POST['parentesco']) ? htmlspecialchars($_POST['parentesco']) : '' ?>">
                        </div>
                    </div>
                </div>
                
                <!-- Campos personalizados dinámicos -->
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4">Campos Personalizados</h2>
                    <div id="customFieldsContainer">
                        <?php
                        // Mostrar campos personalizados si hubo error y se recargó el formulario
                        if (isset($_POST['custom_field_name'])) {
                            foreach ($_POST['custom_field_name'] as $index => $nombre) {
                                $descripcion = $_POST['custom_field_desc'][$index] ?? '';
                                echo '
                                <div class="custom-field-group mb-4 p-4 border rounded-lg bg-gray-50">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-gray-700 mb-2">Nombre del Campo</label>
                                            <input type="text" name="custom_field_name[]" 
                                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                   value="'.htmlspecialchars($nombre).'">
                                        </div>
                                        <div>
                                            <label class="block text-gray-700 mb-2">Descripción</label>
                                            <input type="text" name="custom_field_desc[]" 
                                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                   value="'.htmlspecialchars($descripcion).'">
                                        </div>
                                    </div>
                                    <button type="button" class="mt-2 px-3 py-1 bg-red-500 text-white rounded-lg hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 remove-field">
                                        <i class="fas fa-trash mr-1"></i> Eliminar Campo
                                    </button>
                                </div>';
                            }
                        }
                        ?>
                    </div>
                    <button type="button" id="addCustomFieldBtn" class="mt-2 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <i class="fas fa-plus mr-1"></i> Añadir Campo Personalizado
                    </button>
                </div>
                
                <!-- Fecha de ración familiar -->
                <div class="mb-6 text-center">
                    <div class="inline-block relative group">
                        <label class="block text-gray-700 mb-2">Fecha de Entrega de Ración Familiar</label>
                        <input type="date" name="fecha_racion_familiar" 
                               class="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                               value="<?= isset($_POST['fecha_racion_familiar']) ? htmlspecialchars($_POST['fecha_racion_familiar']) : '' ?>">
                        <div class="absolute hidden group-hover:block bg-white border border-gray-300 p-2 rounded-lg shadow-lg z-10 w-64">
                            <p class="text-sm text-gray-600">Los datos no son obligatorios, pero ten en cuenta que si no completas cédula y nombre, puede afectar la búsqueda en personalización.</p>
                        </div>
                        <i class="fas fa-info-circle ml-2 text-blue-500"></i>
                    </div>
                </div>
                
                <div class="flex justify-end gap-4">
                    <button type="reset" class="px-6 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        <i class="fas fa-undo mr-1"></i> Limpiar
                    </button>
                    <button type="submit" name="registrar" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-500">
                        <i class="fas fa-save mr-1"></i> Guardar Registro
                    </button>
                </div>
                
                <div class="mt-6">
                    <a href="personalizar.php" class="text-lg font-medium text-green-700 hover:text-green-800 transition-colors flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd" />
                        </svg>
                        Realizar personalizaciones
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('customFieldsContainer');
            const addBtn = document.getElementById('addCustomFieldBtn');
            let fieldCount = container.querySelectorAll('.custom-field-group').length;
            
            addBtn.addEventListener('click', function() {
                const fieldId = `customField_${fieldCount++}`;
                
                const fieldHtml = `
                    <div class="custom-field-group mb-4 p-4 border rounded-lg bg-gray-50">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 mb-2">Nombre del Campo</label>
                                <input type="text" name="custom_field_name[]" 
                                       class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-gray-700 mb-2">Descripción</label>
                                <input type="text" name="custom_field_desc[]" 
                                       class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        <button type="button" class="mt-2 px-3 py-1 bg-red-500 text-white rounded-lg hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 remove-field">
                            <i class="fas fa-trash mr-1"></i> Eliminar Campo
                        </button>
                    </div>
                `;
                
                const div = document.createElement('div');
                div.innerHTML = fieldHtml;
                container.appendChild(div);
                
                // Agregar evento al botón de eliminar
                div.querySelector('.remove-field').addEventListener('click', function() {
                    container.removeChild(div);
                });
            });
            
            // Agregar eventos a los botones de eliminar existentes
            document.querySelectorAll('.remove-field').forEach(button => {
                button.addEventListener('click', function() {
                    container.removeChild(this.closest('.custom-field-group'));
                });
            });
        });
    </script>
</body>
</html>