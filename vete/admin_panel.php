<?php
session_start();

if (!isset($_SESSION["usuario"]) || $_SESSION["rol"] !== "Administrador") {
    header("Location: login.html");
    exit;
}
$usuario = $_SESSION['usuario'] ?? '';
$rol = $_SESSION['rol'] ?? '';

// CONEXIÓN A BD
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "veterinaria_mupe";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// ALERTAS: productos con bajo stock
$alertas = [];
$sql_inv = "SELECT nombre, cantidad FROM inventario WHERE cantidad <= 5";
$result_inv = $conn->query($sql_inv);
if ($result_inv && $result_inv->num_rows > 0) {
    while ($row = $result_inv->fetch_assoc()) {
        $alertas[] = [
            'producto' => $row['nombre'],
            'cantidad' => $row['cantidad']
        ];
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel Admin - MUPE</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="admin_panel.css">
    <style>
    /* Reutiliza las variables del sidebar para coherencia visual */
    :root {
      --secundario3: #E9A0A0;
      --content-max-width: 1100px;
      --panel-padding: 28px;
      --muted: #555;
      --card-bg: #fffefa;
      --card-border: #ffd54f;
      --card-shadow: rgba(255,193,7,0.12);
    }

    /* El layout principal: se adapta al sidebar incluido */
    .main-wrapper {
      margin-left: var(--sidebar-width, 240px); /* sidebar.php define --sidebar-width */
      min-height: 100vh;
      padding: var(--panel-padding);
      box-sizing: border-box;
      background: #f7f7f7;
    }

    .content { max-width: var(--content-max-width); margin:0 auto; }

    /* Bienvenida pequeña y compacta */
    .bienvenida {
      display:flex;
      align-items:center;
      gap:14px;
      margin-bottom:12px;
    }
    .bienvenida .icon {
      width:36px;
      height:36px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      background:rgba(0,0,0,0.06);
      border-radius:8px;
      color:#000;
      flex:0 0 36px;
    }
    .bienvenida h2 {
      font-size:1rem; /* reducido */
      margin:0;
      font-weight:700;
      color:#111;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .bienvenida p {
      margin:2px 0 0 0;
      color:var(--muted);
      font-size:0.95rem;
    }

    /* Tarjeta de alertas coherente con sidebar */
    .panel-alertas {
      background: var(--card-bg);
      border: 2px solid var(--card-border);
      border-radius: 12px;
      box-shadow: 0 6px 20px var(--card-shadow);
      padding: 20px 22px;
      margin-top: 18px;
      font-size: 1rem;
    }
    .panel-alertas h3 {
      display:flex;
      align-items:center;
      gap:10px;
      margin:0 0 12px 0;
      font-size:1.05rem;
      color:#b27600;
    }
    .panel-alertas ul { list-style:none; margin:0; padding:0; }
    .panel-alertas li {
      display:flex;
      gap:10px;
      align-items:center;
      padding:8px 0;
      border-bottom:1px dashed rgba(0,0,0,0.04);
      font-weight:600;
      color:#333;
    }
    .panel-alertas li:last-child { border-bottom:0; }
    .panel-alertas .ico {
      width:28px;
      height:28px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      border-radius:6px;
      background:#fff;
      color:#e53935;
      box-shadow:0 1px 4px rgba(0,0,0,0.04);
      flex:0 0 28px;
    }

    /* Responsive tweaks */
    @media (max-width:900px) {
      .main-wrapper { margin-left:0; padding:16px; }
      .content { padding:0 6px; }
      .bienvenida h2 { font-size:0.97rem; }
    }
    </style>
</head>
<body>

<?php include_once "sidebar.php"; ?>

<div class="main-wrapper" role="main">
  <div class="content">
    <section class="bienvenida" aria-labelledby="bienvenida-titulo">
      <div class="icon" aria-hidden="true">
        <!-- SVG icon (shield user) -->
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zM12 8a3 3 0 100 6 3 3 0 000-6z"/>
        </svg>
      </div>
      <div>
        <h2 id="bienvenida-titulo">Administrador <?php echo htmlspecialchars($usuario); ?></h2>
        <p>Panel de administración — gestiona usuarios, inventario y servicios</p>
      </div>
    </section>

    <div class="panel-alertas" role="region" aria-label="Alertas de inventario">
      <h3>
        <!-- bell icon -->
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="color:#b27600" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 22a2 2 0 002-2H10a2 2 0 002 2zm6-6V11c0-3.07-1.63-5.64-4.5-6.32V4a1.5 1.5 0 10-3 0v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
        Alertas recientes
      </h3>

      <ul>
        <?php if (!empty($alertas)): ?>
          <?php foreach ($alertas as $a): ?>
            <li>
              <span class="ico" aria-hidden="true">
                <!-- small box icon -->
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M21 8V7l-9-4-9 4v1l9 4 9-4zM3 10v7a2 2 0 002 2h3v-9L3 10zm11 9h3a2 2 0 002-2v-7l-8 3.56V21z"/></svg>
              </span>
              <span>Producto <strong style="margin:0 6px;"><?php echo htmlspecialchars($a['producto']); ?></strong> con stock bajo (<span style="color:#e53935;"><?php echo intval($a['cantidad']); ?></span>)</span>
            </li>
          <?php endforeach; ?>
        <?php else: ?>
          <li>No hay alertas recientes.</li>
        <?php endif; ?>
      </ul>
    </div>

  </div>
</div>

</body>
</html>