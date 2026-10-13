const map=L.map('map',{zoomControl:true}).setView([17.9,82.1],8);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap contributors'}).addTo(map).on('tileerror',()=>{document.getElementById('hint').textContent='Map tiles unavailable · check internet';});
const current=L.circleMarker([0,0],{radius:10,color:'#fff',weight:3,fillColor:'#087f74',fillOpacity:1});
const destination=L.circleMarker([0,0],{radius:10,color:'#fff',weight:3,fillColor:'#b3353b',fillOpacity:1});
const accuracy=L.circle([0,0],{radius:0,color:'#087f74',weight:1,fillOpacity:.08});
const trail=L.layerGroup().addTo(map);
const road=L.polyline([],{color:'#3278ef',weight:6,opacity:.85}).addTo(map);
let points=[],centered=false,destinationKey='';
const valid=p=>p&&Number.isFinite(p.lat)&&Number.isFinite(p.lng)&&Math.abs(p.lat)<=90&&Math.abs(p.lng)<=180;
function label(marker,value){const span=document.createElement('span');span.textContent=value;marker.bindTooltip(span,{direction:'top'});}
window.recenterTrip=()=>{if(points.length)map.fitBounds(L.latLngBounds(points),{padding:[35,35],maxZoom:16});};
window.describeTrip=()=> 'MAPA map · '+(document.querySelector('.leaflet-tile-loaded')?'map tiles loaded':'map tiles loading')+' · '+(road.getLatLngs().length>1?'road route shown':'no road route')+' · '+(map.hasLayer(current)?'phone marker shown':'waiting for phone location');
window.updateTrip=s=>{
  points=[];document.getElementById('hint').textContent=s.hint||'';
  const route=(s.route||[]).filter(valid).map(p=>[p.lat,p.lng]);road.setLatLngs(route);points.push(...route);
  if(valid(s.current)){const p=[s.current.lat,s.current.lng];current.setLatLng(p).setStyle({fillColor:s.current.stale?'#8a6b26':'#087f74'}).addTo(map);label(current,s.current.label);points.push(p);if(Number.isFinite(s.current.accuracy)){accuracy.setLatLng(p).setRadius(Math.min(20000,s.current.accuracy)).addTo(map);}else map.removeLayer(accuracy);}else{map.removeLayer(current);map.removeLayer(accuracy);}
  if(valid(s.destination)){const p=[s.destination.lat,s.destination.lng];destination.setLatLng(p).addTo(map);label(destination,s.destination.label);points.push(p);}else map.removeLayer(destination);
  trail.clearLayers();let segment=[],last=0,simulated=false;
  const draw=()=>{if(segment.length>1)L.polyline(segment,{color:simulated?'#b9882a':'#087f74',weight:4}).addTo(trail);};
  for(const p of s.positions||[]){if(!valid(p)||!Number.isFinite(p.recorded_at))continue;if(p.recorded_at-last>120000||p.recorded_at<=last||!!p.simulated!==simulated){draw();segment=[];}simulated=!!p.simulated;segment.push([p.lat,p.lng]);points.push([p.lat,p.lng]);const dot=L.circleMarker([p.lat,p.lng],{radius:3,color:simulated?'#b9882a':'#328dba',fillOpacity:.7}).addTo(trail);label(dot,p.label||'Received device location');last=p.recorded_at;}draw();
  const key=valid(s.destination)?s.destination.lat+','+s.destination.lng:'';
  if((!centered||key!==destinationKey)&&points.length){window.recenterTrip();centered=true;}destinationKey=key;
  if(s.follow&&valid(s.current)&&!s.current.stale)map.setView([s.current.lat,s.current.lng],Math.max(15,map.getZoom()),{animate:false});
};
