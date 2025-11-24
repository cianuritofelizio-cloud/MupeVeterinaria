<?php
session_start();

// Validar que el usuario esté logueado y tenga rol Administrador
if (!isset($_SESSION["usuario"]) || $_SESSION["rol"] !== "Administrador") {
    header("Location: login.html");
    exit;
}

$usuario = $_SESSION['usuario'] ?? '';

// --- Conexión a la base de datos ---
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "veterinaria_mupe";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// --- AGREGAR o EDITAR ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_usuario = $_POST['id_usuario'] ?? '';
    $nombre = $_POST['nombre'] ?? '';
    $correo = $_POST['correo'] ?? '';
    $contrasena = $_POST['contrasena'] ?? '';
    $rol = $_POST['rol'] ?? '';

    // Validación: La contraseña es obligatoria al agregar
    if ($id_usuario == '' && empty($contrasena)) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?error=password_required");
        exit;
    }

    if ($id_usuario != '') {
        // Actualizar usuario
        if ($contrasena != '') {
            $sql = "UPDATE usuarios SET nombre=?, correo=?, contrasena=?, rol=? WHERE id_usuario=?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$nombre, $correo, password_hash($contrasena, PASSWORD_DEFAULT), $rol, $id_usuario]);
        } else {
            $sql = "UPDATE usuarios SET nombre=?, correo=?, rol=? WHERE id_usuario=?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$nombre, $correo, $rol, $id_usuario]);
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?mensaje=actualizado");
        exit;
    } else {
        // Agregar usuario
        $sql = "INSERT INTO usuarios (nombre, correo, contrasena, rol) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $correo, password_hash($contrasena, PASSWORD_DEFAULT), $rol]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?mensaje=agregado");
        exit;
    }
}

// --- ELIMINAR ---
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    $stmt = $conn->prepare("DELETE FROM usuarios WHERE id_usuario=?");
    $stmt->execute([$id]);
    header("Location: " . $_SERVER['PHP_SELF'] . "?mensaje=eliminado");
    exit;
}

// --- EDITAR (cargar datos) ---
$usuario_editar = null;
if (isset($_GET['editar'])) {
    $id = $_GET['editar'];
    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE id_usuario=?");
    $stmt->execute([$id]);
    $usuario_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- LISTA DE USUARIOS ---
$usuarios = $conn->query("SELECT * FROM usuarios ORDER BY id_usuario DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>CRUD Usuarios - Administrador</title>
    <link rel="stylesheet" href="admin_panel.css">
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            background: #f7f7f7;
            font-family: Arial, sans-serif;
            overflow: hidden;
        }
        .container {
            height: 100vh;
            display: flex;
            flex-direction: column;
            gap: 30px;
            padding: 30px;
            box-sizing: border-box;
            max-width: 1200px;
            margin-left: 300px;
        }
        h1 {
            color: #E9A0A0;
            text-align: center;
            margin: 0;
            font-weight: bold;
            font-size: 2.2em;
            padding-bottom: 5px;
            border-bottom: 2px solid #E50F53;
        }
        .top-add-btn {
            background: #E9A0A0;
            color: white;
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 auto;
            display: block;
            width: fit-content;
        }
        .top-add-btn:hover {
            background: #b30c40;
            box-shadow: 0 6px 15px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        .table-wrapper {
            flex: 1 1 auto;
            width: 100%;
            overflow-y: auto;
            padding: 10px 0 0 0;
        }
        .table-wrapper::-webkit-scrollbar { display: none; }
        .table-wrapper { -ms-overflow-style: none; scrollbar-width: none; }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            min-width: 800px;
        }
        th {
            background: #E9A0A0;
            color: #333;
            padding: 15px 10px;
            font-size: 15px;
            text-align: center;
            font-weight: 700;
            text-transform: uppercase;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #eee;
            text-align: center;
            font-size: 14px;
        }
        table tr:nth-child(even) { background-color: #fafafa; }
        table tr:hover { background-color: #fff0f5; }
        .action-btn {
            padding: 8px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            margin: 3px;
            display: inline-block;
            transition: all 0.3s;
            font-size: 13px;
            text-transform: uppercase;
            background: #E50F53;
            color: white;
            border: none;
            cursor: pointer;
        }
        .action-btn:hover { background: #b30c40; }
        .action-delete { background: #555; display: inline-block; text-decoration: none; padding: 8px 14px; color: #fff; border-radius: 6px; }
        .action-delete:hover { background: #333; }

        /* MODAL */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .modal-overlay.show { display: flex; opacity: 1; }
        .form-box {
            background: white;
            padding: 30px;
            border-radius: 10px;
            max-width: 450px;
            width: 90%;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border-top: 3px solid #E50F53;
            transform: translateY(-50px);
            transition: transform 0.3s ease;
            text-align: left;
        }
        .modal-overlay.show .form-box { transform: translateY(0); }
        .form-box h2 { color: #E50F53; text-align: center; margin-top: 0; font-size: 1.5em; margin-bottom: 20px; }
        .form-box label { display: block; margin-top: 15px; font-weight: 600; color: #333; }
        .form-box input, .form-box select {
            width: 100%;
            padding: 12px;
            margin-top: 8px;
            border-radius: 6px;
            border: 1px solid #ccc;
            box-sizing: border-box;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .form-box input:focus, .form-box select:focus {
            border-color: #E50F53;
            outline: none;
            box-shadow: 0 0 5px rgba(229, 15, 83, 0.2);
        }
        .form-box button[type="submit"] {
            width: 100%;
            margin-top: 25px;
            padding: 14px;
            background: #E50F53;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.3s;
            text-transform: uppercase;
        }
        .form-box button[type="submit"]:hover { background: #b30c40; }
        @media (max-width: 900px) {
            .container { padding: 15px; }
            .form-box { width: 95%; padding: 20px; }
            table { min-width: 600px; }
        }
    </style>
</head>
<body>

<?php include "sidebar.php"; ?>

<div class="container">
    <h1>Gestión de Usuarios</h1>
    <button class="top-add-btn" onclick="openModal(null)">Agregar Nuevo Usuario</button>
<link rel="stylesheet" href="global.css">
    <div class="table-wrapper">
        <table>
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Acciones</th>
            </tr>
            <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= $u['id_usuario'] ?></td>
                <td><?= htmlspecialchars($u['nombre']) ?></td>
                <td><?= htmlspecialchars($u['correo']) ?></td>
                <td><?= htmlspecialchars($u['rol']) ?></td>
                <td>
                    <!-- Botón Editar no navega; JSON escapado -->
                    <button type="button" onclick='openModal(<?= json_encode($u, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>);' class="action-btn">Editar</button>
                    <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?eliminar=<?= $u['id_usuario'] ?>" class="action-delete" onclick="return confirm('¿Eliminar este usuario?')">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="usuarioModal" onclick="closeModalOnOutsideClick(event)">
    <div class="form-box">
        <form method="POST" id="usuarioForm">
            <h2 id="formTitle"></h2>
            <input type="hidden" name="id_usuario" id="id_usuario">

            <label>Nombre:</label>
            <input type="text" name="nombre" id="nombre" required>

            <label>Correo:</label>
            <input type="email" name="correo" id="correo" required>

            <label>Contraseña:</label>
            <input type="password" name="contrasena" id="contrasena" placeholder="Dejar en blanco para no cambiar">

            <label>Rol:</label>
            <select name="rol" id="rol" required>
                <option value="">Seleccione...</option>
                <option value="Administrador">Administrador</option>
                <option value="Recepcionista">Recepcionista</option>
                <option value="Cliente">Cliente</option>
            </select>

            <button type="submit" id="submitButton"></button>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('usuarioModal');
const form = document.getElementById('usuarioForm');
const formTitle = document.getElementById('formTitle');
const submitButton = document.getElementById('submitButton');
const passInput = document.getElementById('contrasena');
const rolSelect = document.getElementById('rol');

function openModal(usuario) {
    const isEditing = usuario && usuario.id_usuario;
    form.reset();
    document.getElementById('id_usuario').value = '';
    passInput.removeAttribute('required');
    passInput.value = '';
    passInput.placeholder = 'Dejar en blanco para no cambiar';
    rolSelect.value = '';

    if (isEditing) {
        document.getElementById('id_usuario').value = usuario.id_usuario;
        document.getElementById('nombre').value = usuario.nombre;
        document.getElementById('correo').value = usuario.correo;
        rolSelect.value = usuario.rol;
        formTitle.textContent = 'Editar Usuario (ID: ' + usuario.id_usuario + ')';
        submitButton.textContent = 'Guardar Cambios';
    } else {
        formTitle.textContent = 'Agregar Nuevo Usuario';
        submitButton.textContent = 'Agregar Usuario';
        passInput.setAttribute('required', 'required');
        passInput.placeholder = 'Contraseña requerida';
    }

    modal.classList.add('show');
}

function closeModal() {
    modal.classList.remove('show');
    if (window.location.search.includes('editar=')) {
        window.history.pushState({}, document.title, window.location.pathname);
    }
}

function closeModalOnOutsideClick(event) {
    if (event.target === modal) closeModal();
}

window.onload = function() {
    const params = new URLSearchParams(window.location.search);
    if (params.has('editar')) {
        const usuarioEditar = <?= json_encode($usuario_editar, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
        if (usuarioEditar) openModal(usuarioEditar);
    }

    if (params.has('error') && params.get('error') === 'password_required') {
        alert("Error: La contraseña es obligatoria al agregar un nuevo usuario.");
    }
};
</script>

<?php include "footer.php"; ?>
</body>
</html>