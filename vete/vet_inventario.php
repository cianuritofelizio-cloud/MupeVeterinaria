<?php
session_start();
$usuario = $_SESSION['usuario'] ?? 'N/A';

// --- Conexión a la base de datos (MySQLi) ---
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "veterinaria_mupe";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) { die("Error: " . $conn->connect_error); }

// -------------------- AGREGAR o ACTUALIZAR PRODUCTO --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'agregar';
    $nombre = $_POST['nombre'];
    $descripcion = $_POST['descripcion'];
    $cantidad = (int)$_POST['cantidad'];
    $precio = (float)$_POST['precio'];

    if ($mode === 'actualizar') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE inventario SET nombre=?, descripcion=?, cantidad=?, precio=? WHERE id=?");
        $stmt->bind_param("ssisi", $nombre, $descripcion, $cantidad, $precio, $id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO inventario (nombre, descripcion, cantidad, precio) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $nombre, $descripcion, $cantidad, $precio);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: admin_inventario.php");
    exit;
}

// -------------------- ELIMINAR --------------------
if (isset($_GET['eliminar'])) {
    $id = (int)$_GET['eliminar'];
    $stmt = $conn->prepare("DELETE FROM inventario WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_inventario.php");
    exit;
}

// -------------------- OBTENER PRODUCTOS --------------------
$productos = $conn->query("SELECT * FROM inventario ORDER BY id DESC");

// -------------------- EDITAR PRODUCTO --------------------
$editar_producto = null;
if (isset($_GET['editar'])) {
    $id = (int)$_GET['editar'];
    $result = $conn->query("SELECT * FROM inventario WHERE id=$id");
    $editar_producto = $result->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Inventario</title>
<style>
/* ---- ESTILOS BASE ---- */
html, body { 
    height:100%; 
    margin:0; padding:0; font-family:Arial,sans-serif; background:#f7f7f7; overflow-y:auto; }
.container { 
    max-width:1200px; 
    margin-left:300px; 
    padding:30px; display:flex; flex-direction:column; gap:25px; }
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
    color:#333; }
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
    transition:all 0.3s ease; }
.top-add-btn:hover { background:#b30c40; transform:translateY(-2px); }

/* ---- TABLA ---- */
.table-wrapper { flex:1 1 auto; overflow-y:auto; background:#fff; border-radius:6px; padding-top:1px; box-shadow:0 5px 15px rgba(0,0,0,0.08); }
.table-wrapper::-webkit-scrollbar { display:none; }
table { width:100%; border-collapse:collapse; min-width:800px; }
th { background:#E9A0A0; color:#333; padding:12px 8px; text-transform:uppercase; font-weight:700; position:sticky; top:0; z-index:10; }
td { padding:10px 8px; text-align:center; font-size:14px; color:#555; }
table tr:nth-child(even){ background:#fff; } table tr:hover{ background:#fff0f5; }

/* ---- BOTONES ---- */
.action-btn { padding:6px 15px; border-radius:4px; font-weight:600; margin:3px; display:inline-block; font-size:12px; text-transform:uppercase; cursor:pointer; color:#fff; border:none; }
.edit-btn { background:#E50F53; } .edit-btn:hover { background:#b30c40; }
.delete-btn { background:#555; } .delete-btn:hover { background:#333; }

/* ---- MODAL ---- */
.modal-overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); display:none; justify-content:center; align-items:center; z-index:1000; opacity:0; transition:opacity 0.3s; }
.modal-overlay.show { display:flex; opacity:1; }
.form-box { background:#fff; padding:25px; border-radius:10px; max-width:450px; width:90%; box-shadow:0 10px 30px rgba(0,0,0,0.3); border-top:3px solid #E50F53; transform:translateY(-50px); transition:transform 0.3s; text-align:left; }
.modal-overlay.show .form-box { transform:translateY(0); }
.form-box h2 { text-align:center; color:#E50F53; margin-top:0; margin-bottom:15px; font-size:1.4em; }
.form-box label { display:block; margin-top:10px; font-weight:600; color:#333; font-size:13px; }
.form-box input, .form-box textarea { width:100%; padding:10px; margin-top:5px; border-radius:6px; border:1px solid #ccc; font-size:13px; box-sizing:border-box; transition:border-color 0.3s, box-shadow 0.3s; }
.form-box input:focus, .form-box textarea:focus { border-color:#E50F53; box-shadow:0 0 5px rgba(229,15,83,0.2); outline:none; }
.form-box button[type="submit"] { width:100%; margin-top:20px; padding:12px; background:#E50F53; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer; text-transform:uppercase; transition:background-color 0.3s; }
.form-box button[type="submit"]:hover { background:#b30c40; }
</style>
</head>
<body>

<?php include "sidebarVet.php"; ?>

<div class="container">

<h1>Gestión de Inventario</h1>
<button class="top-add-btn" onclick="openModal(null,'agregar')">Agregar Nuevo Producto</button>
<h2 class="subtitle">Inventario Actual</h2>

<div class="table-wrapper">
<table>
<tr>
<th>ID</th><th>Nombre</th><th>Descripción</th><th>Cantidad</th><th>Precio</th><th>Acciones</th>
</tr>
<?php while($row=$productos->fetch_assoc()): ?>
<tr>
<td><?= $row['id'] ?></td>
<td><?= htmlspecialchars($row['nombre']) ?></td>
<td><?= htmlspecialchars($row['descripcion']) ?></td>
<td><?= $row['cantidad'] ?></td>
<td>$<?= number_format((float)$row['precio'],2) ?></td>
<td>
<button class="action-btn edit-btn" onclick="openModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>,'actualizar')">Editar</button>
<a href="admin_inventario.php?eliminar=<?= $row['id'] ?>" class="action-btn delete-btn" onclick="return confirm('¿Eliminar producto?');">Eliminar</a>
</td>
</tr>
<?php endwhile; ?>
</table>
</div>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="inventarioModal" onclick="closeModalOnOutsideClick(event)">
<div class="form-box">
<form method="POST" id="inventarioForm">
<h2 id="formTitle"></h2>
<input type="hidden" name="id" id="id_producto">
<input type="hidden" name="mode" id="mode_field">
<label>Nombre:</label><input type="text" name="nombre" id="nombre" required>
<label>Descripción:</label><textarea name="descripcion" id="descripcion" required></textarea>
<label>Cantidad:</label><input type="number" name="cantidad" id="cantidad" required>
<label>Precio ($):</label><input type="number" step="0.01" name="precio" id="precio" required>
<button type="submit" id="submitButton"></button>
</form>
</div>
</div>

<script>
const modal = document.getElementById('inventarioModal');
const form = document.getElementById('inventarioForm');
const formTitle = document.getElementById('formTitle');
const submitButton = document.getElementById('submitButton');
const modeField = document.getElementById('mode_field');

function openModal(producto, mode){
    form.reset();
    document.getElementById('id_producto').value = '';
    if(mode==='actualizar' && producto){
        document.getElementById('id_producto').value = producto.id;
        document.getElementById('nombre').value = producto.nombre;
        document.getElementById('descripcion').value = producto.descripcion;
        document.getElementById('cantidad').value = producto.cantidad;
        document.getElementById('precio').value = producto.precio;
        formTitle.textContent = 'Editar Producto (ID: '+producto.id+')';
        submitButton.textContent = 'Guardar Cambios';
    } else {
        formTitle.textContent = 'Agregar Producto';
        submitButton.textContent = 'Agregar Producto';
    }
    modeField.value = mode;
    submitButton.name = mode;
    modal.classList.add('show');
}

function closeModal(){ modal.classList.remove('show'); }
function closeModalOnOutsideClick(event){ if(event.target===modal) closeModal(); }

window.onload=function(){
    const params = new URLSearchParams(window.location.search);
    if(params.has('editar')){
        const editarProducto = <?= json_encode($editar_producto) ?>;
        if(editarProducto) openModal(editarProducto,'actualizar');
        window.history.pushState({},document.title,window.location.pathname);
    }
};
</script>
<h3>.</h3>
<h3>.</h3>
<h3>.</h3>


</body>
</html>
