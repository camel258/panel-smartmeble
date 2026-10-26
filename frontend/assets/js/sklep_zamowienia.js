// Wspólny skrypt zakładek sklepów (FRONTYPAX, ZBUDUJMEBEL) – sklep z <body data-sklep="…">
const SKLEP = document.body.dataset.sklep || 'frontypax';
const $ = id => document.getElementById(id);
const esc = v => String(v==null?'':v).replace(/[&<>"']/g, s=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[s]));
const zl = v => Number(v||0).toLocaleString('pl-PL',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' zł';
const KOLORY = {pending:'#6b7280','on-hold':'#d97706',processing:'#2563eb',completed:'#16a34a',cancelled:'#7f1d1d',refunded:'#7c3aed',failed:'#b91c1c','checkout-draft':'#4b5563'};
const KOLEJNOSC = ['processing','on-hold','pending','completed','cancelled','refunded','failed'];
let cur = 'processing', STATUSY = {}, ETAPY = {}, strona = 1, detail = null;
const zazn = new Set();
// kolory etapów (jak etapy w „Zamówieniach aktualnych” – zaznaczony świeci kolorem)
const KOLOR_ETAPU = {zamowiono:'#6366f1',w_stolarni:'#0ea5e9',rozkroj:'#14b8a6',rozkroj_cnc:'#14b8a6',pociete:'#22c55e',pociete_frez:'#22c55e',oklejone:'#84cc16',lakiernia:'#a855f7',pomalowane:'#d946ef',otwory:'#eab308',do_pakowania:'#f59e0b',spakowane:'#f97316',kurier:'#ef4444',wyslane:'#16a34a',cnc:'#eab308',okucia:'#f59e0b'};
const SKROT = {plyta:'płyta', lakier:'lakier', mebel:'mebel'};
function ikony(grupa, zrobione, duze){
  const def = (ETAPY[grupa]||{}).etapy || {};
  return Object.keys(def).map((k,i)=>{ const on = (zrobione||[]).includes(k); return '<span class="fx-e'+(on?' on':'')+'" style="'+(on?'background:'+KOLOR_ETAPU[k]:'')+'" title="'+(i+1)+'. '+esc(def[k])+(on?' – zrobione':'')+'">'+(i+1)+'</span>'; }).join('');
}
function produkcja(r){
  const grupy = (r.grupy && r.grupy.length) ? r.grupy : [Object.keys(ETAPY)[0] || 'plyta'];
  return grupy.map(g=>'<div class="fx-etapy"><span class="g">'+esc(SKROT[g]||g)+'</span>'+ikony(g, (r.etapy||{})[g])+'</div>').join('');
}
function odswiezZazn(){
  $('bulk').classList.toggle('on', zazn.size>0);
  $('bulkN').textContent = 'Zaznaczono: '+zazn.size;
  $('bulkSt').innerHTML = Object.keys(STATUSY).filter(s=>s!=='trash').map(s=>'<option value="'+esc(s)+'">'+esc(STATUSY[s])+'</option>').join('');
}
const pill = s => '<span class="fx-pill" style="background:'+(KOLORY[s]||'#4b5563')+'">'+esc(STATUSY[s]||s)+'</span>';
const api = (akcja, par) => 'sklep_zamowienia.php?sklep='+SKLEP+'&action='+akcja+(par?'&'+par:'');
function blad(e){ let m = String(e&&e.message||e); try{ const j = JSON.parse(m); m = j.details || j.error || m; }catch(_){} return m==='forbidden' ? 'Brak dostępu do tej zakładki – nadaj go w Uprawnieniach grup.' : m; }
function showErr(e){ $('err').textContent = 'Błąd: ' + blad(e); }

async function loadList(){
  $('err').textContent='';
  const par = 'status='+encodeURIComponent(cur)+'&q='+encodeURIComponent($('q').value.trim())+'&od='+$('od').value+'&do='+$('do').value+'&strona='+strona;
  try{
    const r = await apiGet(api('list', par));
    STATUSY = r.statusy || {}; ETAPY = r.etapy_def || ETAPY;
    const c = r.liczniki || {}, wszystkie = Object.keys(c).filter(s=>s!=='trash').reduce((a,s)=>a+c[s],0);
    const zak = KOLEJNOSC.filter(s=>STATUSY[s] && (c[s]||s==='processing')).map(s=>[s,STATUSY[s],c[s]||0])
      .concat(Object.keys(c).filter(s=>!KOLEJNOSC.includes(s) && s!=='trash').map(s=>[s,STATUSY[s]||s,c[s]]))
      .concat([['all','Wszystkie',wszystkie],['trash','Kosz',c.trash||0]]);
    $('tabs').innerHTML = zak.map(t=>'<button class="fx-tab'+(cur===t[0]?' on':'')+'" data-t="'+esc(t[0])+'">'+esc(t[1])+'<b>'+t[2]+'</b></button>').join('');
    $('tabs').querySelectorAll('[data-t]').forEach(b=>b.onclick=()=>{ cur=b.dataset.t; strona=1; loadList(); });
    $('syncInfo').textContent = r.ostatnia_synchronizacja ? 'Ostatnia synchronizacja ze sklepem: '+String(r.ostatnia_synchronizacja).replace('T',' ')+' (automatycznie co 10 minut, nowe zamówienia od razu)' : 'Jeszcze nie zsynchronizowano – kliknij „Odśwież ze sklepu”.';
    const items = r.items || [];
    const kosz = cur === 'trash';
    [...zazn].forEach(id=>{ if(!items.some(i=>String(i.wc_id)===String(id))) zazn.delete(id); }); odswiezZazn();
    if(!items.length){ $('listWrap').innerHTML='<div class="fx-empty">'+(kosz?'Kosz jest pusty. Zamówienia usunięte w panelu lub w sklepie trafiają tu na 30 dni.':'Brak zamówień w tym widoku.')+'</div>'; return; }
    const stron = Math.max(1, Math.ceil(r.razem / r.na_strone));
    $('listWrap').innerHTML = '<div style="overflow:auto"><table class="fx-table"><thead><tr>'+(kosz?'':'<th class="ck"><input type="checkbox" id="ckAll" title="Zaznacz wszystkie na tej stronie"></th>')+'<th>Numer</th><th>Data</th><th>Klient</th><th>Telefon</th><th>Dostawa</th><th>Płatność</th><th style="text-align:right">Wartość</th><th>Status</th><th>Produkcja</th></tr></thead><tbody>'
      + items.map(r=>'<tr data-id="'+r.wc_id+'">'+(kosz?'':'<td class="ck"><input type="checkbox" data-ck="'+r.wc_id+'"'+(zazn.has(String(r.wc_id))?' checked':'')+'></td>')+'<td class="fx-num">#'+esc(r.numer)+'</td><td>'+esc(r.data)+'</td><td><b>'+esc(r.klient)+'</b>'+(r.miasto?'<br><span class="fx-muted">'+esc(r.miasto)+'</span>':'')+'</td><td>'+esc(r.telefon)+'</td><td>'+esc(r.dostawa)+'</td><td>'+esc(r.platnosc)+'</td><td class="fx-kw">'+zl(r.wartosc)+'</td><td>'+(kosz?pill('trash')+(r.status_przed_koszem?'<br><span class="fx-muted">było: '+esc(STATUSY[r.status_przed_koszem]||r.status_przed_koszem)+'</span>':''):pill(r.status))+'</td><td>'+produkcja(r)+'</td></tr>').join('')
      + '</tbody></table></div>'
      + (stron > 1 ? '<div class="fx-pager"><button class="btn btn-ghost" id="prev"'+(strona<=1?' disabled':'')+'>‹ Poprzednie</button><span>Strona '+strona+' z '+stron+' ('+r.razem+' zamówień)</span><button class="btn btn-ghost" id="next"'+(strona>=stron?' disabled':'')+'>Następne ›</button></div>' : '');
    $('listWrap').querySelectorAll('tr[data-id]').forEach(tr=>tr.onclick=e=>{ if(e.target.closest('.ck')) return; openDetail(tr.dataset.id); });
    $('listWrap').querySelectorAll('[data-ck]').forEach(ck=>ck.onchange=()=>{ ck.checked ? zazn.add(ck.dataset.ck) : zazn.delete(ck.dataset.ck); odswiezZazn(); });
    if($('ckAll')) $('ckAll').onchange=()=>{ $('listWrap').querySelectorAll('[data-ck]').forEach(ck=>{ ck.checked=$('ckAll').checked; ck.checked ? zazn.add(ck.dataset.ck) : zazn.delete(ck.dataset.ck); }); odswiezZazn(); };
    if($('prev')) $('prev').onclick=()=>{ strona--; loadList(); };
    if($('next')) $('next').onclick=()=>{ strona++; loadList(); };
  }catch(e){ showErr(e); $('listWrap').innerHTML=''; }
}

async function openDetail(id){
  try{
    detail = await apiGet(api('get', 'id='+encodeURIComponent(id)));
    STATUSY = detail.statusy || STATUSY; ETAPY = detail.etapy_def || ETAPY;
    renderDetail();
    $('drawer').classList.add('open'); $('drawer').setAttribute('aria-hidden','false');
    history.replaceState(null,'','?id='+id);
  }catch(e){ showErr(e); }
}
function closeDetail(){ $('drawer').classList.remove('open'); $('drawer').setAttribute('aria-hidden','true'); history.replaceState(null,'',location.pathname); loadList(); }

// paczka produkcyjna pozycji (zbudujmebel): ZIP z formatkami, DXF, okuciami, etykietami i instrukcją
function paczka(z, p){
  if (SKLEP !== 'zbudujmebel') return '';
  if (!p.paczka) return '<div class="fx-muted" style="margin-top:6px;font-size:13px">Paczka produkcyjna powstanie automatycznie po opłaceniu zamówienia.</div>';
  const url = API_BASE + 'sklep_paczka.php?sklep=' + SKLEP + '&id=' + z.wc_id + '&item=' + p.item_id + '&token=' + encodeURIComponent(localStorage.getItem('token')||'');
  return '<a class="btn" style="margin-top:8px;display:inline-block" href="' + esc(url) + '">Pobierz paczkę produkcyjną (ZIP)</a>';
}
function adres(a){ return [a.firma, a.osoba, a.adres, [a.kod, a.miasto].filter(Boolean).join(' '), a.kraj && a.kraj!=='PL' ? a.kraj : ''].filter(Boolean).map(esc).join('<br>'); }

function renderDetail(){
  const z = detail.item, d = z.dane || {}, f = d.faktura || {}, w = d.wysylka || {};
  const poz = (d.pozycje||[]).map(p=>'<div class="fx-poz">'+(p.obraz?'<img src="'+esc(p.obraz)+'" alt="" loading="lazy">':'')+'<div class="t"><b>'+esc(p.ilosc)+' × '+esc(p.nazwa)+'</b>'
      + (p.meta&&p.meta.length ? '<dl>'+p.meta.map(m=>'<dt>'+esc(m.k)+'</dt><dd>'+esc(m.v)+'</dd>').join('')+'</dl>' : '') + paczka(z, p) + '</div><div class="kw">'+zl(p.wartosc)+'</div></div>').join('');
  const dost = (d.dostawa||[]).map(l=>'<div class="fx-sum"><span>Dostawa: '+esc(l.nazwa)+'</span><span>'+zl(l.wartosc)+'</span></div>').join('');
  const notatki = (detail.notatki||[]).map(n=>'<div class="fx-note'+(n.dla_klienta?' klient':'')+'"><small>'+esc(n.data||'')+(n.dla_klienta?' · wysłana do klienta':'')+(n.autor&&n.autor!=='system'?' · '+esc(n.autor):'')+'</small>'+esc(n.tresc)+'</div>').join('') || '<div class="fx-muted">Brak notatek.</div>';
  const hist = (detail.historia||[]).map(h=>'<div class="fx-note"><small>'+esc(h.data)+' · '+esc(h.user_name||'')+'</small>'+esc(h.akcja==='status'?'Status: '+h.opis:(h.akcja==='notatka'?'Notatka: '+h.opis:(h.akcja==='etap'?'Etap: '+h.opis:h.opis)))+'</div>').join('');
  const kosz = z.status === 'trash';
  const grupy = (z.grupy && z.grupy.length) ? z.grupy : [Object.keys(ETAPY)[0] || 'plyta'];
  const zrobione = detail.etapy || {};
  const etapyHtml = grupy.map(g=>{ const def = (ETAPY[g]||{}).etapy||{}; return '<div class="fx-sec"><h3>Etapy produkcji – '+esc((ETAPY[g]||{}).nazwa||g)+'</h3><div class="fx-stages">'
      + Object.keys(def).map((k,i)=>{ const e = (zrobione[g]||{})[k]; return '<button type="button" class="fx-stage'+(e?' on':'')+'" data-g="'+esc(g)+'" data-e="'+esc(k)+'" style="'+(e?'background:'+KOLOR_ETAPU[k]+'22;border-color:'+KOLOR_ETAPU[k]:'')+'"'+(kosz?' disabled':'')+'><span class="fx-e'+(e?' on':'')+'" style="'+(e?'background:'+KOLOR_ETAPU[k]:'')+'">'+(i+1)+'</span><span>'+esc(def[k])+(e?'<small>'+esc(e.kto||'')+' · '+esc(e.kiedy||'')+'</small>':'')+'</span></button>'; }).join('')
      + '</div></div>'; }).join('');
  const opcje = Object.keys(STATUSY).filter(s=>s!=='trash').map(s=>'<option value="'+esc(s)+'"'+(s===z.status?' selected':'')+'>'+esc(STATUSY[s])+'</option>').join('');
  $('panel').innerHTML =
    '<div class="fx-head"><h2>Zamówienie #'+esc(z.numer)+' '+pill(z.status)+'</h2><a class="btn btn-ghost" href="'+esc(detail.link_sklep)+'" target="_blank" rel="noopener">Otwórz w sklepie</a>'+(kosz?'<button class="btn" id="restBtn" type="button">Przywróć z kosza</button>':'<button class="btn btn-danger" id="delBtn" type="button">Usuń zamówienie</button>')+'<button class="btn btn-ghost" id="closeBtn" type="button">Zamknij</button></div>'
    + (kosz ? '<div class="fx-warn" style="margin-bottom:14px">Zamówienie jest w koszu (w sklepie i w panelu). Sklep usunie je na stałe po 30 dniach. Przywrócenie ustawi status sprzed usunięcia'+(z.status_przed_koszem?' („'+esc(STATUSY[z.status_przed_koszem]||z.status_przed_koszem)+'”)':'')+'.</div>' : '')
    + '<div class="fx-grid"><div>'
    +   '<div class="fx-sec"><h3>Pozycje</h3>'+(poz||'<div class="fx-muted">Brak pozycji.</div>')
    +     '<div style="margin-top:8px">'+dost+(d.rabat?'<div class="fx-sum"><span>Rabat</span><span>−'+zl(d.rabat)+'</span></div>':'')+'<div class="fx-sum big"><span>Razem</span><span>'+zl(z.wartosc)+'</span></div></div></div>'
    +   etapyHtml
    +   (d.uwagi_klienta ? '<div class="fx-sec"><h3>Uwagi klienta</h3><div style="white-space:pre-wrap">'+esc(d.uwagi_klienta)+'</div></div>' : '')
    +   '<div class="fx-sec"><h3>Wiadomości i komentarze</h3><div class="fx-msg">'
    +     '<div class="fx-actions"><textarea class="input" id="msgTxt" rows="3" placeholder="Wiadomość do klienta – dostanie ją mailem ze sklepu i zobaczy w swoim koncie"></textarea><button class="btn" id="msgBtn" type="button">Wyślij do klienta</button></div>'
    +     '<div class="fx-actions"><textarea class="input" id="noteTxt" rows="3" placeholder="Komentarz wewnętrzny – widoczny w panelu i w sklepie, klient go nie widzi"></textarea><button class="btn btn-ghost" id="noteBtn" type="button">Dodaj komentarz</button></div>'
    +   '</div></div>'
    +   '<div class="fx-sec"><h3>Historia w sklepie</h3>'+(detail.notatki_blad?'<div class="fx-warn">'+esc(detail.notatki_blad)+'</div>':'')+notatki+'</div>'
    + '</div><div>'
    +   (kosz ? '' : '<div class="fx-sec"><h3>Status zamówienia</h3><div class="fx-actions"><select class="input" id="stSel">'+opcje+'</select><button class="btn" id="stBtn" type="button">Zmień status</button>'
    +     '<div class="fx-warn">Zmiana działa tak samo jak w sklepie – klient dostaje maila ze sklepu (np. przy „Zrealizowane”).</div></div></div>')
    +   '<div class="fx-sec"><h3>Klient</h3><div class="fx-kv"><span>Imię i nazwisko</span><div>'+esc(z.klient)+'</div><span>E-mail</span><div>'+(z.email?'<a href="mailto:'+esc(z.email)+'">'+esc(z.email)+'</a>':'')+'</div><span>Telefon</span><div>'+(z.telefon?'<a href="tel:'+esc(z.telefon.replace(/\s+/g,''))+'">'+esc(z.telefon)+'</a>':'')+'</div>'
    +     '<span>Data</span><div>'+esc(String(z.data_utworzenia||'').slice(0,16))+'</div><span>Płatność</span><div>'+esc(z.platnosc)+(d.oplacone?'<br><span class="fx-muted">opłacone '+esc(String(d.oplacone).slice(0,16))+'</span>':'')+'</div></div></div>'
    +   '<div class="fx-sec"><h3>Dane do faktury</h3><div>'+(adres({firma:f.firma, osoba:z.klient, adres:f.adres, kod:f.kod, miasto:f.miasto, kraj:f.kraj})||'<span class="fx-muted">brak</span>')+(f.nip?'<br>NIP: '+esc(f.nip):'')+'</div></div>'
    +   '<div class="fx-sec"><h3>Adres dostawy</h3><div>'+(adres(w)||'<span class="fx-muted">jak do faktury</span>')+(w.telefon?'<br>tel. '+esc(w.telefon):'')+'</div></div>'
    +   (hist ? '<div class="fx-sec"><h3>Zmiany w panelu</h3>'+hist+'</div>' : '')
    + '</div></div>';
  $('closeBtn').onclick = closeDetail;
  const zmienStatus = async (s) => { await apiPost(api('status'), {id: z.wc_id, status: s}); await openDetail(z.wc_id); };
  $('panel').querySelectorAll('[data-e]').forEach(b => b.onclick = async () => {
    const done = !b.classList.contains('on'); b.disabled = true;
    try{
      const r = await apiPost(api('etap'), {id: z.wc_id, grupa: b.dataset.g, etap: b.dataset.e, done});
      if (r.zaproponuj_zrealizowane && confirm('Wszystko z zamówienia #'+z.numer+' jest wysłane. Zmienić status w sklepie na „'+(STATUSY.completed||'Zrealizowane')+'”? Klient dostanie maila ze sklepu.')) await zmienStatus('completed');
      else await openDetail(z.wc_id);
    }catch(e){ alert('Nie udało się zapisać etapu: '+blad(e)); b.disabled = false; }
  });
  if ($('delBtn')) $('delBtn').onclick = async () => {
    if (!confirm('Usunąć zamówienie #'+z.numer+'? Trafi do kosza w sklepie i w panelu – można je przywrócić przez 30 dni.')) return;
    $('delBtn').disabled = true;
    try{ await apiPost(api('usun'), {id: z.wc_id}); closeDetail(); }
    catch(e){ alert('Nie udało się usunąć: '+blad(e)); $('delBtn').disabled = false; }
  };
  if ($('restBtn')) $('restBtn').onclick = async () => {
    $('restBtn').disabled = true;
    try{ await apiPost(api('przywroc'), {id: z.wc_id}); await openDetail(z.wc_id); }
    catch(e){ alert('Nie udało się przywrócić: '+blad(e)); $('restBtn').disabled = false; }
  };
  $('msgBtn').onclick = async () => {
    const t = $('msgTxt').value.trim(); if (!t) return;
    if (!confirm('Wysłać tę wiadomość klientowi ('+z.email+')?')) return;
    $('msgBtn').disabled = true;
    try{ await apiPost(api('notatka'), {id: z.wc_id, tresc: t, dla_klienta: true}); await openDetail(z.wc_id); }
    catch(e){ alert('Nie udało się wysłać: '+blad(e)); $('msgBtn').disabled = false; }
  };
  if (!$('stBtn')) { $('noteBtn').onclick = notatka; return; }
  $('stBtn').onclick = async () => {
    const s = $('stSel').value; if (s === z.status) return;
    if (!confirm('Zmienić status zamówienia #'+z.numer+' na „'+(STATUSY[s]||s)+'”? Klient może dostać maila ze sklepu.')) return;
    $('stBtn').disabled = true;
    try{ await zmienStatus(s); }
    catch(e){ alert('Nie udało się zmienić statusu: '+blad(e)); $('stBtn').disabled = false; }
  };
  $('noteBtn').onclick = notatka;
  async function notatka(){
    const t = $('noteTxt').value.trim(); if (!t) return;
    $('noteBtn').disabled = true;
    try{ await apiPost(api('notatka'), {id: z.wc_id, tresc: t, dla_klienta: false}); await openDetail(z.wc_id); }
    catch(e){ alert('Nie udało się dodać komentarza: '+blad(e)); $('noteBtn').disabled = false; }
  }
}

$('bulkClr').onclick = () => { zazn.clear(); odswiezZazn(); loadList(); };
$('bulkBtn').onclick = async () => {
  const s = $('bulkSt').value, n = zazn.size; if (!n) return;
  if (!confirm('Zmienić status '+n+' zamówień na „'+(STATUSY[s]||s)+'”? Klienci mogą dostać maile ze sklepu.')) return;
  $('bulkBtn').disabled = true;
  try{ const r = await apiPost(api('status_zbiorczo'), {ids: [...zazn].map(Number), status: s}); alert('Zmieniono status '+r.zmieniono+' z '+r.z+' zamówień.'+(r.bledy&&r.bledy.length?'\nBłędy: '+r.bledy.join('; '):'')); zazn.clear(); }
  catch(e){ alert('Nie udało się zmienić statusów: '+blad(e)); }
  $('bulkBtn').disabled = false; loadList();
};
$('qBtn').onclick = () => { strona = 1; loadList(); };
$('q').addEventListener('keydown', e => { if (e.key === 'Enter') { strona = 1; loadList(); } });
$('syncBtn').onclick = async () => {
  $('syncBtn').disabled = true; $('syncBtn').textContent = 'Pobieram…';
  try{ const r = await apiPost(api('sync'), {}); $('syncBtn').textContent = 'Pobrano: '+r.zmian+' zmian'; await loadList(); }
  catch(e){ showErr(e); $('syncBtn').textContent = 'Odśwież ze sklepu'; }
  setTimeout(()=>{ $('syncBtn').disabled = false; $('syncBtn').textContent = 'Odśwież ze sklepu'; }, 2500);
};
$('drawer').addEventListener('click', e => { if (e.target === $('drawer')) closeDetail(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('drawer').classList.contains('open')) closeDetail(); });
loadList();
const startId = new URLSearchParams(location.search).get('id'); if (startId) openDetail(startId);
