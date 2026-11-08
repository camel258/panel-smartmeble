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

// Zdjęcia JPG z telefonu (3–7 MB) zmniejszane w przeglądarce przed wysłaniem: dłuższy bok 2560 px, jakość 85% (~0,5–1 MB).
// PNG (rysunki, zrzuty), PDF, małe pliki i formaty nieczytelne dla przeglądarki (np. HEIC) idą bez zmian. 2026-11-08
async function zmniejszZdjecie(f, maks = 2560, jakosc = 0.85){
  try{
    if(!f || !/^image\/jpe?g$/i.test(f.type || '') || f.size < 1500000 || typeof createImageBitmap !== 'function') return f;
    const bmp = await createImageBitmap(f, {imageOrientation: 'from-image'});
    const s = Math.min(1, maks / Math.max(bmp.width, bmp.height));
    const c = document.createElement('canvas'); c.width = Math.round(bmp.width * s); c.height = Math.round(bmp.height * s);
    c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height); if(bmp.close) bmp.close();
    const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', jakosc));
    if(!blob || blob.size >= f.size * 0.9) return f;
    return new File([blob], f.name.replace(/\.(jpe?g)$/i, '') + '.jpg', {type: 'image/jpeg', lastModified: f.lastModified});
  }catch(e){ return f; }
}
