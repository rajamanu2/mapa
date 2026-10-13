const vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict');
const layers=new Set(),polys=[];let views=0;
const map={setView(){views++;return this},fitBounds(){return this},getZoom(){return 15},removeLayer(x){layers.delete(x)},hasLayer(x){return layers.has(x)}};
function layer(points=[]){return{points,addTo(){layers.add(this);return this},on(){return this},setLatLng(){return this},setLatLngs(p){this.points=p;return this},getLatLngs(){return this.points},setStyle(){return this},setRadius(){return this},bindTooltip(){return this},clearLayers(){return this}}}
const box={window:{},document:{getElementById:()=>({textContent:''}),createElement:()=>({}),querySelector:()=>({})},L:{map:()=>map,tileLayer:()=>layer(),circleMarker:()=>layer(),circle:()=>layer(),layerGroup:()=>layer(),polyline:p=>{const l=layer(p);polys.push(l);return l},latLngBounds:p=>p}};
vm.createContext(box);vm.runInContext(fs.readFileSync(require('node:path').join(__dirname,'../res/raw/trip_map.js'),'utf8'),box);
const sample={current:{lat:18,lng:82,accuracy:20},destination:{lat:18.1,lng:82.1},route:[{lat:18,lng:82},{lat:18.1,lng:82.1}],positions:[],follow:true};
box.window.updateTrip(sample);assert.equal(polys[0].points.length,2);assert.match(box.window.describeTrip(),/road route shown/);assert.match(box.window.describeTrip(),/phone marker shown/);assert.equal(views,2);
sample.current.stale=true;box.window.updateTrip(sample);assert.equal(views,2,'stale phone never auto-follows');
sample.route=[];box.window.updateTrip(sample);assert.equal(polys[0].points.length,0,'no route does not invent a direct line');
sample.current=null;box.window.updateTrip(sample);assert.match(box.window.describeTrip(),/waiting for phone location/);
sample.route=[{lat:91,lng:82}];box.window.updateTrip(sample);assert.equal(polys[0].points.length,0,'invalid geometry omitted');
console.log('Map rendering logic: 8 assertions passed');

