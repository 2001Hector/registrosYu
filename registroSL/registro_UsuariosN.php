<?php
require '../db.php';
session_start();

// 1. Verificación básica de sesión (versión simplificada)
if (!isset($_SESSION['logueado']) || !$_SESSION['logueado'] || 
    !isset($_SESSION['usuario_id']) || !isset($_SESSION['pagado']) || !$_SESSION['pagado']) {
    
    // Manejo diferente para solicitudes POST/AJAX
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'session_expired']);
        exit();
    }
    header("Location: ../index.php");
    exit();
}

// 2. Manejo prioritario de solicitudes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Procesar formulario de nota personalizada
    if (isset($_POST['guardar_nota'])) {
        // Verificación adicional para POST
        if (!isset($_SESSION['usuario_id'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'session_expired']);
            exit();
        }
        
        $datos_familiares_id = $_POST['datos_familiares_id'] ?? null;
        $titulo_nota = $_POST['titulo_nota'] ?? '';
        $contenido_nota = $_POST['contenido_nota'] ?? '';
        $color_fila = $_POST['color_fila'] ?? '#ffffff';
        
        if ($datos_familiares_id) {
            $query = "SELECT id FROM datos_familiares WHERE id = ? AND usuario_id = ?";
            $stmt = $conexion->prepare($query);
            $stmt->bind_param("ii", $datos_familiares_id, $_SESSION['usuario_id']);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $query = "INSERT INTO notas_personalizadas (datos_familiares_id, titulo_nota, contenido_nota, color_fila) 
                          VALUES (?, ?, ?, ?)";
                $stmt = $conexion->prepare($query);
                $stmt->bind_param("isss", $datos_familiares_id, $titulo_nota, $contenido_nota, $color_fila);
                $stmt->execute();
                $stmt->close();
                
                $_SESSION['success'] = "Nota personalizada guardada exitosamente";
                header("Location: personalizar.php");
                exit();
            }
            $stmt->close();
        }
    }
    
    // Procesar formulario de campo personalizado
    if (isset($_POST['guardar_campo'])) {
        $datos_familiares_id = $_POST['datos_familiares_id'] ?? null;
        $nombre_campo = $_POST['nombre_campo'] ?? '';
        $descripcion_campo = $_POST['descripcion_campo'] ?? '';
        
        if ($datos_familiares_id && !empty($nombre_campo)) {
            $query = "SELECT id FROM datos_familiares WHERE id = ? AND usuario_id = ?";
            $stmt = $conexion->prepare($query);
            $stmt->bind_param("ii", $datos_familiares_id, $_SESSION['usuario_id']);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $query = "INSERT INTO campos_personalizados (datos_familiares_id, nombre_campo, descripcion_campo) 
                          VALUES (?, ?, ?)";
                $stmt = $conexion->prepare($query);
                $stmt->bind_param("iss", $datos_familiares_id, $nombre_campo, $descripcion_campo);
                $stmt->execute();
                $stmt->close();
                
                $_SESSION['success'] = "Campo personalizado guardado exitosamente";
                header("Location: personalizar.php");
                exit();
            }
            $stmt->close();
        }
    }
    
    // Procesar eliminación de registro
    if (isset($_POST['eliminar_registro'])) {
        $registro_id = $_POST['registro_id'] ?? null;
        
        if ($registro_id) {
            $query = "DELETE FROM datos_familiares WHERE id = ? AND usuario_id = ?";
            $stmt = $conexion->prepare($query);
            $stmt->bind_param("ii", $registro_id, $_SESSION['usuario_id']);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $_SESSION['success'] = "Registro eliminado exitosamente";
                
                // Eliminar notas y campos asociados
                $query = "DELETE FROM notas_personalizadas WHERE datos_familiares_id = ?";
                $stmt = $conexion->prepare($query);
                $stmt->bind_param("i", $registro_id);
                $stmt->execute();
                
                $query = "DELETE FROM campos_personalizados WHERE datos_familiares_id = ?";
                $stmt = $conexion->prepare($query);
                $stmt->bind_param("i", $registro_id);
                $stmt->execute();
            }
            $stmt->close();
            
            header("Location: personalizar.php");
            exit();
        }
    }
}

// 3. Verificación de token de sesión (menos agresiva)
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

// 4. Manejo de inactividad mejorado
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
$_SESSION['LAST_ACTIVITY'] = time(); // Actualizar en cada carga

// 5. Actualizar último acceso
$now = date('Y-m-d H:i:s');
$update_sql = "UPDATE usuarios SET ultimo_acceso = ? WHERE id = ?";
$update_stmt = mysqli_prepare($conexion, $update_sql);
mysqli_stmt_bind_param($update_stmt, "si", $now, $_SESSION['usuario_id']);
mysqli_stmt_execute($update_stmt);

// Configuración de paginación
$registrosPorPagina = 10;
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$inicio = ($pagina - 1) * $registrosPorPagina;

// Búsqueda en tiempo real
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';

// Consulta base para usuarios con búsqueda en tiempo real
$query = "SELECT SQL_CALC_FOUND_ROWS df.* FROM datos_familiares df 
          WHERE df.usuario_id = ? ";
$params = [$_SESSION['usuario_id']];
$types = "i";

// Aplicar filtros de búsqueda
if (!empty($busqueda)) {
    $query .= "AND (df.nombre_nino LIKE ? OR df.nombre_padre LIKE ? OR df.cedula_nino LIKE ? OR df.cedula_padre LIKE ?) ";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $types .= "ssss";
}

$query .= "ORDER BY df.id DESC LIMIT ?, ?";
$params[] = $inicio;
$params[] = $registrosPorPagina;
$types .= "ii";

// Obtener usuarios con paginación
$stmt = $conexion->prepare($query);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $usuarios = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $usuarios = [];
}

// Obtener total de registros para paginación
$totalRegistros = $conexion->query("SELECT FOUND_ROWS()")->fetch_row()[0];
$totalPaginas = ceil($totalRegistros / $registrosPorPagina);

// Obtener todos los campos personalizados
$query = "SELECT cp.*, df.nombre_nino, df.nombre_padre 
          FROM campos_personalizados cp
          JOIN datos_familiares df ON cp.datos_familiares_id = df.id
          WHERE df.usuario_id = ?
          ORDER BY cp.id DESC";
$stmt = $conexion->prepare($query);
$stmt->bind_param("i", $_SESSION['usuario_id']);
$stmt->execute();
$campos_personalizados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Agrupar campos personalizados por datos_familiares_id
$campos_por_familia = [];
foreach ($campos_personalizados as $campo) {
    $datos_familiares_id = $campo['datos_familiares_id'];
    if (!isset($campos_por_familia[$datos_familiares_id])) {
        $campos_por_familia[$datos_familiares_id] = [];
    }
    $campos_por_familia[$datos_familiares_id][] = $campo;
}

// Obtener todos los nombres de campos personalizados únicos
$nombres_campos = [];
foreach ($campos_personalizados as $campo) {
    if (!in_array($campo['nombre_campo'], $nombres_campos)) {
        $nombres_campos[] = $campo['nombre_campo'];
    }
}

// Obtener todas las notas personalizadas
$query = "SELECT np.*, df.nombre_nino, df.nombre_padre 
          FROM notas_personalizadas np
          JOIN datos_familiares df ON np.datos_familiares_id = df.id
          WHERE df.usuario_id = ?
          ORDER BY np.id DESC";
$stmt = $conexion->prepare($query);
$stmt->bind_param("i", $_SESSION['usuario_id']);
$stmt->execute();
$notas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Obtener sugerencias para autocompletado
$sugerencias = [];
if (!empty($busqueda)) {
    $query = "SELECT DISTINCT nombre_nino, nombre_padre, cedula_nino, cedula_padre FROM datos_familiares WHERE usuario_id = ?";
    $stmt = $conexion->prepare($query);
    $stmt->bind_param("i", $_SESSION['usuario_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sugerencias[] = $row['nombre_nino'];
        $sugerencias[] = $row['nombre_padre'];
        $sugerencias[] = $row['cedula_nino'];
        $sugerencias[] = $row['cedula_padre'];
    }
    $stmt->close();
    $sugerencias = array_unique($sugerencias);
    $sugerencias = array_filter($sugerencias);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personalización de Usuarios</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    // Función mejorada para cerrar sesión
    function cerrarSesion() {
        fetch('personalizar.php?clean_token=1', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'user_id=<?php echo $_SESSION['usuario_id']; ?>'
        }).then(response => {
            if (response.ok) {
                window.location.href = '../index.php';
            } else {
                console.error('Error al cerrar sesión');
                window.location.href = '../index.php';
            }
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
    <!-- Botón Volver -->
    <a href="../cliente.php"
       class="inline-flex items-center px-4 py-2 bg-green-600 text-white font-medium rounded-lg shadow hover:bg-green-700 transition duration-200">
        <i class="fas fa-arrow-left mr-2"></i> Volver
    </a>

    <!-- Botón Cerrar sesión -->
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
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="mb-4 p-4 bg-red-100 text-red-700 rounded">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="registro_UsuariosN.php">
                <!-- Datos básicos del niño -->
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4">Datos del Niño</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 mb-2">Nombre del Niño</label>
                            <input type="text" name="nombre_nino" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Tipo de Cédula</label>
                            <input type="text" name="tipo_cedula_nino" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Ej: V, E, P, J">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Cédula del Niño</label>
                            <input type="text" name="cedula_nino" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento_nino" id="fecha_nacimiento_nino" 
                                   onchange="calcularEdad(this, 'edad_nino')" 
                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Edad</label>
                            <input type="number" name="edad_nino" id="edad_nino" readonly
                                   class="w-full px-4 py-2 border rounded-lg bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                </div>
                
                <!-- Datos del padre/tutor -->
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4">Datos del Padre/Tutor</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 mb-2">Nombre del Padre/Tutor</label>
                            <input type="text" name="nombre_padre" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Tipo de Cédula</label>
                            <input type="text" name="tipo_cedula_padre" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Ej: V, E, P, J">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Cédula del Padre/Tutor</label>
                            <input type="text" name="cedula_padre" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento_padre" id="fecha_nacimiento_padre" 
                                   onchange="calcularEdad(this, 'edad_padre')" 
                                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Edad</label>
                            <input type="number" name="edad_padre" id="edad_padre" readonly
                                   class="w-full px-4 py-2 border rounded-lg bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Parentesco</label>
                            <input type="text" name="parentesco" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                </div>
                
                <!-- Campos personalizados dinámicos (sin campo valor) -->
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4">Campos Personalizados</h2>
                    <div id="customFieldsContainer">
                        <!-- Campos se añadirán aquí dinámicamente -->
                    </div>
                    <button type="button" id="addCustomFieldBtn" class="mt-2 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <i class="fas fa-plus mr-1"></i> Añadir Campo Personalizado
                    </button>
                </div>
                
                <!-- Fecha de ración familiar -->
                <div class="mb-6 text-center">
                    <div class="inline-block relative group">
                        <label class="block text-gray-700 mb-2">Fecha de Entrega de Ración Familiar</label>
                        <input type="date" name="fecha_racion_familiar" class="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <div class="absolute hidden group-hover:block bg-white border border-gray-300 p-2 rounded-lg shadow-lg z-10 w-64">
                            <p class="text-sm text-gray-600">Los datos no son obligatorios, pero ten en cuenta que si no completas cédula y nombre, puede afectar la búsqueda en personalización.</p>
                        </div>
                        <i class="fas fa-info-circle ml-2 text-blue-500"></i>
                    </div>
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" name="registrar" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-500">
                        <i class="fas fa-save mr-1"></i> Guardar Registro
                    </button>
                </div>
                <a href="personalizar.php" class="block text-lg font-medium text-green-700 hover:text-green-800 transition-colors flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd" />
                    </svg>
                    Realizar personalizaciones 
                </a>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('customFieldsContainer');
            const addBtn = document.getElementById('addCustomFieldBtn');
            let fieldCount = 0;
            
            addBtn.addEventListener('click', function() {
                const fieldId = `customField_${fieldCount++}`;
                
                const fieldHtml = `
                    <div class="custom-field-group mb-4 p-4 border rounded-lg bg-gray-50">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 mb-2">Nombre del Campo</label>
                                <input type="text" name="custom_field_name[]" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-gray-700 mb-2">Descripción</label>
                                <input type="text" name="custom_field_desc[]" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
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
        });
    </script>
</body>
</html>