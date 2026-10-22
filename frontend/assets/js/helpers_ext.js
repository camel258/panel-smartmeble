function fmtMoney(v){ if(v==null||v==='') return ''; const n=Number(v)||0; return n.toLocaleString('pl-PL',{style:'currency',currency:'PLN'}); }
function statusPill(name){ if(!name) return ''; return '<span class="status-pill">'+name+'</span>'; }
function monthNamePL(m){const n=['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];return n[(m-1+12)%12]||'';}
function deriveRealYearMonth(r){
  const by=parseInt(r.finished_bucket_year||0,10);
  const bm=parseInt(r.finished_bucket_month||0,10);
  if(by&&bm) return {year:by,month:bm};
  const p=r.install_date_new||r.install_date||r.contract_date||'';
  let y=parseInt(r.real_year||0,10),m=parseInt(r.real_month||0,10);
  if((!y||!m)&&p){const t=new Date(p.replace(' ','T'));if(!y)y=t.getFullYear();if(!m)m=t.getMonth()+1;}
  return {year:y,month:m};
}
function effectiveInstallDate(r){
  return r.install_date_new || r.install_date || r.contract_date || r.created_at || '9999-12-31';
}
function groupOrders(items){
  const g={};
  (items||[]).forEach(r=>{
    const d=deriveRealYearMonth(r);
    const k=`${d.year}-${String(d.month).padStart(2,'0')}`;
    if(!g[k])g[k]={title:`${monthNamePL(d.month)} ${d.year}`,items:[]};
    g[k].items.push(r);
  });
  const ks=Object.keys(g).sort((a,b)=>a>b?1:-1);
  return ks.map(k=>({
    key:k,
    title:g[k].title,
    items:g[k].items.sort((a,b)=>{
      const da=String(effectiveInstallDate(a));
      const db=String(effectiveInstallDate(b));
      if(da===db) return Number(a.id||0)-Number(b.id||0);
      return da<db?-1:1;
    })
  }));
}

async function initNavVisibility(){
  try{
    const me = await apiGet('session_check.php');
    const role = Number((me?.user?.role_id ?? me?.user?.role ?? 0));
    const elDel=document.getElementById('navDeleted');
    const elRoz=document.getElementById('navRozl');
    if(elDel) elDel.style.display = (role===999) ? '' : 'none';
    if(elRoz) elRoz.style.display = (role===1 || role===999) ? '' : 'none';
  }catch(e){}
}
document.addEventListener('DOMContentLoaded', ()=>{ initNavVisibility(); });
