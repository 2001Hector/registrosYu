<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require '../db.php';

// Verificación de sesión y token (igual que en tu código original)
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
            $update_sql = "UPDATE usuarios SET session_token = NULL WHERE id = ?";
            $update_stmt = mysqli_prepare($conexion, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "i", $_SESSION['usuario_id']);
            mysqli_stmt_execute($update_stmt);
            session_destroy();
            header("Location: index.php");
            exit();
        }
    } else {
        session_destroy();
        header("Location: index.php");
        exit();
    }
} else {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Manejar eliminación de registro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_registro'])) {
    $id_registro = intval($_POST['id_registro']);
    
    // Iniciar transacción para eliminar en cascada
    mysqli_begin_transaction($conexion);
    
    try {
        // 1. Eliminar imágenes asociadas
        $sql_imagenes = "DELETE FROM imagen_registro WHERE id_registro = ?";
        $stmt_imagenes = mysqli_prepare($conexion, $sql_imagenes);
        mysqli_stmt_bind_param($stmt_imagenes, "i", $id_registro);
        mysqli_stmt_execute($stmt_imagenes);
        
        // 2. Eliminar datos familiares (que a su vez eliminará en cascada campos_personalizados y notas_personalizadas)
        $sql_familiares = "DELETE FROM datos_familiares WHERE usuario_id = ?";
        $stmt_familiares = mysqli_prepare($conexion, $sql_familiares);
        mysqli_stmt_bind_param($stmt_familiares, "i", $id_registro);
        mysqli_stmt_execute($stmt_familiares);
        
        // 3. Finalmente eliminar el registro principal
        $sql_registro = "DELETE FROM registro_salidas WHERE id_registro = ?";
        $stmt_registro = mysqli_prepare($conexion, $sql_registro);
        mysqli_stmt_bind_param($stmt_registro, "i", $id_registro);
        mysqli_stmt_execute($stmt_registro);
        
        mysqli_commit($conexion);
        $_SESSION['mensaje'] = "Registro eliminado correctamente";
        $_SESSION['tipo_mensaje'] = "success";
    } catch (Exception $e) {
        mysqli_rollback($conexion);
        $_SESSION['mensaje'] = "Error al eliminar el registro: " . $e->getMessage();
        $_SESSION['tipo_mensaje'] = "error";
    }
    
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

// Configuración de paginación
$por_pagina = 10;
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$inicio = ($pagina - 1) * $por_pagina;

// Filtros de búsqueda
$filtro = "";
$params = [];
$tipos = "";

if (isset($_GET['buscar']) && !empty($_GET['busqueda'])) {
    $busqueda = "%".trim($_GET['busqueda'])."%";
    $filtro = "WHERE (r.nombre_jefe_hogar LIKE ? OR r.documento_jefe_hogar LIKE ? OR 
              d.nombre_padre LIKE ? OR d.cedula_padre LIKE ? OR 
              d.nombre_nino LIKE ? OR d.cedula_nino LIKE ?)";
    $params = array_fill(0, 6, $busqueda);
    $tipos = str_repeat("s", 6);
}

// Consulta principal con JOIN a todas las tablas
$sql = "SELECT r.*, 
               d.nombre_padre, d.cedula_padre, d.nombre_nino, d.cedula_nino,
               COUNT(i.id_imagen) as total_imagenes
        FROM registro_salidas r
        LEFT JOIN datos_familiares d ON r.id_usuario = d.usuario_id
        LEFT JOIN imagen_registro i ON r.id_registro = i.id_registro
        $filtro
        GROUP BY r.id_registro
        ORDER BY r.fecha DESC, r.id_registro DESC
        LIMIT ?, ?";

// Agregar parámetros de paginación
$params[] = $inicio;
$params[] = $por_pagina;
$tipos .= "ii";

$stmt = mysqli_prepare($conexion, $sql);
if ($params) {
    mysqli_stmt_bind_param($stmt, $tipos, ...$params);
}
mysqli_stmt_execute($stmt);
$resultado = mysqli_stmt_get_result($stmt);

// Contar total de registros para paginación
$sql_total = "SELECT COUNT(DISTINCT r.id_registro) as total
              FROM registro_salidas r
              LEFT JOIN datos_familiares d ON r.id_usuario = d.usuario_id
              $filtro";
              
$stmt_total = mysqli_prepare($conexion, $sql_total);
if ($params) {
    // Removemos los parámetros de paginación para el conteo
    $params_conteo = array_slice($params, 0, count($params)-2);
    $tipos_conteo = substr($tipos, 0, -2);
    mysqli_stmt_bind_param($stmt_total, $tipos_conteo, ...$params_conteo);
}
mysqli_stmt_execute($stmt_total);
$total_registros = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_total))['total'];
$total_paginas = ceil($total_registros / $por_pagina);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial General de Registros</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
    function confirmarEliminacion(id) {
        if (confirm('¿Está seguro que desea eliminar este registro y todos sus datos asociados? Esta acción no se puede deshacer.')) {
            document.getElementById('id_registro').value = id;
            document.getElementById('form_eliminar').submit();
        }
        return false;
    }
    </script>
</head>
<body class="bg-gray-100">
    <!-- Navbar (igual que en tu código original) -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16 md:h-20">
                <div class="flex items-center space-x-2 md:space-x-4">
                    <span class="text-sm md:text-base font-medium text-gray-700 bg-gray-100 px-2 py-1 rounded">
                        ID: <?php echo $_SESSION['usuario_id']; ?>
                    </span>
                    <span class="text-sm md:text-base font-medium text-gray-700 truncate max-w-[120px] md:max-w-none">
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                    </span>
                </div>
                
                <div class="flex items-center space-x-3 md:space-x-4">
                    <a href="areaCliente.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                        <i class="fas fa-arrow-left mr-1"></i> Volver
                    </a>
                    <a href="#" onclick="cerrarSesion(); return false;" 
                       class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-md text-sm font-medium transition-colors duration-200
                              flex items-center justify-center h-8 md:h-9 whitespace-nowrap">
                        <span class="hidden md:inline">Cerrar Sesión</span>
                        <span class="md:hidden">
                            <i class="fas fa-sign-out-alt"></i>
                        </span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Contenido principal -->
    <div class="min-h-screen pt-6 pb-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Título y buscador -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                <h1 class="text-2xl font-bold text-gray-800 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mr-3 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    Historial General de Registros
                </h1>
                
                <form method="get" class="w-full md:w-auto">
                    <div class="flex gap-2">
                        <input type="text" name="busqueda" placeholder="Buscar por nombre o cédula..." 
                               value="<?php echo isset($_GET['busqueda']) ? htmlspecialchars($_GET['busqueda']) : ''; ?>"
                               class="px-4 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 w-full">
                        <button type="submit" name="buscar" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md transition-colors">
                            <i class="fas fa-search"></i>
                        </button>
                        <?php if (isset($_GET['busqueda'])): ?>
                            <a href="<?php echo strtok($_SERVER["REQUEST_URI"], '?'); ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-md transition-colors flex items-center">
                                <i class="fas fa-times mr-1"></i> Limpiar
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Mensajes de éxito/error -->
            <?php if (isset($_SESSION['mensaje'])): ?>
                <div class="mb-6 p-4 rounded-md <?php echo $_SESSION['tipo_mensaje'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                    <?php echo $_SESSION['mensaje']; unset($_SESSION['mensaje']); unset($_SESSION['tipo_mensaje']); ?>
                </div>
            <?php endif; ?>

            <!-- Tabla de registros -->
            <div class="bg-white shadow-md rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jefe de Hogar</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Documento</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Comunidad</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Padre/Madre</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Niño/a</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Imágenes</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (mysqli_num_rows($resultado) > 0): ?>
                                <?php while ($registro = mysqli_fetch_assoc($resultado)): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo date('d/m/Y', strtotime($registro['fecha'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($registro['nombre_jefe_hogar']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($registro['documento_jefe_hogar']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($registro['nombre_comunidad']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $registro['nombre_padre'] ? htmlspecialchars($registro['nombre_padre']).' ('.htmlspecialchars($registro['cedula_padre']).')' : 'N/A'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $registro['nombre_nino'] ? htmlspecialchars($registro['nombre_nino']).' ('.htmlspecialchars($registro['cedula_nino']).')' : 'N/A'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $registro['total_imagenes'] > 0 ? $registro['total_imagenes'] : '0'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <a href="detalle_registro.php?id=<?php echo $registro['id_registro']; ?>" class="text-blue-600 hover:text-blue-900 mr-3" title="Ver detalles">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="#" onclick="return confirmarEliminacion(<?php echo $registro['id_registro']; ?>)" class="text-red-600 hover:text-red-900" title="Eliminar">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-4 text-center text-sm text-gray-500">
                                        No se encontraron registros
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Paginación -->
            <?php if ($total_paginas > 1): ?>
                <div class="mt-6 flex items-center justify-between">
                    <div class="text-sm text-gray-700">
                        Mostrando <span class="font-medium"><?php echo ($inicio + 1); ?></span> a <span class="font-medium"><?php echo min($inicio + $por_pagina, $total_registros); ?></span> de <span class="font-medium"><?php echo $total_registros; ?></span> registros
                    </div>
                    <div class="flex space-x-2">
                        <?php if ($pagina > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])); ?>" class="px-3 py-1 border rounded-md bg-white text-gray-700 hover:bg-gray-50">
                                <i class="fas fa-chevron-left"></i> Anterior
                            </a>
                        <?php endif; ?>
                        
                        <?php 
                        // Mostrar números de página
                        $inicio_pag = max(1, $pagina - 2);
                        $fin_pag = min($total_paginas, $pagina + 2);
                        
                        if ($inicio_pag > 1) {
                            echo '<a href="?'.http_build_query(array_merge($_GET, ['pagina' => 1])).'" class="px-3 py-1 border rounded-md bg-white text-gray-700 hover:bg-gray-50">1</a>';
                            if ($inicio_pag > 2) echo '<span class="px-3 py-1">...</span>';
                        }
                        
                        for ($i = $inicio_pag; $i <= $fin_pag; $i++) {
                            if ($i == $pagina) {
                                echo '<span class="px-3 py-1 border rounded-md bg-green-600 text-white">'.$i.'</span>';
                            } else {
                                echo '<a href="?'.http_build_query(array_merge($_GET, ['pagina' => $i])).'" class="px-3 py-1 border rounded-md bg-white text-gray-700 hover:bg-gray-50">'.$i.'</a>';
                            }
                        }
                        
                        if ($fin_pag < $total_paginas) {
                            if ($fin_pag < $total_paginas - 1) echo '<span class="px-3 py-1">...</span>';
                            echo '<a href="?'.http_build_query(array_merge($_GET, ['pagina' => $total_paginas])).'" class="px-3 py-1 border rounded-md bg-white text-gray-700 hover:bg-gray-50">'.$total_paginas.'</a>';
                        }
                        ?>
                        
                        <?php if ($pagina < $total_paginas): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])); ?>" class="px-3 py-1 border rounded-md bg-white text-gray-700 hover:bg-gray-50">
                                Siguiente <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Formulario oculto para eliminar registros -->
    <form id="form_eliminar" method="post" style="display: none;">
        <input type="hidden" name="eliminar_registro" value="1">
        <input type="hidden" id="id_registro" name="id_registro" value="">
    </form>

    <!-- Footer (igual que en tu código original) -->
    <footer class="w-full bg-gray-800 text-white text-center py-4">
        <p class="text-sm">
            © 2025 Todos los derechos reservados. Ingeniero de Sistema: 
            <a href="https://2001hector.github.io/PerfilHectorP.github.io/" class="text-blue-400 hover:underline">
                Hector Jose Chamorro Nuñez
            </a>
        </p>
    </footer>

    <!-- Script para cerrar sesión (igual que en tu código original) -->
    <script>
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

    function limpiarTokenAlSalir() {
        navigator.sendBeacon('index.php?clean_token=1', 'user_id=<?php echo $_SESSION['usuario_id']; ?>');
    }

    window.addEventListener('beforeunload', function(e) {
        const esNavegacionInterna = e.target.activeElement?.tagName === 'A' && 
                                  e.target.activeElement?.href?.startsWith(window.location.origin);
        
        if (!esNavegacionInterna) {
            limpiarTokenAlSalir();
        }
    });
    </script>
</body>
</html>