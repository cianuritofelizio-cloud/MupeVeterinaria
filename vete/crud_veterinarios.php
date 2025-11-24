<?php
session_start();
$usuario = $_SESSION['usuario'] ?? 'N/A';

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

// Horarios permitidos (usa estos valores para validar en servidor)
$HORARIOS_PERMITIDOS = [
    '08:00-16:00',
    '09:00-17:00',
    '10:00-18:00',
    '14:00-22:00',
    '08:00-12:00',
    '14:00-18:00'
];

// --- AGREGAR o EDITAR ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $id_vet = $_POST['id_vet'] ?? '';
    $nombre = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $cedula = trim($_POST['cedula'] ?? '');
    $especialidad = trim($_POST['especialidad'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $horario = trim($_POST['horario'] ?? '');

    // --- Validación server-side ---
    $errors = [];

    // Campos obligatorios
    if ($nombre === '') $errors[] = "El nombre es obligatorio.";
    if ($apellidos === '') $errors[] = "Los apellidos son obligatorios.";
    if ($cedula === '') $errors[] = "La cédula es obligatoria.";
    if ($telefono === '') $errors[] = "El teléfono es obligatorio.";
    if ($correo === '') $errors[] = "El correo es obligatorio.";
    if ($horario === '') $errors[] = "Selecciona un horario.";

    // Cédula: solo dígitos, longitud 7-10
    if ($cedula !== '') {
        if (!ctype_digit($cedula)) $errors[] = "La cédula debe contener solo dígitos.";
        $len = strlen($cedula);
        if ($len < 7 || $len > 10) $errors[] = "La cédula debe tener entre 7 y 10 dígitos.";
    }

    // Teléfono: solo dígitos, longitud exactamente 10
    if ($telefono !== '') {
        if (!ctype_digit($telefono)) $errors[] = "El teléfono debe contener solo dígitos.";
        if (strlen($telefono) !== 10) $errors[] = "El teléfono debe tener exactamente 10 dígitos.";
    }

    // Correo: formato válido
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "El correo no tiene un formato válido.";
    }

    // Horario: debe estar en la lista autorizada
    if ($horario !== '' && !in_array($horario, $HORARIOS_PERMITIDOS, true)) {
        $errors[] = "Horario inválido. Selecciona uno de la lista.";
    }

    // Verificar unicidad de cédula y correo (excepto si estamos editando el mismo registro)
    try {
        if ($cedula !== '') {
            $sqlCed = "SELECT COUNT(*) FROM veterinarios WHERE cedula = ?" . ($id_vet ? " AND id != ?" : "");
            $stmt = $conn->prepare($sqlCed);
            if ($id_vet) $stmt->execute([$cedula, $id_vet]); else $stmt->execute([$cedula]);
            if ($stmt->fetchColumn() > 0) $errors[] = "La cédula ya está registrada para otro veterinario.";
        }
        if ($correo !== '') {
            $sqlMail = "SELECT COUNT(*) FROM veterinarios WHERE correo = ?" . ($id_vet ? " AND id != ?" : "");
            $stmt = $conn->prepare($sqlMail);
            if ($id_vet) $stmt->execute([$correo, $id_vet]); else $stmt->execute([$correo]);
            if ($stmt->fetchColumn() > 0) $errors[] = "El correo ya está registrado para otro veterinario.";
        }
    } catch (PDOException $e) {
        $errors[] = "Error al verificar datos únicos: " . $e->getMessage();
    }

    if (!empty($errors)) {
        $msg = urlencode(implode(' | ', $errors));
        header("Location: crud_veterinarios.php?mensaje_error=$msg");
        exit;
    }

    // Si pasó validaciones, insertar o actualizar
    try {
        if ($id_vet != '') {
            $sql = "UPDATE veterinarios SET nombre=?, apellidos=?, cedula=?, especialidad=?, telefono=?, correo=?, horario=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$nombre, $apellidos, $cedula, $especialidad, $telefono, $correo, $horario, $id_vet]);
            header("Location: crud_veterinarios.php?mensaje=actualizado");
            exit;
        } else {
            $sql = "INSERT INTO veterinarios (nombre, apellidos, cedula, especialidad, telefono, correo, horario) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$nombre, $apellidos, $cedula, $especialidad, $telefono, $correo, $horario]);
            header("Location: crud_veterinarios.php?mensaje=agregado");
            exit;
        }
    } catch (Exception $e) {
        $msg = urlencode("Error al guardar: " . $e->getMessage());
        header("Location: crud_veterinarios.php?mensaje_error=$msg");
        exit;
    }
}

// --- ELIMINAR ---
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    try {
        $stmt = $conn->prepare("DELETE FROM veterinarios WHERE id=?");
        $stmt->execute([$id]);
        header("Location: crud_veterinarios.php?mensaje=eliminado");
        exit;
    } catch (Exception $e) {
        $msg = urlencode("Error al eliminar: " . $e->getMessage());
        header("Location: crud_veterinarios.php?mensaje_error=$msg");
        exit;
    }
}

// --- EDITAR (cargar datos para modal) ---
$vet_editar = null;
if (isset($_GET['editar'])) {
    $id = $_GET['editar'];
    $stmt = $conn->prepare("SELECT * FROM veterinarios WHERE id=?");
    $stmt->execute([$id]);
    $vet_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- LISTA DE VETERINARIOS ---
$veterinarios = $conn->query("SELECT * FROM veterinarios ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="global.css">
<title>CRUD Veterinarios</title>
<style>
/* (estilos similares a tu versión previa, con pequeñas clases para errores) */
html, body { height:100%; margin:0; padding:0; background:#f7f7f7; font-family:Arial,sans-serif; overflow-y: auto;}
.container { min-height:100vh; display:flex; flex-direction:column; gap:20px; padding:30px; box-sizing:border-box; max-width:1200px; margin:0 auto; margin-left:300px; }
h1 { color:#E9A0A0; text-align:center; margin:0; font-weight:bold; font-size:2.2em; padding-bottom:8px; border-bottom:2px solid #E50F53; }
.top-add-btn { background:#E9A0A0; color:white; padding:12px 22px; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; transition:all 0.2s; margin:0 auto; display:block; width:fit-content; text-transform:uppercase; }
.table-wrapper { width:100%; overflow-x:auto; padding-top:10px; }
table { width:100%; border-collapse:collapse; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.06); }
th, td { padding: 10px 12px; font-size:13px; vertical-align:middle; border-bottom:1px solid #f0f0f0; }
th { background: #E9A0A0; color: #333; font-weight:700; text-transform:uppercase; position: sticky; top: 0; z-index: 10; text-align:center; font-size:12px; }
td { color:#444; }
.actions { display:flex; gap:6px; justify-content:center; }
.action-btn { padding:8px 12px; border-radius:6px; text-decoration:none; font-weight:600; display:inline-block; transition:all 0.2s; font-size:12px; text-transform:uppercase; color:white; border:none; cursor:pointer; }
.action-edit { background:#E50F53; }
.action-delete { background:#555; }
.msg { margin:10px 0; padding:10px; border-radius:6px; }
.success { background:#e6ffef; color:#0a6b2b; border:1px solid #9fe1be; }
.error { background:#ffe6e6; color:#7a0f0f; border:1px solid #f2a1a1; }

/* Modal */
.modal-overlay { justify-content:center; align-items:center; position:fixed; inset:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:none; z-index:1000; padding:20px; box-sizing:border-box; }
.modal-overlay.show { display:flex; }
.form-box { background: white; padding:25px; border-radius:10px; max-width:520px; width:100%; box-shadow:0 10px 30px rgba(0,0,0,0.3); border-top:3px solid #E50F53; text-align:left; }
.form-box label { display:block; margin-top:12px; font-weight:600; color:#333; }
.form-box input, .form-box select { width:100%; padding:12px; margin-top:8px; border-radius:6px; border:1px solid #ccc; box-sizing:border-box; }
.form-box input:focus, .form-box select:focus { border-color:#E50F53; outline:none; box-shadow:0 0 6px rgba(229,15,83,0.12); }
.form-box button[type="submit"] { width:100%; margin-top:20px; padding:12px; background:#E50F53; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer; text-transform:uppercase; }
.field-error { color:#a00; font-size:13px; margin-top:6px; display:none; }

/* Responsive */
@media (max-width:900px) {
    .container{padding:18px; margin-left:0;}
    th, td { padding:10px 8px; font-size:13px; }
}
</style>
</head>
<body>

<?php include "sidebar.php"; ?>

<div class="container">
    <h1>Gestión de Veterinarios</h1>

    <?php if (isset($_GET['mensaje'])): ?>
        <div class="msg success">
            <?php
                $m = $_GET['mensaje'];
                if ($m === 'agregado') echo "Veterinario agregado correctamente.";
                elseif ($m === 'actualizado') echo "Veterinario actualizado correctamente.";
                elseif ($m === 'eliminado') echo "Veterinario eliminado correctamente.";
                else echo htmlspecialchars($m);
            ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['mensaje_error'])): ?>
        <div class="msg error"><?= htmlspecialchars($_GET['mensaje_error']) ?></div>
    <?php endif; ?>

    <button class="top-add-btn" onclick="openModal(null)">Agregar Nuevo Veterinario</button>

    <div class="table-wrapper">
        <table aria-describedby="lista-veterinarios">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Apellidos</th>
                    <th>Cédula</th>
                    <th>Especialidad</th>
                    <th>Teléfono</th>
                    <th>Correo</th>
                    <th>Horario</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($veterinarios)): ?>
                <tr><td colspan="9" style="padding:20px; text-align:center;">No hay veterinarios registrados.</td></tr>
            <?php else: ?>
                <?php foreach($veterinarios as $v): ?>
                <tr>
                    <td class="id"><?= $v['id'] ?></td>
                    <td class="nombre"><?= htmlspecialchars($v['nombre']) ?></td>
                    <td class="apellidos"><?= htmlspecialchars($v['apellidos']) ?></td>
                    <td class="cedula"><?= htmlspecialchars($v['cedula']) ?></td>
                    <td class="especialidad"><?= htmlspecialchars($v['especialidad']) ?></td>
                    <td class="telefono"><?= htmlspecialchars($v['telefono']) ?></td>
                    <td class="correo"><?= htmlspecialchars($v['correo']) ?></td>
                    <td class="horario"><?= htmlspecialchars($v['horario']) ?></td>
                    <td class="actions">
                        <button class="action-btn action-edit" onclick='openModal(<?= htmlspecialchars(json_encode($v), ENT_QUOTES, "UTF-8") ?>)'>Editar</button>
                        <a class="action-btn action-delete" href="crud_veterinarios.php?eliminar=<?= $v['id'] ?>" onclick="return confirm('¿Eliminar este veterinario?')">Eliminar</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="vetModal" onclick="closeModalOnOutsideClick(event)">
    <div class="form-box" role="dialog" aria-modal="true" aria-labelledby="formTitle">
        <form method="POST" id="vetForm" onsubmit="return validateForm(event)">
            <h2 id="formTitle">Agregar Veterinario</h2>
            <input type="hidden" name="id_vet" id="id_vet">

            <label>Nombre:</label>
            <input type="text" name="nombre" id="nombre" required>

            <label>Apellidos:</label>
            <input type="text" name="apellidos" id="apellidos" required>

            <label>Cédula Profesional:</label>
            <input type="text" name="cedula" id="cedula" maxlength="10" required inputmode="numeric" pattern="\d{7,10}">
            <div class="field-error" id="errCedula">La cédula debe contener solo dígitos (7-10).</div>

            <label>Especialidad:</label>
            <input type="text" name="especialidad" id="especialidad" required>

            <label>Teléfono:</label>
            <input type="text" name="telefono" id="telefono" maxlength="10" required inputmode="numeric" pattern="\d{10}">
            <div class="field-error" id="errTelefono">El teléfono debe tener exactamente 10 dígitos.</div>

            <label>Correo:</label>
            <input type="email" name="correo" id="correo" required>
            <div class="field-error" id="errCorreo">Correo inválido o ya registrado.</div>

            <label>Horario:</label>
            <select name="horario" id="horario" required>
                <option value="">Seleccione...</option>
                <?php foreach ($HORARIOS_PERMITIDOS as $h): ?>
                    <option value="<?= htmlspecialchars($h) ?>"><?= htmlspecialchars($h) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="field-error" id="errHorario">Selecciona un horario válido.</div>

            <button type="submit" id="submitButton">Agregar Veterinario</button>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('vetModal');
const form = document.getElementById('vetForm');
const formTitle = document.getElementById('formTitle');
const submitButton = document.getElementById('submitButton');

function openModal(vet) {
    form.reset();
    document.getElementById('id_vet').value = '';

    // ocultar errores
    document.querySelectorAll('.field-error').forEach(el => el.style.display = 'none');

    if (vet && vet.id) {
        document.getElementById('id_vet').value = vet.id;
        document.getElementById('nombre').value = vet.nombre || '';
        document.getElementById('apellidos').value = vet.apellidos || '';
        document.getElementById('cedula').value = vet.cedula || '';
        document.getElementById('especialidad').value = vet.especialidad || '';
        document.getElementById('telefono').value = vet.telefono || '';
        document.getElementById('correo').value = vet.correo || '';
        document.getElementById('horario').value = vet.horario || '';
        formTitle.textContent = 'Editar Veterinario (ID: ' + vet.id + ')';
        submitButton.textContent = 'Guardar Cambios';
    } else {
        formTitle.textContent = 'Agregar Nuevo Veterinario';
        submitButton.textContent = 'Agregar Veterinario';
    }

    modal.classList.add('show');
    setTimeout(() => document.getElementById('nombre').focus(), 50);
}

function closeModal() { modal.classList.remove('show'); }
function closeModalOnOutsideClick(event) { if(event.target === modal) closeModal(); }

// Input restrictions: sólo dígitos en cedula y telefono
document.getElementById('cedula').addEventListener('input', function(e){
    this.value = this.value.replace(/\D/g,'').slice(0,10);
});
document.getElementById('telefono').addEventListener('input', function(e){
    this.value = this.value.replace(/\D/g,'').slice(0,10);
});

// Validación cliente antes de enviar
function validateForm(e){
    // ocultar errores previos
    document.querySelectorAll('.field-error').forEach(el => el.style.display = 'none');

    const cedula = document.getElementById('cedula').value.trim();
    const telefono = document.getElementById('telefono').value.trim();
    const correo = document.getElementById('correo').value.trim();
    const horario = document.getElementById('horario').value;

    let ok = true;

    if (!/^\d{7,10}$/.test(cedula)) {
        document.getElementById('errCedula').style.display = 'block';
        ok = false;
    }
    if (!/^\d{10}$/.test(telefono)) {
        document.getElementById('errTelefono').style.display = 'block';
        ok = false;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) {
        document.getElementById('errCorreo').textContent = 'Correo inválido.';
        document.getElementById('errCorreo').style.display = 'block';
        ok = false;
    }
    if (horario === '') {
        document.getElementById('errHorario').style.display = 'block';
        ok = false;
    }

    if (!ok) {
        e.preventDefault();
        return false;
    }

    // allow submit; server will do final checks (unique, etc.)
    return true;
}

window.onload = function() {
    const params = new URLSearchParams(window.location.search);
    if(params.has('editar')){
        const vetEditar = <?= json_encode($vet_editar) ?>;
        if(vetEditar) openModal(vetEditar);
    }
};
</script>

</body>
</html>