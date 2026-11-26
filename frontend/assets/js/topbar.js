
(function(){
  const STYLE_ID = 'smartmeble-topbar-module-style';
  // wygląd paska jest w assets/css/theme_v17.css (nowy wygląd 2026-11-27)
  const CSS = ``;
  function injectStyle(){
    if(document.getElementById(STYLE_ID)) return;
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = CSS;
    document.head.appendChild(style);
  }
  const esc = v => String(v==null?'':v).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const ik = (d) => '<svg class="smi" viewBox="0 0 24 24" aria-hidden="true">'+d+'</svg>';
  const IK = {
    menu:'<path d="M4 6h16M4 12h16M4 18h16"/>', szukaj:'<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>', plus:'<path d="M12 5v14M5 12h14"/>',
    bell:'<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/>', lock:'<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    out:'<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 17l5-5-5-5M15 12H3"/>', moje:'<path d="M9 4h6l1 2h3v15H5V6h3z"/><path d="m9 13 2 2 4-4"/>',
    sync:'<path d="M20 11a8 8 0 0 0-14.5-4.5L4 8M4 4v4h4M4 13a8 8 0 0 0 14.5 4.5L20 16M20 20v-4h-4"/>'
  };
  const SKLEP = (document.body && document.body.dataset.sklep) || '';
  const NAZWA_SKLEPU = {frontypax:'FRONTYPAX', zbudujmebel:'ZBUDUJMEBEL'}[SKLEP] || '';
  // prawa część paska: przyciski strony (extraHtml), „+ Nowe zamówienie” / „Odśwież ze sklepu”, dzwonek, użytkownik
  function prawaCzesc(extraHtml){
    return `
        <div class="tb2-p">
          ${SKLEP ? '<button type="button" class="btn" id="tb2Sync" title="Pobierz zmiany ze sklepu teraz">'+ik(IK.sync)+'<span class="l">Odśwież ze sklepu</span></button>'
                  : '<a class="btn" id="tb2Nowe" href="order_add.html" hidden>'+ik(IK.plus)+'<span class="l">Nowe zamówienie</span></a>'}
          <button type="button" class="tb2-ikb" id="tb2DzwBtn" title="Co nowego" aria-label="Co nowego" hidden>${ik(IK.bell)}<span class="sb-bdg"></span></button>
          <button type="button" class="tb2-ja" id="tb2JaBtn" aria-haspopup="true"><span class="tb2-av" id="tb2Av">…</span><div><b id="topbarUserName">Ładowanie…</b><small id="tb2Rola"></small></div></button>
        </div>
        <div class="tb2-rozw" id="tb2Dzw"><div class="nag">Co nowego</div><div id="tb2DzwLista"></div></div>
        <div class="tb2-rozw" id="tb2Ja">
          <div class="pw"><b id="tb2JaNazwa"></b><span id="tb2JaRola"></span></div><hr>
          <a href="orders.html" id="tb2Moje" hidden>${ik(IK.moje)}Moje zamówienia (rozpisuję)</a>
          <button type="button" class="poz" id="topbarChangePassword">${ik(IK.lock)}Zmień hasło</button><hr>
          <button type="button" class="poz" id="logoutBtnTopbar" data-logout>${ik(IK.out)}Wyloguj</button>
        </div>`;
  }
  function szukajHtml(){
    const ph = SKLEP ? 'Szukaj w zamówieniach '+NAZWA_SKLEPU+': numer, klient, telefon…' : 'Szukaj zamówienia: nazwisko, telefon, przedmiot…';
    return '<label class="tb2-szukaj" id="tb2Szukaj" hidden>'+ik(IK.szukaj)+'<input id="tb2Q" type="search" autocomplete="off" placeholder="'+esc(ph)+'" aria-label="Szukaj zamówienia"><kbd>Ctrl K</kbd></label>';
  }
  function renderTopbar(){
    const mount = document.getElementById('appTopbar');
    if(mount){
      const title = mount.dataset.title || 'Panel';
      const subtitle = mount.dataset.subtitle || 'Panel zamówień';
      const extraHtml = mount.dataset.extraHtml || '';
      mount.innerHTML = `
      <div class="topbar">
        <button type="button" id="mobileMenuBtn" class="mobileMenuBtn" aria-label="Menu">${ik(IK.menu)}</button>
        <div class="tb2-tyt"><h1>${title}</h1><div>${subtitle}</div></div>
        ${szukajHtml()}<div class="tb2-pusty" id="tb2Pusty"></div>
        ${prawaCzesc('')}
      </div>${extraHtml ? '<div class="tb2-akcje">'+extraHtml+'</div>' : ''}`;
      return;
    }
    // strony z własnym paskiem (Wycena, Nowe zamówienie): zostawiamy ich przyciski, dokładamy resztę
    const stary = document.querySelector('.content > .topbar');
    if(!stary || stary.dataset.tb2) return;
    stary.dataset.tb2 = '1';
    const wyl = stary.querySelector('#logoutBtn'); if(wyl) wyl.style.display = 'none';
    stary.insertAdjacentHTML('afterbegin', `<button type="button" id="mobileMenuBtn" class="mobileMenuBtn" aria-label="Menu">${ik(IK.menu)}</button>`);
    const prawy = stary.children[2];
    if(prawy){ prawy.classList.add('tb2-extra'); prawy.style.marginLeft = 'auto'; }
    stary.insertAdjacentHTML('beforeend', prawaCzesc(''));
    const nowe = stary.querySelector('#tb2Nowe'); if(nowe) nowe.remove();
  }
  function inicjaly(n){ const c = String(n||'').trim().split(/\s+/).filter(Boolean); return ((c[0]||'?')[0] + (c.length>1 ? c[c.length-1][0] : '')).toUpperCase(); }
  async function fillTopbarUser(){
    let me = null;
    try { me = await apiGet('session_check.php'); } catch(e) {}
    const u = (me && (me.user || me)) || {};
    const caps = (me && me.caps) || {}, mod = caps.modules || {};
    const name = u.full_name || u.name || [u.first_name,u.last_name].filter(Boolean).join(' ').trim() || u.email || u.login || 'Użytkownik';
    const rola = u.role_name || '';
    const rid = Number(u.role_id ?? u.role ?? 0);
    const set = (id, v) => { const el = document.getElementById(id); if(el) el.textContent = v; };
    set('topbarUserName', name); set('tb2JaNazwa', name); set('tb2Rola', rola); set('tb2JaRola', rola); set('tb2Av', inicjaly(name));
    if(!me) return;
    const uid = Number(u.uid || u.id || 0);
    const zamowienia = rid !== 3 && mod.orders_current !== 0;
    const nowe = document.getElementById('tb2Nowe'); if(nowe) nowe.hidden = !zamowienia || /zewn/.test(rola.toLowerCase());
    const moje = document.getElementById('tb2Moje'); if(moje && zamowienia && uid){ moje.href = 'orders.html?scheduler=' + uid; moje.hidden = false; }
    const sz = document.getElementById('tb2Szukaj'), pusty = document.getElementById('tb2Pusty');
    if(sz && (SKLEP || zamowienia)){ sz.hidden = false; if(pusty) pusty.hidden = true; }
    dzwonek(caps);
  }
  async function dzwonek(caps){
    if(typeof window.smLiczniki !== 'function') return;
    const l = await window.smLiczniki();
    const poz = [];
    const sk = (k, nazwa) => { const v = l[k]; if(v && v.nowe > 0) poz.push(['frontypax.html'.replace('frontypax',k), v.nowe+' '+(v.nowe===1?'nowe zamówienie':(v.nowe%10>=2&&v.nowe%10<=4&&(v.nowe%100<12||v.nowe%100>14)?'nowe zamówienia':'nowych zamówień'))+' '+nazwa, 'bez rozpoczętej produkcji · w realizacji '+v.w_realizacji, v.nowe]); };
    sk('frontypax','FRONTYPAX'); sk('zbudujmebel','ZBUDUJMEBEL');
    if(l.wyceny_www > 0) poz.push(['wyceny_www.html', 'Wyceny ze strony: '+l.wyceny_www+' nowe', 'czekają na odpowiedź', l.wyceny_www]);
    if(l.reklamacje > 0) poz.push(['complaints.html', 'Otwarte reklamacje: '+l.reklamacje, 'do załatwienia', l.reklamacje]);
    const btn = document.getElementById('tb2DzwBtn'), lista = document.getElementById('tb2DzwLista');
    if(!btn || !lista) return;
    if(!Object.keys(l).length) return;
    btn.hidden = false;
    const suma = poz.reduce((a,p)=>a+p[3],0);
    btn.querySelector('.sb-bdg').textContent = suma > 0 ? String(suma > 99 ? '99+' : suma) : '';
    lista.innerHTML = poz.length ? poz.map(p=>'<a class="pw" href="'+p[0]+'"><b>'+esc(p[1])+'</b><span>'+esc(p[2])+'</span></a>').join('') : '<div class="pw"><span>Nic nowego – wszystko ogarnięte.</span></div>';
  }
  function bindTopbar(){
    const roz = (id) => { document.querySelectorAll('.tb2-rozw').forEach(r=>{ if(r.id!==id) r.classList.remove('on'); }); const el = document.getElementById(id); if(el) el.classList.toggle('on'); };
    const ja = document.getElementById('tb2JaBtn'); if(ja) ja.addEventListener('click', e=>{ e.stopPropagation(); roz('tb2Ja'); });
    const dz = document.getElementById('tb2DzwBtn'); if(dz) dz.addEventListener('click', e=>{ e.stopPropagation(); roz('tb2Dzw'); });
    document.addEventListener('click', e=>{ if(!e.target.closest('.tb2-rozw')) document.querySelectorAll('.tb2-rozw').forEach(r=>r.classList.remove('on')); });
    document.addEventListener('keydown', e=>{ if(e.key==='Escape') document.querySelectorAll('.tb2-rozw').forEach(r=>r.classList.remove('on')); });
    // szukanie: w sklepie – pole szukania tej strony, w panelu – lista zamówień aktualnych
    const q = document.getElementById('tb2Q');
    if(q){
      q.addEventListener('keydown', e=>{
        if(e.key !== 'Enter') return;
        e.preventDefault(); e.stopPropagation();
        const v = q.value.trim();
        if(SKLEP){ const pq = document.getElementById('q'), b = document.getElementById('qBtn'); if(pq){ pq.value = v; if(b) b.click(); } return; }
        const cur = (location.pathname.split('/').pop()||'').toLowerCase();
        if(cur === 'orders.html' && document.getElementById('q') && document.getElementById('go')){ document.getElementById('q').value = v; document.getElementById('go').click(); return; }
        location.href = 'orders.html' + (v ? '?q=' + encodeURIComponent(v) : '');
      }, true);
      document.addEventListener('keydown', e=>{ if((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='k'){ const sz = document.getElementById('tb2Szukaj'); if(sz && !sz.hidden){ e.preventDefault(); q.focus(); q.select(); } } });
    }
    const sync = document.getElementById('tb2Sync');
    if(sync){
      const pb = document.getElementById('syncBtn');
      if(pb) pb.style.display = 'none';
      sync.addEventListener('click', ()=>{ const b = document.getElementById('syncBtn'); if(b) b.click(); });
    }
  }
  function initMobileMenu(){
    const btn = document.getElementById('mobileMenuBtn');
    const sidebar = document.querySelector('.sidebar');
    if(!btn || !sidebar) return;
    btn.addEventListener('click', (e)=>{
      e.stopPropagation();
      document.body.classList.toggle('menu-open');
    });
    document.addEventListener('click', (e)=>{
      if(window.innerWidth > 768) return;
      if(document.body.classList.contains('menu-open') && !sidebar.contains(e.target) && e.target !== btn){
        document.body.classList.remove('menu-open');
      }
    });
    window.addEventListener('resize', ()=>{
      if(window.innerWidth > 768) document.body.classList.remove('menu-open');
    });
  }
  function bindLogout(){
    const btn = document.getElementById('logoutBtnTopbar');
    if(!btn) return;
    btn.addEventListener('click', ()=>{
      localStorage.removeItem('token');
      location.href='login.html';
    });
  }

  function initSmartEnter(){
    document.addEventListener('keydown', (e)=>{
      if(e.key !== 'Enter') return;
      if(e.defaultPrevented) return;
      const t = e.target;
      if(!t) return;
      const tag = (t.tagName||'').toUpperCase();
      if(tag === 'TEXTAREA') return;
      const type = String(t.type||'').toLowerCase();
      if(type === 'submit' || type === 'button' || type === 'checkbox' || type === 'radio' || type === 'file') return;
      const container = t.closest('form,[role="dialog"],.modal,.cp-modal,.section,.toolbar,.filters,.searchRow,.actions,.card') || document;
      const candidates = Array.from(container.querySelectorAll('button,.btn,input[type="submit"],input[type="button"]')).filter(el=>{
        if(!el || el.disabled) return false;
        const style = window.getComputedStyle(el);
        if(style.display === 'none' || style.visibility === 'hidden') return false;
        const txt = ((el.textContent||'') + ' ' + (el.value||'')).toLowerCase();
        return /(szukaj|ok|zatwierdź|zatwierdz|zastosuj|zapisz|filtruj|pokaż|pokaz|dodaj)/.test(txt);
      });
      const btn = candidates[0];
      if(btn){ e.preventDefault(); btn.click(); }
    }, true);
  }

  document.addEventListener('DOMContentLoaded', async ()=>{
    injectStyle();
    renderTopbar();
    bindTopbar();
    bindLogout();
    initMobileMenu();
    initSmartEnter();
    await fillTopbarUser();
  });

/* ===== change password (added, non-invasive) ===== */
(function(){
  function injectCpStyle(){
    if(document.getElementById('cp-style')) return;
    const s = document.createElement('style');
    s.id='cp-style';
    s.textContent = `
    .cp-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:2100;padding:16px}
    .cp-backdrop.is-open{display:flex}
    .cp-modal{width:min(520px,100%);background:#232323;border:1px solid rgba(255,255,255,.10);border-radius:18px;box-shadow:0 28px 80px rgba(0,0,0,.42);padding:18px;color:#fff}
    .cp-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
    .cp-title{font-size:20px;font-weight:700}
    .cp-sub{color:#9ca3af;font-size:13px}
    .cp-close{background:transparent;border:1px solid rgba(255,255,255,.10);color:#fff;width:38px;height:38px;border-radius:10px;cursor:pointer}
    .cp-grid{display:grid;gap:12px}
    .cp-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:14px}
    .cp-msg{min-height:20px;font-size:13px}
    .cp-msg.err{color:#ffb4b4}
    .cp-msg.ok{color:#b7d331}
    `;
    document.head.appendChild(s);
  }
  function ensureModal(){
    if(document.getElementById('changePasswordModal')) return;
    injectCpStyle();
    const el = document.createElement('div');
    el.id = 'changePasswordModal';
    el.className = 'cp-backdrop';
    el.innerHTML = `
      <form class="cp-modal" id="cpForm" autocomplete="on">
        <div class="cp-head">
          <div>
            <div class="cp-title">Zmień hasło</div>
            <div class="cp-sub">Podaj aktualne hasło i ustaw nowe.</div>
          </div>
          <button type="button" class="cp-close" id="cpCloseBtn">✕</button>
        </div>
        <div class="cp-grid">
          <input id="cpUsername" name="username" type="text" autocomplete="username" readonly tabindex="-1" aria-hidden="true" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none">
          <div><label>Aktualne hasło</label><input class="input" id="cpCurrent" name="current-password" type="password" autocomplete="current-password"></div>
          <div><label>Nowe hasło</label><input class="input" id="cpNew" name="new-password" type="password" autocomplete="new-password"></div>
          <div><label>Powtórz nowe hasło</label><input class="input" id="cpConfirm" name="confirm-password" type="password" autocomplete="new-password"></div>
        </div>
        <div id="cpMsg" class="cp-msg"></div>
        <div class="cp-actions">
          <button type="button" class="btn" id="cpCancelBtn" style="background:#404040;color:#fff;box-shadow:none">Anuluj</button>
          <button type="button" class="btn" id="cpSaveBtn">Zapisz nowe hasło</button>
        </div>
      </form>`;
    document.body.appendChild(el);
    // Pole login w formularzu – bez niego Chrome bierze za login ostatnie pole tekstowe strony (np. "Miesiąc") i wkleja tam e-mail
    document.getElementById('cpForm').addEventListener('submit', e=>e.preventDefault());
    apiGet('session_check.php').then(me=>{
      const u = (me && (me.user || me)) || {};
      const i = document.getElementById('cpUsername'); if(i) i.value = u.email || u.login || '';
    }).catch(()=>{});

    function closeModal(){
      el.classList.remove('is-open');
      const msg = document.getElementById('cpMsg');
      if(msg){ msg.className='cp-msg'; msg.textContent=''; }
      ['cpCurrent','cpNew','cpConfirm'].forEach(id=>{
        const i=document.getElementById(id); if(i) i.value='';
      });
    }
    document.getElementById('cpCloseBtn').onclick = closeModal;
    document.getElementById('cpCancelBtn').onclick = closeModal;
    el.addEventListener('click', e=>{ if(e.target===el) closeModal(); });

    document.getElementById('cpSaveBtn').onclick = async ()=>{
      const current_password = document.getElementById('cpCurrent').value;
      const new_password = document.getElementById('cpNew').value;
      const confirm_password = document.getElementById('cpConfirm').value;
      const msg = document.getElementById('cpMsg');
      msg.className='cp-msg'; msg.textContent='';

      if(!current_password || !new_password || !confirm_password){
        msg.className='cp-msg err'; msg.textContent='Uzupełnij wszystkie pola.'; return;
      }
      if(new_password !== confirm_password){
        msg.className='cp-msg err'; msg.textContent='Nowe hasła nie są takie same.'; return;
      }
      if(new_password.length < 6){
        msg.className='cp-msg err'; msg.textContent='Min. 6 znaków.'; return;
      }
      try{
        const token = localStorage.getItem('token') || '';
        const res = await fetch((window.API_BASE || '../backend/api/') + 'change_password.php', {
          method:'POST',
          headers: Object.assign({'Content-Type':'application/json'}, token?{'Authorization':'Bearer '+token}:{ }),
          body: JSON.stringify({ current_password, new_password, confirm_password })
        });
        const data = await res.json().catch(()=>({}));
        if(!res.ok){
          let err='Błąd zmiany hasła';
          if(data.error==='invalid_current_password') err='Błędne aktualne hasło';
          else if(data.error==='password_mismatch') err='Hasła nie są takie same';
          else if(data.error==='password_too_short') err='Hasło za krótkie';
          else if(data.error==='password_same_as_old') err='Nowe takie samo jak stare';
          msg.className='cp-msg err'; msg.textContent=err; return;
        }
        msg.className='cp-msg ok'; msg.textContent='Zmieniono hasło';
        setTimeout(()=>el.classList.remove('is-open'), 800);
      }catch(e){
        msg.className='cp-msg err'; msg.textContent='Błąd połączenia';
      }
    };
    window.openChangePasswordModal = function(){
      el.classList.add('is-open');
      setTimeout(()=>{ const i=document.getElementById('cpCurrent'); if(i) i.focus(); }, 30);
    };
  }
  function bindChangePassword(){
    const link = document.getElementById('topbarChangePassword');
    if(!link) return;
    link.addEventListener('click', e=>{
      e.preventDefault();
      if(window.openChangePasswordModal) window.openChangePasswordModal();
    });
  }
  document.addEventListener('DOMContentLoaded', ()=>{
    ensureModal();
    bindChangePassword();
  });
})();

})();;
