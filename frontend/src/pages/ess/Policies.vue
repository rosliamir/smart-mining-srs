<template>
  <div class="ess-page">
    <section class="hero2">
      <div class="min0">
        <h1 class="ess-h1 text-white">Polisi HR & Tanya Apa Sahaja</h1>
        <p class="mb-3 op">Cuti, kerja lebih masa, tuntutan, gaji, waktu kerja dan kod etika — tanya dalam bahasa anda sendiri.</p>
        <form class="askbar" @submit.prevent="ask(q)">
          <i class="fa-solid fa-wand-magic-sparkles"></i><input v-model="q" placeholder="cth. berapa hari cuti sakit saya? bila gaji dibayar?" />
          <button class="btn-pri" :disabled="!q.trim()">Tanya</button>
        </form>
        <div class="sugs"><button v-for="s in SUG" :key="s" @click="ask(s)">{{ s }}</button></div>
      </div>
      <i class="fa-solid fa-scale-balanced deco"></i>
    </section>

    <div class="pgrid mt-3">
      <button v-for="p in policies" :key="p.id" class="ess-card pc lift" :class="{ on: sel?.id === p.id }" @click="open(p)">
        <span class="ic lg" :class="iconOf(p).c"><i :class="iconOf(p).i"></i></span>
        <b>{{ p.title.split(' / ')[0] }}</b><small>{{ p.title.split(' / ')[1] || '' }}</small>
        <span v-if="/TEMPLATE/.test(p.excerpt)" class="tpl">Templat — sahkan dengan HR</span>
      </button>
    </div>

    <transition name="fade">
      <section v-if="sel" class="ess-card mt-3" ref="detail">
        <div class="d-flex align-items-start gap-3 flex-wrap">
          <span class="ic lg" :class="iconOf(sel).c"><i :class="iconOf(sel).i"></i></span>
          <div class="flex-grow-1 min0"><h5 class="fw-bold mb-0">{{ sel.title }}</h5><small class="muted">KB#{{ sel.id }} · dikemas kini {{ d3(sel.updated_at) }}</small></div>
          <button class="btn-soft" @click="ask(`Terangkan ringkas ${sel.title.split(' / ')[0]} untuk saya`)"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Ringkaskan untuk saya</button>
        </div>
        <div class="law"><i class="fa-solid fa-gavel"></i>Angka berkanun mengikut Akta Kerja 1955 (pindaan 2022). Bahagian bertanda <b>TEMPLATE</b> ialah peraturan contoh yang perlu disahkan oleh HR KSSB.</div>
        <div class="pbody">
          <p v-for="(l, i) in lines" :key="i" :class="{ li: l.startsWith('- '), st: l.startsWith('STATUS') }" v-html="fmt(l)"></p>
        </div>
      </section>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { apiGet } from '../../services/api'
import { d3 } from '../../services/ess'
import { kbUi } from '../../services/kbStore'

const SUG = ['Baki cuti tahunan saya berapa?', 'Berapa kadar OT pada cuti umum?', 'Bila gaji dibayar?', 'Cuti kecemasan potong cuti tahunan ke?', 'Berapa had time-off sebulan?', 'Apa yang perlu untuk tuntutan perbatuan?']
const ICONS = { 'hr-leave': ['fa-solid fa-umbrella-beach', 'ic-cyan'], 'hr-overtime': ['fa-solid fa-business-time', 'ic-violet'], 'hr-timeoff': ['fa-solid fa-person-walking-arrow-right', 'ic-teal'],
  'hr-attendance': ['fa-solid fa-fingerprint', 'ic-blue'], 'hr-claims': ['fa-solid fa-coins', 'ic-green'], 'hr-conduct': ['fa-solid fa-helmet-safety', 'ic-orange'] }
const iconOf = p => { const x = ICONS[p.slug] || ['fa-solid fa-book', 'ic-slate']; return { i: x[0], c: x[1] } }
const policies = ref([]), sel = ref(null), q = ref(''), detail = ref(null)
const lines = computed(() => (sel.value?.content || '').split('\n').filter(l => l.trim()))
const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;')
const fmt = l => esc(l.replace(/^- /, '')).replace(/\(TEMPLATE\)|TEMPLATE/g, '<span class="tag">TEMPLATE</span>').replace(/(\d+(\.\d+)?\s?(x|days|hours|hari|jam)\b)/gi, '<b>$1</b>')
async function open(p) { sel.value = await apiGet(`kb/${p.id}`); await nextTick(); detail.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }
function ask(text) { window.dispatchEvent(new CustomEvent('ask-send', { detail: text })); q.value = '' }
onMounted(async () => {
  const r = await apiGet('kb?page=hr'); policies.value = (r?.entries || []).sort((a, b) => (a.slug || '').localeCompare(b.slug || ''))
})
</script>

<style scoped>
.hero2{position:relative;overflow:hidden;border-radius:26px;padding:28px;background:linear-gradient(135deg,#831843,#be185d 45%,#7c3aed);color:#fff;box-shadow:0 22px 48px rgba(190,24,93,.3)}
.op{opacity:.85}
.deco{position:absolute;right:-10px;bottom:-30px;font-size:180px;opacity:.12}
.askbar{display:flex;gap:10px;align-items:center;background:#fff;border-radius:18px;padding:6px 6px 6px 16px;max-width:720px;position:relative;z-index:1;box-shadow:0 12px 30px rgba(0,0,0,.2)}
.askbar i{color:#a855f7}.askbar input{flex:1;border:0;outline:0;font-size:15px;min-width:0;color:#0f172a}
.sugs{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;position:relative;z-index:1}
.sugs button{border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.14);color:#fff;border-radius:999px;padding:5px 12px;font-size:12.5px;font-weight:600;transition:background .15s}
.sugs button:hover{background:rgba(255,255,255,.3)}
.pgrid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px}
.pc{display:flex;flex-direction:column;align-items:flex-start;gap:6px;text-align:left;cursor:pointer}
.pc b{font-size:14px;line-height:1.2}.pc small{color:var(--muted);font-size:12px}
.pc.on{border-color:#a5b4fc;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
.tpl{font-size:10.5px;font-weight:700;background:#fef3c7;color:#b45309;border-radius:6px;padding:1px 6px;margin-top:auto}
.law{margin-top:14px;font-size:12.5px;background:#eef2ff;color:#3730a3;border-radius:12px;padding:8px 12px;display:flex;gap:8px}
.pbody{margin-top:12px;font-size:14px;line-height:1.7;color:#1e293b}
.pbody p{margin:0 0 8px}.pbody p.li{padding-left:18px;position:relative}.pbody p.li::before{content:'•';position:absolute;left:4px;color:#a855f7;font-weight:800}
.pbody p.st{font-size:12.5px;color:var(--muted)}
.pbody :deep(.tag){font-size:10px;font-weight:800;background:#fef3c7;color:#b45309;border-radius:5px;padding:0 5px}
.fade-enter-from{opacity:0;transform:translateY(8px)}.fade-enter-active{transition:all .25s}
@media (max-width:1199.98px){ .pgrid{grid-template-columns:repeat(3,1fr)} }
@media (max-width:767.98px){ .pgrid{grid-template-columns:repeat(2,1fr)} .hero2{padding:20px} .askbar .btn-pri{padding:.5rem .8rem} }
</style>
