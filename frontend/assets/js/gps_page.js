const GPS_API = {
  overview: API_BASE + 'gps/overview.php',
  events: API_BASE + 'gps/events.php'
};

const gpsState = {
  map: null,
  markers: new Map(),
  devices: [],
  events: [],
  timer: null,
  refreshMs: 20000,
  initializedBounds: false
};

function gpsEsc(v){ return String(v ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
function gpsFormatDate(v){ if(!v) return '—'; const d = new Date(String(v).replace(' ','T')); return Number.isNaN(d.getTime()) ? v : d.toLocaleString('pl-PL'); }
function gpsEventTypeLabel(type){ return ({ignitionOff:'Zapłon wyłączony',ignitionOn:'Zapłon włączony',longStop:'Dłuższy postój',deviceOffline:'Offline',deviceOnline:'Online'})[type] || type; }
function gpsMarkerColor(device){ if(!Number(device.online)) return 'gray'; if(Number(device.motion) || Number(device.speed_kmh||0) > 1) return 'green'; return 'yellow'; }
function gpsMarkerIcon(color){ const colors={green:'#16a34a',yellow:'#d97706',gray:'#6b7280'}; return L.divIcon({className:'', html:`<div style="width:18px;height:18px;border-radius:50%;background:${colors[color]};border:3px solid #fff;box-shadow:0 2px 10px rgba(0,0,0,.25)"></div>`, iconSize:[18,18], iconAnchor:[9,9]}); }
function gpsPopup(device){ return `<div class="gps-popup"><h3>${gpsEsc(device.vehicle_name || 'Pojazd')}</h3><p><strong>Adres:</strong> ${gpsEsc(device.address || 'Brak')}</p><p><strong>Prędkość:</strong> ${Number(device.speed_kmh || 0).toFixed(0)} km/h</p><p><strong>Zapłon:</strong> ${Number(device.ignition) ? 'ON' : 'OFF'}</p><p><strong>Ruch:</strong> ${Number(device.motion) ? 'Tak' : 'Nie'}</p><p><strong>Ostatnia pozycja:</strong> ${gpsFormatDate(device.last_fix_time)}</p></div>`; }

function initGpsMap(){
  gpsState.map = L.map('gpsMap', {zoomControl:true}).setView([52.2297, 21.0122], 6);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, attribution:'&copy; OpenStreetMap'}).addTo(gpsState.map);
}

function renderDeviceFilter(){
  const sel = document.getElementById('filterDevice');
  const current = sel.value;
  sel.innerHTML = '<option value="">Wszystkie auta</option>';
  gpsState.devices.forEach(d => {
    const opt = document.createElement('option');
    opt.value = String(d.traccar_device_id);
    opt.textContent = d.vehicle_name || ('Pojazd #' + d.traccar_device_id);
    sel.appendChild(opt);
  });
  sel.value = current;
}

function renderMarkers(){
  const bounds=[];
  gpsState.devices.forEach(device => {
    if(device.latitude === null || device.longitude === null || device.latitude === '' || device.longitude === '') return;
    const key = String(device.traccar_device_id);
    const latLng = [Number(device.latitude), Number(device.longitude)];
    const icon = gpsMarkerIcon(gpsMarkerColor(device));
    if(gpsState.markers.has(key)){
      const marker = gpsState.markers.get(key);
      marker.setLatLng(latLng);
      marker.setIcon(icon);
      marker.setPopupContent(gpsPopup(device));
    } else {
      const marker = L.marker(latLng, {icon}).addTo(gpsState.map);
      marker.bindPopup(gpsPopup(device));
      gpsState.markers.set(key, marker);
    }
    bounds.push(latLng);
  });
  if(bounds.length && !gpsState.initializedBounds){
    gpsState.map.fitBounds(L.latLngBounds(bounds).pad(0.18));
    gpsState.initializedBounds = true;
  }
}

function renderEvents(){
  const box = document.getElementById('gpsEvents');
  box.innerHTML = '';
  if(!gpsState.events.length){
    box.innerHTML = '<div class="gps-empty">Brak zdarzeń do wyświetlenia.</div>';
    return;
  }
  gpsState.events.forEach(item => {
    const el = document.createElement('div');
    el.className = 'gps-event';
    el.innerHTML = `<div class="gps-event-top"><div class="gps-event-title">${gpsEsc(item.vehicle_name || item.title || 'Zdarzenie')}</div><div class="gps-event-time">${gpsFormatDate(item.event_time)}</div></div><div class="gps-event-type">${gpsEsc(gpsEventTypeLabel(item.event_type))}${item.duration_minutes ? ' • ' + Number(item.duration_minutes) + ' min' : ''}</div><div class="gps-event-address">${gpsEsc(item.address || item.description || 'Brak adresu')}</div>`;
    el.addEventListener('click', ()=>{
      if(item.latitude && item.longitude){ gpsState.map.setView([Number(item.latitude), Number(item.longitude)], 16); }
      const marker = gpsState.markers.get(String(item.traccar_device_id));
      if(marker) marker.openPopup();
    });
    box.appendChild(el);
  });
}

async function gpsFetchJson(url){
  const r = await fetch(url, {headers: authHeaders()});
  const t = await r.text();
  let data = {};
  try{ data = JSON.parse(t); }catch(e){}
  if(!r.ok) throw new Error(data.details || data.error || t || ('HTTP ' + r.status));
  return data;
}

async function loadGpsOverview(){
  const data = await gpsFetchJson(GPS_API.overview);
  gpsState.devices = Array.isArray(data.devices) ? data.devices : [];
  renderDeviceFilter();
  renderMarkers();
}

async function loadGpsEvents(){
  const deviceId = document.getElementById('filterDevice').value;
  const type = document.getElementById('filterType').value;
  const qs = new URLSearchParams({limit:'100'});
  if(deviceId) qs.set('device_id', deviceId);
  if(type) qs.set('type', type);
  const data = await gpsFetchJson(GPS_API.events + '?' + qs.toString());
  gpsState.events = Array.isArray(data.events) ? data.events : [];
  renderEvents();
}

async function refreshGps(){
  const msg = document.getElementById('gpsMsg');
  try{
    await Promise.all([loadGpsOverview(), loadGpsEvents()]);
    document.getElementById('gpsRefreshLabel').textContent = 'Odświeżono: ' + new Date().toLocaleTimeString('pl-PL');
    msg.textContent = 'Widok wszystkich pojazdów i powiadomień w stylu uproszczonym.';
  }catch(e){
    console.error(e);
    msg.textContent = 'Błąd odświeżania GPS: ' + (e.message || 'nieznany');
    document.getElementById('gpsRefreshLabel').textContent = 'Błąd odświeżania';
  }
}

document.addEventListener('DOMContentLoaded', async ()=>{
  initGpsMap();
  document.getElementById('btnRefresh').addEventListener('click', refreshGps);
  document.getElementById('filterDevice').addEventListener('change', loadGpsEvents);
  document.getElementById('filterType').addEventListener('change', loadGpsEvents);
  await refreshGps();
  gpsState.timer = setInterval(refreshGps, gpsState.refreshMs);
});
