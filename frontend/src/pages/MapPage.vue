<template>
  <div class="map-wrap" :class="[`base-${basemap}`, hl ? `hl hl-${hl}` : '', { 'show-labels': showLabels }]">
    <div id="map" class="leaflet"></div>

    <!-- ── TOP BAR: search · region · status chips ───────────────────────── -->
    <div class="topbar">
      <div class="search-box" :class="{ open: searchOpen && suggestions.length }">
        <i class="fa-solid fa-magnifying-glass s-ic"></i>
        <input v-model="q" placeholder="Search site name or code..." @focus="searchOpen = true"
               @blur="closeSearchSoon" @keydown.enter="suggestions[0] && pick(suggestions[0])" @keydown.esc="q = ''" />
        <button v-if="q" class="s-clear" @mousedown.prevent="q = ''" title="Clear"><i class="fa-solid fa-xmark"></i></button>
        <button class="s-list" :class="{ on: listOpen }" @click="listOpen = !listOpen" title="Site list">
          <i class="fa-solid fa-list-ul"></i><span class="d-none d-md-inline ms-1">List</span>
        </button>
        <div v-if="searchOpen && suggestions.length" class="suggest">
          <button v-for="s in suggestions" :key="s.id" class="sg-item" @mousedown.prevent="pick(s)">
            <span class="dot" :class="`bg-${s.status}`"></span>
            <span class="sg-txt"><b>{{ s.site_code }}</b> · {{ s.name }}<small>{{ s.region }} · {{ daysText(s) }}</small></span>
            <i class="fa-solid fa-arrow-right sg-go"></i>
          </button>
        </div>
      </div>

      <div class="seg-wrap">
        <div class="seg">
          <button v-for="r in regions" :key="r.key" :class="{ on: region === r.key }" @click="setRegion(r.key)">
            <i :class="r.icon" class="me-1"></i>{{ r.label }} <span class="seg-n">{{ regionCount(r.key) }}</span>
          </button>
        </div>
      </div>

      <div class="chips">
        <button v-for="c in STATUS" :key="c.key" class="chip" :class="[`chip-${c.key}`, { off: !statusOn[c.key] }]"
                @click="statusOn[c.key] = !statusOn[c.key]" @mouseenter="hl = c.key" @mouseleave="hl = null"
                :title="`${statusOn[c.key] ? 'Hide' : 'Show'} ${c.label.toLowerCase()} sites`">
          <span class="chip-ic"><i :class="c.icon"></i></span>
          <span class="chip-n">{{ counts[c.key] }}</span><span class="chip-l">{{ c.label }}</span>
        </button>
        <div class="chip-filter">
          <select v-model="contractor" class="chip-select" title="Filter by contractor">
            <option value="">All contractors</option>
            <option v-for="c in contractors" :key="c" :value="c">{{ c }}</option>
          </select>
          <i class="fa-solid fa-helmet-safety"></i>
        </div>
      </div>
    </div>

    <!-- ── RIGHT TOOLBAR ─────────────────────────────────────────────────── -->
    <div class="toolbar">
      <div class="tb-group">
        <button class="tb" @click="zoom(1)" data-tip="Zoom in"><i class="fa-solid fa-plus"></i></button>
        <button class="tb" @click="zoom(-1)" data-tip="Zoom out"><i class="fa-solid fa-minus"></i></button>
      </div>
      <div class="tb-group">
        <button class="tb" :class="{ on: panel === 'base' }" @click="togglePanel('base')" data-tip="Map style"><i class="fa-solid fa-layer-group"></i></button>
        <button class="tb" :class="{ on: panel === 'layers' }" @click="togglePanel('layers')" data-tip="Map layers"><i class="fa-solid fa-sliders"></i></button>
        <button class="tb" @click="resetView" data-tip="Reset view"><i class="fa-solid fa-house"></i></button>
        <button class="tb" @click="locate" data-tip="My location"><i class="fa-solid fa-location-crosshairs"></i></button>
        <button class="tb d-none d-md-grid" @click="fullscreen" data-tip="Full screen"><i :class="isFull ? 'fa-solid fa-compress' : 'fa-solid fa-expand'"></i></button>
      </div>

      <transition name="pop">
        <div v-if="panel === 'base'" class="tb-pop">
          <div class="pop-title">Map style</div>
          <div class="base-grid">
            <button v-for="b in BASEMAPS" :key="b.key" class="base-opt" :class="{ on: basemap === b.key }" @click="setBase(b.key)">
              <span class="base-thumb" :style="{ backgroundImage: `url(${b.thumb})` }"></span><span>{{ b.label }}</span>
            </button>
          </div>
        </div>
      </transition>
      <transition name="pop">
        <div v-if="panel === 'layers'" class="tb-pop">
          <div class="pop-title">Map layers</div>
          <label v-for="l in LAYERS" :key="l.key" class="layer-row">
            <span class="lr-ic" :style="{ color: l.color }"><i :class="l.icon"></i></span>
            <span class="flex-grow-1">{{ l.label }}<small>{{ l.hint }}</small></span>
            <span class="switch"><input type="checkbox" v-model="layersOn[l.key]" /><i></i></span>
          </label>
        </div>
      </transition>
    </div>

    <!-- ── BOTTOM LEGEND ─────────────────────────────────────────────────── -->
    <div v-show="!listOpen" class="legend" :class="{ collapsed: !legendOpen }">
      <button class="lg-toggle" @click="legendOpen = !legendOpen">
        <i class="fa-solid fa-circle-info me-2"></i>Legend
        <i class="fa-solid ms-auto" :class="legendOpen ? 'fa-chevron-down' : 'fa-chevron-up'"></i>
      </button>
      <div v-show="legendOpen" class="lg-body">
        <div class="lg-sec">
          <div class="lg-h">Permit status</div>
          <div class="lg-items">
            <div v-for="c in STATUS" :key="c.key" class="lg-item" @mouseenter="hl = c.key" @mouseleave="hl = null" :title="c.desc">
              <span class="lg-mk" :class="`bg-${c.key}`"><i :class="c.icon"></i></span>
              <span><b>{{ c.label }}</b><small>{{ c.short }}</small></span>
              <span class="lg-n">{{ counts[c.key] }}</span>
            </div>
          </div>
        </div>
        <div class="lg-sec">
          <div class="lg-h d-flex justify-content-between gap-3">
            <span>Rain intensity <span class="text-muted2 fw-normal">mm/h</span></span>
            <span class="lg-live" :title="weatherTime ? `Updated ${weatherTime}` : ''">
              <span class="live-dot" :class="{ off: !weatherOk }"></span>{{ weatherOk ? `Live · ${weatherTime}` : weatherErr ? 'Unavailable · retrying' : 'Loading…' }}
            </span>
          </div>
          <div class="rain-bar"></div>
          <div class="rain-cols">
            <div v-for="b in RAIN" :key="b.key" class="rain-col" @mouseenter="hl = `rain-${b.key}`" @mouseleave="hl = null" :title="`${b.range} mm/h`">
              <span class="rc-l">{{ b.label }}</span><span class="rc-n">{{ rainCounts[b.key] }}</span><small>{{ b.range }}</small>
            </div>
          </div>
        </div>
        <div class="lg-sec lg-sym d-none d-md-block">
          <div class="lg-h">Symbols</div>
          <div class="sym"><span class="sym-poly"></span>Site boundary</div>
          <div class="sym"><span class="sym-pt"><i class="fa-solid fa-camera"></i></span>Drone photo point</div>
          <div class="sym"><span class="sym-cam"><i class="fa-solid fa-video"></i></span>CCTV camera</div>
          <div class="sym"><span class="sym-drop"><i class="fa-solid fa-droplet"></i></span>Raining now</div>
        </div>
      </div>
    </div>

    <!-- ── SITE LIST DRAWER ──────────────────────────────────────────────── -->
    <transition name="drawer">
      <div v-if="listOpen" class="drawer">
        <div class="dr-head">
          <div><b>Sites</b> <span class="text-muted2 small">{{ visible.length }} shown · nearest expiry first</span></div>
          <button class="x-btn" @click="listOpen = false"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="dr-body">
          <button v-for="s in sortedVisible" :key="s.id" class="dr-item" :class="{ on: selected?.id === s.id }"
                  @click="pick(s)" @mouseenter="hoverSite(s, true)" @mouseleave="hoverSite(s, false)">
            <span class="dr-thumb" :style="s.thumb ? { backgroundImage: `url(${s.thumb})` } : {}">
              <i v-if="!s.thumb" class="fa-solid fa-mountain-sun"></i>
            </span>
            <span class="dr-txt">
              <b>{{ s.site_code }}</b><span class="dr-nm">{{ s.name }}</span>
              <span class="dr-meta"><span class="pill" :class="`pill-${s.status}`">{{ statusOf(s).label }}</span>{{ daysText(s) }}
                <i v-if="wx(s)?.rain > 0" class="fa-solid fa-droplet text-primary ms-1" title="Raining"></i>
                <i v-if="s.camera_count" class="fa-solid fa-video text-secondary ms-1" title="CCTV"></i></span>
            </span>
          </button>
          <div v-if="!visible.length" class="text-center text-muted2 py-5"><i class="fa-regular fa-face-meh fa-2x mb-2 d-block"></i>No sites match the filters.</div>
        </div>
      </div>
    </transition>

    <!-- ── SITE DETAIL PANEL (side on desktop, bottom sheet on mobile) ───── -->
    <transition name="slide">
      <div v-if="selected" class="site-panel" :class="{ mini: sheetMini }">
        <div class="sp-hero" :style="selected.thumb ? { backgroundImage: `url(${selected.thumb})` } : {}" @click="isMobile() && (sheetMini = !sheetMini)">
          <div class="sp-grab d-md-none"></div>
          <button class="x-btn sp-x" @click.stop="closeSite"><i class="fa-solid fa-xmark"></i></button>
          <div class="sp-hero-in">
            <span class="pill pill-lg" :class="`pill-${selected.status}`"><i :class="statusOf(selected).icon" class="me-1"></i>{{ statusOf(selected).label }}</span>
            <h5 class="sp-title">{{ selected.site_code }} · {{ selected.name }}</h5>
            <div class="sp-sub"><i class="fa-solid fa-map-pin me-1"></i>{{ selected.region || '—' }}</div>
          </div>
        </div>

        <div class="sp-body">
          <div class="d-flex gap-2 mb-3">
            <div class="btn-group btn-group-sm flex-grow-1">
              <button class="btn" :class="selected.is_activated ? 'btn-primary' : 'btn-light'">Activate</button>
              <button class="btn" :class="!selected.is_activated ? 'btn-secondary' : 'btn-light'">Deactivate</button>
            </div>
            <button class="btn btn-sm btn-light" @click="flyTo(selected, 15)" title="Zoom to site"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
          </div>

          <!-- weather now -->
          <div class="wx-card" :class="{ wet: wx(selected)?.rain > 0 }">
            <i :class="wxIcon(wx(selected)?.code)" class="wx-big"></i>
            <div class="flex-grow-1">
              <div class="tile-label">Weather now</div>
              <div v-if="wx(selected)"><b>{{ wx(selected).temp }}°C</b> · {{ wxText(wx(selected).code) }}</div>
              <div v-else class="text-muted2 small">{{ weatherErr ? 'Weather unavailable right now' : 'Loading…' }}</div>
            </div>
            <div v-if="wx(selected)" class="text-end">
              <div class="tile-label">Rain</div><b>{{ wx(selected).rain.toFixed(1) }}</b> <small>mm/h</small>
            </div>
          </div>

          <!-- quick stats -->
          <div class="stats">
            <div class="stat" title="CCTV cameras"><i class="fa-solid fa-video"></i><b>{{ selected.camera_count }}</b><small>Cameras</small></div>
            <div class="stat" title="Drone photo points"><i class="fa-solid fa-camera"></i><b>{{ selected.photo_points }}</b><small>Photo pts</small></div>
            <div class="stat" title="Boundary polygons"><i class="fa-solid fa-draw-polygon"></i><b>{{ selected.polygon_count }}</b><small>Boundary</small></div>
            <div class="stat" title="Total acreage"><i class="fa-solid fa-ruler-combined"></i><b>{{ selected.total_acreage ?? '—' }}</b><small>Acres</small></div>
          </div>

          <div class="row g-2 small mt-1">
            <div class="col-7"><div class="tile-label">Contractor</div><div>{{ selected.contractor || '—' }}</div></div>
            <div class="col-5"><div class="tile-label">Location</div><div class="mono">{{ fmtLL(selected) }}</div></div>
            <div class="col-7 mt-2"><div class="tile-label">Permit date</div><div class="mono">{{ fmtDate(selected.permit_date) }} – {{ fmtDate(selected.permit_date_end) }}</div></div>
            <div class="col-5 mt-2"><div class="tile-label">Luas permit</div><div class="mono">{{ fmtDate(selected.luas_permit_date) }}</div></div>
          </div>

          <!-- permit timeline -->
          <div v-if="permitPct !== null" class="permit-bar mt-3" :title="`${Math.round(permitPct)}% of permit period used`">
            <div class="pb-fill" :class="`bg-${selected.status}`" :style="{ width: permitPct + '%' }"></div>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-3">
            <div><b>Permit details</b><div class="tiny text-muted2">Site authorization &amp; compliance</div></div>
            <button class="btn btn-light btn-sm" :disabled="!permitUrl" @click="downloadPermit"><i class="fa-solid fa-download me-1"></i>Download</button>
          </div>

          <div class="note mt-2" :class="`note-${selected.status}`">
            <i :class="statusOf(selected).icon" class="me-1"></i><b>{{ statusOf(selected).label }}</b>
            <div class="small">{{ permitMessage(selected) }}</div>
          </div>

          <div class="mt-3 d-flex justify-content-between align-items-center">
            <div><b>F001 weekly report</b><div class="tiny text-muted2">Latest report submission status</div></div>
            <span v-if="selected.latest_f001" class="pill" :class="`f-${selected.latest_f001.status}`">{{ f001Label(selected.latest_f001.status) }}</span>
            <span v-else class="pill pill-unknown">No report</span>
          </div>
          <div v-if="selected.latest_f001" class="tiny text-muted2 mt-1">Week {{ fmtDate(selected.latest_f001.from) }} – {{ fmtDate(selected.latest_f001.to) }}</div>

          <div class="sp-actions">
            <router-link v-if="selected.camera_count" class="btn btn-dark" to="/live-view"><i class="fa-solid fa-video me-2"></i>Live view</router-link>
            <router-link class="btn btn-light" :class="{ 'sp-wide': !selected.camera_count }" :to="`/site/${selected.id}?tab=reports`"><i class="fa-regular fa-file-lines me-2"></i>Daily report</router-link>
            <router-link class="btn btn-primary sp-wide" :to="`/site/${selected.id}`"><i class="fa-solid fa-circle-info me-2"></i>View site details page</router-link>
          </div>
        </div>
      </div>
    </transition>

    <div v-if="loading" class="loading"><span class="spinner-border spinner-border-sm me-2"></span>Loading sites…</div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { load, fmtDate, apiGet } from '../services/api'

/* ── constants ─────────────────────────────────────────────────────────── */
const STATUS = [
  { key: 'active',  label: 'Active',  short: '> 90 days left', icon: 'fa-solid fa-circle-check',         desc: 'Permit valid for more than 90 days' },
  { key: 'warning', label: 'Warning', short: '≤ 90 days left', icon: 'fa-solid fa-triangle-exclamation', desc: 'Permit expires within 90 days' },
  { key: 'expired', label: 'Expired', short: 'Permit ended',   icon: 'fa-solid fa-circle-exclamation',   desc: 'Permit end date has passed' },
  { key: 'unknown', label: 'No data', short: 'No permit date', icon: 'fa-solid fa-circle-question',      desc: 'No permit end date recorded' },
]
const RAIN = [
  { key: 'light',    label: 'Light',    range: '< 2.5',     min: 0.01, max: 2.5 },
  { key: 'moderate', label: 'Moderate', range: '2.5 – 7.6', min: 2.5,  max: 7.6 },
  { key: 'heavy',    label: 'Heavy',    range: '7.6 – 50',  min: 7.6,  max: 50 },
  { key: 'violent',  label: 'Violent',  range: '> 50',      min: 50,   max: Infinity },
]
const RAIN_COLOR = { light: '#38bdf8', moderate: '#22c55e', heavy: '#f59e0b', violent: '#e11d48' }
// Esri tiles: free, no API key (CARTO now requires one)
const ESRI = p => `https://server.arcgisonline.com/ArcGIS/rest/services/${p}/MapServer/tile/{z}/{y}/{x}`
const ESRI_THUMB = p => `https://server.arcgisonline.com/ArcGIS/rest/services/${p}/MapServer/tile/10/502/800`
const BASEMAPS = [
  { key: 'street',    label: 'Street',    url: ESRI('World_Street_Map'), thumb: ESRI_THUMB('World_Street_Map'), attr: '© Esri, HERE, Garmin, OpenStreetMap' },
  { key: 'light',     label: 'Light',     url: ESRI('Canvas/World_Light_Gray_Base'), thumb: ESRI_THUMB('Canvas/World_Light_Gray_Base'), attr: '© Esri', labels: 'Canvas/World_Light_Gray_Reference' },
  { key: 'satellite', label: 'Satellite', url: ESRI('World_Imagery'), thumb: ESRI_THUMB('World_Imagery'), attr: 'Imagery © Esri, Maxar', labels: 'Reference/World_Boundaries_and_Places' },
  { key: 'topo',      label: 'Terrain',   url: ESRI('World_Topo_Map'), thumb: ESRI_THUMB('World_Topo_Map'), attr: '© Esri' },
  { key: 'dark',      label: 'Dark',      url: ESRI('Canvas/World_Dark_Gray_Base'), thumb: ESRI_THUMB('Canvas/World_Dark_Gray_Base'), attr: '© Esri', labels: 'Canvas/World_Dark_Gray_Reference' },
]
const LAYERS = [
  { key: 'radar',      label: 'Rain radar',         hint: 'Live precipitation (RainViewer)',  icon: 'fa-solid fa-cloud-rain',   color: '#2563eb' },
  { key: 'rainRings',  label: 'Rain at sites',      hint: 'Ring where rain ≥ 2.5 mm/h',      icon: 'fa-solid fa-droplet',      color: '#0ea5e9' },
  { key: 'boundaries', label: 'Site boundary',      hint: 'Shown for the selected site',      icon: 'fa-solid fa-draw-polygon', color: '#7c3aed' },
  { key: 'photos',     label: 'Drone photo points', hint: 'Shown for the selected site',      icon: 'fa-solid fa-camera',       color: '#db2777' },
  { key: 'cluster',    label: 'Group nearby sites', hint: 'Cluster markers when zoomed out',  icon: 'fa-solid fa-circle-nodes', color: '#0f766e' },
]
const regions = [
  { key: 'all', label: 'All', icon: 'fa-solid fa-earth-asia' },
  { key: 'Northern Region', label: 'Northern', icon: 'fa-solid fa-arrow-up' },
  { key: 'Southern Region', label: 'Southern', icon: 'fa-solid fa-arrow-down' },
]
const F001 = { draft: 'Draft', pending_review: 'Pending review', pending_approval: 'Pending approval', submitted: 'Submitted', returned: 'Returned' }

/* ── state ─────────────────────────────────────────────────────────────── */
const sites = ref([]), loading = ref(true)
const q = ref(''), region = ref('all'), contractor = ref(''), searchOpen = ref(false)
const statusOn = reactive({ active: true, warning: true, expired: true, unknown: true })
const layersOn = reactive({ radar: false, rainRings: true, boundaries: true, photos: true, cluster: true })
const selected = ref(null), permitUrl = ref(null), listOpen = ref(false), sheetMini = ref(false)
const hl = ref(null), panel = ref(null), basemap = ref('street')
const legendOpen = ref(window.innerWidth >= 768), isFull = ref(false), zoomLvl = ref(10)
const weather = ref({}), weatherOk = ref(false), weatherErr = ref(false), weatherTime = ref('')
let skipFit = false

const statusOf = s => STATUS.find(x => x.key === s.status) || STATUS[3]
const f001Label = s => F001[s] || s
const wx = s => s && weather.value[s.id]
const showLabels = computed(() => zoomLvl.value >= 13)
const isMobile = () => window.innerWidth < 768

const contractors = computed(() => [...new Set(sites.value.map(s => s.contractor).filter(Boolean))].sort())
const baseFiltered = computed(() => sites.value.filter(s =>
  (region.value === 'all' || s.region === region.value) && (!contractor.value || s.contractor === contractor.value)))
const visible = computed(() => baseFiltered.value.filter(s => statusOn[s.status] && s.lat && s.lng))
const sortedVisible = computed(() => [...visible.value].sort((a, b) => (a.days_left ?? 1e9) - (b.days_left ?? 1e9)))
const counts = computed(() => {
  const c = { active: 0, warning: 0, expired: 0, unknown: 0 }
  baseFiltered.value.forEach(s => c[s.status]++); return c
})
const regionCount = k => sites.value.filter(s => k === 'all' || s.region === k).length
const rainBand = mm => mm > 0 ? (RAIN.find(b => mm >= b.min && mm < b.max)?.key || 'light') : null
const rainCounts = computed(() => {
  const c = { light: 0, moderate: 0, heavy: 0, violent: 0 }
  visible.value.forEach(s => { const b = rainBand(wx(s)?.rain || 0); if (b) c[b]++ }); return c
})
const suggestions = computed(() => {
  const t = q.value.trim().toLowerCase(); if (!t) return []
  return sites.value.filter(s => `${s.site_code} ${s.name}`.toLowerCase().includes(t)).slice(0, 7)
})
const permitPct = computed(() => {
  const s = selected.value; if (!s?.permit_date || !s?.permit_date_end) return null
  const a = +new Date(s.permit_date), b = +new Date(s.permit_date_end); if (b <= a) return null
  return Math.min(100, Math.max(0, (Date.now() - a) / (b - a) * 100))
})

/* ── text helpers ──────────────────────────────────────────────────────── */
const plural = n => n === 1 ? '' : 's'
function daysText(s) {
  const d = s.days_left
  if (d == null) return 'No permit date'
  if (d < 0) return `Expired ${-d} day${plural(-d)} ago`
  if (d === 0) return 'Expires today'
  return `${d} day${plural(d)} left`
}
function permitMessage(s) {
  const d = s.days_left
  if (d == null) return 'No permit end date is recorded for this site. Please update the permit details.'
  if (d < 0) return `Permit expired on ${fmtDate(s.permit_date_end)} (${-d} day${plural(-d)} ago). Please renew the permit before operations continue.`
  if (d <= 90) return `Permit expires in ${d} day${plural(d)} (${fmtDate(s.permit_date_end)}). Please renew your permit as soon as possible.`
  return `Permit is valid until ${fmtDate(s.permit_date_end)} — ${d} days remaining.`
}
const fmtLL = s => s.lat ? `${(+s.lat).toFixed(4)}° N, ${(+s.lng).toFixed(4)}° E` : '—'
function wxIcon(c) {
  if (c == null) return 'fa-solid fa-cloud text-muted2'
  if (c === 0) return 'fa-solid fa-sun text-warning'
  if (c <= 3) return 'fa-solid fa-cloud-sun text-warning'
  if (c <= 48) return 'fa-solid fa-smog text-secondary'
  if (c <= 67) return 'fa-solid fa-cloud-rain text-primary'
  if (c <= 82) return 'fa-solid fa-cloud-showers-heavy text-primary'
  return 'fa-solid fa-cloud-bolt text-purple'
}
function wxText(c) {
  if (c == null) return '—'
  if (c === 0) return 'Clear'; if (c <= 3) return 'Partly cloudy'; if (c <= 48) return 'Fog / haze'
  if (c <= 57) return 'Drizzle'; if (c <= 67) return 'Rain'; if (c <= 82) return 'Showers'; return 'Thunderstorm'
}

/* ── map ───────────────────────────────────────────────────────────────── */
let map, baseLayer, labelLayer, radarLayer, siteLayer, ringLayer, geoLayer
const markers = new Map()
const L = () => window.L

function markerHtml(s) {
  const st = statusOf(s), w = wx(s), band = rainBand(w?.rain || 0)
  return `<div class="sm st-${s.status} ${band ? `rain-${band}` : 'rain-none'}${selected.value?.id === s.id ? ' sel' : ''}">
    <span class="sm-dot"><i class="${st.icon}"></i></span>
    ${w?.rain > 0 ? '<span class="sm-drop"><i class="fa-solid fa-droplet"></i></span>' : ''}
    ${s.camera_count ? '<span class="sm-cam"><i class="fa-solid fa-video"></i></span>' : ''}
    <span class="sm-label">${s.site_code || ''}</span></div>`
}
function tipHtml(s) {
  const w = wx(s), st = statusOf(s)
  return `<div class="tip">
    ${s.thumb ? `<div class="tip-img" style="background-image:url('${s.thumb}')"></div>` : ''}
    <div class="tip-b"><div class="tip-t">${s.site_code} · ${s.name}</div>
    <div class="tip-r"><span class="pill pill-${s.status}">${st.label}</span><span>${daysText(s)}</span></div>
    <div class="tip-r tip-m"><i class="fa-solid fa-helmet-safety"></i>${s.contractor || '—'}</div>
    ${w ? `<div class="tip-r tip-m"><i class="${wxIcon(w.code)}"></i>${w.temp}°C · ${wxText(w.code)} · ${w.rain.toFixed(1)} mm/h</div>` : ''}
    <div class="tip-hint">Click for details</div></div></div>`
}
function clusterIcon(c) {
  const kids = c.getAllChildMarkers(), n = kids.length, t = { active: 0, warning: 0, expired: 0, unknown: 0 }
  kids.forEach(m => t[m.options.site.status]++)
  const col = { active: '#2563eb', warning: '#f59e0b', expired: '#e11d48', unknown: '#94a3b8' }
  let acc = 0
  const stops = Object.keys(t).filter(k => t[k]).map(k => { const a = acc; acc += t[k] / n * 360; return `${col[k]} ${a}deg ${acc}deg` }).join(',')
  const size = n < 10 ? 46 : n < 30 ? 54 : 62
  return L().divIcon({ className: '', iconSize: [size, size],
    html: `<div class="cl" style="width:${size}px;height:${size}px;background:conic-gradient(${stops})"><span>${n}</span></div>` })
}

function buildMarkers() {
  if (!map) return
  if (siteLayer) map.removeLayer(siteLayer)
  if (detached) { map.removeLayer(detached); detached = null }
  siteLayer = layersOn.cluster
    ? L().markerClusterGroup({ iconCreateFunction: clusterIcon, showCoverageOnHover: false, maxClusterRadius: 55, spiderfyOnMaxZoom: true, disableClusteringAtZoom: 13 })
    : L().layerGroup()
  markers.clear(); ringLayer.clearLayers()
  visible.value.forEach(s => {
    const m = L().marker([+s.lat, +s.lng], { site: s, riseOnHover: true,
      icon: L().divIcon({ className: 'sm-wrap', iconSize: [34, 34], iconAnchor: [17, 17], html: markerHtml(s) }) })
    if (!isMobile()) m.bindTooltip(tipHtml(s), { direction: 'top', offset: [0, -16], className: 'tip-wrap', opacity: 1 })
    m.on('click', () => pick(s, false))
    siteLayer.addLayer(m); markers.set(s.id, m)
    const w = wx(s)
    if (layersOn.rainRings && w?.rain >= 2.5) {   // moderate and above only — light drizzle would clutter the map
      const color = RAIN_COLOR[rainBand(w.rain)]
      L().circle([+s.lat, +s.lng], { radius: 900 + Math.min(w.rain, 60) * 60, color, weight: 1, fillColor: color, fillOpacity: .18, interactive: false }).addTo(ringLayer)
    }
  })
  map.addLayer(siteLayer)
  markSelected()
}
// selection lives in the icon html, so it survives cluster re-renders; z-offset keeps it on top
// the selected marker is pulled out of the cluster group so it is always visible
let lastSel = null, detached = null
function markSelected() {
  const id = selected.value?.id ?? null
  if (detached) { map.removeLayer(detached); if (markers.get(detached.options.site.id) === detached) siteLayer.addLayer(detached); detached = null }
  for (const k of new Set([lastSel, id])) {
    const m = k != null && markers.get(k); if (!m) continue
    m.setIcon(L().divIcon({ className: 'sm-wrap', iconSize: [34, 34], iconAnchor: [17, 17], html: markerHtml(m.options.site) }))
    m.setZIndexOffset(k === id ? 10000 : 0)
  }
  const m = id != null && markers.get(id)
  if (m && layersOn.cluster) { siteLayer.removeLayer(m); m.addTo(map); detached = m }
  lastSel = id
}
function hoverSite(s, on) {
  const el = markers.get(s.id)?.getElement()
  if (el) el.classList.toggle('is-hover', on)
}

function setBase(k) {
  basemap.value = k; const b = BASEMAPS.find(x => x.key === k)
  if (baseLayer) map.removeLayer(baseLayer)
  if (labelLayer) { map.removeLayer(labelLayer); labelLayer = null }
  baseLayer = L().tileLayer(b.url, { maxZoom: 19, maxNativeZoom: k === 'light' || k === 'dark' ? 16 : 18, attribution: b.attr }).addTo(map)
  if (b.labels) labelLayer = L().tileLayer(ESRI(b.labels), { maxZoom: 19, maxNativeZoom: 16, pane: 'shadowPane' }).addTo(map)
  baseLayer.bringToBack()
  try { localStorage.setItem('sm_basemap', k) } catch {}
}
async function setRadar(on) {
  if (radarLayer) { map.removeLayer(radarLayer); radarLayer = null }
  if (!on) return
  try {
    const d = await (await fetch('https://api.rainviewer.com/public/weather-maps.json')).json()
    const f = d.radar.past[d.radar.past.length - 1]
    radarLayer = L().tileLayer(`${d.host}${f.path}/256/{z}/{x}/{y}/2/1_1.png`, { opacity: .65, maxNativeZoom: 7, maxZoom: 19, attribution: 'Radar © RainViewer' }).addTo(map)
  } catch { layersOn.radar = false }
}

// keep the focused area clear of the side panel (desktop) / bottom sheet (mobile)
function fitPad() {
  return isMobile()
    ? { paddingTopLeft: [30, 150], paddingBottomRight: [30, window.innerHeight * 0.55] }
    : { paddingTopLeft: [80, 140], paddingBottomRight: [500, 80] }
}
async function loadGeo(s) {
  geoLayer.clearLayers()
  const g = await apiGet(`sites/${s.id}/geo`)
  if (!g || selected.value?.id !== s.id) return
  // fit the site point + its boundaries + photo points together (some AOI polygons are tiny or sit km away)
  let bounds = s.lat ? L().latLngBounds([[+s.lat, +s.lng], [+s.lat, +s.lng]]) : null
  const grow = b => { bounds = bounds ? bounds.extend(b) : L().latLngBounds(b.getSouthWest ? [b.getSouthWest(), b.getNorthEast()] : [b, b]) }
  if (layersOn.boundaries) g.polygons.forEach((p, i) => {
    if (!p.geojson) return
    const lyr = L().geoJSON(p.geojson, {
      coordsToLatLng: c => L().latLng(+c[1], +c[0]),
      style: { color: i ? '#a78bfa' : '#7c3aed', weight: i ? 1.5 : 2.5, dashArray: i ? '5 5' : null, fillColor: '#7c3aed', fillOpacity: i ? .04 : .12 },
    }).bindTooltip(`<b>${p.name || 'Boundary'}</b>${p.date ? `<br><small>${fmtDate(p.date)}</small>` : ''}`, { sticky: true, className: 'tip-mini' }).addTo(geoLayer)
    grow(lyr.getBounds())
  })
  if (layersOn.photos) g.photo_points.forEach(p => {
    L().marker([p.lat, p.lng], { icon: L().divIcon({ className: '', iconSize: [22, 22], iconAnchor: [11, 11], html: '<div class="pp"><i class="fa-solid fa-camera"></i></div>' }) })
      .bindPopup(`<div class="pp-pop">${p.photo ? `<a href="${p.full || p.photo}" target="_blank" rel="noopener"><img src="${p.photo}" loading="lazy"/></a>` : ''}<b>${p.name || 'Photo point'}</b><small>${p.photo ? 'Tap the image to open full size' : 'No photo'}</small></div>`, { maxWidth: 260, className: 'pp-wrap' })
      .addTo(geoLayer)
    if (!s.lat || map.distance([+s.lat, +s.lng], [p.lat, p.lng]) < 10000) grow(L().latLng(p.lat, p.lng))   // ignore stray points
  })
  g.cameras.filter(c => c.lat && c.lng).forEach(c => {
    L().marker([+c.lat, +c.lng], { icon: L().divIcon({ className: '', iconSize: [26, 26], iconAnchor: [13, 13], html: '<div class="cam"><i class="fa-solid fa-video"></i></div>' }) })
      .bindTooltip(`<b>${c.name}</b><br><small>CCTV camera</small>`, { className: 'tip-mini' }).addTo(geoLayer)
  })
  if (bounds && (g.polygons.length || g.photo_points.length)) map.flyToBounds(bounds, { ...fitPad(), maxZoom: 15, duration: .8 })
}

/* ── actions ───────────────────────────────────────────────────────────── */
async function pick(s, fly = true) {
  q.value = ''; searchOpen.value = false
  if (isMobile()) listOpen.value = false
  if (!statusOn[s.status]) statusOn[s.status] = true
  if ((region.value !== 'all' && s.region !== region.value) || (contractor.value && s.contractor !== contractor.value)) {
    skipFit = true; region.value = 'all'; contractor.value = ''
  }
  selected.value = s; permitUrl.value = null; sheetMini.value = false
  if (fly) flyTo(s, 14)
  loadGeo(s)
  const m = await apiGet(`sites/${s.id}/media`)
  if (selected.value?.id !== s.id) return
  const permits = (m?.files || []).filter(f => f.category === 'Permit & License Approval')
  permits.sort((a, b) => (b.date || '').localeCompare(a.date || ''))
  permitUrl.value = permits[0]?.url || null
}
function closeSite() { selected.value = null; geoLayer?.clearLayers() }
function flyTo(s, z) {
  if (!s?.lat) return
  const shift = isMobile() ? [0, window.innerHeight * 0.22] : [200, 0]
  const p = map.project(L().latLng(+s.lat, +s.lng), z).add(shift)
  map.flyTo(map.unproject(p, z), z, { duration: .8 })
}
function downloadPermit() { if (permitUrl.value) window.open(permitUrl.value, '_blank') }
function setRegion(r) { region.value = r }
function fitVisible() {
  const pts = visible.value.map(s => [+s.lat, +s.lng]); if (!pts.length || !map) return
  const pad = isMobile() ? { paddingTopLeft: [20, 150], paddingBottomRight: [60, 70] } : { paddingTopLeft: [40, 190], paddingBottomRight: [90, 60] }
  map.flyToBounds(L().latLngBounds(pts), { ...pad, maxZoom: 13, duration: .8 })
}
function resetView() {
  region.value = 'all'; contractor.value = ''; Object.keys(statusOn).forEach(k => statusOn[k] = true)
  closeSite(); panel.value = null; setTimeout(fitVisible, 60)
}
function zoom(d) { d > 0 ? map.zoomIn() : map.zoomOut() }
function togglePanel(p) { panel.value = panel.value === p ? null : p }
function locate() {
  map.locate({ setView: true, maxZoom: 13 })
  map.once('locationfound', e => L().circleMarker(e.latlng, { radius: 8, color: '#fff', weight: 3, fillColor: '#0d6efd', fillOpacity: 1 }).addTo(geoLayer))
}
function fullscreen() {
  const el = document.querySelector('.map-wrap')
  if (!document.fullscreenElement) el.requestFullscreen?.(); else document.exitFullscreen?.()
}
const onFs = () => { isFull.value = !!document.fullscreenElement; setTimeout(() => map?.invalidateSize(), 200) }
const onResize = () => map?.invalidateSize()
function closeSearchSoon() { setTimeout(() => searchOpen.value = false, 150) }

/* ── data ──────────────────────────────────────────────────────────────── */
async function loadSites() {
  const live = await apiGet('map')
  if (Array.isArray(live)) return live
  // offline fallback: compute status from the bundled mock JSON
  const raw = await load('sites'), today = new Date(); today.setHours(0, 0, 0, 0)
  return raw.map(s => {
    const d = s.permit_date_end ? Math.round((new Date(s.permit_date_end) - today) / 864e5) : null
    return { ...s, region: s.region?.name || s.region, contractor: s.contractor?.name || s.contractor, days_left: d,
      status: d == null ? 'unknown' : d < 0 ? 'expired' : d <= 90 ? 'warning' : 'active',
      camera_count: 0, photo_points: 0, polygon_count: 0, thumb: null, latest_f001: null, is_activated: s.is_activated ?? true }
  })
}
async function loadWeather() {
  const pts = sites.value.filter(s => s.lat && s.lng); if (!pts.length) return
  const key = 'sm_wx'
  let cached = null; try { cached = JSON.parse(sessionStorage.getItem(key) || 'null') } catch {}
  if (cached && Date.now() - cached.at < 10 * 60e3) { weather.value = cached.data; weatherTime.value = cached.time; weatherOk.value = true; return }
  try {
    const url = 'https://api.open-meteo.com/v1/forecast?latitude=' + pts.map(s => (+s.lat).toFixed(3)).join(',') +
      '&longitude=' + pts.map(s => (+s.lng).toFixed(3)).join(',') + '&current=precipitation,weather_code,temperature_2m&timezone=Asia%2FKuala_Lumpur'
    const res = await fetch(url); if (!res.ok) throw new Error(`weather ${res.status}`)
    let d = await res.json(); if (!Array.isArray(d)) d = [d]
    const data = {}
    d.forEach((r, i) => {
      const c = r.current || {}, perHour = (c.precipitation || 0) * (3600 / (c.interval || 900))   // interval total → mm/h
      data[pts[i].id] = { rain: Math.round(perHour * 10) / 10, code: c.weather_code, temp: Math.round(c.temperature_2m) }
    })
    weather.value = data; weatherOk.value = true
    weatherTime.value = (d[0]?.current?.time || '').slice(11, 16)
    try { sessionStorage.setItem(key, JSON.stringify({ at: Date.now(), data, time: weatherTime.value })) } catch {}
  } catch {
    weatherOk.value = false; weatherErr.value = true
    setTimeout(() => { weatherErr.value = false; loadWeather() }, 60e3)   // retry (e.g. rate-limited)
  }
}

watch([visible, () => layersOn.cluster, () => layersOn.rainRings, weather], buildMarkers)
watch([region, contractor], () => { if (skipFit) { skipFit = false; return } setTimeout(fitVisible, 50) })
watch(() => layersOn.radar, setRadar)
watch([() => layersOn.boundaries, () => layersOn.photos], () => selected.value && loadGeo(selected.value))
watch(selected, () => setTimeout(markSelected, 0))

onMounted(async () => {
  map = L().map('map', { zoomControl: false }).setView([3.2, 101.5], 10)
  map.attributionControl.setPrefix('')
  let saved = 'street'; try { saved = localStorage.getItem('sm_basemap') || 'street' } catch {}
  setBase(BASEMAPS.some(b => b.key === saved) ? saved : 'street')
  ringLayer = L().layerGroup().addTo(map); geoLayer = L().layerGroup().addTo(map)
  map.on('zoomend', () => { zoomLvl.value = map.getZoom() })
  map.on('click', () => { panel.value = null })
  map.on('dragstart', () => { if (isMobile() && selected.value) sheetMini.value = true })   // peek the sheet while panning
  document.addEventListener('fullscreenchange', onFs); window.addEventListener('resize', onResize)
  sites.value = await loadSites(); loading.value = false
  buildMarkers(); fitVisible()
  loadWeather()
})
onBeforeUnmount(() => { document.removeEventListener('fullscreenchange', onFs); window.removeEventListener('resize', onResize); map?.remove() })
</script>

<style scoped>
.map-wrap{position:relative;height:calc(100vh - 64px);height:calc(100dvh - 64px);overflow:hidden;background:#e5e7eb;
  --c-active:#2563eb;--c-warning:#f59e0b;--c-expired:#e11d48;--c-unknown:#94a3b8;--glass:rgba(255,255,255,.92);--sh:0 6px 24px rgba(15,23,42,.12),0 1px 3px rgba(15,23,42,.08)}
.leaflet{position:absolute;inset:0;z-index:1}
.bg-active{background:var(--c-active)!important}.bg-warning{background:var(--c-warning)!important}.bg-expired{background:var(--c-expired)!important}.bg-unknown{background:var(--c-unknown)!important}

/* top bar */
.topbar{position:absolute;top:16px;left:20px;right:84px;z-index:700;display:grid;grid-template-columns:360px 1fr;gap:10px 16px;pointer-events:none}
.topbar>*{pointer-events:auto}
.seg-wrap{pointer-events:none!important}.seg-wrap .seg{pointer-events:auto}
.search-box{position:relative;z-index:5;display:flex;align-items:center;background:var(--glass);backdrop-filter:blur(10px);border-radius:14px;box-shadow:var(--sh);height:48px;padding:0 6px 0 14px;transition:box-shadow .2s,border-radius .2s}
.search-box:focus-within{box-shadow:0 0 0 3px rgba(13,110,253,.18),var(--sh)}
.search-box.open{border-radius:14px 14px 0 0}
.s-ic{color:#94a3b8}
.search-box input{flex:1;border:0;background:transparent;outline:0;padding:0 10px;font-size:15px;min-width:0}
.s-clear{border:0;background:#f1f5f9;border-radius:50%;width:26px;height:26px;color:#64748b;font-size:12px;margin-right:6px}
.s-list{border:0;background:#f1f5f9;border-radius:10px;height:36px;padding:0 12px;color:#334155;font-weight:600;font-size:13px;transition:all .15s;white-space:nowrap}
.s-list:hover,.s-list.on{background:#0d6efd;color:#fff}
.suggest{position:absolute;top:100%;left:0;right:0;background:#fff;border-radius:0 0 14px 14px;box-shadow:0 18px 30px rgba(15,23,42,.15);overflow:hidden;border-top:1px solid #eef0f3}
.sg-item{display:flex;align-items:center;gap:10px;width:100%;border:0;background:#fff;padding:9px 14px;text-align:left;font-size:13.5px;transition:background .12s}
.sg-item:hover{background:#f1f5ff}.sg-item:hover .sg-go{opacity:1;transform:none}
.sg-txt{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sg-txt small{display:block;color:#94a3b8;font-size:11.5px}
.sg-go{color:#0d6efd;opacity:0;transform:translateX(-4px);transition:all .15s}
.dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;box-shadow:0 0 0 3px rgba(0,0,0,.05)}

.seg-wrap{display:flex;justify-content:center;align-items:center}
.seg{background:var(--glass);backdrop-filter:blur(10px);box-shadow:var(--sh)}
.seg button{transition:all .18s;white-space:nowrap}
.seg button:not(.on):hover{background:#eef4ff;color:#0d6efd}
.seg-n{display:inline-block;min-width:22px;padding:0 6px;margin-left:4px;border-radius:999px;background:rgba(15,23,42,.08);font-size:11.5px}
.seg button.on .seg-n{background:rgba(255,255,255,.25)}

.chips{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.chip{display:inline-flex;align-items:center;gap:7px;border:0;background:var(--glass);backdrop-filter:blur(10px);border-radius:999px;padding:5px 14px 5px 5px;box-shadow:var(--sh);font-size:13px;color:#334155;transition:transform .15s,box-shadow .15s,opacity .15s}
.chip:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(15,23,42,.16)}
.chip-ic{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:12px;transition:background .15s}
.chip-active .chip-ic{background:var(--c-active)}.chip-warning .chip-ic{background:var(--c-warning)}.chip-expired .chip-ic{background:var(--c-expired)}.chip-unknown .chip-ic{background:var(--c-unknown)}
.chip-n{font-weight:800;font-size:14px;color:#0f172a}
.chip.off{opacity:.5}.chip.off .chip-ic{background:#cbd5e1}.chip.off .chip-l{text-decoration:line-through}
.chip-filter{position:relative;display:flex;align-items:center}
.chip-filter i{position:absolute;left:13px;color:#64748b;pointer-events:none}
.chip-select{appearance:none;border:0;background:var(--glass) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%2364748b' fill='none' stroke-width='1.5'/%3E%3C/svg%3E") no-repeat right 12px center;border-radius:999px;box-shadow:var(--sh);height:36px;padding:0 32px 0 34px;font-size:13px;color:#334155;max-width:240px;cursor:pointer;transition:box-shadow .15s}
.chip-select:hover{box-shadow:0 10px 24px rgba(15,23,42,.16)}

/* toolbar */
.toolbar{position:absolute;top:16px;right:20px;z-index:750;display:flex;flex-direction:column;gap:10px}
.tb-group{background:var(--glass);backdrop-filter:blur(10px);border-radius:14px;box-shadow:var(--sh);display:flex;flex-direction:column}
.tb{position:relative;width:46px;height:44px;border:0;background:transparent;display:grid;place-items:center;color:#334155;font-size:16px;transition:all .15s}
.tb:first-child{border-radius:14px 14px 0 0}.tb:last-child{border-radius:0 0 14px 14px}
.tb+.tb{border-top:1px solid #eef0f3}
.tb:hover{color:#0d6efd;background:#eef4ff}.tb.on{color:#fff;background:#0d6efd}
.tb::after{content:attr(data-tip);position:absolute;right:calc(100% + 10px);top:50%;transform:translate(6px,-50%);background:#0f172a;color:#fff;font-size:12px;font-weight:600;white-space:nowrap;padding:5px 10px;border-radius:8px;opacity:0;pointer-events:none;transition:all .15s}
.tb:hover::after{opacity:1;transform:translate(0,-50%)}
.tb-pop{position:absolute;right:58px;top:54px;width:300px;background:#fff;border-radius:16px;box-shadow:0 20px 50px rgba(15,23,42,.22);padding:14px}
.pop-title{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:10px}
.base-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.base-opt{border:2px solid transparent;background:#f8fafc;border-radius:12px;padding:5px;font-size:12.5px;font-weight:600;color:#334155;display:flex;flex-direction:column;gap:5px;transition:all .15s}
.base-opt:hover{transform:translateY(-2px);box-shadow:0 8px 18px rgba(15,23,42,.12)}
.base-opt.on{border-color:#0d6efd;color:#0d6efd}
.base-thumb{height:62px;border-radius:8px;background-size:cover;background-position:center}
.layer-row{display:flex;align-items:center;gap:10px;padding:8px 6px;border-radius:10px;cursor:pointer;font-size:13.5px;font-weight:600;color:#1f2937;transition:background .12s;margin:0}
.layer-row:hover{background:#f8fafc}
.layer-row small{display:block;font-weight:400;color:#94a3b8;font-size:11.5px}
.lr-ic{width:30px;height:30px;border-radius:9px;background:#f1f5f9;display:grid;place-items:center;flex-shrink:0}
.switch{position:relative;width:38px;height:22px;flex-shrink:0}
.switch input{position:absolute;opacity:0;inset:0;margin:0;cursor:pointer;z-index:1}
.switch i{position:absolute;inset:0;background:#cbd5e1;border-radius:999px;transition:background .2s}
.switch i::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
.switch input:checked+i{background:#0d6efd}.switch input:checked+i::after{transform:translateX(16px)}

/* legend */
.legend{position:absolute;left:20px;bottom:20px;z-index:700;background:var(--glass);backdrop-filter:blur(12px);border-radius:16px;box-shadow:var(--sh);max-width:calc(100% - 100px);overflow:hidden}
.lg-toggle{display:flex;align-items:center;width:100%;border:0;background:transparent;padding:10px 16px;font-weight:700;font-size:13px;color:#334155;gap:4px}
.lg-toggle:hover{color:#0d6efd}
.legend:not(.collapsed) .lg-toggle{border-bottom:1px solid #eef0f3}
.lg-body{display:flex;padding:4px 0}
.lg-sec{padding:10px 18px}
.lg-sec+.lg-sec{border-left:1px solid #eef0f3}
.lg-h{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin-bottom:8px}
.lg-items{display:grid;grid-template-columns:1fr 1fr;gap:4px 10px}
.lg-item{display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:10px;cursor:default;transition:background .15s,transform .15s,box-shadow .15s;min-width:150px}
.lg-item:hover{background:#fff;transform:translateY(-1px);box-shadow:0 4px 12px rgba(15,23,42,.08)}
.lg-item b{display:block;font-size:12.5px;color:#0f172a}.lg-item small{display:block;font-size:11px;color:#94a3b8;line-height:1.1}
.lg-mk{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:11px;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.25);flex-shrink:0}
.lg-n{margin-left:auto;font-weight:800;color:#0f172a;font-size:13px}
.lg-live{font-weight:600;text-transform:none;letter-spacing:0;color:#16a34a;font-size:11px;white-space:nowrap}
.live-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#22c55e;margin-right:5px;animation:blink 1.6s infinite}
.live-dot.off{background:#cbd5e1;animation:none}
.rain-bar{height:8px;border-radius:6px;background:linear-gradient(90deg,#38bdf8,#22c55e 35%,#f59e0b 65%,#e11d48);width:300px}
.rain-cols{display:grid;grid-template-columns:repeat(4,1fr);margin-top:6px;width:300px}
.rain-col{display:flex;flex-direction:column;padding:4px;border-radius:8px;cursor:default;transition:background .15s,box-shadow .15s}
.rain-col:hover{background:#fff;box-shadow:0 4px 12px rgba(15,23,42,.08)}
.rc-l{font-size:10.5px;font-weight:700;text-transform:uppercase;color:#64748b}
.rc-n{font-weight:800;color:#0f172a;font-size:15px;line-height:1.2}
.rain-col small{font-size:10px;color:#94a3b8}
.sym{display:flex;align-items:center;gap:10px;font-size:12.5px;color:#334155;padding:3px 0}
.sym-poly{width:22px;height:14px;border:2px solid #7c3aed;background:rgba(124,58,237,.15);border-radius:3px}
.sym-pt,.sym-cam,.sym-drop{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:10px}
.sym-pt{background:#db2777}.sym-cam{background:#0f172a;border-radius:6px}.sym-drop{background:#0ea5e9}

/* drawer */
.drawer{position:absolute;top:126px;left:20px;bottom:20px;width:350px;z-index:760;background:#fff;border-radius:18px;box-shadow:0 20px 50px rgba(15,23,42,.2);display:flex;flex-direction:column;overflow:hidden}
.dr-head{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #eef0f3}
.dr-body{overflow:auto;flex:1;padding:6px}
.dr-item{display:flex;gap:10px;width:100%;border:0;background:transparent;text-align:left;padding:8px;border-radius:12px;transition:background .12s,transform .12s}
.dr-item:hover{background:#f1f5ff;transform:translateX(2px)}.dr-item.on{background:#e0ebff}
.dr-thumb{width:54px;height:54px;border-radius:10px;background:#e2e8f0 center/cover;display:grid;place-items:center;color:#94a3b8;flex-shrink:0}
.dr-txt{min-width:0;display:flex;flex-direction:column;font-size:13px}
.dr-nm{color:#475569;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dr-meta{display:flex;align-items:center;gap:6px;color:#64748b;font-size:11.5px;margin-top:3px}

/* site panel */
.site-panel{position:absolute;top:16px;right:84px;width:400px;max-height:calc(100% - 32px);overflow:auto;z-index:800;background:#fff;border-radius:20px;box-shadow:0 24px 60px rgba(15,23,42,.25)}
.sp-hero{position:relative;height:150px;background:linear-gradient(135deg,#1e3a8a,#0d6efd);background-size:cover;background-position:center;border-radius:20px 20px 0 0;display:flex;align-items:flex-end}
.sp-hero::before{content:'';position:absolute;inset:0;border-radius:inherit;background:linear-gradient(180deg,rgba(0,0,0,.05) 20%,rgba(2,6,23,.78))}
.sp-hero-in{position:relative;padding:14px 18px;color:#fff;width:100%}
.sp-title{color:#fff;font-weight:800;margin:6px 0 2px;font-size:17px;line-height:1.25;text-shadow:0 1px 3px rgba(0,0,0,.4)}
.sp-sub{font-size:12.5px;opacity:.85}
.sp-x{position:absolute;top:10px;right:10px;z-index:2;background:rgba(255,255,255,.9)}
.x-btn{width:32px;height:32px;border:0;border-radius:50%;background:#f1f5f9;color:#475569;display:grid;place-items:center;transition:all .15s;flex-shrink:0}
.x-btn:hover{background:#0f172a;color:#fff;transform:rotate(90deg)}
.sp-body{padding:16px 18px 18px}
.wx-card{display:flex;align-items:center;gap:12px;background:linear-gradient(135deg,#fffbeb,#fef3c7);border-radius:14px;padding:10px 14px;margin-bottom:12px;font-size:13.5px}
.wx-card.wet{background:linear-gradient(135deg,#eff6ff,#dbeafe)}
.wx-big{font-size:26px}
.text-purple{color:#7c3aed}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:6px}
.stat{background:#f8fafc;border-radius:12px;padding:8px 4px;text-align:center;display:flex;flex-direction:column;align-items:center;transition:all .15s;cursor:default}
.stat:hover{background:#eef4ff;transform:translateY(-2px)}
.stat i{color:#0d6efd;font-size:14px;margin-bottom:2px}.stat b{font-size:15px;color:#0f172a}.stat small{font-size:10.5px;color:#94a3b8}
.permit-bar{height:6px;background:#eef0f3;border-radius:99px;overflow:hidden}
.pb-fill{height:100%;border-radius:99px;transition:width .5s}
.note{border-radius:12px;padding:10px 14px;border-left:4px solid}
.note-active{background:#eff6ff;border-color:var(--c-active);color:#1e40af}
.note-warning{background:#fffbeb;border-color:var(--c-warning);color:#92400e}
.note-expired{background:#fff1f2;border-color:var(--c-expired);color:#9f1239}
.note-unknown{background:#f8fafc;border-color:var(--c-unknown);color:#475569}
.sp-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:16px}
.sp-actions .btn{border-radius:12px;font-weight:600;font-size:13.5px;padding:.55rem;transition:transform .12s,box-shadow .12s}
.sp-actions .btn:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(15,23,42,.15)}
.sp-actions .sp-wide{grid-column:1/-1}
.pill-lg{font-size:11.5px;padding:.3rem .65rem}
.pill-active{background:#dbeafe;color:#1d4ed8}.pill-warning{background:#fef3c7;color:#b45309}
.pill-expired{background:#ffe4e6;color:#be123c}.pill-unknown{background:#f1f5f9;color:#64748b}
.sp-hero .pill-active{background:#2563eb;color:#fff}.sp-hero .pill-warning{background:#f59e0b;color:#fff}
.sp-hero .pill-expired{background:#e11d48;color:#fff}.sp-hero .pill-unknown{background:#64748b;color:#fff}
.f-draft{background:#f1f5f9;color:#475569}.f-pending_review{background:#fef3c7;color:#b45309}
.f-pending_approval{background:#e0e7ff;color:#4338ca}.f-submitted{background:#dcfce7;color:#15803d}.f-returned{background:#ffe4e6;color:#be123c}
.mono{font-family:ui-monospace,monospace;font-size:12px}.tiny{font-size:10.5px}

.loading{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);z-index:900;background:#fff;padding:12px 20px;border-radius:12px;box-shadow:var(--sh);font-weight:600}

/* transitions */
.slide-enter-from,.slide-leave-to{transform:translateX(24px);opacity:0}
.slide-enter-active,.slide-leave-active{transition:all .25s cubic-bezier(.2,.8,.2,1)}
.drawer-enter-from,.drawer-leave-to{transform:translateX(-24px);opacity:0}
.drawer-enter-active,.drawer-leave-active{transition:all .25s cubic-bezier(.2,.8,.2,1)}
.pop-enter-from,.pop-leave-to{transform:scale(.95) translateX(8px);opacity:0}
.pop-enter-active,.pop-leave-active{transition:all .15s;transform-origin:top right}
@keyframes blink{50%{opacity:.35}}

/* ── tablet ── */
@media (max-width:1199.98px){
  .topbar{grid-template-columns:1fr}
  .search-box{max-width:420px}
  .seg-wrap{justify-content:flex-start}
  .drawer{top:184px}
}
/* ── mobile ── */
@media (max-width:767.98px){
  .map-wrap{height:calc(100vh - 64px - 62px);height:calc(100dvh - 64px - 62px)}
  .topbar{top:10px;left:10px;right:10px;gap:6px}
  .search-box{height:44px;margin-right:56px;max-width:none}
  .seg-wrap,.chips{overflow-x:auto;flex-wrap:nowrap;scrollbar-width:none;margin:0 -10px;padding:2px 10px 6px}
  .seg-wrap,.chips{margin-right:50px}
  .seg-wrap{justify-content:flex-start}
  .seg-wrap::-webkit-scrollbar,.chips::-webkit-scrollbar{display:none}
  .seg button{padding:.4rem .85rem;font-size:13px}
  .chip{flex-shrink:0;padding-right:12px}.chip-l{display:none}
  .chip-select{max-width:170px}
  .chip-filter{flex-shrink:0}
  .toolbar{top:10px;right:10px;gap:8px}
  .toolbar .tb-group:first-child{display:none}
  .tb{width:44px;height:42px}.tb::after{display:none}
  .tb-pop{position:fixed;left:10px;right:10px;top:auto;bottom:140px;width:auto;z-index:900}
  .legend{left:10px;right:10px;bottom:10px;max-width:none}
  .lg-body{flex-direction:column;max-height:42vh;overflow:auto}
  .lg-sec+.lg-sec{border-left:0;border-top:1px solid #eef0f3}
  .rain-bar,.rain-cols{width:100%}
  .lg-item{min-width:0}
  .drawer{top:auto;left:0;right:0;bottom:0;width:auto;height:75%;border-radius:20px 20px 0 0}
  .drawer-enter-from,.drawer-leave-to{transform:translateY(40px)}
  .site-panel{top:auto;left:0;right:0;bottom:0;width:auto;max-height:62%;border-radius:22px 22px 0 0;transition:max-height .25s}
  .site-panel.mini{max-height:118px;overflow:hidden}
  .sp-hero{border-radius:22px 22px 0 0;height:118px;cursor:pointer}
  .sp-title{font-size:15px}
  .sp-grab{position:absolute;top:8px;left:50%;transform:translateX(-50%);width:42px;height:5px;border-radius:99px;background:rgba(255,255,255,.75);z-index:2}
  .slide-enter-from,.slide-leave-to{transform:translateY(60px)}
}
</style>

<style>
/* Leaflet-rendered elements (not scoped) */
.sm-wrap{background:none;border:0}
.sm{position:relative;width:34px;height:34px;transition:transform .18s cubic-bezier(.2,.8,.2,1.4),opacity .2s;--c:#94a3b8}
.sm.st-active{--c:#2563eb}.sm.st-warning{--c:#f59e0b}.sm.st-expired{--c:#e11d48}.sm.st-unknown{--c:#94a3b8}
.sm-dot{position:absolute;inset:3px;border-radius:50%;background:var(--c);color:#fff;display:grid;place-items:center;font-size:13px;border:3px solid #fff;box-shadow:0 3px 10px rgba(15,23,42,.35)}
.sm.st-expired::before{content:'';position:absolute;inset:0;border-radius:50%;background:var(--c);opacity:.5;animation:sm-pulse 1.8s ease-out infinite}
@keyframes sm-pulse{0%{transform:scale(.8);opacity:.55}100%{transform:scale(2.1);opacity:0}}
.sm-drop,.sm-cam{position:absolute;width:16px;height:16px;border-radius:50%;display:grid;place-items:center;font-size:8px;color:#fff;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.3)}
.sm-drop{top:-4px;right:-4px;background:#0ea5e9}
.sm-cam{bottom:-4px;right:-4px;background:#0f172a;border-radius:5px}
.sm-label{position:absolute;left:50%;top:100%;transform:translate(-50%,4px);background:#fff;color:#0f172a;font-weight:800;font-size:11px;padding:1px 7px;border-radius:6px;box-shadow:0 2px 6px rgba(15,23,42,.2);white-space:nowrap;opacity:0;transition:opacity .2s;pointer-events:none}
.show-labels .sm-label{opacity:1}
.sm-wrap:hover .sm,.sm-wrap.is-hover .sm{transform:scale(1.25)}
.sm-wrap:hover .sm-label,.sm-wrap.is-hover .sm-label{opacity:1}
.sm.sel{transform:scale(1.35)}
.sm.sel .sm-dot{box-shadow:0 0 0 5px rgba(13,110,253,.45),0 3px 10px rgba(15,23,42,.35)}
.sm.sel .sm-label{opacity:1;background:#0d6efd;color:#fff}
.sm-wrap:hover .sm.sel{transform:scale(1.45)}
/* legend hover: dim everything except the hovered category */
.hl-active .sm:not(.st-active),.hl-warning .sm:not(.st-warning),.hl-expired .sm:not(.st-expired),.hl-unknown .sm:not(.st-unknown),
.hl-rain-light .sm:not(.rain-light),.hl-rain-moderate .sm:not(.rain-moderate),.hl-rain-heavy .sm:not(.rain-heavy),.hl-rain-violent .sm:not(.rain-violent){opacity:.15;transform:scale(.8)}
.hl-active .sm.st-active,.hl-warning .sm.st-warning,.hl-expired .sm.st-expired,.hl-unknown .sm.st-unknown,
.hl-rain-light .sm.rain-light,.hl-rain-moderate .sm.rain-moderate,.hl-rain-heavy .sm.rain-heavy,.hl-rain-violent .sm.rain-violent{transform:scale(1.25)}
.hl .cl{opacity:.55}

.cl{border-radius:50%;display:grid;place-items:center;box-shadow:0 4px 14px rgba(15,23,42,.3);transition:transform .18s,opacity .2s;cursor:pointer}
.cl span{width:calc(100% - 12px);height:calc(100% - 12px);border-radius:50%;background:#fff;display:grid;place-items:center;font-weight:800;font-size:15px;color:#0f172a}
.cl:hover{transform:scale(1.12)}

.tip-wrap{padding:0;border:0;border-radius:14px;box-shadow:0 14px 36px rgba(15,23,42,.25);overflow:hidden}
.tip-wrap::before{display:none}
.tip{width:250px;font-family:Inter,system-ui,sans-serif}
.tip-img{height:96px;background:#e2e8f0 center/cover}
.tip-b{padding:10px 12px}
.tip-t{font-weight:800;font-size:13px;color:#0f172a;white-space:normal;line-height:1.3;margin-bottom:6px}
.tip-r{display:flex;align-items:center;gap:8px;font-size:12px;color:#475569;margin-top:4px;white-space:normal}
.tip-m i{width:14px;color:#94a3b8}
.tip-hint{font-size:10.5px;color:#0d6efd;font-weight:600;margin-top:8px}
.tip .pill-active{background:#dbeafe;color:#1d4ed8}.tip .pill-warning{background:#fef3c7;color:#b45309}
.tip .pill-expired{background:#ffe4e6;color:#be123c}.tip .pill-unknown{background:#f1f5f9;color:#64748b}
.tip-mini{border:0;border-radius:10px;box-shadow:0 8px 20px rgba(15,23,42,.2);font-family:Inter,system-ui,sans-serif}

.pp{width:22px;height:22px;border-radius:50%;background:#db2777;color:#fff;display:grid;place-items:center;font-size:9px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3);transition:transform .15s}
.pp:hover{transform:scale(1.3)}
.cam{width:26px;height:26px;border-radius:8px;background:#0f172a;color:#fff;display:grid;place-items:center;font-size:11px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35)}
.pp-wrap .leaflet-popup-content-wrapper{border-radius:14px;padding:0;overflow:hidden}
.pp-wrap .leaflet-popup-content{margin:0;width:240px!important}
.pp-pop img{width:100%;height:150px;object-fit:cover;display:block}
.pp-pop b,.pp-pop small{display:block;padding:0 12px}.pp-pop b{padding-top:8px}.pp-pop small{color:#94a3b8;padding-bottom:10px}

.base-dark .leaflet-control-attribution{background:rgba(15,23,42,.6);color:#cbd5e1}
.base-dark .leaflet-control-attribution a{color:#93c5fd}
.leaflet-control-attribution{font-size:10px;border-radius:8px 0 0 0}
@media (max-width:767.98px){ .leaflet-control-attribution{display:none} }
</style>
