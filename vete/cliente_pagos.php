<?php
session_start();

// -----------------------------
// Permisos: debe ser cliente
// -----------------------------
$session_user_id = intval($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$session_rol = $_SESSION['rol'] ?? '';

if ($session_user_id <= 0 || strtolower($session_rol) !== 'cliente') {
    header('Location: login.php');
    exit;
}

$usuario = $_SESSION['usuario'] ?? ('user' . $session_user_id);

// -----------------------------
// CSRF token
// -----------------------------
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// -----------------------------
// Conexión PDO
// -----------------------------
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

// -----------------------------
// Helpers: detectar columnas / existencia de tabla
// -----------------------------
function getTableColumns(PDO $pdo, string $schema, string $table): array {
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table
    ");
    $stmt->execute([':schema' => $schema, ':table' => $table]);
    return array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function tableExists(PDO $pdo, string $schema, string $table): bool {
    $s = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table LIMIT 1");
    $s->execute([':schema' => $schema, ':table' => $table]);
    return (bool)$s->fetchColumn();
}

$pagosCols = getTableColumns($pdo, $dbname, 'pagos');
$citasCols = getTableColumns($pdo, $dbname, 'citas');

function colExists(array $cols, string $col): bool {
    return in_array(strtolower($col), $cols, true);
}

$hasCreatedBy = colExists($pagosCols, 'created_by');
$hasClientDirect = false;
$clientColName = null;
$clientCandidates = ['cliente_id','id_cliente','cliente','clienteid','user_id','id_usuario'];

foreach ($clientCandidates as $cand) {
    if (colExists($pagosCols, $cand)) {
        $hasClientDirect = true;
        $clientColName = $cand;
        break;
    }
}

// -----------------------------
// ACCIÓN: Cliente crea SOLO NUEVO pago (no editar, no eliminar)
// - Permitido: Tarjeta, Transferencia
// - Si ya existe pago para la cita => se rechaza (contactar recepción)
// -----------------------------
$flash_ok = $_SESSION['flash_ok'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_pago_client') {
    // Validar CSRF
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['flash_error'] = "Token CSRF inválido.";
        header("Location: cliente_pagos.php");
        exit;
    }

    // Leer inputs (no aceptamos id_pago para editar)
    $id_cita = !empty($_POST['id_cita']) ? intval($_POST['id_cita']) : 0;
    $monto = isset($_POST['monto']) ? (float) $_POST['monto'] : 0.0;
    $metodo = !empty($_POST['metodo_pago']) ? trim($_POST['metodo_pago']) : '';
    $fecha_pago = !empty($_POST['fecha_pago']) ? $_POST['fecha_pago'] : null;

    // Validaciones básicas
    if ($id_cita <= 0) {
        $_SESSION['flash_error'] = "Seleccione una cita válida.";
        header("Location: cliente_pagos.php");
        exit;
    }
    if ($monto <= 0) {
        $_SESSION['flash_error'] = "El monto debe ser mayor a 0.";
        header("Location: cliente_pagos.php");
        exit;
    }
    // Solo permitir Tarjeta y Transferencia para el pago por cliente
    $allowedMethods = ['Tarjeta','Transferencia'];
    if (!in_array($metodo, $allowedMethods, true)) {
        $_SESSION['flash_error'] = "Método de pago no válido. Solo 'Tarjeta' o 'Transferencia'.";
        header("Location: cliente_pagos.php");
        exit;
    }

    try {
        // Iniciar transacción
        $pdo->beginTransaction();

        // 1) Verificar que la cita pertenece al cliente
        $citasClientCandidates = ['id_cliente','cliente_id','id_usuario','user_id'];
        $citaClientCol = null;
        foreach ($citasClientCandidates as $cand) {
            if (colExists($citasCols, $cand)) {
                $citaClientCol = $cand;
                break;
            }
        }
        if (!$citaClientCol) {
            throw new Exception("La tabla 'citas' no tiene una columna para asociar cliente. Contacte al administrador.");
        }

        // Bloquear la cita
        $stmt = $pdo->prepare("SELECT * FROM citas WHERE id_cita = ? FOR UPDATE");
        $stmt->execute([$id_cita]);
        $cita = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cita) {
            throw new Exception("Cita no encontrada.");
        }
        // Verificar propietario
        $clienteIdInCita = intval($cita[$citaClientCol] ?? 0);
        if ($clienteIdInCita !== $session_user_id) {
            throw new Exception("No puede realizar pagos para una cita que no le pertenece.");
        }

        $cita_estado = $cita['estado'] ?? null;
        $id_servicio = $cita['id_servicio'] ?? null;

        // 2) Si no se proporcionó monto y hay servicio, obtener precio
        if ($monto <= 0 && !empty($id_servicio)) {
            $s = $pdo->prepare("SELECT precio FROM servicios WHERE id_servicio = ? LIMIT 1");
            $s->execute([$id_servicio]);
            $srv = $s->fetch(PDO::FETCH_ASSOC);
            if ($srv && isset($srv['precio'])) $monto = (float) $srv['precio'];
            $s = null;
        }

        // 3) Verificar si ya existe pago para la cita (NO permitimos editar ni crear duplicados)
        $chk = $pdo->prepare("SELECT id_pago FROM pagos WHERE id_cita = ? LIMIT 1 FOR UPDATE");
        $chk->execute([$id_cita]);
        $exists = $chk->fetch(PDO::FETCH_ASSOC);
        if ($exists) {
            // Si ya hay pago, no permitimos que el cliente cree/edite. Debe contactar recepción.
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Ya existe un pago registrado para esta cita. Si necesita asistencia contacte a la recepción.";
            header("Location: cliente_pagos.php");
            exit;
        }

        // 4) Insertar nuevo pago (solo INSERT)
        $fecha_db = $fecha_pago ? $fecha_pago . ' 00:00:00' : date('Y-m-d H:i:s');

        // Insertar considerando si la tabla pagos contiene columna para cliente o created_by
        if ($hasClientDirect && $clientColName) {
            if ($hasCreatedBy) {
                // columnas: id_cita, monto, metodo_pago, fecha_pago, cliente_col, created_by
                $ins = $pdo->prepare("INSERT INTO pagos (id_cita, monto, metodo_pago, fecha_pago, {$clientColName}, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$id_cita, $monto, $metodo, $fecha_db, $session_user_id, $session_user_id]);
            } else {
                // columnas: id_cita, monto, metodo_pago, fecha_pago, cliente_col
                $ins = $pdo->prepare("INSERT INTO pagos (id_cita, monto, metodo_pago, fecha_pago, {$clientColName}) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$id_cita, $monto, $metodo, $fecha_db, $session_user_id]);
            }
        } else {
            if ($hasCreatedBy) {
                // columnas: id_cita, monto, metodo_pago, fecha_pago, created_by
                $ins = $pdo->prepare("INSERT INTO pagos (id_cita, monto, metodo_pago, fecha_pago, created_by) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$id_cita, $monto, $metodo, $fecha_db, $session_user_id]);
            } else {
                // columnas: id_cita, monto, metodo_pago, fecha_pago
                // CORREGIDO: 4 placeholders y 4 valores
                $ins = $pdo->prepare("INSERT INTO pagos (id_cita, monto, metodo_pago, fecha_pago) VALUES (?, ?, ?, ?)");
                $ins->execute([$id_cita, $monto, $metodo, $fecha_db]);
            }
        }
        $pago_id = $pdo->lastInsertId();

        // 5) Guardar metadatos (pagos_meta) si existe (last4, referencia, etc.)
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

        // 6) Actualizar estado de la cita a 'Completada' si procede
        if (strtolower((string)$cita_estado) !== 'completada') {
            $upc = $pdo->prepare("UPDATE citas SET estado = ? WHERE id_cita = ?");
            $upc->execute(['Completada', $id_cita]);
        }

        // 7) Auditoría si existe tabla pagos_auditoria
        if (tableExists($pdo, $dbname, 'pagos_auditoria')) {
            $detalle = sprintf('monto=%01.2f, metodo=%s, usuario=%s', $monto, $metodo, $usuario);
            $aud = $pdo->prepare("INSERT INTO pagos_auditoria (id_pago, id_cita, id_usuario, usuario, accion, detalle, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $aud->execute([$pago_id, $id_cita, $session_user_id, $usuario, 'creado_por_cliente', $detalle]);
        }

        $pdo->commit();

        $_SESSION['flash_ok'] = "Pago registrado correctamente.";
        header("Location: cliente_pagos.php");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("cliente_save_pago error: " . $e->getMessage());
        $_SESSION['flash_error'] = "Ocurrió un error procesando su pago. Contacte a la recepción si el problema persiste.";
        header("Location: cliente_pagos.php");
        exit;
    }
}

// -----------------------------
// Cargar pagos del cliente (lectura)
// -----------------------------
$pagos = [];
$error_db = null;
try {
    if ($hasClientDirect && $clientColName) {
        $cols = array_map(function($c){ return "p.`$c`"; }, $pagosCols);
        $sql = "SELECT " . implode(', ', $cols) . " FROM `pagos` p WHERE p.`$clientColName` = :cid ORDER BY ";
        if (in_array('fecha_pago', $pagosCols, true)) $sql .= "p.`fecha_pago` DESC, ";
        if (in_array('id_pago', $pagosCols, true)) $sql .= "p.`id_pago` DESC";
        else $sql .= "1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cid' => $session_user_id]);
        $pagos = $stmt->fetchAll();
    } elseif (in_array('id_cita', $pagosCols, true)) {
        $cols = array_map(function($c){ return "p.`$c`"; }, $pagosCols);
        $sql = "SELECT " . implode(', ', $cols) . "
                FROM `pagos` p
                LEFT JOIN `citas` c ON p.`id_cita` = c.`id_cita`
                WHERE c.`id_cliente` = :cid
                ORDER BY ";
        if (in_array('fecha_pago', $pagosCols, true)) $sql .= "p.`fecha_pago` DESC, ";
        if (in_array('id_pago', $pagosCols, true)) $sql .= "p.`id_pago` DESC";
        else $sql .= "1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cid' => $session_user_id]);
        $pagos = $stmt->fetchAll();
    } else {
        $error_db = "No fue posible determinar la relación entre pagos y clientes en la base de datos. Contacta al administrador.";
    }
} catch (Exception $e) {
    error_log("cliente_pagos_list error: " . $e->getMessage());
    $error_db = "Ocurrió un problema al cargar tus pagos. Contacta al administrador si el problema persiste.";
}

// -----------------------------
// Cargar citas del cliente para permitir seleccionar y pagar
// -----------------------------
$citas = [];
try {
    $citaCols = $citasCols; // ya en minúsculas
    $select = [];
    foreach (['id_cita', 'fecha', 'id_servicio', 'estado'] as $col) {
        if (colExists($citaCols, $col)) $select[] = "`$col`";
    }
    if (!in_array('`id_cita`', $select, true)) $select[] = "`id_cita`";

    $citaClientCol = null;
    foreach (['id_cliente','cliente_id','id_usuario','user_id'] as $cand) {
        if (colExists($citaCols, $cand)) {
            $citaClientCol = $cand;
            break;
        }
    }
    if ($citaClientCol) {
        $where = " WHERE `$citaClientCol` = :cid ";
        if (colExists($citaCols, 'estado')) {
            $where .= " AND (LOWER(`estado`) != 'anulado') ";
        }
        $sql = "SELECT " . implode(', ', $select) . " FROM `citas` $where ORDER BY ";
        if (in_array('fecha', $citaCols, true)) $sql .= "`fecha` DESC, ";
        $sql .= "`id_cita` DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cid' => $session_user_id]);
        $citas = $stmt->fetchAll();
    } else {
        $citas = [];
    }

    // Obtener precio del servicio si aplica
    if (!empty($citas)) {
        $serviceIds = [];
        foreach ($citas as $ci) {
            if (!empty($ci['id_servicio'])) $serviceIds[] = intval($ci['id_servicio']);
        }
        $prices = [];
        if (!empty($serviceIds)) {
            $in = implode(',', array_fill(0, count($serviceIds), '?'));
            $s = $pdo->prepare("SELECT id_servicio, precio FROM servicios WHERE id_servicio IN ($in)");
            $s->execute($serviceIds);
            foreach ($s->fetchAll() as $row) {
                $prices[intval($row['id_servicio'])] = $row['precio'];
            }
        }
        foreach ($citas as &$ci) {
            $ci['precio_servicio'] = $ci['id_servicio'] ? ($prices[intval($ci['id_servicio'])] ?? 0) : 0;
        }
        unset($ci);
    }

} catch (Exception $e) {
    error_log("cliente_citas error: " . $e->getMessage());
    // no morir; simplemente no mostrar select de citas
}

// -----------------------------
// Escapar para HTML
// -----------------------------
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Mis Pagos - Cliente</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{
  --secundario3:#E9A0A0;
  --primary:#E50F53;
  --bg:#f7f7f7;
  --card:#fff;
  --muted:#666;
  --shadow:0 8px 22px rgba(0,0,0,0.06);
  --sidebar-width:240px;
  --radius:10px;
}
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:var(--bg);color:#222}
.main-wrapper{margin-left: calc(var(--sidebar-width) + 28px); padding:28px; max-width:1200px}
h1{color:var(--secundario3);margin:0 0 14px 0;font-size:1.8rem;border-bottom:3px solid var(--primary);padding-bottom:10px}
.table-card{background:var(--card);border-radius:var(--radius);padding:14px;box-shadow:var(--shadow);overflow:auto;}
.table{width:100%;border-collapse:collapse;min-width:720px}
.table th{position:sticky;top:0;background:linear-gradient(180deg,var(--secundario3),rgba(233,160,160,0.92));color:#111;padding:12px 10px;text-transform:uppercase;font-size:0.78rem}
.table td{padding:12px 10px;border-bottom:1px solid rgba(0,0,0,0.06);text-align:center;font-size:0.95rem;color:#333}
.table tbody tr:nth-child(even){background:#fff}
.table tbody tr:nth-child(odd){background:#fffafc}
.table tbody tr:hover{background:#fff0f5}
.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:700;color:#fff;font-size:0.85rem}
.badge.pendiente{background:#d98888}
.badge.pagado{background:#3aa76d}
.badge.anulado{background:#6c6c6c}
.no-data{color:var(--muted);padding:18px;margin:0;text-align:center}
.info-box{background:#fff;padding:12px;border-radius:8px;margin-bottom:12px;border:1px solid rgba(0,0,0,0.04)}
.top-add-btn{background:var(--secundario3);color:#fff;padding:10px 16px;border-radius:8px;border:none;cursor:pointer;font-weight:700}
.form-box{background:var(--card);padding:18px;border-radius:10px;box-shadow:var(--shadow);max-width:680px;margin-top:14px}
.form-box label{display:block;margin-top:10px;font-weight:700}
.form-box input,.form-box select{width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;margin-top:6px}
.row{display:flex;gap:12px}
.col{flex:1}
.small-note{font-size:0.9rem;color:#666;margin-top:6px}
@media (max-width:900px){
  .main-wrapper{margin-left:18px;margin-right:18px;padding:18px}
  .row{flex-direction:column}
}
</style>
</head>
<body>

<?php include "sidebarCli.php"; ?>

<div class="main-wrapper" role="main" aria-labelledby="pageTitle">
  <h1 id="pageTitle">Mis Pagos</h1>

  <?php if ($flash_ok): ?>
    <div class="info-box" role="status" style="border-left:4px solid #3aa76d;">
      <?php echo h($flash_ok); ?>
    </div>
  <?php endif; ?>
  <?php if ($flash_error): ?>
    <div class="info-box" role="alert" style="border-left:4px solid #a33;">
      <?php echo h($flash_error); ?>
    </div>
  <?php endif; ?>

  <?php if ($error_db): ?>
    <div class="info-box" role="status" style="border-left:4px solid #a33;">
      <strong>Información:</strong>
      <div style="color:#a33; margin-top:6px; font-size:0.95rem;"><?php echo h($error_db); ?></div>
    </div>
  <?php endif; ?>

  <!-- Formulario para que el cliente registre/pague -->
  <div class="form-box" aria-labelledby="payTitle">
    <h2 id="payTitle">Registrar pago (Tarjeta o Transferencia)</h2>
    <p class="small-note">Puedes registrar el pago de una cita tuya. No ingreses datos sensibles de tarjeta (PAN/CVV). En caso de pago con tarjeta, sólo el último 4 dígitos y referencia.</p>

    <?php if (empty($citas)): ?>
      <div class="no-data">No se encontraron citas disponibles para pagar.</div>
    <?php else: ?>
      <form method="POST" action="cliente_pagos.php" onsubmit="return validateForm();">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
        <input type="hidden" name="action" value="save_pago_client">

        <label for="id_cita">Cita a pagar</label>
        <select id="id_cita" name="id_cita" required onchange="onCitaChange();">
          <option value="">Seleccione...</option>
          <?php foreach ($citas as $c):
              $precio = isset($c['precio_servicio']) ? number_format((float)$c['precio_servicio'],2,'.','') : '';
              $fechaTxt = $c['fecha'] ?? '';
          ?>
            <option value="<?php echo h($c['id_cita']); ?>" data-price="<?php echo h($precio); ?>">
              Cita #<?php echo h($c['id_cita']); ?> <?php echo $fechaTxt ? '- ' . h($fechaTxt) : ''; ?> <?php echo $precio ? '- $' . h($precio) : ''; ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="row">
          <div class="col">
            <label for="monto">Monto</label>
            <input id="monto" name="monto" type="number" step="0.01" required>
          </div>
          <div class="col">
            <label for="fecha_pago">Fecha de pago</label>
            <input id="fecha_pago" name="fecha_pago" type="date" value="<?php echo date('Y-m-d'); ?>" required>
          </div>
        </div>

        <label for="metodo_pago">Método de pago</label>
        <select id="metodo_pago" name="metodo_pago" required onchange="toggleExtra();">
            <option value="">Seleccione...</option>
            <option value="Tarjeta">Tarjeta</option>
            <option value="Transferencia">Transferencia</option>
        </select>

        <div id="extra-pago" style="margin-top:8px;">
            <div id="tarjeta-fields" style="display:none;">
                <label>Nombre en la tarjeta (opcional)</label>
                <input type="text" name="card_holder" id="card_holder" placeholder="Titular">

                <label>Últimos 4 dígitos</label>
                <input type="text" name="card_last4" id="card_last4" pattern="\d{4}" maxlength="4" placeholder="1234">

                <label>Referencia / Código autorización (opcional)</label>
                <input type="text" name="card_auth" id="card_auth" placeholder="Ref. autorización">

                <label>Banco (opcional)</label>
                <input type="text" name="card_bank" id="card_bank" placeholder="Banco emisor">
                <div class="small-note">No envíe el número completo de la tarjeta ni el CVV. Estos datos no deben almacenarse en el sistema.</div>
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

        <div style="margin-top:12px;">
          <button type="submit" class="top-add-btn">Registrar Pago</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <!-- Listado de pagos del cliente (solo lectura) -->
  <div class="table-card" style="margin-top:18px;" aria-live="polite">
    <?php if (empty($pagos)): ?>
      <p class="no-data">No hay pagos registrados para tu cuenta.</p>
    <?php else: ?>
      <table class="table" role="table" aria-label="Listado de pagos">
        <thead>
          <tr>
            <th>ID</th>
            <th>Id Cita</th>
            <th>Fecha pago</th>
            <th>Método</th>
            <th>Monto</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pagos as $p): ?>
            <?php
              $id_pago = $p['id_pago'] ?? ($p['id'] ?? '—');
              $id_cita = $p['id_cita'] ?? ($p['cita_id'] ?? '—');
              $fecha_pago = $p['fecha_pago'] ?? $p['fecha'] ?? ($p['creado_at'] ?? '—');
              $metodo = $p['metodo_pago'] ?? $p['metodo'] ?? '—';
              $monto = isset($p['monto']) ? number_format((float)$p['monto'], 2, ',', '.') : '0,00';
              $estadoRaw = $p['estado'] ?? ($p['status'] ?? ( !empty($p['fecha_pago']) ? 'Pagado' : 'Pendiente' ));
              $estLower = strtolower($estadoRaw);
              $badgeClass = ($estLower === 'pagado') ? 'pagado' : (($estLower === 'anulado') ? 'anulado' : 'pendiente');
            ?>
            <tr>
              <td><?php echo h($id_pago); ?></td>
              <td><?php echo h($id_cita); ?></td>
              <td><?php echo h($fecha_pago); ?></td>
              <td><?php echo h($metodo); ?></td>
              <td>$<?php echo $monto; ?></td>
              <td><span class="badge <?php echo $badgeClass; ?>"><?php echo h($estadoRaw); ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<script>
// Rellenar monto al seleccionar cita (si la opción tiene data-price)
function onCitaChange() {
    var sel = document.getElementById('id_cita');
    var monto = document.getElementById('monto');
    var opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset && opt.dataset.price) {
        monto.value = opt.dataset.price || '';
    }
}

// Mostrar campos extra según método
function toggleExtra() {
    var metodo = document.getElementById('metodo_pago').value;
    document.getElementById('tarjeta-fields').style.display = metodo === 'Tarjeta' ? 'block' : 'none';
    document.getElementById('transfer-fields').style.display = metodo === 'Transferencia' ? 'block' : 'none';
}

// Validaciones cliente antes de enviar
function validateForm() {
    var metodo = document.getElementById('metodo_pago').value;
    if (metodo === 'Tarjeta') {
        var last4 = document.getElementById('card_last4').value.trim();
        if (last4 && !/^\d{4}$/.test(last4)) {
            alert('Últimos 4 dígitos no válidos.');
            return false;
        }
    }
    var montoVal = parseFloat(document.getElementById('monto').value);
    if (isNaN(montoVal) || montoVal <= 0) {
        alert('Ingrese un monto válido mayor a 0.');
        return false;
    }
    // evitar métodos no permitidos por manipulación (solo double-check en cliente)
    var allowed = ['Tarjeta','Transferencia'];
    if (allowed.indexOf(metodo) === -1) {
        alert('Método de pago no permitido.');
        return false;
    }
    return true;
}

document.addEventListener('DOMContentLoaded', function(){
    toggleExtra();
});
</script>

</body>
</html>