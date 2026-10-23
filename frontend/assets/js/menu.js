function buildMenuForRole(role){
  if(Number(role)===3){
    return [
      {href:'aktualnosci.html', label:'Aktualności', code:'news'},
      {href:'my_orders.html', label:'Moje zamówienia', code:'orders_current'},
      {href:'schedule.html', label:'Grafik', code:'schedule'},
      {adminOnly:true,href:'schedule_report.html', label:'Zestawienie pracy', code:'schedule'},
      {href:'dashboard.html', label:'Dashboard', code:'dashboard'}
    ];
  }
  return [
    {href:'aktualnosci.html', label:'Aktualności', code:'news'},
    {href:'orders.html', label:'Zamówienia aktualne', code:'orders_current'},
    {href:'orders_finished.html', label:'Zamówienia zakończone', code:'orders_finished'},
    {href:'complaints.html', label:'Reklamacje', code:'complaints', attr:'data-admin-only'},
    {href:'orders_deleted.html', label:'Zamówienia usunięte', code:'orders_deleted', attr:'data-super-only'},
    {href:'payments.html', label:'Rozliczenia', code:'payments', attr:'data-finance-only'},
    {href:'frontypax.html', label:'FRONTYPAX', code:'frontypax'},
    {href:'import_orders.html', label:'Import zamówień', code:'import_orders', attr:'data-admin-only'},
    {href:'schedule.html', label:'Grafik', code:'schedule'},
    {href:'grafik_ekip.html', label:'Grafik testowy (ekipy)', code:'schedule', attr:'data-admin-only'},
    {href:'ekipy.html', label:'Ekipy', code:'schedule', attr:'data-admin-only'},
    {adminOnly:true,href:'schedule_report.html', label:'Zestawienie pracy', code:'schedule'},
    {href:'users.html', label:'Użytkownicy', code:'users', attr:'data-admin-only'},
    {href:'statuses.html', label:'Statusy', code:'statuses', attr:'data-admin-only'},
    {href:'notifications.html', label:'Powiadomienia', code:'notifications', attr:'data-admin-only'},
    {href:'settings.html', label:'Ustawienia', code:'settings', attr:'data-admin-only'},
    {href:'dashboard.html', label:'Dashboard', code:'dashboard'},
    {href:'gps.html', label:'Monitoring GPS', code:'gps_monitoring'},
    {href:'permissions.html', label:'Uprawnienia grup', code:'permissions', attr:'data-super-only'},
    {href:'wycena.html', label:'Wycena', code:'wycena', attr:'data-super-only'}
  ];
}

function renderUnifiedMenu(role){
  const nav = document.querySelector('nav, #sideNav');
  if(!nav) return null;
  const current = (location.pathname.split('/').pop() || 'aktualnosci.html').toLowerCase();
  nav.innerHTML = buildMenuForRole(role).map(item => {
    const aliases = item.href === 'my_orders.html' ? ['my_orders.html','orders.html']
      : item.href === 'wycena.html' ? ['wycena.html','wycena_details.html','wycena_materials.html','wycena_offer.html']
      : [item.href.toLowerCase()];
    const cls = aliases.includes(current) ? ' class="active"' : '';
    const extra = item.attr ? ` ${item.attr}="1"` : '';
    return `<a${cls}${extra} href="${item.href}">${item.label}</a>`;
  }).join('');
  return nav;
}

// Menu bez migania (2026-10-22): rysujemy je od razu z ostatnio znanych uprawnień (sessionStorage),
// a odpowiedź serwera tylko je odświeża. Do tego czasu CSS ukrywa menu (theme_v17.css: nav:not(.menu-ready)),
// więc stare menu wpisane na stałe w HTML stron nigdy się nie pokazuje.
const MENU_CACHE_KEY = 'sm_menu_caps_v1';
function menuCacheRead(){ try{ return JSON.parse(sessionStorage.getItem(MENU_CACHE_KEY) || 'null'); }catch(e){ return null; } }
function menuCacheWrite(v){ try{ sessionStorage.setItem(MENU_CACHE_KEY, JSON.stringify(v)); }catch(e){} }
function menuReady(nav){ if(nav) nav.classList.add('menu-ready'); }

async function applyMenu(){
  const cached = menuCacheRead();
  if(cached){ drawMenu(cached.role, cached.caps || {}); }

  let role = 0;
  let caps = {};
  let ok = false;
  try{
    const r = await apiGet('session_check.php');
    caps = (r && r.caps) ? r.caps : {};
    role = Number(r?.user?.role_id ?? r?.user?.role ?? 0);
    ok = !!(r && r.user);
  }catch(e){}

  if(ok){
    const fresh = {role, caps};
    if(!cached || JSON.stringify(cached) !== JSON.stringify(fresh)) drawMenu(role, caps);
    menuCacheWrite(fresh);
  } else if(!cached){
    drawMenu(role, caps);
  }
}

function drawMenu(role, caps){
  const nav = renderUnifiedMenu(role);
  if(!nav) return;
  menuReady(nav);

  if(role===3){
    const current = (location.pathname.split('/').pop() || 'aktualnosci.html').toLowerCase();
    if(current === 'orders.html'){
      location.replace('my_orders.html');
      return;
    }
    return;
  }

  const modules = caps.modules || {};
  const isAdmin = !!caps.is_admin;
  const isSuper = !!caps.is_superadmin;
  const canFin = !!caps.can_view_finance;

  document.querySelectorAll('[data-admin-only]').forEach(a=>{ a.style.display = isAdmin ? '' : 'none'; });
  document.querySelectorAll('[data-super-only]').forEach(a=>{ a.style.display = isSuper ? '' : 'none'; });
  document.querySelectorAll('[data-finance-only]').forEach(a=>{ a.style.display = canFin ? '' : 'none'; });

  Object.entries({
    'aktualnosci.html':'news',
    'orders.html':'orders_current',
    'orders_finished.html':'orders_finished',
    'orders_deleted.html':'orders_deleted',
    'payments.html':'payments',
    'import_orders.html':'import_orders',
    'schedule.html':'schedule',
    'schedule_report.html':'schedule',
    'users.html':'users',
    'statuses.html':'statuses',
    'settings.html':'settings',
    'dashboard.html':'dashboard',
    'gps.html':'gps_monitoring',
    'permissions.html':'permissions',
    'frontypax.html':'frontypax'
  }).forEach(([href,code])=>{
    const link = nav.querySelector(`a[href="${href}"]`);
    if(link && Object.prototype.hasOwnProperty.call(modules, code)) {
      link.style.display = modules[code] ? '' : 'none';
    }
  });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applyMenu); else applyMenu();
// wylogowanie / zmiana użytkownika – nie pokazuj menu poprzedniego konta
document.addEventListener('click', e => { if (e.target.closest('[data-logout], .logout, #logoutBtn, #logoutBtnTopbar, a[href*="logout"]')) { try{ sessionStorage.removeItem(MENU_CACHE_KEY); }catch(_){} } }, true);
