<template>
  <div>
    <div class="io-head">
      <div class="ess-tabs">
        <button v-for="p in PERIODS" :key="p.k" :class="{ on: period === p.k }" @click="$emit('period', p.k)">{{ p.l }}</button>
      </div>
      <div class="ess-tabs">
        <button :class="{ on: mode === 'daily' }" @click="mode = 'daily'"><i class="fa-solid fa-chart-line"></i>Harian</button>
        <button :class="{ on: mode === 'weekly' }" @click="mode = 'weekly'"><i class="fa-solid fa-calendar-week"></i>Purata mingguan</button>
      </div>
    </div>
    <div class="io-stats">
      <div><small>Purata masuk</small><b>{{ fmt(avg('in')) }}</b></div>
      <div><small>Purata balik</small><b>{{ fmt(avg('out')) }}</b></div>
      <div><small>Purata jam/hari</small><b>{{ avgHours }}</b></div>
      <div><small>Hari lewat</small><b :class="{ bad: lateDays }">{{ lateDays }}</b></div>
      <div><small>Trend masuk</small><b :class="drift > 0.05 ? 'bad' : drift < -0.05 ? 'good' : ''"><i class="fa-solid" :class="drift > 0.05 ? 'fa-arrow-trend-up' : drift < -0.05 ? 'fa-arrow-trend-down' : 'fa-minus'"></i> {{ driftText }}</b></div>
    </div>
    <div class="io-chart" :style="{ height }"><canvas ref="el"></canvas></div>
    <div class="tiny muted mt-1">Garis putus merah = 08:30 (had lewat). Garis putus kelabu = 17:00. Bar kelabu = jam bekerja (paksi kanan).</div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import Chart from 'chart.js/auto'
const props = defineProps({ data: Object, period: { type: String, default: 'month' }, height: { type: String, default: '300px' } })
defineEmits(['period'])
const PERIODS = [{ k: 'week', l: 'Minggu' }, { k: 'month', l: 'Bulan' }, { k: 'quarter', l: '3 Bulan' }]
const el = ref(null), mode = ref('daily'); let chart
const series = computed(() => mode.value === 'weekly' ? (props.data?.weekly || []).map(w => ({ ...w, label: 'Mg ' + w.week.slice(5) })) : (props.data?.daily || []).map(d => ({ ...d, label: d.date.slice(5) })))
const fmt = h => { if (h == null || isNaN(h)) return '—'; const m = Math.round(h * 60); return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}` }
const avg = k => { const v = (props.data?.daily || []).map(d => d[k]).filter(x => x != null); return v.length ? v.reduce((a, b) => a + b, 0) / v.length : null }
const avgHours = computed(() => { const v = (props.data?.daily || []).map(d => d.hours).filter(Boolean); return v.length ? (v.reduce((a, b) => a + b, 0) / v.length).toFixed(1) + ' j' : '—' })
const lateDays = computed(() => (props.data?.daily || []).filter(d => d.late).length)
// slope of arrival time (hours per week) via least squares on daily points
const drift = computed(() => {
  const d = (props.data?.daily || []).filter(x => x.in != null); if (d.length < 4) return 0
  const xs = d.map(x => new Date(x.date) / 864e5), ys = d.map(x => x.in), n = xs.length
  const mx = xs.reduce((a, b) => a + b) / n, my = ys.reduce((a, b) => a + b) / n
  const s = xs.reduce((a, x, i) => a + (x - mx) * (ys[i] - my), 0) / (xs.reduce((a, x) => a + (x - mx) ** 2, 0) || 1)
  return s * 7
})
const driftText = computed(() => Math.abs(drift.value) <= 0.05 ? 'stabil' : `${drift.value > 0 ? 'lewat' : 'awal'} ${Math.round(Math.abs(drift.value) * 60)} min/minggu`)

function draw() {
  if (!el.value) return; chart?.destroy()
  const s = series.value, ref = (v, c) => s.map(() => v)
  chart = new Chart(el.value, {
    data: { labels: s.map(x => x.label), datasets: [
      { type: 'line', label: 'Masuk', data: s.map(x => x.in), borderColor: '#16a34a', backgroundColor: '#16a34a', tension: .35, pointRadius: s.length > 40 ? 0 : 3, pointHoverRadius: 6, yAxisID: 'y', spanGaps: true,
        pointBackgroundColor: s.map(x => x.in > 8.5 ? '#f59e0b' : '#16a34a') },
      { type: 'line', label: 'Balik', data: s.map(x => x.out), borderColor: '#6366f1', backgroundColor: '#6366f1', tension: .35, pointRadius: s.length > 40 ? 0 : 3, pointHoverRadius: 6, yAxisID: 'y', spanGaps: true },
      { type: 'line', label: '08:30', data: ref(8.5), borderColor: '#e11d48', borderDash: [6, 5], borderWidth: 1.5, pointRadius: 0, yAxisID: 'y' },
      { type: 'line', label: '17:00', data: ref(17), borderColor: '#94a3b8', borderDash: [6, 5], borderWidth: 1.2, pointRadius: 0, yAxisID: 'y' },
      { type: 'bar', label: 'Jam bekerja', data: s.map(x => x.hours), backgroundColor: 'rgba(148,163,184,.18)', borderRadius: 4, yAxisID: 'y2', order: 9 },
    ] },
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, filter: i => !['08:30', '17:00'].includes(i.text) } },
        tooltip: { filter: i => !['08:30', '17:00'].includes(i.dataset.label),
          callbacks: { label: c => c.dataset.yAxisID === 'y2' ? `Jam bekerja: ${c.raw ?? '—'} j` : `${c.dataset.label}: ${fmt(c.raw)}`,
            footer: it => { const x = s[it[0].dataIndex]; return x?.late ? `⚠ lewat${x.n > 1 ? ` (${x.late} staf)` : ''}` : '' } } } },
      scales: { y: { min: 5, max: 21, reverse: false, ticks: { stepSize: 2, callback: v => fmt(v) }, grid: { color: '#f1f5f9' }, title: { display: true, text: 'Masa' } },
        y2: { position: 'right', min: 0, max: 16, grid: { display: false }, ticks: { callback: v => v + 'j' } }, x: { grid: { display: false }, ticks: { maxTicksLimit: 16 } } } },
  })
}
watch([series], draw); onMounted(draw); onBeforeUnmount(() => chart?.destroy())
</script>

<style scoped>
.io-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.io-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:10px}
.io-stats div{background:#f8fafc;border-radius:12px;padding:8px 10px}
.io-stats small{display:block;font-size:11px;color:var(--muted,#64748b)}.io-stats b{font-size:16px}
.io-stats .bad{color:#e11d48}.io-stats .good{color:#16a34a}
.io-chart{position:relative}
@media (max-width:767.98px){ .io-stats{grid-template-columns:repeat(2,1fr)} }
</style>
