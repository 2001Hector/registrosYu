<?php
session_start();
require '../db.php';

// Verificar si el usuario está logueado y ha pagado
if (!isset($_SESSION['logueado']) || !$_SESSION['logueado'] || !$_SESSION['pagado']) {
    header("Location: ../index.php");
    exit();
}

// Verificar token de sesión (solo para usuarios no admin)
if (!isset($_SESSION['es_admin']) || !$_SESSION['es_admin']) {
    $sql = "SELECT session_token FROM usuarios WHERE id = ?";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['usuario_id']);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    
    if ($resultado && mysqli_num_rows($resultado) > 0) {
        $datos = mysqli_fetch_assoc($resultado);
        if ($datos['session_token'] !== $_SESSION['session_token']) {
            // Token no coincide - limpiar y redirigir
            $clean_stmt = mysqli_prepare($conexion, "UPDATE usuarios SET session_token = NULL WHERE id = ?");
            mysqli_stmt_bind_param($clean_stmt, "i", $_SESSION['usuario_id']);
            mysqli_stmt_execute($clean_stmt);
            session_destroy();
            header("Location: ../index.php");
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
        header("Location: index.php?timeout=1");
        exit();
    }
}
$_SESSION['last_activity'] = time();

// Actualizar último acceso
$now = date('Y-m-d H:i:s');
$update_sql = "UPDATE usuarios SET ultimo_acceso = ? WHERE id = ?";
$update_stmt = mysqli_prepare($conexion, $update_sql);
mysqli_stmt_bind_param($update_stmt, "si", $now, $_SESSION['usuario_id']);
mysqli_stmt_execute($update_stmt);

// Variable para controlar si se mostró el mensaje de éxito
$mostrar_exito = false;

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar y sanitizar los datos del formulario
    $id_usuario = $_SESSION['usuario_id'];
    $nombre_comunidad = mysqli_real_escape_string($conexion, $_POST['nombre_comunidad']);
    $nombre_entrega = mysqli_real_escape_string($conexion, $_POST['nombre_entrega']);
    $nombre_jefe_hogar = mysqli_real_escape_string($conexion, $_POST['nombre_jefe_hogar']);
    $documento_jefe_hogar = mysqli_real_escape_string($conexion, $_POST['documento_jefe_hogar']);
    $nombre_salida = mysqli_real_escape_string($conexion, $_POST['nombre_salida']);
    $fecha = date('Y-m-d'); // Fecha actual
    
    // Procesar el tipo de RFPP
    $tipo_rfpp = null;
    if (isset($_POST['tiene_rfpp']) && $_POST['tiene_rfpp'] === 'si') {
        $tipo_rfpp = mysqli_real_escape_string($conexion, $_POST['tipo_rfpp']);
    }

    // Insertar el registro principal
    $query = "INSERT INTO registro_salidas (id_usuario, nombre_comunidad, nombre_entrega, nombre_jefe_hogar, documento_jefe_hogar, nombre_salida, fecha, tipo_de_rfpp) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conexion, $query);
    mysqli_stmt_bind_param($stmt, 'isssssss', $id_usuario, $nombre_comunidad, $nombre_entrega, $nombre_jefe_hogar, $documento_jefe_hogar, $nombre_salida, $fecha, $tipo_rfpp);
    mysqli_stmt_execute($stmt);
    $id_registro = mysqli_insert_id($conexion);
    mysqli_stmt_close($stmt);

    // Procesar la imagen si se subió (solo una imagen)
    if (!empty($_FILES['imagen']['name'])) {
        $directorio = '../uploads/';
        $nombre_archivo = $_FILES['imagen']['name'];
        $extension = strtolower(pathinfo($nombre_archivo, PATHINFO_EXTENSION));

        // Formatos bloqueados
        $formatosBloqueados = ['webp', 'svg', 'tiff', 'tif', 'bmp'];
        $nombresProhibidos = []; // Puedes añadir nombres de archivo prohibidos si es necesario

        // Validar formato del archivo
        if (in_array($extension, $formatosBloqueados)) {
            echo '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    Swal.fire({
                        title: "Error",
                        text: "Formato de imagen no permitido. Use solo JPG, PNG o JPEG.",
                        icon: "error",
                        confirmButtonText: "Entendido"
                    });
                });
            </script>';
        } else {
            // Verificar dimensiones de la imagen
            $imagen_info = getimagesize($_FILES['imagen']['tmp_name']);
            $ancho = $imagen_info[0];
            $alto = $imagen_info[1];
            
            if ($ancho >= $alto) {
                echo '<script>
                    document.addEventListener("DOMContentLoaded", function() {
                        Swal.fire({
                            title: "Error",
                            text: "La imagen debe ser vertical (la altura mayor que el ancho).",
                            icon: "error",
                            confirmButtonText: "Entendido"
                        });
                    });
                </script>';
            } else {
                // Verificar si el directorio existe, si no, crearlo
                if (!file_exists($directorio)) {
                    mkdir($directorio, 0777, true);
                }

                $nombre_unico = uniqid() . '_' . basename($nombre_archivo);
                $ruta_final = $directorio . $nombre_unico;
                $ruta_temporal = $_FILES['imagen']['tmp_name'];

                if (move_uploaded_file($ruta_temporal, $ruta_final)) {
                    // Insertar la información de la imagen en la base de datos
                    $query_img = "INSERT INTO imagen_registro (imagen, fecha_imagen, id_registro) VALUES (?, ?, ?)";
                    $stmt_img = mysqli_prepare($conexion, $query_img);
                    $fecha_imagen = $fecha;
                    $ruta_db = 'uploads/' . $nombre_unico; // Cambiado para almacenar ruta relativa
                    mysqli_stmt_bind_param($stmt_img, 'ssi', $ruta_db, $fecha_imagen, $id_registro);
                    mysqli_stmt_execute($stmt_img);
                    mysqli_stmt_close($stmt_img);
                } else {
                    echo '<script>
                        document.addEventListener("DOMContentLoaded", function() {
                            Swal.fire({
                                title: "Error",
                                text: "Hubo un problema al subir la imagen.",
                                icon: "error",
                                confirmButtonText: "Entendido"
                            });
                        });
                    </script>';
                }
            }
        }
    }

    // Marcar para mostrar el mensaje de éxito
    $mostrar_exito = true;
    
    // Limpiar el formulario (opcional)
    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            document.getElementById("registroForm").reset();
            selectedFile = null;
            renderPreview();
            // Resetear el campo de RFPP
            document.getElementById("tipo_rfpp_container").style.display = "none";
        });
    </script>';
}

// Obtener datos de la tabla datos_familiares para el filtro
$query_familias = "SELECT cedula_padre, nombre_padre FROM datos_familiares WHERE usuario_id = ?";
$stmt_familias = mysqli_prepare($conexion, $query_familias);
mysqli_stmt_bind_param($stmt_familias, "i", $_SESSION['usuario_id']);
mysqli_stmt_execute($stmt_familias);
$resultado_familias = mysqli_stmt_get_result($stmt_familias);
$familias = [];
while ($fila = mysqli_fetch_assoc($resultado_familias)) {
    $familias[] = $fila;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Salidas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/11.4.24/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Agregado el CSS para el modal de zoom -->
    <style>
        .modal-zoom {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.9);
            overflow: auto;
        }
        
        .modal-content-zoom {
            margin: auto;
            display: block;
            width: 80%;
            max-width: 1200px;
            max-height: 90vh;
            margin-top: 5vh;
        }
        
        .close-zoom {
            position: absolute;
            top: 15px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            transition: 0.3s;
            cursor: pointer;
        }
        
        .close-zoom:hover {
            color: #bbb;
        }
        
        /* Estilos para el autocompletado */
        .autocomplete {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        
        .autocomplete-items {
            position: absolute;
            border: 1px solid #d4d4d4;
            border-bottom: none;
            border-top: none;
            z-index: 99;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .autocomplete-items div {
            padding: 10px;
            cursor: pointer;
            background-color: #fff;
            border-bottom: 1px solid #d4d4d4;
        }
        
        .autocomplete-items div:hover {
            background-color: #e9e9e9;
        }
        
        .autocomplete-active {
            background-color: DodgerBlue !important;
            color: #ffffff;
        }
    </style>
    <script>
    // Función para manejar el cierre de sesión
    function cerrarSesion() {
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

    // Función para limpiar token al cerrar pestaña/ventana
    function limpiarTokenAlSalir() {
        navigator.sendBeacon('../index.php?clean_token=1', 'user_id=<?php echo $_SESSION['usuario_id']; ?>');
    }

    // Manejar cierre de pestaña/ventana
    window.addEventListener('beforeunload', function(e) {
        const esNavegacionInterna = e.target.activeElement?.tagName === 'A' && 
                                  e.target.activeElement?.href?.startsWith(window.location.origin);
        
        if (!esNavegacionInterna) {
            limpiarTokenAlSalir();
        }
    });
    </script>
</head>
<body class="bg-gray-100">
    <!-- Navbar superior con información del usuario -->
    <nav class="bg-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-4">
                    <span class="font-semibold text-gray-700">ID: <?php echo $_SESSION['usuario_id']; ?></span>
                    <span class="font-semibold text-gray-700">Usuario: <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?></span>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="../cliente.php" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-md text-sm font-medium">
                        Volver
                    </a>
                    <a href="#" onclick="cerrarSesion(); return false;" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-md text-sm font-medium">
                        Cerrar Sesión
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Contenido principal -->
    <div class="min-h-screen pt-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white p-8 rounded-lg shadow-md">
                <h1 class="text-3xl font-bold mb-6 text-center">Registro de Salidas</h1>
                
                <?php if ($mostrar_exito): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                title: '¡Éxito!',
                                text: 'El registro se ha guardado correctamente.',
                                icon: 'success',
                                confirmButtonText: 'Aceptar'
                            });
                        });
                    </script>
                <?php endif; ?>

                <form id="registroForm" action="registro.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <!-- Sección de imagen (movida al principio como solicitaste) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Imagen del registro</label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                            <div class="space-y-1 text-center w-full">
                                <div class="flex text-sm text-gray-600 justify-center">
                                    <label for="imagen" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none">
                                        <span>Subir imagen</span>
                                        <input id="imagen" name="imagen" type="file" accept="image/jpeg, image/png" class="sr-only">
                                    </label>
                                    <p class="pl-1">o arrastrar y soltar</p>
                                </div>
                                <p class="text-xs text-gray-500">Formatos admitidos: PNG, JPG, JPEG</p>
                                <p class="text-xs text-gray-500">La imagen debe ser vertical (altura mayor que ancho)</p>
                            </div>
                        </div>
                        
                        <!-- Previsualización de imagen -->
                        <div id="preview-container" class="mt-4">
                            <p class="text-sm text-gray-500 text-center">No hay imagen seleccionada</p>
                        </div>
                    </div>

                    <!-- Sección de datos básicos -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="nombre_comunidad" class="block text-sm font-medium text-gray-700">Nombre de la Comunidad</label>
                            <input type="text" id="nombre_comunidad" name="nombre_comunidad" required
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label for="nombre_entrega" class="block text-sm font-medium text-gray-700">Nombre de quien entrega</label>
                            <input type="text" id="nombre_entrega" name="nombre_entrega" required
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="autocomplete">
                            <label for="documento_jefe_hogar" class="block text-sm font-medium text-gray-700">Documento del Jefe de Hogar</label>
                            <input type="text" id="documento_jefe_hogar" name="documento_jefe_hogar" required
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                autocomplete="off">
                        </div>
                        <div>
                            <label for="nombre_jefe_hogar" class="block text-sm font-medium text-gray-700">Nombre del Jefe de Hogar</label>
                            <input type="text" id="nombre_jefe_hogar" name="nombre_jefe_hogar" required
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label for="nombre_salida" class="block text-sm font-medium text-gray-700">Nombre de la Salida</label>
                            <input type="text" id="nombre_salida" name="nombre_salida"
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label for="fecha" class="block text-sm font-medium text-gray-700">Fecha</label>
                            <input type="date" id="fecha" name="fecha" value="<?php echo date('Y-m-d'); ?>"
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>

                    <!-- Sección de RFPP -->
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">¿Tiene tipo de RFPP?</label>
                            <div class="mt-1 flex space-x-4">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="tiene_rfpp" value="si" class="h-4 w-4 text-blue-600 focus:ring-blue-500" 
                                           onchange="document.getElementById('tipo_rfpp_container').style.display = 'block'">
                                    <span class="ml-2 text-gray-700">Sí</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="tiene_rfpp" value="no" class="h-4 w-4 text-blue-600 focus:ring-blue-500" 
                                           onchange="document.getElementById('tipo_rfpp_container').style.display = 'none'" checked>
                                    <span class="ml-2 text-gray-700">No</span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="tipo_rfpp_container" style="display: none;">
                            <label for="tipo_rfpp" class="block text-sm font-medium text-gray-700">Tipo de RFPP</label>
                            <select id="tipo_rfpp" name="tipo_rfpp" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="Tipo A">A</option>
                                <option value="Tipo C">C</option>
                            </select>
                        </div>
                    </div>

                    <!-- Botón de envío -->
                    <div class="flex justify-center">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                            Guardar Registro
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para zoom de imagen -->
    <div id="zoomModal" class="modal-zoom">
        <span class="close-zoom">&times;</span>
        <img class="modal-content-zoom" id="zoomedImage">
    </div>
<footer class="w-full bg-gray-800 text-white text-center py-4">
        <p class="text-sm">
            © 2025 Todos los derechos reservados. Ingeniero de Sistema: 
            <a href="https://2001hector.github.io/PerfilHectorP.github.io/" class="text-blue-400 hover:underline">
                Hector Jose Chamorro Nuñez
            </a>
        </p>
    </footer>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/11.4.24/sweetalert2.min.js"></script>
    <script>
        // Variable para mantener la imagen seleccionada
        let selectedFile = null;
        const fileInput = document.getElementById('imagen');
        const previewContainer = document.getElementById('preview-container');
        
        // Datos de familias para autocompletado
        const familias = <?php echo json_encode($familias); ?>;
        
        // Mostrar previsualización de la imagen
        fileInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            
            if (!file) {
                selectedFile = null;
                renderPreview();
                return;
            }
            
            // Validar formato y nombre del archivo
            const forbiddenExtensions = ['webp', 'svg', 'tiff', 'tif', 'bmp'];
            const fileExtension = file.name.split('.').pop().toLowerCase();
            const isForbiddenExtension = forbiddenExtensions.includes(fileExtension);
            
            if (isForbiddenExtension || !file.type.match('image.*')) {
                Swal.fire({
                    title: 'Error',
                    text: 'Formato no permitido. Use solo imágenes PNG, JPG o JPEG.',
                    icon: 'error',
                    confirmButtonText: 'Entendido'
                });
                fileInput.value = '';
                selectedFile = null;
                renderPreview();
                return;
            }
            
            // Validar dimensiones de la imagen
            const img = new Image();
            img.onload = function() {
                if (this.width >= this.height) {
                    Swal.fire({
                        title: 'Error',
                        text: 'La imagen debe ser vertical (la altura mayor que el ancho).',
                        icon: 'error',
                        confirmButtonText: 'Entendido'
                    });
                    fileInput.value = '';
                    selectedFile = null;
                    renderPreview();
                } else {
                    selectedFile = file;
                    renderPreview();
                }
            };
            img.src = URL.createObjectURL(file);
        });
        
        // Función para renderizar la previsualización
        function renderPreview() {
            previewContainer.innerHTML = '';
            
            if (!selectedFile) {
                previewContainer.innerHTML = '<p class="text-sm text-gray-500 text-center">No hay imagen seleccionada</p>';
                return;
            }
            
            const reader = new FileReader();
            
            reader.onload = function(e) {
                const previewDiv = document.createElement('div');
                previewDiv.className = 'flex flex-col items-center';
                
                const img = document.createElement('img');
                img.src = e.target.result;
                img.className = 'w-64 h-64 object-contain rounded-md cursor-zoom-in';
                img.alt = 'Previsualización de imagen';
                img.onclick = function() {
                    openZoomModal(e.target.result);
                };
                
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'mt-2 bg-red-500 text-white rounded-md px-3 py-1 text-sm hover:bg-red-600';
                removeBtn.innerHTML = 'Eliminar imagen';
                removeBtn.onclick = function() {
                    selectedFile = null;
                    fileInput.value = '';
                    renderPreview();
                };
                
                const fileName = document.createElement('p');
                fileName.className = 'text-xs text-gray-500 mt-1';
                fileName.textContent = selectedFile.name;
                
                previewDiv.appendChild(img);
                previewDiv.appendChild(removeBtn);
                previewDiv.appendChild(fileName);
                previewContainer.appendChild(previewDiv);
            };
            
            reader.readAsDataURL(selectedFile);
        }
        
        // Función para abrir el modal de zoom
        function openZoomModal(imgSrc) {
            const modal = document.getElementById('zoomModal');
            const modalImg = document.getElementById('zoomedImage');
            modal.style.display = "block";
            modalImg.src = imgSrc;
            
            // Ajustar para visualización vertical tanto en móvil como en desktop
            if (window.innerWidth > 768) { // Desktop
                modalImg.style.width = 'auto';
                modalImg.style.height = '90vh';
                modalImg.style.maxWidth = '100%';
            } else { // Móvil
                modalImg.style.width = '100%';
                modalImg.style.height = 'auto';
            }
        }
        
        // Cerrar el modal de zoom
        document.getElementsByClassName('close-zoom')[0].onclick = function() {
            document.getElementById('zoomModal').style.display = "none";
        }
        
        // Cerrar el modal si se hace clic fuera de la imagen
        window.onclick = function(event) {
            const modal = document.getElementById('zoomModal');
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
        
        // Autocompletado para documento del jefe de hogar
        function autocomplete(inp, arr) {
            let currentFocus;
            
            inp.addEventListener("input", function(e) {
                let val = this.value;
                closeAllLists();
                if (!val) { return false; }
                currentFocus = -1;
                
                const a = document.createElement("DIV");
                a.setAttribute("id", this.id + "autocomplete-list");
                a.setAttribute("class", "autocomplete-items");
                this.parentNode.appendChild(a);
                
                for (let i = 0; i < arr.length; i++) {
                    if (arr[i].cedula_padre.substr(0, val.length).toUpperCase() === val.toUpperCase()) {
                        const b = document.createElement("DIV");
                        b.innerHTML = "<strong>" + arr[i].cedula_padre.substr(0, val.length) + "</strong>";
                        b.innerHTML += arr[i].cedula_padre.substr(val.length);
                        b.innerHTML += "<input type='hidden' value='" + arr[i].cedula_padre + "'>";
                        b.innerHTML += "<small class='text-gray-500 ml-2'>" + arr[i].nombre_padre + "</small>";
                        
                        b.addEventListener("click", function() {
                            inp.value = this.getElementsByTagName("input")[0].value;
                            // Buscar y asignar el nombre correspondiente
                            const familia = arr.find(f => f.cedula_padre === inp.value);
                            if (familia) {
                                document.getElementById('nombre_jefe_hogar').value = familia.nombre_padre;
                            }
                            closeAllLists();
                        });
                        a.appendChild(b);
                    }
                }
            });
            
            inp.addEventListener("keydown", function(e) {
                let x = document.getElementById(this.id + "autocomplete-list");
                if (x) x = x.getElementsByTagName("div");
                if (e.keyCode == 40) { // Flecha abajo
                    currentFocus++;
                    addActive(x);
                } else if (e.keyCode == 38) { // Flecha arriba
                    currentFocus--;
                    addActive(x);
                } else if (e.keyCode == 13) { // Enter
                    e.preventDefault();
                    if (currentFocus > -1) {
                        if (x) x[currentFocus].click();
                    }
                }
            });
            
            function addActive(x) {
                if (!x) return false;
                removeActive(x);
                if (currentFocus >= x.length) currentFocus = 0;
                if (currentFocus < 0) currentFocus = (x.length - 1);
                x[currentFocus].classList.add("autocomplete-active");
            }
            
            function removeActive(x) {
                for (let i = 0; i < x.length; i++) {
                    x[i].classList.remove("autocomplete-active");
                }
            }
            
            function closeAllLists(elmnt) {
                const x = document.getElementsByClassName("autocomplete-items");
                for (let i = 0; i < x.length; i++) {
                    if (elmnt != x[i] && elmnt != inp) {
                        x[i].parentNode.removeChild(x[i]);
                    }
                }
            }
            
            document.addEventListener("click", function(e) {
                closeAllLists(e.target);
            });
        }
        
        // Inicializar autocompletado
        autocomplete(document.getElementById("documento_jefe_hogar"), familias);
        
        // Manejar el envío del formulario
        document.getElementById('registroForm').addEventListener('submit', function(e) {
            if (!selectedFile) {
                e.preventDefault();
                Swal.fire({
                    title: 'Advertencia',
                    text: 'Debe subir una imagen del registro.',
                    icon: 'warning',
                    confirmButtonText: 'Entendido'
                });
            }
        });
    </script>

</body>
</html> . 