<?php
session_start();

// Validación de sesión
if (!isset($_SESSION['rol']) || $_SESSION['rol'] != 'Veterinario') {
    header("Location: ../../vet_panel.php");
    exit;
}

$usuario = $_SESSION['usuario'];
$id_veterinario_actual = $_SESSION['id_usuario'] ?? 3;

// --- Conexión a la BD ---
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "veterinaria_mupe";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// --- Obtener Mascotas ---
$mascotas = [];
try {
    $stmt = $conn->query("SELECT id_mascota, nombre FROM mascotas ORDER BY nombre ASC");
    $mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// --- CRUD Historial ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_historial = $_POST['id_historial'] ?? '';
    $id_mascota = $_POST['id_mascota'] ?? '';
    $fecha = $_POST['fecha'] ?? '';
    $diagnostico_real = $_POST['motivo_consulta'] ?? '';
    $tratamiento_real = $_POST['diagnostico_final'] ?? '';

    if (empty($id_mascota) || empty($fecha) || empty($diagnostico_real) || empty($tratamiento_real)) {
        echo "<script>alert('Todos los campos son obligatorios');</script>";
    } else {
        if ($id_historial != '') {
            $stmt = $conn->prepare("
                UPDATE historial_medico SET 
                    id_mascota=?, id_veterinario=?, fecha=?, diagnostico=?, tratamiento=? 
                WHERE id_historial=?
            ");
            $stmt->execute([$id_mascota, $id_veterinario_actual, $fecha, $diagnostico_real, $tratamiento_real, $id_historial]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO historial_medico 
                (id_mascota, id_veterinario, fecha, diagnostico, tratamiento) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$id_mascota, $id_veterinario_actual, $fecha, $diagnostico_real, $tratamiento_real]);
        }
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }
}

// --- Eliminar Historial ---
if (isset($_GET['eliminar'])) {
    $id_eliminar = $_GET['eliminar'];
    $stmt = $conn->prepare("DELETE FROM historial_medico WHERE id_historial=?");
    $stmt->execute([$id_eliminar]);
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// --- Recuperar Historial ---
$sql_historial = "
    SELECT 
        h.id_historial, 
        h.id_mascota, 
        m.nombre AS nombre_mascota, 
        u.nombre AS nombre_veterinario, 
        DATE_FORMAT(h.fecha, '%d/%m/%Y') AS fecha_formateada,
        h.fecha AS fecha_iso,
        h.diagnostico AS motivo_consulta,
        h.tratamiento AS diagnostico_final
    FROM historial_medico h
    JOIN mascotas m ON h.id_mascota = m.id_mascota
    LEFT JOIN usuarios u ON h.id_veterinario = u.id_usuario 
    ORDER BY h.fecha DESC, h.id_historial DESC
";
$historial_medico = $conn->query($sql_historial)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Historial Médico</title>
<style>
html, body { background:#f7f7f7; font-family:Arial,sans-serif; margin:0; padding:0; }
.container { margin-left:300px; padding:30px; }
h1 { color:#E9A0A0; text-align:center; font-size:2.2em; border-bottom:2px solid #E50F53; padding-bottom:5px; }
.top-add-btn { background:#E9A0A0; color:white; padding:14px 28px; border:none; border-radius:8px; font-size:16px; font-weight:700; cursor:pointer; transition:all 0.3s; text-transform:uppercase; margin:20px auto; display:block; }
.top-add-btn:hover { background:#b30c40; box-shadow:0 6px 15px rgba(0,0,0,0.15); transform:translateY(-2px); }
table { width:100%; border-collapse:collapse; background:white; border-radius:10px; box-shadow:0 5px 15px rgba(0,0,0,0.08); }
th, td { padding:10px; text-align:center; font-size:13px; }
th { background:#E9A0A0; color:#333; text-transform:uppercase; }
tr:nth-child(even){ background:#fafafa; } tr:hover{ background:#fff0f5; }
.action-btn { padding:8px 14px; border-radius:6px; text-decoration:none; font-weight:600; margin:3px; display:inline-block; font-size:13px; text-transform:uppercase; background:#E50F53; color:white; transition:all 0.3s; }
.action-btn:hover { background:#b30c40; }
.action-delete { background:#555; } .action-delete:hover { background:#333; }
.modal-overlay { display:none; justify-content:center; align-items:center; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; }
.modal-overlay.show { display:flex; }
.form-box { background:white; padding:25px; border-radius:10px; max-width:500px; width:90%; box-shadow:0 10px 30px rgba(0,0,0,0.3); border-top:3px solid #E50F53; }
.form-box label { display:block; margin-top:15px; font-weight:600; color:#333; }
.form-box textarea, .form-box input, .form-box select { width:100%; padding:12px; margin-top:8px; border-radius:6px; border:1px solid #ccc; }
.form-box button[type="submit"] { width:100%; margin-top:25px; padding:14px; background:#E50F53; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer; text-transform:uppercase; }
.form-box button[type="submit"]:hover { background:#b30c40; }
.close { float:right; font-size:24px; cursor:pointer; color:#888; }
.close:hover { color:#333; }
.med-row { display:flex; gap:8px; margin-bottom:8px; align-items:center; }
.add-med-btn { background:#0ea5b0; color:#fff; padding:8px 12px; border-radius:6px; border:none; cursor:pointer; }
.remove-med-btn { background:#aaa; color:#fff; padding:6px 10px; border-radius:6px; border:none; cursor:pointer; }
.small { font-size:0.9rem; color:#666; }
.badge { background:#E50F53; color:#fff; padding:6px 10px; border-radius:999px; font-weight:700; min-width:36px; text-align:center; box-shadow:0 6px 18px rgba(229,15,83,0.12); font-size:0.95rem; }

/* Styled Cancel button */
.btn-cancel {
    display: inline-block;
    width: 100%;
    margin-top: 12px;
    padding: 12px 14px;
    background: #f3f4f6;
    color: #1f2937;
    border-radius: 8px;
    border: 1px solid #d1d5db;
    font-weight: 700;
    cursor: pointer;
    text-transform: uppercase;
    transition: background 0.18s ease, transform 0.12s ease, box-shadow 0.12s ease;
}
.btn-cancel:hover {
    background: #e5e7eb;
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.06);
}
.btn-cancel:focus {
    outline: 3px solid rgba(229,15,83,0.12);
    outline-offset: 3px;
}
</style>
</head>
<body>

<?php include "sidebarVet.php"; ?>

<div class="container">
    <h1>Gestión de Historial Médico</h1>

    <button class="top-add-btn" id="btnAddHistorial">Agregar Nueva Consulta</button>

    <table>
        <tr>
            <th>ID</th>
            <th>Mascota</th>
            <th>Veterinario</th>
            <th>Fecha</th>
            <th>Motivo Consulta / Diagnóstico</th>
            <th>Tratamiento / Notas Finales</th>
            <th>Acciones</th>
        </tr>
        <?php foreach ($historial_medico as $h): ?>
        <tr>
            <td><?= $h['id_historial'] ?></td>
            <td><?= htmlspecialchars($h['nombre_mascota']) ?> (ID: <?= $h['id_mascota'] ?>)</td>
            <td><?= htmlspecialchars($h['nombre_veterinario'] ?? 'N/A') ?></td>
            <td><?= $h['fecha_formateada'] ?></td>
            <td><span title="<?= htmlspecialchars($h['motivo_consulta']) ?>"><?= htmlspecialchars(substr($h['motivo_consulta'],0,40)) . (strlen($h['motivo_consulta'])>40?'...':'') ?></span></td>
            <td><span title="<?= htmlspecialchars($h['diagnostico_final']) ?>"><?= htmlspecialchars(substr($h['diagnostico_final'],0,40)) . (strlen($h['diagnostico_final'])>40?'...':'') ?></span></td>
            <td>
                <?php $datos_json = json_encode($h, JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                <a class="action-btn" href="#" onclick='openModalHistorial(<?= $datos_json ?>); return false;'>Editar</a>
                <a class="action-btn action-delete" href="?eliminar=<?= $h['id_historial'] ?>" onclick="return confirm('¿Confirma la ELIMINACIÓN del Historial ID: <?= $h['id_historial'] ?>?')">Eliminar</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="formHistorialModal" onclick="closeModalHistorialOnOutsideClick(event)">
    <div class="form-box">
        <form method="POST" action="" id="historialForm">
            <h2 style="color:#E50F53; text-align:center; margin-top:0;" id="formTitleHistorial">Agregar Historial</h2>
            <input type="hidden" name="id_historial" id="id_historial">

            <label>Mascota:</label>
            <select name="id_mascota" id="id_mascota_historial" required>
                <option value="">-- Seleccione una Mascota --</option>
                <?php foreach ($mascotas as $m): ?>
                    <option value="<?= $m['id_mascota'] ?>"><?= htmlspecialchars($m['nombre']) ?> (ID: <?= $m['id_mascota'] ?>)</option>
                <?php endforeach; ?>
            </select>

            <label>Fecha de Consulta:</label>
            <input type="date" name="fecha" id="fecha_historial" required>

            <label>Motivo de la Consulta / Diagnóstico:</label>
            <textarea name="motivo_consulta" id="motivo_consulta" required></textarea>

            <label>Tratamiento / Notas Finales:</label>
            <textarea name="diagnostico_final" id="diagnostico_final" required></textarea>

            <button type="submit" id="submitButtonHistorial">Agregar al Historial</button>
        </form>
        <!-- Styled Cancel button -->
        <button type="button" class="btn-cancel" onclick="closeModalHistorial()">Cancelar</button>
    </div>
</div>

<script>
(function() {
    const modal = document.getElementById('formHistorialModal');
    const form = document.getElementById('historialForm');
    const formTitle = document.getElementById('formTitleHistorial');
    const submitButton = document.getElementById('submitButtonHistorial');
    const btnAdd = document.getElementById('btnAddHistorial');

    window.openModalHistorial = function(historial) {
        form.reset();
        document.getElementById('id_historial').value = '';

        if (historial && historial.id_historial) {
            document.getElementById('id_historial').value = historial.id_historial;
            document.getElementById('id_mascota_historial').value = historial.id_mascota;
            document.getElementById('fecha_historial').value = historial.fecha_iso;
            document.getElementById('motivo_consulta').value = historial.motivo_consulta;
            document.getElementById('diagnostico_final').value = historial.diagnostico_final;
            formTitle.textContent = 'Editar Historial (ID: ' + historial.id_historial + ')';
            submitButton.textContent = 'Guardar Cambios';
        } else {
            formTitle.textContent = 'Agregar Nueva Consulta';
            submitButton.textContent = 'Agregar al Historial';
            document.getElementById('fecha_historial').value = new Date().toISOString().substring(0,10);
        }
        modal.classList.add('show');
        // focus primer campo
        setTimeout(()=>document.getElementById('id_mascota_historial').focus(),100);
    };

    window.closeModalHistorial = function() {
        modal.classList.remove('show');
    };

    window.closeModalHistorialOnOutsideClick = function(event) {
        if (event.target === modal) closeModalHistorial();
    };

    btnAdd.addEventListener('click', function(){ openModalHistorial(); });
})();
</script>
</body>
</html>