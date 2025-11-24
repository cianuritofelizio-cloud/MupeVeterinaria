<?php
// sidebarVet.php - Sidebar Veterinario con iconos NEGROS sin fondo blanco.
// Solo HTML/CSS/JS (no lógica PHP de sesión).
?>
<style>
:root{
  --secundario3: #E9A0A0;
  --sidebar-width: 240px;
  --sidebar-compact: 72px;
  --text-white: #ffffff;
  --icon-color: #111111;  /* iconos en negro */
  --hover-bg: #d98888;
  --radius: 10px;
  --transition-fast: 150ms;
}

/* Sidebar base (coherente con el panel del cliente) */
.sidebarVet{
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
.sidebarVet .brand{
  color: var(--text-white);
  font-size: 1.02rem;
  font-weight: 800;
  text-align: center;
  padding: 10px 8px;
  letter-spacing: 0.6px;
  display:flex;
  align-items:center;
  gap:10px;
  justify-content:center;
}

.sidebarVet .brand .logo{
  width:34px;
  height:34px;
  border-radius:8px;
  background: transparent; /* sin fondo blanco */
  display:inline-flex;
  align-items:center;
  justify-content:center;
  color: var(--text-white);
  flex:0 0 34px;
}

/* Nav */
.sidebarVet nav{ display:flex; flex-direction:column; gap:6px; padding:4px; }
.sidebarVet a{
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
.sidebarVet a:hover{
  background: var(--hover-bg);
  transform: translateY(-2px);
  box-shadow: 0 6px 14px rgba(0,0,0,0.06);
}
.sidebarVet a.active{
  background: linear-gradient(90deg, rgba(0,0,0,0.03), rgba(255,255,255,0.02));
  box-shadow: inset 4px 0 0 rgba(0,0,0,0.06);
  color: #111;
}

/* Icon styling: SIN fondo blanco, iconos en negro (alto contraste) */
.sidebarVet a .icon{
  width:34px;
  height:34px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background: transparent; /* nada de fondo */
  color: var(--icon-color); /* icon color NEGRO */
  border-radius:8px;
  flex:0 0 34px;
  /* sin sombra para mantener limpieza */
}

/* On hover keep icon legible: color remains black */
.sidebarVet a:hover .icon,
.sidebarVet a.active .icon {
  color: var(--icon-color);
  background: transparent;
}

/* Label text */
.sidebarVet a span.label{ flex:1; text-align:left; }

/* Footer */
.sidebarVet .footer{
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
.sidebarVet.compact{ width: var(--sidebar-compact); }
.sidebarVet.compact a{ justify-content:center; padding:10px 6px; }
.sidebarVet.compact a span.label{ display:none; }
.sidebarVet.compact .brand{ display:none; }
.sidebarVet.compact .footer{ display:none; }

/* Tooltip in compact mode */
.sidebarVet.compact a[title]:hover::after{
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
  .sidebarVet{ position: relative; width:100%; height:auto; flex-direction:row; flex-wrap:wrap; padding:8px; gap:6px; }
  .sidebarVet .brand{ flex-basis:100%; text-align:left; padding-left:12px; }
  .main-wrapper{ margin-left:0; } /* páginas deben manejar el margen principal */
  .sidebarVet.compact{ width:100%; }
}

/* Accessibility focus */
.sidebarVet a:focus{
  outline: 3px solid rgba(0,0,0,0.12);
  outline-offset: 2px;
}
</style>

<div class="sidebarVet" role="navigation" aria-label="Panel lateral veterinario">
  <a class="brand" href="vet_panel.php" aria-label="Panel Veterinario">
    <span class="logo" aria-hidden="true">
      <!-- paw icon in black (no white box) -->
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M12 13c-3.5 0-6.5 2-6.5 4.5S8.5 22 12 22s6.5-1.99 6.5-4.5S15.5 13 12 13z" fill="#111111"/>
        <path d="M6.5 8A1.9 1.9 0 1 1 2.7 8 1.9 1.9 0 0 1 6.5 8zM11.5 3.6A1.8 1.8 0 1 1 8 3.6 1.8 1.8 0 0 1 11.5 3.6zM17 6.5A1.9 1.9 0 1 1 13.2 6.5 1.9 1.9 0 0 1 17 6.5zM19 11.8a1.2 1.2 0 1 1-2.4 0 1.2 1.2 0 0 1 2.4 0z" fill="#111111"/>
      </svg>
    </span>
    <span>VETERINARIO</span>
  </a>

  <nav>
    <a class="nav-link" href="crud_diagnostico.php" title="Diagnósticos">
      <span class="icon" aria-hidden="true">
        <!-- stethoscope: black filled path -->
        <svg width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M20 7a1 1 0 00-1 1v2a5 5 0 01-5 5 1 1 0 000 2 7 7 0 007-7V8a1 1 0 00-1-1zM6 7a3 3 0 110-6 3 3 0 010 6zM8 10a1 1 0 00-1 1v7a3 3 0 006 0v-7a1 1 0 00-1-1H8z" fill="#111111"/>
        </svg>
      </span>
      <span class="label">Diagnósticos</span>
    </a>

    <a class="nav-link" href="crud_historial.php" title="Historial">
      <span class="icon" aria-hidden="true">
        <!-- document/file: black -->
        <svg width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M6 2h7l5 5v13a2 2 0 01-2 2H6a2 2 0 01-2-2V4a2 2 0 012-2zm8 1.5V8h4.5L14 3.5zM8 11h8v2H8v-2zm0 4h8v2H8v-2z" fill="#111111"/>
        </svg>
      </span>
      <span class="label">Historial</span>
    </a>

    <a class="nav-link" href="vet_inventario.php" title="Inventario">
      <span class="icon" aria-hidden="true">
        <!-- box/inventory: black -->
        <svg width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M21 8l-9-5-9 5v8a2 2 0 002 2h14a2 2 0 002-2V8zm-9-3.6L18.6 8H5.4L12 4.4zM6 10h12v6H6v-6z" fill="#111111"/>
        </svg>
      </span>
      <span class="label">Inventario</span>
    </a>

    <a class="nav-link" href="index.html" title="Cerrar sesión">
      <span class="icon" aria-hidden="true">
        <!-- logout: black -->
        <svg width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M16 13v-2H7V8l-5 4 5 4v-3h9zM20 3h-8v2h8v14h-8v2h8a2 2 0 002-2V5a2 2 0 00-2-2z" fill="#111111"/>
        </svg>
      </span>
      <span class="label">Cerrar Sesión</span>
    </a>
  </nav>

  <div class="footer" aria-hidden="false">
    <div style="font-weight:700; color:#fff;">Rol: <span style="font-weight:800; margin-left:6px;">VETERINARIO</span></div>
    <div style="font-size:0.92rem; color:#fff; opacity:0.95;">Usuario: <strong style="margin-left:6px;">Invitado</strong></div>
  </div>
</div>

<script>
// Ajuste responsivo: compacta el sidebar en pantallas pequeñas
(function(){
  function updateCompact(){
    const sb = document.querySelector('.sidebarVet');
    if (!sb) return;
    const shouldCompact = window.innerWidth < 900;
    if (shouldCompact) sb.classList.add('compact');
    else sb.classList.remove('compact');
  }
  window.addEventListener('resize', updateCompact);
  document.addEventListener('DOMContentLoaded', updateCompact);
})();
</script>