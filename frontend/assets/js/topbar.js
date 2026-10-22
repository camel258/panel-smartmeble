
(function(){
  const STYLE_ID = 'smartmeble-topbar-module-style';
  const CSS = `
.mobileMenuBtn{display:none}
.topbar{
  display:flex;align-items:center;justify-content:space-between;gap:16px;
  padding:14px 20px;border-bottom:1px solid var(--border);
  background:linear-gradient(180deg,#2a2a2a 0%, #262626 100%);
  box-shadow:0 4px 20px rgba(0,0,0,.30);
  position:sticky;top:0;z-index:1000
}
.topbar-left{display:flex;align-items:center;gap:16px;min-width:0}
.topbar-logo{height:48px;width:auto;flex:0 0 auto}
.topbar-title-wrap{display:flex;flex-direction:column;min-width:0}
.topbar-title{font-size:18px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.topbar-sub{font-size:12px;color:#fff;font-weight:700;letter-spacing:.22em;text-transform:uppercase}
.topbar-right{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.user-chip{display:flex;align-items:center;gap:10px;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.06);box-shadow:0 8px 18px rgba(0,0,0,.14)}
.user-chip small{display:block;font-size:10px;color:var(--muted);line-height:1.1}
.user-chip strong{display:block;font-size:13px;line-height:1.2}
.topbar-link{font-size:13px;color:var(--muted);padding:8px 12px;border:1px solid rgba(183,211,49,.18);background:rgba(183,211,49,.07);border-radius:12px}
.topbar-link:hover{color:var(--accent)}
@media (max-width:768px){
  .app{display:block}
  .sidebar{
    position:fixed;left:0;top:0;bottom:0;width:260px;z-index:1200;
    transform:translateX(-100%);transition:transform .22s ease;
    box-shadow:0 20px 60px rgba(0,0,0,.35)
  }
  body.menu-open .sidebar{transform:translateX(0)}
  body.menu-open::before{
    content:'';position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1100
  }
  .content{min-width:0}
  .main{padding:10px}
  .mobileMenuBtn{
    display:inline-flex;align-items:center;justify-content:center;
    width:42px;height:42px;border-radius:10px;border:1px solid var(--border);
    background:rgba(255,255,255,.04);color:var(--text);cursor:pointer;
    font-size:22px;line-height:1;flex:0 0 auto
  }
  .topbar{
    flex-direction:column;align-items:flex-start;gap:10px;padding:12px 14px;
  }
  .topbar-left,.topbar-right{width:100%}
  .topbar-right{justify-content:space-between;gap:10px}
  .topbar-logo{height:34px}
  .topbar-title{font-size:16px}
  .topbar-sub{font-size:11px}
  .user-chip{padding:6px 10px}
}
@media (max-width:520px){
  .topbar-right{align-items:flex-start;flex-wrap:wrap}
}`;
  function injectStyle(){
    if(document.getElementById(STYLE_ID)) return;
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = CSS;
    document.head.appendChild(style);
  }
  function renderTopbar(){
    const mount = document.getElementById('appTopbar');
    if(!mount) return;
    const title = mount.dataset.title || 'Panel';
    const subtitle = mount.dataset.subtitle || 'Panel zamówień';
    const extraHtml = mount.dataset.extraHtml || '';
    mount.innerHTML = `
      <div class="topbar">
        <div class="topbar-left">
          <button type="button" id="mobileMenuBtn" class="mobileMenuBtn" aria-label="Menu">☰</button>
          <img src="assets/img/logo_smartmeble.png" class="topbar-logo" alt="Smart Meble"/>
          <div class="topbar-title-wrap">
            <div class="topbar-title">${title}</div>
            <div class="topbar-sub">${subtitle}</div>
          </div>
        </div>
        <div class="topbar-right">
          <div class="user-chip">
            <div>
              <small>Zalogowany użytkownik</small>
              <strong id="topbarUserName">Ładowanie…</strong>
            </div>
          </div>
          <span class="topbar-link" id="topbarChangePassword" title="Przygotowane pod kolejny etap">Zmień hasło</span>
          ${extraHtml}
          <button class="btn" id="logoutBtnTopbar" style="box-shadow:0 10px 24px rgba(183,211,49,.26)">Wyloguj</button>
        </div>
      </div>`;
  }
  async function fillTopbarUser(){
    const setName = (v) => {
      const el = document.getElementById('topbarUserName');
      if(el) el.textContent = v || 'Użytkownik';
    };
    try{
      let me = null;
      try { me = await apiGet('session_check.php'); } catch(e) {}
      const u = (me && (me.user || me)) || {};
      const name = u.full_name || u.name || [u.first_name,u.last_name].filter(Boolean).join(' ').trim() || u.email || u.login || 'Użytkownik';
      setName(name);
    }catch(e){
      setName('Użytkownik');
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
      <div class="cp-modal">
        <div class="cp-head">
          <div>
            <div class="cp-title">Zmień hasło</div>
            <div class="cp-sub">Podaj aktualne hasło i ustaw nowe.</div>
          </div>
          <button class="cp-close" id="cpCloseBtn">✕</button>
        </div>
        <div class="cp-grid">
          <div><label>Aktualne hasło</label><input class="input" id="cpCurrent" type="password"></div>
          <div><label>Nowe hasło</label><input class="input" id="cpNew" type="password"></div>
          <div><label>Powtórz nowe hasło</label><input class="input" id="cpConfirm" type="password"></div>
        </div>
        <div id="cpMsg" class="cp-msg"></div>
        <div class="cp-actions">
          <button class="btn" id="cpCancelBtn" style="background:#404040;color:#fff;box-shadow:none">Anuluj</button>
          <button class="btn" id="cpSaveBtn">Zapisz nowe hasło</button>
        </div>
      </div>`;
    document.body.appendChild(el);

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
