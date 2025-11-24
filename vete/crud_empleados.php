<?php
session_start();
$usuario = $_SESSION['usuario'] ?? 'N/A';

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
    $id = $_POST['id_empleado'] ?? '';
    $nombre = $_POST['nombre'];
    $puesto = $_POST['puesto'];
    $telefono = $_POST['telefono'];
    $correo = $_POST['correo'];
    $horario = $_POST['horario'];

    if ($id != '') {
        // actualizar
        $stmt = $conn->prepare("UPDATE empleados SET nombre=?, puesto=?, telefono=?, correo=?, horario=? WHERE id_empleado=?");
        $stmt->execute([$nombre, $puesto, $telefono, $correo, $horario, $id]);

        header("Location: crud_empleados.php?mensaje=actualizado");
        exit;
    } else {
        // agregar
        $stmt = $conn->prepare("INSERT INTO empleados (nombre, puesto, telefono, correo, horario) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$nombre, $puesto, $telefono, $correo, $horario]);

        header("Location: crud_empleados.php?mensaje=agregado");
        exit;
    }
}

// --- ELIMINAR ---
if (isset($_GET['eliminar'])) {
    $stmt = $conn->prepare("DELETE FROM empleados WHERE id_empleado=?");
    $stmt->execute([$_GET['eliminar']]);

    header("Location: crud_empleados.php?mensaje=eliminado");
    exit;
}


// --- EDITAR (cargar datos para modal) ---
$empleado_editar = null;
if (isset($_GET['editar'])) {
    $stmt = $conn->prepare("SELECT * FROM empleados WHERE id_empleado=?");
    $stmt->execute([$_GET['editar']]);
    $empleado_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- LISTA DE EMPLEADOS ---
$empleados = $conn->query("SELECT * FROM empleados ORDER BY id_empleado DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="global.css">
<title>CRUD Empleados</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
/* Reset y base */
html, body {
    height:100%;
    margin:0;
    padding:0;
    background:#f7f7f7;
    font-family:Arial,sans-serif;
    -webkit-font-smoothing:antialiased;
    -moz-osx-font-smoothing:grayscale;
}

/* Aseguramos que, si hay un sidebar fijo, el contenido no quede oculto.
   Ajusta .sidebar width si tu sidebar usa otro ancho. */
.sidebar {
    /* Si sidebar.php ya define estilos, estos sólo aplicarán si no se sobrescriben. */
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    width: 260px; /* ancho consistente para el contenido */
    overflow: auto;
    z-index: 50;
}

/* Contenedor principal: se adapta al ancho del sidebar */
.container {
    min-height: 100vh;
    display:flex;
    flex-direction:column;
    gap:30px;
    padding:30px;
    box-sizing:border-box;
    max-width:1200px;
    margin:0 auto;
    /* dejar espacio a la izquierda igual al ancho del sidebar */
    margin-left: 260px;
    transition: margin-left 0.2s ease;
}

/* Si no se desea que el sidebar sea fijo en pantallas pequeñas, lo cambiamos */
@media (max-width: 900px) {
    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
    }
    .container {
        margin-left: 0;
        padding: 16px;
    }
}

h1 {
    color:#E9A0A0;
    text-align:center;
    margin:0;
    font-weight:bold;
    font-size:2.2em;
    padding-bottom:5px;
    border-bottom:2px solid #E50F53;
}

/* Botón superior */
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

/* Table wrapper: controla scroll horizontal sin cortar contenido */
.table-wrapper {
    width: 100%;
    overflow-x: auto;
    box-sizing: border-box;
    padding: 0;
    margin: 0;
    background: transparent;
}

/* Estilos de la tabla adaptativos:
   - Usamos table-layout:fixed para que las columnas se distribuyan,
     permitiendo que el contenido se ajuste y no "rompa" el layout.
   - Eliminamos márgenes negativos o anchuras excesivas */
table {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    background: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow:0 5px 15px rgba(0,0,0,0.08);
    min-width: 700px; /* evita colapso extremo en pantallas muy pequeñas */
}

/* Encabezados */
th, td {
    padding: 10px 12px;
    text-align: left;
    font-size:14px;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

/* Si necesitas una celda con truncado, añade la clase .truncate */
.truncate {
    white-space: nowrap;
    text-overflow: ellipsis;
    overflow: hidden;
}

/* Encabezado con color */
th {
    background: #E9A0A0;
    color:#333;
    font-weight:700;
    text-transform:uppercase;
    position: sticky;
    top: 0;
    z-index: 5;
}

/* Filas */
table tr:nth-child(even) { background-color:#fafafa; }
table tr:hover { background-color:#fff0f5; }

/* Acciones (botones) */
.action-btn {
    padding:8px 14px;
    border-radius:6px;
    text-decoration:none;
    font-weight:600;
    margin:3px;
    display:inline-block;
    transition:all 0.2s;
    font-size:13px;
    text-transform:uppercase;
    background:#E50F53;
    color:white;
}
.action-btn:hover { background:#b30c40; }
.action-delete { background:#555; }
.action-delete:hover { background:#333; }

/* Modal */
.modal-overlay {
    display:flex;
    justify-content:center;
    align-items:center;
    overflow-y:auto;
    position:fixed;
    top:0; left:0;
    width:100%; height:100%;
    background: rgba(0,0,0,0.6);
    visibility: hidden;
    opacity: 0;
    z-index: 1200;
    transition: opacity 0.25s ease, visibility 0.25s;
}
.modal-overlay.show {
    visibility: visible;
    opacity: 1;
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
    box-sizing: border-box;
}
.form-box label { display:block; margin-top:15px; font-weight:600; color:#333; }
.form-box input, .form-box select { width:100%; padding:12px; margin-top:8px; border-radius:6px; border:1px solid #ccc; box-sizing:border-box; transition:border-color 0.3s, box-shadow 0.3s; }
.form-box input:focus, .form-box select:focus { border-color:#E50F53; outline:none; box-shadow:0 0 5px rgba(229,15,83,0.18); }
.form-box button[type="submit"] { width:100%; margin-top:25px; padding:14px; background:#E50F53; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer; transition:background-color 0.3s; text-transform:uppercase; }
.form-box button[type="submit"]:hover { background:#b30c40; }

/* Ajustes responsivos: columna de acciones más compacta */
@media (max-width: 1100px) {
    th, td { font-size:13px; padding:8px 10px; }
    .top-add-btn { padding:10px 18px; font-size:14px; }
}
@media (max-width: 700px) {
    table { min-width: 600px; }
    th, td { font-size:13px; padding:8px; }
}

/* Pequeños estilos para mejorar legibilidad de celdas largas */
td small { color: #666; font-size: 12px; display:block; margin-top:4px; }
</style>
</head>
<body>
<?php include "sidebar.php"; ?>

<div class="container">
    <h1>Gestión de Empleados</h1>
    <button class="top-add-btn" onclick="openModal(null)">Agregar Nuevo Empleado</button>

    <div class="table-wrapper" role="region" aria-label="Lista de empleados">
        <table>
            <thead>
                <tr>
                    <th style="width:70px;">ID</th>
                    <th>Nombre</th>
                    <th style="width:160px;">Puesto</th>
                    <th style="width:140px;">Teléfono</th>
                    <th style="width:220px;">Correo</th>
                    <th style="width:120px;">Horario</th>
                    <th style="width:160px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if(!empty($empleados)): ?>
                <?php foreach($empleados as $e): ?>
                <tr>
                    <td class="truncate"><?= $e['id_empleado'] ?></td>
                    <td><?= htmlspecialchars($e['nombre']) ?></td>
                    <td class="truncate"><?= htmlspecialchars($e['puesto']) ?></td>
                    <td class="truncate"><?= htmlspecialchars($e['telefono']) ?></td>
                    <td class="truncate" title="<?= htmlspecialchars($e['correo']) ?>"><?= htmlspecialchars($e['correo']) ?></td>
                    <td class="truncate"><?= htmlspecialchars($e['horario']) ?></td>
                    <td>
                        <a href="#" onclick="openModal(<?= htmlspecialchars(json_encode($e), ENT_QUOTES, 'UTF-8') ?>); return false;" class="action-btn" aria-label="Editar empleado <?= htmlspecialchars($e['nombre']) ?>">Editar</a>
                        <a href="crud_empleados.php?eliminar=<?= $e['id_empleado'] ?>" class="action-btn action-delete" onclick="return confirm('¿Eliminar empleado?')" aria-label="Eliminar empleado <?= htmlspecialchars($e['nombre']) ?>">Eliminar</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center; padding:18px;">No hay empleados registrados.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="empleadoModal" onclick="closeModalOnOutsideClick(event)" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="form-box" role="document">
        <form method="POST" id="empleadoForm">
            <h2 id="formTitle">Agregar Empleado</h2>
            <input type="hidden" name="id_empleado" id="id_empleado">
            <label>Nombre:</label><input type="text" name="nombre" id="nombre" required>
            <label>Puesto:</label><input type="text" name="puesto" id="puesto" required>
            <label>Teléfono:</label><input type="text" name="telefono" id="telefono" maxlength="15" required>
            <label>Correo:</label><input type="email" name="correo" id="correo" required>
            <label>Horario:</label><input type="text" name="horario" id="horario" placeholder="4:30–7 p.m." required>
            <button type="submit" id="submitButton">Agregar Empleado</button>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('empleadoModal');
const form = document.getElementById('empleadoForm');
const formTitle = document.getElementById('formTitle');
const submitButton = document.getElementById('submitButton');

function openModal(empleado) {
    const isEditing = empleado && empleado.id_empleado;
    form.reset();
    document.getElementById('id_empleado').value = '';
    if(isEditing){
        document.getElementById('id_empleado').value = empleado.id_empleado;
        document.getElementById('nombre').value = empleado.nombre;
        document.getElementById('puesto').value = empleado.puesto;
        document.getElementById('telefono').value = empleado.telefono;
        document.getElementById('correo').value = empleado.correo;
        document.getElementById('horario').value = empleado.horario;
        formTitle.textContent = 'Editar Empleado (ID: '+empleado.id_empleado+')';
        submitButton.textContent = 'Guardar Cambios';
    } else {
        formTitle.textContent = 'Agregar Nuevo Empleado';
        submitButton.textContent = 'Agregar Empleado';
    }
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
}

function closeModal() {
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
}
function closeModalOnOutsideClick(event){
    if(event.target===modal) closeModal();
}

window.onload = function(){
    const params = new URLSearchParams(window.location.search);
    if(params.has('editar')){
        const empleadoEditar = <?= json_encode($empleado_editar) ?>;
        if(empleadoEditar) openModal(empleadoEditar);
    }
};

// Validación simple de teléfono al enviar
form.addEventListener('submit', function(e){
    const telefono = document.getElementById('telefono').value.replace(/\D/g,'');
    if(telefono.length < 7){
        alert("El teléfono debe tener al menos 7 dígitos.");
        e.preventDefault();
        return;
    }
});
</script>
</body>
</html>