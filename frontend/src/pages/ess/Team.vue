<template>
  <div class="ess-page">
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
      <span class="ic lg ic-teal"><i class="fa-solid fa-calendar-days"></i></span>
      <div class="flex-grow-1"><h1 class="ess-h1">Kalendar & Pasukan</h1><p class="ess-sub">Siapa bercuti di {{ team?.office || 'pejabat anda' }} dan cuti umum</p></div>
      <div class="d-flex gap-2 align-items-center"><button class="btn-soft" @click="shift(-1)"><i class="fa-solid fa-chevron-left"></i></button>
        <b class="mon">{{ label }}</b><button class="btn-soft" @click="shift(1)"><i class="fa-solid fa-chevron-right"></i></button></div>
    </div>
    <div class="g">
      <section class="ess-card">
        <div class="cal-h"><span v-for="d in ['Isn','Sel','Rab','Kha','Jum','Sab','Ahd']" :key="d">{{ d }}</span></div>
        <div class="cal">
          <div v-for="c in cells" :key="c.key" class="c" :class="{ hol: c.hol, today: c.today, wk: c.wk }">
            <template v-if="c.n"><span class="n">{{ c.n }}</span>
              <span v-if="c.hol" class="hl">{{ c.hol }}</span>
              <span v-for="p in c.people.slice(0, 3)" :key="p.code + p.start_date" class="pp" :style="{ background: (p.color || '#64748b') + '22', color: p.color || '#334155' }" :title="`${p.name} — ${p.leave}${p.status === 'pending' ? ' (menunggu)' : ''}`">{{ initials(p.name) }}<i v-if="p.status === 'pending'" class="fa-solid fa-hourglass-half"></i></span>
              <span v-if="c.people.length > 3" class="more">+{{ c.people.length - 3 }}</span></template>
          </div>
        </div>
      </section>
      <div class="d-flex flex-column gap-3">
        <section class="ess-card">
          <h6 class="mb-2"><i class="fa-solid fa-people-group me-2 text-info"></i>Cuti pasukan bulan ini</h6>
          <div v-for="(p, i) in team?.leaves || []" :key="i" class="row1">
            <span class="av" :style="{ background: p.color || '#64748b' }">{{ initials(p.name) }}</span>
            <span class="min0 flex-grow-1"><b class="ell">{{ p.name }}</b><small>{{ p.leave }} · {{ d2(p.start_date) }}{{ p.end_date !== p.start_date ? ' – ' + d2(p.end_date) : '' }}</small></span>
            <span class="st" :class="p.status === 'approved' ? 'st-approved' : 'st-pending'">{{ p.status === 'approved' ? 'Lulus' : 'Menunggu' }}</span>
          </div>
          <div v-if="!team?.leaves?.length" class="empty"><i class="fa-solid fa-mug-hot"></i>Tiada cuti direkod</div>
        </section>
        <section class="ess-card">
          <h6 class="mb-2"><i class="fa-solid fa-star me-2 text-danger"></i>Cuti umum {{ year }}</h6>
          <div v-for="h in hols" :key="h.id" class="row1" :class="{ past: String(h.date).slice(0,10) < todayIso }">
            <span class="hd"><b>{{ +String(h.date).slice(8,10) }}</b><small>{{ monthName(+String(h.date).slice(5,7)) }}</small></span>
            <span class="flex-grow-1">{{ h.name }}<small class="d-block muted">{{ h.scope === 'selangor' ? 'Selangor' : 'Kebangsaan' }}</small></span>
          </div>
          <div class="note"><i class="fa-solid fa-circle-info"></i>Hanya cuti bertarikh tetap disenaraikan. Cuti berasaskan kalendar lunar (Hari Raya, Tahun Baru Cina, Deepavali, Wesak…) perlu ditambah oleh HR mengikut warta rasmi.</div>
        </section>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, computed, onMounted } from 'vue'
import { ess, d2, initials, monthName, today } from '../../services/ess'
const m = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1)), team = ref(null), hols = ref([]), todayIso = today()
const year = computed(() => m.value.getFullYear())
const label = computed(() => m.value.toLocaleDateString('ms-MY', { month: 'long', year: 'numeric' }))
const iso = d => d.toLocaleDateString('en-CA')
async function load() {
  const end = new Date(m.value.getFullYear(), m.value.getMonth() + 1, 0)
  team.value = await ess.team(iso(m.value), iso(end)); hols.value = await ess.holidays(year.value) || []
}
function shift(d) { m.value = new Date(m.value.getFullYear(), m.value.getMonth() + d, 1); load() }
const cells = computed(() => {
  const out = [], first = m.value, n = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate(), lead = (first.getDay() + 6) % 7
  const hol = Object.fromEntries(hols.value.map(h => [String(h.date).slice(0, 10), h.name]))
  for (let i = 0; i < lead; i++) out.push({ key: 'x' + i, people: [] })
  for (let d = 1; d <= n; d++) {
    const dt = new Date(first.getFullYear(), first.getMonth(), d), k = iso(dt)
    const people = (team.value?.leaves || []).filter(p => k >= String(p.start_date).slice(0, 10) && k <= String(p.end_date || p.start_date).slice(0, 10))
    out.push({ key: k, n: d, hol: hol[k], people, today: k === todayIso, wk: dt.getDay() === 0 })
  }
  return out
})
onMounted(load)
</script>
<style scoped>
.mon{min-width:140px;text-align:center;text-transform:capitalize}
.g{display:grid;grid-template-columns:1.6fr 1fr;gap:16px;align-items:start}
.cal-h,.cal{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}
.cal-h span{text-align:center;font-size:11.5px;font-weight:800;color:var(--muted)}
.c{min-height:92px;border-radius:14px;background:#f8fafc;padding:6px;display:flex;flex-wrap:wrap;align-content:flex-start;gap:3px}
.c:empty{background:transparent}.c.wk{background:#f1f5f9}.c.hol{background:#fff1f2}.c.today{box-shadow:0 0 0 2px var(--pri)}
.n{width:100%;font-weight:800;font-size:12.5px}
.hl{width:100%;font-size:10px;font-weight:700;color:#be123c;line-height:1.2}
.pp{font-size:10px;font-weight:800;border-radius:7px;padding:2px 5px;display:inline-flex;gap:3px;align-items:center}
.more{font-size:10px;color:var(--muted);font-weight:700}
.row1{display:flex;gap:10px;align-items:center;padding:7px 0;border-bottom:1px dashed var(--line);font-size:13px}.row1.past{opacity:.45}
.row1 b{display:block;font-size:13px}.row1 small{color:var(--muted);font-size:11.5px}
.ell{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.av{width:32px;height:32px;border-radius:50%;color:#fff;font-size:11px;font-weight:800;display:grid;place-items:center;flex-shrink:0}
.hd{width:42px;border-radius:10px;background:#fff1f2;color:#e11d48;text-align:center;line-height:1;padding:5px 0;flex-shrink:0}.hd b{display:block;font-size:16px}.hd small{font-size:10px;font-weight:700}
.note{margin-top:10px;font-size:12px;background:#fffbeb;color:#92400e;border-radius:10px;padding:8px 10px;display:flex;gap:8px}
@media (max-width:991.98px){ .g{grid-template-columns:1fr} }
@media (max-width:767.98px){ .c{min-height:60px} .hl{display:none} }
</style>
