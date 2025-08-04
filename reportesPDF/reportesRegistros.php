<?php
session_start();
require '../db.php';

if (!isset($_SESSION['logueado']) || $_SESSION['logueado'] !== true || !isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

// Verificación de token para no-admins
if (!isset($_SESSION['es_admin']) || !$_SESSION['es_admin']) {
    $sql = "SELECT session_token FROM usuarios WHERE id = ?";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['usuario_id']);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    
    if ($resultado && mysqli_num_rows($resultado) > 0) {
        $datos = mysqli_fetch_assoc($resultado);
        if ($datos['session_token'] !== $_SESSION['session_token']) {
            $clean_stmt = mysqli_prepare($conexion, "UPDATE usuarios SET session_token = NULL WHERE id = ?");
            mysqli_stmt_bind_param($clean_stmt, "i", $_SESSION['usuario_id']);
            mysqli_stmt_execute($clean_stmt);
            session_destroy();
            header("Location: index.php");
            exit();
        }
    }
}

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

// Actualizar último acceso
$update_sql = "UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?";
$update_stmt = mysqli_prepare($conexion, $update_sql);
mysqli_stmt_bind_param($update_stmt, "i", $_SESSION['usuario_id']);
mysqli_stmt_execute($update_stmt);

// Obtener comunidades para autocompletado
$comunidades = [];
$sql_comunidades = "SELECT DISTINCT nombre_comunidad FROM registro_salidas 
                   WHERE nombre_comunidad IS NOT NULL AND nombre_comunidad != '' 
                   AND id_usuario = ?
                   ORDER BY nombre_comunidad";
$stmt_comunidades = mysqli_prepare($conexion, $sql_comunidades);
mysqli_stmt_bind_param($stmt_comunidades, "i", $_SESSION['usuario_id']);
mysqli_stmt_execute($stmt_comunidades);
$result_comunidades = mysqli_stmt_get_result($stmt_comunidades);

if ($result_comunidades) {
    while($com = mysqli_fetch_assoc($result_comunidades)) {
        $comunidades[] = $com['nombre_comunidad'];
    }
}

// Obtener nombres de salida para autocompletado
$nombres_salida = [];
$sql_salidas = "SELECT DISTINCT nombre_salida FROM registro_salidas 
               WHERE nombre_salida IS NOT NULL AND nombre_salida != '' 
               AND id_usuario = ?
               ORDER BY nombre_salida";
$stmt_salidas = mysqli_prepare($conexion, $sql_salidas);
mysqli_stmt_bind_param($stmt_salidas, "i", $_SESSION['usuario_id']);
mysqli_stmt_execute($stmt_salidas);
$result_salidas = mysqli_stmt_get_result($stmt_salidas);

if ($result_salidas) {
    while($salida = mysqli_fetch_assoc($result_salidas)) {
        $nombres_salida[] = $salida['nombre_salida'];
    }
}

// Obtener tipos de RFPP disponibles
$tipos_rfpp = [];
$sql_tipos = "SELECT DISTINCT tipo_de_rfpp FROM registro_salidas 
             WHERE tipo_de_rfpp IS NOT NULL AND tipo_de_rfpp != '' 
             AND id_usuario = ?
             ORDER BY tipo_de_rfpp";
$stmt_tipos = mysqli_prepare($conexion, $sql_tipos);
mysqli_stmt_bind_param($stmt_tipos, "i", $_SESSION['usuario_id']);
mysqli_stmt_execute($stmt_tipos);
$result_tipos = mysqli_stmt_get_result($stmt_tipos);

if ($result_tipos) {
    while($tipo = mysqli_fetch_assoc($result_tipos)) {
        $tipos_rfpp[] = $tipo['tipo_de_rfpp'];
    }
}

// Configuración de paginación
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Procesar filtros
$filtros = ["r.id_usuario = ?"];
$params = [$_SESSION['usuario_id']];
$types = 'i';

// Determinar si mostrar columna tipo_de_rfpp
$mostrar_tipo_rfpp = false;

if (!empty($_GET['tiene_rfpp']) && $_GET['tiene_rfpp'] === 'si') {
    $mostrar_tipo_rfpp = true;
    
    if (!empty($_GET['tipo_rfpp'])) {
        $filtros[] = "r.tipo_de_rfpp = ?";
        $params[] = $_GET['tipo_rfpp'];
        $types .= 's';
    } else {
        $filtros[] = "r.tipo_de_rfpp IS NOT NULL AND r.tipo_de_rfpp != ''";
    }
} elseif (isset($_GET['tiene_rfpp']) && $_GET['tiene_rfpp'] === 'no') {
    $filtros[] = "(r.tipo_de_rfpp IS NULL OR r.tipo_de_rfpp = '')";
}

if (!empty($_GET['nombre_salida'])) {
    $filtros[] = "r.nombre_salida = ?";
    $params[] = $_GET['nombre_salida'];
    $types .= 's';
}

if (!empty($_GET['comunidad'])) {
    $filtros[] = "r.nombre_comunidad = ?";
    $params[] = $_GET['comunidad'];
    $types .= 's';
}

// Consulta para contar total de registros
$sql_count = "SELECT COUNT(r.id_registro) as total 
              FROM registro_salidas r
              WHERE " . implode(" AND ", $filtros);
$stmt_count = mysqli_prepare($conexion, $sql_count);
mysqli_stmt_bind_param($stmt_count, $types, ...$params);
mysqli_stmt_execute($stmt_count);
$result_count = mysqli_stmt_get_result($stmt_count);
$total_registros = mysqli_fetch_assoc($result_count)['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);

// Ajustar página actual si es mayor que el total de páginas
if ($pagina_actual > $total_paginas && $total_paginas > 0) {
    $pagina_actual = $total_paginas;
    $offset = ($pagina_actual - 1) * $registros_por_pagina;
}

// Consulta principal con paginación
$sql = "SELECT r.id_registro, r.fecha, r.documento_jefe_hogar, r.nombre_jefe_hogar, 
               r.nombre_comunidad, r.nombre_salida, r.tipo_de_rfpp, i.imagen 
        FROM registro_salidas r
        LEFT JOIN imagen_registro i ON r.id_registro = i.id_registro
        WHERE " . implode(" AND ", $filtros) . "
        ORDER BY r.fecha DESC
        LIMIT ?, ?";

// Agregar parámetros de paginación
$params[] = $offset;
$params[] = $registros_por_pagina;
$types .= 'ii';

$stmt = mysqli_prepare($conexion, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$resultado = mysqli_stmt_get_result($stmt);
$registros = [];

if ($resultado) {
    while($row = mysqli_fetch_assoc($resultado)) {
        $registros[] = $row;
    }
}

// Obtener todos los IDs que cumplen con los filtros (para selección masiva)
$sql_all_ids = "SELECT r.id_registro 
                FROM registro_salidas r
                WHERE " . implode(" AND ", $filtros);
$stmt_all_ids = mysqli_prepare($conexion, $sql_all_ids);
mysqli_stmt_bind_param($stmt_all_ids, substr($types, 0, -2), ...array_slice($params, 0, -2));
mysqli_stmt_execute($stmt_all_ids);
$result_all_ids = mysqli_stmt_get_result($stmt_all_ids);
$all_ids = [];
while($row = mysqli_fetch_assoc($result_all_ids)) {
    $all_ids[] = $row['id_registro'];
}
$all_ids_json = json_encode($all_ids);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Reportes</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #4CAF50;
            color: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: none;
            animation: fadeIn 0.5s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @media (max-width: 768px) {
            table.responsive-table {
                width: 100%;
                border-collapse: collapse;
            }
            
            table.responsive-table thead {
                display: none;
            }
            
            table.responsive-table tr {
                display: block;
                margin-bottom: 1rem;
                border: 1px solid #e2e8f0;
                border-radius: 0.5rem;
                overflow: hidden;
            }
            
            table.responsive-table td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.75rem;
                text-align: right;
                border-bottom: 1px solid #e2e8f0;
            }
            
            table.responsive-table td:before {
                content: attr(data-label);
                font-weight: 600;
                margin-right: 1rem;
                color: #4a5568;
            }
            
            table.responsive-table td:last-child {
                border-bottom: none;
            }
            
            /* Ajustes para checkboxes en móvil */
            .registro-checkbox {
                width: 20px;
                height: 20px;
                margin-right: 8px;
            }
            
            .select-all-desktop {
                display: none;
            }
            
            .select-all-mobile {
                display: block;
                margin-bottom: 1rem;
            }
            
            .checkbox-label {
                display: flex;
                align-items: center;
            }
        }
        
        @media (min-width: 769px) {
            .select-all-mobile {
                display: none;
            }
            
            .select-all-desktop {
                display: block;
            }
        }
        
        .selected-count {
            background-color: #3B82F6;
            color: white;
            border-radius: 9999px;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Mis Reportes de Entregas</h1>
            <div class="flex items-center space-x-4">
                <span class="text-gray-600"><h4>Usuario:</h4><?= htmlspecialchars($_SESSION['usuario_nombre']) ?></span>
                
                <a href="../index.php" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md flex items-center" onclick="limpiarTokenAlSalir()">
                    <i class="fas fa-sign-out-alt mr-2"></i> salir
                </a>
                <a href="../cliente.php" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md flex items-center">
                    <i class="fas fa-sign-out-alt mr-2"></i> Volver 
                </a>
            </div>
        </div>

        <!-- Panel de Filtros -->
        <div class="bg-white rounded-xl shadow-md p-4 md:p-6 mb-8">
            <h2 class="text-lg md:text-xl font-semibold mb-4 text-gray-700">Filtros Avanzados</h2>
            <form id="filtrosForm" method="get" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
                <input type="hidden" name="pagina" value="1">
                
               <!-- Filtro por RFPP -->
<div>
    <label class="block text-sm font-medium text-gray-700 mb-1">¿Tiene tipo de RFPP?</label>
    <div class="mt-1 flex space-x-4">
        <label class="inline-flex items-center">
            <input type="radio" name="tiene_rfpp" value="si" class="h-4 w-4 text-blue-600 focus:ring-blue-500"
                <?php if(isset($_GET['tiene_rfpp']) && $_GET['tiene_rfpp'] === 'si') echo 'checked'; ?>
                onchange="document.getElementById('tipo_rfpp_container').style.display = 'block'">
            <span class="ml-2 text-gray-700">Sí</span>
        </label>
        <label class="inline-flex items-center">
            <input type="radio" name="tiene_rfpp" value="no" class="h-4 w-4 text-blue-600 focus:ring-blue-500"
                <?php if(!isset($_GET['tiene_rfpp']) || $_GET['tiene_rfpp'] === 'no') echo 'checked'; ?>
                onchange="document.getElementById('tipo_rfpp_container').style.display = 'none'">
            <span class="ml-2 text-gray-700">No</span>
        </label>
    </div>
    
    <div id="tipo_rfpp_container" style="display: <?php echo (isset($_GET['tiene_rfpp']) && $_GET['tiene_rfpp'] === 'si') ? 'block' : 'none'; ?>; margin-top: 0.5rem;">
        <label for="tipo_rfpp" class="block text-sm font-medium text-gray-700 mb-1">Tipo de RFPP</label>
        <select name="tipo_rfpp" id="tipo_rfpp" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
            <option value="">Todos los tipos</option>
            <?php foreach($tipos_rfpp as $tipo): ?>
                <option value="<?php echo htmlspecialchars($tipo); ?>"
                    <?php if(isset($_GET['tipo_rfpp']) && $_GET['tipo_rfpp'] == $tipo) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($tipo); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<!-- Filtro por Nombre de Salida -->
<div>
    <label for="nombre_salida" class="block text-sm font-medium text-gray-700 mb-1">Nombre de Salida</label>
    <select name="nombre_salida" id="nombre_salida" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
        <option value="">Todos los nombres</option>
        <?php foreach($nombres_salida as $salida): ?>
            <option value="<?php echo htmlspecialchars($salida); ?>" 
                <?php if(isset($_GET['nombre_salida']) && $_GET['nombre_salida'] == $salida) echo 'selected'; ?>>
                <?php echo htmlspecialchars($salida); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Filtro por Comunidad -->
<div>
    <label for="comunidad" class="block text-sm font-medium text-gray-700 mb-1">Comunidad</label>
    <select name="comunidad" id="comunidad" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
        <option value="">Todas las comunidades</option>
        <?php foreach($comunidades as $comunidad): ?>
            <option value="<?php echo htmlspecialchars($comunidad); ?>" 
                <?php if(isset($_GET['comunidad']) && $_GET['comunidad'] == $comunidad) echo 'selected'; ?>>
                <?php echo htmlspecialchars($comunidad); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

                <!-- Botones -->
                <div class="flex items-end space-x-3">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md flex items-center">
                        <i class="fas fa-search mr-2"></i> Buscar
                    </button>
                    <button type="button" onclick="limpiarFiltros()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-md flex items-center">
                        <i class="fas fa-broom mr-2"></i> Limpiar
                    </button>
                </div>
            </form>
        </div>

        <!-- Resultados -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <!-- Checkbox para seleccionar todo (versión móvil) -->
            <div class="select-all-mobile px-4 pt-4">
                <label class="inline-flex items-center">
                    <input type="checkbox" id="selectAllMobile" class="registro-checkbox h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <span class="ml-2 text-gray-700">Seleccionar todos</span>
                    <span id="selectedCountMobile" class="selected-count hidden">0</span>
                </label>
            </div>
            
            <div class="px-4 md:px-6 py-4 border-b border-gray-200 flex flex-col md:flex-row justify-between items-start md:items-center bg-gray-50 gap-4">
                <h2 class="text-base md:text-lg font-semibold text-gray-800">
                    Resultados: <span class="text-blue-600"><?= $total_registros ?></span> registros
                </h2>
                <div class="flex space-x-3">
                    <?php if(!empty($registros)): ?>
                        <button onclick="iniciarDescargaPDFs()" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md flex items-center">
                            <i class="fas fa-file-pdf mr-2"></i> Generar PDF
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tabla -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 responsive-table">
                    <thead class="bg-gray-100">
                        <tr>
                            <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider select-all-desktop">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="selectAllDesktop" class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                    <span id="selectedCountDesktop" class="selected-count hidden">0</span>
                                </label>
                            </th>
                            <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha</th>
                            <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Documento</th>
                            <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jefe de Hogar</th>
                            <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Comunidad</th>
                            <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre Salida</th>
                            <?php if($mostrar_tipo_rfpp): ?>
                                <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo RFPP</th>
                            <?php endif; ?>
                            <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if(empty($registros)): ?>
                            <tr>
                                <td colspan="<?= $mostrar_tipo_rfpp ? '8' : '7' ?>" class="px-4 md:px-6 py-4 text-center text-gray-500">No se encontraron registros</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($registros as $reg): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap" data-label="Seleccionar">
                                        <label class="checkbox-label">
                                            <input type="checkbox" name="seleccion[]" value="<?= $reg['id_registro'] ?>" class="registro-checkbox h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                        </label>
                                    </td>
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap text-sm text-gray-900" data-label="Fecha">
                                        <?= date('d/m/Y', strtotime($reg['fecha'])) ?>
                                    </td>
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap text-sm text-gray-900" data-label="Documento">
                                        <?= htmlspecialchars($reg['documento_jefe_hogar']) ?>
                                    </td>
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap text-sm text-gray-900" data-label="Jefe de Hogar">
                                        <?= htmlspecialchars($reg['nombre_jefe_hogar']) ?>
                                    </td>
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap text-sm text-gray-900" data-label="Comunidad">
                                        <?= !empty($reg['nombre_comunidad']) ? htmlspecialchars($reg['nombre_comunidad']) : '<span class="text-gray-400">Sin comunidad</span>' ?>
                                    </td>
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap text-sm text-gray-900" data-label="Nombre Salida">
                                        <?= !empty($reg['nombre_salida']) ? htmlspecialchars($reg['nombre_salida']) : '<span class="text-gray-400">Sin nombre</span>' ?>
                                    </td>
                                    
                                    <?php if($mostrar_tipo_rfpp): ?>
                                        <td class="px-4 md:px-6 py-4 whitespace-nowrap text-sm text-gray-900" data-label="Tipo RFPP">
                                            <?= !empty($reg['tipo_de_rfpp']) ? htmlspecialchars($reg['tipo_de_rfpp']) : '<span class="text-gray-400">Sin tipo</span>' ?>
                                        </td>
                                    <?php endif; ?>
                                    
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap text-sm text-gray-900" data-label="Acciones">
                                        <div class="flex flex-wrap gap-2">
                                            <button onclick="generarPDFIndividual('<?= $reg['id_registro'] ?>')" class="text-red-600 hover:text-red-800">
                                                <i class="fas fa-file-pdf"></i> PDF
                                            </button>
                                            <a href="../registroSL/detalle_registro.php?id=<?= $reg['id_registro'] ?>" class="text-blue-600 hover:text-blue-800">
                                                <i class="fas fa-eye"></i> Ver
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <?php if($total_paginas > 1): ?>
                <div class="px-4 md:px-6 py-4 bg-gray-50 border-t border-gray-200">
                    <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                        <div class="text-sm text-gray-700">
                            Página <?= $pagina_actual ?> de <?= $total_paginas ?>
                        </div>
                        <div class="flex gap-1">
                            <?php 
                            // Construir query string manteniendo los filtros
                            $queryParams = $_GET;
                            unset($queryParams['pagina']);
                            
                            if($pagina_actual > 1): 
                                $queryParams['pagina'] = 1;
                            ?>
                                <a href="?<?= http_build_query($queryParams) ?>" class="px-3 py-1 border border-gray-300 rounded-md hover:bg-gray-100">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <?php 
                                $queryParams['pagina'] = $pagina_actual - 1;
                                ?>
                                <a href="?<?= http_build_query($queryParams) ?>" class="px-3 py-1 border border-gray-300 rounded-md hover:bg-gray-100">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php 
                            $inicio = max(1, $pagina_actual - 2);
                            $fin = min($total_paginas, $pagina_actual + 2);
                            
                            if($inicio > 1) echo '<span class="px-3 py-1">...</span>';
                            
                            for($i = $inicio; $i <= $fin; $i++): 
                                $queryParams['pagina'] = $i;
                            ?>
                                <a href="?<?= http_build_query($queryParams) ?>" class="px-3 py-1 border <?= $i == $pagina_actual ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-100' ?> rounded-md">
                                    <?= $i ?>
                                </a>
                            <?php endfor; 
                            
                            if($fin < $total_paginas) echo '<span class="px-3 py-1">...</span>';
                            
                            if($pagina_actual < $total_paginas): 
                                $queryParams['pagina'] = $pagina_actual + 1;
                            ?>
                                <a href="?<?= http_build_query($queryParams) ?>" class="px-3 py-1 border border-gray-300 rounded-md hover:bg-gray-100">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <?php 
                                $queryParams['pagina'] = $total_paginas;
                                ?>
                                <a href="?<?= http_build_query($queryParams) ?>" class="px-3 py-1 border border-gray-300 rounded-md hover:bg-gray-100">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal para Imagen -->
    <div id="imagenModal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-lg p-4 max-w-4xl max-h-screen mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Vista previa de imagen</h3>
                <button onclick="document.getElementById('imagenModal').classList.add('hidden')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <img id="modalImagen" src="" alt="Imagen del registro" class="max-w-full max-h-[80vh] mx-auto">
        </div>
    </div>

    <!-- Notificación para PDF -->
    <div id="pdfNotification" class="notification">
        <div class="flex items-center">
            <i class="fas fa-file-pdf mr-2"></i>
            <span>Tu PDF se descargará en unos minutos. Por favor tenga paciencia.</span>
        </div>
    </div>
<footer class="w-full bg-gray-800 text-white text-center py-4">
        <p class="text-sm">
            © 2025 Todos los derechos reservados. Ingeniero de Sistema: 
            <a href="https://2001hector.github.io/PerfilHectorP.github.io/" class="text-blue-400 hover:underline">
                Hector Jose Chamorro Nuñez
            </a>
        </p>
    </footer>
    <script>
    // Variables globales
    const allIds = <?= $all_ids_json ?>;
    let selectedIds = new Set();
    
    // Función para limpiar token al cerrar sesión
    function limpiarTokenAlSalir() {
        fetch('../index.php?clean_token=1', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'user_id=<?php echo $_SESSION['usuario_id']; ?>'
        }).then(() => {
            window.location.href = '../index.php';
        });
    }

    // Limpiar filtros
    function limpiarFiltros() {
        window.location.href = 'reportesRegistros.php';
    }

    // Función para mostrar notificación
    function showNotification() {
        const notification = document.getElementById('pdfNotification');
        notification.style.display = 'block';
        
        // Ocultar después de 8 segundos
        setTimeout(() => {
            notification.style.display = 'none';
        }, 8000);
    }

    // Actualizar contador de seleccionados
    function updateSelectedCount() {
        const count = selectedIds.size;
        const countElements = document.querySelectorAll('#selectedCountDesktop, #selectedCountMobile');
        
        countElements.forEach(el => {
            el.textContent = count;
            if (count > 0) {
                el.classList.remove('hidden');
            } else {
                el.classList.add('hidden');
            }
        });
        
        // Actualizar estado de "Seleccionar todos"
        const selectAllDesktop = document.getElementById('selectAllDesktop');
        const selectAllMobile = document.getElementById('selectAllMobile');
        
        if (count === allIds.length) {
            selectAllDesktop.checked = true;
            selectAllMobile.checked = true;
        } else {
            selectAllDesktop.checked = false;
            selectAllMobile.checked = false;
        }
    }

    // Sincronizar los checkboxes de seleccionar todos
    function syncSelectAllCheckboxes() {
        const selectAllDesktop = document.getElementById('selectAllDesktop');
        const selectAllMobile = document.getElementById('selectAllMobile');
        const checkboxes = document.querySelectorAll('.registro-checkbox');
        
        // Función para manejar la selección/deselección de todos
        function handleSelectAllChange(isChecked) {
            if (isChecked) {
                allIds.forEach(id => selectedIds.add(id.toString()));
            } else {
                selectedIds.clear();
            }
            
            // Actualizar checkboxes visibles
            checkboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            
            updateSelectedCount();
        }
        
        // Sincronizar desktop y mobile
        selectAllDesktop.addEventListener('change', function(e) {
            handleSelectAllChange(e.target.checked);
        });
        
        selectAllMobile.addEventListener('change', function(e) {
            handleSelectAllChange(e.target.checked);
        });
        
        // Sincronizar checkboxes individuales
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const id = this.value;
                
                if (this.checked) {
                    selectedIds.add(id);
                } else {
                    selectedIds.delete(id);
                }
                
                updateSelectedCount();
            });
            
            // Marcar checkbox si ya está seleccionado
            if (selectedIds.has(checkbox.value)) {
                checkbox.checked = true;
            }
        });
    }

    // Generar PDF individual
    function generarPDFIndividual(id) {
        showNotification();
        window.open(`pdf.php?generar_pdf=1&id_registro=${id}`, '_blank');
    }

    // Función para descargar múltiples PDFs
    function descargarPDFsEnZIP(ids) {
        showNotification();
        
        // Crear iframe para la descarga
        const iframe = document.createElement('iframe');
        iframe.name = 'downloadFrame';
        iframe.style.display = 'none';
        document.body.appendChild(iframe);

        // Crear formulario para enviar los IDs
        const form = document.createElement('form');
        form.method = 'GET';
        form.action = 'pdf.php';
        form.target = 'downloadFrame';
        
        const input1 = document.createElement('input');
        input1.type = 'hidden';
        input1.name = 'generar_pdf';
        input1.value = '1';
        form.appendChild(input1);
        
        const input2 = document.createElement('input');
        input2.type = 'hidden';
        input2.name = 'seleccionados';
        input2.value = JSON.stringify(Array.from(ids));
        form.appendChild(input2);
        
        document.body.appendChild(form);
        form.submit();
        
        // Eliminar el iframe después de un tiempo
        setTimeout(() => {
            document.body.removeChild(iframe);
            document.body.removeChild(form);
        }, 10000);
    }

    // Iniciar proceso de descarga
    function iniciarDescargaPDFs() {
        if (selectedIds.size === 0) {
            alert('Por favor selecciona al menos un registro');
            return;
        }
        
        if (selectedIds.size === 1) {
            generarPDFIndividual(Array.from(selectedIds)[0]);
        } else {
            if (confirm(`Vas a generar ${selectedIds.size} archivos PDF. ¿Deseas continuar?`)) {
                descargarPDFsEnZIP(selectedIds);
            }
        }
    }

    // Inicializar cuando el DOM esté listo
    document.addEventListener('DOMContentLoaded', function() {
        // Inicializar selectedIds con los checkboxes ya marcados
        document.querySelectorAll('.registro-checkbox:checked').forEach(checkbox => {
            selectedIds.add(checkbox.value);
        });
        
        updateSelectedCount();
        syncSelectAllCheckboxes();
    });
    </script>
</body>
</html>