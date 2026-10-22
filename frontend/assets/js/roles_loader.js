// Ładowanie ról z bazy (roles.php) – bez hardcode
async function loadRolesIntoSelect(selectEl, selectedId=null){
  selectEl.innerHTML = '';
  const r = await apiGet('roles.php');
  const items = (r && r.items) ? r.items : [];
  items.sort((a,b)=>Number(a.id)-Number(b.id));
  for(const it of items){
    const o = document.createElement('option');
    o.value = it.id;
    o.textContent = `${it.name} - ${it.id}`;
    selectEl.appendChild(o);
  }
  if(selectedId!==null && selectedId!==undefined){
    selectEl.value = String(selectedId);
  }
}
