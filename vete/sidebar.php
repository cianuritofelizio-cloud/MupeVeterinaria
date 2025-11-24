<?php
// sidebar.php - panel lateral centralizado y estilizado
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$usuario = $_SESSION['usuario'] ?? '';
$rol = $_SESSION['rol'] ?? '';
$current = basename($_SERVER['PHP_SELF']);
?>
<style>
:root{
  --secundario3: #E9A0A0;
  --sidebar-width: 240px;
  --sidebar-compact: 72px;
  --text-white: #ffffff;
  --icon-color: #000000; /* iconos en negro */
  --hover-bg: #d98888;
  --active-overlay: rgba(0,0,0,0.06);
  --radius: 10px;
}

/* Sidebar base */
.sidebar{
  position: fixed;
  top: 0;
  left: 0;
  width: var(--sidebar-width);
  height: 100vh;
  background: var(--secundario3);
  padding: 16px 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  box-shadow: 2px 0 6px rgba(0,0,0,0.12);
  font-family: Arial, sans-serif; /* no cambiar fuente */
  z-index: 100;
  transition: width 180ms ease;
  overflow: hidden;
}

/* Brand */
.sidebar .brand {
  color: var(--text-white);
  font-size: 1.05rem;
  font-weight: 800;
  text-align: center;
  padding: 10px 8px;
  letter-spacing: 0.6px;
  border-radius: calc(var(--radius) - 2px);
  margin-bottom: 4px;
}

/* Nav container */
.sidebar nav{ display:flex; flex-direction:column; gap:6px; padding: 4px; }

/* Link style */
.sidebar a{
  display:flex;
  align-items:center;
  gap:12px;
  text-decoration:none;
  color:var(--text-white);
  padding:10px 12px;
  border-radius:8px;
  transition: transform 120ms ease, background 120ms ease, box-shadow 120ms ease;
  font-size: 0.98rem;
  font-weight:600;
  white-space:nowrap;
}

/* Icon wrapper */
.sidebar a .icon{
  width:22px;
  height:22px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  color: var(--icon-color); /* iconos en negro */
  flex: 0 0 22px;
}

/* Label text */
.sidebar a span.label{ flex:1; text-align:left; }

/* Hover */
.sidebar a:hover{
  background: var(--hover-bg);
  transform: translateY(-2px);
  box-shadow: 0 6px 14px rgba(0,0,0,0.06);
}

/* Active link: subtle left accent + brighter text */
.sidebar a.active{
  background: linear-gradient(90deg, rgba(0,0,0,0.03), rgba(255,255,255,0.02));
  box-shadow: inset 4px 0 0 rgba(0,0,0,0.08);
  color: #111;
}

/* Footer with role/user */
.sidebar .footer{
  margin-top:auto;
  padding:12px;
  border-top: 1px solid rgba(255,255,255,0.06);
  color: rgba(255,255,255,0.95);
  font-size: 0.92rem;
  display:flex;
  flex-direction:column;
  gap:6px;
  align-items:flex-start;
}

/* compact mode for very small screens: hide labels, show icons only */
.sidebar.compact{
  width: var(--sidebar-compact);
}
.sidebar.compact a{ justify-content:center; padding:10px 6px; }
.sidebar.compact a span.label{ display:none; }
.sidebar.compact .brand{ display:none; }
.sidebar.compact .footer{ display:none; }

/* tooltip for compact mode */
.sidebar.compact a[title] { position:relative; }
.sidebar.compact a[title]:hover::after{
  content: attr(title);
  position: absolute;
  left: calc(100% + 8px);
  top: 50%;
  transform: translateY(-50%);
  background: rgba(0,0,0,0.85);
  color: #fff;
  padding:6px 10px;
  border-radius:6px;
  white-space:nowrap;
  font-size:0.9rem;
  z-index: 200;
}

/* Responsive behavior */
@media (max-width: 900px){
  .sidebar{ position: relative; width: 100%; height: auto; flex-direction:row; flex-wrap:wrap; padding:8px; gap:6px; }
  .sidebar .brand{ flex-basis:100%; text-align:left; padding-left:12px; }
  .main-wrapper{ margin-left:0; } /* pages should handle main-wrapper margin */
  .sidebar.compact{ width:100%; }
}

/* Accessibility focus */
.sidebar a:focus{
  outline: 3px solid rgba(0,0,0,0.12);
  outline-offset: 2px;
}
</style>

<div class="sidebar" role="navigation" aria-label="Panel lateral">
  <div class="brand" aria-hidden="true">ADMINISTRADOR
    <div style="font-size:0.82rem; font-weight:600; opacity:0.95; margin-top:6px;"><?php echo $usuario ? htmlspecialchars($usuario) : 'Invitado'; ?></div>
  </div>

  <nav>
    <a href="admin_panel.php" class="<?= $current === 'admin_panel.php' ? 'active' : '' ?>" title="Panel">
      <span class="icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zM13 21h8v-10h-8v10zM13 3v6h8V3h-8z"/></svg>
      </span>
      <span class="label">Panel</span>
    </a>

    <a href="crud_usuarios.php" class="<?= $current === 'crud_usuarios.php' ? 'active' : '' ?>" title="Usuarios">
      <span class="icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zM8 11c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.95 1.97 3.45V19h6v-2.5C23 14.17 18.33 13 16 13z"/></svg>
      </span>
      <span class="label">Usuarios</span>
    </a>

    <a href="crud_veterinarios.php" class="<?= $current === 'crud_veterinarios.php' ? 'active' : '' ?>" title="Veterinarios">
      <span class="icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M12 12c2.21 0 4-1.79 4-4S14.21 4 12 4 8 5.79 8 8s1.79 4 4 4zm6-2v6h2v2H4v-2h2v-6c0-2.76 2.24-5 5-5h2c2.76 0 5 2.24 5 5z"/></svg>
      </span>
      <span class="label">Veterinarios</span>
    </a>

    <a href="crud_empleados.php" class="<?= $current === 'crud_empleados.php' ? 'active' : '' ?>" title="Empleados">
      <span class="icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M20 6H4v2h16V6zM4 18h16v-8H4v8zM6 8h2v2H6V8z"/></svg>
      </span>
      <span class="label">Empleados</span>
    </a>

    <a href="admin_inventario.php" class="<?= $current === 'admin_inventario.php' ? 'active' : '' ?>" title="Inventario">
      <span class="icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M21 8V7l-9-4-9 4v1l9 4 9-4zM3 10v7a2 2 0 0 0 2 2h3v-9L3 10zm11 9h3a2 2 0 0 0 2-2v-7l-8 3.56V21z"/></svg>
      </span>
      <span class="label">Inventario</span>
    </a>

    <a href="servicios.php" class="<?= $current === 'servicios.php' ? 'active' : '' ?>" title="Servicios">
      <span class="icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M12 2l2.09 6.26L20 9.27l-5 3.64L16.18 20 12 16.9 7.82 20 9 12.91l-5-3.64 5.91-.99L12 2z"/></svg>
      </span>
      <span class="label">Servicios</span>
    </a>

    <a href="index.html" class="<?= $current === 'index.html' ? 'active' : '' ?>" title="Cerrar sesión">
      <span class="icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M16 13v-2H7V8l-5 4 5 4v-3zM20 3h-8v2h8v14h-8v2h8a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/></svg>
      </span>
      <span class="label">Cerrar Sesión</span>
    </a>
  </nav>

  <div class="footer" aria-hidden="false">
    <div style="font-weight:700; color:#fff;">Rol: <span style="font-weight:800; margin-left:6px;"><?php echo $rol ? htmlspecialchars($rol) : 'Invitado'; ?></span></div>
    <?php if ($usuario): ?>
      <div style="font-size:0.92rem; color:#fff; opacity:0.95;">Usuario: <strong style="margin-left:6px;"><?php echo htmlspecialchars($usuario); ?></strong></div>
    <?php endif; ?>
  </div>
</div>

<script>
// Optional: toggle compact mode on small widths or when user wants a minimized sidebar.
// This preserves your colors and fonts but offers a neater compact view.
(function(){
  function updateCompact(){
    const sb = document.querySelector('.sidebar');
    if (!sb) return;
    const shouldCompact = window.innerWidth < 900;
    if (shouldCompact) sb.classList.add('compact');
    else sb.classList.remove('compact');
  }
  window.addEventListener('resize', updateCompact);
  document.addEventListener('DOMContentLoaded', updateCompact);
})();
</script>