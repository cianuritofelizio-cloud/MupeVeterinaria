<?php
session_start();

// Generar CSRF token si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Permisos
if (!isset($_SESSION['rol']) || $_SESSION['rol'] != 'Veterinario') {
    header("Location: ../../vet_panel.php");
    exit;
}

$usuario = $_SESSION['usuario'];
$id_veterinario = $_SESSION['id_usuario'] ?? null;

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "veterinaria_mupe";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Cargar mascotas e inventario disponible para el formulario
$mascotas = $conn->query("SELECT id_mascota, nombre FROM mascotas ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$inventario_list = $conn->query("SELECT id, nombre, cantidad FROM inventario ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

// Manejo de POST: crear o editar diagnóstico (con consumo/ajuste de inventario)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Validar CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        echo "<script>alert('Token CSRF inválido. Intente nuevamente.');</script>";
    } else {
        $id = $_POST["id_diagnostico"] ?? '';
        $id_mascota = isset($_POST['id_mascota']) ? intval($_POST['id_mascota']) : null;
        $fecha = $_POST["fecha"] ?? date('Y-m-d');
        $descripcion = trim($_POST["descripcion"] ?? '');
        $observaciones = trim($_POST["observaciones"] ?? '');
        $tratamiento = trim($_POST["tratamiento"] ?? '');
        $now = date("Y-m-d H:i:s");

        // Medicamentos arrays (optional)
        $med_ids = $_POST['med_id'] ?? [];
        $med_cants = $_POST['med_cant'] ?? [];

        if (empty($fecha) || empty($descripcion) || empty($tratamiento) || !$id_mascota) {
            echo "<script>alert('Los campos Fecha, Mascota, Descripción y Tratamiento son obligatorios');</script>";
        } else {
            try {
                if ($id) {
                    // EDITAR: reponer prev meds, eliminar relaciones, aplicar nuevos meds
                    $conn->beginTransaction();

                    // Actualizar diagnóstico
                    $sql = "UPDATE diagnosticos 
                            SET id_mascota = :id_mascota, id_veterinario = :id_veterinario, fecha = :fecha, descripcion = :descripcion, observaciones = :observaciones, tratamiento = :tratamiento, fecha_actualizacion = :fecha_actualizacion 
                            WHERE id_diagnostico = :id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ":id_mascota" => $id_mascota,
                        ":id_veterinario" => $id_veterinario,
                        ":fecha" => $fecha,
                        ":descripcion" => $descripcion,
                        ":observaciones" => $observaciones,
                        ":tratamiento" => $tratamiento,
                        ":fecha_actualizacion" => $now,
                        ":id" => $id
                    ]);

                    // Obtener medicamentos previos
                    $prev = $conn->prepare("SELECT id_inventario, cantidad FROM diagnostico_medicamentos WHERE id_diagnostico = ?");
                    $prev->execute([$id]);
                    $prevMeds = $prev->fetchAll(PDO::FETCH_ASSOC);

                    // Reponer stock de prev meds y registrar movimientos (entrada)
                    foreach ($prevMeds as $pm) {
                        $id_inv = (int)$pm['id_inventario'];
                        $cant_prev = (int)$pm['cantidad'];

                        // Lock row and get current stock
                        $sel = $conn->prepare("SELECT cantidad FROM inventario WHERE id = ? FOR UPDATE");
                        $sel->execute([$id_inv]);
                        $rowInv = $sel->fetch(PDO::FETCH_ASSOC);
                        if ($rowInv) {
                            $stock_actual = (int)$rowInv['cantidad'];
                            $nuevo_stock = $stock_actual + $cant_prev;
                            $upd = $conn->prepare("UPDATE inventario SET cantidad = :cantidad WHERE id = :id");
                            $upd->execute([":cantidad" => $nuevo_stock, ":id" => $id_inv]);

                            // Registrar movimiento de entrada (reversión)
                            $mov = $conn->prepare("INSERT INTO inventario_movimientos (id_inventario, id_usuario, tipo, cantidad, motivo) VALUES (:id_inv, :id_usr, 'entrada', :cant, :motivo)");
                            $mot = "Reversión por edición de diagnóstico #{$id}";
                            $mov->execute([":id_inv" => $id_inv, ":id_usr" => $id_veterinario, ":cant" => $cant_prev, ":motivo" => $mot]);
                        }
                    }

                    // Eliminar relaciones previas
                    $delRel = $conn->prepare("DELETE FROM diagnostico_medicamentos WHERE id_diagnostico = ?");
                    $delRel->execute([$id]);

                    // Aplicar nuevos medicamentos (si any)
                    $countMed = min(count($med_ids), count($med_cants));
                    for ($i = 0; $i < $countMed; $i++) {
                        $id_inventario = intval($med_ids[$i]);
                        $cantidad = intval($med_cants[$i]);
                        if ($id_inventario <= 0 || $cantidad <= 0) continue;

                        // Bloquear fila del inventario
                        $sel = $conn->prepare("SELECT cantidad, nombre FROM inventario WHERE id = ? FOR UPDATE");
                        $sel->execute([$id_inventario]);
                        $row = $sel->fetch(PDO::FETCH_ASSOC);
                        if (!$row) {
                            throw new Exception("Artículo con ID {$id_inventario} no encontrado en inventario.");
                        }
                        $stock_actual = (int)$row['cantidad'];
                        $nombre_art = $row['nombre'] ?? 'Artículo';

                        if ($stock_actual < $cantidad) {
                            throw new Exception("Stock insuficiente para {$nombre_art} (hay {$stock_actual}, solicitado {$cantidad}). Edición cancelada.");
                        }

                        $nuevo_stock = $stock_actual - $cantidad;

                        // Actualizar inventario
                        $upd = $conn->prepare("UPDATE inventario SET cantidad = :cantidad WHERE id = :id");
                        $upd->execute([":cantidad" => $nuevo_stock, ":id" => $id_inventario]);

                        // Insertar relación diagnostico_medicamentos
                        $insRel = $conn->prepare("INSERT INTO diagnostico_medicamentos (id_diagnostico, id_inventario, cantidad) VALUES (:id_diag, :id_inv, :cant)");
                        $insRel->execute([":id_diag" => $id, ":id_inv" => $id_inventario, ":cant" => $cantidad]);

                        // Insertar movimiento (salida)
                        $motivo = "Uso en edición de diagnóstico #{$id}";
                        $insMov = $conn->prepare("INSERT INTO inventario_movimientos (id_inventario, id_usuario, tipo, cantidad, motivo) VALUES (:id_inv, :id_user, 'salida', :cant, :motivo)");
                        $insMov->execute([":id_inv" => $id_inventario, ":id_user" => $id_veterinario, ":cant" => $cantidad, ":motivo" => $motivo]);
                    }

                    $conn->commit();
                    echo "<script>alert('Diagnóstico actualizado y stock ajustado.');</script>";
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    // CREAR nuevo diagnóstico con consumo de inventario
                    $conn->beginTransaction();

                    // Insertar diagnóstico
                    $sql = "INSERT INTO diagnosticos (id_mascota, id_veterinario, fecha, descripcion, observaciones, tratamiento, fecha_creacion, fecha_actualizacion)
                            VALUES (:id_mascota, :id_veterinario, :fecha, :descripcion, :observaciones, :tratamiento, :fecha_creacion, :fecha_actualizacion)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ":id_mascota" => $id_mascota,
                        ":id_veterinario" => $id_veterinario,
                        ":fecha" => $fecha,
                        ":descripcion" => $descripcion,
                        ":observaciones" => $observaciones,
                        ":tratamiento" => $tratamiento,
                        ":fecha_creacion" => $now,
                        ":fecha_actualizacion" => $now
                    ]);
                    $id_diagnostico = $conn->lastInsertId();

                    // Procesar medicamentos
                    $countMed = min(count($med_ids), count($med_cants));
                    for ($i = 0; $i < $countMed; $i++) {
                        $id_inventario = intval($med_ids[$i]);
                        $cantidad = intval($med_cants[$i]);
                        if ($id_inventario <= 0 || $cantidad <= 0) continue;

                        // Bloquear fila del inventario (SELECT FOR UPDATE)
                        $sel = $conn->prepare("SELECT cantidad, nombre FROM inventario WHERE id = ? FOR UPDATE");
                        $sel->execute([$id_inventario]);
                        $row = $sel->fetch(PDO::FETCH_ASSOC);
                        if (!$row) {
                            throw new Exception("Artículo con ID {$id_inventario} no encontrado en inventario.");
                        }

                        $stock_actual = (int)$row['cantidad'];
                        $nombre_art = $row['nombre'] ?? 'Artículo';

                        if ($stock_actual < $cantidad) {
                            throw new Exception("Stock insuficiente para {$nombre_art} (hay {$stock_actual}, solicitado {$cantidad}).");
                        }

                        $nuevo_stock = $stock_actual - $cantidad;

                        // Actualizar inventario
                        $upd = $conn->prepare("UPDATE inventario SET cantidad = :cantidad WHERE id = :id");
                        $upd->execute([":cantidad" => $nuevo_stock, ":id" => $id_inventario]);

                        // Insertar relación diagnostico_medicamentos
                        $insRel = $conn->prepare("INSERT INTO diagnostico_medicamentos (id_diagnostico, id_inventario, cantidad) VALUES (:id_diag, :id_inv, :cant)");
                        $insRel->execute([":id_diag" => $id_diagnostico, ":id_inv" => $id_inventario, ":cant" => $cantidad]);

                        // Insertar movimiento de inventario (salida)
                        $motivo = "Uso en diagnóstico #{$id_diagnostico}";
                        $insMov = $conn->prepare("INSERT INTO inventario_movimientos (id_inventario, id_usuario, tipo, cantidad, motivo) VALUES (:id_inv, :id_user, 'salida', :cant, :motivo)");
                        $insMov->execute([":id_inv" => $id_inventario, ":id_user" => $id_veterinario, ":cant" => $cantidad, ":motivo" => $motivo]);
                    }

                    $conn->commit();
                    echo "<script>alert('Diagnóstico creado y stock actualizado correctamente.');</script>";
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                }
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $msg = "Error: " . $e->getMessage();
                echo "<script>alert(" . json_encode($msg) . ");</script>";
            }
        }
    }
}

// Manejo de eliminación: reponer inventario si el diagnóstico tenía medicamentos
if (isset($_GET["eliminar"])) {
    $id = intval($_GET["eliminar"]);
    try {
        $conn->beginTransaction();

        // Obtener medicamentos ligados al diagnóstico
        $sel = $conn->prepare("SELECT id_inventario, cantidad FROM diagnostico_medicamentos WHERE id_diagnostico = ?");
        $sel->execute([$id]);
        $prevMeds = $sel->fetchAll(PDO::FETCH_ASSOC);

        // Reponer stock y registrar movimiento de entrada
        foreach ($prevMeds as $pm) {
            $id_inv = (int)$pm['id_inventario'];
            $cant_prev = (int)$pm['cantidad'];

            // Lock row and update
            $lock = $conn->prepare("SELECT cantidad FROM inventario WHERE id = ? FOR UPDATE");
            $lock->execute([$id_inv]);
            $rowInv = $lock->fetch(PDO::FETCH_ASSOC);
            if ($rowInv) {
                $stock_actual = (int)$rowInv['cantidad'];
                $nuevo_stock = $stock_actual + $cant_prev;
                $upd = $conn->prepare("UPDATE inventario SET cantidad = :cantidad WHERE id = :id");
                $upd->execute([":cantidad" => $nuevo_stock, ":id" => $id_inv]);

                // Registrar entrada
                $mov = $conn->prepare("INSERT INTO inventario_movimientos (id_inventario, id_usuario, tipo, cantidad, motivo) VALUES (:id_inv, :id_usr, 'entrada', :cant, :motivo)");
                $mot = "Reversión por eliminación de diagnóstico #{$id}";
                $mov->execute([":id_inv" => $id_inv, ":id_usr" => $id_veterinario, ":cant" => $cant_prev, ":motivo" => $mot]);
            }
        }

        // Borrar diagnostico (las relaciones en diagnostico_medicamentos se eliminarán por FK ON DELETE CASCADE)
        $stmt = $conn->prepare("DELETE FROM diagnosticos WHERE id_diagnostico = :id");
        $stmt->execute([":id" => $id]);

        $conn->commit();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $msg = "Error al eliminar: " . $e->getMessage();
        echo "<script>alert(" . json_encode($msg) . ");</script>";
    }
}

// Listado de diagnósticos (incluye datos del veterinario y mascota)
$diagnosticos = $conn->query("
    SELECT d.*, m.nombre AS nombre_mascota, u.nombre AS nombre_vet 
    FROM diagnosticos d 
    LEFT JOIN mascotas m ON d.id_mascota = m.id_mascota 
    LEFT JOIN usuarios u ON d.id_veterinario = u.id_usuario 
    ORDER BY id_diagnostico DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Función auxiliar para obtener medicamentos de un diagnóstico
function obtenerMedicamentos(PDO $conn, $id_diagnostico) {
    $stmt = $conn->prepare("
        SELECT dm.id, dm.id_inventario, dm.cantidad, i.nombre AS articulo_nombre
        FROM diagnostico_medicamentos dm
        LEFT JOIN inventario i ON dm.id_inventario = i.id
        WHERE dm.id_diagnostico = ?
    ");
    $stmt->execute([$id_diagnostico]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Diagnósticos</title>
<style>
html, body { background:#f7f7f7; font-family:Arial,sans-serif; margin:0; padding:0; }
.container { margin-left:300px; padding:30px; }
h1 { color:#E9A0A0; text-align:center; font-size:2.2em; border-bottom:2px solid #E50F53; padding-bottom:5px; }
.top-add-btn { background:#E9A0A0; color:white; padding:14px 28px; border:none; border-radius:8px; font-size:16px; font-weight:700; cursor:pointer; transition:all 0.3s; text-transform:uppercase; margin:20px auto; display:block; }
.top-add-btn:hover { background:#b30c40; box-shadow:0 6px 15px rgba(0,0,0,0.15); transform:translateY(-2px); }
table { width:100%; border-collapse:collapse; background:white; border-radius:10px; box-shadow:0 5px 15px rgba(0,0,0,0.08); }
th, td { padding:10px; text-align:center; font-size:13px; vertical-align:middle; }
th { background:#E9A0A0; color:#333; text-transform:uppercase; }
tr:nth-child(even){ background:#fafafa; } tr:hover{ background:#fff0f5; }
.action-btn { padding:8px 14px; border-radius:6px; text-decoration:none; font-weight:600; margin:3px; display:inline-block; font-size:13px; text-transform:uppercase; background:#E50F53; color:white; transition:all 0.3s; }
.action-btn:hover { background:#b30c40; }
.action-delete { background:#555; } .action-delete:hover { background:#333; }
.view-meds { background:#0ea5b0; } .view-meds:hover { background:#088f86; }
.modal-overlay { display:none; justify-content:center; align-items:center; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; }
.modal-overlay.show { display:flex; }
.form-box { background:white; padding:20px; border-radius:10px; max-width:900px; width:95%; box-shadow:0 10px 30px rgba(0,0,0,0.3); border-top:3px solid #E50F53; }
.form-box label { display:block; margin-top:12px; font-weight:600; color:#333; }
.form-box input, .form-box textarea, .form-box select { width:100%; padding:10px; margin-top:8px; border-radius:6px; border:1px solid #ccc; box-sizing:border-box; }
.close { float:right; font-size:24px; cursor:pointer; color:#888; }
.med-row { display:flex; gap:8px; margin-bottom:8px; align-items:center; }
.add-med-btn { background:#0ea5b0; color:#fff; padding:8px 12px; border-radius:6px; border:none; cursor:pointer; }
.remove-med-btn { background:#aaa; color:#fff; padding:6px 10px; border-radius:6px; border:none; cursor:pointer; }
.small { font-size:0.9rem; color:#666; }
.badge { background:#E50F53; color:#fff; padding:6px 10px; border-radius:999px; font-weight:700; min-width:36px; text-align:center; box-shadow:0 6px 18px rgba(229,15,83,0.12); font-size:0.95rem; }
</style>
</head>
<body>

<?php include "sidebarVet.php"; ?>

<div class="container">
    <h1>Gestión de Diagnósticos</h1>
    <button class="top-add-btn" id="btnAdd">Agregar Diagnóstico</button>

    <table>
        <tr>
            <th>ID</th>
            <th>Mascota</th>
            <th>Fecha</th>
            <th>Descripción</th>
            <th>Observaciones</th>
            <th>Tratamiento</th>
            <th>Veterinario</th>
            <th>Creación</th>
            <th>Última Actualización</th>
            <th>Acciones</th>
        </tr>
        <?php foreach ($diagnosticos as $d): ?>
        <tr>
            <td><?= $d['id_diagnostico'] ?></td>
            <td><?= htmlspecialchars($d['nombre_mascota'] ?? 'N/A') ?></td>
            <td><?= $d['fecha'] ?></td>
            <td><?= htmlspecialchars($d['descripcion']) ?></td>
            <td><?= htmlspecialchars($d['observaciones']) ?></td>
            <td><?= htmlspecialchars($d['tratamiento']) ?></td>
            <td><?= htmlspecialchars($d['nombre_vet'] ?? 'N/A') ?></td>
            <td><?= $d['fecha_creacion'] ?? '' ?></td>
            <td><?= $d['fecha_actualizacion'] ?? '' ?></td>
            <td>
                <a class="action-btn" href="#" onclick='openModal(<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT) ?>); return false;'>Editar</a>
                <a class="action-btn view-meds" href="#" onclick='viewMeds(<?= (int)$d["id_diagnostico"] ?>); return false;'>Ver meds</a>
                <a class="action-btn action-delete" href="?eliminar=<?= $d['id_diagnostico'] ?>" onclick="return confirm('¿Eliminar diagnóstico? Esto restaurará el stock de los medicamentos usados.')">Eliminar</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<!-- MODAL AGREGAR/EDITAR -->
<div class="modal-overlay" id="modalForm">
    <div class="form-box" role="dialog" aria-modal="true">
        <span class="close" onclick="closeModal()">&times;</span>
        <h3 id="modalTitle">Agregar Diagnóstico</h3>
        <form method="POST" id="formDiagnostico">
            <input type="hidden" name="id_diagnostico" id="id_diagnostico">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <label>Mascota:</label>
            <select name="id_mascota" id="id_mascota" required>
                <option value="">-- Seleccione una mascota --</option>
                <?php foreach ($mascotas as $m): ?>
                    <option value="<?= $m['id_mascota'] ?>"><?= htmlspecialchars($m['nombre']) ?> (ID: <?= $m['id_mascota'] ?>)</option>
                <?php endforeach; ?>
            </select>

            <label>Fecha:</label>
            <input type="date" name="fecha" id="fecha" required>

            <label>Descripción:</label>
            <textarea name="descripcion" id="descripcion" required></textarea>

            <label>Observaciones:</label>
            <textarea name="observaciones" id="observaciones"></textarea>

            <label>Tratamiento:</label>
            <textarea name="tratamiento" id="tratamiento" required></textarea>

            <hr style="margin:12px 0; border:none; border-top:1px solid #eee;">

            <h4 style="margin:10px 0 8px 0;">Medicamentos / Insumos (opcional)</h4>
            <div id="medicamentosContainer">
                <div class="med-row">
                    <select name="med_id[]" class="med-select">
                        <option value="">-- Seleccione artículo --</option>
                        <?php foreach($inventario_list as $it): ?>
                            <option value="<?= $it['id'] ?>" data-stock="<?= $it['cantidad'] ?>"><?= htmlspecialchars($it['nombre']) ?> (<?= $it['cantidad'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="med_cant[]" class="med-cant" min="1" placeholder="Cantidad" style="width:120px;">
                    <button type="button" class="remove-med-btn" onclick="removeMedRow(this)">Quitar</button>
                </div>
            </div>
            <button type="button" class="add-med-btn" onclick="addMedRow()">Agregar artículo</button>

            <button type="submit" id="submitBtn">Guardar</button>
        </form>
    </div>
</div>

<!-- MODAL VER MEDICAMENTOS -->
<div class="modal-overlay" id="modalMeds">
    <div class="form-box">
        <span class="close" onclick="closeMedsModal()">&times;</span>
        <h3 id="medsTitle">Medicamentos del diagnóstico</h3>
        <div id="medsList" style="margin-top:12px;"></div>
        <div style="margin-top:12px; text-align:right;">
            <button onclick="closeMedsModal()" style="background:#999;color:#fff;padding:8px 12px;border-radius:6px;border:none;">Cerrar</button>
        </div>
    </div>
</div>

<script>
// ---------- Script corregido para manejo de medicamentos en modal ----------

// Capturamos la plantilla de options una sola vez
const medSelectElem = document.querySelector('.med-select');
const medSelectHTML = medSelectElem ? medSelectElem.innerHTML : '';

const modal = document.getElementById('modalForm');
const form = document.getElementById('formDiagnostico');
const modalTitle = document.getElementById('modalTitle');
const submitBtn = document.getElementById('submitBtn');
const btnAdd = document.getElementById('btnAdd');
const medicamentosContainer = document.getElementById('medicamentosContainer');

btnAdd.addEventListener('click', () => openModal());

function openModal(data = null) {
    form.reset();
    resetMedContainer();
    document.getElementById('id_diagnostico').value = '';
    if (data) {
        modalTitle.textContent = 'Editar Diagnóstico (ID: ' + data.id_diagnostico + ')';
        submitBtn.textContent = 'Guardar Cambios';
        document.getElementById('id_diagnostico').value = data.id_diagnostico;
        document.getElementById('id_mascota').value = data.id_mascota;
        document.getElementById('fecha').value = data.fecha;
        document.getElementById('descripcion').value = data.descripcion;
        document.getElementById('observaciones').value = data.observaciones;
        document.getElementById('tratamiento').value = data.tratamiento;

        const template = medSelectHTML || (document.querySelector('.med-select') ? document.querySelector('.med-select').innerHTML : '');

        // Cargar medicamentos existentes vía AJAX y poblar filas
        fetch('get_diagnostico_medicamentos.php?id=' + encodeURIComponent(data.id_diagnostico))
            .then(r => r.json())
            .then(json => {
                if (!template) {
                    medicamentosContainer.innerHTML = '';
                    addMedRow();
                    return;
                }
                medicamentosContainer.innerHTML = '';
                if (json.ok && Array.isArray(json.medicamentos) && json.medicamentos.length > 0) {
                    json.medicamentos.forEach(m => {
                        const row = document.createElement('div');
                        row.className = 'med-row';
                        const select = document.createElement('select');
                        select.name = 'med_id[]';
                        select.className = 'med-select';
                        select.innerHTML = template;
                        const input = document.createElement('input');
                        input.type = 'number';
                        input.name = 'med_cant[]';
                        input.className = 'med-cant';
                        input.min = 1;
                        input.value = parseInt(m.cantidad, 10) || 1;
                        input.style.width = '120px';
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'remove-med-btn';
                        btn.textContent = 'Quitar';
                        btn.onclick = function(){ removeMedRow(btn); };
                        row.appendChild(select);
                        row.appendChild(input);
                        row.appendChild(btn);
                        medicamentosContainer.appendChild(row);
                        const opt = select.querySelector('option[value="'+m.id_inventario+'"]');
                        if (opt) opt.selected = true;
                    });
                } else {
                    addMedRow();
                }
            }).catch(err => {
                console.error('Error cargando meds:', err);
                medicamentosContainer.innerHTML = '';
                addMedRow();
            });
    } else {
        modalTitle.textContent = 'Agregar Diagnóstico';
        submitBtn.textContent = 'Guardar';
        document.getElementById('fecha').value = new Date().toISOString().split('T')[0];
    }
    modal.classList.add('show');
}

function closeModal() {
    modal.classList.remove('show');
}

function resetMedContainer(){
    const template = medSelectHTML || (document.querySelector('.med-select') ? document.querySelector('.med-select').innerHTML : '');
    medicamentosContainer.innerHTML = '';
    const row = document.createElement('div');
    row.className = 'med-row';
    const select = document.createElement('select');
    select.name = 'med_id[]';
    select.className = 'med-select';
    select.innerHTML = template || '<option value="">-- Seleccione artículo --</option>';
    const input = document.createElement('input');
    input.type = 'number';
    input.name = 'med_cant[]';
    input.className = 'med-cant';
    input.min = 1;
    input.placeholder = 'Cantidad';
    input.style.width = '120px';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'remove-med-btn';
    btn.textContent = 'Quitar';
    btn.onclick = function(){ removeMedRow(btn); };
    row.appendChild(select);
    row.appendChild(input);
    row.appendChild(btn);
    medicamentosContainer.appendChild(row);
}

function addMedRow() {
    const template = medSelectHTML || (document.querySelector('.med-select') ? document.querySelector('.med-select').innerHTML : '');
    const cont = document.getElementById('medicamentosContainer');
    const row = document.createElement('div');
    row.className = 'med-row';
    const select = document.createElement('select');
    select.name = 'med_id[]';
    select.className = 'med-select';
    select.innerHTML = template || '<option value="">-- Seleccione artículo --</option>';
    const input = document.createElement('input');
    input.type = 'number';
    input.name = 'med_cant[]';
    input.className = 'med-cant';
    input.min = 1;
    input.placeholder = 'Cantidad';
    input.style.width = '120px';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'remove-med-btn';
    btn.textContent = 'Quitar';
    btn.onclick = function(){ removeMedRow(btn); };
    row.appendChild(select);
    row.appendChild(input);
    row.appendChild(btn);
    cont.appendChild(row);
}

function removeMedRow(btn){
    const row = btn.closest('.med-row');
    if (row) row.remove();
}

// Validación cliente antes de enviar (verifica stock)
form.addEventListener('submit', function(e){
    const idDiag = document.getElementById('id_diagnostico').value;
    if (!idDiag) {
        const selects = document.querySelectorAll('select[name="med_id[]"]');
        const cants = document.querySelectorAll('input[name="med_cant[]"]');
        for (let i = 0; i < selects.length; i++) {
            const sel = selects[i];
            const cant = parseInt(cants[i]?.value || '0', 10);
            const id = sel.value;
            if (!id && cant) { e.preventDefault(); alert('Debe seleccionar artículo para la cantidad indicada'); return false; }
            if (!id) continue;
            if (!cant || cant <= 0) { e.preventDefault(); alert('Ingrese cantidad válida para cada artículo seleccionado'); return false; }
            const stock = parseInt(sel.selectedOptions[0]?.dataset?.stock || '0', 10);
            if (stock < cant) { e.preventDefault(); alert('Stock insuficiente para: ' + sel.selectedOptions[0].text); return false; }
        }
    }
    return true;
});

// Cerrar modal al hacer clic fuera
window.onclick = function(e) {
    if (e.target === modal) closeModal();
};

// Ver medicamentos de un diagnóstico (AJAX)
const modalMeds = document.getElementById('modalMeds');
const medsList = document.getElementById('medsList');
function viewMeds(id_diag) {
    medsList.innerHTML = 'Cargando...';
    modalMeds.classList.add('show');
    fetch('get_diagnostico_medicamentos.php?id=' + encodeURIComponent(id_diag))
        .then(r => r.json())
        .then(json => {
            if (!json.ok) {
                medsList.innerHTML = '<div class="small">No hay medicamentos o ocurrió un error.</div>';
                return;
            }
            if (!json.medicamentos || json.medicamentos.length === 0) {
                medsList.innerHTML = '<div class="small">No se registraron medicamentos para este diagnóstico.</div>';
                return;
            }
            let html = '<ul style="list-style:none;padding:0;margin:0;">';
            json.medicamentos.forEach(m => {
                html += '<li style="padding:8px 0;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">' +
                        '<div><strong>' + escapeHtml(m.articulo_nombre || 'Artículo eliminado') + '</strong><div class="small">ID: ' + m.id_inventario + '</div></div>' +
                        '<div><span class="badge">' + m.cantidad + '</span></div></li>';
            });
            html += '</ul>';
            medsList.innerHTML = html;
        }).catch(() => {
            medsList.innerHTML = '<div class="small">Error al cargar medicamentos.</div>';
        });
}

function closeMedsModal() { modalMeds.classList.remove('show'); }

function escapeHtml(s) {
    if (!s) return '';
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
</script>

</body>
</html>