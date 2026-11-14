<template>
  <div class="hra">
    <div class="bar">
      <div class="ess-tabs">
        <button v-for="t in TABS" :key="t.k" :class="{ on: tab === t.k }" @click="tab = t.k"><i :class="t.i"></i>{{ t.l }}</button>
      </div>
      <div class="filters">
        <div v-if="!scope && !['manpower','geofence'].includes(tab)" class="ess-tabs sm">
          <button v-for="o in ['', 'HQ', 'PSU', 'PSS']" :key="o" :class="{ on: office === o }" @click="office = o">{{ o || 'Semua' }}</button>
        </div>
        <span v-else-if="scope" class="scope"><i class="fa-solid fa-building"></i>Pejabat {{ scope }}</span>
        <template v-if="tab === 'map'">
          <button class="nav" @click="shiftDay(-1)"><i class="fa-solid fa-chevron-left"></i></button>
          <input type="date" class="f-in date" v-model="day" :max="todayIso" />
          <button class="nav" @click="shiftDay(1)" :disabled="day >= todayIso"><i class="fa-solid fa-chevron-right"></i></button>
          <div class="ess-tabs sm"><button v-for="r in [1, 7]" :key="r" :class="{ on: span === r }" @click="span = r">{{ r === 1 ? 'Hari' : '7 hari' }}</button></div>
        </template>
        <div v-if="tab === 'heat'" class="ess-tabs sm"><button v-for="d in [30, 60, 90]" :key="d" :class="{ on: days === d }" @click="days = d">{{ d }} hari</button></div>
        <select v-if="tab === 'trend'" v-model="emp" class="f-in sel"><option :value="0">Seluruh pasukan</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }} ({{ s.code }})</option></select>
      </div>
    </div>

    <!-- MAP -->
    <template v-if="tab === 'map'">
      <div class="kpis">
        <div v-for="k in mapKpis" :key="k.l" class="k" :class="k.c"><i :class="k.i"></i><div><b>{{ k.v }}</b><small>{{ k.l }}</small></div></div>
      </div>
      <div class="mapgrid">
        <LocationMap ref="lm" :points="mapData?.points || []" :sites="mapData?.sites || []" :offices="mapData?.offices || []" :fence-m="mapData?.fence_m || 1000" show-names height="560px" />
        <div class="side">
          <div class="side-h"><b>{{ mapData?.points?.length || 0 }} rekod berlokasi</b><input v-model="q" class="f-in mini" placeholder="Cari staf…" /></div>
          <div class="side-list">
            <button v-for="p in listPts" :key="p.id" class="sp" @click="$refs.lm.focus(p.loc.lat, p.loc.lng)">
              <span class="dot" :style="{ background: p.outside ? '#e11d48' : p.late ? '#f59e0b' : '#16a34a' }"></span>
              <span class="min0 flex-grow-1 text-start"><b class="ell">{{ p.name }}</b><small>{{ p.code }} · {{ p.office }} · {{ p.loc.label }}{{ span > 1 ? ' · ' + p.date.slice(5) : '' }}</small></span>
              <span class="t">{{ p.tin || '—' }}<small>{{ p.tout || '' }}</small></span>
            </button>
          </div>
          <div v-if="mapData?.summary?.no_location" class="nl"><i class="fa-solid fa-circle-info"></i>{{ mapData.summary.no_location }} rekod tiada lokasi (staf pejabat tanpa tapak / belum clock-in melalui ESS).</div>
        </div>
      </div>
    </template>

    <!-- HEATMAPS -->
    <template v-if="tab === 'heat' && heat">
      <div class="insights">
        <div v-for="(t, i) in heatInsights" :key="i" class="ins"><i :class="t.i"></i><span v-html="t.t"></span></div>
      </div>
      <div class="hg">
        <section class="ess-card">
          <h6><i class="fa-solid fa-door-open me-2 text-success"></i>Heatmap waktu ketibaan</h6><p class="hint">Bilangan clock-in setiap 15 minit · merah = selepas 08:30</p>
          <div class="hm" :style="{ gridTemplateColumns: `40px repeat(${heat.slots.length}, 1fr)` }">
            <span></span><span v-for="(s, i) in heat.slots" :key="s" class="hx">{{ i % 4 === 0 ? s : '' }}</span>
            <template v-for="(row, d) in heat.arrival" :key="d">
              <span class="hy">{{ heat.dow[d] }}</span>
              <span v-for="(v, i) in row" :key="i" class="hc" :style="{ background: cellColor(v, maxArr, heat.slots[i] > '08:29') }" :title="`${heat.dow[d]} ${heat.slots[i]} — ${v} clock-in`"></span>
            </template>
          </div>
        </section>
        <section class="ess-card">
          <h6><i class="fa-solid fa-door-closed me-2 text-primary"></i>Heatmap waktu balik</h6><p class="hint">Bilangan clock-out setiap 30 minit</p>
          <div class="hm" :style="{ gridTemplateColumns: `40px repeat(${heat.out_slots.length}, 1fr)` }">
            <span></span><span v-for="(s, i) in heat.out_slots" :key="s" class="hx">{{ i % 4 === 0 ? s : '' }}</span>
            <template v-for="(row, d) in heat.departure" :key="d">
              <span class="hy">{{ heat.dow[d] }}</span>
              <span v-for="(v, i) in row" :key="i" class="hc" :style="{ background: blue(v, maxDep) }" :title="`${heat.dow[d]} ${heat.out_slots[i]} — ${v} clock-out`"></span>
            </template>
          </div>
        </section>
      </div>
      <div class="hg mt-3">
        <section class="ess-card">
          <h6><i class="fa-solid fa-calendar-days me-2 text-warning"></i>Kalendar kadar lewat</h6><p class="hint">Setiap kotak = satu hari · warna = % staf lewat · klik untuk buka peta hari itu</p>
          <div class="calwrap">
            <div class="cal-dow"><span v-for="d in heat.dow" :key="d">{{ d }}</span></div>
            <div class="calhm">
              <template v-for="w in calWeeks" :key="w.k">
                <div v-for="c in w.days" :key="c.k" class="cc" :class="{ empty: !c.date }" :style="{ background: c.date ? lateColor(c.late_rate) : '#f8fafc' }"
                  :title="c.date ? `${c.date}: ${c.present} hadir, ${c.late} lewat (${c.late_rate}%), ${c.leave} cuti` : c.k + ': tiada rekod'" @click="c.date && openDay(c.date)">
                  <small v-if="c.date && c.late_rate >= 35">{{ c.late_rate }}</small></div>
              </template>
            </div>
          </div>
          <div class="cal-months"><span v-for="w in calWeeks" :key="w.k">{{ new Date(w.k).getDate() <= 7 ? new Date(w.k).toLocaleDateString('ms-MY', { month: 'short' }) : '' }}</span></div>
          <div class="scale"><span>0%</span><i v-for="v in [0, 10, 20, 35, 50]" :key="v" :style="{ background: lateColor(v) }"></i><span>50%+</span></div>
        </section>
        <section class="ess-card">
          <h6><i class="fa-solid fa-hard-hat me-2 text-info"></i>Kepadatan clock-in mengikut tapak</h6><p class="hint">Di mana staf hadir · bahagian oren = lewat</p>
          <div v-for="s in heat.sites.slice(0, 12)" :key="s.site_code" class="sb">
            <b>{{ s.site_code }}</b>
            <div class="sbar"><div class="ok" :style="{ width: (s.count - s.late) / maxSite * 100 + '%' }"></div><div class="lt" :style="{ width: s.late / maxSite * 100 + '%' }"></div></div>
            <span>{{ s.count }}<small> · {{ s.staff }} staf</small></span>
          </div>
        </section>
      </div>
      <section class="ess-card mt-3">
        <h6><i class="fa-solid fa-table-cells me-2 text-danger"></i>Matriks ketepatan masa — 20 staf paling kerap lewat</h6>
        <p class="hint">Hijau = tepat masa · kuning &lt; 15 min · oren 15–60 min · merah &gt; 60 min lewat · kelabu = tiada rekod</p>
        <div class="mx-wrap">
          <table class="mx">
            <thead><tr><th class="nm">Staf</th><th class="n">Lewat</th><th v-for="(d, i) in heat.dates" :key="d" class="dh">{{ i % 5 === 0 ? d.slice(5) : '' }}</th></tr></thead>
            <tbody><tr v-for="m in heat.matrix" :key="m.emp_id">
              <td class="nm"><b>{{ m.name }}</b><small>{{ m.code }} · {{ m.office }}</small></td><td class="n">{{ m.late }}</td>
              <td v-for="(v, i) in m.cells" :key="i" class="mc"><span :style="{ background: minColor(v) }" :title="`${heat.dates[i]}: ${v === null ? 'tiada rekod' : v === 0 ? 'tepat masa' : v + ' min lewat'}`"></span></td>
            </tr></tbody>
          </table>
        </div>
      </section>
    </template>
    <div v-if="tab === 'heat' && !heat" class="empty"><span class="spinner-border spinner-border-sm"></span></div>

    <HrAlerts v-if="tab === 'alerts'" :office="scope || office" />
    <HrManpower v-if="tab === 'manpower'" />
    <HrGeofence v-if="tab === 'geofence'" />

    <!-- TREND -->
    <section v-if="tab === 'trend'" class="ess-card">
      <h6 class="mb-2"><i class="fa-solid fa-chart-line me-2 text-primary"></i>Trend masa masuk & balik — {{ emp ? staff.find(s => s.id === emp)?.name : 'purata pasukan' }}</h6>
      <InOutTrend :data="trend" :period="period" height="360px" @period="p => { period = p; loadTrend() }" />
    </section>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { apiGet } from '../../services/api'
import LocationMap from './LocationMap.vue'
import InOutTrend from './InOutTrend.vue'
import HrAlerts from './HrAlerts.vue'
import HrGeofence from './HrGeofence.vue'
import HrManpower from './HrManpower.vue'
import { useRoute } from 'vue-router'

const TABS = [{ k: 'map', l: 'Peta langsung', i: 'fa-solid fa-map-location-dot' }, { k: 'alerts', l: 'Amaran', i: 'fa-solid fa-shield-halved' },
  { k: 'heat', l: 'Heatmap', i: 'fa-solid fa-fire' }, { k: 'trend', l: 'Trend masuk/balik', i: 'fa-solid fa-chart-line' },
  { k: 'manpower', l: 'Tenaga kerja vs pengeluaran', i: 'fa-solid fa-people-carry-box' }, { k: 'geofence', l: 'Geofence', i: 'fa-solid fa-draw-polygon' }]
const todayIso = new Date().toLocaleDateString('en-CA')
const route = useRoute()
const tab = ref(TABS.some(t => t.k === route.query.tab) ? route.query.tab : 'map'), office = ref(''), scope = ref(null), staff = ref([]), q = ref('')
const day = ref(todayIso), span = ref(1), mapData = ref(null), days = ref(60), heat = ref(null), emp = ref(0), period = ref('month'), trend = ref(null)
const qs = o => new URLSearchParams(Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v != null && v !== 0))).toString()

async function loadMap() {
  const to = day.value, f = new Date(day.value + 'T00:00:00'); f.setDate(f.getDate() - (span.value - 1))
  mapData.value = await apiGet(`hr/attendance/map?${qs({ from: f.toLocaleDateString('en-CA'), to, office: office.value })}`)
}
async function loadHeat() { heat.value = null; heat.value = await apiGet(`hr/attendance/heatmap?${qs({ days: days.value, office: office.value })}`) }
async function loadTrend() { trend.value = await apiGet(`hr/attendance/trend?${qs({ period: period.value, office: office.value, employee: emp.value })}`) }
function load() { ({ map: loadMap, heat: loadHeat, trend: loadTrend })[tab.value]?.() }   // other tabs load themselves
function shiftDay(d) { const x = new Date(day.value + 'T00:00:00'); x.setDate(x.getDate() + d); day.value = x.toLocaleDateString('en-CA') }
function openDay(d) { day.value = d; span.value = 1; tab.value = 'map' }

const listPts = computed(() => { const t = q.value.toLowerCase(); return (mapData.value?.points || []).filter(p => !t || `${p.name} ${p.code}`.toLowerCase().includes(t)) })
const mapKpis = computed(() => { const s = mapData.value?.summary || {}; return [
  { l: 'Rekod', v: s.records ?? '—', i: 'fa-solid fa-users', c: 'k-slate' }, { l: 'Berlokasi', v: s.located ?? '—', i: 'fa-solid fa-location-dot', c: 'k-blue' },
  { l: 'GPS sebenar', v: s.gps ?? '—', i: 'fa-solid fa-satellite-dish', c: 'k-violet' }, { l: 'Lokasi tapak', v: s.site ?? '—', i: 'fa-solid fa-hard-hat', c: 'k-teal' },
  { l: 'Lewat', v: s.late ?? '—', i: 'fa-solid fa-clock', c: 'k-amber' }, { l: 'Luar geofence', v: s.outside ?? '—', i: 'fa-solid fa-triangle-exclamation', c: s.outside ? 'k-rose' : 'k-slate' }] })

const maxArr = computed(() => Math.max(1, ...(heat.value?.arrival || []).flat()))
const maxDep = computed(() => Math.max(1, ...(heat.value?.departure || []).flat()))
const maxSite = computed(() => Math.max(1, ...(heat.value?.sites || []).map(s => s.count)))
function cellColor(v, max, late) { if (!v) return '#f1f5f9'; const a = .15 + .85 * Math.sqrt(v / max); return late ? `rgba(225,29,72,${a})` : `rgba(22,163,74,${a})` }
function blue(v, max) { if (!v) return '#f1f5f9'; return `rgba(79,70,229,${.15 + .85 * Math.sqrt(v / max)})` }
function lateColor(r) { if (r <= 0) return '#dcfce7'; if (r < 10) return '#fef9c3'; if (r < 20) return '#fde68a'; if (r < 35) return '#fdba74'; if (r < 50) return '#fb7185'; return '#e11d48' }
function minColor(v) { if (v === null) return '#f1f5f9'; if (v === 0) return '#4ade80'; if (v < 15) return '#facc15'; if (v <= 60) return '#fb923c'; return '#e11d48' }
const calWeeks = computed(() => {
  const cal = heat.value?.calendar || []; if (!cal.length) return []
  const by = Object.fromEntries(cal.map(c => [c.date, c])), start = new Date(cal[0].date + 'T00:00:00'); start.setDate(start.getDate() - ((start.getDay() + 6) % 7))
  const end = new Date(cal[cal.length - 1].date + 'T00:00:00'), weeks = []
  for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 7)) {
    const w = { k: d.toISOString(), days: [] }
    for (let i = 0; i < 7; i++) { const x = new Date(d); x.setDate(x.getDate() + i); const k = x.toLocaleDateString('en-CA'); w.days.push(by[k] ? { ...by[k], k } : { k }) }
    weeks.push(w)
  }
  return weeks
})
const heatInsights = computed(() => {
  const h = heat.value; if (!h) return []
  const out = []; let best = [0, 0, 0]
  h.arrival.forEach((r, d) => r.forEach((v, i) => { if (v > best[0]) best = [v, d, i] }))
  out.push({ i: 'fa-solid fa-bolt', t: `Puncak ketibaan: <b>${h.dow[best[1]]} ${h.slots[best[2]]}</b> (${best[0]} clock-in)` })
  const lateByDow = h.arrival.map((r, d) => [h.dow[d], r.reduce((s, v, i) => s + (h.slots[i] > '08:29' ? v : 0), 0), r.reduce((a, b) => a + b, 0)]).filter(x => x[2] > 20)
  const worst = lateByDow.sort((a, b) => b[1] / b[2] - a[1] / a[2])[0]
  if (worst) out.push({ i: 'fa-solid fa-person-running', t: `Hari paling ramai lewat: <b>${worst[0]}</b> (${Math.round(worst[1] / worst[2] * 100)}% clock-in selepas 08:30)` })
  const site = [...h.sites].filter(s => s.count > 20).sort((a, b) => b.late / b.count - a.late / a.count)[0]
  if (site) out.push({ i: 'fa-solid fa-hard-hat', t: `Tapak dengan kadar lewat tertinggi: <b>${site.site_code}</b> (${Math.round(site.late / site.count * 100)}% daripada ${site.count} rekod)` })
  if (h.matrix[0]) out.push({ i: 'fa-solid fa-user-clock', t: `Paling kerap lewat: <b>${h.matrix[0].name}</b> (${h.matrix[0].late} kali dalam ${days.value} hari)` })
  return out
})

watch([tab, office], load)
watch([day, span], () => tab.value === 'map' && loadMap())
watch(days, () => tab.value === 'heat' && loadHeat())
watch(emp, loadTrend)
onMounted(async () => {
  const s = await apiGet('hr/staff'); scope.value = s?.scope || null; staff.value = s?.staff || []
  // default the map to the latest day that has records
  const last = await apiGet('attendances'); if (last?.date && last.date < todayIso) day.value = last.date
  load()
})
</script>

<style scoped>
.bar{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.ess-tabs.sm button{padding:.35rem .7rem;font-size:12px}
.scope{font-size:12.5px;font-weight:700;background:#eef2ff;color:#4338ca;border-radius:10px;padding:6px 10px;display:inline-flex;gap:6px;align-items:center}
.date{width:150px;padding:.4rem .6rem}.sel{width:260px;padding:.45rem .6rem}
.nav{border:1px solid var(--line,#eef0f4);background:#fff;border-radius:10px;width:34px;height:34px}
.kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:12px}
.k{display:flex;gap:10px;align-items:center;border-radius:14px;padding:10px 12px;background:#fff;border:1px solid var(--line,#eef0f4)}
.k i{width:32px;height:32px;border-radius:10px;display:grid;place-items:center}.k b{display:block;font-size:18px;line-height:1}.k small{font-size:11px;color:#64748b}
.k-slate i{background:#f1f5f9;color:#475569}.k-blue i{background:#dbeafe;color:#2563eb}.k-violet i{background:#ede9fe;color:#7c3aed}
.k-teal i{background:#ccfbf1;color:#0f766e}.k-amber i{background:#fef3c7;color:#d97706}.k-rose i{background:#ffe4e6;color:#e11d48}
.mapgrid{display:grid;grid-template-columns:1fr 340px;gap:12px}
.side{background:#fff;border:1px solid var(--line,#eef0f4);border-radius:18px;display:flex;flex-direction:column;max-height:560px;overflow:hidden}
.side-h{display:flex;gap:8px;align-items:center;justify-content:space-between;padding:12px;border-bottom:1px solid var(--line,#eef0f4);font-size:13px}
.mini{width:140px;padding:.35rem .6rem;font-size:12.5px}
.side-list{overflow:auto;flex:1;padding:6px}
.sp{display:flex;gap:10px;align-items:center;width:100%;border:0;background:transparent;border-radius:10px;padding:7px 8px;transition:background .12s}
.sp:hover{background:#f8fafc}.sp b{display:block;font-size:12.5px}.sp small{display:block;font-size:11px;color:#64748b}
.dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.t{font-weight:800;font-size:12.5px;text-align:right}.t small{display:block;color:#94a3b8;font-weight:600}
.ell{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.nl{font-size:11.5px;color:#64748b;background:#f8fafc;padding:8px 12px;display:flex;gap:6px}
.insights{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}
.ins{display:flex;gap:10px;align-items:flex-start;background:linear-gradient(135deg,#eef2ff,#faf5ff);border-radius:14px;padding:10px 12px;font-size:12.5px;color:#312e81}
.ins i{color:#7c3aed;margin-top:2px}
.hg{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.hint{font-size:12px;color:#64748b;margin:2px 0 10px}
.hm{display:grid;gap:2px;align-items:center}
.hx{font-size:9.5px;color:#94a3b8;white-space:nowrap;overflow:visible}.hy{font-size:11px;font-weight:700;color:#475569}
.hc{height:22px;border-radius:4px;transition:transform .1s;cursor:default}.hc:hover{transform:scale(1.35);outline:2px solid #0f172a;z-index:2}
/* GitHub-style calendar: one column per week, rows Mon..Sun */
.calwrap{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px}
.cal-dow{display:grid;grid-template-rows:repeat(7,22px);gap:4px;font-size:10px;color:#94a3b8;font-weight:700;align-items:center}
.calhm{display:grid;grid-template-rows:repeat(7,22px);grid-auto-flow:column;grid-auto-columns:22px;gap:4px}
.cc{border-radius:5px;cursor:pointer;transition:transform .1s;display:grid;place-items:center}
.cc small{font-size:8px;font-weight:800;color:#fff}
.cc:hover:not(.empty){transform:scale(1.3);outline:2px solid #0f172a;z-index:2}.cc.empty{cursor:default}
.cal-months{display:grid;grid-auto-flow:column;grid-auto-columns:22px;gap:4px;margin-left:34px;font-size:10px;color:#94a3b8;font-weight:700;white-space:nowrap}
.scale{display:flex;gap:4px;align-items:center;font-size:11px;color:#64748b;margin-top:8px}.scale i{width:16px;height:12px;border-radius:3px}
.sb{display:grid;grid-template-columns:90px 1fr 110px;gap:10px;align-items:center;padding:4px 0;font-size:12.5px}
.sbar{display:flex;height:12px;background:#f1f5f9;border-radius:99px;overflow:hidden}.sbar .ok{background:#4ade80}.sbar .lt{background:#f59e0b}
.sb small{color:#94a3b8}
.mx-wrap{overflow-x:auto}
.mx{border-collapse:separate;border-spacing:2px;font-size:11.5px}
.mx th{font-weight:700;color:#94a3b8;font-size:9.5px;white-space:nowrap;text-align:left}
.mx .nm{min-width:200px;position:sticky;left:0;background:#fff;z-index:1}.mx .nm b{display:block;font-size:12px}.mx .nm small{color:#94a3b8}
.mx .n{font-weight:800;text-align:center;color:#e11d48;min-width:40px}
.mc span{display:block;width:13px;height:18px;border-radius:3px}
.empty{text-align:center;padding:40px;color:#94a3b8}
@media (max-width:1199.98px){ .mapgrid{grid-template-columns:1fr} .side{max-height:360px} .kpis{grid-template-columns:repeat(3,1fr)} .insights{grid-template-columns:1fr 1fr} }
@media (max-width:991.98px){ .hg{grid-template-columns:1fr} }
@media (max-width:767.98px){ .insights{grid-template-columns:1fr} .kpis{grid-template-columns:repeat(2,1fr)} .sel{width:100%} .hc{height:16px} }
</style>
