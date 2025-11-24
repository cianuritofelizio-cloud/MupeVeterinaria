<?php
// sidebarCli.php - solo HTML/CSS/JS del sidebar para CLIENTE (NO session_start ni lógica)
?>
<style>
:root{
  --secundario3: #E9A0A0;
  --sidebar-width: 240px;
  --sidebar-compact: 72px;
  --text-white: #ffffff;
  --icon-color: #000000;
  --hover-bg: #d98888;
  --radius: 10px;
  --transition-fast: 150ms;
}

/* Sidebar base (coherente con el panel de Recepcionista / Admin) */
.sidebarCli{
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
  font-family: Arial, sans-serif;
  z-index: 100;
  transition: width var(--transition-fast) ease;
  overflow: hidden;
}

/* Brand */
.sidebarCli .brand{
  color: var(--text-white);
  font-size: 1.02rem;
  font-weight: 800;
  text-align: center;
  padding: 10px 8px;
  letter-spacing: 0.6px;
}

/* Nav */
.sidebarCli nav{ display:flex; flex-direction:column; gap:6px; padding:4px; }
.sidebarCli a{
  display:flex;
  align-items:center;
  gap:12px;
  text-decoration:none;
  color:var(--text-white);
  padding:10px 12px;
  border-radius:8px;
  transition: transform var(--transition-fast), background var(--transition-fast), box-shadow var(--transition-fast);
  font-weight:600;
  white-space:nowrap;
}
.sidebarCli a:hover{
  background: var(--hover-bg);
  transform: translateY(-2px);
  box-shadow: 0 6px 14px rgba(0,0,0,0.06);
}
.sidebarCli a.active{
  background: linear-gradient(90deg, rgba(0,0,0,0.03), rgba(255,255,255,0.02));
  box-shadow: inset 4px 0 0 rgba(0,0,0,0.06);
  color: #111;
}
.sidebarCli a .icon{
  width:22px;
  height:22px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  color: var(--icon-color);
  flex:0 0 22px;
}
.sidebarCli a span.label{ flex:1; text-align:left; }

/* Footer */
.sidebarCli .footer{
  margin-top:auto;
  padding-top:12px;
  border-top:1px solid rgba(255,255,255,0.06);
  color:rgba(255,255,255,0.95);
  font-size:0.92rem;
  display:flex;
  flex-direction:column;
  gap:6px;
  align-items:flex-start;
}

/* Compact mode for small screens */
.sidebarCli.compact{ width: var(--sidebar-compact); }
.sidebarCli.compact a{ justify-content:center; padding:10px 6px; }
.sidebarCli.compact a span.label{ display:none; }
.sidebarCli.compact .brand{ display:none; }
.sidebarCli.compact .footer{ display:none; }

/* Tooltip in compact mode */
.sidebarCli.compact a[title]:hover::after{
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
  z-index:200;
}

/* Responsive behavior */
@media (max-width:900px){
  .sidebarCli{ position: relative; width:100%; height:auto; flex-direction:row; flex-wrap:wrap; padding:8px; gap:6px; }
  .sidebarCli .brand{ flex-basis:100%; text-align:left; padding-left:12px; }
  .main-wrapper{ margin-left:0; } /* páginas deben manejar el margen principal */
  .sidebarCli.compact{ width:100%; }
}

/* Accessibility focus */
.sidebarCli a:focus{
  outline: 3px solid rgba(0,0,0,0.12);
  outline-offset: 2px;
}
</style>

<div class="sidebarCli" role="navigation" aria-label="Panel lateral cliente">
  <div class="brand" aria-hidden="true">CLIENTE</div>

  <nav>
    <a href="cliente_panel.php" title="Panel del cliente">
      <span class="icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zM13 21h8v-10h-8v10zM13 3v6h8V3h-8z"/></svg>
      </span>
      <span class="label">Panel</span>
    </a>

    <a href="agendar_cita.php" title="Solicitar Cita">
      <span class="icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M19 3h-1V1h-2v2H8V1H6v2H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V5a2 2 0 00-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>
      </span>
      <span class="label">Solicitar Cita</span>
    </a>

    <a href="mascotac.php" title="Mascotas">
      <span class="icon" aria-hidden="true">
        <!-- paw icon -->
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M12 11.5c1.66 0 3-1.34 3-3S13.66 5.5 12 5.5 9 6.84 9 8.5s1.34 3 3 3zM6.5 8C7.33 8 8 7.33 8 6.5S7.33 5 6.5 5 5 5.67 5 6.5 5.67 8 6.5 8zM17.5 8c.83 0 1.5-.67 1.5-1.5S18.33 5 17.5 5 16 5.67 16 6.5 16.67 8 17.5 8zM12 13.5c-3.5 0-7 2.29-7 5v1h14v-1c0-2.71-3.5-5-7-5z"/></svg>
      </span>
      <span class="label">Mascotas</span>
    </a>

    <a href="index.html" title="Cerrar sesión">
      <span class="icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M16 13v-2H7V8l-5 4 5 4v-3zM20 3h-8v2h8v14h-8v2h8a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/></svg>
      </span>
      <span class="label">Cerrar Sesión</span>
    </a>
  </nav>

  <div class="footer" aria-hidden="false">
    <div style="font-weight:700; color:#fff;">Rol: <span style="font-weight:800; margin-left:6px;">CLIENTE</span></div>
    <div style="font-size:0.92rem; color:#fff; opacity:0.95;">Usuario: <strong style="margin-left:6px;">Invitado</strong></div>
  </div>
</div>

<script>
// Ajuste responsivo: compacta el sidebar en pantallas pequeñas
(function(){
  function updateCompact(){
    const sb = document.querySelector('.sidebarCli');
    if (!sb) return;
    const shouldCompact = window.innerWidth < 900;
    if (shouldCompact) sb.classList.add('compact');
    else sb.classList.remove('compact');
  }
  window.addEventListener('resize', updateCompact);
  document.addEventListener('DOMContentLoaded', updateCompact);
})();
</script>