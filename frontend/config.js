const API_BASE = window.location.origin + '/backend/api/';
function authHeaders(){
  const t = localStorage.getItem('token') || '';
  return t ? {'Authorization':'Bearer '+t} : {};
}
// session_check.php: jedno zapytanie na stronę (menu, pasek górny i strona pytały o to samo nawet 5 razy)
let _sesjaObietnica = null;
function apiGet(p){
  if(p === 'session_check.php'){
    if(!_sesjaObietnica) _sesjaObietnica = _apiGet(p).catch(e => { _sesjaObietnica = null; throw e; });
    return _sesjaObietnica;
  }
  return _apiGet(p);
}
async function _apiGet(p){
  const r = await fetch(API_BASE+p, {headers: authHeaders()});
  const t = await r.text();
  if(!r.ok) throw new Error(t);
  try { return JSON.parse(t); } catch { return t; }
}
async function apiPost(p, body){
  const r = await fetch(API_BASE+p, {method:'POST', headers:{'Content-Type':'application/json', ...authHeaders()}, body: JSON.stringify(body||{})});
  const t = await r.text();
  if(!r.ok) throw new Error(t);
  try { return JSON.parse(t); } catch { return t; }
}async function apiDownload(p, fallbackName){
  const r = await fetch(API_BASE+p, {headers: authHeaders()});
  if(!r.ok) throw new Error(await r.text());
  const cd = r.headers.get('Content-Disposition') || '';
  const m = cd.match(/filename="([^"]+)"/);
  const url = URL.createObjectURL(await r.blob());
  const a = document.createElement('a');
  a.href = url; a.download = m ? m[1] : (fallbackName || 'plik');
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(()=>URL.revokeObjectURL(url), 10000);
}
