<?php
require 'db.php';

// Procesar acciones (eliminar, actualizar, cerrar sesión)
if (isset($_GET['action'])) {
    try {
        switch ($_GET['action']) {
            case 'delete':
                $stmt = $conexion->prepare("DELETE FROM usuarios WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $mensaje = "Usuario eliminado correctamente";
                break;
                
            case 'logout':
                $stmt = $conexion->prepare("UPDATE usuarios SET session_token = NULL WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $mensaje = "Sesión cerrada forzosamente para el usuario";
                break;
        }
    } catch (PDOException $e) {
        $error = "Error al realizar la acción: " . $e->getMessage();
    }
}

// Procesar actualización de usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    try {
        $id = $_POST['id'];
        $nombre = filter_input(INPUT_POST, 'nombre', FILTER_SANITIZE_STRING);
        $correo = filter_input(INPUT_POST, 'correo', FILTER_SANITIZE_EMAIL);
        $telefono = !empty($_POST['telefono']) ? filter_input(INPUT_POST, 'telefono', FILTER_SANITIZE_STRING) : null;
        $estado_pago = isset($_POST['estado_pago']) ? $_POST['estado_pago'] : null;
        $fecha_inicio_pago = !empty($_POST['fecha_inicio_pago']) ? $_POST['fecha_inicio_pago'] : null;
        $fecha_fin_pago = !empty($_POST['fecha_fin_pago']) ? $_POST['fecha_fin_pago'] : null;
        
        // Si se proporcionó una nueva contraseña
        $password_update = "";
        $params = [$nombre, $correo, $telefono, $estado_pago, $fecha_inicio_pago, $fecha_fin_pago, $id];
        
        if (!empty($_POST['contrasena'])) {
            $password_update = ", contraseña = ?";
            $contrasena = password_hash($_POST['contrasena'], PASSWORD_DEFAULT);
            $params = [$nombre, $correo, $telefono, $estado_pago, $fecha_inicio_pago, $fecha_fin_pago, $contrasena, $id];
        }

        $sql = "UPDATE usuarios SET 
                nombre = ?, 
                correo = ?, 
                telefono = ?, 
                estado_pago = ?, 
                fecha_inicio_pago = ?, 
                fecha_fin_pago = ?
                $password_update
                WHERE id = ?";
        
        $stmt = $conexion->prepare($sql);
        $stmt->execute($params);
        
        $mensaje = "Usuario actualizado correctamente";
    } catch (PDOException $e) {
        $error = "Error al actualizar usuario: " . $e->getMessage();
    }
}

// Procesar nuevo registro de usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['update'])) {
    try {
        $nombre = filter_input(INPUT_POST, 'nombre', FILTER_SANITIZE_STRING);
        $correo = filter_input(INPUT_POST, 'correo', FILTER_SANITIZE_EMAIL);
        $contrasena = password_hash($_POST['contrasena'], PASSWORD_DEFAULT);
        $telefono = !empty($_POST['telefono']) ? filter_input(INPUT_POST, 'telefono', FILTER_SANITIZE_STRING) : null;
        $estado_pago = isset($_POST['estado_pago']) ? $_POST['estado_pago'] : null;
        $fecha_inicio_pago = !empty($_POST['fecha_inicio_pago']) ? $_POST['fecha_inicio_pago'] : null;
        $fecha_fin_pago = !empty($_POST['fecha_fin_pago']) ? $_POST['fecha_fin_pago'] : null;

        if (empty($nombre) || empty($correo) || empty($_POST['contrasena'])) {
            throw new Exception("Todos los campos marcados como obligatorios deben ser completados");
        }

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("El correo electrónico no es válido");
        }

        $stmt = $conexion->prepare("INSERT INTO usuarios (
            nombre, 
            correo, 
            contraseña, 
            telefono, 
            estado_pago, 
            fecha_inicio_pago, 
            fecha_fin_pago
        ) VALUES (?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $nombre,
            $correo,
            $contrasena,
            $telefono,
            $estado_pago,
            $fecha_inicio_pago,
            $fecha_fin_pago
        ]);

        $mensaje = "Usuario registrado exitosamente!";
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $error = "El correo electrónico ya está registrado";
        } else {
            $error = "Error al registrar el usuario: " . $e->getMessage();
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Obtener todos los usuarios para el historial
$usuarios = $conexion->query("SELECT * FROM usuarios ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Usuarios</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen p-4">
    <div class="max-w-7xl mx-auto">
        <!-- Formulario de Registro -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-6 text-center">Registro de Usuario</h1>
            
            <?php if (isset($mensaje)): ?>
                <div class="mb-4 p-4 bg-green-100 text-green-700 rounded">
                    <?= htmlspecialchars($mensaje) ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="mb-4 p-4 bg-red-100 text-red-700 rounded">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Campos del formulario -->
                <div class="md:col-span-2">
                    <h2 class="text-xl font-semibold mb-4">Información Básica</h2>
                </div>
                
                <!-- Campo Nombre -->
                <div>
                    <label for="nombre" class="block text-sm font-medium text-gray-700">Nombre *</label>
                    <input type="text" id="nombre" name="nombre" required
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                        value="<?= isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : '' ?>">
                </div>
                
                <!-- Campo Correo -->
                <div>
                    <label for="correo" class="block text-sm font-medium text-gray-700">Correo electrónico *</label>
                    <input type="email" id="correo" name="correo" required
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                        value="<?= isset($_POST['correo']) ? htmlspecialchars($_POST['correo']) : '' ?>">
                </div>
                
                <!-- Campo Contraseña -->
                <div>
                    <label for="contrasena" class="block text-sm font-medium text-gray-700">Contraseña *</label>
                    <input type="password" id="contrasena" name="contrasena" required
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                
                <!-- Campo Teléfono -->
                <div>
                    <label for="telefono" class="block text-sm font-medium text-gray-700">Teléfono</label>
                    <input type="tel" id="telefono" name="telefono"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                        value="<?= isset($_POST['telefono']) ? htmlspecialchars($_POST['telefono']) : '' ?>">
                </div>
                
                <div class="md:col-span-2">
                    <h2 class="text-xl font-semibold mb-4">Información de Pago</h2>
                </div>
                
                <!-- Campo Estado de Pago -->
                <div>
                    <label for="estado_pago" class="block text-sm font-medium text-gray-700">Estado de Pago</label>
                    <select id="estado_pago" name="estado_pago"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Seleccione...</option>
                        <option value="no_pago" <?= (isset($_POST['estado_pago']) && $_POST['estado_pago'] == 'no_pago') ? 'selected' : '' ?>>No pagado</option>
                        <option value="pago" <?= (isset($_POST['estado_pago']) && $_POST['estado_pago'] == 'pago') ? 'selected' : '' ?>>Pagado</option>
                    </select>
                </div>
                
                <!-- Campo Fecha Inicio Pago -->
                <div>
                    <label for="fecha_inicio_pago" class="block text-sm font-medium text-gray-700">Fecha Inicio Pago</label>
                    <input type="date" id="fecha_inicio_pago" name="fecha_inicio_pago"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                        value="<?= isset($_POST['fecha_inicio_pago']) ? htmlspecialchars($_POST['fecha_inicio_pago']) : '' ?>">
                </div>
                
                <!-- Campo Fecha Fin Pago -->
                <div>
                    <label for="fecha_fin_pago" class="block text-sm font-medium text-gray-700">Fecha Fin Pago</label>
                    <input type="date" id="fecha_fin_pago" name="fecha_fin_pago"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                        value="<?= isset($_POST['fecha_fin_pago']) ? htmlspecialchars($_POST['fecha_fin_pago']) : '' ?>">
                </div>
                
                <div class="md:col-span-2 flex justify-between">
                    <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Registrar Usuario
                    </button>
                    <a href="admin.php" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-gray-600 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                        Regresar
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Historial de Usuarios -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Historial de Usuarios</h2>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Correo</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Teléfono</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado Pago</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Último Acceso</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($usuarios as $usuario): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($usuario['id']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($usuario['nombre']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($usuario['correo']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($usuario['telefono'] ?? 'N/A') ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $usuario['estado_pago'] == 'pago' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $usuario['estado_pago'] == 'pago' ? 'Pagado' : 'No pagado' ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($usuario['ultimo_acceso']): 
                                    $ultimoAcceso = new DateTime($usuario['ultimo_acceso']);
                                    $ahora = new DateTime();
                                    $diferencia = $ahora->diff($ultimoAcceso);
                                    
                                    $minutos = $diferencia->days * 24 * 60;
                                    $minutos += $diferencia->h * 60;
                                    $minutos += $diferencia->i;
                                ?>
                                    <?= $ultimoAcceso->format('d/m/Y H:i') ?>
                                    <span class="text-xs text-gray-500 block">(Hace <?= $minutos ?> minutos)</span>
                                <?php else: ?>
                                    Nunca
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2">
                                    <!-- Botón Editar -->
                                    <button onclick="openEditModal(<?= htmlspecialchars(json_encode($usuario)) ?>)" 
                                        class="text-indigo-600 hover:text-indigo-900">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <!-- Botón Eliminar -->
                                    <a href="?action=delete&id=<?= $usuario['id'] ?>" 
                                        class="text-red-600 hover:text-red-900" 
                                        onclick="return confirm('¿Estás seguro de eliminar este usuario?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    
                                    <!-- Botón Cerrar Sesión (si tiene token) -->
                                    <?php if (!empty($usuario['session_token'])): ?>
                                        <a href="?action=logout&id=<?= $usuario['id'] ?>" 
                                            class="text-yellow-600 hover:text-yellow-900" 
                                            title="Cerrar sesión forzadamente"
                                            onclick="return confirm('¿Forzar cierre de sesión de este usuario?')">
                                            <i class="fas fa-sign-out-alt"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Modal para Editar Usuario -->
    <div id="editModal" class="fixed z-10 inset-0 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <form method="POST" id="editForm" class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <input type="hidden" name="update" value="1">
                    <input type="hidden" name="id" id="editId">
                    
                    <div class="sm:flex sm:items-start">
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                            <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                                Editar Usuario
                            </h3>
                            
                            <div class="mt-4 grid grid-cols-1 gap-4">
                                <!-- Campos del formulario de edición -->
                                <div>
                                    <label for="editNombre" class="block text-sm font-medium text-gray-700">Nombre *</label>
                                    <input type="text" id="editNombre" name="nombre" required
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                </div>
                                
                                <div>
                                    <label for="editCorreo" class="block text-sm font-medium text-gray-700">Correo *</label>
                                    <input type="email" id="editCorreo" name="correo" required
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                </div>
                                
                                <div>
                                    <label for="editContrasena" class="block text-sm font-medium text-gray-700">Nueva Contraseña (dejar vacío para no cambiar)</label>
                                    <input type="password" id="editContrasena" name="contrasena"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                </div>
                                
                                <div>
                                    <label for="editTelefono" class="block text-sm font-medium text-gray-700">Teléfono</label>
                                    <input type="tel" id="editTelefono" name="telefono"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                </div>
                                
                                <div>
                                    <label for="editEstadoPago" class="block text-sm font-medium text-gray-700">Estado de Pago</label>
                                    <select id="editEstadoPago" name="estado_pago"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                        <option value="no_pago">No pagado</option>
                                        <option value="pago">Pagado</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="editFechaInicio" class="block text-sm font-medium text-gray-700">Fecha Inicio Pago</label>
                                    <input type="date" id="editFechaInicio" name="fecha_inicio_pago"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                </div>
                                
                                <div>
                                    <label for="editFechaFin" class="block text-sm font-medium text-gray-700">Fecha Fin Pago</label>
                                    <input type="date" id="editFechaFin" name="fecha_fin_pago"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:ml-3 sm:w-auto sm:text-sm">
                            Guardar Cambios
                        </button>
                        <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    // Función para abrir el modal de edición con los datos del usuario
    function openEditModal(usuario) {
        document.getElementById('editId').value = usuario.id;
        document.getElementById('editNombre').value = usuario.nombre;
        document.getElementById('editCorreo').value = usuario.correo;
        document.getElementById('editTelefono').value = usuario.telefono || '';
        document.getElementById('editEstadoPago').value = usuario.estado_pago || 'no_pago';
        document.getElementById('editFechaInicio').value = usuario.fecha_inicio_pago || '';
        document.getElementById('editFechaFin').value = usuario.fecha_fin_pago || '';
        
        document.getElementById('editModal').classList.remove('hidden');
    }
    
    // Cerrar modal al hacer clic fuera del contenido
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.add('hidden');
        }
    });
    </script>
</body>
</html>