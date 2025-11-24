<?php
session_start();

// Obtener id de sesión y rol
$session_user_id = $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? null;
$session_rol = $_SESSION['rol'] ?? null;

if (!$session_user_id) {
    header("Location: index.html");
    exit;
}

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
// Si el usuario actual es Cliente, no necesitamos poblar el select con otros clientes
if (strtolower($session_rol) === 'cliente') {
    $usuarios = $conn->prepare("SELECT id_usuario, nombre FROM usuarios WHERE id_usuario = ? LIMIT 1");
    $usuarios->execute([$session_user_id]);
    $usuarios = $usuarios->fetchAll(PDO::FETCH_ASSOC);
} else {
    $usuarios = $conn->prepare("SELECT id_usuario, nombre FROM usuarios WHERE rol = 'Cliente' ORDER BY nombre");
    $usuarios->execute();
    $usuarios = $usuarios->fetchAll(PDO::FETCH_ASSOC);
}

// --- Procesar agregar/editar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id_mascota'] ?? '';
    $nombre = trim($_POST['nombre'] ?? '');
    $especie = $_POST['especie'] ?? '';
    $raza = $_POST['raza'] ?? '';
    $edad = null;

    if (!empty($_POST['fecha_nacimiento'])) {
        $dob = $_POST['fecha_nacimiento'];
        $dob_dt = DateTime::createFromFormat('Y-m-d', $dob);
        if ($dob_dt) {
            $today = new DateTime();
            $diff = $today->diff($dob_dt);
            $edad = (int)$diff->y;
        } else {
            $edad = isset($_POST['edad']) ? intval($_POST['edad']) : null;
        }
    } else {
        $edad = isset($_POST['edad']) ? intval($_POST['edad']) : null;
    }

    // Si rol Cliente, forzamos owner desde sesión; si no, tomamos el id_cliente del form
    if (strtolower($session_rol) === 'cliente') {
        $id_cliente = (int)$session_user_id;
    } else {
        $id_cliente = !empty($_POST['id_cliente']) ? intval($_POST['id_cliente']) : null;
    }

    if ($id) {
        // Para editar: si es cliente, comprobar propiedad antes de actualizar
        if (strtolower($session_rol) === 'cliente') {
            $check = $conn->prepare("SELECT id_cliente FROM mascotas WHERE id_mascota = ?");
            $check->execute([$id]);
            $r = $check->fetch(PDO::FETCH_ASSOC);
            if (!$r || (int)$r['id_cliente'] !== (int)$session_user_id) {
                http_response_code(403);
                die("No autorizado para editar esta mascota.");
            }
        }
        $stmt = $conn->prepare("UPDATE mascotas SET nombre=?, especie=?, raza=?, edad=?, id_cliente=? WHERE id_mascota=?");
        $stmt->execute([$nombre, $especie, $raza, $edad, $id_cliente, $id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO mascotas (nombre, especie, raza, edad, id_cliente) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$nombre, $especie, $raza, $edad, $id_cliente]);
    }
    header("Location: mascotac.php");
    exit;
}

// --- Eliminar ---
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Si el usuario es cliente, comprobar propiedad
    if (strtolower($session_rol) === 'cliente') {
        $check = $conn->prepare("SELECT id_cliente FROM mascotas WHERE id_mascota = ?");
        $check->execute([$id]);
        $r = $check->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['id_cliente'] !== (int)$session_user_id) {
            http_response_code(403);
            die("No autorizado para eliminar esta mascota.");
        }
    }

    $stmt = $conn->prepare("DELETE FROM mascotas WHERE id_mascota=?");
    $stmt->execute([$id]);
    header("Location: mascotac.php");
    exit;
}

// --- Obtener mascotas ---
// Si es cliente, solo sus mascotas; si no, todas
if (strtolower($session_rol) === 'cliente') {
    $stmt = $conn->prepare("
        SELECT m.*, u.nombre AS dueño 
        FROM mascotas m 
        LEFT JOIN usuarios u ON m.id_cliente = u.id_usuario
        WHERE m.id_cliente = ?
        ORDER BY m.nombre
    ");
    $stmt->execute([$session_user_id]);
    $mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $mascotas = $conn->query("
        SELECT m.*, u.nombre AS dueño 
        FROM mascotas m 
        LEFT JOIN usuarios u ON m.id_cliente = u.id_usuario
        ORDER BY m.nombre
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Mascotas</title>
<style>
:root{
  --secundario3: #E9A0A0;
  --sidebar-width: 240px;
  --sidebar-compact: 72px;
  --text-white: #ffffff;
  --icon-color: #000000;
  --hover-bg: #d98888;
  --primary: #E50F53;
  --bg: #f7f7f7;
  --muted: #666;
  --table-bg: #ffffff;
  --shadow: 0 6px 18px rgba(0,0,0,0.06);
  --radius: 10px;
  --transition-fast: 150ms;
  --transition: 180ms;
  --text: #222;
  --container-gap: 30px;
}

/* Reset y base */
*{box-sizing:border-box}
html, body { height:100%; margin:0; padding:0; font-family: Arial, Helvetica, sans-serif; background:var(--bg); color:var(--text); -webkit-font-smoothing:antialiased; }

/* Layout: deja espacio para el sidebarRecep (mismo ancho) */
.container {
  max-width:1200px;
  margin-left: calc(var(--sidebar-width) + 28px);
  padding:var(--container-gap);
  display:flex;
  flex-direction:column;
  gap:20px;
}

/* Header */
h1 {
  color: var(--secundario3);
  text-align:center;
  font-size:2.0rem;
  margin:0;
  font-weight:800;
  border-bottom:3px solid var(--primary);
  padding-bottom:10px;
}

/* Botón añadir */
.top-add-btn {
  background:var(--secundario3);
  color:var(--text-white);
  padding:12px 22px;
  border:none;
  border-radius:8px;
  font-weight:700;
  text-transform:uppercase;
  cursor:pointer;
  width:max-content;
  align-self:center;
  transition: transform var(--transition-fast), background var(--transition-fast);
  box-shadow: 0 6px 14px rgba(0,0,0,0.06);
}
.top-add-btn:hover{ transform:translateY(-3px); background: #b30c40; }

/* Tabla wrapper */
table { width:100%; border-collapse:collapse; margin-top:18px; background:var(--table-bg); border-radius:8px; box-shadow:var(--shadow); overflow:hidden; }
th, td { padding:12px 14px; text-align:center; border-bottom:1px solid rgba(0,0,0,0.04); }
th { background: linear-gradient(180deg, var(--secundario3), rgba(233,160,160,0.92)); color:#111; text-transform:uppercase; font-size:0.78rem; letter-spacing:0.6px; position:sticky; top:0; z-index:5; }
tbody tr:nth-child(even){ background: #fff; }
tbody tr:nth-child(odd){ background: #fffafc; }
tbody tr:hover{ background: #fff0f5; transform: translateY(-1px); }

/* Small text */
.small-note { font-size:0.92rem; color:var(--muted); margin-top:6px; }

/* Action buttons */
.action-btn { padding:8px 14px; border-radius:6px; text-decoration:none; font-weight:600; margin:3px; display:inline-block; font-size:13px; text-transform:uppercase; color:#fff; background:var(--primary); transition:all var(--transition); }
.action-delete { background:#6c6c6c; }
.action-delete:hover { background:#444; }

/* Modal */
.modal-overlay { display:none; justify-content:center; align-items:center; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; }
.modal-overlay.show { display:flex; }
.form-box { background:var(--table-bg); padding:25px; border-radius:12px; max-width:520px; width:92%; box-shadow:0 10px 30px rgba(0,0,0,0.3); border-top:3px solid var(--primary); }
.form-box label { display:block; margin-top:12px; font-weight:700; color:#333; }
.form-box input, .form-box select, .form-box textarea { width:100%; padding:10px; margin-top:8px; border-radius:8px; border:1px solid #ccc; font-size:0.95rem; box-sizing:border-box; }
.form-box textarea { min-height:70px; }
.form-box button[type="submit"] { width:100%; margin-top:18px; padding:12px; background:var(--primary); color:#fff; border:none; border-radius:10px; font-weight:800; cursor:pointer; }
.form-box button[type="submit"]:hover { background:#b30c40; }

/* Responsive */
@media (max-width:1000px) {
  .container { margin-left: 18px; margin-right: 18px; padding:20px; }
}
@media (max-width:760px) {
  .container { margin-left: 12px; margin-right: 12px; padding:14px; }
  th, td { padding:10px; font-size:0.92rem; }
  .top-add-btn { padding:10px 16px; }
}

/* Accessibility */
button:focus, a:focus { outline:3px solid rgba(229,15,83,0.12); outline-offset:2px; }
</style>
</head>
<body>

<?php include "sidebarCli.php";?>

<div class="container">
    <h1>Gestión de Mascotas</h1>
    <button class="top-add-btn" id="btnAdd">Agregar Mascota</button>

    <table aria-live="polite" role="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Especie</th>
            <th>Raza</th>
            <th>Edad (años)</th>
            <th>Dueño</th>
            <th>Acciones</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($mascotas)): ?>
            <tr><td colspan="7" style="padding:22px; color:var(--muted);">No hay mascotas para mostrar.</td></tr>
        <?php endif; ?>
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
        </tbody>
    </table>
</div>

<!-- MODAL AGREGAR/EDITAR (mezcla comportamientos: si cliente ocultar select dueño) -->
<div class="modal-overlay" id="modalForm" aria-hidden="true">
    <div class="form-box" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <span class="close" style="float:right; font-size:22px; cursor:pointer;" onclick="closeModal()">&times;</span>
        <h3 id="modalTitle">Agregar Mascota</h3>
        <form method="POST" id="formMascota">
            <input type="hidden" name="id_mascota" id="id_mascota">
            <input type="hidden" name="edad" id="edadHidden">

            <label for="nombre">Nombre:</label>
            <input type="text" name="nombre" id="nombre" required>

            <label for="especie">Especie:</label>
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

            <label for="raza">Raza:</label>
            <select name="raza" id="raza">
                <option value="">Seleccione especie primero</option>
            </select>

            <label for="fecha_nacimiento">Fecha de nacimiento:</label>
            <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" onchange="calcularEdadDesdeFecha()">

            <?php if (strtolower($session_rol) === 'cliente'): ?>
                <input type="hidden" name="id_cliente" id="id_cliente" value="<?= (int)$session_user_id ?>">
                <div class="small-note">Dueño: <?= htmlspecialchars($_SESSION['usuario'] ?? 'Cliente') ?></div>
            <?php else: ?>
                <label for="id_cliente">Dueño:</label>
                <select name="id_cliente" id="id_cliente" required>
                    <option value="">Seleccione dueño</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= htmlspecialchars($u['id_usuario']) ?>"><?= htmlspecialchars($u['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <button type="submit" id="submitBtn">Guardar</button>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('modalForm');
const form = document.getElementById('formMascota');
const modalTitle = document.getElementById('modalTitle');
const submitBtn = document.getElementById('submitBtn');
const razasPorEspecie = {
    "Perro": ["Labrador","Golden Retriever","Bulldog","Poodle","Chihuahua","Pastor Alemán","Beagle","Dálmata","Shih Tzu","Husky Siberiano","Boxer","Cocker Spaniel"],
    "Gato": ["Siamés","Persa","Maine Coon","Bengala","Sphynx","Ragdoll","Abisinio","British Shorthair","Siberiano","Exótico"],
    "Conejo": ["Enano Holandés","Cabeza de León","Belier","Mini Lop","Gigante de Flandes","Rex","Holland Lop"],
    "Ave": ["Canario","Periquito","Agaporni","Loro","Cacatúa","Guacamayo","Jilguero"],
    "Hámster": ["Siria","Ruso","Chino","Roborovski","Campbell"],
    "Tortuga": ["Sulcata","Galápago","Tigre","Caja","Estrella"],
    "Pez": ["Betta","Goldfish","Guppy","Neón","Molly","Disco","Corydora"],
    "Reptil": ["Iguana","Gecko","Camaleón","Serpiente de maíz","Boa constrictor"]
};

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

function openModal(data = null) {
    form.reset();
    document.getElementById('edadHidden').value = '';
    actualizarRazas();
    if (data) {
        modalTitle.textContent = 'Editar Mascota (ID: ' + data.id_mascota + ')';
        submitBtn.textContent = 'Guardar Cambios';
        document.getElementById('id_mascota').value = data.id_mascota;
        document.getElementById('nombre').value = data.nombre;
        document.getElementById('especie').value = data.especie;
        actualizarRazas();
        document.getElementById('raza').value = data.raza;
        document.getElementById('edadHidden').value = data.edad ?? '';
        document.getElementById('fecha_nacimiento').value = '';
        const sel = document.getElementById('id_cliente');
        if (sel) sel.value = data.id_cliente ?? '';
    } else {
        modalTitle.textContent = 'Agregar Mascota';
        submitBtn.textContent = 'Guardar';
    }
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
}

function closeModal() {
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
}

window.onclick = function(e) {
    if (e.target === modal) closeModal();
};

document.getElementById('btnAdd').addEventListener('click', function(){ openModal(); });
</script>


</body>
</html>