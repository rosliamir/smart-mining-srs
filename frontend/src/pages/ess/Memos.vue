<template>
  <div class="ess-page">
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
      <span class="ic lg ic-amber"><i class="fa-solid fa-bullhorn"></i></span>
      <div class="flex-grow-1"><h1 class="ess-h1">Memo Syarikat</h1><p class="ess-sub">Pengumuman rasmi daripada pengurusan dan HR</p></div>
      <button v-if="isHr" class="btn-pri" @click="compose = true; sel = null"><i class="fa-solid fa-pen-to-square me-2"></i>Terbit memo</button>
    </div>
    <div class="ess-tabs mb-3">
      <button v-for="c in CATS" :key="c.k" :class="{ on: cat === c.k }" @click="cat = c.k"><i :class="c.i"></i>{{ c.l }}</button>
    </div>
    <div class="wrap">
      <div class="lst" :class="{ hide: (sel || compose) && narrow }">
        <button v-for="m in shown" :key="m.id" class="mi lift" :class="{ on: sel?.id === m.id, unread: !m.read_at }" @click="open(m.id)">
          <span class="ic" :class="catOf(m.category).c"><i :class="catOf(m.category).i"></i></span>
          <span class="min0 flex-grow-1 text-start">
            <span class="d-flex gap-2 align-items-center"><b class="ell">{{ m.pinned ? '📌 ' : '' }}{{ m.title }}</b><span v-if="m.priority !== 'normal'" class="pr" :class="m.priority">{{ m.priority === 'urgent' ? 'Segera' : 'Penting' }}</span></span>
            <small class="ex">{{ m.excerpt }}</small>
            <small class="meta">{{ d3(m.published_at) }} · {{ m.author || 'HR' }}<span v-if="m.requires_ack"> · <i class="fa-solid" :class="m.acked_at ? 'fa-circle-check text-success' : 'fa-signature'"></i> {{ m.acked_at ? 'Diakui' : 'Perlu pengakuan' }}</span>
              <span v-if="isHr"> · 👁 {{ m.reads }}/{{ m.audience }}</span></small>
          </span>
          <span v-if="!m.read_at" class="ud"></span>
        </button>
        <div v-if="!shown.length" class="empty ess-card"><i class="fa-regular fa-envelope-open"></i>Tiada memo</div>
      </div>

      <div class="det ess-card" :class="{ hide: !(sel || compose) && narrow }">
        <template v-if="compose">
          <button v-if="narrow" class="btn-soft mb-2" @click="compose = false"><i class="fa-solid fa-arrow-left"></i></button>
          <h6 class="mb-3"><i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Memo baharu</h6>
          <label class="f-lbl">Tajuk</label><input v-model="nm.title" class="f-in mb-2" placeholder="cth. Waktu operasi semasa cuti Deepavali" />
          <div class="row g-2">
            <div class="col-6"><label class="f-lbl">Kategori</label><select v-model="nm.category" class="f-in"><option v-for="c in CATS.slice(1)" :key="c.k" :value="c.k">{{ c.l }}</option></select></div>
            <div class="col-6"><label class="f-lbl">Keutamaan</label><select v-model="nm.priority" class="f-in"><option value="normal">Biasa</option><option value="important">Penting</option><option value="urgent">Segera</option></select></div>
          </div>
          <label class="f-lbl mt-2">Kandungan</label><textarea v-model="nm.body" class="f-in" rows="9"></textarea>
          <div class="d-flex gap-3 mt-2 small"><label><input type="checkbox" v-model="nm.pinned" /> Sematkan di atas</label><label><input type="checkbox" v-model="nm.requires_ack" /> Wajib diakui staf</label></div>
          <div v-if="err" class="err mt-2">{{ err }}</div>
          <div class="d-flex justify-content-end gap-2 mt-3"><button class="btn-soft" @click="compose = false">Batal</button>
            <button class="btn-pri" :disabled="!nm.title || !nm.body || busy" @click="publish"><i class="fa-solid fa-paper-plane me-1"></i>Terbit & maklumkan semua staf</button></div>
        </template>
        <template v-else-if="sel">
          <button v-if="narrow" class="btn-soft mb-2" @click="sel = null"><i class="fa-solid fa-arrow-left"></i></button>
          <div class="d-flex gap-2 align-items-center mb-1"><span class="ic sm" :class="catOf(sel.category).c"><i :class="catOf(sel.category).i"></i></span>
            <small class="muted">{{ catOf(sel.category).l }} · {{ d3(sel.published_at) }} · {{ sel.author || 'HR' }}</small></div>
          <h4 class="fw-bold">{{ sel.title }}</h4>
          <div class="body">{{ sel.body }}</div>
          <div v-if="sel.requires_ack" class="ack" :class="{ done: sel.acked_at }">
            <i class="fa-solid" :class="sel.acked_at ? 'fa-circle-check' : 'fa-signature'"></i>
            <span v-if="sel.acked_at">Anda telah mengakui memo ini pada {{ d3(sel.acked_at) }}.</span>
            <template v-else><span>Memo ini memerlukan pengakuan anda.</span><button class="btn-pri ms-auto" @click="ack">Saya sudah baca & faham</button></template>
          </div>
          <button class="btn-soft mt-3" @click="askAbout"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Tanya tentang memo ini</button>
        </template>
        <div v-else class="empty"><i class="fa-solid fa-envelope-open-text"></i>Pilih memo untuk dibaca</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { ess, essState, refreshHome, d3 } from '../../services/ess'
import { kbUi } from '../../services/kbStore'

const CATS = [{ k: 'all', l: 'Semua', i: 'fa-solid fa-layer-group', c: 'ic-slate' }, { k: 'general', l: 'Umum', i: 'fa-solid fa-circle-info', c: 'ic-blue' },
  { k: 'hr', l: 'HR', i: 'fa-solid fa-users', c: 'ic-pink' }, { k: 'finance', l: 'Kewangan', i: 'fa-solid fa-coins', c: 'ic-green' },
  { k: 'safety', l: 'Keselamatan', i: 'fa-solid fa-helmet-safety', c: 'ic-orange' }, { k: 'operations', l: 'Operasi', i: 'fa-solid fa-truck', c: 'ic-violet' },
  { k: 'it', l: 'IT', i: 'fa-solid fa-laptop', c: 'ic-cyan' }]
const catOf = k => CATS.find(c => c.k === k) || CATS[1]
const route = useRoute()
const memos = ref([]), cat = ref('all'), sel = ref(null), compose = ref(false), busy = ref(false), err = ref(''), narrow = ref(window.innerWidth < 992)
const nm = reactive({ title: '', body: '', category: 'general', priority: 'normal', pinned: false, requires_ack: false })
const isHr = computed(() => essState.home?.roles?.hr)
const shown = computed(() => memos.value.filter(m => cat.value === 'all' || m.category === cat.value))
async function load() { memos.value = await ess.memos() || [] }
async function open(id) { compose.value = false; sel.value = await ess.memo(id); load(); refreshHome() }
async function ack() { await ess.ackMemo(sel.value.id); open(sel.value.id) }
async function publish() {
  busy.value = true; err.value = ''
  try { const r = await ess.postMemo({ ...nm }); compose.value = false; Object.assign(nm, { title: '', body: '', pinned: false, requires_ack: false }); await load(); open(r.id) }
  catch (e) { err.value = e.message } finally { busy.value = false }
}
function askAbout() { kbUi.askOpen = true; window.dispatchEvent(new CustomEvent('ask-prefill', { detail: `Apa maksud memo "${sel.value.title}" untuk saya?` })) }
const onR = () => narrow.value = window.innerWidth < 992
onMounted(async () => { await load(); if (route.query.id) open(route.query.id); else if (!narrow.value && memos.value[0]) open(memos.value[0].id); window.addEventListener('resize', onR) })
onBeforeUnmount(() => window.removeEventListener('resize', onR))
</script>

<style scoped>
.wrap{display:grid;grid-template-columns:420px 1fr;gap:16px;align-items:start}
.lst{display:flex;flex-direction:column;gap:8px}
.mi{position:relative;display:flex;gap:12px;align-items:flex-start;border:1px solid var(--line);background:#fff;border-radius:16px;padding:12px}
.mi.on{border-color:#a5b4fc;box-shadow:0 0 0 3px rgba(99,102,241,.12)}.mi.unread{background:#fffdf5}
.mi b{font-size:13.5px}.ex{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:12px;color:var(--muted);margin-top:2px}
.meta{display:block;font-size:11px;color:#94a3b8;margin-top:4px}
.ell{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pr{font-size:10px;font-weight:800;border-radius:6px;padding:1px 6px}.pr.important{background:#fef3c7;color:#b45309}.pr.urgent{background:#ffe4e6;color:#be123c}
.ud{position:absolute;top:12px;right:12px;width:9px;height:9px;border-radius:50%;background:#f59e0b}
.det{min-height:420px}
.body{white-space:pre-wrap;line-height:1.7;font-size:14.5px;color:#1e293b;margin-top:10px}
.ack{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:18px;background:#fffbeb;border:1px solid #fde68a;border-radius:14px;padding:12px 14px;color:#92400e}
.ack.done{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}
@media (max-width:991.98px){ .wrap{grid-template-columns:1fr} .hide{display:none!important} }
</style>
