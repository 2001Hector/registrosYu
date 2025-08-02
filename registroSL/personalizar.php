<?php
require '../db.php';
session_start();

// 1. Verificación básica de sesión simplificada
if (!isset($_SESSION['logueado']) || !$_SESSION['logueado'] || !isset($_SESSION['usuario_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'session_expired']);
        exit();
    }
    header("Location: ../index.php");
    exit();
}

// 2. Manejo de limpieza de sesión
if (isset($_GET['clean_token']) || isset($_POST['clean_token'])) {
    if (isset($_SESSION['usuario_id'])) {
        $clean_stmt = mysqli_prepare($conexion, "UPDATE usuarios SET session_token = NULL WHERE id = ?");
        mysqli_stmt_bind_param($clean_stmt, "i", $_SESSION['usuario_id']);
        mysqli_stmt_execute($clean_stmt);
    }
    
    session_unset();
    session_destroy();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit();
    }
    header("Location: ../index.php");
    exit();
}

// 3. Verificación de token de sesión menos estricta
if (isset($_SESSION['session_token'])) {
    $sql = "SELECT session_token FROM usuarios WHERE id = ?";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['usuario_id']);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    
    if ($resultado && mysqli_num_rows($resultado) > 0) {
        $datos = mysqli_fetch_assoc($resultado);
        if ($datos['session_token'] !== $_SESSION['session_token'] && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $update_sql = "UPDATE usuarios SET session_token = NULL WHERE id = ?";
            $update_stmt = mysqli_prepare($conexion, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "i", $_SESSION['usuario_id']);
            mysqli_stmt_execute($update_stmt);
            session_destroy();
            header("Location: ../index.php");
            exit();
        }
    }
}

// 4. Manejo de inactividad mejorado (30 minutos)
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 1800) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $clean_stmt = mysqli_prepare($conexion, "UPDATE usuarios SET session_token = NULL WHERE id = ?");
    mysqli_stmt_bind_param($clean_stmt, "i", $_SESSION['usuario_id']);
    mysqli_stmt_execute($clean_stmt);
    session_destroy();
    header("Location: ../index.php");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

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

// Consulta base para usuarios con búsqueda
$query = "SELECT SQL_CALC_FOUND_ROWS df.* FROM datos_familiares df 
          WHERE df.usuario_id = ? ";
$params = [$_SESSION['usuario_id']];
$types = "i";

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

// Obtener total de registros
$totalRegistros = $conexion->query("SELECT FOUND_ROWS()")->fetch_row()[0];
$totalPaginas = ceil($totalRegistros / $registrosPorPagina);

// Obtener campos personalizados
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

// Agrupar campos por familia
$campos_por_familia = [];
foreach ($campos_personalizados as $campo) {
    $datos_familiares_id = $campo['datos_familiares_id'];
    if (!isset($campos_por_familia[$datos_familiares_id])) {
        $campos_por_familia[$datos_familiares_id] = [];
    }
    $campos_por_familia[$datos_familiares_id][] = $campo;
}

// Obtener nombres de campos únicos
$nombres_campos = [];
foreach ($campos_personalizados as $campo) {
    if (!in_array($campo['nombre_campo'], $nombres_campos)) {
        $nombres_campos[] = $campo['nombre_campo'];
    }
}

// Procesar formulario de nota personalizada
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_nota'])) {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_campo'])) {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_registro'])) {
    $registro_id = $_POST['registro_id'] ?? null;
    
    if ($registro_id) {
        $query = "DELETE FROM datos_familiares WHERE id = ? AND usuario_id = ?";
        $stmt = $conexion->prepare($query);
        $stmt->bind_param("ii", $registro_id, $_SESSION['usuario_id']);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['success'] = "Registro eliminado exitosamente";
            
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

// Obtener notas personalizadas
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
    
    <style>
        .color-option {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-block;
            margin: 5px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s;
        }
        .color-option.selected {
            border-color: #000;
            transform: scale(1.2);
            box-shadow: 0 0 5px rgba(0,0,0,0.3);
        }
        .suggestions {
            position: absolute;
            z-index: 10;
            background: white;
            width: calc(100% - 30px);
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 0 0 4px 4px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .suggestion-item {
            padding: 8px 15px;
            cursor: pointer;
        }
        .suggestion-item:hover {
            background-color: #f5f5f5;
        }
        .personalize-btn {
            background: rgb(99, 102, 241);
            color: white;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .personalize-btn:hover {
            background: rgb(79, 70, 229);
            transform: scale(1.1);
        }
        .action-btn {
            background: rgb(59, 130, 246);
            color: white;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .action-btn:hover {
            background: rgb(29, 78, 216);
            transform: scale(1.1);
        }
        .delete-btn {
            background: rgb(239, 68, 68);
            color: white;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .delete-btn:hover {
            background: rgb(220, 38, 38);
            transform: scale(1.1);
        }
        @media (max-width: 768px) {
            .table-responsive {
                display: block;
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .mobile-hidden {
                display: none;
            }
            .mobile-visible {
                display: block;
            }
        }
        .pagination-slider {
            overflow-x: auto;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
        }
        .pagination-slider::-webkit-scrollbar {
            height: 8px;
        }
        .pagination-slider::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        .pagination-slider::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        .pagination-slider::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        .tabla-con-scroll {
            overflow-x: auto;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
        }
        .tabla-con-scroll table {
            min-width: 100%;
        }
        .tabla-con-scroll th, .tabla-con-scroll td {
            white-space: nowrap;
        }
        .tabla-con-scroll::-webkit-scrollbar {
            height: 8px;
        }
        .tabla-con-scroll::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        .tabla-con-scroll::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        .tabla-con-scroll::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        .bg-custom-1 {
            background-color: #FF00FF;
            box-shadow: 0 0 10px #FF00FF;
        }
        .bg-custom-2 {
            background-color: #00FFFF;
            box-shadow: 0 0 10px #00FFFF;
        }
        .bg-custom-3 {
            background-color: #FFFF00;
            box-shadow: 0 0 10px #FFFF00;
        }
        .bg-custom-4 {
            background-color: #00FF00;
            box-shadow: 0 0 10px #00FF00;
        }
        .bg-custom-5 {
            background-color: #FF1493;
            box-shadow: 0 0 10px #FF1493;
        }
        .bg-custom-6 {
            background-color: #00BFFF;
            box-shadow: 0 0 10px #00BFFF;
        }
        .bg-custom-7 {
            background-color: #FF8C00;
            box-shadow: 0 0 10px #FF8C00;
        }
        .bg-custom-8 {
            background-color: #9400D3;
            box-shadow: 0 0 10px #9400D3;
        }
        .bg-custom-9 {
            background-color: #FF4500;
            box-shadow: 0 0 10px #FF4500;
        }
        .bg-custom-10 {
            background-color: #FF00FF;
            box-shadow: 0 0 10px #FF00FF;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Personalización de Usuarios</h1>
            <div class="flex items-center gap-4">
                <a href="../registro_UsuariosN.php" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors">
                    <i class="fas fa-arrow-left mr-1"></i> Volver
                </a>
                <a href="#" onclick="cerrarSesion(); return false;" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-md text-sm font-medium">
                        Cerrar Sesión
                    </a>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="mb-4 p-4 bg-green-100 text-green-700 rounded">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Barra de búsqueda unificada -->
        <div class="mb-6 bg-white rounded-lg shadow-md p-4">
            <form method="GET" action="personalizar.php" class="flex flex-col md:flex-row gap-4">
                <div class="flex-grow relative">
                    <label for="busqueda" class="block text-gray-700 mb-2">Buscar (nombre niño/padre o cédula)</label>
                    <input type="text" name="busqueda" id="busqueda" 
                           value="<?= htmlspecialchars($busqueda) ?>" 
                           class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="Escriba para buscar..." autocomplete="off">
                    <div id="suggestions" class="suggestions hidden"></div>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <i class="fas fa-search mr-1"></i> Buscar
                    </button>
                    <?php if (!empty($busqueda)): ?>
                        <a href="personalizar.php" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500">
                            <i class="fas fa-times mr-1"></i> Limpiar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <!-- Tabla de usuarios con scroll horizontal -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden mb-8">
            <div class="tabla-con-scroll">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre Niño</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cédula Niño</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre Padre</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cédula Padre</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Edad Niño</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Edad Padre</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Parentesco</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha Entrega</th>
                            
                            <!-- Columnas para campos personalizados -->
                            <?php foreach ($nombres_campos as $nombre_campo): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= htmlspecialchars($nombre_campo) ?></th>
                            <?php endforeach; ?>
                            
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Personalización</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($usuarios as $usuario): 
                            // Obtener notas para este usuario
                            $notasUsuario = array_filter($notas, function($nota) use ($usuario) {
                                return $nota['datos_familiares_id'] == $usuario['id'];
                            });
                            $ultimaNota = !empty($notasUsuario) ? reset($notasUsuario) : null;
                            
                            // Obtener campos personalizados para este usuario
                            $camposUsuario = isset($campos_por_familia[$usuario['id']]) ? $campos_por_familia[$usuario['id']] : [];
                        ?>
                            <tr style="<?= $ultimaNota ? 'background-color: ' . htmlspecialchars($ultimaNota['color_fila']) : '' ?>">
                                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($usuario['nombre_nino']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($usuario['cedula_nino'] ?? '') ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($usuario['nombre_padre']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($usuario['cedula_padre'] ?? '') ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($usuario['edad_nino'] ?? '') ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($usuario['edad_padre'] ?? '') ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($usuario['parentesco'] ?? '') ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?= htmlspecialchars($usuario['fecha_de_entrega_racion_familiar'] ?? 'No especificada') ?>
                                </td>
                                
                                <!-- Campos personalizados -->
                                <?php foreach ($nombres_campos as $nombre_campo): 
                                    $valor_campo = '';
                                    foreach ($camposUsuario as $campo) {
                                        if ($campo['nombre_campo'] === $nombre_campo) {
                                            $valor_campo = $campo['descripcion_campo'];
                                            break;
                                        }
                                    }
                                ?>
                                    <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($valor_campo) ?></td>
                                <?php endforeach; ?>
                                
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($ultimaNota): ?>
                                        <span class="inline-block w-4 h-4 rounded-full mr-2" style="background-color: <?= htmlspecialchars($ultimaNota['color_fila']) ?>"></span>
                                        <?= htmlspecialchars($ultimaNota['titulo_nota']) ?>
                                    <?php else: ?>
                                        Sin personalización
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap flex gap-2">
                                    <button onclick="mostrarModalPersonalizacion(<?= $usuario['id'] ?>)" 
                                            class="personalize-btn" title="Personalizar">
                                        <i class="fas fa-paint-brush"></i>
                                    </button>
                                    <button onclick="mostrarModalCampoPersonalizado(<?= $usuario['id'] ?>)" 
                                            class="personalize-btn bg-purple-500 hover:bg-purple-600" title="Agregar Campo">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                    <a href="editar_usuario.php?id=<?= $usuario['id'] ?>" 
                                       class="action-btn" title="Actualizar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" action="personalizar.php" class="inline" onsubmit="return confirm('¿Estás seguro de que deseas eliminar este registro?');">
                                        <input type="hidden" name="registro_id" value="<?= $usuario['id'] ?>">
                                        <button type="submit" name="eliminar_registro" class="delete-btn" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Paginación con deslizador -->
            <?php if ($totalPaginas > 1): ?>
                <div class="bg-white px-4 py-3 border-t border-gray-200">
                    <div class="flex-1 flex justify-between items-center">
                        <div>
                            <p class="text-sm text-gray-700">
                                Mostrando <span class="font-medium"><?= $inicio + 1 ?></span> a 
                                <span class="font-medium"><?= min($inicio + $registrosPorPagina, $totalRegistros) ?></span> de 
                                <span class="font-medium"><?= $totalRegistros ?></span> resultados
                            </p>
                        </div>
                        
                        <div class="pagination-slider">
                            <nav class="inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                <a href="personalizar.php?pagina=<?= $pagina > 1 ? $pagina - 1 : 1 ?>&busqueda=<?= urlencode($busqueda) ?>" 
                                   class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                    <span class="sr-only">Anterior</span>
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                                
                                <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                    <a href="personalizar.php?pagina=<?= $i ?>&busqueda=<?= urlencode($busqueda) ?>" 
                                       class="<?= $i == $pagina ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50' ?> relative inline-flex items-center px-4 py-2 border text-sm font-medium">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <a href="personalizar.php?pagina=<?= $pagina < $totalPaginas ? $pagina + 1 : $totalPaginas ?>&busqueda=<?= urlencode($busqueda) ?>" 
                                   class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                    <span class="sr-only">Siguiente</span>
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </nav>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal de personalización -->
    <div id="modalPersonalizacion" class="fixed z-10 inset-0 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <form method="POST" action="personalizar.php">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Personalizar Usuario</h3>
                        <input type="hidden" name="datos_familiares_id" id="datosFamiliaresId">
                        
                        <div class="mb-4">
                            <label for="titulo_nota" class="block text-sm font-medium text-gray-700">Título de la nota</label>
                            <input type="text" name="titulo_nota" id="titulo_nota" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        
                        <div class="mb-4">
                            <label for="contenido_nota" class="block text-sm font-medium text-gray-700">Contenido de la nota</label>
                            <textarea name="contenido_nota" id="contenido_nota" rows="3" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Color de fila</label>
                            <input type="hidden" name="color_fila" id="color_fila_selected" value="#ffffff">
                            <div class="grid grid-cols-5 gap-2">
                                <!-- 10 colores fluorescentes brillantes -->
                                <div class="color-option" style="background-color: #FF00FF; box-shadow: 0 0 10px #FF00FF;" data-color="#FF00FF" onclick="selectColor(this)"></div>
                                <div class="color-option" style="background-color: #00FFFF; box-shadow: 0 0 10px #00FFFF;" data-color="#00FFFF" onclick="selectColor(this)"></div>
                                <div class="color-option" style="background-color: #FFFF00; box-shadow: 0 0 10px #FFFF00;" data-color="#FFFF00" onclick="selectColor(this)"></div>
                                <div class="color-option" style="background-color: #00FF00; box-shadow: 0 0 10px #00FF00;" data-color="#00FF00" onclick="selectColor(this)"></div>
                                <div class="color-option" style="background-color: #FF1493; box-shadow: 0 0 10px #FF1493;" data-color="#FF1493" onclick="selectColor(this)"></div>
                                <div class="color-option" style="background-color: #00BFFF; box-shadow: 0 0 10px #00BFFF;" data-color="#00BFFF" onclick="selectColor(this)"></div>
                                <div class="color-option" style="background-color: #FF8C00; box-shadow: 0 0 10px #FF8C00;" data-color="#FF8C00" onclick="selectColor(this)"></div>
                                <div class="color-option" style="background-color: #9400D3; box-shadow: 0 0 10px #9400D3;" data-color="#9400D3" onclick="selectColor(this)"></div>
                                <div class="color-option" style="background-color: #FF4500; box-shadow: 0 0 10px #FF4500;" data-color="#FF4500" onclick="selectColor(this)"></div>
                                <div class="color-option" style="background-color: #FF00FF; box-shadow: 0 0 10px #FF00FF;" data-color="#FF00FF" onclick="selectColor(this)"></div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="submit" name="guardar_nota" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                            Guardar
                        </button>
                        <button type="button" onclick="cerrarModal()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para campos personalizados -->
    <div id="modalCampoPersonalizado" class="fixed z-10 inset-0 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <form method="POST" action="personalizar.php">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Agregar Campo Personalizado</h3>
                        <input type="hidden" name="datos_familiares_id" id="datosFamiliaresIdCampo">
                        
                        <div class="mb-4">
                            <label for="nombre_campo" class="block text-sm font-medium text-gray-700">Nombre del Campo</label>
                            <input type="text" name="nombre_campo" id="nombre_campo" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" required>
                        </div>
                        
                        <div class="mb-4">
                            <label for="descripcion_campo" class="block text-sm font-medium text-gray-700">Descripción/Valor</label>
                            <textarea name="descripcion_campo" id="descripcion_campo" rows="3" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"></textarea>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="submit" name="guardar_campo" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-purple-600 text-base font-medium text-white hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 sm:ml-3 sm:w-auto sm:text-sm">
                            Guardar Campo
                        </button>
                        <button type="button" onclick="cerrarModalCampo()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        function mostrarModalPersonalizacion(id) {
            document.getElementById('datosFamiliaresId').value = id;
            document.getElementById('modalPersonalizacion').classList.remove('hidden');
            
            // Resetear selección de color
            document.querySelectorAll('.color-option').forEach(el => {
                el.classList.remove('selected');
                if (el.dataset.color === '#ffffff') {
                    el.classList.add('selected');
                }
            });
            document.getElementById('color_fila_selected').value = '#ffffff';
        }
        
        function mostrarModalCampoPersonalizado(id) {
            document.getElementById('datosFamiliaresIdCampo').value = id;
            document.getElementById('modalCampoPersonalizado').classList.remove('hidden');
        }
        
        function cerrarModal() {
            document.getElementById('modalPersonalizacion').classList.add('hidden');
        }
        
        function cerrarModalCampo() {
            document.getElementById('modalCampoPersonalizado').classList.add('hidden');
        }
        
        function selectColor(element) {
            document.querySelectorAll('.color-option').forEach(el => el.classList.remove('selected'));
            element.classList.add('selected');
            document.getElementById('color_fila_selected').value = element.dataset.color;
        }
        
        // Cerrar modales al hacer clic fuera de ellos
        document.querySelectorAll('.fixed').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    if (this.id === 'modalPersonalizacion') cerrarModal();
                    if (this.id === 'modalCampoPersonalizado') cerrarModalCampo();
                }
            });
        });
        
        // Autocompletado en tiempo real
        document.getElementById('busqueda').addEventListener('input', function() {
            const input = this.value.toLowerCase();
            const suggestionsDiv = document.getElementById('suggestions');
            suggestionsDiv.innerHTML = '';
            
            if (input.length > 0) {
                // Hacer una petición AJAX para obtener sugerencias
                fetch(`personalizar.php?busqueda_suggest=${encodeURIComponent(input)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.length > 0) {
                            data.forEach(item => {
                                if (item && item.toLowerCase().includes(input)) {
                                    const div = document.createElement('div');
                                    div.className = 'suggestion-item';
                                    div.textContent = item;
                                    div.onclick = function() {
                                        document.getElementById('busqueda').value = item;
                                        suggestionsDiv.classList.add('hidden');
                                        document.querySelector('form').submit();
                                    };
                                    suggestionsDiv.appendChild(div);
                                }
                            });
                            suggestionsDiv.classList.remove('hidden');
                        } else {
                            suggestionsDiv.classList.add('hidden');
                        }
                    });
            } else {
                suggestionsDiv.classList.add('hidden');
            }
        });
        
        // Ocultar sugerencias al hacer clic fuera
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.relative')) {
                document.getElementById('suggestions').classList.add('hidden');
            }
        });
        
        // Enviar formulario al seleccionar una sugerencia
        document.getElementById('suggestions').addEventListener('click', function(e) {
            if (e.target.classList.contains('suggestion-item')) {
                document.querySelector('form').submit();
            }
        });
        
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