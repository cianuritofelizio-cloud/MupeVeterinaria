<?php
session_start();

// Obtener id y rol desde la sesión (compatibilidad con id_usuario o user_id)
$session_user_id = intval($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$session_rol = strtolower(trim($_SESSION['rol'] ?? ''));

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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_cita = $_POST['id_cita'] ?? '';
    // Si el usuario es cliente, forzamos id_cliente desde la sesión
    if ($session_rol === 'cliente' && $session_user_id > 0) {
        $id_cliente = $session_user_id;
    } else {
        $id_cliente = (int)($_POST['id_cliente'] ?? 0);
    }
    $nuevo_cliente = trim($_POST['nuevo_cliente'] ?? '');
    $id_mascota = isset($_POST['id_mascota']) ? (int)$_POST['id_mascota'] : null;
    $id_veterinario = isset($_POST['id_veterinario']) ? (int)$_POST['id_veterinario'] : null;
    $id_servicio = isset($_POST['id_servicio']) ? (int)$_POST['id_servicio'] : null;
    $fecha = $_POST['fecha'] ?? null;
    $hora = $_POST['hora'] ?? null;
    $motivo = $_POST['motivo'] ?? null;

    // --- Si hay cliente nuevo, solo permitirlo si no es Cliente (ej. admin/recepcionista) ---
    if ($nuevo_cliente !== '' && $session_rol !== 'cliente') {
        // Generar correo temporal único
        $correoTemp = strtolower(str_replace(' ', '', $nuevo_cliente)) . uniqid() . "@temporal.com";
        $passTemp = password_hash("temporal123", PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO usuarios (nombre, correo, contrasena, rol) VALUES (?, ?, ?, 'Cliente')");
        $stmt->execute([$nuevo_cliente, $correoTemp, $passTemp]);
        $id_cliente = $conn->lastInsertId();
    }

    // --- Insertar o actualizar cita ---
    if ($id_cita != '') {
        // Antes de actualizar, si el usuario es cliente comprobaremos que la cita le pertenece
        if ($session_rol === 'cliente') {
            $check = $conn->prepare("SELECT id_cliente FROM citas WHERE id_cita = ?");
            $check->execute([$id_cita]);
            $r = $check->fetch(PDO::FETCH_ASSOC);
            if (!$r || (int)$r['id_cliente'] !== $session_user_id) {
                http_response_code(403);
                die("No autorizado para editar esta cita.");
            }
        }
        $stmt = $conn->prepare("UPDATE citas SET id_cliente=?, id_mascota=?, id_veterinario=?, id_servicio=?, fecha=?, hora=?, motivo=? WHERE id_cita=?");
        $stmt->execute([$id_cliente ?: null, $id_mascota ?: null, $id_veterinario ?: null, $id_servicio ?: null, $fecha, $hora, $motivo, $id_cita]);
        header("Location: agendar_cita.php?mensaje=actualizado");
        exit;
    } else {
        $stmt = $conn->prepare("INSERT INTO citas (id_cliente, id_mascota, id_veterinario, id_servicio, fecha, hora, motivo) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id_cliente ?: null, $id_mascota ?: null, $id_veterinario ?: null, $id_servicio ?: null, $fecha, $hora, $motivo]);
        header("Location: agendar_cita.php?mensaje=agregado");
        exit;
    }
}

// --- ELIMINAR ---
if (isset($_GET['eliminar'])) {
    $id = (int)$_GET['eliminar'];

    // Si el usuario es cliente, comprobar propiedad
    if ($session_rol === 'cliente') {
        $check = $conn->prepare("SELECT id_cliente FROM citas WHERE id_cita = ?");
        $check->execute([$id]);
        $r = $check->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['id_cliente'] !== $session_user_id) {
            http_response_code(403);
            die("No autorizado para eliminar esta cita.");
        }
    }

    $stmt = $conn->prepare("DELETE FROM citas WHERE id_cita = ?");
    $stmt->execute([$id]);
    header("Location: agendar_cita.php?mensaje=eliminado");
    exit;
}

// --- EDITAR (obtener datos para el modal) ---
$cita_editar = null;
if (isset($_GET['editar'])) {
    $id = (int)$_GET['editar'];
    // Si es cliente, solo puede editar sus propias citas
    if ($session_rol === 'cliente') {
        $stmt = $conn->prepare("SELECT * FROM citas WHERE id_cita=? AND id_cliente = ?");
        $stmt->execute([$id, $session_user_id]);
    } else {
        $stmt = $conn->prepare("SELECT * FROM citas WHERE id_cita=?");
        $stmt->execute([$id]);
    }
    $cita_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- LISTAS ---
// Clientes: si es cliente, solo ese; si no, todos
if ($session_rol === 'cliente' && $session_user_id > 0) {
    $stmt = $conn->prepare("SELECT id_usuario, nombre FROM usuarios WHERE id_usuario = ? LIMIT 1");
    $stmt->execute([$session_user_id]);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $clientes = $conn->query("SELECT id_usuario, nombre FROM usuarios WHERE rol='Cliente' ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Veterinarios (siempre todos)
$veterinarios = $conn->query("SELECT id_usuario, nombre FROM usuarios WHERE rol='Veterinario' ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

// Mascotas: si es cliente -> solo sus mascotas; si no -> todas
if ($session_rol === 'cliente' && $session_user_id > 0) {
    $stmt = $conn->prepare("SELECT id_mascota, nombre FROM mascotas WHERE id_cliente = ? ORDER BY nombre ASC");
    $stmt->execute([$session_user_id]);
    $mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $mascotas = $conn->query("SELECT id_mascota, nombre FROM mascotas ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Servicios: agregar para formulario y tabla
$servicios = $conn->query("SELECT id_servicio, nombre, precio FROM servicios ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- LISTA DE CITAS (incluyendo nombre del servicio) ---
if ($session_rol === 'cliente' && $session_user_id > 0) {
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
    WHERE c.id_cliente = :cid
    ORDER BY c.id_cita DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':cid' => $session_user_id]);
    $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
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
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Citas</title>
<style>
:root{
  --secundario3: #E9A0A0;
  --sidebar-width: 240px;
  --text-white: #ffffff;
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
  --max-table-height: 66vh;
}
*{box-sizing:border-box}
html, body { height:100%; margin:0; padding:0; font-family: Arial, Helvetica, sans-serif; background:var(--bg); color:var(--text); -webkit-font-smoothing:antialiased; }
.container {
  max-width:1200px;
  margin-left: calc(var(--sidebar-width) + 28px);
  padding:var(--container-gap);
  display:flex;
  flex-direction:column;
  gap:20px;
}
h1 {
  color: var(--secundario3);
  text-align:center;
  font-size:2.0rem;
  margin:0;
  font-weight:800;
  border-bottom:3px solid var(--primary);
  padding-bottom:10px;
}
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
.table-wrapper {
  background:var(--table-bg);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:0;
  overflow:auto;
  min-height:220px;
  max-height:var(--max-table-height);
}
.table {
  width:100%;
  border-collapse:collapse;
  min-width:760px;
  font-size:0.95rem;
  display:table;
}
.table thead th {
  position:sticky;
  top:0;
  background: linear-gradient(180deg, var(--secundario3), rgba(233,160,160,0.92));
  color:#111;
  padding:12px 14px;
  text-transform:uppercase;
  font-size:0.75rem;
  letter-spacing:0.6px;
  z-index:5;
  text-align:center;
}
.table tbody td {
  padding:12px 14px;
  color:#444;
  vertical-align:middle;
  text-align:center;
  border-bottom:1px solid rgba(0,0,0,0.04);
}
.table tbody tr:nth-child(even){ background: #fff; }
.table tbody tr:nth-child(odd){ background: #fffafc; }
.table tbody tr:hover{ background: #fff0f5; transform: translateY(-1px); }
.small { font-size:0.86rem; color:var(--muted); text-align:left; }
.action-btn {
  display:inline-block;
  padding:7px 12px;
  border-radius:6px;
  color:var(--text-white);
  text-decoration:none;
  font-weight:700;
  font-size:0.78rem;
  margin:2px;
  transition: all var(--transition);
  border:none;
}
.edit-btn { background: var(--primary); cursor:pointer; }
.edit-btn:hover{ background:#b30c40; }
.delete-btn { background:#6c6c6c; cursor:pointer; }
.delete-btn:hover{ background:#444; }
.modal-overlay {
  position:fixed;
  inset:0;
  display:none;
  justify-content:center;
  align-items:center;
  background:rgba(0,0,0,0.5);
  z-index:1200;
  transition: opacity var(--transition);
}
.modal-overlay.show { display:flex; opacity:1; }
.form-box {
  background:var(--table-bg);
  padding:22px;
  border-radius:12px;
  max-width:560px;
  width:92%;
  box-shadow: 0 10px 30px rgba(0,0,0,0.25);
  border-top:4px solid var(--primary);
}
.form-box h2 { text-align:center; color:var(--primary); margin:0 0 12px 0; font-size:1.25rem; }
.form-box label { display:block; margin-top:10px; font-weight:700; color:#333; font-size:0.92rem; }
.form-box input, .form-box select, .form-box textarea {
  width:100%; padding:10px; margin-top:8px; border-radius:8px; border:1px solid #ddd; font-size:0.95rem;
  transition: border-color var(--transition), box-shadow var(--transition);
}
.form-box textarea { min-height:70px; resize:vertical; }
.form-box input:focus, .form-box select:focus, .form-box textarea:focus { border-color:var(--primary); box-shadow:0 6px 18px rgba(229,15,83,0.08); outline:none; }
.form-box button[type="submit"] {
  width:100%; margin-top:16px; padding:12px; background:var(--primary); color:#fff; border:none; border-radius:10px; font-weight:800; cursor:pointer;
}
.form-box button[type="submit"]:hover { background:#b30c40; }
@media (max-width: 1000px) {
  .container { margin-left: 18px; margin-right: 18px; padding:20px; }
  .table { min-width:700px; }
}
@media (max-width: 760px) {
  .container { margin-left: 12px; margin-right: 12px; padding:14px; }
  .table { min-width:600px; font-size:0.9rem; }
  .table thead th { font-size:0.72rem; padding:10px; }
  .table tbody td { padding:10px; }
  .top-add-btn { padding:10px 16px; font-size:0.9rem; }
}
button:focus, a:focus { outline:3px solid rgba(229,15,83,0.12); outline-offset:2px; }
</style>
</head>
<body>

<?php include "sidebarCli.php"; ?>

<div class="container">
  <h1>Gestión de Citas</h1>
  <button class="top-add-btn" onclick="openModal(null)">Agregar Nueva Cita</button>

  <div class="table-wrapper" role="region" aria-label="Lista de citas">
    <table class="table" role="table" aria-live="polite">
      <thead>
        <tr>
          <th style="width:6%;">ID</th>
          <th style="width:16%;">Cliente</th>
          <th style="width:14%;">Mascota</th>
          <th style="width:14%;">Veterinario</th>
          <th style="width:10%;">Fecha</th>
          <th style="width:8%;">Hora</th>
          <th style="width:12%;">Servicio</th>
          <th style="width:12%;">Motivo</th>
          <th style="width:8%;">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($citas) === 0): ?>
          <tr><td colspan="9" style="padding:28px; color:var(--muted);">No hay citas para mostrar.</td></tr>
        <?php endif; ?>
        <?php foreach ($citas as $c): ?>
        <tr>
          <td><?= $c['id_cita'] ?></td>
          <td><?= htmlspecialchars($c['cliente'] ?? '') ?></td>
          <td><?= htmlspecialchars($c['mascota'] ?? '') ?></td>
          <td><?= htmlspecialchars($c['veterinario'] ?? '') ?></td>
          <td><?= $c['fecha'] ?></td>
          <td><?= $c['hora'] ?></td>
          <td><?= htmlspecialchars($c['servicio'] ?? '') ?></td>
          <td class="small"><?= nl2br(htmlspecialchars($c['motivo'])) ?></td>
          <td>
            <button class="action-btn edit-btn" onclick='openModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)'>Editar</button>
            <a href="agendar_cita.php?eliminar=<?= $c['id_cita'] ?>" class="action-btn delete-btn" onclick="return confirm('¿Eliminar esta cita?')">Eliminar</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="citaModal" aria-hidden="true">
  <div class="form-box" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <form method="POST" id="citaForm">
      <h2 id="modalTitle"></h2>
      <input type="hidden" name="id_cita" id="id_cita">

      <?php if ($session_rol === 'cliente' && $session_user_id > 0): ?>
        <input type="hidden" name="id_cliente" id="id_cliente" value="<?= $session_user_id ?>">
        <div style="margin-bottom:12px; font-weight:700; color:#333;">Cliente: <?= htmlspecialchars($_SESSION['usuario'] ?? 'Cliente') ?></div>
      <?php else: ?>
        <label for="id_cliente">Cliente:</label>
        <select name="id_cliente" id="id_cliente">
          <option value="">Seleccione...</option>
          <?php foreach ($clientes as $cl): ?>
            <option value="<?= $cl['id_usuario'] ?>"><?= htmlspecialchars($cl['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

      <label for="nuevo_cliente">Agregar Cliente Nuevo (opcional):</label>
      <input type="text" name="nuevo_cliente" id="nuevo_cliente" placeholder="Nombre del nuevo cliente" <?php if ($session_rol === 'cliente') echo 'disabled title="Los clientes no pueden crear otros clientes."'; ?>>

      <label for="id_mascota">Mascota:</label>
      <select name="id_mascota" id="id_mascota">
        <option value="">Seleccione...</option>
        <?php foreach ($mascotas as $m): ?>
          <option value="<?= $m['id_mascota'] ?>"><?= htmlspecialchars($m['nombre']) ?></option>
        <?php endforeach; ?>
      </select>

      <label for="id_veterinario">Veterinario:</label>
      <select name="id_veterinario" id="id_veterinario">
        <option value="">Seleccione...</option>
        <?php foreach ($veterinarios as $v): ?>
          <option value="<?= $v['id_usuario'] ?>"><?= htmlspecialchars($v['nombre']) ?></option>
        <?php endforeach; ?>
      </select>

      <label for="id_servicio">Servicio:</label>
      <select name="id_servicio" id="id_servicio">
        <option value="">Seleccione...</option>
        <?php foreach ($servicios as $s): ?>
          <option value="<?= $s['id_servicio'] ?>"><?= htmlspecialchars($s['nombre']) ?> (<?= number_format($s['precio'],2) ?>)</option>
        <?php endforeach; ?>
      </select>

      <label for="fecha">Fecha:</label>
      <input type="date" name="fecha" id="fecha" required>

      <label for="hora">Hora:</label>
      <input type="time" name="hora" id="hora" required>

      <label for="motivo">Motivo:</label>
      <textarea name="motivo" id="motivo" rows="3"></textarea>

      <button type="submit" id="btnSubmit">Guardar</button>
    </form>
  </div>
</div>

<script>
const modal = document.getElementById("citaModal");
const form = document.getElementById("citaForm");
const modalTitle = document.getElementById("modalTitle");
const btnSubmit = document.getElementById("btnSubmit");
const isCliente = <?= json_encode($session_rol === 'cliente' && $session_user_id > 0) ?>;

function openModal(cita){
    form.reset();
    modal.classList.add('show');
    modal.setAttribute('aria-hidden','false');

    if(cita){
        modalTitle.innerText = "Editar Cita";
        btnSubmit.innerText = "Guardar Cambios";
        document.getElementById("id_cita").value = cita.id_cita || '';
        const selCliente = document.getElementById("id_cliente");
        if (selCliente) selCliente.value = cita.id_cliente || '';
        const selMasc = document.getElementById("id_mascota");
        if (selMasc) selMasc.value = cita.id_mascota || '';
        const selVet = document.getElementById("id_veterinario");
        if (selVet) selVet.value = cita.id_veterinario || '';
        const selServ = document.getElementById("id_servicio");
        if (selServ) selServ.value = cita.id_servicio || '';
        document.getElementById("fecha").value = cita.fecha || '';
        document.getElementById("hora").value = cita.hora || '';
        document.getElementById("motivo").value = cita.motivo || '';
    } else {
        modalTitle.innerText = "Agregar Nueva Cita";
        btnSubmit.innerText = "Agregar Cita";
        document.getElementById("id_cita").value = "";
        // default date today
        document.getElementById("fecha").value = new Date().toISOString().substring(0,10);
    }
}

form.addEventListener('submit', function(e){
    const nuevoCliente = document.getElementById('nuevo_cliente');
    if (nuevoCliente && nuevoCliente.value.trim() !== '' && isCliente) {
        alert('No autorizado para crear un nuevo cliente.');
        e.preventDefault();
        return;
    }
    // if nuevo_cliente is filled, clear id_cliente to prioritize creation
    if (nuevoCliente && nuevoCliente.value.trim() !== '') {
        const selCliente = document.getElementById('id_cliente');
        if (selCliente) selCliente.value = '';
    }
});

modal.addEventListener("click", e => { if(e.target === modal){ modal.classList.remove("show"); modal.setAttribute('aria-hidden','true'); }});
</script>

</body>
</html>