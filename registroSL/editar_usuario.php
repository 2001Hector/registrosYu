<?php
require '../db.php';
session_start();

// Verificación de sesión
if (!isset($_SESSION['logueado']) || $_SESSION['logueado'] !== true || 
    !isset($_SESSION['usuario_id']) || !isset($_SESSION['pagado']) || $_SESSION['pagado'] !== true) {
    header("Location: ../index.php");
    exit();
}

// Verificar que se haya proporcionado un ID válido
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "ID de usuario no válido";
    header("Location: personalizar.php");
    exit();
}

$id_usuario = (int)$_GET['id'];

// Obtener los datos del usuario
$query = "SELECT * FROM datos_familiares WHERE id = ? AND usuario_id = ?";
$stmt = $conexion->prepare($query);
$stmt->bind_param("ii", $id_usuario, $_SESSION['usuario_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Usuario no encontrado o no tienes permiso para editarlo";
    header("Location: personalizar.php");
    exit();
}

$usuario = $result->fetch_assoc();
$stmt->close();

// Obtener campos personalizados
$query_campos = "SELECT * FROM campos_personalizados WHERE datos_familiares_id = ?";
$stmt_campos = $conexion->prepare($query_campos);
$stmt_campos->bind_param("i", $id_usuario);
$stmt_campos->execute();
$campos_personalizados = $stmt_campos->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_campos->close();

// Procesar el formulario de actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Actualizar datos principales
    if (isset($_POST['actualizar_datos'])) {
        $nombre_nino = $_POST['nombre_nino'] ?? '';
        $cedula_nino = $_POST['cedula_nino'] ?? '';
        $nombre_padre = $_POST['nombre_padre'] ?? '';
        $cedula_padre = $_POST['cedula_padre'] ?? '';
        $edad_nino = $_POST['edad_nino'] ?? '';
        $edad_padre = $_POST['edad_padre'] ?? '';
        $parentesco = $_POST['parentesco'] ?? '';
        $fecha_entrega = $_POST['fecha_entrega'] ?? '';
        $fecha_nacimiento_nino = $_POST['fecha_nacimiento_nino'] ?? '';
        $fecha_nacimiento_padre = $_POST['fecha_nacimiento_padre'] ?? '';
        $tipo_cedula_nino = $_POST['tipo_cedula_nino'] ?? '';
        $tipo_cedula_padre = $_POST['tipo_cedula_padre'] ?? '';

        $query = "UPDATE datos_familiares SET 
                  nombre_nino = ?, cedula_nino = ?, nombre_padre = ?, cedula_padre = ?, 
                  edad_nino = ?, edad_padre = ?, parentesco = ?, fecha_de_entrega_racion_familiar = ?,
                  fecha_nacimiento_nino = ?, fecha_nacimiento_padre = ?,
                  tipo_cedula_nino = ?, tipo_cedula_padre = ?
                  WHERE id = ? AND usuario_id = ?";
        
        $stmt = $conexion->prepare($query);
        $stmt->bind_param("ssssiissssssii", 
            $nombre_nino, $cedula_nino, $nombre_padre, $cedula_padre,
            $edad_nino, $edad_padre, $parentesco, $fecha_entrega,
            $fecha_nacimiento_nino, $fecha_nacimiento_padre,
            $tipo_cedula_nino, $tipo_cedula_padre,
            $id_usuario, $_SESSION['usuario_id']);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Datos actualizados correctamente";
        } else {
            $_SESSION['error'] = "Error al actualizar los datos";
        }
        $stmt->close();
    }

    // Actualizar campos personalizados
    if (isset($_POST['actualizar_campos_personalizados'])) {
        foreach ($_POST['campo_personalizado'] as $id => $valor) {
            $query = "UPDATE campos_personalizados SET descripcion_campo = ? WHERE id = ? AND datos_familiares_id = ?";
            $stmt = $conexion->prepare($query);
            $stmt->bind_param("sii", $valor, $id, $id_usuario);
            $stmt->execute();
            $stmt->close();
        }
        $_SESSION['success'] = "Campos personalizados actualizados correctamente";
    }

    // Eliminar campo personalizado
    if (isset($_POST['eliminar_campo'])) {
        $campo_id = (int)$_POST['campo_id'];
        $query = "DELETE FROM campos_personalizados WHERE id = ? AND datos_familiares_id = ?";
        $stmt = $conexion->prepare($query);
        $stmt->bind_param("ii", $campo_id, $id_usuario);
        if ($stmt->execute()) {
            $_SESSION['success'] = "Campo personalizado eliminado correctamente";
        } else {
            $_SESSION['error'] = "Error al eliminar el campo personalizado";
        }
        $stmt->close();
    }

    // Redirigir para evitar reenvío del formulario
    header("Location: editar_usuario.php?id=".$id_usuario);
    exit();
}

// Procesar agregar nuevo campo personalizado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_campo'])) {
    $nombre_campo = $_POST['nuevo_nombre_campo'] ?? '';
    $descripcion_campo = $_POST['nueva_descripcion_campo'] ?? '';

    if (!empty($nombre_campo)) {
        $query = "INSERT INTO campos_personalizados (datos_familiares_id, nombre_campo, descripcion_campo) VALUES (?, ?, ?)";
        $stmt = $conexion->prepare($query);
        $stmt->bind_param("iss", $id_usuario, $nombre_campo, $descripcion_campo);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Campo personalizado agregado correctamente";
        } else {
            $_SESSION['error'] = "Error al agregar el campo personalizado";
        }
        $stmt->close();
    } else {
        $_SESSION['error'] = "El nombre del campo no puede estar vacío";
    }

    header("Location: editar_usuario.php?id=".$id_usuario);
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Usuario</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Editar Usuario</h1>
            <div class="flex items-center gap-4">
                <a href="personalizar.php" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors">
                    <i class="fas fa-arrow-left mr-1"></i> Volver
                </a>
            </div>
        </div>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="mb-4 p-4 bg-red-100 text-red-700 rounded">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="mb-4 p-4 bg-green-100 text-green-700 rounded">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <form method="POST" action="editar_usuario.php?id=<?= $id_usuario ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Datos del niño -->
                    <div class="space-y-4">
                        <h2 class="text-lg font-semibold text-gray-700 border-b pb-2">Datos del Niño</h2>
                        
                        <div>
                            <label for="nombre_nino" class="block text-sm font-medium text-gray-700">Nombre Completo</label>
                            <input type="text" name="nombre_nino" id="nombre_nino" 
                                   value="<?= htmlspecialchars($usuario['nombre_nino'] ?? '') ?>" 
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" required>
                        </div>
                        
                        <div>
                            <label for="tipo_cedula_nino" class="block text-sm font-medium text-gray-700">Tipo de Cédula</label>
                            <input type="text" name="tipo_cedula_nino" id="tipo_cedula_nino" 
                                   value="<?= htmlspecialchars($usuario['tipo_cedula_nino'] ?? '') ?>" 
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        
                        <div>
                            <label for="cedula_nino" class="block text-sm font-medium text-gray-700">Cédula</label>
                            <input type="text" name="cedula_nino" id="cedula_nino" 
                                   value="<?= htmlspecialchars($usuario['cedula_nino'] ?? '') ?>" 
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        
                        <div>
                            <label for="fecha_nacimiento_nino" class="block text-sm font-medium text-gray-700">Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento_nino" id="fecha_nacimiento_nino" 
                                   value="<?= htmlspecialchars($usuario['fecha_nacimiento_nino'] ?? '') ?>" 
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                   onchange="calcularEdad('fecha_nacimiento_nino', 'edad_nino')">
                        </div>
                        
                        <div>
                            <label for="edad_nino" class="block text-sm font-medium text-gray-700">Edad</label>
                            <input type="number" name="edad_nino" id="edad_nino" 
                                   value="<?= htmlspecialchars($usuario['edad_nino'] ?? '') ?>" 
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                   readonly>
                        </div>
                    </div>
                    
                    <!-- Datos del padre/madre -->
                    <div class="space-y-4">
                        <h2 class="text-lg font-semibold text-gray-700 border-b pb-2">Datos del Padre/Madre</h2>
                        
                        <div>
                            <label for="nombre_padre" class="block text-sm font-medium text-gray-700">Nombre Completo</label>
                            <input type="text" name="nombre_padre" id="nombre_padre" 
                                   value="<?= htmlspecialchars($usuario['nombre_padre'] ?? '') ?>" 
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" required>
                        </div>
                        
                        <div>
                            <label for="tipo_cedula_padre" class="block text-sm font-medium text-gray-700">Tipo de Cédula</label>
                            <input type="text" name="tipo_cedula_padre" id="tipo_cedula_padre" 
                                   value="<?= htmlspecialchars($usuario['tipo_cedula_padre'] ?? '') ?>" 
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        
                        <div>
                            <label for="cedula_padre" class="block text-sm font-medium text-gray-700">Cédula</label>
                            <input type="text" name="cedula_padre" id="cedula_padre" 
                                   value="<?= htmlspecialchars($usuario['cedula_padre'] ?? '') ?>" 
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        
                        <div>
                            <label for="fecha_nacimiento_padre" class="block text-sm font-medium text-gray-700">Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento_padre" id="fecha_nacimiento_padre" 
                                   value="<?= htmlspecialchars($usuario['fecha_nacimiento_padre'] ?? '') ?>" 
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                   onchange="calcularEdad('fecha_nacimiento_padre', 'edad_padre')">
                        </div>
                        
                        <div>
                            <label for="edad_padre" class="block text-sm font-medium text-gray-700">Edad</label>
                            <input type="number" name="edad_padre" id="edad_padre" 
                                   value="<?= htmlspecialchars($usuario['edad_padre'] ?? '') ?>" 
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                   readonly>
                        </div>
                    </div>
                    
                    <!-- Datos adicionales -->
                    <div class="md:col-span-2 space-y-4">
                        <h2 class="text-lg font-semibold text-gray-700 border-b pb-2">Datos Adicionales</h2>
                        
                        <div>
                            <label for="parentesco" class="block text-sm font-medium text-gray-700">Parentesco</label>
                            <input type="text" name="parentesco" id="parentesco" 
                                   value="<?= htmlspecialchars($usuario['parentesco'] ?? '') ?>" 
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        
                        <div>
                            <label for="fecha_entrega" class="block text-sm font-medium text-gray-700">Fecha de Entrega de Ración Familiar</label>
                            <input type="date" name="fecha_entrega" id="fecha_entrega" 
                                   value="<?= htmlspecialchars($usuario['fecha_de_entrega_racion_familiar'] ?? '') ?>" 
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end gap-3">
                    <a href="personalizar.php" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        Cancelar
                    </a>
                    <button type="submit" name="actualizar_datos" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <i class="fas fa-save mr-1"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>

        <!-- Sección de Campos Personalizados -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-700 border-b pb-2 mb-4">Campos Personalizados</h2>
            
            <?php if (!empty($campos_personalizados)): ?>
                <form method="POST" action="editar_usuario.php?id=<?= $id_usuario ?>">
                    <div class="space-y-4">
                        <?php foreach ($campos_personalizados as $campo): ?>
                            <div class="flex items-start gap-4">
                                <div class="flex-grow">
                                    <label for="campo_<?= $campo['id'] ?>" class="block text-sm font-medium text-gray-700">
                                        <?= htmlspecialchars($campo['nombre_campo']) ?>
                                    </label>
                                    <input type="text" name="campo_personalizado[<?= $campo['id'] ?>]" 
                                           id="campo_<?= $campo['id'] ?>" 
                                           value="<?= htmlspecialchars($campo['descripcion_campo'] ?? '') ?>" 
                                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                </div>
                                <div class="mt-7">
                                    <button type="submit" name="eliminar_campo" value="<?= $campo['id'] ?>" 
                                            class="px-3 py-1 bg-red-500 text-white rounded-lg hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500"
                                            onclick="return confirm('¿Estás seguro de que deseas eliminar este campo?')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <input type="hidden" name="campo_id" value="<?= $campo['id'] ?>">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-4 flex justify-end gap-3">
                        <button type="submit" name="actualizar_campos_personalizados" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500">
                            <i class="fas fa-save mr-1"></i> Guardar Campos
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <p class="text-gray-500">No hay campos personalizados para este usuario.</p>
            <?php endif; ?>
        </div>

        <!-- Formulario para agregar nuevo campo personalizado -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-lg font-semibold text-gray-700 border-b pb-2 mb-4">Agregar Nuevo Campo Personalizado</h2>
            <form method="POST" action="editar_usuario.php?id=<?= $id_usuario ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="nuevo_nombre_campo" class="block text-sm font-medium text-gray-700">Nombre del Campo</label>
                        <input type="text" name="nuevo_nombre_campo" id="nuevo_nombre_campo" 
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" required>
                    </div>
                    <div>
                        <label for="nueva_descripcion_campo" class="block text-sm font-medium text-gray-700">Descripción/Valor</label>
                        <input type="text" name="nueva_descripcion_campo" id="nueva_descripcion_campo" 
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    </div>
                </div>
                <div class="mt-4 flex justify-end">
                    <button type="submit" name="agregar_campo" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500">
                        <i class="fas fa-plus mr-1"></i> Agregar Campo
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Función para calcular la edad automáticamente
        function calcularEdad(fechaInputId, edadInputId) {
            const fechaNacimiento = document.getElementById(fechaInputId).value;
            if (fechaNacimiento) {
                const hoy = new Date();
                const nacimiento = new Date(fechaNacimiento);
                let edad = hoy.getFullYear() - nacimiento.getFullYear();
                const mes = hoy.getMonth() - nacimiento.getMonth();
                
                if (mes < 0 || (mes === 0 && hoy.getDate() < nacimiento.getDate())) {
                    edad--;
                }
                
                document.getElementById(edadInputId).value = edad;
            }
        }

        // Calcular edades al cargar la página si hay fechas
        window.onload = function() {
            const fechaNino = document.getElementById('fecha_nacimiento_nino').value;
            const fechaPadre = document.getElementById('fecha_nacimiento_padre').value;
            
            if (fechaNino) {
                calcularEdad('fecha_nacimiento_nino', 'edad_nino');
            }
            if (fechaPadre) {
                calcularEdad('fecha_nacimiento_padre', 'edad_padre');
            }
        };

        // Mostrar SweetAlert para confirmaciones u otros mensajes
        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Éxito',
                text: '<?= addslashes($_SESSION['success']) ?>'
            });
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?= addslashes($_SESSION['error']) ?>'
            });
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
    </script>
</body>
</html>