<?php
session_start();

// Validar que el usuario esté logueado y tenga rol Recepcionista
if (!isset($_SESSION["usuario"]) || $_SESSION["rol"] !== "Recepcionista") {
    header("Location: login.php");
    exit;
}

$usuario = $_SESSION["usuario"];

// Conexión a la base de datos (ajusta si corresponde)
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'veterinaria_mupe';

$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
$dbError = '';
if ($mysqli->connect_errno) {
    $dbError = "Error al conectar a la base de datos: " . $mysqli->connect_error;
}

// Comprueba si una tabla existe
function tableExists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    if ($res === false) return false;
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
}

// Obtener citas para hoy
$citasHoy = [];
if (empty($dbError) && tableExists($mysqli, 'citas')) {
    $selects = [
        "c.id_cita AS id",
        "c.fecha",
        "c.hora",
        "COALESCE(c.motivo, '') AS motivo",
        "COALESCE(c.estado, '') AS estado"
    ];
    $joins = [];

    if (tableExists($mysqli, 'usuarios')) {
        $selects[] = "COALESCE(cl.nombre, '') AS cliente";
        $joins[] = "LEFT JOIN usuarios cl ON cl.id_usuario = c.id_cliente";
    } else {
        $selects[] = "'' AS cliente";
    }

    if (tableExists($mysqli, 'mascotas')) {
        $selects[] = "COALESCE(m.nombre, '') AS mascota";
        $joins[] = "LEFT JOIN mascotas m ON m.id_mascota = c.id_mascota";
    } else {
        $selects[] = "'' AS mascota";
    }

    if (tableExists($mysqli, 'servicios')) {
        $selects[] = "COALESCE(s.nombre, '') AS servicio";
        $joins[] = "LEFT JOIN servicios s ON s.id_servicio = c.id_servicio";
    } else {
        $selects[] = "'' AS servicio";
    }

    $sql = "SELECT " . implode(", ", $selects) .
           " FROM citas c " . implode(" ", $joins) .
           " WHERE c.fecha = CURDATE() ORDER BY c.hora ASC";

    if ($res = $mysqli->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $citasHoy[] = $row;
        }
        $res->free();
    } else {
        error_log("Error consulta citasHoy: " . $mysqli->error);
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel Recepción - Citas de Hoy</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="admin_panel.css">
    <script src="https://kit.fontawesome.com/bbf0071ecd.js" crossorigin="anonymous"></script>
    <style>
        /* Estilos específicos para la vista "Citas de hoy" y botones Mejorados */
        .main-wrapper { margin-left: 240px; padding: 28px; min-height: 100vh; box-sizing: border-box; }
        .content { max-width: 1100px; margin: 0 auto; }
        .bienvenida h2 { font-size: 1rem; display:flex; align-items:center; gap:8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .bienvenida p { margin-top:6px; color:#555; }

        .lista-citas {
            background:#fff;
            border-radius:12px;
            padding:16px;
            box-shadow:0 8px 28px rgba(0,0,0,0.06);
            border:1px solid rgba(0,0,0,0.03);
            margin-top:18px;
        }
        .lista-citas h3 { margin:0 0 12px 0; }

        .cita-item {
            display:flex;
            gap:12px;
            align-items:center;
            padding:12px 8px;
            border-radius:8px;
        }
        .cita-item + .cita-item { border-top:1px dashed rgba(0,0,0,0.04); margin-top:10px; padding-top:12px; }

        .hora {
            min-width:80px;
            font-weight:700;
            color:#111;
            background:rgba(0,0,0,0.03);
            padding:8px 10px;
            border-radius:8px;
            text-align:center;
        }
        .cita-meta { flex:1; }
        .cita-meta b { display:block; font-weight:700; color:#111; }
        .cita-meta small { color:#666; }

        /* NEW: estilos para el grupo de acciones (Ver / Editar) */
        .action-btn-group {
            display:flex;
            gap:8px;
            justify-content:flex-end;
            align-items:center;
            min-width:140px;
        }
        .btn {
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:8px 12px;
            border-radius:8px;
            font-weight:700;
            font-size:0.95rem;
            text-decoration:none;
            color:#fff;
            transition:transform .12s ease, box-shadow .12s ease, opacity .12s ease;
            cursor:pointer;
            border: none;
        }
        .btn:focus { outline: 3px solid rgba(0,0,0,0.08); outline-offset:2px; }

        .btn-primary {
            background: #E50F53; /* color principal */
            box-shadow: 0 8px 18px rgba(229,15,83,0.08);
        }
        .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(229,15,83,0.12); }

        .btn-outline {
            background: transparent;
            color: #111;
            border: 1px solid rgba(0,0,0,0.08);
        }
        .btn-outline:hover {
            background: rgba(0,0,0,0.03);
            transform: translateY(-2px);
        }

        /* variant small (menos padding) */
        .btn-sm { padding:6px 10px; font-size:0.9rem; border-radius:7px; }

        /* icon only on very small screens */
        .btn .btn-text { display:inline-block; }
        @media (max-width:480px) {
            .btn .btn-text { display:none; } /* en móvil mostrar solo el icono */
        }

        /* small visually-hidden helper for accessibility if needed */
        .sr-only {
          position: absolute !important;
          width: 1px; height: 1px;
          padding: 0; margin: -1px;
          overflow: hidden; clip: rect(0,0,0,0);
          white-space: nowrap; border: 0;
        }

        .acciones { min-width:160px; text-align:right; } /* para mantener espacio */
        @media (max-width: 900px) {
            .main-wrapper { margin-left: 0; padding: 16px; }
            .acciones { min-width:90px; }
        }
    </style>
</head>
<body>
    <?php include_once "sidebarRecep.php"; ?>

    <div class="main-wrapper">
        <div class="content">
            <section class="bienvenida" aria-labelledby="bienvenida-titulo">
                <h2 id="bienvenida-titulo"><i class="fa-solid fa-calendar-day" style="font-size:18px;color:#000;"></i> Citas para hoy</h2>
                <p>Bienvenido <?php echo htmlspecialchars($usuario); ?> — aquí están las citas programadas para el día de hoy.</p>
            </section>

            <div class="lista-citas" role="region" aria-live="polite">
                <h3>Hoy (<?php echo date('Y-m-d'); ?>)</h3>

                <?php if (!empty($dbError)): ?>
                    <div style="padding:14px; color:#666;">Error de conexión: <?php echo htmlspecialchars($dbError); ?></div>
                <?php elseif (!tableExists($mysqli, 'citas')): ?>
                    <div style="padding:14px; color:#666;">La tabla 'citas' no existe en la base de datos.</div>
                <?php elseif (empty($citasHoy)): ?>
                    <div style="padding:14px; color:#666;">No hay citas programadas para hoy.</div>
                <?php else: ?>
                    <?php foreach ($citasHoy as $c): 
                        $hora = substr($c['hora'], 0, 5);
                        $cliente = $c['cliente'] ?: '—';
                        $mascota = $c['mascota'] ?: '—';
                        $servicio = $c['servicio'] ?: '—';
                        $estado = $c['estado'] ?: 'Pendiente';
                    ?>
                    <div class="cita-item" role="article">
                        <div class="hora"><?php echo htmlspecialchars($hora); ?></div>
                        <div class="cita-meta">
                            <b><?php echo htmlspecialchars($cliente); ?></b>
                            <small>Mascota: <?php echo htmlspecialchars($mascota); ?> — Servicio: <?php echo htmlspecialchars($servicio); ?></small>
                            <small style="display:block; margin-top:6px; color:#777;">Estado: <?php echo htmlspecialchars($estado); ?> <?php if (!empty($c['motivo'])): ?>— Motivo: <?php echo htmlspecialchars($c['motivo']); ?><?php endif; ?></small>
                        </div>

                        <!-- NUEVO: grupo de acciones con mejor estilo -->
                        <div class="acciones">
                            <div class="action-btn-group" role="group" aria-label="Acciones">
                                <!-- Ver (outline) -->
                                <a class="btn btn-outline btn-sm" href="crud_cita.php?ver=<?php echo urlencode($c['id']); ?>" title="Ver cita <?php echo htmlspecialchars($c['id']); ?>">
                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    <span class="btn-text">Ver</span>
                                </a>

                                <!-- Editar (primary) -->
                                <a class="btn btn-primary btn-sm" href="crud_cita.php?editar=<?php echo urlencode($c['id']); ?>" title="Editar cita <?php echo htmlspecialchars($c['id']); ?>">
                                    <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                    <span class="btn-text">Editar</span>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>

<?php
$mysqli->close();
?>
</body>
</html>