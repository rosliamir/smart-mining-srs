<template>
  <div class="ess-page">
    <div class="head">
      <span class="ic lg" :style="{ background: T.bg, color: T.color }"><i :class="T.icon"></i></span>
      <div class="flex-grow-1 min0"><h1 class="ess-h1">{{ T.title }}</h1><p class="ess-sub">{{ T.sub }}</p></div>
      <button v-if="type" class="btn-pri" @click="openForm(type)"><i class="fa-solid fa-plus me-2"></i>Mohon {{ T.short }}</button>
      <div v-else class="d-flex gap-2 flex-wrap">
        <button v-for="(t, k) in TYPE" :key="k" class="btn-soft" @click="openForm(k)"><i :class="t.icon" :style="{ color: t.color }" class="me-1"></i>{{ t.label }}</button>
      </div>
    </div>

    <!-- leave balances -->
    <div v-if="type === 'leave'" class="bal-grid">
      <button v-for="b in balances" :key="b.code" class="bal ess-card lift" @click="openForm('leave', { leave_type: b.code })">
        <span class="ic" :style="{ background: b.color + '1f', color: b.color }"><i :class="b.icon"></i></span>
        <div class="min0 text-start flex-grow-1"><b>{{ b.name_ms }}</b>
          <small v-if="b.entitled !== null">{{ b.used }} diguna · {{ b.pending }} menunggu</small><small v-else>{{ b.uses === 'annual' && b.code === 'emergency' ? 'Ditolak dari cuti tahunan' : 'Tiada had baki' }}</small>
          <div v-if="b.entitled" class="bar"><div :style="{ width: (b.used / b.entitled * 100) + '%', background: b.color }"></div><div :style="{ width: (b.pending / b.entitled * 100) + '%', background: b.color, opacity: .35 }"></div></div></div>
        <span v-if="b.entitled !== null" class="big" :style="{ color: b.color }">{{ b.available }}<small>/{{ b.entitled }}</small></span>
      </button>
    </div>

    <!-- type info strip -->
    <div v-if="info.length" class="info">
      <div v-for="x in info" :key="x.l" class="ess-card inf"><span class="ic sm" :class="x.c"><i :class="x.i"></i></span><div><b>{{ x.v }}</b><small>{{ x.l }}</small></div></div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3 mb-2 flex-wrap gap-2">
      <div class="ess-tabs">
        <button v-for="s in TABS" :key="s.k" :class="{ on: tab === s.k }" @click="tab = s.k"><i :class="s.i"></i>{{ s.l }} <span class="muted">{{ count(s.k) }}</span></button>
      </div>
      <span v-if="!type" class="tiny muted">{{ rows.length }} permohonan</span>
    </div>
    <div class="list">
      <RequestCard v-for="r in shown" :key="r.id" :r="r" @cancel="cancel" />
      <div v-if="!shown.length && !loading" class="empty ess-card"><i :class="T.icon"></i>Tiada permohonan {{ tab !== 'all' ? 'dalam kategori ini' : 'lagi' }}.
        <div v-if="type" class="mt-2"><button class="btn-pri" @click="openForm(type)">Mohon sekarang</button></div></div>
      <div v-if="loading" class="empty"><span class="spinner-border spinner-border-sm"></span></div>
    </div>
    <div v-if="msg" class="ok mt-2">{{ msg }}</div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { ess, essState, refreshHome, openForm, TYPE as TT } from '../../services/ess'
import RequestCard from '../../components/ess/RequestCard.vue'

const TYPE = TT
const route = useRoute()
const type = computed(() => route.meta.type || null)
const META = {
  leave: { title: 'Cuti', short: 'cuti', sub: 'Baki, permohonan dan status kelulusan cuti anda' },
  overtime: { title: 'Kerja Lebih Masa (OT)', short: 'OT', sub: 'Kadar 1.5x hari bekerja · 2.0x hari rehat · 3.0x cuti umum · had 104 jam sebulan' },
  timeoff: { title: 'Time-off (keluar sementara)', short: 'time-off', sub: 'Maksimum 4 jam setiap slip dan 2 slip sebulan' },
  claim: { title: 'Tuntutan', short: 'tuntutan', sub: 'Perbatuan, perjalanan, makan, perubatan — hantar dalam 30 hari dengan resit' },
}
const T = computed(() => type.value ? { ...TYPE[type.value], ...META[type.value] } : { icon: 'fa-solid fa-folder-open', color: '#475569', bg: '#f1f5f9', title: 'Semua Permohonan', sub: 'Cuti, OT, time-off dan tuntutan anda di satu tempat' })
const TABS = [{ k: 'all', l: 'Semua', i: 'fa-solid fa-layer-group' }, { k: 'pending', l: 'Dalam proses', i: 'fa-solid fa-hourglass-half' },
  { k: 'approved', l: 'Lulus', i: 'fa-solid fa-circle-check' }, { k: 'rejected', l: 'Ditolak', i: 'fa-solid fa-circle-xmark' }, { k: 'cancelled', l: 'Batal', i: 'fa-solid fa-ban' }]
const rows = ref([]), balances = ref([]), tab = ref('all'), loading = ref(false), msg = ref('')
const count = k => k === 'all' ? rows.value.length : rows.value.filter(r => r.status === k).length
const shown = computed(() => rows.value.filter(r => tab.value === 'all' || r.status === tab.value))
const thisMonth = r => String(r.start_date).slice(0, 7) === new Date().toLocaleDateString('en-CA').slice(0, 7)
const info = computed(() => {
  const live = rows.value.filter(r => ['pending', 'approved'].includes(r.status))
  if (type.value === 'overtime') {
    const m = live.filter(thisMonth), h = m.reduce((s, r) => s + +r.quantity, 0)
    return [{ l: 'Jam OT bulan ini', v: `${h} / 104`, i: 'fa-solid fa-gauge-high', c: h > 83 ? 'ic-rose' : 'ic-violet' },
      { l: 'Hari bekerja (1.5x)', v: m.filter(r => r.details?.ot_type === 'normal').reduce((s, r) => s + +r.quantity, 0) + ' j', i: 'fa-solid fa-briefcase', c: 'ic-blue' },
      { l: 'Hari rehat (2.0x)', v: m.filter(r => r.details?.ot_type === 'rest').reduce((s, r) => s + +r.quantity, 0) + ' j', i: 'fa-solid fa-mug-hot', c: 'ic-teal' },
      { l: 'Cuti umum (3.0x)', v: m.filter(r => r.details?.ot_type === 'holiday').reduce((s, r) => s + +r.quantity, 0) + ' j', i: 'fa-solid fa-star', c: 'ic-rose' }]
  }
  if (type.value === 'timeoff') {
    const m = live.filter(thisMonth)
    return [{ l: 'Slip bulan ini', v: `${m.length} / 2`, i: 'fa-solid fa-ticket', c: m.length >= 2 ? 'ic-rose' : 'ic-teal' },
      { l: 'Jam bulan ini', v: m.reduce((s, r) => s + +r.quantity, 0) + ' j', i: 'fa-solid fa-hourglass-half', c: 'ic-cyan' },
      { l: 'Had setiap slip', v: '4 jam', i: 'fa-solid fa-ruler', c: 'ic-slate' }]
  }
  if (type.value === 'claim') {
    const rm = xs => 'RM ' + xs.reduce((s, r) => s + +r.quantity, 0).toLocaleString('en-MY', { minimumFractionDigits: 2 })
    return [{ l: 'Menunggu kelulusan', v: rm(rows.value.filter(r => r.status === 'pending')), i: 'fa-solid fa-hourglass-half', c: 'ic-amber' },
      { l: 'Diluluskan tahun ini', v: rm(rows.value.filter(r => r.status === 'approved' && String(r.start_date).startsWith(String(new Date().getFullYear())))), i: 'fa-solid fa-sack-dollar', c: 'ic-green' },
      { l: 'Tempoh hantar', v: '30 hari', i: 'fa-solid fa-calendar-xmark', c: 'ic-slate' }]
  }
  return []
})
async function load() {
  loading.value = true
  rows.value = (await ess.requests(type.value) || [])
  if (type.value === 'leave') balances.value = await ess.balances(new Date().getFullYear()) || []
  loading.value = false
}
async function cancel(r) {
  if (!confirm('Batalkan permohonan ini?')) return
  try { await ess.cancel(r.id); msg.value = 'Permohonan dibatalkan.'; setTimeout(() => msg.value = '', 3000); load(); refreshHome() } catch (e) { alert(e.message) }
}
const onSubmitted = () => load()
watch(type, () => { tab.value = 'all'; load() })
onMounted(() => { load(); window.addEventListener('ess-submitted', onSubmitted) })
onBeforeUnmount(() => window.removeEventListener('ess-submitted', onSubmitted))
</script>

<style scoped>
.head{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.bal-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.bal{display:flex;gap:12px;align-items:center;padding:14px;cursor:pointer;text-align:left}
.bal b{display:block;font-size:13.5px}.bal small{display:block;color:var(--muted);font-size:11.5px}
.big{font-size:26px;font-weight:800;white-space:nowrap}.big small{font-size:13px;color:#94a3b8}
.bar{display:flex;height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;margin-top:6px}.bar div{height:100%}
.info{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.inf{display:flex;gap:10px;align-items:center;padding:12px 14px}.inf b{display:block;font-size:17px}.inf small{color:var(--muted);font-size:11.5px}
.list{display:flex;flex-direction:column;gap:10px}
@media (max-width:1199.98px){ .bal-grid{grid-template-columns:repeat(3,1fr)} }
@media (max-width:767.98px){ .bal-grid{grid-template-columns:1fr 1fr} .info{grid-template-columns:1fr 1fr} .head .btn-pri{width:100%} }
</style>
