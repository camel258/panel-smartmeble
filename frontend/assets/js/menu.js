// Menu boczne panelu – nowy wygląd (2026-11-27): grupy, ikony, logo na górze, zwijanie, liczniki.
// Na stronach sklepów (body[data-sklep] – FRONTYPAX, ZBUDUJMEBEL) menu pokazuje tylko ten sklep
// i przycisk „← Panel SMART MEBLE”. Uprawnienia jak dotąd: data-admin-only / data-super-only /
// data-finance-only + moduły z session_check.php (caps.modules).

const SM_IKONY = {
  news:'<path d="M4 5h13v14H6a2 2 0 0 1-2-2z"/><path d="M17 9h3v8a2 2 0 0 1-2 2"/><path d="M8 9h5M8 13h5"/>',
  dash:'<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
  list:'<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
  ok:'<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
  warn:'<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
  trash:'<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
  pln:'<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
  imp:'<path d="M12 3v12M7 10l5 5 5-5M4 19h16"/>',
  shop:'<path d="M3 9h18l-1.5 11h-15z"/><path d="M8 9V7a4 4 0 0 1 8 0v2"/>',
  cal:'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
  team:'<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a5.5 5.5 0 0 1 3.5 6"/>',
  chart:'<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  gps:'<path d="M12 21s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/>',
  calc:'<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 12h.01M12 12h.01M16 12h.01M8 16h.01M12 16h.01M16 16h.01"/>',
  www:'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
  user:'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  tag:'<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
  bell:'<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
  cog:'<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
  lock:'<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  moje:'<path d="M9 4h6l1 2h3v15H5V6h3z"/><path d="m9 13 2 2 4-4"/>',
  side:'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
  back:'<path d="M15 18l-6-6 6-6"/>',
  pause:'<circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/>',
  clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  box:'<path d="M3 7l9-4 9 4-9 4z"/><path d="M3 7v10l9 4 9-4V7M12 11v10"/>',
  cart:'<circle cx="9" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/><path d="M3 4h2l2.5 11h11l2-8H6.5"/>',
  x:'<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
  wallet:'<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18M16 15h2"/>'
};
function smIkona(n){ return '<svg class="smi" viewBox="0 0 24 24" aria-hidden="true">'+(SM_IKONY[n]||'')+'</svg>'; }

function buildMenuForRole(role){
  if(Number(role)===3){
    return [
      {href:'aktualnosci.html', label:'Aktualności', code:'news', ico:'news'},
      {href:'my_orders.html', label:'Moje zamówienia', code:'orders_current', ico:'list'},
      {href:'schedule.html', label:'Grafik', code:'schedule', ico:'cal'},
      {adminOnly:true,href:'schedule_report.html', label:'Zestawienie pracy', code:'schedule', ico:'chart'},
      {href:'dashboard.html', label:'Dashboard', code:'dashboard', ico:'dash'}
    ];
  }
  return [
    {href:'aktualnosci.html', label:'Aktualności', code:'news', ico:'news'},
    {href:'dashboard.html', label:'Dashboard', code:'dashboard', ico:'dash'},
    {grp:'Zamówienia'},
    {href:'orders.html', label:'Aktualne', code:'orders_current', ico:'list'},
    {href:'orders_finished.html', label:'Zakończone', code:'orders_finished', ico:'ok'},
    {href:'complaints.html', label:'Reklamacje', code:'complaints', attr:'data-admin-only', ico:'warn', licznik:'reklamacje'},
    {href:'payments.html', label:'Rozliczenia', code:'payments', attr:'data-finance-only', ico:'pln'},
    {href:'orders_deleted.html', label:'Usunięte', code:'orders_deleted', attr:'data-super-only', ico:'trash'},
    {href:'import_orders.html', label:'Import zamówień', code:'import_orders', attr:'data-admin-only', ico:'imp'},
    {grp:'Sklepy'},
    {href:'frontypax.html', label:'FRONTYPAX', code:'frontypax', ico:'shop', licznik:'frontypax'},
    {href:'zbudujmebel.html', label:'ZBUDUJMEBEL', code:'zbudujmebel', ico:'shop', licznik:'zbudujmebel'},
    {grp:'Planowanie'},
    {href:'schedule.html', label:'Grafik', code:'schedule', ico:'cal'},
    {href:'grafik_ekip.html', label:'Grafik testowy (ekipy)', code:'schedule', attr:'data-admin-only', ico:'cal'},
    {href:'ekipy.html', label:'Ekipy', code:'schedule', attr:'data-admin-only', ico:'team'},
    {adminOnly:true,href:'schedule_report.html', label:'Zestawienie pracy', code:'schedule', ico:'chart'},
    {href:'gps.html', label:'Monitoring GPS', code:'gps_monitoring', ico:'gps'},
    {grp:'Wyceny'},
    {href:'wycena.html', label:'Wycena', code:'wycena', attr:'data-super-only', ico:'calc'},
    {href:'wyceny_www.html', label:'Wyceny ze strony', code:'wyceny_www', attr:'data-super-only', ico:'www', licznik:'wyceny_www'},
    {grp:'Administracja'},
    {href:'users.html', label:'Użytkownicy', code:'users', attr:'data-admin-only', ico:'user'},
    {href:'permissions.html', label:'Uprawnienia grup', code:'permissions', attr:'data-super-only', ico:'lock'},
    {href:'statuses.html', label:'Statusy', code:'statuses', attr:'data-admin-only', ico:'tag'},
    {href:'notifications.html', label:'Powiadomienia', code:'notifications', attr:'data-admin-only', ico:'bell'},
    {href:'settings.html', label:'Ustawienia', code:'settings', attr:'data-admin-only', ico:'cog'}
  ];
}

// ── sklepy: menu tylko tego sklepu (zakładki statusów strony + linki) ──
const SM_SKLEPY = {
  frontypax:   {nazwa:'FRONTYPAX', url:'https://frontypax.pl', logo:'<img class="fp" src="assets/img/sklepy/frontypax-logo.svg" alt="frontypax">'},
  zbudujmebel: {nazwa:'ZBUDUJMEBEL', url:'https://zbudujmebel.pl', logo:'<span class="sb-znak"><img src="assets/img/sklepy/zbudujmebel-znak.svg" alt=""><span>zbudujmebel</span></span>'}
};
const SM_STATUSY_SKLEPU = [['processing','W realizacji','clock'],['on-hold','Wstrzymane','pause'],['pending','Czeka na płatność','wallet'],['completed','Zrealizowane','box'],['cancelled','Anulowane','x'],['all','Wszystkie','list'],['trash','Kosz','trash']];
function smSklep(){ const k = document.body && document.body.dataset.sklep; return SM_SKLEPY[k] ? k : ''; }

function smLink(item, current){
  const aliases = item.href === 'my_orders.html' ? ['my_orders.html']
    : item.href === 'wycena.html' ? ['wycena.html','wycena_details.html','wycena_materials.html','wycena_offer.html']
    : item.href === 'orders.html' ? ['orders.html','order_details.html','order_add.html']
    : [item.href.toLowerCase()];
  const cls = aliases.includes(current) ? ' class="active"' : '';
  const extra = item.attr ? ` ${item.attr}="1"` : '';
  const lic = item.licznik ? ` data-licznik="${item.licznik}"` : '';
  return `<a${cls}${extra}${lic} href="${item.href}" title="${item.label}">${smIkona(item.ico)}<span class="l">${item.label}</span>`
    + (item.licznik ? '<span class="sb-bdg"></span><i class="sb-kropka"></i>' : '') + '</a>';
}

function smSidebarRama(){
  const sb = document.querySelector('.sidebar');
  if(!sb) return;
  const kod = smSklep();
  // logo na górze menu (stary .brand zostaje ukryty w CSS)
  let logo = sb.querySelector('.sb-logo');
  if(!logo){ logo = document.createElement('div'); logo.className = 'sb-logo'; sb.insertBefore(logo, sb.firstChild); }
  logo.innerHTML = kod ? SM_SKLEPY[kod].logo : '<a href="aktualnosci.html" title="SMART MEBLE"><img src="assets/img/logo_smartmeble.png" alt="SMART MEBLE"></a>';
  // „← Panel SMART MEBLE” w sklepach
  if(kod && !sb.querySelector('.sb-powrot')){
    const a = document.createElement('a');
    a.className = 'sb-powrot'; a.href = 'orders.html'; a.title = 'Wróć do panelu SMART MEBLE';
    a.innerHTML = smIkona('back') + '<span class="sm">SM</span><span class="l">Panel SMART MEBLE</span>';
    logo.insertAdjacentElement('afterend', a);
  }
  // zwijanie menu (zapamiętane w przeglądarce)
  if(!sb.querySelector('.sb-dol')){
    const d = document.createElement('div'); d.className = 'sb-dol';
    d.innerHTML = '<a href="#" id="sbZwin">'+smIkona('side')+'<span class="l"></span></a>';
    sb.appendChild(d);
    const ustaw = (z) => { document.body.classList.toggle('sb-zwiniete', z); d.querySelector('.l').textContent = z ? 'Rozwiń menu' : 'Zwiń menu'; d.querySelector('a').title = z ? 'Rozwiń menu' : 'Zwiń menu'; };
    let z = false; try{ z = localStorage.getItem('sm_menu_zwiniete') === '1'; }catch(e){}
    ustaw(z);
    d.querySelector('a').onclick = (e) => { e.preventDefault(); const n = !document.body.classList.contains('sb-zwiniete'); ustaw(n); try{ localStorage.setItem('sm_menu_zwiniete', n ? '1' : '0'); }catch(_){} };
  }
}

function renderUnifiedMenu(role){
  const nav = document.querySelector('.sidebar nav') || document.querySelector('nav, #sideNav');
  if(!nav) return null;
  smSidebarRama();
  const current = (location.pathname.split('/').pop() || 'aktualnosci.html').toLowerCase();
  const kod = smSklep();
  if(kod){
    const s = SM_SKLEPY[kod], inny = kod === 'frontypax' ? 'zbudujmebel' : 'frontypax';
    nav.innerHTML = '<div class="sb-grp">Zamówienia sklepu</div>'
      + SM_STATUSY_SKLEPU.map(([st, nazwa, ico]) => `<a href="#" data-st="${st}" title="${nazwa}">${smIkona(ico)}<span class="l">${nazwa}</span><span class="sb-bdg${st==='processing'?'':' szary'}"></span><i class="sb-kropka"></i></a>`).join('')
      + (kod === 'zbudujmebel' ? '<div class="sb-grp">Produkcja</div><a href="#" data-zakupy="1" title="Lista zakupów dla zaznaczonych zamówień">'+smIkona('cart')+'<span class="l">Lista zakupów</span></a>' : '')
      + '<div class="sb-grp">Sklep</div>'
      + `<a href="${s.url}" target="_blank" rel="noopener" title="${s.url}">${smIkona('www')}<span class="l">${s.url.replace('https://','')}</span><span class="zew">↗</span></a>`
      + `<a href="${s.url}/wp-admin/edit.php?post_type=shop_order" target="_blank" rel="noopener" title="Zamówienia w WooCommerce">${smIkona('cog')}<span class="l">Panel WooCommerce</span><span class="zew">↗</span></a>`
      + `<div class="sb-grp" data-inny-sklep>Inny sklep</div>` + smLink({href:inny+'.html', label:SM_SKLEPY[inny].nazwa, code:inny, ico:'shop', licznik:inny}, current);
    smSklepPodlacz(nav);
    return nav;
  }
  nav.innerHTML = buildMenuForRole(role).map(item => item.grp ? `<div class="sb-grp">${item.grp}</div>` : smLink(item, current)).join('');
  return nav;
}

// menu sklepu steruje zakładkami statusów na stronie (#tabs) i pokazuje ich liczniki
function smSklepPodlacz(nav){
  nav.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-st],a[data-zakupy]');
    if(!a) return;
    e.preventDefault();
    document.body.classList.remove('menu-open');
    if(a.dataset.zakupy){
      try{
        if(typeof zazn !== 'undefined' && zazn.size && typeof zakupyZbiorcze === 'function'){ zakupyZbiorcze(); return; }
      }catch(_){}
      const err = document.getElementById('err');
      if(err){ err.textContent = 'Zaznacz na liście zamówienia (pole po lewej), potem kliknij „Lista zakupów”.'; err.scrollIntoView({block:'center', behavior:'smooth'}); }
      return;
    }
    const tab = document.querySelector('#tabs [data-t="'+a.dataset.st+'"]');
    if(tab) tab.click();
  });
  const sync = () => {
    const tabs = document.getElementById('tabs'); if(!tabs) return;
    nav.querySelectorAll('a[data-st]').forEach(a => {
      const t = tabs.querySelector('[data-t="'+a.dataset.st+'"]');
      a.style.display = t ? '' : 'none';
      a.classList.toggle('active', !!(t && t.classList.contains('on')));
      const b = t && t.querySelector('b'), n = b ? Number(b.textContent) : 0;
      const bdg = a.querySelector('.sb-bdg'); if(bdg) bdg.textContent = (n && a.dataset.st !== 'all') ? String(n) : '';
      a.classList.toggle('ma-licznik', a.dataset.st === 'processing' && n > 0);
    });
  };
  const start = () => { const tabs = document.getElementById('tabs'); if(!tabs) return; sync(); new MutationObserver(sync).observe(tabs, {childList:true, subtree:true, attributes:true, attributeFilter:['class']}); };
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
}

// liczniki (nowe zamówienia sklepów, reklamacje, wyceny ze strony) – menu i dzwonek w pasku
let smLicznikiObietnica = null;
function smLiczniki(){
  if(!smLicznikiObietnica) smLicznikiObietnica = apiGet('panel_liczniki.php').then(r => (r && r.liczniki) || {}).catch(() => ({}));
  return smLicznikiObietnica;
}
window.smLiczniki = smLiczniki;
async function smPokazLiczniki(nav){
  const l = await smLiczniki();
  nav.querySelectorAll('a[data-licznik]').forEach(a => {
    const k = a.dataset.licznik, v = l[k];
    const n = v && typeof v === 'object' ? v.nowe : v;
    const bdg = a.querySelector('.sb-bdg'); if(bdg) bdg.textContent = n > 0 ? String(n) : '';
    a.classList.toggle('ma-licznik', n > 0);
    if(v && typeof v === 'object') a.title = SM_SKLEPY[k] ? SM_SKLEPY[k].nazwa + ': ' + n + ' nowych (bez rozpoczętej produkcji), w realizacji ' + v.w_realizacji : a.title;
  });
}

// Menu bez migania (2026-10-22): rysujemy je od razu z ostatnio znanych uprawnień (sessionStorage),
// a odpowiedź serwera tylko je odświeża. Do tego czasu CSS ukrywa menu (theme_v17.css: nav:not(.menu-ready)).
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
    const nav = document.querySelector('.sidebar nav');
    if(nav) smPokazLiczniki(nav);
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
    'frontypax.html':'frontypax',
    'zbudujmebel.html':'zbudujmebel'
  }).forEach(([href,code])=>{
    const link = nav.querySelector(`a[href="${href}"]`);
    if(link && Object.prototype.hasOwnProperty.call(modules, code)) {
      link.style.display = modules[code] ? '' : 'none';
    }
  });

  // nagłówek grupy chowamy, gdy wszystkie jej pozycje są ukryte
  nav.querySelectorAll('.sb-grp').forEach(g => {
    let el = g.nextElementSibling, widac = false;
    while(el && !el.classList.contains('sb-grp')){ if(el.style.display !== 'none') widac = true; el = el.nextElementSibling; }
    g.style.display = widac ? '' : 'none';
  });
  // w sklepie: „← Panel SMART MEBLE” prowadzi tam, gdzie użytkownik ma dostęp
  const powrot = document.querySelector('.sb-powrot');
  if(powrot && Object.prototype.hasOwnProperty.call(modules, 'orders_current') && !modules.orders_current) powrot.href = 'aktualnosci.html';
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applyMenu); else applyMenu();
// wylogowanie / zmiana użytkownika – nie pokazuj menu poprzedniego konta
document.addEventListener('click', e => { if (e.target.closest('[data-logout], .logout, #logoutBtn, #logoutBtnTopbar, a[href*="logout"]')) { try{ sessionStorage.removeItem(MENU_CACHE_KEY); }catch(_){} } }, true);
