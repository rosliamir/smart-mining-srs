<template>
  <div class="ask-root">
    <!-- launcher -->
    <transition name="pop">
      <button v-if="!kbUi.askOpen" class="ask-fab" @click="kbUi.askOpen = true" title="Ask anything about this page">
        <i class="fa-solid fa-wand-magic-sparkles"></i><span>Ask</span>
      </button>
    </transition>

    <!-- panel -->
    <transition name="slide">
      <div v-if="kbUi.askOpen" class="ask">
        <div class="ask-head">
          <span class="ask-ic"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
          <div class="flex-grow-1 min0">
            <b>Ask the knowledge base</b>
            <div class="tiny">Page: <span class="pg">{{ page }}</span> · answers come from the KB <a href="#" @click.prevent="openKb()">view KB</a></div>
          </div>
          <button class="hbtn" title="New conversation" @click="reset"><i class="fa-solid fa-rotate-left"></i></button>
          <button class="hbtn" title="Close" @click="kbUi.askOpen = false"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div ref="scroller" class="ask-body" @click="onCite">
          <div v-if="!msgs.length" class="welcome">
            <div class="w-ic"><i class="fa-solid fa-comments"></i></div>
            <p>Tanya apa sahaja tentang <b>{{ PAGE_LABEL[page] }}</b> — kenapa sesuatu berlaku, dan apa tindakan perlu dibuat.</p>
            <div class="sugs">
              <button v-for="s in suggestions" :key="s" class="sug" @click="send(s)"><i class="fa-solid fa-arrow-right"></i>{{ s }}</button>
            </div>
          </div>
          <div v-for="(m, i) in msgs" :key="i" class="msg" :class="m.role">
            <div v-if="m.role === 'user'" class="bubble">{{ m.content }}</div>
            <div v-else class="bubble bot">
              <div v-if="m.note" class="note"><i class="fa-solid fa-circle-info me-1"></i>{{ m.note }}</div>
              <div class="mdx" v-html="md(m.content)"></div>
              <div v-if="m.sources?.length" class="srcs">
                <span class="tiny">Sources:</span>
                <button v-for="s in m.sources.slice(0, 5)" :key="s.id" class="srcchip" @click="s.id && openKb(s.id)" :title="s.title">{{ s.id ? 'KB#' + s.id : '🔒' }} {{ short(s.title) }}</button>
              </div>
              <div class="mode tiny"><i class="fa-solid" :class="m.mode === 'ai' ? 'fa-wand-magic-sparkles' : 'fa-magnifying-glass'"></i>{{ m.mode === 'ai' ? 'AI answer (Claude)' : 'Search answer' }}</div>
            </div>
          </div>
          <div v-if="busy" class="msg assistant"><div class="bubble bot typing"><span></span><span></span><span></span></div></div>
        </div>

        <form class="ask-input" @submit.prevent="send()">
          <textarea ref="inputEl" v-model="draft" rows="1" placeholder="Tanya soalan… e.g. kenapa jualan turun?" @keydown.enter.exact.prevent="send()" @input="grow"></textarea>
          <button class="send" :disabled="!draft.trim() || busy"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { apiPost } from '../services/api'
import { kbUi, openKb, md } from '../services/kbStore'

const PAGE_LABEL = { map: 'Map', dashboard: 'Dashboard', monitoring: 'Monitoring', attendance: 'Attendance', general: 'the system', ess: 'HR, polisi & data anda' }
const SUGGEST = {
  dashboard: ['Kenapa jualan bulan ini turun?', 'Tapak mana paling berisiko dan kenapa?', 'Apa 3 tindakan paling penting minggu ini?', 'Which entries were excluded as suspicious sales?'],
  monitoring: ['Tapak mana tiada dokumen OMS atau LUAS?', 'Kenapa doket selalu dibatalkan?', 'Which sites had e-Docket downtime?', 'Berapa laporan pemilik tanah belum lulus?'],
  attendance: ['Siapa staf paling kerap lewat?', 'Hari apa paling ramai lewat?', 'Which office is least punctual?', 'Rekod kehadiran mana perlu dibetulkan?'],
  map: ['Tapak mana permit sudah tamat?', 'Permit mana akan tamat dalam 60 hari?', 'Which sites have CCTV cameras?', 'Senaraikan tapak di Wilayah Utara yang berisiko'],
  ess: ['Baki cuti tahunan saya berapa?', 'Berapa kadar OT pada cuti umum?', 'Bila gaji dibayar?', 'Berapa jam OT saya bulan ini?', 'Cuti sakit perlu MC ke?'],
  general: ['Apa maksud skor kesihatan tapak?', 'How does the F001 approval flow work?', 'Apa maksud kod cuaca X dan /?'],
}
const route = useRoute()
const page = computed(() => { const p = route.path.split('/')[1]; return PAGE_LABEL[p] && p !== 'general' ? p : (p === 'site' ? 'map' : 'general') })   // /ess/* -> 'ess'
const suggestions = computed(() => SUGGEST[page.value] || SUGGEST.general)

const load = () => { try { return JSON.parse(sessionStorage.getItem('sm_ask') || '[]') } catch { return [] } }
const msgs = ref(load()), draft = ref(''), busy = ref(false), scroller = ref(null), inputEl = ref(null)
watch(msgs, v => { try { sessionStorage.setItem('sm_ask', JSON.stringify(v.slice(-30))) } catch {} }, { deep: true })

const short = t => t.length > 26 ? t.slice(0, 25) + '…' : t
function grow() { const el = inputEl.value; if (!el) return; el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 120) + 'px' }
async function scrollDown() { await nextTick(); scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: 'smooth' }) }
function reset() { msgs.value = [] }
function onCite(e) { const id = e.target?.dataset?.kb; if (id && +id) openKb(+id) }

async function send(text) {
  const q = (text ?? draft.value).trim(); if (!q || busy.value) return
  const history = msgs.value.slice(-8).map(m => ({ role: m.role, content: m.content }))
  msgs.value.push({ role: 'user', content: q }); draft.value = ''; grow(); busy.value = true; scrollDown()
  try {
    const r = await apiPost('ask', { question: q, page: page.value, history })
    msgs.value.push({ role: 'assistant', content: r.answer, sources: r.sources, mode: r.mode, note: r.note })
  } catch (e) {
    msgs.value.push({ role: 'assistant', content: `Maaf, tidak dapat menjawab sekarang: ${e.message}`, mode: 'error' })
  } finally { busy.value = false; scrollDown() }
}

const onPrefill = e => { draft.value = e.detail || ''; nextTick(() => { grow(); inputEl.value?.focus() }) }
const onSend = e => { kbUi.askOpen = true; if (e.detail) send(e.detail) }
watch(() => kbUi.askOpen, v => { if (v) { scrollDown(); nextTick(() => inputEl.value?.focus()) } })
onMounted(() => { window.addEventListener('ask-prefill', onPrefill); window.addEventListener('ask-send', onSend) })
onBeforeUnmount(() => { window.removeEventListener('ask-prefill', onPrefill); window.removeEventListener('ask-send', onSend) })
</script>

<style scoped>
.ask-fab{position:fixed;right:24px;bottom:24px;z-index:1600;border:0;border-radius:999px;padding:12px 18px 12px 14px;display:flex;gap:8px;align-items:center;
  background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;font-weight:700;font-size:14px;box-shadow:0 14px 30px rgba(79,70,229,.4);transition:transform .2s,box-shadow .2s}
.ask-fab i{font-size:16px}
.ask-fab:hover{transform:translateY(-3px) scale(1.03);box-shadow:0 18px 40px rgba(79,70,229,.5)}
.ask{position:fixed;right:24px;bottom:24px;z-index:1700;width:420px;height:min(640px,calc(100vh - 110px));background:#fff;border-radius:22px;
  box-shadow:0 30px 80px rgba(15,23,42,.3);display:flex;flex-direction:column;overflow:hidden;border:1px solid #eef0f3}
.ask-head{display:flex;align-items:center;gap:10px;padding:14px 14px 12px 16px;background:linear-gradient(135deg,#0f172a,#312e81);color:#fff}
.ask-ic{width:36px;height:36px;border-radius:12px;background:rgba(255,255,255,.15);display:grid;place-items:center;flex-shrink:0}
.ask-head .tiny{color:#c7d2fe}.ask-head a{color:#fff;text-decoration:underline}
.pg{background:rgba(255,255,255,.18);border-radius:5px;padding:0 6px;text-transform:capitalize}
.min0{min-width:0}
.hbtn{width:32px;height:32px;border:0;border-radius:10px;background:rgba(255,255,255,.12);color:#fff;transition:background .15s}
.hbtn:hover{background:rgba(255,255,255,.25)}
.tiny{font-size:11.5px}
.ask-body{flex:1;overflow:auto;padding:16px;background:#f8fafc;display:flex;flex-direction:column;gap:12px}
.welcome{text-align:center;color:#475569;font-size:13.5px;margin:auto 0}
.w-ic{width:56px;height:56px;border-radius:18px;margin:0 auto 10px;background:#eef2ff;color:#4f46e5;display:grid;place-items:center;font-size:22px}
.sugs{display:flex;flex-direction:column;gap:8px;margin-top:12px;text-align:left}
.sug{border:1px solid #e0e7ff;background:#fff;border-radius:12px;padding:9px 12px;font-size:13px;color:#3730a3;display:flex;gap:8px;align-items:baseline;transition:all .15s}
.sug i{font-size:11px;color:#818cf8}
.sug:hover{background:#eef2ff;transform:translateX(3px)}
.msg{display:flex}.msg.user{justify-content:flex-end}
.bubble{max-width:88%;border-radius:16px;padding:10px 13px;font-size:13.5px;line-height:1.5}
.msg.user .bubble{background:#2563eb;color:#fff;border-bottom-right-radius:4px;white-space:pre-wrap}
.bubble.bot{background:#fff;border:1px solid #eef0f3;border-bottom-left-radius:4px;color:#1f2937;box-shadow:0 2px 8px rgba(15,23,42,.04)}
.mdx :deep(p){margin:0 0 6px}.mdx :deep(ul){margin:0 0 6px;padding-left:18px}.mdx :deep(li){margin:2px 0}
.mdx :deep(.kb-cite){display:inline-block;font-size:10.5px;font-weight:700;background:#eef2ff;color:#4338ca;border-radius:5px;padding:0 5px;cursor:pointer;text-decoration:none}
.mdx :deep(.kb-cite:hover){background:#4338ca;color:#fff}
.note{font-size:11.5px;background:#fffbeb;color:#92400e;border-radius:8px;padding:5px 8px;margin-bottom:8px}
.srcs{display:flex;flex-wrap:wrap;gap:5px;align-items:center;margin-top:8px;padding-top:8px;border-top:1px dashed #e2e8f0}
.srcchip{border:0;background:#f1f5f9;color:#334155;border-radius:999px;font-size:11px;padding:2px 8px;transition:background .15s}
.srcchip:hover{background:#e0e7ff;color:#3730a3}
.mode{color:#94a3b8;margin-top:6px;display:flex;gap:5px;align-items:center}
.typing{display:flex;gap:4px;padding:14px}
.typing span{width:7px;height:7px;border-radius:50%;background:#a5b4fc;animation:bounce 1s infinite}
.typing span:nth-child(2){animation-delay:.15s}.typing span:nth-child(3){animation-delay:.3s}
@keyframes bounce{0%,80%,100%{transform:translateY(0);opacity:.5}40%{transform:translateY(-5px);opacity:1}}
.ask-input{display:flex;gap:8px;align-items:flex-end;padding:12px;border-top:1px solid #eef0f3;background:#fff}
.ask-input textarea{flex:1;resize:none;border:1px solid #e2e8f0;border-radius:14px;padding:9px 12px;font-size:14px;outline:0;max-height:120px;transition:border-color .15s,box-shadow .15s}
.ask-input textarea:focus{border-color:#818cf8;box-shadow:0 0 0 3px rgba(129,140,248,.2)}
.send{width:42px;height:42px;border:0;border-radius:14px;background:#4f46e5;color:#fff;flex-shrink:0;transition:transform .15s,opacity .15s}
.send:disabled{opacity:.4}.send:not(:disabled):hover{transform:scale(1.06)}
.slide-enter-from,.slide-leave-to{opacity:0;transform:translateY(20px) scale(.97)}
.slide-enter-active,.slide-leave-active{transition:all .22s cubic-bezier(.2,.8,.2,1);transform-origin:bottom right}
.pop-enter-from,.pop-leave-to{opacity:0;transform:scale(.8)}.pop-enter-active,.pop-leave-active{transition:all .18s}
@media (max-width:991.98px){ .ask-fab{bottom:80px;right:16px} }
@media (max-width:767.98px){
  .ask-fab span{display:none}.ask-fab{padding:14px;border-radius:50%}
  .ask{right:0;left:0;bottom:0;width:auto;height:88vh;border-radius:22px 22px 0 0;z-index:2100}
}
</style>
