<?php
session_start();

// Usuarios de ejemplo (id, nombre, correo, password en texto por ahora, rol)
// En producción usa una base de datos y password_hash()/password_verify().
$usuarios = [
    "admin@mupe.com"   => ["id" => 1, "nombre" => "Administrador", "password" => "admin123",   "rol" => "Administrador"],
    "cliente@mupe.com" => ["id" => 2, "nombre" => "Cliente Demo",  "password" => "cliente123", "rol" => "Cliente"],
    "vet@mupe.com"     => ["id" => 3, "nombre" => "Dr. Veterinario","password" => "vet123",     "rol" => "Veterinario"],
    "recep@mupe.com"   => ["id" => 4, "nombre" => "Recepción",     "password" => "recep123",   "rol" => "Recepcionista"],
    "areli@mupe.com"   => ["id" => 5, "nombre" => "Areli",         "password" => "areli123",   "rol" => "Cliente"]
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $correo = strtolower(trim($_POST["correo"] ?? ""));
    $contrasena = trim($_POST["contrasena"] ?? "");

    if ($correo !== "" && isset($usuarios[$correo]) && $usuarios[$correo]["password"] === $contrasena) {
        // Login correcto: regenerar id de sesión y guardar datos clave
        session_regenerate_id(true);

        $u = $usuarios[$correo];

        // Datos en sesión (compatibles con tus páginas actuales)
        $_SESSION["id_usuario"] = (int)$u["id"];
        $_SESSION["user_id"]    = (int)$u["id"];             // alias por compatibilidad
        $_SESSION["usuario"]    = $u["nombre"];              // nombre para mostrar en sidebar
        $_SESSION["correo"]     = $correo;
        $_SESSION["rol"]        = $u["rol"];

        // Redirección según rol
        switch ($u["rol"]) {
            case "Administrador":
                header("Location: admin_panel.php");
                break;
            case "Cliente":
                header("Location: cliente_panel.php");
                break;
            case "Veterinario":
                header("Location: vet_panel.php");
                break;
            case "Recepcionista":
                header("Location: recep_panel.php");
                break;
            default:
                header("Location: index.html");
        }
        exit;
    } else {
        echo "<p style='color:red; text-align:center;'>Correo o contraseña incorrectos</p>";
        echo "<p style='text-align:center;'><a href='login.html'>Volver</a></p>";
    }
}
?>