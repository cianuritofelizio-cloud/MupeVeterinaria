<?php
session_start();

// Validar que el usuario esté logueado y tenga rol Veterinario
if (!isset($_SESSION["usuario"]) || $_SESSION["rol"] !== "Veterinario") {
    header("Location: login.html");
    exit;
}

$usuario_sesion = $_SESSION['usuario'];
// Intentamos obtener el id del usuario desde la sesión; si no existe, lo buscamos en la tabla usuarios
$user_id = $_SESSION['id_usuario'] ?? null;

// CONEXIÓN A BD
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "veterinaria_mupe";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Si no tenemos id en sesión, intentar resolverlo buscando por nombre o correo
if (!$user_id) {
    $stmt = $conn->prepare("SELECT id_usuario, nombre, correo FROM usuarios WHERE nombre = ? OR correo = ? LIMIT 1");
    $stmt->bind_param("ss", $usuario_sesion, $usuario_sesion);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $user_id = (int)$row['id_usuario'];
        }
    }
    $stmt->close();
}

// ALERTAS (inventario con stock bajo, igual que en admin)
$alertas = [];
$sql_inv = "SELECT nombre, cantidad FROM inventario WHERE cantidad <= 5";
$result_inv = $conn->query($sql_inv);
if ($result_inv && $result_inv->num_rows > 0) {
    while ($row = $result_inv->fetch_assoc()) {
        $alertas[] = [
            'icon' => 'fa-box-open',
            'color' => '#e53935',
            'producto' => $row['nombre'],
            'cantidad' => $row['cantidad']
        ];
    }
}

// CONTADORES: usar consultas basadas en el esquema real de la BD (vease dump)
// 1) Diagnósticos asignados al veterinario (la tabla diagnosticos no tiene "estado" en tu dump,
//    así que contamos los diagnósticos asignados a este vet).
$diagnosticos_asignados = 0;
if ($user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM diagnosticos WHERE id_veterinario = ?");
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $stmt->bind_result($cnt);
        if ($stmt->fetch()) $diagnosticos_asignados = (int)$cnt;
    }
    $stmt->close();
}

// 2) Citas pendientes asignadas a este veterinario (tabla citas tiene columna estado)
$citas_pendientes = 0;
if ($user_id) {
    $estado_pendiente = 'Pendiente';
    $stmt = $conn->prepare("SELECT COUNT(*) FROM citas WHERE estado = ? AND id_veterinario = ?");
    $stmt->bind_param("si", $estado_pendiente, $user_id);
    if ($stmt->execute()) {
        $stmt->bind_result($cnt2);
        if ($stmt->fetch()) $citas_pendientes = (int)$cnt2;
    }
    $stmt->close();
}

// 3) Pacientes sin historial ni diagnóstico (conteo de mascotas que no tienen entradas en historial_medico ni en diagnosticos)
$pacientes_sin_historial = 0;
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM mascotas m
    LEFT JOIN diagnosticos d ON d.id_mascota = m.id_mascota
    LEFT JOIN historial_medico h ON h.id_mascota = m.id_mascota
    WHERE d.id_diagnostico IS NULL AND h.id_historial IS NULL
");
if ($stmt) {
    if ($stmt->execute()) {
        $stmt->bind_result($cnt3);
        if ($stmt->fetch()) $pacientes_sin_historial = (int)$cnt3;
    }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel Veterinario - MUPE</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- CSS externo -->
    <link rel="stylesheet" href="admin_panel.css">
    <script src="https://kit.fontawesome.com/bbf0071ecd.js" crossorigin="anonymous"></script>

    <style>
        /* Reutilizamos el estilo de alertas del panel admin */
        .panel-alertas {
            background: #fffefa;
            border: 2px solid #ffd54f;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(255, 193, 7, 0.15);
            margin: 18px 0;
            padding: 24px;
            max-width: 950px;
        }
        .panel-alertas h3 {
            margin-bottom: 14px;
            color: #b27600;
            font-size: 1.35rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .panel-alertas ul {
            padding: 0;
            margin: 0;
            list-style: none;
        }
        .panel-alertas li {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.05em;
            margin-bottom: 12px;
            font-weight: 500;
            justify-content: space-between;
        }
        .panel-alertas .left {
            display:flex;
            gap:12px;
            align-items:center;
        }
        .panel-alertas i.fa-solid {
            font-size: 1.35em;
            color: #FFA000;
            min-width: 28px;
            text-align: center;
        }
        .panel-alertas .prod {
            font-weight: bold;
        }
        .panel-alertas .stock-bajo {
            color: #e53935;
            font-weight: bold;
            margin: 0 6px;
        }

        /* Badge/contador */
        .badge {
            background: #E50F53;
            color: #fff;
            padding: 6px 10px;
            border-radius: 999px;
            font-weight: 700;
            min-width:36px;
            text-align:center;
            box-shadow:0 6px 18px rgba(229,15,83,0.12);
            font-size:0.95rem;
        }

        /* Asegurar espacio si el sidebarVet es fijo (ajusta si tu sidebar tiene otro ancho) */
        .sidebarVet {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 260px;
            overflow: auto;
            z-index: 50;
        }
        @media (max-width:900px) {
            .sidebarVet { position: relative; width:100%; height:auto; }
        }

        .main-wrapper {
            margin-left: 260px; /* mismo ancho que .sidebarVet */
            padding: 28px;
            min-height: 100vh;
            box-sizing: border-box;
        }
        @media (max-width:900px) {
            .main-wrapper { margin-left: 0; padding: 16px; }
        }

        .bienvenida h2 {
            margin: 0 0 6px 0;
            font-size: 1.6rem;
            display:flex;
            gap:0.6rem;
            align-items:center;
            color: #1f2937;
        }
        .bienvenida p { margin:0 0 18px 0; color: #6b7280; }
    </style>
</head>
<body>
    <?php include "sidebarVet.php"; ?>

    <div class="main-wrapper">
        <div class="content">
            <section class="bienvenida" aria-labelledby="bienvenido-titulo">
                <h2 id="bienvenido-titulo"><i class="fa-solid fa-user-doctor" aria-hidden="true"></i> Bienvenido <?php echo htmlspecialchars($usuario_sesion); ?></h2>
                <p>Este es tu panel como veterinario. Aquí tienes información y alertas relevantes.</p>
            </section>

            <!-- Panel: Alertas recientes (inventario) -->
            <div class="panel-alertas" aria-live="polite">
                <h3><i class="fa-solid fa-bell"></i> Alertas recientes</h3>
                <ul>
                <?php if (!empty($alertas)): ?>
                    <?php foreach ($alertas as $a): ?>
                        <li>
                            <div class="left">
                                <i class="fa-solid <?= htmlspecialchars($a['icon']) ?>" style="color:<?= htmlspecialchars($a['color']) ?>;" aria-hidden="true"></i>
                                <span>Producto <span class="prod"><?= htmlspecialchars($a['producto']) ?></span> con stock bajo (<span class="stock-bajo"><?= intval($a['cantidad']) ?> en inventario</span>)</span>
                            </div>
                            <span class="badge" aria-label="<?= intval($a['cantidad']) ?> en stock"><?= intval($a['cantidad']) ?></span>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li>No hay alertas recientes.</li>
                <?php endif; ?>
                </ul>
            </div>

            <!-- Panel: Contadores (misma apariencia) -->
            <div class="panel-alertas" aria-live="polite" style="margin-top:12px;">
                <h3><i class="fa-solid fa-chart-simple"></i> Resumen rápido</h3>
                <ul>
                    <li>
                        <div class="left">
                            <i class="fa-solid fa-stethoscope" style="color:#E50F53"></i>
                            <span>Diagnósticos asignados</span>
                        </div>
                        <span class="badge" aria-label="<?php echo $diagnosticos_asignados; ?> diagnósticos"><?php echo $diagnosticos_asignados; ?></span>
                    </li>

                    <li>
                        <div class="left">
                            <i class="fa-solid fa-calendar" style="color:#0ea5b0"></i>
                            <span>Citas pendientes</span>
                        </div>
                        <span class="badge" aria-label="<?php echo $citas_pendientes; ?> citas pendientes"><?php echo $citas_pendientes; ?></span>
                    </li>

                    <li>
                        <div class="left">
                            <i class="fa-solid fa-file-medical" style="color:#059669"></i>
                            <span>Pacientes sin historial</span>
                        </div>
                        <span class="badge" aria-label="<?php echo $pacientes_sin_historial; ?> pacientes sin historial"><?php echo $pacientes_sin_historial; ?></span>
                    </li>
                </ul>
            </div>

            <!-- Footer -->
           

        </div>
    </div>
</body>
</html>