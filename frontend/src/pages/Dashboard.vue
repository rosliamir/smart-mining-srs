<template>
  <div class="page-wrap dash">
    <!-- ── HEADER ─────────────────────────────────────────────────────── -->
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
      <div>
        <h1 class="page-h1">Operation Sites</h1>
        <p class="page-sub">Decision intelligence: what happened, why it happened, and what to do next
          <span v-if="ins" class="badge-live ms-2"><span class="dot"></span>Live · PostgreSQL</span></p>
      </div>
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <div class="seg seg-sm">
          <button v-for="r in REGIONS" :key="r.k" :class="{ on: region === r.k }" @click="region = r.k">{{ r.l }}</button>
        </div>
        <div class="seg seg-sm period">
          <button v-for="p in periods" :key="p.label" :class="{ on: p.month === month && p.year === year }" @click="setPeriod(p)">{{ p.label.split(' ')[0] }}</button>
        </div>
      </div>
    </div>

    <div v-if="loading && !ins" class="text-center py-5 text-muted2"><span class="spinner-border spinner-border-sm me-2"></span>Analysing operations…</div>

    <template v-if="ins">
      <!-- ── BRIEFING: what / why / do ───────────────────────────────── -->
      <div class="brief mt-3" :class="`tone-${ins.summary.tone}`">
        <div class="brief-head">
          <span class="brief-ic"><i class="fa-solid fa-brain"></i></span>
          <div>
            <div class="brief-t">Decision briefing · {{ ins.period.label }}<span v-if="ins.period.partial" class="ms-1 brief-tag">month to date</span></div>
            <div class="brief-s">Compared with {{ ins.period.prev_label }}{{ region !== 'all' ? ` · ${region}` : '' }}</div>
          </div>
          <div class="ms-auto sev-pills">
            <span v-for="s in SEV" :key="s.k" v-show="ins.summary.counts[s.k]" class="sev-pill" :class="`sev-${s.k}`"
                  @click="scrollTo('actions'); actFilter = s.k" :title="`Show ${s.l.toLowerCase()} actions`">
              {{ ins.summary.counts[s.k] }} {{ s.l }}</span>
          </div>
        </div>
        <div class="brief-cols">
          <div class="bcol">
            <div class="bcol-h"><i class="fa-solid fa-chart-column"></i>What happened</div>
            <ul><li v-for="(t, i) in ins.summary.what" :key="i">{{ t }}</li></ul>
          </div>
          <div class="bcol">
            <div class="bcol-h"><i class="fa-solid fa-magnifying-glass-chart"></i>Why</div>
            <ul><li v-for="(t, i) in ins.summary.why" :key="i">{{ t }}</li></ul>
          </div>
          <div class="bcol">
            <div class="bcol-h"><i class="fa-solid fa-bolt"></i>What to do</div>
            <ul><li v-for="(t, i) in ins.summary.do" :key="i">{{ t }}</li></ul>
            <button class="btn btn-sm btn-dark mt-1" @click="scrollTo('actions')">Open action center <i class="fa-solid fa-arrow-right ms-1"></i></button>
          </div>
        </div>
      </div>

      <!-- ── KPI STRIP ───────────────────────────────────────────────── -->
      <div class="kpis mt-3">
        <button v-for="k in ins.kpis" :key="k.key" class="kpi" :class="{ on: metric === k.key, clickable: k.spark.length }"
                @click="k.spark.length && (metric = k.key)" :title="k.hint">
          <div class="kpi-l">{{ k.label }}</div>
          <div class="kpi-v">{{ fmtNum(k.value, k.unit) }}<small>{{ unitLabel(k.unit) }}</small></div>
          <div class="kpi-f">
            <span v-if="k.delta_pct !== null" class="delta" :class="deltaClass(k)">
              <i class="fa-solid" :class="k.delta_pct >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'"></i>{{ Math.abs(k.delta_pct) }}%</span>
            <span v-else-if="k.key === 'expired'" class="delta bad" @click.stop="$router.push('/map')">View on map</span>
            <svg v-if="k.spark.length > 1" class="spark" viewBox="0 0 80 24" preserveAspectRatio="none">
              <polyline :points="sparkPts(k.spark)" fill="none" :stroke="deltaClass(k) === 'good' ? '#16a34a' : deltaClass(k) === 'bad' ? '#e11d48' : '#64748b'" stroke-width="2" stroke-linejoin="round" />
            </svg>
          </div>
          <div class="kpi-tip">{{ k.hint }}<span v-if="k.prev !== null"><br>Previous: {{ fmtNum(k.prev, k.unit) }} {{ unitLabel(k.unit) }}</span></div>
        </button>
      </div>

      <!-- ── TREND + SALES BRIDGE ────────────────────────────────────── -->
      <div class="row g-3 mt-1">
        <div class="col-xl-5">
          <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-start">
              <div><h5 class="fw-bold mb-0">{{ metricLabel }} trend</h5>
                <p class="text-muted2 small mb-0">Click a month to analyse it · click a KPI above to change metric</p></div>
              <span v-if="ins.anomalies.length && metric === 'sales'" class="pill pill-amber" :title="`${ins.anomalies.length} implausible entries excluded`">
                <i class="fa-solid fa-filter me-1"></i>Cleaned</span>
            </div>
            <div class="chart-wrap"><canvas ref="trendEl"></canvas></div>
            <div v-if="ins.forecast" class="forecast">
              <div class="d-flex justify-content-between small"><b>Month-end projection</b>
                <span>{{ fmtNum(ins.forecast.projected, 'MT') }} MT <span class="text-muted2">vs {{ fmtNum(ins.forecast.prev_month_total, 'MT') }} last month</span></span></div>
              <div class="fc-bar"><div class="fc-mtd" :style="{ width: fcPct(ins.forecast.mtd) + '%' }"></div>
                <div class="fc-proj" :style="{ width: fcPct(ins.forecast.projected) + '%' }"></div>
                <div class="fc-prev" :style="{ left: fcPct(ins.forecast.prev_month_total) + '%' }" title="Last month total"></div></div>
              <div class="tiny text-muted2 mt-1">Day {{ ins.forecast.days_elapsed }} of {{ ins.forecast.days_in_month }} · solid = actual, light = projected at current run-rate, line = last month</div>
            </div>
          </div>
        </div>
        <div class="col-xl-7">
          <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-start">
              <div><h5 class="fw-bold mb-0">Why did sales change? <span class="text-muted2 fw-normal">sales bridge</span></h5>
                <p class="text-muted2 small mb-0">{{ ins.period.prev_label }} → {{ ins.period.label }} · biggest site contributors · click a bar for the root cause</p></div>
            </div>
            <div class="chart-wrap tall"><canvas ref="bridgeEl"></canvas></div>
          </div>
        </div>
      </div>

      <!-- ── ACTION CENTER + SIDE ────────────────────────────────────── -->
      <div class="row g-3 mt-1" id="actions">
        <div class="col-xl-8">
          <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
              <div><h5 class="fw-bold mb-0"><i class="fa-solid fa-list-check me-2 text-primary"></i>Action center</h5>
                <p class="text-muted2 small mb-0">Prioritised recommendations generated from F001 data, permits and workflow</p></div>
              <div class="seg seg-xs">
                <button :class="{ on: actFilter === 'all' }" @click="actFilter = 'all'">All {{ openActions.length }}</button>
                <button v-for="s in SEV" :key="s.k" v-show="ins.summary.counts[s.k] || s.k === 'critical'" :class="{ on: actFilter === s.k }" @click="actFilter = s.k">{{ s.l }}</button>
                <button :class="{ on: actFilter === 'done' }" @click="actFilter = 'done'"><i class="fa-solid fa-check"></i> {{ doneCount }}</button>
              </div>
            </div>
            <transition-group name="act" tag="div" class="acts mt-3">
              <div v-for="a in shownActions" :key="a.id" class="act" :class="[`sev-b-${a.sev}`, { done: done[a.id] }]">
                <button class="act-check" @click="toggleDone(a)" :title="done[a.id] ? 'Mark as open' : 'Mark as done'"><i class="fa-solid fa-check"></i></button>
                <div class="act-body" @click="a.site ? openSite(a.site) : (a.link && $router.push(a.link))">
                  <div class="act-top"><span class="sev-dot" :class="`sev-${a.sev}`">{{ a.sev }}</span><span class="act-cat"><i :class="CAT_ICON[a.cat] || 'fa-solid fa-circle-info'"></i>{{ a.cat }}</span>
                    <span class="act-owner"><i class="fa-solid fa-user-tie"></i>{{ a.owner }}</span></div>
                  <div class="act-title">{{ a.title }}</div>
                  <div class="act-why"><b>Why:</b> {{ a.why }}</div>
                  <div class="act-do"><i class="fa-solid fa-arrow-right"></i>{{ a.action }}</div>
                  <div v-if="a.impact" class="act-impact"><i class="fa-solid fa-scale-unbalanced"></i>{{ a.impact }}</div>
                </div>
                <i v-if="a.site || a.link" class="fa-solid fa-chevron-right act-go"></i>
              </div>
            </transition-group>
            <div v-if="!shownActions.length" class="text-center text-muted2 py-4"><i class="fa-regular fa-circle-check fa-2x d-block mb-2 text-success"></i>Nothing here — good job.</div>
          </div>
        </div>
        <div class="col-xl-4 d-flex flex-column gap-3">
          <div class="card p-4">
            <h5 class="fw-bold mb-0">Region performance</h5>
            <p class="text-muted2 small mb-2">Click a segment to focus the dashboard on that region</p>
            <div class="dough-wrap"><canvas ref="doughEl"></canvas></div>
          </div>
          <div class="card p-4">
            <h5 class="fw-bold mb-1">F001 approval pipeline</h5>
            <p class="text-muted2 small mb-3">Reports stuck &gt; 7 days are a bottleneck</p>
            <div v-for="st in PIPE" :key="st.k" class="pipe" @click="$router.push('/reports')">
              <div class="d-flex justify-content-between small"><span><i :class="st.icon" class="me-2" :style="{ color: st.c }"></i>{{ st.l }}</span>
                <span><b>{{ ins.backlog[st.k].count }}</b><span v-if="ins.backlog[st.k].overdue" class="text-danger ms-1">({{ ins.backlog[st.k].overdue }} overdue)</span></span></div>
              <div class="pipe-bar"><div :style="{ width: pipePct(ins.backlog[st.k].count) + '%', background: st.c }"></div>
                <div class="pipe-od" :style="{ width: pipePct(ins.backlog[st.k].overdue) + '%' }"></div></div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── DAILY DRIVERS ───────────────────────────────────────────── -->
      <div class="card p-4 mt-3">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div><h5 class="fw-bold mb-0">Daily drivers <span class="text-muted2 fw-normal">{{ ins.period.label }}{{ siteFilter ? ` · ${siteFilter.code}` : '' }}</span></h5>
            <p class="text-muted2 small mb-0">Sales vs operating hours, rain and absenteeism — red bars = e-Docket down that day. Click a day for detail.</p></div>
          <select v-model="siteId" class="form-select form-select-sm site-sel">
            <option :value="null">All sites</option>
            <option v-for="h in sortedBySales" :key="h.id" :value="h.id">{{ h.code }} · {{ fmtNum(h.sales, 'MT') }} MT</option>
          </select>
        </div>
        <div class="chart-wrap tall"><canvas ref="dailyEl"></canvas></div>
        <transition name="fade">
          <div v-if="day" class="day-card">
            <b>{{ fmtDay(day.date) }}</b>
            <span><i class="fa-solid fa-truck"></i>{{ fmtNum(day.sales, 'MT') }} MT · {{ day.trips }} trips</span>
            <span><i class="fa-solid fa-clock"></i>{{ day.op_h }} h operating (avg/site)</span>
            <span><i class="fa-solid fa-cloud-rain"></i>{{ day.rain }} rain slots</span>
            <span><i class="fa-solid fa-user-xmark"></i>{{ day.absent }} absent / {{ day.present + day.absent }}</span>
            <span v-if="day.edocket_down || day.cctv_down" class="text-danger"><i class="fa-solid fa-plug-circle-xmark"></i>{{ day.edocket_down }} e-Docket, {{ day.cctv_down }} CCTV down</span>
            <span class="text-muted2">{{ day.reports }} report(s)</span>
            <button class="x-btn ms-auto" @click="day = null"><i class="fa-solid fa-xmark"></i></button>
          </div>
        </transition>
      </div>

      <!-- ── SITE HEALTH ─────────────────────────────────────────────── -->
      <div class="card p-4 mt-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div><h5 class="fw-bold mb-0">Site health score</h5>
            <p class="text-muted2 small mb-0">100 = healthy. Points are deducted for permit, sales drop, system downtime, environment and manpower issues. Click a site for the diagnosis.</p></div>
          <div class="d-flex gap-2 align-items-center flex-wrap mw-100">
            <div class="seg seg-xs">
              <button v-for="g in GRADES" :key="g.k" :class="{ on: grade === g.k }" @click="grade = g.k">{{ g.l }} {{ gradeCount(g.k) }}</button>
            </div>
            <input v-model="hq" class="form-control form-control-sm" placeholder="Search site…" style="width:160px" />
          </div>
        </div>
        <div class="table-responsive mt-2">
          <table class="table table-hover health">
            <thead><tr>
              <th v-for="c in COLS" :key="c.k" @click="sortBy(c.k)" :class="c.cls">{{ c.l }}
                <i v-if="sort.k === c.k" class="fa-solid" :class="sort.dir > 0 ? 'fa-caret-up' : 'fa-caret-down'"></i></th>
              <th>Issues</th></tr></thead>
            <tbody>
              <tr v-for="h in (showAll ? healthRows : healthRows.slice(0, 10))" :key="h.id" @click="openSite(h.id)">
                <td><div class="d-flex align-items-center gap-2"><span class="score" :class="`g-${h.grade}`">{{ h.score }}</span>
                  <div class="score-bar"><div :class="`g-${h.grade}`" :style="{ width: h.score + '%' }"></div></div></div></td>
                <td><b>{{ h.code }}</b><div class="tiny text-muted2 nm">{{ h.name }}</div></td>
                <td class="text-end">{{ fmtNum(h.sales, 'MT') }}</td>
                <td class="text-end"><span v-if="h.delta_pct !== null" :class="h.delta_pct < 0 ? 'text-danger' : 'text-success'">{{ h.delta_pct > 0 ? '+' : '' }}{{ h.delta_pct }}%</span><span v-else class="text-muted2">—</span></td>
                <td class="text-end d-none d-md-table-cell">{{ h.op_h_day ?? '—' }}</td>
                <td class="d-none d-lg-table-cell"><span class="pill" :class="`pill-${h.permit}`">{{ h.days_left == null ? 'No date' : h.days_left < 0 ? 'Expired' : h.days_left + 'd' }}</span></td>
                <td><span v-for="i in h.issues" :key="i" class="issue">{{ i }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <button v-if="healthRows.length > 10" class="btn btn-light btn-sm w-100" @click="showAll = !showAll">
          {{ showAll ? 'Show top 10 only' : `Show all ${healthRows.length} sites` }} <i class="fa-solid ms-1" :class="showAll ? 'fa-chevron-up' : 'fa-chevron-down'"></i></button>
      </div>

      <!-- ── SITES CATEGORY + LIVE STREAMING (kept) ──────────────────── -->
      <div class="row g-3 mt-1">
        <div class="col-lg-4">
          <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="fw-bold mb-0">Sites category</h5>
              <span class="pill pill-green">{{ ins.health.length }} reporting</span></div>
            <div class="row g-3 text-center">
              <div v-for="c in categories" :key="c.k" class="col-4"><div class="cat"><div class="tile-label">{{ c.k }}</div><div class="cat-n">{{ c.v }}</div></div></div>
            </div>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
              <h5 class="fw-bold mb-0"><i class="fa-solid fa-video text-danger me-2"></i>Live streaming</h5>
              <div class="seg seg-xs"><button v-for="d in droneList" :key="d" :class="{ on: drones.includes(d) }" @click="toggleDrone(d)">{{ d }}</button></div>
            </div>
            <div class="feed-grid mt-3" :style="{ gridTemplateColumns: `repeat(${Math.min(drones.length || 1, 4)},1fr)` }">
              <div v-for="d in (drones.length ? drones : ['Drone 1'])" :key="d" class="feed">
                <div class="feed-label"><span class="rec"></span>{{ d }}</div>
                <div class="text-center"><i class="fa-solid fa-video" style="font-size:22px"></i><div class="small mt-2">Live feed will start when connected</div>
                  <button class="btn btn-primary btn-sm mt-2"><i class="fa-solid fa-play me-1"></i>Start</button></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ── SITE DIAGNOSIS DRAWER ────────────────────────────────────── -->
    <transition name="drawer">
      <div v-if="site" class="drawer-bg" @click.self="site = null">
        <div class="drawer">
          <div class="dr-head">
            <div>
              <span class="score big" :class="`g-${site.h?.grade || 'watch'}`">{{ site.h?.score ?? '—' }}</span>
              <h5 class="fw-bold mb-0 mt-2">{{ site.code }}</h5>
              <div class="small text-muted2">{{ site.name }}</div>
              <div class="small text-muted2">{{ site.h?.region }} · {{ site.h?.contractor || '—' }}</div>
            </div>
            <button class="x-btn" @click="site = null"><i class="fa-solid fa-xmark"></i></button>
          </div>
          <div class="dr-body">
            <div class="dstats">
              <div><small>Sales</small><b>{{ fmtNum(site.h?.sales, 'MT') }}</b><span v-if="site.h?.delta_pct != null" :class="site.h.delta_pct < 0 ? 'text-danger' : 'text-success'">{{ site.h.delta_pct > 0 ? '+' : '' }}{{ site.h.delta_pct }}%</span></div>
              <div><small>Trips</small><b>{{ fmtNum(site.h?.trips) }}</b></div>
              <div><small>Op. h/day</small><b>{{ site.h?.op_h_day ?? '—' }}</b></div>
              <div><small>Permit</small><b :class="site.h?.permit === 'expired' ? 'text-danger' : ''">{{ site.h?.days_left == null ? '—' : site.h.days_left < 0 ? 'Expired' : site.h.days_left + 'd' }}</b></div>
            </div>
            <div class="sec-h"><i class="fa-solid fa-magnifying-glass-chart"></i>Why {{ site.d && site.d.delta < 0 ? 'sales fell' : 'sales changed' }}</div>
            <div v-if="site.d" class="factors">
              <div class="f-delta" :class="site.d.delta < 0 ? 'neg' : 'pos'">{{ site.d.delta < 0 ? '−' : '+' }}{{ fmtNum(Math.abs(site.d.delta), 'MT') }} MT vs {{ ins.period.prev_label }}</div>
              <div v-for="f in site.d.factors" :key="f.text" class="factor"><i class="fa-solid" :class="FACTOR_ICON[f.key] || 'fa-circle-info'"></i>{{ f.text }}</div>
            </div>
            <div v-else class="small text-muted2">No significant change in this period.</div>
            <div class="sec-h"><i class="fa-solid fa-bolt"></i>Recommended actions</div>
            <div v-for="a in site.acts" :key="a.id" class="mini-act" :class="`sev-b-${a.sev}`"><span class="sev-dot" :class="`sev-${a.sev}`">{{ a.sev }}</span><b>{{ a.action }}</b><div class="small text-muted2">{{ a.owner }}</div></div>
            <div v-if="!site.acts.length" class="small text-muted2">No open actions for this site.</div>
            <div class="sec-h"><i class="fa-solid fa-chart-column"></i>Daily in {{ ins.period.label }}</div>
            <div class="chart-wrap sm"><canvas ref="siteEl"></canvas></div>
            <div class="d-flex gap-2 mt-3">
              <router-link class="btn btn-light flex-fill" :to="`/map`"><i class="fa-solid fa-map me-1"></i>Map</router-link>
              <router-link class="btn btn-primary flex-fill" :to="`/site/${site.id}`"><i class="fa-solid fa-circle-info me-1"></i>Site details</router-link>
            </div>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { apiGet } from '../services/api'
import Chart from 'chart.js/auto'

const REGIONS = [{ k: 'all', l: 'All regions' }, { k: 'Northern Region', l: 'Northern' }, { k: 'Southern Region', l: 'Southern' }]
const SEV = [{ k: 'critical', l: 'Critical' }, { k: 'high', l: 'High' }, { k: 'medium', l: 'Medium' }, { k: 'low', l: 'Low' }]
const CAT_ICON = { Permit: 'fa-solid fa-file-signature', Sales: 'fa-solid fa-chart-line', Systems: 'fa-solid fa-plug-circle-xmark', Environment: 'fa-solid fa-leaf',
  Data: 'fa-solid fa-database', 'Data quality': 'fa-solid fa-triangle-exclamation', Workflow: 'fa-solid fa-diagram-project', Manpower: 'fa-solid fa-people-group' }
const FACTOR_ICON = { reports: 'fa-file-circle-xmark', op_h: 'fa-clock', rain: 'fa-cloud-rain', manpower: 'fa-user-xmark', edocket: 'fa-receipt',
  cctv: 'fa-video-slash', trips: 'fa-truck', demand: 'fa-tags', new: 'fa-seedling' }
const PIPE = [
  { k: 'draft', l: 'Draft (site officer)', icon: 'fa-solid fa-pen', c: '#94a3b8' },
  { k: 'pending_review', l: 'Pending review (executive)', icon: 'fa-solid fa-magnifying-glass', c: '#f59e0b' },
  { k: 'pending_approval', l: 'Pending approval (manager)', icon: 'fa-solid fa-stamp', c: '#6366f1' },
  { k: 'returned', l: 'Returned for rework', icon: 'fa-solid fa-rotate-left', c: '#e11d48' },
]
const GRADES = [{ k: 'all', l: 'All' }, { k: 'risk', l: 'At risk' }, { k: 'watch', l: 'Watch' }, { k: 'good', l: 'Good' }]
const COLS = [{ k: 'score', l: 'Score' }, { k: 'code', l: 'Site' }, { k: 'sales', l: 'Sales MT', cls: 'text-end' }, { k: 'delta_pct', l: 'Δ', cls: 'text-end' },
  { k: 'op_h_day', l: 'Op h/day', cls: 'text-end d-none d-md-table-cell' }, { k: 'days_left', l: 'Permit', cls: 'd-none d-lg-table-cell' }]
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

const ins = ref(null), loading = ref(false)
const year = ref(null), month = ref(null), region = ref('all'), siteId = ref(null)
const metric = ref('sales'), actFilter = ref('all'), grade = ref('all'), hq = ref(''), sort = reactive({ k: 'score', dir: 1 })
const day = ref(null), site = ref(null), dailyRows = ref([]), showAll = ref(false)
const done = reactive((() => { try { return JSON.parse(localStorage.getItem('sm_done_actions') || '{}') } catch { return {} } })())
const categories = [{ k: 'Tanah Kerajaan', v: 1 }, { k: 'GLC', v: 6 }, { k: 'Private Mines', v: 1 }, { k: 'River', v: 11 }, { k: 'Stockpiles (Sand)', v: 22 }, { k: 'Clay', v: 1 }, { k: 'Quarry', v: 3 }, { k: 'KSMM', v: 8 }]
const droneList = ['Drone 1', 'Drone 2', 'Drone 3', 'Drone 4'], drones = ref(['Drone 1'])
const trendEl = ref(null), bridgeEl = ref(null), doughEl = ref(null), dailyEl = ref(null), siteEl = ref(null)
let trendChart, bridgeChart, doughChart, dailyChart, siteChart

/* ── computed ─────────────────────────────────────────────────────────── */
const periods = computed(() => ins.value?.period.available || [])
const metricLabel = computed(() => ins.value?.kpis.find(k => k.key === metric.value)?.label || 'Sales')
const openActions = computed(() => (ins.value?.actions || []).filter(a => !done[a.id]))
const doneCount = computed(() => (ins.value?.actions || []).filter(a => done[a.id]).length)
const shownActions = computed(() => {
  const all = ins.value?.actions || []
  if (actFilter.value === 'done') return all.filter(a => done[a.id])
  return openActions.value.filter(a => actFilter.value === 'all' || a.sev === actFilter.value)
})
const siteFilter = computed(() => ins.value?.health.find(h => h.id === siteId.value))
const sortedBySales = computed(() => [...(ins.value?.health || [])].sort((a, b) => b.sales - a.sales))
const gradeCount = g => (ins.value?.health || []).filter(h => g === 'all' || h.grade === g).length
const healthRows = computed(() => {
  const t = hq.value.toLowerCase()
  return (ins.value?.health || [])
    .filter(h => (grade.value === 'all' || h.grade === grade.value) && (!t || `${h.code} ${h.name}`.toLowerCase().includes(t)))
    .sort((a, b) => {
      const x = a[sort.k], y = b[sort.k]
      if (x == null) return 1; if (y == null) return -1
      return (typeof x === 'string' ? x.localeCompare(y) : x - y) * sort.dir
    })
})

/* ── formatting ───────────────────────────────────────────────────────── */
function fmtNum(v, unit) {
  if (v == null) return '—'
  if (unit === '%') return v.toFixed(1)
  if (Math.abs(v) >= 1e6) return (v / 1e6).toFixed(2) + 'M'
  if (Math.abs(v) >= 1e4) return Math.round(v / 1e3).toLocaleString() + 'K'
  return Math.round(v).toLocaleString()
}
const unitLabel = u => u === '%' ? '%' : u === 'h' ? ' h' : u === 'MT' ? ' MT' : ''
function deltaClass(k) {
  if (k.delta_pct == null || Math.abs(k.delta_pct) < 1) return 'flat'
  return (k.delta_pct > 0) === (k.good === 'up') ? 'good' : 'bad'
}
function sparkPts(a) {
  const mn = Math.min(...a), mx = Math.max(...a), r = mx - mn || 1
  return a.map((v, i) => `${(i / (a.length - 1)) * 80},${22 - ((v - mn) / r) * 20}`).join(' ')
}
const fmtDay = d => new Date(d).toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short' })
const fcPct = v => { const f = ins.value.forecast, mx = Math.max(f.projected, f.prev_month_total) * 1.08; return Math.min(100, v / mx * 100) }
const pipePct = v => { const b = ins.value.backlog, mx = Math.max(...Object.values(b).map(x => x.count), 1); return v / mx * 100 }

/* ── actions ──────────────────────────────────────────────────────────── */
function setPeriod(p) { year.value = p.year; month.value = p.month }
function toggleDone(a) {
  if (done[a.id]) delete done[a.id]; else done[a.id] = Date.now()
  try { localStorage.setItem('sm_done_actions', JSON.stringify(done)) } catch {}
}
function sortBy(k) { if (sort.k === k) sort.dir *= -1; else { sort.k = k; sort.dir = k === 'score' || k === 'code' ? 1 : -1 } }
function scrollTo(id) { document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }
function toggleDrone(d) { const i = drones.value.indexOf(d); if (i >= 0) drones.value.splice(i, 1); else drones.value.push(d) }

async function openSite(id) {
  const h = ins.value.health.find(x => x.id === id), d = ins.value.drivers.find(x => x.site_id === id)
  const code = h?.code || d?.code || ''
  // site-specific actions + grouped actions that name this site (e.g. "operating on expired permit: SP97")
  const named = a => code && new RegExp(`(^|[^\\w/])${code.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}([^\\w/]|$)`).test(`${a.title} ${a.why}`)
  const acts = ins.value.actions.filter(a => !done[a.id] && (a.site === id || named(a)))
  site.value = { id, code, name: h?.name || d?.name, h, d, acts }
  const r = await apiGet(`insights?${qs({ site: id })}`)
  if (!site.value || site.value.id !== id) return
  await nextTick(); drawSite(r?.daily || [])
}

/* ── data ─────────────────────────────────────────────────────────────── */
function qs(extra = {}) {
  const p = new URLSearchParams()
  if (year.value) p.set('year', year.value); if (month.value) p.set('month', month.value)
  if (region.value !== 'all') p.set('region', region.value)
  Object.entries(extra).forEach(([k, v]) => v != null && p.set(k, v))
  return p.toString()
}
async function load() {
  loading.value = true
  const r = await apiGet(`insights?${qs({ site: siteId.value })}`)
  loading.value = false
  if (!r) return
  ins.value = r; year.value = r.period.year; month.value = r.period.month; dailyRows.value = r.daily; day.value = null
  await nextTick(); drawAll()
}
async function loadDaily() {
  const r = await apiGet(`insights?${qs({ site: siteId.value })}`)
  if (r) { dailyRows.value = r.daily; day.value = null; drawDaily() }
}

/* ── charts ───────────────────────────────────────────────────────────── */
const kfmt = v => Math.abs(v) >= 1e6 ? (v / 1e6).toFixed(1) + 'M' : Math.abs(v) >= 1e3 ? Math.round(v / 1e3) + 'K' : v
const baseOpts = { responsive: true, maintainAspectRatio: false, animation: { duration: 500 }, plugins: { legend: { display: false } } }
function drawAll() { drawTrend(); drawBridge(); drawDough(); drawDaily() }

function drawTrend() {
  if (!trendEl.value) return; trendChart?.destroy()
  const t = ins.value.trend.filter(x => x.report_days), k = metric.value
  const data = t.map(x => x[k] ?? 0)
  const colors = t.map(x => x.month === month.value ? '#2563eb' : '#c7d7fe')
  trendChart = new Chart(trendEl.value, {
    type: 'bar',
    data: { labels: t.map(x => x.label), datasets: [{ data, backgroundColor: colors, borderRadius: 8, hoverBackgroundColor: '#1d4ed8' }] },
    options: { ...baseOpts, onHover: (e, el) => e.native.target.style.cursor = el.length ? 'pointer' : 'default',
      onClick: (e, el) => { if (el.length) { const m = t[el[0].index]; setPeriod({ year: ins.value.period.year, month: m.month }) } },
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${metricLabel.value}: ${c.raw?.toLocaleString()}`, footer: () => 'Click to analyse this month' } } },
      scales: { y: { ticks: { callback: kfmt }, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } } },
  })
}

function drawBridge() {
  if (!bridgeEl.value) return; bridgeChart?.destroy()
  const k = ins.value.kpis.find(x => x.key === 'sales'), drivers = ins.value.drivers.slice(0, 7)
  const other = k.value - k.prev - drivers.reduce((s, d) => s + d.delta, 0)
  const steps = [...drivers.map(d => ({ label: d.code, v: d.delta, site: d.site_id })), ...(Math.abs(other) > 1 ? [{ label: 'Other sites', v: other }] : [])]
  const labels = ['Previous', ...steps.map(s => s.label), 'Current']
  let run = k.prev; const bars = [[0, k.prev]], colors = ['#94a3b8']
  steps.forEach(s => { bars.push([run, run + s.v]); colors.push(s.v < 0 ? '#f43f5e' : '#22c55e'); run += s.v })
  bars.push([0, k.value]); colors.push('#2563eb')
  bridgeChart = new Chart(bridgeEl.value, {
    type: 'bar',
    data: { labels, datasets: [{ data: bars, backgroundColor: colors, borderRadius: 6, borderSkipped: false }] },
    options: { ...baseOpts,
      onHover: (e, el) => e.native.target.style.cursor = el.length && steps[el[0].index - 1]?.site ? 'pointer' : 'default',
      onClick: (e, el) => { const s = el.length && steps[el[0].index - 1]; if (s?.site) openSite(s.site) },
      plugins: { legend: { display: false }, tooltip: { callbacks: {
        label: c => { const i = c.dataIndex; if (i === 0 || i === labels.length - 1) return `${fmtNum(c.raw[1], 'MT')} MT`
          const s = steps[i - 1]; return `${s.v < 0 ? '−' : '+'}${fmtNum(Math.abs(s.v), 'MT')} MT` },
        afterLabel: c => { const s = steps[c.dataIndex - 1]; const d = s && ins.value.drivers.find(x => x.site_id === s.site); return d ? d.factors.slice(0, 2).map(f => '• ' + f.text) : '' },
      } } },
      scales: { y: { ticks: { callback: kfmt }, grid: { color: '#f1f5f9' }, min: Math.max(0, Math.min(...bars.flat()) * 0.9) }, x: { grid: { display: false } } } },
  })
}

function drawDough() {
  if (!doughEl.value) return; doughChart?.destroy()
  // per-region share for the selected period comes from the health list (sales per site)
  const all = ins.value.health, by = {}
  all.forEach(h => { by[h.region || 'Unassigned'] = (by[h.region || 'Unassigned'] || 0) + h.sales })
  const labels = Object.keys(by), vals = Object.values(by), tot = vals.reduce((a, b) => a + b, 0) || 1
  const focus = region.value !== 'all' ? region.value : labels[vals.indexOf(Math.max(...vals))]
  const pct = Math.round((by[focus] || 0) / tot * 100)
  doughChart = new Chart(doughEl.value, {
    type: 'doughnut',
    data: { labels, datasets: [{ data: vals, backgroundColor: labels.map(l => l === 'Northern Region' ? '#2563eb' : l === 'Southern Region' ? '#22c55e' : '#cbd5e1'), hoverOffset: 10, borderWidth: 3, borderColor: '#fff' }] },
    options: { ...baseOpts, cutout: '70%', onHover: (e, el) => e.native.target.style.cursor = el.length ? 'pointer' : 'default',
      onClick: (e, el) => { if (el.length) { const r = labels[el[0].index]; region.value = region.value === r ? 'all' : r } },
      plugins: { legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
        tooltip: { callbacks: { label: c => `${c.label}: ${fmtNum(c.raw, 'MT')} MT (${(c.raw / tot * 100).toFixed(1)}%)` } } } },
    plugins: [{ id: 'center', afterDraw(ch) {
      const { ctx, chartArea: { left, right, top, bottom } } = ch; const x = (left + right) / 2, y = (top + bottom) / 2
      ctx.save(); ctx.textAlign = 'center'; ctx.fillStyle = '#111827'; ctx.font = '800 24px Inter'; ctx.fillText(pct + '%', x, y + 2)
      ctx.font = '600 11px Inter'; ctx.fillStyle = '#6b7280'; ctx.fillText((focus || '').replace(' Region', ''), x, y + 20); ctx.restore() } }],
  })
}

function drawDaily() {
  if (!dailyEl.value) return; dailyChart?.destroy()
  const d = dailyRows.value
  dailyChart = new Chart(dailyEl.value, {
    data: { labels: d.map(r => r.date.slice(8)),
      datasets: [
        { type: 'bar', label: 'Sales (MT)', data: d.map(r => r.sales), backgroundColor: d.map(r => r.edocket_down ? '#fda4af' : '#bfdbfe'), hoverBackgroundColor: '#2563eb', borderRadius: 5, yAxisID: 'y', order: 3 },
        { type: 'line', label: 'Operating h (avg)', data: d.map(r => r.op_h), borderColor: '#16a34a', backgroundColor: '#16a34a', tension: .35, pointRadius: 2, yAxisID: 'y2', order: 1 },
        { type: 'line', label: 'Rain slots (avg)', data: d.map(r => r.rain), borderColor: '#0ea5e9', backgroundColor: '#0ea5e9', borderDash: [5, 4], tension: .35, pointRadius: 0, yAxisID: 'y2', order: 2 },
        { type: 'line', label: 'Absent staff', data: d.map(r => r.absent), borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: .35, pointRadius: 0, yAxisID: 'y2', order: 2, hidden: true },
      ] },
    options: { ...baseOpts, interaction: { mode: 'index', intersect: false },
      onHover: (e, el) => e.native.target.style.cursor = el.length ? 'pointer' : 'default',
      onClick: (e, el) => { if (el.length) day.value = d[el[0].index] },
      plugins: { legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
        tooltip: { callbacks: { footer: items => d[items[0].dataIndex]?.edocket_down ? '⚠ e-Docket down this day' : '' } } },
      scales: { y: { ticks: { callback: kfmt }, grid: { color: '#f1f5f9' }, title: { display: true, text: 'MT' } },
        y2: { position: 'right', grid: { display: false }, title: { display: true, text: 'hours / slots / staff' } }, x: { grid: { display: false } } } },
  })
}

function drawSite(rows) {
  if (!siteEl.value) return; siteChart?.destroy()
  siteChart = new Chart(siteEl.value, {
    data: { labels: rows.map(r => r.date.slice(8)), datasets: [
      { type: 'bar', data: rows.map(r => r.sales), backgroundColor: rows.map(r => r.edocket_down ? '#fda4af' : '#93c5fd'), borderRadius: 4, yAxisID: 'y' },
      { type: 'line', data: rows.map(r => r.op_h), borderColor: '#16a34a', pointRadius: 0, tension: .35, yAxisID: 'y2' }] },
    options: { ...baseOpts, scales: { y: { ticks: { callback: kfmt } }, y2: { position: 'right', grid: { display: false } }, x: { grid: { display: false } } } },
  })
}

watch([year, month, region], (n, o) => { if (o[0] !== null || o[1] !== null) load() })
watch(siteId, loadDaily)
watch(metric, drawTrend)
const onKey = e => { if (e.key === 'Escape') site.value = null }
onMounted(() => { load(); document.addEventListener('keydown', onKey) })
onBeforeUnmount(() => { document.removeEventListener('keydown', onKey); [trendChart, bridgeChart, doughChart, dailyChart, siteChart].forEach(c => c?.destroy()) })
</script>

<style scoped>
.dash{--sh:0 1px 2px rgba(16,24,40,.04),0 8px 24px rgba(16,24,40,.05)}
.badge-live{display:inline-flex;align-items:center;gap:6px;background:#dcfce7;color:#15803d;font-weight:700;font-size:11px;padding:.15rem .55rem;border-radius:999px}
.badge-live .dot{width:7px;height:7px;border-radius:50%;background:#15803d;animation:pulse 1.4s infinite}
@keyframes pulse{50%{opacity:.3}}
.seg-sm button{padding:.4rem .9rem;font-size:13px}
.seg-xs{box-shadow:none;background:#f1f5f9;padding:3px}
.seg-xs button{padding:.3rem .7rem;font-size:12px}
.seg button{transition:all .15s}
.seg button:not(.on):hover{background:#eef4ff;color:#0d6efd}
.period{max-width:100%;overflow-x:auto}

/* briefing */
.brief{border-radius:20px;padding:20px 22px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:#e2e8f0;box-shadow:0 20px 40px rgba(30,58,138,.25);position:relative;overflow:hidden}
.brief::after{content:'';position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(96,165,250,.35),transparent 70%)}
.brief.tone-bad{background:linear-gradient(135deg,#1f1027,#7f1d1d 55%,#9a3412)}
.brief.tone-good{background:linear-gradient(135deg,#052e2b,#065f46)}
.brief-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.brief-ic{width:44px;height:44px;border-radius:14px;background:rgba(255,255,255,.12);display:grid;place-items:center;font-size:20px;color:#fff}
.brief-t{font-weight:800;font-size:18px;color:#fff}
.brief-tag{font-size:11px;font-weight:700;background:rgba(255,255,255,.15);padding:2px 8px;border-radius:999px;vertical-align:middle}
.brief-s{font-size:12.5px;opacity:.75}
.sev-pills{display:flex;gap:6px;flex-wrap:wrap}
.sev-pill{cursor:pointer;font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px;transition:transform .15s}
.sev-pill:hover{transform:translateY(-2px)}
.brief-cols{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:16px;position:relative;z-index:1}
.bcol{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:12px 14px;transition:background .2s}
.bcol:hover{background:rgba(255,255,255,.13)}
.bcol-h{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#93c5fd;margin-bottom:6px;display:flex;gap:8px;align-items:center}
.bcol ul{margin:0;padding-left:18px;font-size:13.5px;line-height:1.5}
.bcol li+li{margin-top:4px}

/* severity colours */
.sev-critical{background:#e11d48;color:#fff}.sev-high{background:#f97316;color:#fff}.sev-medium{background:#facc15;color:#422006}.sev-low{background:#cbd5e1;color:#334155}

/* KPI strip */
.kpis{display:grid;grid-template-columns:repeat(8,1fr);gap:12px}
.kpi{position:relative;text-align:left;border:1px solid #eef0f3;background:#fff;border-radius:16px;padding:14px 14px 12px;box-shadow:var(--sh);transition:transform .15s,box-shadow .15s,border-color .15s}
.kpi.clickable{cursor:pointer}
.kpi:hover{transform:translateY(-3px);box-shadow:0 14px 30px rgba(16,24,40,.1)}
.kpi.on{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15),var(--sh)}
.kpi-l{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#8a93a3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kpi-v{font-size:22px;font-weight:800;color:#0f172a;margin-top:4px;white-space:nowrap}
.kpi-v small{font-size:12px;font-weight:600;color:#94a3b8}
.kpi-f{display:flex;align-items:center;justify-content:space-between;margin-top:6px;gap:6px}
.delta{font-size:12px;font-weight:700;border-radius:999px;padding:2px 8px;display:inline-flex;gap:4px;align-items:center;white-space:nowrap}
.delta.good{background:#dcfce7;color:#15803d}.delta.bad{background:#ffe4e6;color:#be123c;cursor:pointer}.delta.flat{background:#f1f5f9;color:#64748b}
.spark{width:60px;height:22px}
.kpi-tip{position:absolute;left:10px;right:10px;top:calc(100% + 6px);background:#0f172a;color:#fff;font-size:11.5px;border-radius:10px;padding:8px 10px;opacity:0;pointer-events:none;transform:translateY(-4px);transition:all .15s;z-index:20}
.kpi:hover .kpi-tip{opacity:1;transform:none}

.chart-wrap{position:relative;height:250px;margin-top:12px}
.chart-wrap.tall{height:300px}
.chart-wrap.sm{height:170px}
.dough-wrap{position:relative;height:230px}
.forecast{margin-top:14px;background:#f8fafc;border-radius:12px;padding:10px 12px}
.fc-bar{position:relative;height:10px;background:#e2e8f0;border-radius:99px;margin-top:8px}
.fc-proj{position:absolute;left:0;top:0;bottom:0;background:#bfdbfe;border-radius:99px}
.fc-mtd{position:absolute;left:0;top:0;bottom:0;background:#2563eb;border-radius:99px;z-index:1}
.fc-prev{position:absolute;top:-4px;bottom:-4px;width:3px;background:#0f172a;border-radius:2px;z-index:2}
.tiny{font-size:11px}

/* action center */
.acts{display:flex;flex-direction:column;gap:10px;max-height:620px;overflow:auto;padding-right:4px}
.act{display:flex;gap:12px;align-items:flex-start;border:1px solid #eef0f3;border-left-width:4px;border-radius:14px;padding:12px 14px;background:#fff;transition:transform .15s,box-shadow .15s,opacity .2s}
.act:hover{transform:translateX(3px);box-shadow:0 8px 20px rgba(16,24,40,.08)}
.act.done{opacity:.55}.act.done .act-title{text-decoration:line-through}
.sev-b-critical{border-left-color:#e11d48}.sev-b-high{border-left-color:#f97316}.sev-b-medium{border-left-color:#facc15}.sev-b-low{border-left-color:#cbd5e1}
.act-check{flex-shrink:0;width:26px;height:26px;border-radius:8px;border:2px solid #cbd5e1;background:#fff;color:transparent;display:grid;place-items:center;font-size:12px;transition:all .15s;margin-top:2px}
.act-check:hover{border-color:#16a34a;color:#16a34a}
.act.done .act-check{background:#16a34a;border-color:#16a34a;color:#fff}
.act-body{flex:1;min-width:0;cursor:pointer}
.act-top{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:11.5px}
.sev-dot{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;padding:2px 7px;border-radius:6px}
.act-cat,.act-owner{color:#64748b;display:inline-flex;gap:5px;align-items:center;font-weight:600}
.act-owner{margin-left:auto;background:#f1f5f9;padding:2px 8px;border-radius:999px}
.act-title{font-weight:800;color:#0f172a;margin-top:4px;font-size:14.5px}
.act-why{font-size:12.5px;color:#475569;margin-top:3px}
.act-do{font-size:13px;color:#1d4ed8;font-weight:600;margin-top:5px;display:flex;gap:7px;align-items:baseline}
.act-impact{font-size:12px;color:#b45309;margin-top:3px;display:flex;gap:6px;align-items:center}
.act-go{color:#cbd5e1;align-self:center;transition:transform .15s,color .15s}
.act:hover .act-go{color:#2563eb;transform:translateX(3px)}
.act-enter-from,.act-leave-to{opacity:0;transform:translateY(-6px)}
.act-enter-active,.act-leave-active{transition:all .2s}

.pipe{cursor:pointer;padding:6px 0;border-radius:8px;transition:background .15s}
.pipe:hover{background:#f8fafc}
.pipe+.pipe{margin-top:6px}
.pipe-bar{position:relative;height:8px;background:#f1f5f9;border-radius:99px;margin-top:6px;overflow:hidden}
.pipe-bar>div:first-child{height:100%;border-radius:99px;opacity:.35}
.pipe-od{position:absolute;left:0;top:0;bottom:0;background:repeating-linear-gradient(45deg,#e11d48 0 4px,#fb7185 4px 8px);border-radius:99px}

.site-sel{width:230px}
.day-card{display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;background:#f8fafc;border-radius:12px;padding:10px 14px;margin-top:12px;font-size:13px}
.day-card i{margin-right:6px;color:#64748b}
.x-btn{width:30px;height:30px;border:0;border-radius:50%;background:#f1f5f9;color:#475569;display:grid;place-items:center;transition:all .15s;flex-shrink:0}
.x-btn:hover{background:#0f172a;color:#fff;transform:rotate(90deg)}

/* health table */
.health th{cursor:pointer;user-select:none;white-space:nowrap}
.health tbody tr{cursor:pointer;transition:background .12s}
.health td{font-size:13.5px}
.nm{max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.score{display:inline-grid;place-items:center;min-width:38px;height:26px;border-radius:8px;font-weight:800;font-size:13px;color:#fff}
.score.big{min-width:56px;height:40px;font-size:20px;border-radius:12px}
.g-good{background:#16a34a}.g-watch{background:#f59e0b}.g-risk{background:#e11d48}
.score-bar{width:70px;height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden}.score-bar div{height:100%}
.issue{display:inline-block;font-size:11px;background:#f1f5f9;color:#475569;border-radius:6px;padding:1px 7px;margin:1px 4px 1px 0;white-space:nowrap}
.pill-active{background:#dbeafe;color:#1d4ed8}.pill-warning{background:#fef3c7;color:#b45309}.pill-expired{background:#ffe4e6;color:#be123c}.pill-unknown{background:#f1f5f9;color:#64748b}

.cat{border-radius:12px;padding:8px 4px;transition:background .15s,transform .15s}
.cat:hover{background:#f8fafc;transform:translateY(-2px)}
.cat-n{font-size:26px;font-weight:800;color:#111827}
.feed-grid{display:grid;gap:12px}
.feed{background:#0b0f16;border-radius:14px;min-height:200px;position:relative;display:grid;place-items:center;color:#8b94a3;transition:transform .15s}
.feed:hover{transform:scale(1.01)}
.feed-label{position:absolute;top:10px;left:12px;color:#cbd5e1;font-size:12.5px;display:flex;align-items:center;gap:6px}
.rec{width:8px;height:8px;border-radius:50%;background:#ef4444;animation:pulse 1.4s infinite}

/* drawer */
.drawer-bg{position:fixed;inset:0;background:rgba(15,23,42,.35);z-index:2000;display:flex;justify-content:flex-end}
.drawer{width:440px;max-width:100%;height:100%;background:#fff;box-shadow:-20px 0 50px rgba(15,23,42,.25);display:flex;flex-direction:column}
.dr-head{display:flex;justify-content:space-between;align-items:flex-start;padding:20px 22px;border-bottom:1px solid #eef0f3}
.dr-body{padding:18px 22px;overflow:auto;flex:1}
.dstats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.dstats div{background:#f8fafc;border-radius:12px;padding:8px;display:flex;flex-direction:column;font-size:12px}
.dstats small{color:#94a3b8;font-weight:600}.dstats b{font-size:16px;color:#0f172a}
.sec-h{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin:18px 0 8px;display:flex;gap:8px;align-items:center}
.f-delta{font-weight:800;font-size:18px;margin-bottom:6px}.f-delta.neg{color:#e11d48}.f-delta.pos{color:#16a34a}
.factor{display:flex;gap:10px;align-items:baseline;font-size:13.5px;padding:7px 10px;border-radius:10px;background:#f8fafc;margin-bottom:6px}
.factor i{color:#2563eb;width:16px}
.mini-act{border-left-width:4px;border-left-style:solid;background:#f8fafc;border-radius:10px;padding:8px 12px;margin-bottom:6px;font-size:13px}
.mini-act .sev-dot{margin-right:8px}
.drawer-enter-from,.drawer-leave-to{opacity:0}.drawer-enter-from .drawer,.drawer-leave-to .drawer{transform:translateX(40px)}
.drawer-enter-active,.drawer-leave-active,.drawer-enter-active .drawer,.drawer-leave-active .drawer{transition:all .25s cubic-bezier(.2,.8,.2,1)}
.fade-enter-from,.fade-leave-to{opacity:0}.fade-enter-active,.fade-leave-active{transition:opacity .2s}

@media (max-width:1399.98px){ .kpis{grid-template-columns:repeat(4,1fr)} }
@media (max-width:991.98px){ .brief-cols{grid-template-columns:1fr} }
@media (max-width:767.98px){
  .page-wrap.dash{padding:16px 14px 40px}
  .kpis{grid-template-columns:repeat(2,1fr);gap:10px}
  .kpi-v{font-size:19px}
  .brief{padding:16px}
  .brief-t{font-size:16px}
  .sev-pills{margin-left:0!important}
  .site-sel{width:100%}
  .chart-wrap.tall{height:260px}
  .drawer{width:100%}
  .dstats{grid-template-columns:repeat(2,1fr)}
  .period,.seg-sm{max-width:100%}
}
</style>
