<template>
  <transition name="kbfade">
    <div v-if="kbUi.open" class="kb-bg" @click.self="close">
      <div class="kb">
        <!-- header -->
        <div class="kb-head">
          <span class="kb-ic"><i class="fa-solid fa-book-open"></i></span>
          <div class="flex-grow-1 min0">
            <h5 class="fw-bold mb-0">Knowledge base</h5>
            <div class="small text-muted2">{{ entries.length }} entries · the Ask assistant reads these to answer questions
              <span v-if="lastRefresh"> · snapshots updated {{ ago(lastRefresh) }}</span></div>
          </div>
          <span class="ai-badge" :class="aiEnabled ? 'on' : 'off'" :title="aiEnabled ? 'Claude writes the answers' : 'No ANTHROPIC_API_KEY — answers show the best matching entries'">
            <i class="fa-solid" :class="aiEnabled ? 'fa-wand-magic-sparkles' : 'fa-magnifying-glass'"></i>{{ aiEnabled ? 'AI answers on' : 'Search mode' }}</span>
          <button class="x-btn" @click="close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="kb-body">
          <!-- list -->
          <div class="kb-list" :class="{ hide: sel && narrow }">
            <div class="kb-tools">
              <div class="kb-search"><i class="fa-solid fa-magnifying-glass"></i><input v-model="q" placeholder="Search the knowledge base…" /></div>
              <div class="chips">
                <button v-for="p in PAGES" :key="p.k" :class="{ on: page === p.k }" @click="page = p.k">
                  <i :class="p.icon"></i>{{ p.l }} <small>{{ p.k === 'all' ? entries.length : counts[p.k] || 0 }}</small></button>
              </div>
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-fill" @click="newNote"><i class="fa-solid fa-plus me-1"></i>Add note</button>
                <button class="btn btn-sm btn-light flex-fill" :disabled="refreshing" @click="refresh">
                  <i class="fa-solid fa-rotate me-1" :class="{ 'fa-spin': refreshing }"></i>{{ refreshing ? 'Updating…' : 'Update snapshots' }}</button>
              </div>
            </div>
            <div class="kb-items">
              <button v-for="e in shown" :key="e.id" class="kb-item" :class="{ on: sel?.id === e.id }" @click="openEntry(e.id)">
                <span class="src" :class="`src-${e.source}`" :title="SRC[e.source].t"><i :class="SRC[e.source].i"></i></span>
                <span class="min0 flex-grow-1">
                  <b>{{ e.title }}</b>
                  <span class="ex">{{ e.excerpt }}</span>
                  <span class="meta"><span class="pg">{{ e.page }}</span>{{ SRC[e.source].l }} · {{ ago(e.updated_at) }} · {{ Math.round(e.chars / 100) / 10 }}k chars</span>
                </span>
              </button>
              <div v-if="!shown.length && !loading" class="text-center text-muted2 py-5"><i class="fa-regular fa-folder-open fa-2x d-block mb-2"></i>No entries match.</div>
              <div v-if="loading" class="text-center text-muted2 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>
            </div>
          </div>

          <!-- detail / editor -->
          <div class="kb-detail" :class="{ hide: !sel && narrow }">
            <template v-if="editing">
              <button v-if="narrow" class="btn btn-sm btn-light mb-2" @click="editing = null"><i class="fa-solid fa-arrow-left me-1"></i>Back</button>
              <h6 class="fw-bold">{{ editing.id ? 'Edit note' : 'New note' }}</h6>
              <label class="lbl">Title</label>
              <input v-model="editing.title" class="form-control mb-2" placeholder="e.g. Royalty rate for river sand 2026" />
              <label class="lbl">Page</label>
              <select v-model="editing.page" class="form-select mb-2"><option v-for="p in PAGES.slice(1)" :key="p.k" :value="p.k">{{ p.l }}</option></select>
              <label class="lbl">Content</label>
              <textarea v-model="editing.content" class="form-control" rows="12" placeholder="Facts, SOPs, decisions, context the assistant should know…"></textarea>
              <div v-if="err" class="text-danger small mt-2">{{ err }}</div>
              <div class="d-flex gap-2 mt-3 justify-content-end">
                <button class="btn btn-light" @click="editing = null">Cancel</button>
                <button class="btn btn-primary" :disabled="!editing.title || !editing.content || saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
              </div>
            </template>
            <template v-else-if="sel">
              <button v-if="narrow" class="btn btn-sm btn-light mb-2" @click="sel = null"><i class="fa-solid fa-arrow-left me-1"></i>All entries</button>
              <div class="d-flex align-items-start gap-2">
                <span class="src big" :class="`src-${sel.source}`"><i :class="SRC[sel.source].i"></i></span>
                <div class="flex-grow-1 min0"><h6 class="fw-bold mb-0">{{ sel.title }}</h6>
                  <div class="small text-muted2">KB#{{ sel.id }} · {{ sel.page }} · {{ SRC[sel.source].l }} · updated {{ fmt(sel.updated_at) }}</div></div>
                <div class="d-flex gap-1">
                  <button class="btn btn-sm btn-light" title="Ask about this entry" @click="askAbout"><i class="fa-solid fa-comment-dots"></i></button>
                  <button v-if="sel.source === 'manual'" class="btn btn-sm btn-light" title="Edit" @click="editing = { ...sel }"><i class="fa-solid fa-pen"></i></button>
                  <button v-if="sel.source !== 'reference'" class="btn btn-sm btn-light text-danger" title="Delete" @click="remove"><i class="fa-solid fa-trash"></i></button>
                </div>
              </div>
              <div v-if="sel.source === 'auto'" class="note-auto"><i class="fa-solid fa-robot me-1"></i>Generated from the live analysis — refreshed automatically, so edit by adding a note instead.</div>
              <pre class="kb-content">{{ sel.content }}</pre>
            </template>
            <div v-else class="empty"><i class="fa-solid fa-book-open-reader"></i><p>Select an entry to read it, or add a note with facts the assistant should know (SOPs, rates, decisions).</p></div>
          </div>
        </div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { apiGet, apiSend } from '../services/api'
import { kbUi } from '../services/kbStore'

const PAGES = [
  { k: 'all', l: 'All', icon: 'fa-solid fa-layer-group' }, { k: 'dashboard', l: 'Dashboard', icon: 'fa-solid fa-table-cells-large' },
  { k: 'monitoring', l: 'Monitoring', icon: 'fa-solid fa-chart-line' }, { k: 'attendance', l: 'Attendance', icon: 'fa-solid fa-calendar-check' },
  { k: 'map', l: 'Map', icon: 'fa-solid fa-map' }, { k: 'general', l: 'General', icon: 'fa-solid fa-circle-info' },
]
const SRC = {
  auto: { l: 'Auto snapshot', i: 'fa-solid fa-robot', t: 'Generated from the live analysis' },
  manual: { l: 'Note', i: 'fa-solid fa-pen-nib', t: 'Added by a user' },
  reference: { l: 'Reference', i: 'fa-solid fa-book', t: 'Definitions and rules' },
}
const entries = ref([]), counts = ref({}), aiEnabled = ref(false), lastRefresh = ref(null), loading = ref(false)
const q = ref(''), page = ref('all'), sel = ref(null), editing = ref(null), saving = ref(false), refreshing = ref(false), err = ref('')
const narrow = ref(window.innerWidth < 768)

const shown = computed(() => {
  const t = q.value.toLowerCase()
  return entries.value.filter(e => (page.value === 'all' || e.page === page.value) && (!t || `${e.title} ${e.excerpt}`.toLowerCase().includes(t)))
})
const fmt = d => d ? new Date(d.replace(' ', 'T')).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'
function ago(d) {
  if (!d) return '—'; const s = (Date.now() - new Date(d.replace(' ', 'T') + (d.includes('Z') || d.includes('+') ? '' : 'Z'))) / 1000
  if (s < 90) return 'just now'; if (s < 3600) return Math.round(s / 60) + ' min ago'; if (s < 86400) return Math.round(s / 3600) + ' h ago'
  return Math.round(s / 86400) + ' d ago'
}

async function load() {
  loading.value = true
  const r = await apiGet('kb'); loading.value = false
  if (r) { entries.value = r.entries; counts.value = r.counts; aiEnabled.value = r.ai_enabled; lastRefresh.value = r.last_refresh }
}
async function openEntry(id) { editing.value = null; const r = await apiGet(`kb/${id}`); if (r) sel.value = r }
function newNote() { sel.value = null; err.value = ''; editing.value = { title: '', content: '', page: page.value === 'all' ? 'general' : page.value } }
async function save() {
  saving.value = true; err.value = ''
  try {
    const r = editing.value.id ? await apiSend('PUT', `kb/${editing.value.id}`, editing.value) : await apiSend('POST', 'kb', editing.value)
    editing.value = null; await load(); sel.value = r
  } catch (e) { err.value = e.message } finally { saving.value = false }
}
async function remove() {
  if (!confirm(`Delete "${sel.value.title}" from the knowledge base?`)) return
  try { await apiSend('DELETE', `kb/${sel.value.id}`); sel.value = null; load() } catch (e) { alert(e.message) }
}
async function refresh() {
  refreshing.value = true
  try { await apiSend('POST', 'kb/refresh'); await load(); if (sel.value) openEntry(sel.value.id) } catch (e) { alert(e.message) } finally { refreshing.value = false }
}
function askAbout() { kbUi.open = false; kbUi.askOpen = true; window.dispatchEvent(new CustomEvent('ask-prefill', { detail: `Ringkaskan "${sel.value.title}" — apa yang penting dan apa tindakan perlu dibuat?` })) }
function close() { kbUi.open = false; kbUi.focusId = null }

watch(() => kbUi.open, v => { if (v) { load(); if (kbUi.focusId) openEntry(kbUi.focusId); else if (!narrow.value) sel.value = sel.value } })
watch(() => kbUi.focusId, id => { if (id && kbUi.open) openEntry(id) })
const onKey = e => { if (e.key === 'Escape' && kbUi.open) close() }
const onResize = () => narrow.value = window.innerWidth < 768
onMounted(() => { document.addEventListener('keydown', onKey); window.addEventListener('resize', onResize) })
onBeforeUnmount(() => { document.removeEventListener('keydown', onKey); window.removeEventListener('resize', onResize) })
</script>

<style scoped>
.kb-bg{position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(3px);z-index:3000;display:grid;place-items:center;padding:24px}
.kb{width:100%;max-width:1100px;height:min(86vh,820px);background:#fff;border-radius:22px;box-shadow:0 40px 100px rgba(15,23,42,.35);display:flex;flex-direction:column;overflow:hidden}
.kb-head{display:flex;align-items:center;gap:14px;padding:18px 22px;border-bottom:1px solid #eef0f3}
.kb-ic{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;display:grid;place-items:center;font-size:18px;flex-shrink:0}
.min0{min-width:0}
.ai-badge{font-size:12px;font-weight:700;border-radius:999px;padding:5px 11px;display:inline-flex;gap:6px;align-items:center;white-space:nowrap}
.ai-badge.on{background:#ede9fe;color:#6d28d9}.ai-badge.off{background:#f1f5f9;color:#475569}
.x-btn{width:34px;height:34px;border:0;border-radius:50%;background:#f1f5f9;color:#475569;display:grid;place-items:center;transition:all .15s;flex-shrink:0}
.x-btn:hover{background:#0f172a;color:#fff;transform:rotate(90deg)}
.kb-body{flex:1;display:grid;grid-template-columns:380px 1fr;min-height:0}
.kb-list{border-right:1px solid #eef0f3;display:flex;flex-direction:column;min-height:0}
.kb-tools{padding:14px;display:flex;flex-direction:column;gap:10px;border-bottom:1px solid #f1f5f9}
.kb-search{display:flex;align-items:center;gap:8px;background:#f8fafc;border:1px solid #eef0f3;border-radius:12px;padding:0 12px}
.kb-search i{color:#94a3b8}.kb-search input{border:0;background:transparent;outline:0;height:38px;flex:1;font-size:14px;min-width:0}
.chips{display:flex;gap:6px;flex-wrap:wrap}
.chips button{border:0;background:#f1f5f9;color:#475569;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:600;display:inline-flex;gap:5px;align-items:center;transition:all .15s}
.chips button small{opacity:.7}
.chips button:hover{background:#e0e7ff}.chips button.on{background:#2563eb;color:#fff}
.kb-items{flex:1;overflow:auto;padding:8px}
.kb-item{display:flex;gap:10px;width:100%;text-align:left;border:0;background:transparent;border-radius:12px;padding:10px;transition:background .12s,transform .12s}
.kb-item:hover{background:#f8fafc;transform:translateX(2px)}.kb-item.on{background:#eef4ff}
.kb-item b{display:block;font-size:13.5px;color:#0f172a;line-height:1.3}
.ex{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:12px;color:#64748b;margin-top:2px}
.meta{display:block;font-size:11px;color:#94a3b8;margin-top:4px}
.pg{background:#f1f5f9;color:#475569;border-radius:5px;padding:0 6px;margin-right:6px;text-transform:capitalize}
.src{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-size:13px;flex-shrink:0}
.src.big{width:38px;height:38px;font-size:15px}
.src-auto{background:#e0f2fe;color:#0369a1}.src-manual{background:#fef3c7;color:#b45309}.src-reference{background:#ede9fe;color:#6d28d9}
.kb-detail{padding:20px 24px;overflow:auto;min-height:0}
.kb-content{white-space:pre-wrap;font-family:inherit;font-size:13px;line-height:1.6;color:#334155;background:#f8fafc;border-radius:14px;padding:16px;margin-top:14px}
.note-auto{margin-top:12px;font-size:12.5px;background:#f0f9ff;color:#0369a1;border-radius:10px;padding:8px 12px}
.empty{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#94a3b8;max-width:340px;margin:auto}
.empty i{font-size:40px;margin-bottom:12px;color:#c7d2fe}
.lbl{font-size:12.5px;font-weight:700;color:#4b5563;margin-bottom:4px;display:block}
.kbfade-enter-from,.kbfade-leave-to{opacity:0}.kbfade-enter-from .kb,.kbfade-leave-to .kb{transform:translateY(16px) scale(.98)}
.kbfade-enter-active,.kbfade-leave-active,.kbfade-enter-active .kb,.kbfade-leave-active .kb{transition:all .22s cubic-bezier(.2,.8,.2,1)}
@media (max-width:767.98px){
  .kb-bg{padding:0;place-items:end stretch}
  .kb{height:92vh;border-radius:22px 22px 0 0;max-width:none}
  .kb-body{grid-template-columns:1fr}
  .kb-list{border-right:0}
  .hide{display:none!important}
  .kb-head{padding:14px 16px}
}
</style>
