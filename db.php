<?php

$host = "localhost";
$usuario = "root";
$contrasena = "";
$base_de_datos = "gestion_registros";

// Establecer conexión sin especificar el puerto (usa el puerto por defecto 3306)
$conexion = mysqli_connect($host, $usuario, $contrasena, $base_de_datos);

// Verificar la conexión
if (!$conexion) {
    die("Error de conexión: " . mysqli_connect_error());
}

// Si llega aquí, la conexión fue exitosa
echo "Conexión exitosa a la base de datos.";

?>
