<?php
session_start();

// --- Conexión a la base de datos ---
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "veterinaria_mupe";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// --- AGREGAR O EDITAR ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_cita = $_POST['id_cita'] ?? '';
    $id_cliente = $_POST['id_cliente'] ?? null;
    $nuevo_cliente = trim($_POST['nuevo_cliente'] ?? '');
    $id_mascota = $_POST['id_mascota'] ?? null;
    $id_veterinario = $_POST['id_veterinario'] ?? null;
    $id_servicio = $_POST['id_servicio'] ?? null;
    $fecha = $_POST['fecha'] ?? null;
    $hora = $_POST['hora'] ?? null;
    $motivo = $_POST['motivo'] ?? '';

    // Si hay cliente nuevo, insertarlo
    if ($nuevo_cliente !== '') {
        $stmt = $conn->prepare("INSERT INTO usuarios (nombre, rol) VALUES (?, 'Cliente')");
        $stmt->execute([$nuevo_cliente]);
        $id_cliente = $conn->lastInsertId();
    }

    try {
        if ($id_cita != '') {
            $sql = "UPDATE citas SET id_cliente = ?, id_mascota = ?, id_veterinario = ?, id_servicio = ?, fecha = ?, hora = ?, motivo = ? WHERE id_cita = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$id_cliente ?: null, $id_mascota ?: null, $id_veterinario ?: null, $id_servicio ?: null, $fecha, $hora, $motivo, $id_cita]);
            header("Location: crud_cita.php?mensaje=actualizado");
            exit;
        } else {
            $sql = "INSERT INTO citas (id_cliente, id_mascota, id_veterinario, id_servicio, fecha, hora, motivo) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$id_cliente ?: null, $id_mascota ?: null, $id_veterinario ?: null, $id_servicio ?: null, $fecha, $hora, $motivo]);
            header("Location: crud_cita.php?mensaje=agregado");
            exit;
        }
    } catch (Exception $e) {
        die("Error al guardar la cita: " . $e->getMessage());
    }
}

// --- ELIMINAR ---
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    $stmt = $conn->prepare("DELETE FROM citas WHERE id_cita = ?");
    $stmt->execute([$id]);
    header("Location: crud_cita.php?mensaje=eliminado");
    exit;
}

// --- EDITAR (obtener datos para el modal) ---
$cita_editar = null;
if (isset($_GET['editar'])) {
    $id = $_GET['editar'];
    $stmt = $conn->prepare("SELECT * FROM citas WHERE id_cita = ?");
    $stmt->execute([$id]);
    $cita_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- LISTAS ---
$clientes = $conn->query("SELECT id_usuario, nombre FROM usuarios WHERE rol='Cliente' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$veterinarios = $conn->query("SELECT id_usuario, nombre FROM usuarios WHERE rol='Veterinario' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$mascotas = $conn->query("SELECT id_mascota, nombre FROM mascotas ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$servicios = $conn->query("SELECT id_servicio, nombre, precio FROM servicios ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// --- LISTA DE CITAS (incluyendo nombre del servicio) ---
$sql = "SELECT 
        c.id_cita,
        c.id_cliente,
        c.id_mascota,
        c.id_veterinario,
        c.id_servicio,
        u.nombre AS cliente,
        m.nombre AS mascota,
        v.nombre AS veterinario,
        s.nombre AS servicio,
        c.fecha,
        c.hora,
        c.motivo,
        c.estado
    FROM citas c
    LEFT JOIN usuarios u ON c.id_cliente = u.id_usuario
    LEFT JOIN mascotas m ON c.id_mascota = m.id_mascota
    LEFT JOIN usuarios v ON c.id_veterinario = v.id_usuario
    LEFT JOIN servicios s ON c.id_servicio = s.id_servicio
    ORDER BY c.id_cita DESC";
$citas = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Citas</title>
<style>
/* (mantén el CSS que ya usabas) */
html, body { height:100%; margin:0; padding:0; font-family:Arial,sans-serif; background:#f7f7f7; overflow-y:auto; }
.container { max-width:1200px; margin-left:300px; padding:30px; display:flex; flex-direction:column; gap:25px; }
h1 { color:#E9A0A0; text-align:center; font-size:2.5em; margin:0; font-weight:bold; border-bottom:2px solid #E50F53; }
.top-add-btn { background:#E9A0A0; color:#fff; padding:12px 30px; border:none; border-radius:4px; font-weight:700; cursor:pointer; text-transform:uppercase; display:block; margin:0 auto; transition:all 0.3s ease; }
.top-add-btn:hover { background:#b30c40; transform:translateY(-2px); }
.table-wrapper { flex:1 1 auto; overflow-y:auto; background:#fff; border-radius:6px; padding-top:1px; box-shadow:0 5px 15px rgba(0,0,0,0.08); }
.table-wrapper::-webkit-scrollbar { display:none; }
table { width:100%; border-collapse:collapse; min-width:800px; }
th { background:#E9A0A0; color:#333; padding:12px 8px; text-transform:uppercase; font-weight:700; position:sticky; top:0; z-index:10; }
td { padding:10px 8px; text-align:center; font-size:14px; color:#555; }
table tr:nth-child(even){ background:#fff; } 
table tr:hover{ background:#fff0f5; }
.action-btn { padding:6px 15px; border-radius:4px; font-weight:600; margin:3px; display:inline-block; font-size:12px; text-transform:uppercase; cursor:pointer; color:#fff; border:none; }
.edit-btn { background:#E50F53; } .edit-btn:hover { background:#b30c40; }
.delete-btn { background:#555; } .delete-btn:hover { background:#333; }
.modal-overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); display:none; justify-content:center; align-items:center; z-index:1000; opacity:0; transition:opacity 0.3s; }
.modal-overlay.show { display:flex; opacity:1; }
.form-box { background:#fff; padding:25px; border-radius:10px; max-width:450px; width:90%; box-shadow:0 10px 30px rgba(0,0,0,0.3); border-top:3px solid #E50F53; transform:translateY(-50px); transition:transform 0.3s; text-align:left; }
.modal-overlay.show .form-box { transform:translateY(0); }
.form-box h2 { text-align:center; color:#E50F53; margin-top:0; margin-bottom:15px; font-size:1.4em; }
.form-box label { display:inline-block; width:30%; margin-top:10px; font-weight:600; color:#333; font-size:13px; text-align:right; margin-right:10px; }
.form-box input, .form-box select, .form-box textarea { display:inline-block; width:65%; padding:10px; margin-top:10px; border-radius:6px; border:1px solid #ccc; font-size:13px; box-sizing:border-box; transition:border-color 0.3s, box-shadow 0.3s; }
.form-box textarea { vertical-align: top; height:60px; }
.form-box input:focus, .form-box select:focus, .form-box textarea:focus { border-color:#E50F53; box-shadow:0 0 5px rgba(229,15,83,0.2); outline:none; }
.form-box button[type="submit"] { width:100%; margin-top:20px; padding:12px; background:#E50F53; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer; text-transform:uppercase; transition:background-color 0.3s; }
.form-box button[type="submit"]:hover { background:#b30c40; }
.form-row { display:flex; align-items:center; margin-bottom:10px; }
</style>
</head>
<body>

<?php include "sidebarRecep.php"; ?>

<div class="container">
<h1>Gestión de Citas</h1>
<button class="top-add-btn" onclick="openModal(null)">Agregar Nueva Cita</button>
<div class="table-wrapper">
<table>
<tr>
<th>ID</th><th>Cliente</th><th>Mascota</th><th>Veterinario</th><th>Fecha</th><th>Hora</th><th>Servicio</th><th>Motivo</th><th>Estado</th><th>Acciones</th>
</tr>
<?php foreach ($citas as $c): ?>
<tr>
<td><?= $c['id_cita'] ?></td>
<td><?= htmlspecialchars($c['cliente'] ?? '') ?></td>
<td><?= htmlspecialchars($c['mascota'] ?? '') ?></td>
<td><?= htmlspecialchars($c['veterinario'] ?? '') ?></td>
<td><?= htmlspecialchars($c['fecha']) ?></td>
<td><?= htmlspecialchars($c['hora']) ?></td>
<td><?= htmlspecialchars($c['servicio'] ?? '') ?></td>
<td><?= htmlspecialchars($c['motivo'] ?? '') ?></td>
<td><?= htmlspecialchars($c['estado'] ?? '') ?></td>
<td>
<button class="action-btn edit-btn" onclick='openModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)'>Editar</button>
<a href="crud_cita.php?eliminar=<?= $c['id_cita'] ?>" class="action-btn delete-btn" onclick="return confirm('¿Eliminar esta cita?')">Eliminar</a>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="citaModal">
<div class="form-box">
<form method="POST" id="citaForm">
<h2 id="modalTitle"></h2>
<input type="hidden" name="id_cita" id="id_cita">

<label>Cliente:</label>
<select name="id_cliente" id="id_cliente">
    <option value="">Seleccione...</option>
    <?php foreach ($clientes as $cl): ?>
    <option value="<?= $cl['id_usuario'] ?>"><?= htmlspecialchars($cl['nombre']) ?></option>
    <?php endforeach; ?>
</select>

<label>Agregar Cliente Nuevo (opcional):</label>
<input type="text" name="nuevo_cliente" id="nuevo_cliente" placeholder="Nombre del nuevo cliente">

<label>Mascota:</label>
<select name="id_mascota" id="id_mascota">
    <option value="">Seleccione...</option>
    <?php foreach ($mascotas as $m): ?>
    <option value="<?= $m['id_mascota'] ?>"><?= htmlspecialchars($m['nombre']) ?></option>
    <?php endforeach; ?>
</select>

<label>Veterinario:</label>
<select name="id_veterinario" id="id_veterinario">
    <option value="">Seleccione...</option>
    <?php foreach ($veterinarios as $v): ?>
    <option value="<?= $v['id_usuario'] ?>"><?= htmlspecialchars($v['nombre']) ?></option>
    <?php endforeach; ?>
</select>

<label>Servicio:</label>
<select name="id_servicio" id="id_servicio">
    <option value="">Seleccione...</option>
    <?php foreach ($servicios as $s): ?>
    <option value="<?= $s['id_servicio'] ?>"><?= htmlspecialchars($s['nombre']) ?> (<?= number_format($s['precio'],2) ?>)</option>
    <?php endforeach; ?>
</select>

<label>Fecha:</label>
<input type="date" name="fecha" id="fecha" required>

<label>Hora:</label>
<input type="time" name="hora" id="hora" required>

<label>Motivo:</label>
<textarea name="motivo" id="motivo" rows="2"></textarea>

<button type="submit" id="btnSubmit"></button>
</form>
</div>
</div>

<script>
const modal = document.getElementById("citaModal");
const form = document.getElementById("citaForm");
const modalTitle = document.getElementById("modalTitle");
const btnSubmit = document.getElementById("btnSubmit");

function openModal(cita){
    form.reset();
    modal.classList.add("show");

    if(cita){
        modalTitle.innerText = "Editar Cita";
        btnSubmit.innerText = "Guardar Cambios";
        document.getElementById("id_cita").value = cita.id_cita || '';
        document.getElementById("id_cliente").value = cita.id_cliente || '';
        document.getElementById("id_mascota").value = cita.id_mascota || '';
        document.getElementById("id_veterinario").value = cita.id_veterinario || '';
        document.getElementById("id_servicio").value = cita.id_servicio || '';
        document.getElementById("fecha").value = cita.fecha || '';
        document.getElementById("hora").value = cita.hora || '';
        document.getElementById("motivo").value = cita.motivo || '';
    } else {
        modalTitle.innerText = "Agregar Nueva Cita";
        btnSubmit.innerText = "Agregar Cita";
        document.getElementById("id_cita").value = "";
    }
}

// Priorizar nuevo cliente si se escribe algo
form.addEventListener('submit', function(e){
    const nuevoCliente = document.getElementById('nuevo_cliente').value.trim();
    if(nuevoCliente !== ''){
        document.getElementById('id_cliente').value = '';
    }
});

// cerrar modal haciendo click afuera
modal.addEventListener("click", e => { if(e.target === modal){ modal.classList.remove("show"); }});
</script>


</body>
</html>