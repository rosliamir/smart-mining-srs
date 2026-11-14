<template>
  <div class="ess-page">
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
      <span class="ic lg ic-rose"><i class="fa-solid fa-bell"></i></span>
      <div class="flex-grow-1"><h1 class="ess-h1">Notifikasi</h1><p class="ess-sub">{{ unread }} belum dibaca</p></div>
      <button class="btn-soft" :disabled="!unread" @click="readAll"><i class="fa-solid fa-check-double me-1"></i>Tandakan semua dibaca</button>
    </div>
    <div class="ess-tabs mb-3"><button v-for="f in F" :key="f.k" :class="{ on: flt === f.k }" @click="flt = f.k"><i :class="f.i"></i>{{ f.l }}</button></div>
    <div class="ess-card p-0 overflow-hidden">
      <template v-for="(g, day) in groups" :key="day">
        <div class="day">{{ day }}</div>
        <button v-for="n in g" :key="n.id" class="nt" :class="{ unread: !n.read_at }" @click="go(n)">
          <span class="ic" :class="col(n.type)"><i :class="n.icon || 'fa-solid fa-bell'"></i></span>
          <span class="min0 flex-grow-1 text-start"><b>{{ n.title }}</b><small>{{ n.body }}</small></span>
          <small class="muted text-nowrap">{{ ago(n.created_at) }}</small><span v-if="!n.read_at" class="ud"></span>
        </button>
      </template>
      <div v-if="!list.length" class="empty"><i class="fa-regular fa-bell-slash"></i>Tiada notifikasi</div>
    </div>
  </div>
</template>
<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ess, refreshHome, ago, d3 } from '../../services/ess'
const router = useRouter()
const F = [{ k: 'all', l: 'Semua', i: 'fa-solid fa-layer-group' }, { k: 'unread', l: 'Belum dibaca', i: 'fa-solid fa-envelope' }, { k: 'approval', l: 'Kelulusan', i: 'fa-solid fa-stamp' },
  { k: 'status', l: 'Status permohonan', i: 'fa-solid fa-circle-check' }, { k: 'memo', l: 'Memo', i: 'fa-solid fa-bullhorn' }]
const all = ref([]), flt = ref('all')
const unread = computed(() => all.value.filter(n => !n.read_at).length)
const list = computed(() => all.value.filter(n => flt.value === 'all' || (flt.value === 'unread' ? !n.read_at : flt.value === 'status' ? ['approved', 'rejected', 'progress'].includes(n.type) : n.type === flt.value)))
const groups = computed(() => list.value.reduce((g, n) => { const k = d3(n.created_at); (g[k] ||= []).push(n); return g }, {}))
const col = t => ({ approved: 'ic-green', rejected: 'ic-rose', approval: 'ic-orange', memo: 'ic-amber', progress: 'ic-blue' }[t] || 'ic-slate')
async function load() { all.value = await ess.notifications() || [] }
async function go(n) { if (!n.read_at) { await ess.readNotifications(n.id); refreshHome(); load() } if (n.link) router.push(n.link) }
async function readAll() { await ess.readNotifications(); refreshHome(); load() }
onMounted(load)
</script>
<style scoped>
.day{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;padding:14px 18px 6px;background:#fafbff}
.nt{position:relative;display:flex;gap:12px;align-items:center;width:100%;border:0;border-bottom:1px solid var(--line);background:#fff;padding:12px 18px;transition:background .12s}
.nt:hover{background:#f8fafc}.nt.unread{background:#f7f8ff}
.nt b{display:block;font-size:13.5px}.nt small{display:block;color:var(--muted);font-size:12.5px}
.ud{width:8px;height:8px;border-radius:50%;background:#6366f1;flex-shrink:0}
</style>
