<template>
  <div class="gf">
    <div class="map-wrap"><div ref="el" class="map"></div>
      <div v-if="placing" class="hint-p"><i class="fa-solid fa-hand-pointer me-1"></i>Klik pada peta untuk letak lokasi <b>{{ placing.code }}</b> <button class="btn-soft sm ms-2" @click="placing = null">Batal</button></div>
    </div>
    <div class="side">
      <section class="ess-card">
        <h6 class="mb-1"><i class="fa-solid fa-building me-2 text-primary"></i>Pejabat</h6>
        <p class="hint">Koordinat pejabat belum ada dalam data — tetapkan di peta supaya staf pejabat juga disemak geofence.</p>
        <div v-for="o in offices" :key="o.id" class="off" :class="{ unset: !o.lat }">
          <div class="d-flex align-items-center gap-2"><b>{{ o.code }}</b><small class="muted flex-grow-1">{{ o.name }}</small>
            <label class="sw"><input type="checkbox" v-model="o.active" :disabled="!canEdit" @change="saveOffice(o)" /><i></i></label></div>
          <div class="tiny muted">{{ o.lat ? `${(+o.lat).toFixed(5)}, ${(+o.lng).toFixed(5)}` : 'Lokasi belum ditetapkan' }}</div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <input type="range" min="50" max="2000" step="50" v-model.number="o.radius_m" :disabled="!canEdit" @change="saveOffice(o)" class="flex-grow-1" />
            <span class="rv">{{ o.radius_m }} m</span>
            <button v-if="canEdit" class="btn-soft sm" @click="placing = o"><i class="fa-solid fa-map-pin"></i></button>
          </div>
        </div>
      </section>
      <section class="ess-card mt-3">
        <div class="d-flex align-items-center gap-2 mb-2"><h6 class="m-0 flex-grow-1"><i class="fa-solid fa-hard-hat me-2 text-warning"></i>Tapak ({{ sites.length }})</h6>
          <input v-model="q" class="f-in q" placeholder="Cari…" /></div>
        <div class="sites">
          <div v-for="s in shownSites" :key="s.id" class="st" @click="map?.flyTo([s.lat, s.lng], 14)">
            <b>{{ s.site_code }}</b><small class="muted ell">{{ s.records }} rekod</small>
            <input type="number" min="0" step="100" class="f-in r" v-model.number="s.geofence_m" :disabled="!canEdit" @click.stop @change="saveSite(s)" /><span class="tiny muted">m</span>
          </div>
        </div>
        <div class="tiny muted mt-2">0 m = matikan geofence untuk tapak itu. Lalai 1000 m.</div>
      </section>
      <div v-if="msg" class="ok mt-2">{{ msg }}</div>
    </div>
  </div>
</template>
<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { apiGet, apiSend } from '../../services/api'
const el = ref(null), sites = ref([]), offices = ref([]), canEdit = ref(false), q = ref(''), placing = ref(null), msg = ref('')
let map, layer
const L = () => window.L
const shownSites = computed(() => sites.value.filter(s => !q.value || s.site_code.toLowerCase().includes(q.value.toLowerCase())))
function flash(t) { msg.value = t; setTimeout(() => msg.value = '', 2000) }
function draw() {
  if (!map) return; layer?.clearLayers()
  sites.value.forEach(s => {
    if (s.geofence_m > 0) L().circle([s.lat, s.lng], { radius: s.geofence_m, color: '#f59e0b', weight: 1, fillOpacity: .06 }).addTo(layer)
    L().circleMarker([s.lat, s.lng], { radius: 4, color: '#b45309', fillColor: '#fff', fillOpacity: 1, weight: 2 }).bindTooltip(`<b>${s.site_code}</b> · ${s.geofence_m} m`).addTo(layer)
  })
  offices.value.filter(o => o.lat).forEach(o => {
    L().circle([+o.lat, +o.lng], { radius: o.radius_m, color: '#7c3aed', weight: 2, dashArray: '5 4', fillOpacity: o.active ? .12 : .02 }).addTo(layer)
    L().marker([+o.lat, +o.lng], { draggable: canEdit.value, icon: L().divIcon({ className: '', iconSize: [30, 30], iconAnchor: [15, 15], html: '<div class="lm-off"><i class="fa-solid fa-building"></i></div>' }) })
      .bindTooltip(`<b>${o.code}</b> ${o.radius_m} m`).on('dragend', e => { const p = e.target.getLatLng(); Object.assign(o, { lat: p.lat.toFixed(6), lng: p.lng.toFixed(6) }); saveOffice(o) }).addTo(layer)
  })
}
async function load() {
  const d = await apiGet('hr/geofences'); sites.value = d?.sites || []; offices.value = d?.offices || []; canEdit.value = !!d?.can_edit; draw()
}
async function saveSite(s) { try { await apiSend('PUT', `hr/geofences/site/${s.id}`, { geofence_m: s.geofence_m }); draw(); flash(`${s.site_code}: geofence ${s.geofence_m} m disimpan`) } catch (e) { alert(e.message) } }
async function saveOffice(o) {
  try { await apiSend('PUT', `hr/geofences/office/${o.id}`, { lat: o.lat ? +o.lat : null, lng: o.lng ? +o.lng : null, radius_m: o.radius_m, active: !!o.active }); draw(); flash(`${o.code} disimpan`) }
  catch (e) { alert(e.message) }
}
watch(placing, v => { if (el.value) el.value.style.cursor = v ? 'crosshair' : '' })
onMounted(async () => {
  await nextTick()
  map = L().map(el.value, { attributionControl: false }).setView([3.2, 101.5], 9)
  L().tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19 }).addTo(map)
  layer = L().layerGroup().addTo(map)
  map.on('click', e => { if (!placing.value) return; const o = placing.value; o.lat = e.latlng.lat.toFixed(6); o.lng = e.latlng.lng.toFixed(6); placing.value = null; saveOffice(o) })
  await load(); setTimeout(() => map.invalidateSize(), 200)
})
onBeforeUnmount(() => map?.remove())
</script>
<style scoped>
.gf{display:grid;grid-template-columns:1fr 380px;gap:12px}
.map-wrap{position:relative;border-radius:18px;overflow:hidden;border:1px solid var(--line,#eef0f4);min-height:620px}
.map{position:absolute;inset:0}
.hint-p{position:absolute;top:12px;left:50%;transform:translateX(-50%);z-index:500;background:#0f172a;color:#fff;border-radius:12px;padding:8px 12px;font-size:13px;display:flex;align-items:center}
.hint{font-size:12px;color:#64748b;margin-bottom:8px}
.off{border:1px solid var(--line,#eef0f4);border-radius:14px;padding:10px 12px;margin-bottom:8px}.off.unset{background:#fffbeb;border-color:#fde68a}
.rv{font-weight:800;font-size:12.5px;width:60px;text-align:right}
.sm{padding:.3rem .6rem!important;font-size:12px!important}
.sw{position:relative;width:36px;height:20px}.sw input{position:absolute;opacity:0;inset:0;z-index:1;margin:0;cursor:pointer}
.sw i{position:absolute;inset:0;background:#cbd5e1;border-radius:99px;transition:.2s}.sw i::after{content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;transition:.2s}
.sw input:checked+i{background:#7c3aed}.sw input:checked+i::after{transform:translateX(16px)}
.q{width:120px;padding:.3rem .6rem;font-size:12.5px}
.sites{max-height:300px;overflow:auto}
.st{display:grid;grid-template-columns:80px 1fr 80px 14px;gap:8px;align-items:center;padding:5px 4px;border-radius:8px;cursor:pointer;font-size:13px}.st:hover{background:#f8fafc}
.r{padding:.25rem .5rem;font-size:12.5px;text-align:right}
.ell{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media (max-width:1199.98px){ .gf{grid-template-columns:1fr} .map-wrap{min-height:400px} }
</style>
