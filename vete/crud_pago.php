<?php
session_start();
/**
 * CRUD de Pagos — Recepcionista solo registra (en efectivo), sin advertencia sobre created_by.
 * - Recepcionista: solo puede registrar pagos (método Efectivo). No editar ni anular.
 * - Cliente: puede registrar pagos (Tarjeta/Transferencia) y ver su historial; no editar ni anular.
 * - Privilegiados (admin/finanzas): pueden ver/crear/editar/anular.
 *
 * Cambios solicitados:
 * - No mostrar la advertencia "la tabla 'pagos' no contiene 'created_by'".
 * - Mostrar botón "Registrar Pago" en la parte superior que abre el formulario/modal.
 * - Mostrar la tabla de pagos con el mismo layout que el resto de vistas.
 *
 * NOTA: Se supone que los includes "sidebarRecep.php" y "sidebarCli.php" existen.
 */

// -------------------- Config / sesión --------------------
$session_user_id = intval($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$session_rol = $_SESSION['rol'] ?? '';
$session_usuario = $_SESSION['usuario'] ?? ('user' . $session_user_id);

if ($session_user_id <= 0) {
    header('Location: login.php');
    exit;
}

$rol_norm = strtolower($session_rol);
$is_recepcionista = ($rol_norm === 'recepcionista');
$privileged_roles = ['admin','administrador','finanzas','contabilidad','contador'];
$is_privileged = in_array($rol_norm, $privileged_roles, true);
$is_cliente = ($rol_norm === 'cliente');

if (!($is_privileged || $is_recepcionista || $is_cliente)) {
    header('Location: login.php');
    exit;
}

// CSRF
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// -------------------- Conexión PDO --------------------
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "veterinaria_mupe";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Error de conexión: " . htmlspecialchars($e->getMessage()));
}

// -------------------- Helpers --------------------
function tableExists(PDO $pdo, string $schema, string $table): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table LIMIT 1");
    $stmt->execute([':schema' => $schema, ':table' => $table]);
    return (bool)$stmt->fetchColumn();
}
function getColumns(PDO $pdo, string $schema, string $table): array {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table");
    $stmt->execute([':schema' => $schema, ':table' => $table]);
    return array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
function colExists(array $cols, string $col): bool {
    return in_array(strtolower($col), $cols, true);
}
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// Detectar esquema/columnas
$pagosExists = tableExists($pdo, $dbname, 'pagos');
$pagosCols = $pagosExists ? getColumns($pdo, $dbname, 'pagos') : [];
$citasCols = tableExists($pdo, $dbname, 'citas') ? getColumns($pdo, $dbname, 'citas') : [];

$hasCreatedBy = colExists($pagosCols, 'created_by');

// detectar columna cliente directa si existe
$clientColName = null;
foreach (['cliente_id','id_cliente','cliente','clienteid','user_id','id_usuario'] as $cand) {
    if (colExists($pagosCols, $cand)) { $clientColName = $cand; break; }
}

// -------------------- POST Actions --------------------
// Solo se permite crear pagos para recepcionista/cliente; edición/anulación sólo para privilegiados.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['flash_error'] = "Token CSRF inválido.";
        header("Location: crud_pago.php");
        exit;
    }

    // ANULAR (solo privileged) - mantenido por si admin lo usa, pero recepcionista no verá botón
    if ($action === 'anular_pago') {
        if (! $is_privileged) {
            $_SESSION['flash_error'] = "No tienes permiso para anular pagos.";
            header("Location: crud_pago.php");
            exit;
        }
        $id = intval($_POST['anular_id'] ?? 0);
        $reason = trim($_POST['anular_reason'] ?? '');
        if ($id <= 0) { $_SESSION['flash_error'] = "Pago inválido."; header("Location: crud_pago.php"); exit; }
        if ($reason === '') { $_SESSION['flash_error'] = "Debes indicar un motivo."; header("Location: crud_pago.php"); exit; }

        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT id_pago, id_cita FROM pagos WHERE id_pago = ? LIMIT 1 FOR UPDATE");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) throw new Exception("Pago no encontrado.");

            // Preferimos marcar estado/deleted_* si existen
            $updates = [];
            $params = [];
            if (colExists($pagosCols, 'estado')) { $updates[] = "estado = ?"; $params[] = 'Anulado'; }
            if (colExists($pagosCols, 'deleted_by')) { $updates[] = "deleted_by = ?"; $params[] = $session_user_id; }
            if (colExists($pagosCols, 'deleted_at')) { $updates[] = "deleted_at = NOW()"; }
            if (colExists($pagosCols, 'deleted_reason')) { $updates[] = "deleted_reason = ?"; $params[] = $reason; }

            if (!empty($updates)) {
                $sql = "UPDATE pagos SET " . implode(', ', $updates) . " WHERE id_pago = ?";
                $params[] = $id;
                $pdo->prepare($sql)->execute($params);
            }

            if (tableExists($pdo, $dbname, 'pagos_auditoria')) {
                $aud = $pdo->prepare("INSERT INTO pagos_auditoria (id_pago, id_cita, id_usuario, usuario, accion, detalle, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $detalle = "anulado por {$session_usuario}. motivo: " . substr($reason,0,1000);
                $aud->execute([$id, $row['id_cita'] ?? null, $session_user_id, $session_usuario, 'anulado', $detalle]);
            }

            $pdo->commit();
            $_SESSION['flash_ok'] = "Pago anulado correctamente.";
            header("Location: crud_pago.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("anular_pago error: " . $e->getMessage());
            $_SESSION['flash_error'] = "Error al anular el pago.";
            header("Location: crud_pago.php");
            exit;
        }
    }

    // GUARDAR PAGO (crear o privileged editar)
    if ($action === 'save_pago') {
        $id_pago = !empty($_POST['id_pago']) ? intval($_POST['id_pago']) : null;
        $id_cita = !empty($_POST['id_cita']) ? intval($_POST['id_cita']) : 0;
        $monto = isset($_POST['monto']) ? (float) $_POST['monto'] : 0.0;
        $metodo = !empty($_POST['metodo_pago']) ? trim($_POST['metodo_pago']) : '';
        $fecha_pago = !empty($_POST['fecha_pago']) ? $_POST['fecha_pago'] : null;

        // Validaciones mínimas
        if ($id_cita <= 0) { $_SESSION['flash_error'] = "Seleccione una cita válida."; header("Location: crud_pago.php"); exit; }
        if ($monto <= 0) { $_SESSION['flash_error'] = "El monto debe ser mayor a 0."; header("Location: crud_pago.php"); exit; }

        // Enforce receptionist restrictions:
        if ($is_recepcionista) {
            // Recepcionista no puede editar
            if ($id_pago) {
                $_SESSION['flash_error'] = "No tienes permiso para editar pagos.";
                header("Location: crud_pago.php"); exit;
            }
            // Recepcionista solo puede registrar Efectivo
            if ($metodo !== 'Efectivo') {
                $_SESSION['flash_error'] = "Como Recepcionista solo puedes registrar pagos en efectivo.";
                header("Location: crud_pago.php"); exit;
            }
        }
        // Cliente restrictions
        if ($is_cliente && $id_pago) {
            $_SESSION['flash_error'] = "No tienes permiso para editar pagos.";
            header("Location: crud_pago.php"); exit;
        }
        if ($is_cliente && !in_array($metodo, ['Tarjeta','Transferencia'], true) && !$is_privileged) {
            $_SESSION['flash_error'] = "Método no permitido para clientes.";
            header("Location: crud_pago.php"); exit;
        }

        try {
            $pdo->beginTransaction();

            // Bloquear cita
            $stc = $pdo->prepare("SELECT * FROM citas WHERE id_cita = ? FOR UPDATE");
            $stc->execute([$id_cita]);
            $cita = $stc->fetch();
            if (!$cita) throw new Exception("Cita no encontrada.");

            // If client, verify the cita belongs to them
            if ($is_cliente) {
                $cand = null;
                foreach (['id_cliente','cliente_id','id_usuario','user_id'] as $c) if (colExists($citasCols, $c)) { $cand = $c; break; }
                if (!$cand) throw new Exception("No hay columna cliente en citas.");
                if (intval($cita[$cand] ?? 0) !== $session_user_id) throw new Exception("La cita no pertenece al cliente.");
            }

            // check existing payment for the cita
            $chk = $pdo->prepare("SELECT id_pago FROM pagos WHERE id_cita = ? LIMIT 1 FOR UPDATE");
            $chk->execute([$id_cita]);
            $exists = $chk->fetch();

            if ($id_pago) {
                // Only privileged can edit
                if (! $is_privileged) throw new Exception("No tienes permiso para editar pagos.");
                $fecha_db = $fecha_pago ? $fecha_pago . ' 00:00:00' : date('Y-m-d H:i:s');
                $upd = $pdo->prepare("UPDATE pagos SET id_cita = ?, monto = ?, metodo_pago = ?, fecha_pago = ? WHERE id_pago = ?");
                $upd->execute([$id_cita, $monto, $metodo, $fecha_db, $id_pago]);
                $pago_id = $id_pago;
                $accion = 'actualizado';
            } else {
                // Create
                if ($exists) {
                    // If already exists and not privileged, reject; privileged may update
                    if ($is_privileged) {
                        $pago_id = intval($exists['id_pago']);
                        $fecha_db = $fecha_pago ? $fecha_pago . ' 00:00:00' : date('Y-m-d H:i:s');
                        $u2 = $pdo->prepare("UPDATE pagos SET monto = ?, metodo_pago = ?, fecha_pago = ? WHERE id_pago = ?");
                        $u2->execute([$monto, $metodo, $fecha_db, $pago_id]);
                        $accion = 'actualizado';
                    } else {
                        throw new Exception("Ya existe un pago para esta cita. Contacte al administrador o recepción.");
                    }
                } else {
                    $fecha_db = $fecha_pago ? $fecha_pago . ' 00:00:00' : date('Y-m-d H:i:s');
                    if ($hasCreatedBy) {
                        $ins = $pdo->prepare("INSERT INTO pagos (id_cita, monto, metodo_pago, fecha_pago, created_by) VALUES (?, ?, ?, ?, ?)");
                        $ins->execute([$id_cita, $monto, $metodo, $fecha_db, $session_user_id]);
                    } else {
                        // Si no existe created_by, insertamos sin ella (sin avisos)
                        $ins = $pdo->prepare("INSERT INTO pagos (id_cita, monto, metodo_pago, fecha_pago) VALUES (?, ?, ?, ?)");
                        $ins->execute([$id_cita, $monto, $metodo, $fecha_db]);
                    }
                    $pago_id = $pdo->lastInsertId();
                    $accion = 'creado';
                }
            }

            // pagos_meta
            if (tableExists($pdo, $dbname, 'pagos_meta')) {
                $insMeta = $pdo->prepare("INSERT INTO pagos_meta (id_pago, meta_key, meta_value) VALUES (?, ?, ?)");
                if ($metodo === 'Tarjeta') {
                    $card_holder = trim($_POST['card_holder'] ?? '');
                    $card_last4 = trim($_POST['card_last4'] ?? '');
                    $card_auth = trim($_POST['card_auth'] ?? '');
                    $card_bank = trim($_POST['card_bank'] ?? '');
                    if ($card_holder !== '') $insMeta->execute([$pago_id, 'card_holder', $card_holder]);
                    if ($card_last4 !== '') $insMeta->execute([$pago_id, 'card_last4', $card_last4]);
                    if ($card_auth !== '') $insMeta->execute([$pago_id, 'card_auth', $card_auth]);
                    if ($card_bank !== '') $insMeta->execute([$pago_id, 'card_bank', $card_bank]);
                } elseif ($metodo === 'Transferencia') {
                    $transfer_bank = trim($_POST['transfer_bank'] ?? '');
                    $transfer_ref = trim($_POST['transfer_ref'] ?? '');
                    $transfer_holder = trim($_POST['transfer_holder'] ?? '');
                    if ($transfer_bank !== '') $insMeta->execute([$pago_id, 'transfer_bank', $transfer_bank]);
                    if ($transfer_ref !== '') $insMeta->execute([$pago_id, 'transfer_ref', $transfer_ref]);
                    if ($transfer_holder !== '') $insMeta->execute([$pago_id, 'transfer_holder', $transfer_holder]);
                }
            }

            // update cita estado
            if (isset($cita['estado']) && strtolower((string)$cita['estado']) !== 'completada') {
                $upc = $pdo->prepare("UPDATE citas SET estado = ? WHERE id_cita = ?");
                $upc->execute(['Completada', $id_cita]);
            }

            // auditoría
            if (tableExists($pdo, $dbname, 'pagos_auditoria')) {
                $detalle = sprintf('monto=%01.2f, metodo=%s, usuario=%s', $monto, $metodo, $session_usuario);
                $aud = $pdo->prepare("INSERT INTO pagos_auditoria (id_pago, id_cita, id_usuario, usuario, accion, detalle, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $aud->execute([$pago_id, $id_cita, $session_user_id, $session_usuario, $accion ?? 'modificado', $detalle]);
            }

            $pdo->commit();
            $_SESSION['flash_ok'] = "Pago {$accion} correctamente.";
            header("Location: crud_pago.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("save_pago error: " . $e->getMessage());
            $_SESSION['flash_error'] = "Ocurrió un error procesando el pago. Contacte al administrador.";
            header("Location: crud_pago.php");
            exit;
        }
    }
}

// -------------------- Lectura / Listados --------------------
// Citas para select
$citas = [];
try {
    if ($is_cliente) {
        $cand = null;
        foreach (['id_cliente','cliente_id','id_usuario','user_id'] as $c) if (colExists($citasCols, $c)) { $cand = $c; break; }
        if ($cand) {
            $sql = "SELECT id_cita" . (colExists($citasCols, 'fecha') ? ", fecha" : "") . (colExists($citasCols, 'id_servicio') ? ", id_servicio" : "") . " FROM citas WHERE `$cand` = :cid ORDER BY id_cita DESC";
            $st = $pdo->prepare($sql);
            $st->execute([':cid' => $session_user_id]);
            $citas = $st->fetchAll();
        }
    } else {
        $citas = $pdo->query("SELECT id_cita" . (colExists($citasCols, 'fecha') ? ", fecha" : "") . (colExists($citasCols, 'id_servicio') ? ", id_servicio" : "") . " FROM citas ORDER BY id_cita DESC LIMIT 500")->fetchAll();
    }
} catch (Exception $e) {
    error_log("list_citas error: " . $e->getMessage());
    $citas = [];
}

// Pagos: según rol (sin mostrar advertencia sobre created_by)
$pagos = [];
try {
    if ($is_privileged) {
        $pagos = $pdo->query("SELECT * FROM pagos ORDER BY id_pago DESC LIMIT 1000")->fetchAll();
    } elseif ($is_recepcionista) {
        if ($hasCreatedBy) {
            $st = $pdo->prepare("SELECT * FROM pagos WHERE created_by = ? ORDER BY id_pago DESC");
            $st->execute([$session_user_id]);
            $pagos = $st->fetchAll();
        } else {
            // Si no existe created_by mostramos todo para compatibilidad, pero NO mostramos advertencia
            $pagos = $pdo->query("SELECT * FROM pagos ORDER BY id_pago DESC LIMIT 200")->fetchAll();
        }
    } elseif ($is_cliente) {
        if ($clientColName) {
            $st = $pdo->prepare("SELECT * FROM pagos WHERE {$clientColName} = ? ORDER BY id_pago DESC");
            $st->execute([$session_user_id]);
            $pagos = $st->fetchAll();
        } elseif (in_array('id_cita', $pagosCols, true)) {
            $st = $pdo->prepare("SELECT p.* FROM pagos p LEFT JOIN citas c ON p.id_cita = c.id_cita WHERE c.id_cliente = ? ORDER BY p.id_pago DESC");
            $st->execute([$session_user_id]);
            $pagos = $st->fetchAll();
        } else {
            $pagos = [];
        }
    }
} catch (Exception $e) {
    error_log("list_pagos error: " . $e->getMessage());
    $pagos = [];
}

// -------------------- No editar for recepcionista/client (block edit GET) --------------------
$pago_editar = null;
if (isset($_GET['editar']) && $_GET['editar'] !== '') {
    $editId = intval($_GET['editar']);
    if ($editId > 0) {
        if (! $is_privileged) {
            $_SESSION['flash_error'] = "No tienes permiso para editar pagos.";
            header("Location: crud_pago.php");
            exit;
        }
        $st = $pdo->prepare("SELECT * FROM pagos WHERE id_pago = ? LIMIT 1");
        $st->execute([$editId]);
        $pago_editar = $st->fetch();
    }
}

// -------------------- Flash extract --------------------
$flash_ok = $_SESSION['flash_ok'] ?? null;
$flash_err = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Pagos - Registrar</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{ --rosa-claro:#E9A0A0; --rosa-fuerte:#E50F53; --fondo:#f7f7f7; --tabla:#fff; --muted:#666; --shadow:0 8px 24px rgba(0,0,0,0.06); --radius:10px; --sidebar-width:280px }
*{box-sizing:border-box}
body{margin:0;font-family:Inter,Arial,Helvetica,sans-serif;background:var(--fondo);color:#222}
.container{margin-left:calc(var(--sidebar-width));padding:24px;max-width:1200px}
h1{color:var(--rosa-claro);margin:0 0 10px 0;font-size:1.9rem;border-bottom:3px solid var(--rosa-fuerte);padding-bottom:10px}
.flash{padding:10px 14px;border-radius:8px;margin-bottom:12px}
.flash.ok{background:#e6ffef;border:1px solid #b9f2d1;color:#0b6a3a}
.flash.err{background:#ffecec;border:1px solid #f0b7b7;color:#7a1414}
.top-add{background:var(--rosa-claro);color:#fff;padding:10px 14px;border-radius:8px;border:none;cursor:pointer;font-weight:800;margin-bottom:12px}
.table-wrap{background:var(--tabla);border-radius:var(--radius);padding:12px;box-shadow:var(--shadow);overflow:auto}
.table{width:100%;border-collapse:collapse;min-width:800px}
.table th{position:sticky;top:0;background:linear-gradient(180deg,var(--rosa-claro),rgba(233,160,160,0.92));padding:10px;font-size:0.78rem;text-transform:uppercase;color:#111}
.table td{padding:10px;border-bottom:1px solid rgba(0,0,0,0.04);text-align:center}
.form-box{background:var(--tabla);padding:16px;border-radius:10px;box-shadow:var(--shadow);max-width:720px;margin:12px 0;display:none}
label{display:block;margin-top:8px;font-weight:700}
input,select,textarea{width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;margin-top:6px}
.small-note{font-size:0.9rem;color:var(--muted)}
.badge{display:inline-block;padding:6px 10px;border-radius:999px;color:#fff;font-weight:700}
.badge.pagado{background:#3aa76d} .badge.pendiente{background:#d98888} .badge.anulado{background:#6c6c6c}
.action-btn{display:inline-block;padding:7px 12px;border-radius:8px;color:#fff;text-decoration:none;font-weight:700;margin:2px;border:none;cursor:pointer}
.edit-btn{background:var(--rosa-fuerte)} .anular-btn{background:#c04848}
@media (max-width:900px){ .container{margin-left:14px;padding:12px} .table{min-width:600px} }
</style>
</head>
<body>

<?php
if ($is_cliente) include "sidebarCli.php";
else include "sidebarRecep.php";
?>

<div class="container" role="main">
  <h1>Gestión de Pagos</h1>

  <?php if ($flash_ok): ?><div class="flash ok"><?php echo h($flash_ok); ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash err"><?php echo h($flash_err); ?></div><?php endif; ?>

  <!-- Botón registrar pago -->
  <button class="top-add" onclick="openForm();">Registrar Pago</button>

  <!-- Formulario (oculto por defecto, se abre con el botón) -->
  <div class="form-box" id="formBox" aria-labelledby="formTitle">
    <h2 id="formTitle">Registrar Pago</h2>
    <p class="small-note">
      <?php if ($is_recepcionista): ?>
        Como Recepcionista puedes registrar pagos únicamente en EFECTIVO.
      <?php elseif ($is_cliente): ?>
        Como Cliente puedes registrar pagos con Tarjeta o Transferencia.
      <?php else: ?>
        Rol privilegiado: puedes crear/editar/anular pagos.
      <?php endif; ?>
    </p>

    <form method="POST" action="crud_pago.php" onsubmit="return validateForm();">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
      <input type="hidden" name="action" value="save_pago">
      <input type="hidden" name="id_pago" id="id_pago" value="<?php echo $pago_editar['id_pago'] ?? '' ?>">

      <label for="id_cita">Cita</label>
      <select id="id_cita" name="id_cita" required>
        <option value="">Seleccione...</option>
        <?php foreach ($citas as $c): ?>
          <option value="<?php echo h($c['id_cita']); ?>"><?php echo "Cita #".h($c['id_cita']) . (isset($c['fecha']) ? " - ".h($c['fecha']) : ""); ?></option>
        <?php endforeach; ?>
      </select>

      <label for="monto">Monto</label>
      <input id="monto" name="monto" type="number" step="0.01" required>

      <label for="metodo_pago">Método de pago</label>
      <select id="metodo_pago" name="metodo_pago" required onchange="toggleExtra();">
        <option value="">Seleccione...</option>
        <?php
          if ($is_recepcionista) {
              echo '<option value="Efectivo">Efectivo</option>';
          } elseif ($is_cliente) {
              echo '<option value="Tarjeta">Tarjeta</option><option value="Transferencia">Transferencia</option>';
          } else {
              echo '<option value="Efectivo">Efectivo</option><option value="Tarjeta">Tarjeta</option><option value="Transferencia">Transferencia</option>';
          }
        ?>
      </select>

      <div id="extra-pago" style="margin-top:8px;">
        <div id="tarjeta-fields" style="display:none;">
          <label>Nombre en la tarjeta (opcional)</label>
          <input type="text" name="card_holder" id="card_holder" placeholder="Titular">
          <label>Últimos 4 dígitos</label>
          <input type="text" name="card_last4" id="card_last4" pattern="\d{4}" maxlength="4" placeholder="1234">
          <label>Referencia / TX ID</label>
          <input type="text" name="card_auth" id="card_auth" placeholder="Referencia">
          <label>Banco (opcional)</label>
          <input type="text" name="card_bank" id="card_bank" placeholder="Banco">
          <div class="small-note">No almacenar PAN completo ni CVV.</div>
        </div>

        <div id="transfer-fields" style="display:none;">
          <label>Banco emisor</label>
          <input type="text" name="transfer_bank" id="transfer_bank" placeholder="Banco">
          <label>Referencia / Folio</label>
          <input type="text" name="transfer_ref" id="transfer_ref" placeholder="Referencia">
          <label>Nombre titular (opcional)</label>
          <input type="text" name="transfer_holder" id="transfer_holder" placeholder="Titular de cuenta">
        </div>
      </div>

      <label for="fecha_pago">Fecha de pago</label>
      <input id="fecha_pago" name="fecha_pago" type="date" value="<?php echo date('Y-m-d'); ?>" required>

      <div style="margin-top:12px;">
        <button type="submit" class="top-add">Guardar Pago</button>
        <button type="button" onclick="closeForm()" style="margin-left:8px;padding:10px 14px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer;">Cancelar</button>
      </div>
    </form>
  </div>

  <!-- Tabla de pagos (igual estilo que en las otras vistas) -->
  <div class="table-wrap" role="region" aria-label="Pagos" style="margin-top:16px;">
    <?php if (empty($pagos)): ?>
      <p class="small-note">No hay pagos para mostrar.</p>
    <?php else: ?>
      <table class="table" role="table" aria-label="Listado de pagos">
        <thead>
          <tr>
            <th>ID</th><th>ID Cita</th><th>Monto</th><th>Método</th><th>Fecha</th><th>Estado</th><th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pagos as $p):
            $estado = $p['estado'] ?? (!empty($p['fecha_pago']) ? 'Pagado' : 'Pendiente');
            $badge = strtolower($estado) === 'pagado' ? 'pagado' : (strtolower($estado) === 'anulado' ? 'anulado' : 'pendiente');
            // Only privileged can edit/anular; receptionist/client see 'Editar'/'Anular' disabled
            $canEdit = $is_privileged;
            $canAnular = $is_privileged;
          ?>
          <tr>
            <td><?php echo h($p['id_pago'] ?? $p['id'] ?? ''); ?></td>
            <td><?php echo h($p['id_cita'] ?? ''); ?></td>
            <td>$<?php echo isset($p['monto']) ? number_format((float)$p['monto'],2,',','.') : '0,00'; ?></td>
            <td><?php echo h($p['metodo_pago'] ?? $p['metodo'] ?? ''); ?></td>
            <td><?php echo h($p['fecha_pago'] ?? ''); ?></td>
            <td><span class="badge <?php echo $badge; ?>"><?php echo h($estado); ?></span></td>
            <td>
              <?php if ($canEdit): ?>
                <a class="action-btn edit-btn" href="crud_pago.php?editar=<?php echo h($p['id_pago']); ?>">Editar</a>
              <?php else: ?>
                <span style="color:#999;font-weight:700;margin-right:6px;">Editar</span>
              <?php endif; ?>

              <?php if ($canAnular): ?>
                <button class="action-btn anular-btn" onclick="openAnularModal(<?php echo h($p['id_pago']); ?>)">Anular</button>
              <?php else: ?>
                <span style="color:#999;font-weight:700;">Anular</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>

<!-- Modal anular (solo funcional para privileged) -->
<div id="modal-anular" style="display:none;position:fixed;inset:0;justify-content:center;align-items:center;background:rgba(0,0,0,0.5);z-index:2000;">
  <div style="background:#fff;padding:18px;border-radius:10px;max-width:520px;width:92%;box-shadow:0 10px 30px rgba(0,0,0,0.25);">
    <h3>Anular pago</h3>
    <p class="small-note">Indica el motivo de la anulación. Esta acción dejará registro en la auditoría.</p>
    <form id="anularForm" method="POST" action="crud_pago.php" onsubmit="return submitAnular();">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
      <input type="hidden" name="action" value="anular_pago">
      <input type="hidden" name="anular_id" id="anular_id" value="">
      <label for="anular_reason">Motivo</label>
      <textarea id="anular_reason" name="anular_reason" rows="4" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;"></textarea>
      <div style="margin-top:12px;display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeAnularModal()" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#eee;cursor:pointer;">Cancelar</button>
        <button type="submit" style="padding:8px 12px;border-radius:8px;border:none;background:#c04848;color:#fff;cursor:pointer;">Anular</button>
      </div>
    </form>
  </div>
</div>

<script>
function openForm() {
    document.getElementById('formBox').style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function closeForm() {
    document.getElementById('formBox').style.display = 'none';
    // reset form
    var f = document.querySelector('#formBox form');
    if (f) f.reset();
    toggleExtra();
}
function toggleExtra() {
    var metodo = document.getElementById('metodo_pago') ? document.getElementById('metodo_pago').value : '';
    document.getElementById('tarjeta-fields').style.display = metodo === 'Tarjeta' ? 'block' : 'none';
    document.getElementById('transfer-fields').style.display = metodo === 'Transferencia' ? 'block' : 'none';
}
function validateForm() {
    var metodoEl = document.getElementById('metodo_pago');
    if (!metodoEl) return true;
    var metodo = metodoEl.value;
    if (metodo === 'Tarjeta') {
        var last4 = document.getElementById('card_last4').value.trim();
        if (last4 && !/^\d{4}$/.test(last4)) {
            alert('Últimos 4 dígitos no válidos.');
            return false;
        }
    }
    var montoEl = document.getElementById('monto');
    if (!montoEl) return true;
    var monto = parseFloat(montoEl.value);
    if (isNaN(monto) || monto <= 0) {
        alert('Ingrese un monto válido mayor a 0.');
        return false;
    }
    return true;
}

function openAnularModal(id) {
    document.getElementById('anular_id').value = id;
    document.getElementById('anular_reason').value = '';
    document.getElementById('modal-anular').style.display = 'flex';
}
function closeAnularModal() {
    document.getElementById('modal-anular').style.display = 'none';
}
function submitAnular() {
    var reason = document.getElementById('anular_reason').value.trim();
    if (!reason) { alert('Ingrese un motivo'); return false; }
    return confirm('¿Confirmar anulación del pago? Esta acción será registrada.');
}

document.addEventListener('DOMContentLoaded', function(){ toggleExtra(); });
</script>

</body>
</html>