<template>
  <div class="ess-page">
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
      <span class="ic lg ic-indigo"><i class="fa-solid fa-chart-pie"></i></span>
      <div class="flex-grow-1"><h1 class="ess-h1">Laporan Saya</h1><p class="ess-sub">Ringkasan tahunan kehadiran, cuti, OT, time-off dan tuntutan</p></div>
      <div class="ess-tabs"><button v-for="y in [year - 1, year]" :key="y" :class="{ on: yr === y }" @click="yr = y; load()">{{ y }}</button></div>
      <button class="btn-soft" :disabled="!rep" @click="csv"><i class="fa-solid fa-file-csv me-1"></i>Muat turun CSV</button>
      <button class="btn-soft" @click="print"><i class="fa-solid fa-print me-1"></i>Cetak</button>
    </div>
    <template v-if="rep">
      <div class="kp">
        <div v-for="k in kpis" :key="k.l" class="ess-card k lift"><span class="ic" :class="k.c"><i :class="k.i"></i></span><div><b>{{ k.v }}</b><small>{{ k.l }}</small></div></div>
      </div>
      <div class="g mt-3">
        <section class="ess-card"><h6 class="mb-2">Hari hadir & jam bekerja</h6><div class="ch"><canvas ref="c1"></canvas></div></section>
        <section class="ess-card"><h6 class="mb-2">Cuti, OT & lewat</h6><div class="ch"><canvas ref="c2"></canvas></div></section>
      </div>
      <section class="ess-card mt-3">
        <h6 class="mb-2">Butiran bulanan</h6>
        <div class="table-responsive"><table class="table tbl">
          <thead><tr><th>Bulan</th><th>Hadir</th><th>Lewat</th><th>Jam</th><th>Tiada clock-out</th><th>Cuti (ESS)</th><th>Cuti (rekod)</th><th>OT (j)</th><th>Time-off (j)</th><th>Tuntutan (RM)</th></tr></thead>
          <tbody><tr v-for="m in rep.months" :key="m.month" :class="{ dim: !m.present && !m.leave && !m.ot }">
            <td><b>{{ monthName(m.month) }}</b></td><td>{{ m.present }}</td><td :class="{ warn: m.late >= 4 }">{{ m.late }}</td><td>{{ m.hours }}</td><td :class="{ warn: m.no_out }">{{ m.no_out }}</td>
            <td>{{ m.leave }}</td><td>{{ m.leave_days_att }}</td><td>{{ m.ot }}</td><td>{{ m.timeoff }}</td><td>{{ m.claims.toFixed(2) }}</td></tr></tbody>
        </table></div>
        <div class="tiny muted">"Cuti (ESS)" = cuti diluluskan melalui portal ini. "Cuti (rekod)" = hari bertanda cuti dalam rekod kehadiran sistem asal (jenis cuti tidak direkodkan).</div>
      </section>
    </template>
    <div v-else class="empty"><span class="spinner-border spinner-border-sm"></span></div>
  </div>
</template>
<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
import Chart from 'chart.js/auto'
import { ess, monthName } from '../../services/ess'
const year = new Date().getFullYear(), yr = ref(year), rep = ref(null), c1 = ref(null), c2 = ref(null); let ch1, ch2
const sum = k => rep.value.months.reduce((s, m) => s + (+m[k] || 0), 0)
const kpis = computed(() => {
  const annual = rep.value.balances.find(b => b.code === 'annual')
  return [
    { l: 'Hari hadir', v: sum('present'), i: 'fa-solid fa-calendar-check', c: 'ic-green' },
    { l: 'Jam bekerja', v: Math.round(sum('hours')), i: 'fa-solid fa-hourglass-end', c: 'ic-violet' },
    { l: 'Kali lewat', v: sum('late'), i: 'fa-solid fa-person-running', c: 'ic-amber' },
    { l: 'Baki cuti tahunan', v: annual ? `${annual.available}/${annual.entitled}` : '—', i: 'fa-solid fa-umbrella-beach', c: 'ic-cyan' },
    { l: 'Jam OT diluluskan', v: sum('ot'), i: 'fa-solid fa-business-time', c: 'ic-indigo' },
    { l: 'Tuntutan diluluskan', v: 'RM ' + sum('claims').toFixed(2), i: 'fa-solid fa-sack-dollar', c: 'ic-teal' },
  ]
})
async function load() { rep.value = await ess.report(yr.value); await nextTick(); draw() }
function draw() {
  ch1?.destroy(); ch2?.destroy(); const L = rep.value.months.map(m => monthName(m.month))
  ch1 = new Chart(c1.value, { data: { labels: L, datasets: [
    { type: 'bar', label: 'Hari hadir', data: rep.value.months.map(m => m.present), backgroundColor: '#a5b4fc', borderRadius: 6, yAxisID: 'y' },
    { type: 'line', label: 'Jam', data: rep.value.months.map(m => m.hours), borderColor: '#7c3aed', tension: .35, yAxisID: 'y2', pointRadius: 3 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
      scales: { y: { grid: { color: '#f1f5f9' } }, y2: { position: 'right', grid: { display: false } }, x: { grid: { display: false } } } } })
  ch2 = new Chart(c2.value, { type: 'bar', data: { labels: L, datasets: [
    { label: 'Cuti (hari)', data: rep.value.months.map(m => m.leave + m.leave_days_att), backgroundColor: '#67e8f9', borderRadius: 6 },
    { label: 'OT (jam)', data: rep.value.months.map(m => m.ot), backgroundColor: '#c4b5fd', borderRadius: 6 },
    { label: 'Lewat', data: rep.value.months.map(m => m.late), backgroundColor: '#fcd34d', borderRadius: 6 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } }, scales: { y: { grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } } } })
}
function csv() {
  const head = ['month', 'present', 'late', 'hours', 'no_clock_out', 'leave_ess', 'leave_records', 'ot_hours', 'timeoff_hours', 'claims_rm']
  const rows = rep.value.months.map(m => [monthName(m.month), m.present, m.late, m.hours, m.no_out, m.leave, m.leave_days_att, m.ot, m.timeoff, m.claims])
  const blob = new Blob([[`# ${rep.value.employee.name} (${rep.value.employee.code}) — ${rep.value.year}`, head.join(','), ...rows.map(r => r.join(','))].join('\n')], { type: 'text/csv' })
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `laporan-${rep.value.employee.code}-${rep.value.year}.csv`; a.click(); URL.revokeObjectURL(a.href)
}
const print = () => window.print()
onMounted(load); onBeforeUnmount(() => { ch1?.destroy(); ch2?.destroy() })
</script>
<style scoped>
.kp{display:grid;grid-template-columns:repeat(6,1fr);gap:12px}
.k{display:flex;gap:10px;align-items:center;padding:14px}.k b{display:block;font-size:19px;line-height:1.1}.k small{color:var(--muted);font-size:11.5px}
.g{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.ch{position:relative;height:260px}
.tbl th{font-size:11px;text-transform:uppercase;color:#94a3b8;white-space:nowrap}.tbl td{font-size:13px}
.tbl tr.dim td{color:#cbd5e1}.tbl td.warn{color:#e11d48;font-weight:800}
@media (max-width:1199.98px){ .kp{grid-template-columns:repeat(3,1fr)} }
@media (max-width:767.98px){ .kp{grid-template-columns:repeat(2,1fr)} .g{grid-template-columns:1fr} }
</style>
