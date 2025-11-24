<?php
session_start();

// Validar sesión y rol Cliente
if (!isset($_SESSION["usuario"]) || ($_SESSION["rol"] ?? '') !== "Cliente") {
    header("Location: login.php");
    exit;
}

$usuario_nombre = $_SESSION["usuario"] ?? '';
$session_user_id = intval($_SESSION['id_usuario'] ?? $_SESSION['user_id'] ?? 0);

// Conectar a la BD y obtener métricas y pagos pendientes
$pagos_pendientes = 0;
$total_pendiente = 0.00;
$pagos_list = []; // pagos pendientes para el modal
$db_error = null;

if ($session_user_id > 0) {
    $host = "localhost";
    $user = "root";
    $pass = "";
    $dbname = "veterinaria_mupe";
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Obtener pagos pendientes: dejamos como pendientes si fecha_pago IS NULL or empty
        $stmt = $pdo->prepare("
            SELECT p.id_pago, p.id_cita, p.monto, p.metodo_pago, p.fecha_pago,
                   COALESCE(c.fecha,'') AS fecha_cita, COALESCE(m.nombre, '') AS mascota
            FROM pagos p
            LEFT JOIN citas c ON p.id_cita = c.id_cita
            LEFT JOIN mascotas m ON c.id_mascota = m.id_mascota
            WHERE c.id_cliente = ? AND (p.fecha_pago IS NULL OR p.fecha_pago = '')
            ORDER BY p.id_pago DESC
        ");
        $stmt->execute([$session_user_id]);
        $pagos_list = $stmt->fetchAll();

        // métricas
        $stmt2 = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(p.monto),0) AS total
                                FROM pagos p
                                LEFT JOIN citas c ON p.id_cita = c.id_cita
                                WHERE c.id_cliente = ? AND (p.fecha_pago IS NULL OR p.fecha_pago = '')");
        $stmt2->execute([$session_user_id]);
        $r = $stmt2->fetch();
        $pagos_pendientes = (int)($r['cnt'] ?? 0);
        $total_pendiente = (float)($r['total'] ?? 0.0);

    } catch (PDOException $e) {
        error_log("cliente_panel error: " . $e->getMessage());
        $db_error = "No fue posible cargar información. Intenta más tarde.";
    }
} else {
    $db_error = "No se encontró id de usuario en sesión.";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel Cliente - MUPE</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="admin_panel.css">
<script src="https://kit.fontawesome.com/bbf0071ecd.js" crossorigin="anonymous"></script>
<style>
:root{
  --accent-1:#E9A0A0;
  --accent-2:#E50F53;
  --accent-2-dark:#b30c40;
  --bg:#f7f7f7;
  --card:#fff;
  --muted:#666;
  --shadow:0 10px 30px rgba(0,0,0,0.06);
  --radius:12px;
  --sidebar-width:300px;
  --gap:18px;
}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,Arial,Helvetica,sans-serif;background:var(--bg);color:#222;-webkit-font-smoothing:antialiased}
.main-wrapper{margin-left:calc(var(--sidebar-width));padding:28px;min-height:100vh}
.content{max-width:1160px;margin:0 auto}
.bienvenida h2{margin:0 0 8px 0;color:var(--accent-2);font-size:1.5rem}
.bienvenida p{margin:0 0 16px 0;color:#444}

/* Grid: pagos (left) y consejos (right) en desktop; apila en móvil */
.tarjetas-grid{
  display:grid;
  grid-template-columns: 1.4fr 1fr;
  gap:var(--gap);
  align-items:start;
  margin-top:20px;
}

/* Tarjeta */
.tarjeta{
  background:var(--card);
  border-radius:var(--radius);
  padding:18px;
  box-shadow:var(--shadow);
  display:flex;
  flex-direction:column;
  gap:12px;
  border-top:4px solid rgba(229,15,83,0.06);
}
.head{display:flex;gap:12px;align-items:center}
.icon{width:56px;height:56px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(180deg,rgba(229,15,83,0.06),rgba(233,160,160,0.03));color:var(--accent-2);font-size:22px}
h3{margin:0;font-size:1.05rem}
.lead{font-weight:800;font-size:1.4rem;margin:0}
.desc{margin:0;color:var(--muted)}
.small-note{font-size:0.92rem;color:var(--muted)}

.card-actions{margin-top:8px;display:flex;gap:10px;flex-wrap:wrap}
.btn{
  display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;background:var(--accent-1);color:#fff;text-decoration:none;font-weight:700;border:none;cursor:pointer;
}
.btn.secondary{background:#666}
.btn:hover{background:var(--accent-2-dark)}

/* Pagos list inside card */
.pagos-list{margin-top:8px;display:flex;flex-direction:column;gap:8px}
.pago-item{display:flex;justify-content:space-between;align-items:center;padding:10px;border-radius:10px;background:#fffafc;border:1px solid rgba(0,0,0,0.02)}
.pago-meta{display:flex;flex-direction:column;align-items:flex-start}
.pago-meta small{color:var(--muted)}

/* Consejos card */
.tarjeta.tall{min-height:200px}

/* Modal */
.modal-overlay{position:fixed;inset:0;display:none;justify-content:center;align-items:center;background:rgba(0,0,0,0.5);z-index:1200}
.modal-overlay.show{display:flex}
.form-box{background:var(--card);padding:20px;border-radius:12px;max-width:540px;width:94%;box-shadow:0 12px 36px rgba(0,0,0,0.18);border-top:4px solid var(--accent-2)}
.form-box label{display:block;margin-top:10px;font-weight:700}
.form-box input, .form-box select, .form-box textarea{width:100%;padding:10px;margin-top:8px;border-radius:8px;border:1px solid #ddd;box-sizing:border-box}
.form-actions{display:flex;gap:10px;margin-top:14px;justify-content:flex-end}

/* Responsive */
@media (max-width:980px){
  .tarjetas-grid{grid-template-columns:1fr;padding:0 12px}
  .main-wrapper{margin-left:18px;padding:18px}
  .icon{width:48px;height:48px}
}
</style>
</head>
<body>

<?php include_once __DIR__ . '/sidebarCli.php'; ?>

<div class="main-wrapper">
  <div class="content">
    <section class="bienvenida">
      <h2><i class="fa-solid fa-user"></i> Hola, <?php echo htmlspecialchars($usuario_nombre); ?></h2>
      <p>Resumen rápido. Desde aquí puedes enviar comprobantes de pago a Recepción.</p>
    </section>

    <section class="dashboard-resumen">
      <div class="tarjetas-grid">

        <!-- Pagos: listado y botón para enviar comprobante -->
        <div class="tarjeta">
          <div class="head">
            <div class="icon" aria-hidden="true"><i class="fa-solid fa-credit-card"></i></div>
            <div>
              <h3>Realiza tu pago y visualiza tus pagos pendientes</h3>
              <p class="lead"><?php echo $pagos_pendientes; ?></p>
              <p class="desc">Total pendiente: $<?php echo number_format($total_pendiente,2,',','.'); ?></p>
            </div>
          </div>

          <?php if (!empty($db_error)): ?>
            <div class="small-note" style="color:#a33;"><?php echo htmlspecialchars($db_error); ?></div>
          <?php endif; ?>

          <div class="pagos-list" aria-live="polite">
            <?php if (empty($pagos_list)): ?>
              <div class="small-note">No tienes pagos pendientes registrados.</div>
            <?php else: foreach ($pagos_list as $pl): ?>
              <div class="pago-item">
                <div class="pago-meta">
                  <strong>Cita #<?php echo htmlspecialchars($pl['id_cita']); ?> — $<?php echo number_format($pl['monto'],2,',','.'); ?></strong>
                  <small><?php echo htmlspecialchars($pl['fecha_cita'] ?: 'Fecha no asignada'); ?> — <?php echo htmlspecialchars($pl['mascota'] ?: 'Mascota'); ?></small>
                </div>
                <div>
                  <button class="btn" onclick="openPayModal(<?php echo (int)$pl['id_pago']; ?>, <?php echo (float)$pl['monto']; ?>)">
                    <i class="fa-solid fa-paper-plane"></i> Enviar comprobante
                  </button>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <div class="card-actions">
            <a class="btn secondary" href="cliente_pagos.php"><i class="fa-solid fa-eye"></i> Ver todos los pagos</a>
          </div>
        </div>

        <!-- Consejos de cuidado -->
        <div class="tarjeta tall">
          <div class="head">
            <div class="icon" aria-hidden="true"><i class="fa-solid fa-heart-pulse"></i></div>
            <div>
              <h3>Consejos de cuidado</h3>
              <p class="desc">Pequeños hábitos que hacen la diferencia.</p>
            </div>
          </div>

          <ul style="margin:8px 0 0 18px;color:#444">
            <li>Mantén calendario de vacunas al día.</li>
            <li>Higiene y revisiones periódicas.</li>
            <li>Alimentación adecuada según edad/raza.</li>
            <li>Actividad y agua fresca diariamente.</li>
          </ul>

          <div class="small-note">Si detectas síntomas de enfermedad, solicita una cita inmediata.</div>
        </div>

      </div>
    </section>
  </div>
</div>

<!-- Modal: enviar comprobante -->
<div id="payModal" class="modal-overlay" aria-hidden="true" role="dialog" aria-labelledby="payModalTitle">
  <div class="form-box" role="document">
    <h2 id="payModalTitle">Enviar comprobante de pago</h2>
    <form id="payForm" action="cliente_submit_pago.php" method="POST" enctype="multipart/form-data" onsubmit="return validatePayForm();">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
      <input type="hidden" name="id_pago" id="modal_id_pago" value="">
      <label>Monto</label>
      <input type="text" id="modal_monto" disabled>

      <label for="metodo_pago">Método de pago</label>
      <select name="metodo_pago" id="modal_metodo" required>
        <option value="">Seleccione...</option>
        <option value="Efectivo">Efectivo</option>
        <option value="Tarjeta">Tarjeta</option>
        <option value="Transferencia">Transferencia</option>
      </select>

      <label for="referencia">Referencia / Número de operación (opcional)</label>
      <input type="text" name="referencia" id="referencia" placeholder="Ej: REF12345">

      <label for="comprobante">Comprobante (imagen/pdf, opcional)</label>
      <input type="file" name="comprobante" id="comprobante" accept="image/*,.pdf">

      <div class="form-actions">
        <button type="button" class="btn secondary" onclick="closePayModal()">Cancelar</button>
        <button type="submit" class="btn"><i class="fa-solid fa-paper-plane"></i> Enviar comprobante</button>
      </div>
    </form>
  </div>
</div>

<script>
// Abrir modal con id_pago
function openPayModal(id_pago, monto) {
    document.getElementById('modal_id_pago').value = id_pago;
    document.getElementById('modal_monto').value = '$' + Number(monto).toLocaleString('es-ES', {minimumFractionDigits:2});
    document.getElementById('payModal').classList.add('show');
    document.getElementById('payModal').setAttribute('aria-hidden','false');
    window.scrollTo({top:0,behavior:'smooth'});
}
// Cerrar modal
function closePayModal() {
    document.getElementById('payForm').reset();
    document.getElementById('payModal').classList.remove('show');
    document.getElementById('payModal').setAttribute('aria-hidden','true');
}
function validatePayForm() {
    // se puede hacer más validación; por ahora permitimos enviar sin referencia ni comprobante
    var metodo = document.getElementById('modal_metodo').value;
    if (!metodo) {
        alert('Selecciona un método de pago');
        return false;
    }
    return true;
}
</script>

</body>
</html>