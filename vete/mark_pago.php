
<?php
session_start();

// Permisos
if (!isset($_SESSION['usuario']) || !in_array($_SESSION['rol'] ?? '', ['Recepcionista','Administrador'])) {
    http_response_code(403);
    echo "Acceso denegado.";
    exit;
}

// Método y CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Método no permitido.";
    exit;
}
if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(400);
    echo "Token CSRF inválido.";
    exit;
}

// incluir conexión
require_once 'db.php';
if (!$mysqli) {
    http_response_code(500);
    echo "Error de conexión a la BD.";
    exit;
}

$id_cita = isset($_POST['id_cita']) ? intval($_POST['id_cita']) : 0;
$monto   = isset($_POST['monto']) ? (float)$_POST['monto'] : null;
$metodo  = isset($_POST['metodo_pago']) ? trim($_POST['metodo_pago']) : 'Efectivo';
$id_pago = isset($_POST['id_pago']) ? intval($_POST['id_pago']) : 0;
$usuario_registro = $_SESSION['usuario'];
$id_usuario_registro = $_SESSION['id_usuario'] ?? null;

if ($id_cita <= 0) {
    http_response_code(400);
    echo "Falta id de la cita.";
    exit;
}

try {
    $mysqli->begin_transaction();

    // Bloquear la cita
    $stmt = $mysqli->prepare("SELECT id_cita, id_servicio, estado FROM citas WHERE id_cita = ? FOR UPDATE");
    $stmt->bind_param('i', $id_cita);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $cita_estado = $row['estado'];
        $id_servicio = $row['id_servicio'];
    } else {
        $stmt->close();
        $mysqli->rollback();
        http_response_code(404);
        echo "Cita no encontrada.";
        exit;
    }
    $stmt->close();

    // Si monto no viene, intentar buscar precio del servicio
    if ($monto === null) {
        if (!empty($id_servicio)) {
            $s = $mysqli->prepare("SELECT precio FROM servicios WHERE id_servicio = ? LIMIT 1");
            $s->bind_param('i', $id_servicio);
            $s->execute();
            $r = $s->get_result();
            $monto = ($rr = $r->fetch_assoc()) ? (float)$rr['precio'] : 0.00;
            $s->close();
        } else {
            $monto = 0.00;
        }
    }

    // Si id_pago viene -> actualizar, si no -> insertar
    if ($id_pago > 0) {
        $u = $mysqli->prepare("UPDATE pagos SET id_cita = ?, monto = ?, metodo_pago = ?, fecha_pago = NOW() WHERE id_pago = ?");
        $u->bind_param('idsi', $id_cita, $monto, $metodo, $id_pago);
        $u->execute();
        $accion = 'actualizado';
        $pago_id = $id_pago;
    } else {
        // comprobar si ya existe pago para la cita (idempotencia)
        $chk = $mysqli->prepare("SELECT id_pago FROM pagos WHERE id_cita = ? LIMIT 1 FOR UPDATE");
        $chk->bind_param('i', $id_cita);
        $chk->execute();
        $reschk = $chk->get_result();
        if ($rchk = $reschk->fetch_assoc()) {
            $pago_id = $rchk['id_pago'];
            $up = $mysqli->prepare("UPDATE pagos SET monto = ?, metodo_pago = ?, fecha_pago = NOW() WHERE id_pago = ?");
            $up->bind_param('dsi', $monto, $metodo, $pago_id);
            $up->execute();
            $accion = 'actualizado';
            $up->close();
        } else {
            $ins = $mysqli->prepare("INSERT INTO pagos (id_cita, monto, metodo_pago, fecha_pago) VALUES (?, ?, ?, NOW())");
            $ins->bind_param('ids', $id_cita, $monto, $metodo);
            $ins->execute();
            $pago_id = $mysqli->insert_id;
            $ins->close();
            $accion = 'creado';
        }
        $chk->close();
    }

    // Opcional: actualizar estado de la cita a 'Completada'
    if (strtolower(trim((string)$cita_estado)) !== 'completada') {
        $new = 'Completada';
        $upc = $mysqli->prepare("UPDATE citas SET estado = ? WHERE id_cita = ?");
        $upc->bind_param('si', $new, $id_cita);
        $upc->execute();
        $upc->close();
    }

    // Auditoría (si existe la tabla)
    $ra = $mysqli->query("SHOW TABLES LIKE 'pagos_auditoria'");
    if ($ra && $ra->num_rows) {
        $aud = $mysqli->prepare("INSERT INTO pagos_auditoria (id_pago, id_cita, id_usuario, usuario, accion, detalle, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $detalle = sprintf('monto=%01.2f, metodo=%s', $monto, $metodo);
        $aud->bind_param('iiisss', $pago_id, $id_cita, $id_usuario_registro, $usuario_registro, $accion, $detalle);
        $aud->execute();
        $aud->close();
    }

    $mysqli->commit();

    // Volver al CRUD (puedes cambiar la redirección)
    header("Location: crud_pago.php?cita={$id_cita}&resultado=ok&accion={$accion}");
    exit;

} catch (Exception $e) {
    if ($mysqli->errno) $mysqli->rollback();
    error_log("mark_pago error: " . $e->getMessage());
    http_response_code(500);
    echo "Error al registrar el pago.";
    exit;
}