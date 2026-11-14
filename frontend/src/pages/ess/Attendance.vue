<template>
  <div class="ess-page">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-3">
      <div><h1 class="ess-h1">Kehadiran Saya</h1><p class="ess-sub">Rekod clock-in/out, cuti dan cuti umum bulan {{ label }}</p></div>
      <div class="d-flex gap-2 align-items-center">
        <button class="btn-soft" @click="shift(-1)"><i class="fa-solid fa-chevron-left"></i></button>
        <div class="mon">{{ label }}</div>
        <button class="btn-soft" @click="shift(1)" :disabled="isCurrent"><i class="fa-solid fa-chevron-right"></i></button>
      </div>
    </div>

    <div class="sum">
      <div v-for="s in summary" :key="s.l" class="ess-card s lift"><span class="ic" :class="s.c"><i :class="s.i"></i></span><div><b>{{ s.v }}</b><small>{{ s.l }}</small></div></div>
    </div>

    <div class="ess-grid g2 mt-3">
      <section class="ess-card">
        <div class="d-flex align-items-center gap-2 mb-2"><span class="ic sm ic-violet"><i class="fa-solid fa-chart-line"></i></span><h6 class="m-0">Trend masa masuk & balik</h6></div>
        <InOutTrend :data="trend" :period="period" @period="p => { period = p; loadTrend() }" />
      </section>
      <section class="ess-card">
        <div class="d-flex align-items-center gap-2 mb-2"><span class="ic sm ic-teal"><i class="fa-solid fa-map-location-dot"></i></span><h6 class="m-0 flex-grow-1">Peta lokasi saya</h6>
          <small class="muted">{{ locs.points.length }} rekod · {{ gpsCount }} GPS</small></div>
        <LocationMap :points="locs.points" :sites="locs.sites" height="360px" />
        <div class="tiny muted mt-2">Clock-in melalui portal ini merekod GPS sebenar. Rekod lama menunjukkan koordinat tapak tempat anda didaftarkan (anggaran).</div>
      </section>
    </div>

    <div class="ess-grid g mt-3">
      <section class="ess-card">
        <div class="cal-h"><span v-for="d in ['Isn','Sel','Rab','Kha','Jum','Sab','Ahd']" :key="d">{{ d }}</span></div>
        <div class="cal">
          <div v-for="c in cells" :key="c.key" class="c" :class="[c.cls, { today: c.today, sel: sel?.iso === c.iso }]" @click="c.iso && (sel = c)">
            <template v-if="c.iso"><span class="n">{{ c.n }}</span><i v-if="c.icon" :class="c.icon"></i><small v-if="c.sub">{{ c.sub }}</small></template>
          </div>
        </div>
        <div class="legend">
          <span><i class="lg ok"></i>Hadir</span><span><i class="lg late"></i>Lewat (&gt;08:30)</span><span><i class="lg leave"></i>Cuti</span>
          <span><i class="lg hol"></i>Cuti umum</span><span><i class="lg miss"></i>Tiada clock-out</span><span><i class="lg rest"></i>Rehat</span>
        </div>
      </section>
      <section class="ess-card">
        <h6 class="mb-2">{{ sel ? d3(sel.iso) : 'Pilih tarikh' }}</h6>
        <template v-if="sel">
          <div class="det"><span class="ic sm ic-green"><i class="fa-solid fa-right-to-bracket"></i></span>Masuk<b>{{ hm(sel.row?.timeclock_in) }}</b></div>
          <div class="det"><span class="ic sm ic-rose"><i class="fa-solid fa-right-from-bracket"></i></span>Keluar<b>{{ hm(sel.row?.timeclock_out) }}</b></div>
          <div class="det"><span class="ic sm ic-violet"><i class="fa-solid fa-hourglass-half"></i></span>Tempoh<b>{{ dur(sel.row) }}</b></div>
          <div class="det"><span class="ic sm ic-blue"><i class="fa-solid fa-tag"></i></span>Status<b>{{ sel.label }}</b></div>
          <div v-if="sel.row?.source" class="det"><span class="ic sm ic-slate"><i class="fa-solid fa-mobile-screen"></i></span>Sumber<b>{{ sel.row.source === 'ess' ? 'Portal ESS' : 'Sistem asal' }}</b></div>
          <div v-if="sel.row?.lat" class="det"><span class="ic sm ic-teal"><i class="fa-solid fa-location-dot"></i></span>Lokasi
            <a class="ms-auto" :href="`https://maps.google.com/?q=${sel.row.lat},${sel.row.lng}`" target="_blank" rel="noopener">{{ (+sel.row.lat).toFixed(4) }}, {{ (+sel.row.lng).toFixed(4) }}</a></div>
          <div v-if="sel.missingOut" class="tip warn"><i class="fa-solid fa-triangle-exclamation"></i>Tiada clock-out — maklumkan penyelia untuk membetulkan rekod.</div>
          <button v-if="!sel.row && !sel.past" class="btn-pri w-100 mt-3" @click="openForm('leave', { start_date: sel.iso, end_date: sel.iso })"><i class="fa-solid fa-umbrella-beach me-2"></i>Mohon cuti pada tarikh ini</button>
        </template>
        <h6 class="mt-4 mb-2">Log bulan ini</h6>
        <div class="log">
          <div v-for="r in [...(data?.rows || [])].reverse()" :key="r.date" class="lr" @click="sel = cells.find(c => c.iso === String(r.date).slice(0,10))">
            <span class="dd"><b>{{ new Date(String(r.date).slice(0,10)).getDate() }}</b><small>{{ ['Ahd','Isn','Sel','Rab','Kha','Jum','Sab'][new Date(String(r.date).slice(0,10)).getDay()] }}</small></span>
            <span class="flex-grow-1">{{ hm(r.timeclock_in) }} → {{ hm(r.timeclock_out) }} <small class="muted">· {{ dur(r) }}</small></span>
            <span class="st" :class="r.status === 'on_leave' ? 'st-cancelled' : late(r) ? 'st-pending' : 'st-approved'">{{ r.status === 'on_leave' ? 'Cuti' : late(r) ? 'Lewat' : r.timeclock_in ? 'Hadir' : (r.status || '—') }}</span>
          </div>
          <div v-if="!data?.rows?.length" class="empty"><i class="fa-regular fa-calendar"></i>Tiada rekod</div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ess, openForm, hm, d3, today } from '../../services/ess'
import { apiGet } from '../../services/api'
import InOutTrend from '../../components/ess/InOutTrend.vue'
import LocationMap from '../../components/ess/LocationMap.vue'

const period = ref('month'), trend = ref(null), locs = ref({ points: [], sites: [] })
const gpsCount = computed(() => locs.value.points.filter(p => p.loc?.precision === 'gps').length)
async function loadTrend() { trend.value = await apiGet(`ess/me/trend?period=${period.value}`) }
async function loadLocs() {
  const end = new Date(month.value + '-01T00:00:00'); end.setMonth(end.getMonth() + 1); end.setDate(0)
  locs.value = await apiGet(`ess/me/locations?from=${month.value}-01&to=${end.toLocaleDateString('en-CA')}`) || { points: [], sites: [] }
}

const month = ref(new Date().toLocaleDateString('en-CA').slice(0, 7)), data = ref(null), sel = ref(null)
const label = computed(() => new Date(month.value + '-01T00:00:00').toLocaleDateString('ms-MY', { month: 'long', year: 'numeric' }))
const isCurrent = computed(() => month.value >= today().slice(0, 7))
const late = r => r?.timeclock_in && r.timeclock_in > '08:30' && r.timeclock_in < '12:00'
function dur(r) { if (!r?.timeclock_in || !r?.timeclock_out) return '—'; const m = (new Date(`1970-01-01T${r.timeclock_out}`) - new Date(`1970-01-01T${r.timeclock_in}`)) / 60000; return m > 0 ? `${Math.floor(m / 60)}j ${Math.round(m % 60)}m` : '—' }
async function load() { data.value = await ess.attendance(month.value); sel.value = cells.value.find(c => c.today) || null; loadLocs() }
function shift(d) { const x = new Date(month.value + '-01T00:00:00'); x.setMonth(x.getMonth() + d); month.value = x.toLocaleDateString('en-CA').slice(0, 7); load() }

const cells = computed(() => {
  if (!data.value) return []
  const first = new Date(month.value + '-01T00:00:00'), n = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate()
  const rows = Object.fromEntries(data.value.rows.map(r => [String(r.date).slice(0, 10), r]))
  const hol = Object.fromEntries(data.value.holidays.map(h => [String(h.date).slice(0, 10), h.name]))
  const leaveOn = iso => data.value.leaves.find(l => iso >= String(l.start_date).slice(0, 10) && iso <= String(l.end_date || l.start_date).slice(0, 10))
  const out = []; const lead = (first.getDay() + 6) % 7, t = today()
  for (let i = 0; i < lead; i++) out.push({ key: 'x' + i })
  for (let d = 1; d <= n; d++) {
    const dt = new Date(first.getFullYear(), first.getMonth(), d), iso = dt.toLocaleDateString('en-CA'), r = rows[iso], lv = leaveOn(iso)
    const rest = dt.getDay() === 0 || (data.value.workdays <= 5 && dt.getDay() === 6)
    let cls = '', icon = '', sub = '', lab = 'Tiada rekod'
    const missingOut = r?.timeclock_in && !r.timeclock_out && iso < t
    if (hol[iso]) { cls = 'hol'; icon = 'fa-solid fa-star'; sub = 'Cuti umum'; lab = hol[iso] }
    else if (r?.status === 'on_leave' || (lv && lv.status === 'approved')) { cls = 'leave'; icon = 'fa-solid fa-umbrella-beach'; sub = lv?.name_ms || 'Cuti'; lab = sub }
    else if (r?.timeclock_in) { cls = missingOut ? 'miss' : late(r) ? 'late' : 'ok'; icon = missingOut ? 'fa-solid fa-triangle-exclamation' : late(r) ? 'fa-solid fa-clock' : 'fa-solid fa-check'; sub = hm(r.timeclock_in); lab = late(r) ? 'Lewat' : 'Hadir' }
    else if (lv) { cls = 'pend'; icon = 'fa-solid fa-hourglass-half'; sub = 'Menunggu'; lab = 'Cuti menunggu kelulusan' }
    else if (rest) { cls = 'rest'; lab = 'Hari rehat' }
    out.push({ key: iso, iso, n: d, cls, icon, sub, row: r, label: lab, today: iso === t, past: iso < t, missingOut })
  }
  return out
})
const summary = computed(() => {
  const rows = data.value?.rows || []
  const worked = rows.filter(r => r.timeclock_in && r.timeclock_out)
  const mins = worked.reduce((s, r) => s + Math.max(0, (new Date(`1970-01-01T${r.timeclock_out}`) - new Date(`1970-01-01T${r.timeclock_in}`)) / 60000), 0)
  return [
    { l: 'Hari hadir', v: rows.filter(r => r.timeclock_in).length, i: 'fa-solid fa-calendar-check', c: 'ic-green' },
    { l: 'Lewat', v: rows.filter(late).length, i: 'fa-solid fa-person-running', c: 'ic-amber' },
    { l: 'Hari cuti', v: rows.filter(r => r.status === 'on_leave').length, i: 'fa-solid fa-umbrella-beach', c: 'ic-cyan' },
    { l: 'Jumlah jam', v: Math.round(mins / 60), i: 'fa-solid fa-hourglass-end', c: 'ic-violet' },
    { l: 'Tiada clock-out', v: rows.filter(r => r.timeclock_in && !r.timeclock_out && String(r.date).slice(0, 10) < today()).length, i: 'fa-solid fa-triangle-exclamation', c: 'ic-rose' },
  ]
})
onMounted(() => { load(); loadTrend() })
</script>

<style scoped>
.mon{font-weight:800;min-width:150px;text-align:center;text-transform:capitalize}
.sum{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
.s{display:flex;gap:12px;align-items:center;padding:14px 16px}.s b{font-size:22px;display:block;line-height:1}.s small{color:var(--muted);font-size:12px}
.g{grid-template-columns:1.5fr 1fr}
.g2{grid-template-columns:1.2fr 1fr}
.cal-h,.cal{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}
.cal-h span{text-align:center;font-size:11.5px;font-weight:800;color:var(--muted);padding-bottom:4px}
.c{min-height:74px;border-radius:14px;background:#f8fafc;padding:6px 8px;display:flex;flex-direction:column;cursor:pointer;transition:transform .12s,box-shadow .12s;position:relative}
.c:empty{background:transparent;cursor:default}
.c:not(:empty):hover{transform:translateY(-2px);box-shadow:0 8px 18px rgba(16,24,40,.08)}
.c .n{font-weight:800;font-size:13px}.c i{margin-top:auto;font-size:13px}.c small{font-size:10px;font-weight:600}
.c.ok{background:#ecfdf5;color:#047857}.c.late{background:#fffbeb;color:#b45309}.c.leave{background:#ecfeff;color:#0e7490}
.c.hol{background:#fff1f2;color:#be123c}.c.miss{background:#fff1f2;color:#e11d48}.c.rest{background:#f1f5f9;color:#94a3b8}.c.pend{background:#f5f3ff;color:#6d28d9}
.c.today{box-shadow:0 0 0 2px var(--pri)}.c.sel{box-shadow:0 0 0 3px #a5b4fc}
.legend{display:flex;gap:14px;flex-wrap:wrap;margin-top:12px;font-size:12px;color:var(--muted)}
.lg{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px}
.lg.ok{background:#10b981}.lg.late{background:#f59e0b}.lg.leave{background:#06b6d4}.lg.hol{background:#f43f5e}.lg.miss{background:#e11d48}.lg.rest{background:#cbd5e1}
.det{display:flex;gap:10px;align-items:center;padding:7px 0;font-size:13.5px;border-bottom:1px dashed var(--line)}.det b{margin-left:auto}
.tip{margin-top:10px;border-radius:12px;padding:8px 10px;font-size:12.5px;display:flex;gap:8px}.tip.warn{background:#fff1f2;color:#be123c}
.log{max-height:420px;overflow:auto}
.lr{display:flex;gap:10px;align-items:center;padding:8px 4px;border-radius:10px;font-size:13px;cursor:pointer}.lr:hover{background:#f8fafc}
.dd{width:40px;text-align:center;line-height:1;background:#eef2ff;color:#4338ca;border-radius:10px;padding:5px 0}.dd b{display:block}.dd small{font-size:10px}
@media (max-width:991.98px){ .g,.g2{grid-template-columns:1fr} .sum{grid-template-columns:repeat(3,1fr)} }
@media (max-width:767.98px){ .sum{grid-template-columns:repeat(2,1fr)} .c{min-height:56px;padding:4px 5px} .c small{display:none} .cal,.cal-h{gap:4px} }
</style>
