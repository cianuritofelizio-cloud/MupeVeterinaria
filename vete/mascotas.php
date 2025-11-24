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

// --- Obtener usuarios para el select (solo Clientes) ---
$usuarios = $conn->prepare("SELECT id_usuario, nombre FROM usuarios WHERE rol = 'Cliente' ORDER BY nombre");
$usuarios->execute();
$usuarios = $usuarios->fetchAll(PDO::FETCH_ASSOC);

// --- Procesar agregar/editar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id_mascota'] ?? '';
    $nombre = trim($_POST['nombre'] ?? '');
    $especie = $_POST['especie'] ?? '';
    $raza = $_POST['raza'] ?? '';
    // edad final a guardar (int)
    $edad = null;

    // Si envían fecha de nacimiento, calcular edad
    if (!empty($_POST['fecha_nacimiento'])) {
        $dob = $_POST['fecha_nacimiento']; // YYYY-MM-DD
        $dob_dt = DateTime::createFromFormat('Y-m-d', $dob);
        if ($dob_dt) {
            $today = new DateTime();
            $diff = $today->diff($dob_dt);
            $edad = (int)$diff->y;
        } else {
            $edad = isset($_POST['edad']) ? intval($_POST['edad']) : null;
        }
    } else {
        // compatibilidad: aceptar edad directa (hidden)
        $edad = isset($_POST['edad']) ? intval($_POST['edad']) : null;
    }

    $id_cliente = !empty($_POST['id_cliente']) ? intval($_POST['id_cliente']) : null;

    if ($id) {
        $stmt = $conn->prepare("UPDATE mascotas SET nombre=?, especie=?, raza=?, edad=?, id_cliente=? WHERE id_mascota=?");
        $stmt->execute([$nombre, $especie, $raza, $edad, $id_cliente, $id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO mascotas (nombre, especie, raza, edad, id_cliente) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$nombre, $especie, $raza, $edad, $id_cliente]);
    }
    header("Location: mascotas.php");
    exit;
}

// --- Eliminar ---
if (isset($_GET['delete'])) {
    $stmt = $conn->prepare("DELETE FROM mascotas WHERE id_mascota=?");
    $stmt->execute([$_GET['delete']]);
    header("Location: mascotas.php");
    exit;
}

// --- Obtener todas las mascotas ---
$mascotas = $conn->query("
    SELECT m.*, u.nombre AS dueño 
    FROM mascotas m 
    LEFT JOIN usuarios u ON m.id_cliente = u.id_usuario
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Mascotas</title>
<style>
/* --- Estilos generales --- */
html, body { background: #f7f7f7; font-family: Arial,sans-serif; margin: 0; padding: 0; }
.container { margin-left: 300px; padding: 30px; }
h1 { color:#E9A0A0; text-align:center; font-size:2.2em; border-bottom:2px solid #E50F53; padding-bottom:5px; }

/* --- Botón superior agregar --- */
.top-add-btn { background:#E9A0A0; color:white; padding:14px 28px; border:none; border-radius:8px; font-size:16px; font-weight:700; cursor:pointer; transition:all 0.3s; text-transform:uppercase; margin:20px auto; display:block; }
.top-add-btn:hover { background:#b30c40; box-shadow:0 6px 15px rgba(0,0,0,0.15); transform:translateY(-2px); }

/* --- Tabla de mascotas --- */
table { width:100%; border-collapse:collapse; background:white; border-radius:10px; box-shadow:0 5px 15px rgba(0,0,0,0.08); margin-top:20px; }
th, td { padding:10px; text-align:center; font-size:13px; }
th { background:#E9A0A0; color:#333; text-transform:uppercase; }
tr:nth-child(even){ background:#fafafa; } 
tr:hover{ background:#fff0f5; }

/* --- Botones de acción --- */
.action-btn { padding:8px 14px; border-radius:6px; text-decoration:none; font-weight:600; margin:3px; display:inline-block; font-size:13px; text-transform:uppercase; background:#E50F53; color:white; transition:all 0.3s; }
.action-btn:hover { background:#b30c40; }
.action-delete { background:#555; }
.action-delete:hover { background:#333; }

/* --- Modal --- */
.modal-overlay { display:none; justify-content:center; align-items:center; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; }
.modal-overlay.show { display:flex; }

/* --- Formulario dentro del modal --- */
.form-box { background:white; padding:25px; border-radius:10px; max-width:500px; width:90%; box-shadow:0 10px 30px rgba(0,0,0,0.3); border-top:3px solid #E50F53; }
.form-box label { display:block; margin-top:15px; font-weight:600; color:#333; }
.form-box input, .form-box select, .form-box textarea { width:100%; padding:12px; margin-top:8px; border-radius:6px; border:1px solid #ccc; }
.form-box button[type="submit"] { width:100%; margin-top:25px; padding:14px; background:#E50F53; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer; text-transform:uppercase; }
.form-box button[type="submit"]:hover { background:#b30c40; }

/* --- Botón cerrar modal --- */
.close { float:right; font-size:24px; cursor:pointer; color:#888; }
.close:hover { color:#333; }

.small-note { font-size:0.9rem; color:#666; margin-top:6px; }

</style>
</head>
<body>

<?php include "sidebarRecep.php";?>

<div class="container">
    <h1>Gestión de Mascotas</h1>
    <button class="top-add-btn" id="btnAdd">Agregar Mascota</button>

    <table>
        <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Especie</th>
            <th>Raza</th>
            <th>Edad (años)</th>
            <th>Dueño</th>
            <th>Acciones</th>
        </tr>
        <?php foreach ($mascotas as $m): ?>
        <tr>
            <td><?= htmlspecialchars($m['id_mascota']) ?></td>
            <td><?= htmlspecialchars($m['nombre']) ?></td>
            <td><?= htmlspecialchars($m['especie']) ?></td>
            <td><?= htmlspecialchars($m['raza']) ?></td>
            <td><?= htmlspecialchars($m['edad'] ?? '—') ?></td>
            <td><?= htmlspecialchars($m['dueño'] ?? 'Sin dueño') ?></td>
            <td>
                <a class="action-btn" href="#" onclick='openModal(<?= json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT) ?>); return false;'>Editar</a>
                <a class="action-btn action-delete" href="?delete=<?= $m['id_mascota'] ?>" onclick="return confirm('¿Eliminar mascota?')">Eliminar</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<!-- MODAL AGREGAR/EDITAR -->
<div class="modal-overlay" id="modalForm">
    <div class="form-box">
        <span class="close" onclick="closeModal()">&times;</span>
        <h3 id="modalTitle">Agregar Mascota</h3>
        <form method="POST" id="formMascota">
            <input type="hidden" name="id_mascota" id="id_mascota">
            <!-- Hidden edad (se calcula a partir de fecha_nacimiento) -->
            <input type="hidden" name="edad" id="edadHidden">

            <label>Nombre:</label>
            <input type="text" name="nombre" id="nombre" required>

            <label>Especie:</label>
            <select name="especie" id="especie" required onchange="actualizarRazas()">
                <option value="">Seleccione especie</option>
                <option value="Perro">Perro</option>
                <option value="Gato">Gato</option>
                <option value="Conejo">Conejo</option>
                <option value="Ave">Ave</option>
                <option value="Hámster">Hámster</option>
                <option value="Tortuga">Tortuga</option>
                <option value="Pez">Pez</option>
                <option value="Reptil">Reptil</option>
            </select>

            <label>Raza:</label>
            <select name="raza" id="raza">
                <option value="">Seleccione especie primero</option>
            </select>

            <label>Fecha de nacimiento:</label>
            <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" onchange="calcularEdadDesdeFecha()">
            

            <label>Dueño:</label>
            <select name="id_cliente" id="id_cliente" required>
                <option value="">Seleccione dueño</option>
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?= htmlspecialchars($u['id_usuario']) ?>"><?= htmlspecialchars($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" id="submitBtn">Guardar</button>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('modalForm');
const form = document.getElementById('formMascota');
const modalTitle = document.getElementById('modalTitle');
const submitBtn = document.getElementById('submitBtn');
const btnAdd = document.getElementById('btnAdd');

const razasPorEspecie = {
    "Perro": ["Labrador", "Golden Retriever", "Bulldog", "Poodle", "Chihuahua", "Pastor Alemán", "Beagle", "Dálmata", "Shih Tzu", "Husky Siberiano", "Boxer", "Cocker Spaniel"],
    "Gato": ["Siamés", "Persa", "Maine Coon", "Bengala", "Sphynx", "Ragdoll", "Abisinio", "British Shorthair", "Siberiano", "Exótico"],
    "Conejo": ["Enano Holandés", "Cabeza de León", "Belier", "Mini Lop", "Gigante de Flandes", "Rex", "Holland Lop"],
    "Ave": ["Canario", "Periquito", "Agaporni", "Loro", "Cacatúa", "Guacamayo", "Jilguero"],
    "Hámster": ["Siria", "Ruso", "Chino", "Roborovski", "Campbell"],
    "Tortuga": ["Sulcata", "Galápago", "Tigre", "Caja", "Estrella"],
    "Pez": ["Betta", "Goldfish", "Guppy", "Neón", "Molly", "Disco", "Corydora"],
    "Reptil": ["Iguana", "Gecko", "Camaleón", "Serpiente de maíz", "Boa constrictor"]
};

btnAdd.addEventListener('click', () => openModal());

function actualizarRazas() {
    const especie = document.getElementById('especie').value;
    const razaSelect = document.getElementById('raza');
    razaSelect.innerHTML = '';
    if (razasPorEspecie[especie]) {
        razasPorEspecie[especie].forEach(r => {
            const option = document.createElement('option');
            option.value = r;
            option.text = r;
            razaSelect.appendChild(option);
        });
    } else {
        const option = document.createElement('option');
        option.value = '';
        option.text = 'Otra';
        razaSelect.appendChild(option);
    }
}

// calcular edad desde fecha y ponerla en hidden
function calcularEdadDesdeFecha() {
    const dobVal = document.getElementById('fecha_nacimiento').value;
    const edadHidden = document.getElementById('edadHidden');
    if (!dobVal) {
        edadHidden.value = '';
        return;
    }
    const dob = new Date(dobVal);
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
        age--;
    }
    edadHidden.value = age;
}

// abrir modal con datos (editar) o vacío (agregar)
function openModal(data = null) {
    form.reset();
    document.getElementById('edadHidden').value = '';
    actualizarRazas(); // siempre actualiza las razas al abrir
    if (data) {
        modalTitle.textContent = 'Editar Mascota (ID: ' + data.id_mascota + ')';
        submitBtn.textContent = 'Guardar Cambios';
        document.getElementById('id_mascota').value = data.id_mascota;
        document.getElementById('nombre').value = data.nombre;
        document.getElementById('especie').value = data.especie;
        actualizarRazas();
        document.getElementById('raza').value = data.raza;
        // Si tenemos edad guardada, la ponemos en el hidden (no conocemos DOB)
        document.getElementById('edadHidden').value = data.edad ?? '';
        // dejar fecha_nacimiento vacía porque no existe columna para ella
        document.getElementById('fecha_nacimiento').value = '';
        document.getElementById('id_cliente').value = data.id_cliente ?? '';
    } else {
        modalTitle.textContent = 'Agregar Mascota';
        submitBtn.textContent = 'Guardar';
    }
    modal.classList.add('show');
}

function closeModal() {
    modal.classList.remove('show');
}

window.onclick = function(e) {
    if (e.target === modal) closeModal();
};

</script>
<h3>.</h3>
<h3>.</h3>
<h3>.</h3>
<h3>.</h3>
<h3>.</h3>
<h3>.</h3>

<?php include "footer.php"; ?>
</body>
</html>