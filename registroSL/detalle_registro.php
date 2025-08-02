<?php
require '../db.php';
session_start();

// Verificar autenticación
if (!isset($_SESSION['logueado']) || $_SESSION['logueado'] !== true) {
    header("Location: ../index.php");
    exit();
}

if (!isset($_GET['id'])) {
    echo "ID no especificado";
    exit;
}

$id = intval($_GET['id']);

// Obtener datos del registro
$sql = "SELECT r.*, i.imagen, i.id_imagen 
        FROM registro_salidas r
        LEFT JOIN imagen_registro i ON r.id_registro = i.id_registro
        WHERE r.id_registro = ?";
$stmt = mysqli_prepare($conexion, $sql);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$registro = mysqli_fetch_assoc($result);

if (!$registro) {
    echo "Registro no encontrado";
    exit;
}

// Procesar actualización si se envió el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Actualizar datos básicos
    $documento = mysqli_real_escape_string($conexion, $_POST['documento_jefe_hogar']);
    $nombre = mysqli_real_escape_string($conexion, $_POST['nombre_jefe_hogar']);
    $comunidad = mysqli_real_escape_string($conexion, $_POST['nombre_comunidad']);
    $entrega = mysqli_real_escape_string($conexion, $_POST['nombre_entrega']);
    $salida = mysqli_real_escape_string($conexion, $_POST['nombre_salida']);
    $fecha = mysqli_real_escape_string($conexion, $_POST['fecha']);

    $update_sql = "UPDATE registro_salidas SET 
                  documento_jefe_hogar = ?,
                  nombre_jefe_hogar = ?,
                  nombre_comunidad = ?,
                  nombre_entrega = ?,
                  nombre_salida = ?,
                  fecha = ?
                  WHERE id_registro = ?";
    
    $stmt = mysqli_prepare($conexion, $update_sql);
    mysqli_stmt_bind_param($stmt, "ssssssi", $documento, $nombre, $comunidad, $entrega, $salida, $fecha, $id);
    $result = mysqli_stmt_execute($stmt);

    if ($result) {
        $success_message = "Registro actualizado correctamente";
        // Actualizar los datos mostrados
        $registro['documento_jefe_hogar'] = $documento;
        $registro['nombre_jefe_hogar'] = $nombre;
        $registro['nombre_comunidad'] = $comunidad;
        $registro['nombre_entrega'] = $entrega;
        $registro['nombre_salida'] = $salida;
        $registro['fecha'] = $fecha;
    } else {
        $error_message = "Error al actualizar el registro: " . mysqli_error($conexion);
    }

    // Procesar actualización de imagen si se subió una nueva
    if (!empty($_FILES['nueva_imagen']['name'])) {
        $target_dir = "C:/xampp/htdocs/roles/uploads/";
        
        // Eliminar imagen anterior si existe
        if (!empty($registro['imagen'])) {
            $old_image_path = $target_dir . ltrim($registro['imagen'], '/');
            if (file_exists($old_image_path)) {
                unlink($old_image_path);
            }
        }

        // Generar nombre único para la nueva imagen
        $file_extension = pathinfo($_FILES['nueva_imagen']['name'], PATHINFO_EXTENSION);
        $new_filename = 'registro_' . $id . '_' . time() . '.' . $file_extension;
        $target_file = $target_dir . $new_filename;

        // Validar y mover el archivo
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array(strtolower($file_extension), $allowed_types)) {
            if (move_uploaded_file($_FILES['nueva_imagen']['tmp_name'], $target_file)) {
                // Actualizar la base de datos
                if (empty($registro['id_imagen'])) {
                    // Insertar nueva imagen
                    $insert_sql = "INSERT INTO imagen_registro (id_registro, imagen) VALUES (?, ?)";
                    $stmt = mysqli_prepare($conexion, $insert_sql);
                    mysqli_stmt_bind_param($stmt, "is", $id, $new_filename);
                } else {
                    // Actualizar imagen existente
                    $update_sql = "UPDATE imagen_registro SET imagen = ? WHERE id_imagen = ?";
                    $stmt = mysqli_prepare($conexion, $update_sql);
                    mysqli_stmt_bind_param($stmt, "si", $new_filename, $registro['id_imagen']);
                }
                
                if (mysqli_stmt_execute($stmt)) {
                    $success_message = isset($success_message) ? $success_message . " e imagen actualizada" : "Imagen actualizada correctamente";
                    $registro['imagen'] = $new_filename;
                } else {
                    $error_message = isset($error_message) ? $error_message . " Error al actualizar imagen: " . mysqli_error($conexion) : "Error al actualizar imagen: " . mysqli_error($conexion);
                }
            } else {
                $error_message = isset($error_message) ? $error_message . " Error al subir la imagen." : "Error al subir la imagen.";
            }
        } else {
            $error_message = isset($error_message) ? $error_message . " Formato de imagen no permitido." : "Formato de imagen no permitido.";
        }
    }
}

// Verificar si existe la imagen
$imagen_path = '';
if (!empty($registro['imagen'])) {
    $base_path = '../uploads/';
    $full_path = $base_path . ltrim($registro['imagen'], '/');
    
    if (file_exists($full_path)) {
        // Construir la ruta relativa para el navegador
        $imagen_path = '../uploads/' . ltrim($registro['imagen'], '/');
    } else {
        $error_message = isset($error_message) ? $error_message . " La imagen no se encuentra en el servidor." : "La imagen no se encuentra en el servidor.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle del Registro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800">Detalle del Registro #<?= htmlspecialchars($registro['id_registro']) ?></h1>
            <a href="../reportesPDF/reportesRegistros.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md flex items-center">
                <i class="fas fa-arrow-left mr-2"></i> Volver
            </a>
        </div>

        <!-- Notificaciones -->
        <?php if(isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6" role="alert">
                <span class="block sm:inline"><?= $success_message ?></span>
            </div>
        <?php endif; ?>
        
        <?php if(isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
                <span class="block sm:inline"><?= $error_message ?></span>
            </div>
        <?php endif; ?>

        <!-- Formulario de edición -->
        <form method="POST" enctype="multipart/form-data" class="space-y-8">
            <!-- Sección de imagen -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-lg font-semibold text-gray-800"><?= !empty($imagen_path) ? 'Imagen del Registro' : 'Agregar Imagen' ?></h2>
                </div>
                <div class="p-6">
                    <?php if(!empty($imagen_path)): ?>
                        <div class="flex flex-col items-center mb-6">
                            <img src="<?= htmlspecialchars($imagen_path) ?>" 
                                 alt="Imagen del registro" 
                                 class="max-w-full max-h-[50vh] object-contain rounded-lg shadow-lg mb-4">
                        </div>
                    <?php endif; ?>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2"><?= !empty($imagen_path) ? 'Cambiar imagen' : 'Seleccionar imagen' ?></label>
                        <input type="file" name="nueva_imagen" accept="image/*"
                               class="block w-full text-sm text-gray-500
                               file:mr-4 file:py-2 file:px-4
                               file:rounded-md file:border-0
                               file:text-sm file:font-semibold
                               file:bg-blue-50 file:text-blue-700
                               hover:file:bg-blue-100">
                    </div>
                </div>
            </div>

            <!-- Sección de información del registro -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-lg font-semibold text-gray-800">Información del Registro</h2>
                </div>
                
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Columna 1 -->
                        <div>
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Fecha</label>
                                <input type="date" name="fecha" value="<?= htmlspecialchars($registro['fecha']) ?>" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Documento Jefe de Hogar</label>
                                <input type="text" name="documento_jefe_hogar" value="<?= htmlspecialchars($registro['documento_jefe_hogar']) ?>" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre Jefe de Hogar</label>
                                <input type="text" name="nombre_jefe_hogar" value="<?= htmlspecialchars($registro['nombre_jefe_hogar']) ?>" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                        
                        <!-- Columna 2 -->
                        <div>
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Comunidad</label>
                                <input type="text" name="nombre_comunidad" value="<?= htmlspecialchars($registro['nombre_comunidad']) ?>" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de quien entrega</label>
                                <input type="text" name="nombre_entrega" value="<?= htmlspecialchars($registro['nombre_entrega']) ?>" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de la Salida</label>
                                <input type="text" name="nombre_salida" value="<?= htmlspecialchars($registro['nombre_salida']) ?>" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">ID Usuario</label>
                                <input type="text" value="<?= htmlspecialchars($registro['id_usuario']) ?>" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" disabled>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sección de información adicional -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-lg font-semibold text-gray-800">Información Adicional</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-500">ID del Registro</p>
                            <p class="font-medium"><?= htmlspecialchars($registro['id_registro']) ?></p>
                        </div>
                        <?php if(!empty($registro['id_imagen'])): ?>
                        <div>
                            <p class="text-sm text-gray-500">ID de la Imagen</p>
                            <p class="font-medium"><?= htmlspecialchars($registro['id_imagen']) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Botones de acción -->
            <div class="flex justify-end space-x-3">
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md flex items-center">
                    <i class="fas fa-save mr-2"></i> Guardar Cambios
                </button>
                
                <a href="../reportesPDF/reportesRegistros.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-md flex items-center">
                    <i class="fas fa-times mr-2"></i> Cancelar
                </a>
            </div>
        </form>
    </div>
</body>
</html>