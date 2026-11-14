<template>
  <div class="ai">
    <div v-if="!ins" class="text-center py-4 text-muted2"><span class="spinner-border spinner-border-sm me-2"></span>Analysing attendance…</div>
    <template v-else>
      <!-- briefing -->
      <div class="brief" :class="`tone-${ins.summary.tone}`">
        <div class="brief-head">
          <span class="brief-ic"><i class="fa-solid fa-user-clock"></i></span>
          <div><div class="brief-t">Attendance briefing · last {{ ins.period.days }} days</div>
            <div class="brief-s">{{ fmtD(ins.period.from) }} – {{ fmtD(ins.period.to) }} vs previous {{ ins.period.days }} days{{ office ? ` · ${office}` : '' }} · late = clock-in after {{ ins.period.late_after }}</div></div>
          <div class="ms-auto d-flex gap-2 align-items-center flex-wrap">
            <span v-for="s in SEV" :key="s.k" v-show="ins.summary.counts[s.k]" class="sev-pill" :class="`sev-${s.k}`" @click="actFilter = s.k; open = true">{{ ins.summary.counts[s.k] }} {{ s.l }}</span>
            <div class="seg seg-dark"><button v-for="d in [7, 30, 90]" :key="d" :class="{ on: days === d }" @click="days = d">{{ d }}d</button></div>
            <button class="btn btn-sm btn-light collapse-btn" @click="open = !open" :title="open ? 'Collapse' : 'Expand'"><i class="fa-solid" :class="open ? 'fa-chevron-up' : 'fa-chevron-down'"></i></button>
          </div>
        </div>
        <div class="brief-cols">
          <div class="bcol"><div class="bcol-h"><i class="fa-solid fa-chart-column"></i>What happened</div><ul><li v-for="(t, i) in ins.summary.what" :key="i">{{ t }}</li></ul></div>
          <div class="bcol"><div class="bcol-h"><i class="fa-solid fa-magnifying-glass-chart"></i>Why</div><ul><li v-for="(t, i) in ins.summary.why" :key="i">{{ t }}</li></ul></div>
          <div class="bcol"><div class="bcol-h"><i class="fa-solid fa-bolt"></i>What to do</div><ul><li v-for="(t, i) in ins.summary.do" :key="i">{{ t }}</li></ul></div>
        </div>
      </div>

      <!-- KPIs -->
      <div class="kpis mt-3">
        <button v-for="k in ins.kpis" :key="k.key" class="kpi" :class="{ on: metric === k.key }" @click="k.spark?.length && (metric = k.key, open = true)">
          <div class="kpi-l">{{ k.label }}</div>
          <div class="kpi-v">{{ k.value ?? '—' }}<small>{{ k.unit === '%' ? '%' : k.unit === 'h' ? ' h' : '' }}</small></div>
          <div class="kpi-f">
            <span v-if="k.delta !== null" class="delta" :class="tone(k)"><i class="fa-solid" :class="k.delta >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'"></i>{{ Math.abs(k.delta) }}{{ k.unit === '%' ? ' pts' : '' }}</span>
            <span v-else-if="k.prev" class="delta flat">was {{ k.prev }}</span>
            <svg v-if="k.spark?.length > 1" class="spark" viewBox="0 0 80 24" preserveAspectRatio="none"><polyline :points="sparkPts(k.spark)" fill="none" :stroke="tone(k) === 'bad' ? '#e11d48' : tone(k) === 'good' ? '#16a34a' : '#64748b'" stroke-width="2" /></svg>
          </div>
          <div class="kpi-tip">{{ k.hint }}<span v-if="k.prev !== null"><br>Previous period: {{ k.prev }}</span></div>
        </button>
      </div>

      <transition name="fold">
        <div v-show="open">
          <!-- charts -->
          <div class="row g-3 mt-1">
            <div class="col-xl-6">
              <div class="card p-4 h-100">
                <div class="d-flex justify-content-between align-items-start"><div><h6 class="fw-bold mb-0">{{ metricLabel }} — daily trend</h6>
                  <p class="text-muted2 small mb-0">Last 60 days · click a KPI to switch · click a point to open that day below</p></div></div>
                <div class="chart-wrap"><canvas ref="trendEl"></canvas></div>
              </div>
            </div>
            <div class="col-xl-3 col-md-6">
              <div class="card p-4 h-100">
                <h6 class="fw-bold mb-0">Arrival time</h6>
                <p class="text-muted2 small mb-0">Clock-ins per 15 min · red = after {{ ins.period.late_after }}</p>
                <div class="chart-wrap"><canvas ref="arrEl"></canvas></div>
              </div>
            </div>
            <div class="col-xl-3 col-md-6">
              <div class="card p-4 h-100">
                <h6 class="fw-bold mb-0">Weekday pattern</h6>
                <p class="text-muted2 small mb-2">Attendance &amp; on-time % by day</p>
                <div class="wd">
                  <div v-for="w in ins.weekday" :key="w.day" class="wd-row" :title="`${w.n} records · ${w.late} late · ${w.absent} absent · ${w.leave} leave`">
                    <b>{{ w.day }}</b>
                    <div class="wd-bars">
                      <div class="wd-bar"><div :style="{ width: w.rate + '%' }" class="b-rate"></div></div>
                      <div class="wd-bar"><div :style="{ width: (w.punctual ?? 0) + '%' }" :class="(w.punctual ?? 0) < worstPunct + 0.01 ? 'b-bad' : 'b-punct'"></div></div>
                    </div>
                    <span class="wd-v">{{ w.rate }}%<br><small>{{ w.punctual ?? '—' }}%</small></span>
                  </div>
                </div>
                <div class="tiny text-muted2 mt-2"><i class="lg b-rate"></i>attendance <i class="lg b-punct ms-2"></i>on time</div>
              </div>
            </div>
          </div>

          <!-- actions + office/role -->
          <div class="row g-3 mt-1">
            <div class="col-xl-7">
              <div class="card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <h6 class="fw-bold mb-0"><i class="fa-solid fa-list-check me-2 text-primary"></i>Action center</h6>
                  <div class="seg seg-xs">
                    <button :class="{ on: actFilter === 'all' }" @click="actFilter = 'all'">All {{ openActs.length }}</button>
                    <button v-for="s in SEV" :key="s.k" v-show="ins.summary.counts[s.k]" :class="{ on: actFilter === s.k }" @click="actFilter = s.k">{{ s.l }}</button>
                    <button :class="{ on: actFilter === 'done' }" @click="actFilter = 'done'"><i class="fa-solid fa-check"></i> {{ ins.actions.length - openActs.length }}</button>
                  </div>
                </div>
                <transition-group name="act" tag="div" class="acts mt-3">
                  <div v-for="a in shownActs" :key="a.id" class="act" :class="[`sev-b-${a.sev}`, { done: done[a.id] }]">
                    <button class="act-check" @click="toggleDone(a)"><i class="fa-solid fa-check"></i></button>
                    <div class="act-body">
                      <div class="act-top"><span class="sev-dot" :class="`sev-${a.sev}`">{{ a.sev }}</span><span class="act-cat">{{ a.cat }}</span>
                        <span class="act-owner"><i class="fa-solid fa-user-tie"></i>{{ a.owner }}</span></div>
                      <div class="act-title">{{ a.title }}</div>
                      <div class="act-why"><b>Why:</b> {{ a.why }}</div>
                      <div class="act-do"><i class="fa-solid fa-arrow-right"></i>{{ a.action }}</div>
                    </div>
                  </div>
                </transition-group>
                <div v-if="!shownActs.length" class="text-center text-muted2 py-3">Nothing here.</div>
              </div>
            </div>
            <div class="col-xl-5">
              <div class="card p-4 h-100">
                <h6 class="fw-bold mb-0">By office &amp; role</h6>
                <p class="text-muted2 small mb-2">Click an office to filter the whole page</p>
                <div v-for="o in ins.by_office" :key="o.key" class="grp" :class="{ on: office === o.key }" @click="$emit('office', office === o.key ? 'all' : o.key)">
                  <span class="off-badge">{{ o.key }}</span>
                  <div class="flex-grow-1"><div class="d-flex justify-content-between small"><span>{{ o.staff }} staff</span><b>{{ o.rate }}%</b></div>
                    <div class="g-bar"><div :style="{ width: o.rate + '%' }" :class="o.rate < 93 ? 'b-bad' : 'b-rate'"></div></div></div>
                  <span class="g-p" title="On-time %">{{ o.punctual ?? '—' }}%<small>on time</small></span>
                </div>
                <div class="role-h">Role · on-time %</div>
                <div class="roles">
                  <div v-for="r in roleRows" :key="r.key" class="role" :title="`${r.late} late arrivals · avg clock-in ${r.avg_in_txt}`">
                    <span>{{ r.key.replace(/_/g, ' ') }}</span>
                    <div class="g-bar sm"><div :style="{ width: (r.punctual ?? 0) + '%' }" :class="(r.punctual ?? 100) < 75 ? 'b-bad' : 'b-punct'"></div></div>
                    <b>{{ r.punctual ?? '—' }}%</b>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- people watchlist -->
          <div class="card p-4 mt-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
              <div><h6 class="fw-bold mb-0">Staff watchlist</h6>
                <p class="text-muted2 small mb-0">Individuals with repeated lateness, absence, missing clock-outs or very long days · click to open their month</p></div>
              <div class="seg seg-xs"><button v-for="f in FLAGS" :key="f.k" :class="{ on: flag === f.k }" @click="flag = f.k">{{ f.l }} {{ flagCount(f.k) }}</button></div>
            </div>
            <div class="people mt-3">
              <div v-for="p in peopleRows" :key="p.emp_id" class="person" @click="$emit('view', { code: p.code, name: p.name })">
                <span class="avatar">{{ initials(p.name) }}</span>
                <div class="min0 flex-grow-1">
                  <div class="p-name">{{ p.name }}</div>
                  <div class="tiny text-muted2">{{ p.code }} · {{ p.role.replace(/_/g, ' ') }} · {{ p.office }} · avg in {{ p.avg_in || '—' }}{{ p.late_pattern ? ` · mostly late on ${p.late_pattern}` : '' }}</div>
                  <div class="mt-1"><span v-for="f in p.flags" :key="f.k" class="flag" :class="`f-${f.k}`">{{ f.t }}</span></div>
                </div>
                <i class="fa-solid fa-chevron-right text-muted2"></i>
              </div>
            </div>
            <button v-if="filteredPeople.length > 8" class="btn btn-light btn-sm w-100 mt-2" @click="allPeople = !allPeople">{{ allPeople ? 'Show fewer' : `Show all ${filteredPeople.length}` }}</button>
          </div>

          <!-- data quality -->
          <div v-if="ins.quality.total" class="dq mt-3">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div><b>{{ ins.quality.total }} attendance records need correction before payroll</b>
              <div class="small">{{ ins.quality.present_no_in }} present without clock-in · {{ ins.quality.no_out }} without clock-out · {{ ins.quality.late_but_early }} marked late but arrived before {{ ins.period.late_after }} · {{ ins.quality.no_status }} with no status · {{ ins.quality.absent_with_in }} absent but clocked in</div></div>
          </div>
        </div>
      </transition>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { apiGet } from '../services/api'
import Chart from 'chart.js/auto'

const props = defineProps({ office: { type: String, default: null } })
const emit = defineEmits(['view', 'office', 'day'])
const SEV = [{ k: 'critical', l: 'Critical' }, { k: 'high', l: 'High' }, { k: 'medium', l: 'Medium' }, { k: 'low', l: 'Low' }]
const FLAGS = [{ k: 'all', l: 'All' }, { k: 'late', l: 'Late' }, { k: 'absent', l: 'Absent' }, { k: 'no_out', l: 'No clock-out' }, { k: 'long', l: '> 11h' }, { k: 'leave', l: 'Leave' }]

const ins = ref(null), days = ref(30), metric = ref('rate'), actFilter = ref('all'), flag = ref('all'), allPeople = ref(false)
const open = ref((() => { try { return localStorage.getItem('sm_att_open') !== '0' } catch { return true } })())
const done = reactive((() => { try { return JSON.parse(localStorage.getItem('sm_done_att') || '{}') } catch { return {} } })())
const trendEl = ref(null), arrEl = ref(null); let trendChart, arrChart

const metricLabel = computed(() => ins.value?.kpis.find(k => k.key === metric.value)?.label || 'Attendance rate')
const openActs = computed(() => (ins.value?.actions || []).filter(a => !done[a.id]))
const shownActs = computed(() => actFilter.value === 'done' ? ins.value.actions.filter(a => done[a.id]) : openActs.value.filter(a => actFilter.value === 'all' || a.sev === actFilter.value))
const worstPunct = computed(() => Math.min(...(ins.value?.weekday || []).filter(w => w.n > 20).map(w => w.punctual ?? 100)))
const roleRows = computed(() => [...(ins.value?.by_role || [])].filter(r => r.clocked_am > 5).sort((a, b) => (a.punctual ?? 100) - (b.punctual ?? 100)))
const filteredPeople = computed(() => (ins.value?.people || []).filter(p => flag.value === 'all' || p.flags.some(f => f.k === flag.value)))
const peopleRows = computed(() => allPeople.value ? filteredPeople.value : filteredPeople.value.slice(0, 8))
const flagCount = k => (ins.value?.people || []).filter(p => k === 'all' || p.flags.some(f => f.k === k)).length

const fmtD = d => new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' })
const initials = n => (n || '').split(/\s+/).filter(w => !/^(BIN|BINTI|A\/L|A\/P)$/i.test(w)).slice(0, 2).map(w => w[0]).join('')
function tone(k) { if (k.delta == null || !k.good || Math.abs(k.delta) < 0.1) return 'flat'; return (k.delta > 0) === (k.good === 'up') ? 'good' : 'bad' }
function sparkPts(a) { const v = a.map(x => x ?? 0), mn = Math.min(...v), mx = Math.max(...v), r = mx - mn || 1; return v.map((x, i) => `${i / (v.length - 1) * 80},${22 - (x - mn) / r * 20}`).join(' ') }
function toggleDone(a) { if (done[a.id]) delete done[a.id]; else done[a.id] = 1; try { localStorage.setItem('sm_done_att', JSON.stringify(done)) } catch {} }

async function loadIns() {
  const p = new URLSearchParams({ days: days.value }); if (props.office) p.set('office', props.office)
  const r = await apiGet(`attendance/insights?${p}`)
  if (r) { ins.value = r; await nextTick(); draw() }
}
function draw() { drawTrend(); drawArr() }
function drawTrend() {
  if (!trendEl.value || !open.value) return; trendChart?.destroy()
  const t = ins.value.trend, k = metric.value, isPct = ['rate', 'punctual'].includes(k)
  trendChart = new Chart(trendEl.value, {
    type: isPct || k === 'avg_h' ? 'line' : 'bar',
    data: { labels: t.map(x => x.date.slice(5)), datasets: [{ data: t.map(x => x[k]), borderColor: '#2563eb', backgroundColor: isPct ? 'rgba(37,99,235,.1)' : '#93c5fd',
      fill: isPct, tension: .3, pointRadius: 2, pointHoverRadius: 6, borderRadius: 4 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false },
        tooltip: { callbacks: { afterLabel: c => { const r = t[c.dataIndex]; return `${r.attended} present · ${r.late} late · ${r.leave} leave · ${r.absent} absent` } } } },
      onHover: (e, el) => e.native.target.style.cursor = el.length ? 'pointer' : 'default',
      onClick: (e, el) => { if (el.length) emit('day', t[el[0].index].date) },
      scales: { y: { grid: { color: '#f1f5f9' }, ...(isPct ? { suggestedMin: 60, max: 100 } : {}) }, x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } } } },
  })
}
function drawArr() {
  if (!arrEl.value || !open.value) return; arrChart?.destroy()
  const a = ins.value.arrivals, lim = ins.value.period.late_after
  arrChart = new Chart(arrEl.value, {
    type: 'bar',
    data: { labels: a.map(x => x.slot), datasets: [{ data: a.map(x => x.count), backgroundColor: a.map(x => x.slot >= lim ? '#fb7185' : '#86efac'), borderRadius: 3 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { title: i => `${i[0].label}–`, label: c => `${c.raw} clock-ins` } } },
      scales: { y: { grid: { color: '#f1f5f9' } }, x: { grid: { display: false }, ticks: { maxTicksLimit: 7 } } } },
  })
}

watch([days, () => props.office], loadIns)
watch(metric, drawTrend)
watch(open, async v => { try { localStorage.setItem('sm_att_open', v ? '1' : '0') } catch {}; if (v) { await nextTick(); draw() } })
onMounted(loadIns)
onBeforeUnmount(() => { trendChart?.destroy(); arrChart?.destroy() })
</script>

<style scoped>
.brief{border-radius:20px;padding:20px 22px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:#e2e8f0;box-shadow:0 20px 40px rgba(30,58,138,.25);position:relative;overflow:hidden}
.brief::after{content:'';position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(96,165,250,.35),transparent 70%);pointer-events:none}
.brief.tone-bad{background:linear-gradient(135deg,#1f1027,#7f1d1d 55%,#9a3412)}
.brief.tone-good{background:linear-gradient(135deg,#052e2b,#065f46 60%,#0f766e)}
.brief-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.brief-ic{width:44px;height:44px;border-radius:14px;background:rgba(255,255,255,.12);display:grid;place-items:center;font-size:20px;color:#fff}
.brief-t{font-weight:800;font-size:18px;color:#fff}
.brief-s{font-size:12.5px;opacity:.75}
.sev-pill{cursor:pointer;font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px;transition:transform .15s}
.sev-pill:hover{transform:translateY(-2px)}
.seg-dark{background:rgba(255,255,255,.12);box-shadow:none;padding:3px}
.seg-dark button{color:#e2e8f0;padding:.25rem .7rem;font-size:12px}
.seg-dark button.on{background:#fff;color:#0f172a}
.collapse-btn{border-radius:10px}
.brief-cols{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:16px;position:relative;z-index:1}
.bcol{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:12px 14px;transition:background .2s}
.bcol:hover{background:rgba(255,255,255,.13)}
.bcol-h{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#99f6e4;margin-bottom:6px;display:flex;gap:8px;align-items:center}
.bcol ul{margin:0;padding-left:18px;font-size:13.5px;line-height:1.5}
.bcol li+li{margin-top:4px}
.sev-critical{background:#e11d48;color:#fff}.sev-high{background:#f97316;color:#fff}.sev-medium{background:#facc15;color:#422006}.sev-low{background:#cbd5e1;color:#334155}

.kpis{display:grid;grid-template-columns:repeat(8,1fr);gap:12px}
.kpi{position:relative;text-align:left;border:1px solid #eef0f3;background:#fff;border-radius:16px;padding:12px 14px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px rgba(16,24,40,.05);transition:transform .15s,box-shadow .15s}
.kpi:hover{transform:translateY(-3px);box-shadow:0 14px 30px rgba(16,24,40,.1)}
.kpi.on{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.kpi-l{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#8a93a3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kpi-v{font-size:22px;font-weight:800;color:#0f172a;margin-top:4px}
.kpi-v small{font-size:12px;color:#94a3b8;font-weight:600}
.kpi-f{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-top:4px;min-height:22px}
.delta{font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 8px;display:inline-flex;gap:4px;align-items:center;white-space:nowrap}
.delta.good{background:#dcfce7;color:#15803d}.delta.bad{background:#ffe4e6;color:#be123c}.delta.flat{background:#f1f5f9;color:#64748b}
.spark{width:56px;height:22px}
.kpi-tip{position:absolute;left:10px;right:10px;top:calc(100% + 6px);background:#0f172a;color:#fff;font-size:11.5px;border-radius:10px;padding:8px 10px;opacity:0;pointer-events:none;transform:translateY(-4px);transition:all .15s;z-index:20}
.kpi:hover .kpi-tip{opacity:1;transform:none}

.chart-wrap{position:relative;height:220px;margin-top:10px}
.wd-row{display:grid;grid-template-columns:36px 1fr 48px;gap:8px;align-items:center;padding:4px 6px;border-radius:8px;font-size:12.5px;transition:background .12s}
.wd-row:hover{background:#f8fafc}
.wd-bars{display:flex;flex-direction:column;gap:3px}
.wd-bar,.g-bar{height:7px;background:#f1f5f9;border-radius:99px;overflow:hidden}
.wd-bar div,.g-bar div{height:100%;border-radius:99px;transition:width .5s}
.wd-v{text-align:right;font-weight:700;line-height:1.1}.wd-v small{color:#94a3b8;font-weight:600}
.b-rate{background:#2563eb}.b-punct{background:#16a34a}.b-bad{background:#e11d48}
.lg{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:4px;vertical-align:-1px}
.tiny{font-size:11px}

.acts{display:flex;flex-direction:column;gap:10px;max-height:420px;overflow:auto;padding-right:4px}
.act{display:flex;gap:12px;align-items:flex-start;border:1px solid #eef0f3;border-left-width:4px;border-radius:14px;padding:12px 14px;background:#fff;transition:transform .15s,box-shadow .15s,opacity .2s}
.act:hover{transform:translateX(3px);box-shadow:0 8px 20px rgba(16,24,40,.08)}
.act.done{opacity:.55}.act.done .act-title{text-decoration:line-through}
.sev-b-critical{border-left-color:#e11d48}.sev-b-high{border-left-color:#f97316}.sev-b-medium{border-left-color:#facc15}.sev-b-low{border-left-color:#cbd5e1}
.act-check{flex-shrink:0;width:26px;height:26px;border-radius:8px;border:2px solid #cbd5e1;background:#fff;color:transparent;display:grid;place-items:center;font-size:12px;transition:all .15s}
.act-check:hover{border-color:#16a34a;color:#16a34a}
.act.done .act-check{background:#16a34a;border-color:#16a34a;color:#fff}
.act-body{flex:1;min-width:0}
.act-top{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:11.5px}
.sev-dot{font-size:10px;font-weight:800;text-transform:uppercase;padding:2px 7px;border-radius:6px}
.act-cat{color:#64748b;font-weight:600}
.act-owner{margin-left:auto;background:#f1f5f9;color:#64748b;font-weight:600;padding:2px 8px;border-radius:999px;display:inline-flex;gap:5px;align-items:center}
.act-title{font-weight:800;color:#0f172a;margin-top:4px;font-size:14px}
.act-why{font-size:12.5px;color:#475569;margin-top:3px}
.act-do{font-size:13px;color:#1d4ed8;font-weight:600;margin-top:5px;display:flex;gap:7px;align-items:baseline}
.act-enter-from,.act-leave-to{opacity:0;transform:translateY(-6px)}.act-enter-active,.act-leave-active{transition:all .2s}

.grp{display:flex;align-items:center;gap:12px;padding:8px 10px;border-radius:12px;cursor:pointer;transition:background .15s;margin:0 -10px}
.grp:hover,.grp.on{background:#f1f5ff}
.off-badge{background:#2563eb;color:#fff;font-weight:800;font-size:12px;border-radius:8px;padding:4px 8px;min-width:44px;text-align:center}
.g-p{font-weight:800;font-size:13px;text-align:right;line-height:1}.g-p small{display:block;font-size:10px;color:#94a3b8;font-weight:600}
.g-bar{margin-top:4px}.g-bar.sm{height:6px;margin:0}
.role-h{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin:14px 0 6px}
.role{display:grid;grid-template-columns:140px 1fr 44px;gap:10px;align-items:center;font-size:12.5px;padding:3px 0;text-transform:capitalize}
.role b{text-align:right}

.people{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.person{display:flex;gap:12px;align-items:center;border:1px solid #eef0f3;border-radius:14px;padding:10px 12px;cursor:pointer;transition:transform .15s,box-shadow .15s,border-color .15s}
.person:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(16,24,40,.08);border-color:#c7d7fe}
.avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;font-weight:800;display:grid;place-items:center;flex-shrink:0;font-size:14px}
.min0{min-width:0}
.p-name{font-weight:800;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.flag{display:inline-block;font-size:11px;font-weight:700;border-radius:6px;padding:1px 7px;margin:2px 4px 0 0}
.f-late{background:#fef3c7;color:#b45309}.f-absent{background:#ffe4e6;color:#be123c}.f-no_out,.f-no_in{background:#e0e7ff;color:#4338ca}
.f-long{background:#fce7f3;color:#be185d}.f-leave{background:#e0f2fe;color:#0369a1}
.dq{display:flex;gap:14px;align-items:flex-start;background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:14px;padding:12px 16px;color:#92400e}
.dq i{font-size:18px;margin-top:2px}
.fold-enter-from,.fold-leave-to{opacity:0;transform:translateY(-8px)}.fold-enter-active,.fold-leave-active{transition:all .25s}

@media (max-width:1399.98px){ .kpis{grid-template-columns:repeat(4,1fr)} }
@media (max-width:991.98px){ .brief-cols{grid-template-columns:1fr} .people{grid-template-columns:minmax(0,1fr)} }
@media (max-width:767.98px){ .kpis{grid-template-columns:repeat(2,1fr);gap:10px} .brief{padding:16px} .role{grid-template-columns:110px 1fr 40px} }
</style>
