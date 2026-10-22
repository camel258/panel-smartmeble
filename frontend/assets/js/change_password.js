
(function(){
  if(window.__smartmebleChangePasswordLoaded) return;
  window.__smartmebleChangePasswordLoaded = true;

  function ensureModal(){
    if(document.getElementById('changePasswordModal')) return;

    const css = document.createElement('style');
    css.textContent = `
      .cp-backdrop{
        position:fixed; inset:0; background:rgba(0,0,0,.55);
        display:none; align-items:center; justify-content:center;
        z-index:2100; padding:16px;
      }
      .cp-backdrop.is-open{display:flex}
      .cp-modal{
        width:min(520px, 100%);
        background:#232323;
        border:1px solid rgba(255,255,255,.10);
        border-radius:18px;
        box-shadow:0 28px 80px rgba(0,0,0,.42);
        padding:18px;
        color:var(--text, #fff);
      }
      .cp-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
      .cp-title{font-size:20px;font-weight:700}
      .cp-sub{color:var(--muted, #9ca3af);font-size:13px;margin-top:2px}
      .cp-close{
        background:transparent;border:1px solid rgba(255,255,255,.10);color:#fff;
        width:38px;height:38px;border-radius:10px;cursor:pointer
      }
      .cp-grid{display:grid;gap:12px}
      .cp-actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-top:14px}
      .cp-msg{min-height:20px;font-size:13px}
      .cp-msg.err{color:#ffb4b4}
      .cp-msg.ok{color:#b7d331}
    `;
    document.head.appendChild(css);

    const el = document.createElement('div');
    el.id = 'changePasswordModal';
    el.className = 'cp-backdrop';
    el.innerHTML = `
      <div class="cp-modal" role="dialog" aria-modal="true" aria-labelledby="cpTitle">
        <div class="cp-head">
          <div>
            <div class="cp-title" id="cpTitle">Zmień hasło</div>
            <div class="cp-sub">Podaj aktualne hasło i ustaw nowe.</div>
          </div>
          <button type="button" class="cp-close" id="cpCloseBtn" aria-label="Zamknij">✕</button>
        </div>
        <div class="cp-grid">
          <div>
            <label for="cpCurrent">Aktualne hasło</label>
            <input class="input" id="cpCurrent" type="password" autocomplete="current-password">
          </div>
          <div>
            <label for="cpNew">Nowe hasło</label>
            <input class="input" id="cpNew" type="password" autocomplete="new-password">
          </div>
          <div>
            <label for="cpConfirm">Powtórz nowe hasło</label>
            <input class="input" id="cpConfirm" type="password" autocomplete="new-password">
          </div>
        </div>
        <div id="cpMsg" class="cp-msg"></div>
        <div class="cp-actions">
          <button type="button" class="btn" id="cpCancelBtn" style="background:#404040;color:#fff;box-shadow:none">Anuluj</button>
          <button type="button" class="btn" id="cpSaveBtn">Zapisz nowe hasło</button>
        </div>
      </div>
    `;
    document.body.appendChild(el);

    function closeModal(){
      el.classList.remove('is-open');
      const msg = document.getElementById('cpMsg');
      if(msg){ msg.className = 'cp-msg'; msg.textContent = ''; }
      ['cpCurrent','cpNew','cpConfirm'].forEach(id=>{
        const input = document.getElementById(id);
        if(input) input.value = '';
      });
    }

    document.getElementById('cpCloseBtn').onclick = closeModal;
    document.getElementById('cpCancelBtn').onclick = closeModal;
    el.addEventListener('click', (ev)=>{
      if(ev.target === el) closeModal();
    });

    document.getElementById('cpSaveBtn').onclick = async ()=>{
      const current_password = document.getElementById('cpCurrent').value;
      const new_password = document.getElementById('cpNew').value;
      const confirm_password = document.getElementById('cpConfirm').value;
      const msg = document.getElementById('cpMsg');
      msg.className = 'cp-msg';
      msg.textContent = '';

      if(!current_password || !new_password || !confirm_password){
        msg.className = 'cp-msg err';
        msg.textContent = 'Uzupełnij wszystkie pola.';
        return;
      }
      if(new_password !== confirm_password){
        msg.className = 'cp-msg err';
        msg.textContent = 'Nowe hasła nie są takie same.';
        return;
      }
      if(new_password.length < 6){
        msg.className = 'cp-msg err';
        msg.textContent = 'Nowe hasło musi mieć co najmniej 6 znaków.';
        return;
      }

      try{
        const token = localStorage.getItem('token') || '';
        const res = await fetch((window.API_BASE || '../backend/api/') + 'change_password.php', {
          method: 'POST',
          headers: Object.assign({'Content-Type':'application/json'}, token ? {'Authorization':'Bearer ' + token} : {}),
          body: JSON.stringify({ current_password, new_password, confirm_password })
        });
        const data = await res.json().catch(()=>({}));
        if(!res.ok){
          let err = 'Nie udało się zmienić hasła.';
          if(data.error === 'invalid_current_password') err = 'Aktualne hasło jest nieprawidłowe.';
          else if(data.error === 'password_mismatch') err = 'Nowe hasła nie są takie same.';
          else if(data.error === 'password_too_short') err = 'Nowe hasło musi mieć co najmniej 6 znaków.';
          else if(data.error === 'password_same_as_old') err = 'Nowe hasło musi być inne niż aktualne.';
          msg.className = 'cp-msg err';
          msg.textContent = err;
          return;
        }
        msg.className = 'cp-msg ok';
        msg.textContent = 'Hasło zostało zmienione.';
        setTimeout(closeModal, 900);
      }catch(e){
        msg.className = 'cp-msg err';
        msg.textContent = 'Błąd połączenia z serwerem.';
      }
    };

    window.openChangePasswordModal = function(){
      el.classList.add('is-open');
      setTimeout(()=>{
        const input = document.getElementById('cpCurrent');
        if(input) input.focus();
      }, 30);
    };
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    ensureModal();
    const bind = () => {
      const links = document.querySelectorAll('.topbar-link.is-disabled');
      links.forEach(link => {
        if(link.dataset.cpBound === '1') return;
        link.dataset.cpBound = '1';
        link.title = 'Zmień hasło';
        link.style.cursor = 'pointer';
        link.addEventListener('click', (e)=>{
          e.preventDefault();
          if(window.openChangePasswordModal) window.openChangePasswordModal();
        });
      });
    };
    bind();
    const observer = new MutationObserver(bind);
    observer.observe(document.body, { childList: true, subtree: true });
  });
})();
