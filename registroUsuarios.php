<?php
require 'db.php'; // Incluye tu archivo de conexión a la base de datos

$mensaje = '';
$error = '';

// Procesar el formulario cuando se envíe
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Recoger y sanitizar los datos del formulario
        $nombre = filter_input(INPUT_POST, 'nombre', FILTER_SANITIZE_STRING);
        $correo = filter_input(INPUT_POST, 'correo', FILTER_SANITIZE_EMAIL);
        $contrasena = password_hash($_POST['contrasena'], PASSWORD_DEFAULT);
        $telefono = !empty($_POST['telefono']) ? filter_input(INPUT_POST, 'telefono', FILTER_SANITIZE_STRING) : null;
        $estado_pago = isset($_POST['estado_pago']) ? $_POST['estado_pago'] : null;
        $fecha_inicio_pago = !empty($_POST['fecha_inicio_pago']) ? $_POST['fecha_inicio_pago'] : null;
        $fecha_fin_pago = !empty($_POST['fecha_fin_pago']) ? $_POST['fecha_fin_pago'] : null;

        // Validar campos obligatorios
        if (empty($nombre) || empty($correo) || empty($_POST['contrasena'])) {
            throw new Exception("Todos los campos marcados como obligatorios deben ser completados");
        }

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("El correo electrónico no es válido");
        }

        // Insertar en la base de datos
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
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Usuarios</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-lg shadow-md p-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-6 text-center">Registro de Usuario</h1>
        
        <?php if ($mensaje): ?>
            <div class="mb-4 p-4 bg-green-100 text-green-700 rounded">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="mb-4 p-4 bg-red-100 text-red-700 rounded">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="space-y-4">
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
            
            <div>
                <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Registrar Usuario
                </button>
                <a class="btn btn-danger" href="admin.php">regresar</a>
            </div>

        </form>
    </div>
</body>
</html>