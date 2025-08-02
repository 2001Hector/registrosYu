<?php
session_start();
include('db.php');

// Procesar cambio de estado de pago
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario_id']) && isset($_POST['estado_pago'])) {
    $usuario_id = intval($_POST['usuario_id']);
    $nuevo_estado = ($_POST['estado_pago'] === 'pagado') ? 'pagado' : 'no_pagado';
    
    if ($nuevo_estado == 'pagado') {
        $fecha_inicio = date('Y-m-d');
        $fecha_fin = date('Y-m-d', strtotime('+1 month'));
        $sql = "UPDATE usuarios SET estado_pago = 'pagado', fecha_inicio_pago = '$fecha_inicio', fecha_fin_pago = '$fecha_fin' WHERE id = $usuario_id";
    } else {
        $sql = "UPDATE usuarios SET estado_pago = 'no_pagado', fecha_inicio_pago = NULL, fecha_fin_pago = NULL WHERE id = $usuario_id";
    }
     
    if (mysqli_query($conexion, $sql)) {
        $_SESSION['mensaje'] = "Estado de pago actualizado correctamente";
    } else {
        $_SESSION['error'] = "Error al actualizar: " . mysqli_error($conexion);
    }
    
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

// Verificar y actualizar SOLO los estados vencidos
$hoy = date('Y-m-d');
$sql_vencidos = "UPDATE usuarios SET estado_pago = 'no_pagado', fecha_inicio_pago = NULL, fecha_fin_pago = NULL WHERE fecha_fin_pago < '$hoy' AND estado_pago = 'pagado'";
mysqli_query($conexion, $sql_vencidos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Usuarios</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold text-center mb-8 text-gray-800">LISTA DE USUARIOS</h1>
    
    <?php if(isset($_SESSION['mensaje'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?= $_SESSION['mensaje']; unset($_SESSION['mensaje']); ?>
        </div>
    <?php endif; ?>
    
    <?php if(isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <div class="overflow-x-auto bg-white rounded-lg shadow">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-indigo-600 text-white">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Nombre</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Correo</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Teléfono</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Estado Pago</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Inicio Pago</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Fin Pago</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php
                $sql = "SELECT * FROM usuarios ORDER BY id DESC";
                $result = mysqli_query($conexion, $sql);
                
                while ($usuario = mysqli_fetch_assoc($result)):
                    $estado = $usuario['estado_pago'] ?? 'no_pagado';
                    $fecha_inicio = !empty($usuario['fecha_inicio_pago']) ? date('d/m/Y', strtotime($usuario['fecha_inicio_pago'])) : 'N/A';
                    $fecha_fin = !empty($usuario['fecha_fin_pago']) ? date('d/m/Y', strtotime($usuario['fecha_fin_pago'])) : 'N/A';
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $usuario['id'] ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($usuario['nombre']) ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($usuario['correo']) ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $usuario['telefono'] ?? 'N/A' ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <form method="post" class="inline-flex items-center">
                            <input type="hidden" name="usuario_id" value="<?= $usuario['id'] ?>">
                            <input type="hidden" name="estado_pago" value="<?= $estado == 'pagado' ? 'no_pagado' : 'pagado' ?>">
                            <label class="relative inline-flex items-center cursor-pointer mr-2">
                                <input type="checkbox" <?= $estado == 'pagado' ? 'checked' : '' ?> onchange="this.form.submit()" class="sr-only peer">
                                <div class="w-14 h-7 bg-red-500 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-green-500"></div>
                            </label>
                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $estado == 'pagado' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                <?= $estado == 'pagado' ? 'PAGADO' : 'NO PAGADO' ?>
                            </span>
                        </form>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $fecha_inicio ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $fecha_fin ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <a href="editar_usuario.php?id=<?= $usuario['id'] ?>" class="text-yellow-600 hover:text-yellow-900 mr-2">Editar</a>
                        <a href="eliminar_usuario.php?id=<?= $usuario['id'] ?>" class="text-red-600 hover:text-red-900">Eliminar</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-6 flex justify-center space-x-4">
        <a href="index.php" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition">Cerrar Sesión</a>
        <a href="registroUsuarios.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">Registrar Nuevo Usuario</a>
    </div>
</div>

<script>
    // Actualizar la página cada hora para verificar vencimientos
    setTimeout(function(){
        location.reload();
    }, 3600000); // 1 hora
    
    // Mostrar confirmación antes de eliminar
    document.querySelectorAll('a[href^="eliminar_usuario"]').forEach(link => {
        link.addEventListener('click', function(e) {
            if(!confirm('¿Estás seguro de eliminar este usuario?')) {
                e.preventDefault();
            }
        });
    });
</script>
</body>
</html>