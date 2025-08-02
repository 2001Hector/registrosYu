<?php
session_start();
require '../db.php';

// Verificar sesión y pago
if (!isset($_SESSION['logueado']) || !$_SESSION['logueado'] || !$_SESSION['pagado']) {
    header("Location: index.php");
    exit();
}

// Verificar token de sesión (para usuarios no admin)
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

// Actualizar último acceso
$now = date('Y-m-d H:i:s');
$update_sql = "UPDATE usuarios SET ultimo_acceso = ? WHERE id = ?";
$update_stmt = mysqli_prepare($conexion, $update_sql);
mysqli_stmt_bind_param($update_stmt, "si", $now, $_SESSION['usuario_id']);
mysqli_stmt_execute($update_stmt);

// Verificar conexión a la base de datos
$modo_offline = !mysqli_ping($conexion);

// Sincronizar registros pendientes si hay conexión
if (!$modo_offline) {
    $pendientes = mysqli_query($conexion, 
        "SELECT * FROM registro_salidas 
         WHERE id_usuario = {$_SESSION['usuario_id']} AND modo_offline = 1");
    
    while ($pendiente = mysqli_fetch_assoc($pendientes)) {
        $imagenes = glob("../uploads/offline_{$pendiente['id_registro']}_*");
        
        if (!empty($imagenes)) {
            $ruta_imagen = $imagenes[0];
            $nuevo_nombre = str_replace('offline_', '', basename($ruta_imagen));
            rename($ruta_imagen, "../uploads/$nuevo_nombre");
            
            $query_img = "INSERT INTO imagen_registro (imagen, fecha_imagen, id_registro) VALUES (?, ?, ?)";
            $stmt_img = mysqli_prepare($conexion, $query_img);
            $ruta_db = 'uploads/' . $nuevo_nombre;
            mysqli_stmt_bind_param($stmt_img, 'ssi', $ruta_db, $pendiente['fecha'], $pendiente['id_registro']);
            mysqli_stmt_execute($stmt_img);
            mysqli_stmt_close($stmt_img);
        }
        
        mysqli_query($conexion, 
            "UPDATE registro_salidas SET modo_offline = 0 
             WHERE id_registro = {$pendiente['id_registro']}");
    }
}

// Procesar formulario
$mostrar_exito = false;
$mostrar_pendientes = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_usuario = $_SESSION['usuario_id'];
    $nombre_comunidad = mysqli_real_escape_string($conexion, $_POST['nombre_comunidad']);
    $nombre_entrega = mysqli_real_escape_string($conexion, $_POST['nombre_entrega']);
    $nombre_jefe_hogar = mysqli_real_escape_string($conexion, $_POST['nombre_jefe_hogar']);
    $documento_jefe_hogar = mysqli_real_escape_string($conexion, $_POST['documento_jefe_hogar']);
    $nombre_salida = mysqli_real_escape_string($conexion, $_POST['nombre_salida']);
    $fecha = date('Y-m-d');
    
    $tipo_rfpp = null;
    if (isset($_POST['tiene_rfpp']) && $_POST['tiene_rfpp'] === 'si') {
        $tipo_rfpp = mysqli_real_escape_string($conexion, $_POST['tipo_rfpp']);
    }

    // Insertar registro
    $query = "INSERT INTO registro_salidas 
              (id_usuario, nombre_comunidad, nombre_entrega, nombre_jefe_hogar, 
               documento_jefe_hogar, nombre_salida, fecha, tipo_de_rfpp, modo_offline) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conexion, $query);
    mysqli_stmt_bind_param($stmt, 'isssssssi', $id_usuario, $nombre_comunidad, $nombre_entrega, 
                          $nombre_jefe_hogar, $documento_jefe_hogar, $nombre_salida, $fecha, $tipo_rfpp, $modo_offline);
    mysqli_stmt_execute($stmt);
    $id_registro = mysqli_insert_id($conexion);
    mysqli_stmt_close($stmt);

    // Procesar imagen
    if (!empty($_FILES['imagen']['name'])) {
        $directorio = '../uploads/';
        $nombre_archivo = $_FILES['imagen']['name'];
        $extension = strtolower(pathinfo($nombre_archivo, PATHINFO_EXTENSION));
        
        $prefijo = $modo_offline ? 'offline_' : '';
        $nombre_unico = $prefijo . uniqid() . '_' . basename($nombre_archivo);
        $ruta_final = $directorio . $nombre_unico;
        
        if (move_uploaded_file($_FILES['imagen']['tmp_name'], $ruta_final) && !$modo_offline) {
            $query_img = "INSERT INTO imagen_registro (imagen, fecha_imagen, id_registro) VALUES (?, ?, ?)";
            $stmt_img = mysqli_prepare($conexion, $query_img);
            $ruta_db = 'uploads/' . $nombre_unico;
            mysqli_stmt_bind_param($stmt_img, 'ssi', $ruta_db, $fecha, $id_registro);
            mysqli_stmt_execute($stmt_img);
            mysqli_stmt_close($stmt_img);
        }
    }

    $mostrar_exito = true;
    $mostrar_pendientes = $modo_offline;
    
    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            document.getElementById("registroForm").reset();
            selectedFile = null;
            renderPreview();
            document.getElementById("tipo_rfpp_container").style.display = "none";
        });
    </script>';
}

// Obtener datos para autocompletado
$query_familias = "SELECT cedula_padre, nombre_padre FROM datos_familiares WHERE usuario_id = ?";
$stmt_familias = mysqli_prepare($conexion, $query_familias);
mysqli_stmt_bind_param($stmt_familiars, "i", $_SESSION['usuario_id']);
mysqli_stmt_execute($stmt_familias);
$resultado_familias = mysqli_stmt_get_result($stmt_familias);
$familias = [];
while ($fila = mysqli_fetch_assoc($resultado_familias)) {
    $familias[] = $fila;
}

// Obtener historial
$query_historial = "SELECT * FROM registro_salidas WHERE id_usuario = ? ORDER BY fecha DESC LIMIT 10";
$stmt_historial = mysqli_prepare($conexion, $query_historial);
mysqli_stmt_bind_param($stmt_historial, "i", $_SESSION['usuario_id']);
mysqli_stmt_execute($stmt_historial);
$resultado_historial = mysqli_stmt_get_result($stmt_historial);
$historial = [];
while ($fila = mysqli_fetch_assoc($resultado_historial)) {
    $historial[] = $fila;
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
    <style>
        .modal-zoom { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9); overflow: auto; }
        .modal-content-zoom { margin: auto; display: block; width: 80%; max-width: 1200px; max-height: 90vh; margin-top: 5vh; }
        .close-zoom { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; }
        .autocomplete { position: relative; display: inline-block; width: 100%; }
        .autocomplete-items { position: absolute; border: 1px solid #d4d4d4; z-index: 99; top: 100%; left: 0; right: 0; max-height: 200px; overflow-y: auto; }
        .autocomplete-items div { padding: 10px; cursor: pointer; background-color: #fff; border-bottom: 1px solid #d4d4d4; }
        .autocomplete-items div:hover { background-color: #e9e9e9; }
        .autocomplete-active { background-color: DodgerBlue !important; color: #ffffff; }
        .connection-status { position: fixed; bottom: 20px; right: 20px; padding: 10px 15px; border-radius: 5px; font-weight: bold; z-index: 1000; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .online { background-color: #4CAF50; color: white; }
        .offline { background-color: #f44336; color: white; }
        .historial-item { border-bottom: 1px solid #e2e8f0; padding: 10px 0; }
        .offline-badge { background-color: #f44336; color: white; padding: 2px 6px; border-radius: 4px; font-size: 12px; margin-left: 8px; }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-4">
                    <span class="font-semibold text-gray-700">ID: <?= $_SESSION['usuario_id'] ?></span>
                    <span class="font-semibold text-gray-700">Usuario: <?= htmlspecialchars($_SESSION['usuario_nombre']) ?></span>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="../cliente.php" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-md text-sm font-medium">Volver</a>
                    <a href="#" onclick="cerrarSesion(); return false;" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-md text-sm font-medium">Cerrar Sesión</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="min-h-screen pt-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white p-8 rounded-lg shadow-md">
                <h1 class="text-3xl font-bold mb-6 text-center">Registro de Salidas</h1>
                
                <?php if ($mostrar_exito): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                title: '<?= $mostrar_pendientes ? "Registro guardado localmente" : "¡Éxito!" ?>',
                                text: '<?= $mostrar_pendientes ? "El registro se subirá cuando se restablezca la conexión" : "El registro se ha guardado correctamente" ?>',
                                icon: '<?= $mostrar_pendientes ? "info" : "success" ?>',
                                confirmButtonText: 'Aceptar'
                            });
                        });
                    </script>
                <?php endif; ?>
                
                <?php if ($modo_offline): ?>
                    <div class="mb-6 p-4 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700">
                        <p><i class="fas fa-exclamation-triangle mr-2"></i> Estás trabajando en modo offline. Los registros se sincronizarán automáticamente cuando se recupere la conexión.</p>
                    </div>
                <?php endif; ?>

                <form id="registroForm" action="registro.php" method="POST" enctype="multipart/form-data" class="space-y-6">
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
                        <div id="preview-container" class="mt-4">
                            <p class="text-sm text-gray-500 text-center">No hay imagen seleccionada</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="nombre_comunidad" class="block text-sm font-medium text-gray-700">Nombre de la Comunidad</label>
                            <input type="text" id="nombre_comunidad" name="nombre_comunidad" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label for="nombre_entrega" class="block text-sm font-medium text-gray-700">Nombre de quien entrega</label>
                            <input type="text" id="nombre_entrega" name="nombre_entrega" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="autocomplete">
                            <label for="documento_jefe_hogar" class="block text-sm font-medium text-gray-700">Documento del Jefe de Hogar</label>
                            <input type="text" id="documento_jefe_hogar" name="documento_jefe_hogar" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500" autocomplete="off">
                        </div>
                        <div>
                            <label for="nombre_jefe_hogar" class="block text-sm font-medium text-gray-700">Nombre del Jefe de Hogar</label>
                            <input type="text" id="nombre_jefe_hogar" name="nombre_jefe_hogar" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label for="nombre_salida" class="block text-sm font-medium text-gray-700">Nombre de la Salida</label>
                            <input type="text" id="nombre_salida" name="nombre_salida" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label for="fecha" class="block text-sm font-medium text-gray-700">Fecha</label>
                            <input type="date" id="fecha" name="fecha" value="<?= date('Y-m-d') ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">¿Tiene tipo de RFPP?</label>
                            <div class="mt-1 flex space-x-4">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="tiene_rfpp" value="si" class="h-4 w-4 text-blue-600 focus:ring-blue-500" onchange="document.getElementById('tipo_rfpp_container').style.display = 'block'">
                                    <span class="ml-2 text-gray-700">Sí</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="tiene_rfpp" value="no" class="h-4 w-4 text-blue-600 focus:ring-blue-500" onchange="document.getElementById('tipo_rfpp_container').style.display = 'none'" checked>
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

                    <div class="flex justify-center">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                            Guardar Registro
                        </button>
                    </div>
                </form>
                
                <div class="mt-12">
                    <h2 class="text-xl font-bold mb-4">Historial de Registros</h2>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <?php if (empty($historial)): ?>
                            <p class="text-gray-500">No hay registros recientes.</p>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ($historial as $registro): ?>
                                    <div class="historial-item">
                                        <div class="flex justify-between">
                                            <span class="font-medium"><?= htmlspecialchars($registro['nombre_comunidad']) ?></span>
                                            <span class="text-sm text-gray-500"><?= date('d/m/Y', strtotime($registro['fecha'])) ?></span>
                                        </div>
                                        <div class="text-sm text-gray-600">
                                            Jefe de Hogar: <?= htmlspecialchars($registro['nombre_jefe_hogar']) ?>
                                            <?= $registro['modo_offline'] ? '<span class="offline-badge">OFFLINE</span>' : '' ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="zoomModal" class="modal-zoom">
        <span class="close-zoom">&times;</span>
        <img class="modal-content-zoom" id="zoomedImage">
    </div>
    
    <div id="connectionStatus" class="connection-status <?= $modo_offline ? 'offline' : 'online hidden' ?>">
        <span id="connectionText"><?= $modo_offline ? 'Sin conexión - Modo offline activado' : 'Conectado' ?></span>
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
        // Variables y configuraciones
        let selectedFile = null;
        const fileInput = document.getElementById('imagen');
        const previewContainer = document.getElementById('preview-container');
        const connectionStatus = document.getElementById('connectionStatus');
        const connectionText = document.getElementById('connectionText');
        const familias = <?= json_encode($familias) ?>;
        
        // Verificar conexión
        function checkConnection() {
            const status = navigator.onLine ? 
                { class: 'online', text: 'Conectado' } : 
                { class: 'offline', text: 'Sin conexión - Modo offline activado' };
            
            connectionStatus.className = `connection-status ${status.class}`;
            connectionText.textContent = status.text;
            connectionStatus.classList.remove('hidden');
            
            if (navigator.onLine) {
                setTimeout(() => connectionStatus.classList.add('hidden'), 3000);
            }
        }
        
        // Eventos de conexión
        window.addEventListener('online', checkConnection);
        window.addEventListener('offline', checkConnection);
        setInterval(checkConnection, 30000);
        
        // Manejo de imágenes
        fileInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            const img = new Image();
            img.onload = function() {
                if (this.width >= this.height) {
                    Swal.fire('Error', 'La imagen debe ser vertical (altura > ancho)', 'error');
                    fileInput.value = '';
                } else {
                    selectedFile = file;
                    renderPreview();
                }
            };
            img.src = URL.createObjectURL(file);
        });
        
        function renderPreview() {
            previewContainer.innerHTML = selectedFile ? '' : 
                '<p class="text-sm text-gray-500 text-center">No hay imagen seleccionada</p>';
            
            if (!selectedFile) return;
            
            const reader = new FileReader();
            reader.onload = function(e) {
                previewContainer.innerHTML = `
                    <div class="flex flex-col items-center">
                        <img src="${e.target.result}" class="w-64 h-64 object-contain rounded-md cursor-zoom-in" 
                             alt="Previsualización" onclick="openZoomModal('${e.target.result}')">
                        <button type="button" class="mt-2 bg-red-500 text-white rounded-md px-3 py-1 text-sm hover:bg-red-600"
                                onclick="selectedFile=null; fileInput.value=''; renderPreview()">
                            Eliminar imagen
                        </button>
                        <p class="text-xs text-gray-500 mt-1">${selectedFile.name}</p>
                    </div>
                `;
            };
            reader.readAsDataURL(selectedFile);
        }
        
        // Zoom de imagen
        function openZoomModal(imgSrc) {
            const modal = document.getElementById('zoomModal');
            const modalImg = document.getElementById('zoomedImage');
            modal.style.display = "block";
            modalImg.src = imgSrc;
            modalImg.style.width = window.innerWidth > 768 ? 'auto' : '100%';
            modalImg.style.height = window.innerWidth > 768 ? '90vh' : 'auto';
        }
        
        document.querySelector('.close-zoom').onclick = () => 
            document.getElementById('zoomModal').style.display = "none";
        
        window.onclick = (event) => {
            if (event.target == document.getElementById('zoomModal')) {
                document.getElementById('zoomModal').style.display = "none";
            }
        };
        
        // Autocompletado
        function autocomplete(inp, arr) {
            let currentFocus;
            
            inp.addEventListener("input", function() {
                closeAllLists();
                if (!this.value) return;
                currentFocus = -1;
                
                const a = document.createElement("DIV");
                a.id = this.id + "autocomplete-list";
                a.className = "autocomplete-items";
                this.parentNode.appendChild(a);
                
                arr.forEach(item => {
                    if (item.cedula_padre.substr(0, this.value.length).toUpperCase() === this.value.toUpperCase()) {
                        const b = document.createElement("DIV");
                        b.innerHTML = `<strong>${item.cedula_padre.substr(0, this.value.length)}</strong>`;
                        b.innerHTML += item.cedula_padre.substr(this.value.length);
                        b.innerHTML += `<input type='hidden' value='${item.cedula_padre}'>`;
                        b.innerHTML += `<small class='text-gray-500 ml-2'>${item.nombre_padre}</small>`;
                        
                        b.addEventListener("click", function() {
                            inp.value = this.getElementsByTagName("input")[0].value;
                            document.getElementById('nombre_jefe_hogar').value = 
                                arr.find(f => f.cedula_padre === inp.value)?.nombre_padre || '';
                            closeAllLists();
                        });
                        a.appendChild(b);
                    }
                });
            });
            
            inp.addEventListener("keydown", function(e) {
                let x = document.getElementById(this.id + "autocomplete-list");
                if (x) x = x.getElementsByTagName("div");
                
                if (e.key == 'ArrowDown') currentFocus++;
                else if (e.key == 'ArrowUp') currentFocus--;
                else if (e.key == 'Enter') {
                    e.preventDefault();
                    if (currentFocus > -1 && x) x[currentFocus].click();
                }
                
                addActive(x);
            });
            
            function addActive(x) {
                if (!x) return;
                removeActive(x);
                currentFocus = currentFocus >= x.length ? 0 : currentFocus < 0 ? x.length - 1 : currentFocus;
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
            
            document.addEventListener("click", (e) => closeAllLists(e.target));
        }
        
        autocomplete(document.getElementById("documento_jefe_hogar"), familias);
        
        // Manejo de sesión
        function cerrarSesion() {
            fetch('../index.php?clean_token=1', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'user_id=<?= $_SESSION['usuario_id'] ?>'
            }).then(() => window.location.href = '../index.php');
        }

        function limpiarTokenAlSalir() {
            navigator.sendBeacon('../index.php?clean_token=1', 'user_id=<?= $_SESSION['usuario_id'] ?>');
        }

        window.addEventListener('beforeunload', function(e) {
            if (!e.target.activeElement?.tagName === 'A' && 
                !e.target.activeElement?.href?.startsWith(window.location.origin)) {
                limpiarTokenAlSalir();
            }
        });
    </script>
</body>
</html>