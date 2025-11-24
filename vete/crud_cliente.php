<?php
session_start();

// --- Validar sesión ---
if (!isset($_SESSION['rol']) || $_SESSION['rol'] != 'Recepcionista') {
    header("Location: ../../login.php");
    exit;
}

$usuario = $_SESSION['usuario'];

// --- Conexión BD ---
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

// --- AGREGAR / EDITAR CLIENTE ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_usuario = $_POST['id_usuario'] ?? '';
    $nombre = $_POST['nombre'];
    $correo = $_POST['correo'];
    $contrasena = $_POST['contrasena'];

    if ($id_usuario != '') {
        if (!empty($contrasena)) {
            $hash = password_hash($contrasena, PASSWORD_DEFAULT);
            $sql = "UPDATE usuarios SET nombre=?, correo=?, contrasena=? WHERE id_usuario=?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$nombre, $correo, $hash, $id_usuario]);
        } else {
            $sql = "UPDATE usuarios SET nombre=?, correo=? WHERE id_usuario=?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$nombre, $correo, $id_usuario]);
        }
        header("Location: crud_cliente.php?mensaje=actualizado");
        exit;
    } else {
        $hash = password_hash($contrasena, PASSWORD_DEFAULT);
        $sql = "INSERT INTO usuarios (nombre, correo, contrasena, rol) VALUES (?, ?, ?, 'Cliente')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $correo, $hash]);
        header("Location: crud_cliente.php?mensaje=agregado");
        exit;
    }
}

// --- ELIMINAR CLIENTE ---
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    $stmt = $conn->prepare("DELETE FROM usuarios WHERE id_usuario=? AND rol='Cliente'");
    $stmt->execute([$id]);
    header("Location: crud_cliente.php?mensaje=eliminado");
    exit;
}

// --- CARGAR CLIENTE PARA EDITAR ---
$cliente_editar = null;
if (isset($_GET['editar'])) {
    $id = $_GET['editar'];
    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE id_usuario=? AND rol='Cliente'");
    $stmt->execute([$id]);
    $cliente_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- LISTA DE CLIENTES ---
$clientes = $conn->query("SELECT * FROM usuarios WHERE rol='Cliente' ORDER BY id_usuario DESC")
          ->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Clientes</title>

<style>
html, body { 
    height:100%; 
    margin:0; padding:0; font-family:Arial,sans-serif; background:#f7f7f7; overflow-y:auto;
}
.container { 
    max-width:1200px; 
    margin-left:300px; 
    padding:30px; display:flex; flex-direction:column; gap:25px;
}
h1 { 
    color: #E9A0A0; 
    text-align:center; 
    font-size:2.5em; 
    margin:0; 
    font-weight:bold; 
    border-bottom: 2px solid #E50F53;
}
.subtitle { 
    font-size:1.5em; 
    text-align:center; 
    margin-top:15px; 
    margin-bottom:0; 
    color:#333;
}
.top-add-btn { 
    background: #E9A0A0; 
    color:#fff; 
    padding:12px 30px; 
    border:none; 
    border-radius:4px; 
    font-weight:700; 
    cursor:pointer; 
    text-transform:uppercase; 
    display:block; 
    margin:0 auto; 
    transition:all 0.3s ease;
}
.top-add-btn:hover { background:#b30c40; transform:translateY(-2px); }

/* ---- TABLA ---- */
.table-wrapper { flex:1 1 auto; overflow-y:auto; background:#fff; border-radius:6px; padding-top:1px; box-shadow:0 5px 15px rgba(0,0,0,0.08); }
.table-wrapper::-webkit-scrollbar { display:none; }
table { width:100%; border-collapse:collapse; min-width:800px; }
th { background:#E9A0A0; color:#333; padding:12px 8px; text-transform:uppercase; font-weight:700; position:sticky; top:0; z-index:10; }
td { padding:10px 8px; text-align:center; font-size:14px; color:#555; }
table tr:nth-child(even){ background:#fff; } 
table tr:hover{ background:#fff0f5; }

/* ---- ACCIONES ---- */
.action-btn { padding:6px 15px; border-radius:4px; font-weight:600; margin:3px; display:inline-block; font-size:12px; text-transform:uppercase; cursor:pointer; color:#fff; border:none; }
.edit-btn { background:#E50F53; } 
.edit-btn:hover { background:#b30c40; }
.delete-btn { background:#555; } 
.delete-btn:hover { background:#333; }

/* ---- MODAL ---- */
.modal-overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); display:none; justify-content:center; align-items:center; z-index:1000; opacity:0; transition:opacity 0.3s; }
.modal-overlay.show { display:flex; opacity:1; }
.form-box { background:#fff; padding:25px; border-radius:10px; max-width:450px; width:90%; box-shadow:0 10px 30px rgba(0,0,0,0.3); border-top:3px solid #E50F53; transform:translateY(-50px); transition:transform 0.3s; }
.modal-overlay.show .form-box { transform:translateY(0); }
.form-box h2 { text-align:center; color:#E50F53; margin-top:0; margin-bottom:15px; font-size:1.4em; }
.form-box label { display:block; margin-top:10px; font-weight:600; color:#333; font-size:13px; }
.form-box input { width:100%; padding:10px; margin-top:5px; border-radius:6px; border:1px solid #ccc; font-size:13px; box-sizing:border-box; }
.form-box input:focus { border-color:#E50F53; box-shadow:0 0 5px rgba(229,15,83,0.2); outline:none; }
.form-box button[type="submit"] { width:100%; margin-top:20px; padding:12px; background:#E50F53; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer; text-transform:uppercase; }
.form-box button[type="submit"]:hover { background:#b30c40; }
</style>
</head>

<body>

<?php include "sidebarRecep.php"; ?>

<div class="container">

<h1>Gestión de Clientes</h1>
<button class="top-add-btn" onclick="openModal(null,'agregar')">Agregar Nuevo Cliente</button>

<h2 class="subtitle">Lista de Clientes</h2>

<div class="table-wrapper">
<table>
<tr>
<th>ID</th><th>Nombre</th><th>Correo</th><th>Acciones</th>
</tr>

<?php foreach($clientes as $c): ?>
<tr>
<td><?= $c['id_usuario'] ?></td>
<td><?= htmlspecialchars($c['nombre']) ?></td>
<td><?= htmlspecialchars($c['correo']) ?></td>
<td>
<button class="action-btn edit-btn" 
onclick="openModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>,'actualizar')">
Editar
</button>

<a href="crud_cliente.php?eliminar=<?= $c['id_usuario'] ?>" 
class="action-btn delete-btn" onclick="return confirm('¿Eliminar cliente?');">
Eliminar
</a>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>

</div>

<!-- MODAL -->
<div class="modal-overlay" id="clienteModal" onclick="closeModalOnOutsideClick(event)">
<div class="form-box">
<form method="POST" id="clienteForm">

<h2 id="formTitle"></h2>

<input type="hidden" name="id_usuario" id="id_usuario">
<input type="hidden" name="mode" id="mode_field">

<label>Nombre:</label>
<input type="text" name="nombre" id="nombre" required>

<label>Correo:</label>
<input type="email" name="correo" id="correo" required>

<label>Contraseña (dejar vacío si no cambia):</label>
<input type="password" name="contrasena" id="contrasena">

<button type="submit" id="submitButton"></button>
</form>
</div>
</div>
<h3>.</h3>
<h3>.</h3>
<h3>.</h3>
<h3>.</h3>



<script>
const modal = document.getElementById('clienteModal');
const form = document.getElementById('clienteForm');
const formTitle = document.getElementById('formTitle');
const submitButton = document.getElementById('submitButton');
const modeField = document.getElementById('mode_field');

function openModal(cliente, mode){
    form.reset();
    document.getElementById('id_usuario').value = '';

    if(mode === 'actualizar' && cliente){
        document.getElementById('id_usuario').value = cliente.id_usuario;
        document.getElementById('nombre').value = cliente.nombre;
        document.getElementById('correo').value = cliente.correo;

        formTitle.textContent = 'Editar Cliente (ID: '+cliente.id_usuario+')';
        submitButton.textContent = 'Guardar Cambios';
    } else {
        formTitle.textContent = 'Agregar Cliente';
        submitButton.textContent = 'Registrar Cliente';
    }

    modeField.value = mode;
    submitButton.name = mode;
    modal.classList.add('show');
}

function closeModal(){ modal.classList.remove('show'); }
function closeModalOnOutsideClick(event){ if(event.target === modal) closeModal(); }

window.onload = function(){
    const params = new URLSearchParams(window.location.search);
    if(params.has('editar')){
        const clienteEditar = <?= json_encode($cliente_editar) ?>;
        if(clienteEditar) openModal(clienteEditar,'actualizar');
        window.history.pushState({}, document.title, window.location.pathname);
    }
};
</script>

</body>
</html>
