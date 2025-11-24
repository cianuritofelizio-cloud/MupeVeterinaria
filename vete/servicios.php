<?php
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

$accion = $_GET['accion'] ?? '';

// =========================
//   GUARDAR / ACTUALIZAR SERVICIO
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $id_servicio = $_POST['id_servicio'] ?? '';
    $nombre = $_POST['nombre'] ?? '';
    $descripcion = $_POST['descripcion'] ?? '';
    $precio = $_POST['precio'] ?? '';

    if (empty($nombre) || empty($descripcion) || empty($precio)) {
        header("Location: servicios.php?error=campos_obligatorios");
        exit;
    } 
    
    if (empty($id_servicio)) {
        $stmt = $conn->prepare("INSERT INTO servicios (nombre, descripcion, precio) VALUES (?, ?, ?)");
        $stmt->execute([$nombre, $descripcion, $precio]);
    } else {
        $stmt = $conn->prepare("UPDATE servicios SET nombre=?, descripcion=?, precio=? WHERE id_servicio=?");
        $stmt->execute([$nombre, $descripcion, $precio, $id_servicio]);
    }

    header("Location: servicios.php");
    exit;
}

// =========================
//   ELIMINAR SERVICIO
// =========================
if ($accion == "eliminar") {
    $id = $_GET['id'] ?? 0;
    $stmt = $conn->prepare("DELETE FROM servicios WHERE id_servicio=?");
    $stmt->execute([$id]);
    header("Location: servicios.php");
    exit;
}

// =========================
//   EDITAR SERVICIO (CARGAR DATOS)
// =========================
$editData = null;
if ($accion == "editar") {
    $id = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM servicios WHERE id_servicio=?");
    $stmt->execute([$id]);
    $editData = $stmt->fetch(PDO::FETCH_ASSOC);
}

$stmt = $conn->query("SELECT * FROM servicios ORDER BY id_servicio DESC");
$servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Servicios</title>
<style>
html, body { 
    height:100%; 
    margin:0; 
    padding:0; 
    background:#f7f7f7; 
    font-family:Arial,sans-serif; 
    overflow-y:auto; 
}

/* --- CONTENEDOR PRINCIPAL --- */
.container { 
    height:100vh; 
    display:flex; 
    flex-direction:column; 
    gap:30px; 
    padding:30px; 
    box-sizing:border-box; 
    max-width:1200px; 
    margin-left:300px; /* espacio para sidebar */
}

/* --- TÍTULO --- */
h1 { 
    color:#E9A0A0; 
    text-align:center; 
    margin:0; 
    font-weight:bold; 
    font-size:2.2em; 
    padding-bottom:5px; 
    border-bottom:2px solid #E50F53; 
}

/* --- BOTÓN AGREGAR --- */
.top-add-btn { 
    background:#E9A0A0; 
    color:white; 
    padding:14px 28px; 
    border:none; 
    border-radius:8px; 
    font-size:16px; 
    font-weight:700; 
    cursor:pointer; 
    transition:all 0.3s; 
    margin:0 auto; 
    display:block; 
    width:fit-content; 
    text-transform:uppercase; 
}
.top-add-btn:hover { 
    background:#b30c40; 
    box-shadow:0 6px 15px rgba(0,0,0,0.15); 
    transform:translateY(-2px); 
}

/* --- TABLA --- */
.table-wrapper { 
    width: 100%; 
    overflow-x: auto; 
    box-sizing: border-box; 
}
.table-wrapper::-webkit-scrollbar { display:none; }

table { 
    width:max-content; 
    min-width:100%; 
    border-collapse: collapse; 
    background:white; 
    border-radius:10px; 
    overflow:hidden; 
    box-shadow:0 5px 15px rgba(0,0,0,0.08); 
}

th, td { 
    padding:8px 6px; 
    text-align:center; 
    font-size:12px; 
    white-space: nowrap;
}

th { 
    background: #E9A0A0; 
    color:#333; 
    font-weight:700; 
    text-transform:uppercase; 
    position:sticky; 
    top:0; 
    z-index:10;
}

table tr:nth-child(even) { background-color:#fafafa; }
table tr:hover { background-color:#fff0f5; }

/* --- BOTONES DE ACCIÓN --- */
.action-btn { 
    padding:8px 14px; 
    border-radius:6px; 
    text-decoration:none; 
    font-weight:600; 
    margin:3px; 
    display:inline-block; 
    transition:all 0.3s; 
    font-size:13px; 
    text-transform:uppercase; 
    color:white;
}
.action-btn.edit-btn { background:#E50F53; }
.action-btn.edit-btn:hover { background:#b30c40; }
.action-btn.delete-btn { background:#555; }
.action-btn.delete-btn:hover { background:#333; }

/* --- MODAL --- */
.modal-overlay { 
    display:flex; 
    justify-content:center; 
    align-items:center; 
    overflow-y:auto; 
    position:fixed; 
    top:0; left:0; 
    width:100%; height:100%; 
    background: rgba(0,0,0,0.6); 
    display:none; 
    z-index:1000; 
    opacity:0; 
    transition:opacity 0.3s; 
}

.modal-overlay.show { 
    display:flex; 
    opacity:1; 
}

.form-box { 
    background:white; 
    padding:25px; 
    border-radius:10px; 
    max-width:450px; 
    width:90%; 
    box-shadow:0 10px 30px rgba(0,0,0,0.3); 
    border-top:3px solid #E50F53; 
    margin:auto;
}

.form-box label { display:block; margin-top:15px; font-weight:600; color:#333; }
.form-box input, .form-box textarea { width:100%; padding:12px; margin-top:8px; border-radius:6px; border:1px solid #ccc; box-sizing:border-box; transition:border-color 0.3s, box-shadow 0.3s; }
.form-box input:focus, .form-box textarea:focus { border-color:#E50F53; outline:none; box-shadow:0 0 5px rgba(229,15,83,0.2); }

.form-box button[type="submit"] { width:100%; margin-top:25px; padding:14px; background:#E50F53; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer; transition:background-color 0.3s; text-transform:uppercase; }
.form-box button[type="submit"]:hover { background:#b30c40; }

@media (max-width:900px) { .container{padding:15px;} table{min-width:600px;} }
</style>
</head>
<body>

<?php include "sidebar.php"; ?>

<div class="container">
<h1>Gestión de Servicios</h1>
<button class="top-add-btn" onclick="openModal(null)">Agregar Nuevo Servicio</button>

<div class="table-wrapper">
<table>
<tr>
<th>ID</th><th>Nombre</th><th>Descripción</th><th>Precio</th><th>Acciones</th>
</tr>
<?php foreach($servicios as $s): ?>
<tr>
<td><?= $s['id_servicio'] ?></td>
<td><?= htmlspecialchars($s['nombre']) ?></td>
<td><?= htmlspecialchars($s['descripcion']) ?></td>
<td>$<?= number_format((float)$s['precio'],2) ?></td>
<td>
<button class="action-btn edit-btn" onclick="openModal(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>)">Editar</button>
<a href="servicios.php?accion=eliminar&id=<?= $s['id_servicio'] ?>" class="action-btn delete-btn" onclick="return confirm('¿Eliminar este servicio?')">Eliminar</a>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>
</div>

<div class="modal-overlay" id="servicioModal" onclick="closeModalOnOutsideClick(event)">
<div class="form-box">
<form method="POST" id="servicioForm">
<h2 id="formTitle">Agregar Nuevo Servicio</h2>
<input type="hidden" name="id_servicio" id="id_servicio">
<label>Nombre:</label><input type="text" name="nombre" id="nombre" required>
<label>Descripción:</label><textarea name="descripcion" id="descripcion" required></textarea>
<label>Precio ($):</label><input type="number" step="0.01" name="precio" id="precio" required>
<button type="submit" id="submitButton">Guardar Servicio</button>
</form>
</div>
</div>



<script>
const modal = document.getElementById('servicioModal');
const form = document.getElementById('servicioForm');
const formTitle = document.getElementById('formTitle');
const submitButton = document.getElementById('submitButton');

function openModal(servicio){
    const isEditing = servicio && servicio.id_servicio;
    form.reset();
    document.getElementById('id_servicio').value = '';
    if(isEditing){
        document.getElementById('id_servicio').value = servicio.id_servicio;
        document.getElementById('nombre').value = servicio.nombre;
        document.getElementById('descripcion').value = servicio.descripcion;
        document.getElementById('precio').value = servicio.precio;
        formTitle.textContent = 'Editar Servicio (ID: '+servicio.id_servicio+')';
        submitButton.textContent = 'Guardar Cambios';
    } else {
        formTitle.textContent = 'Agregar Nuevo Servicio';
        submitButton.textContent = 'Guardar Servicio';
    }
    modal.classList.add('show');
}

function closeModal() { modal.classList.remove('show'); }
function closeModalOnOutsideClick(event){ if(event.target===modal) closeModal(); }
</script>

</body>
</html>
