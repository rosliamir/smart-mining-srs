<template>
  <div class="ess-page">
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
      <span class="ic lg ic-orange"><i class="fa-solid fa-stamp"></i></span>
      <div class="flex-grow-1"><h1 class="ess-h1">Kelulusan</h1><p class="ess-sub">Permohonan pasukan yang menunggu tindakan anda</p></div>
      <div class="ess-tabs"><button :class="{ on: tab === 'pending' }" @click="tab = 'pending'"><i class="fa-solid fa-inbox"></i>Menunggu <span v-if="pending.length" class="n">{{ pending.length }}</span></button>
        <button :class="{ on: tab === 'history' }" @click="tab = 'history'"><i class="fa-solid fa-clock-rotate-left"></i>Sejarah</button></div>
    </div>

    <template v-if="tab === 'pending'">
      <div class="ess-tabs mb-3">
        <button v-for="f in FILTERS" :key="f.k" :class="{ on: filter === f.k }" @click="filter = f.k"><i :class="f.i"></i>{{ f.l }} <span class="muted">{{ cnt(f.k) }}</span></button>
      </div>
      <transition-group name="ap" tag="div" class="grid">
        <div v-for="r in shown" :key="r.id" class="ess-card ap lift">
          <div class="d-flex gap-12 align-items-start">
            <span class="av">{{ initials(r.employee) }}</span>
            <div class="min0 flex-grow-1"><b class="nm">{{ r.employee }}</b><div class="tiny muted">{{ r.code }} · {{ (r.job_level || '').replace(/_/g, ' ') }} · {{ r.office }}</div></div>
            <span class="lvl">Peringkat {{ r.step_no }} · {{ ROLE[r.step_role] }}</span>
          </div>
          <div class="what">
            <span class="ic sm" :style="{ background: (r.leave_color || TYPE[r.type].color) + '1f', color: r.leave_color || TYPE[r.type].color }"><i :class="r.leave_icon || TYPE[r.type].icon"></i></span>
            <div class="min0"><b>{{ r.leave_name || TYPE[r.type].label }}<span v-if="r.details?.category" class="muted fw-normal"> · {{ cat(r) }}</span></b>
              <div class="tiny muted">{{ dateText(r) }}</div></div>
            <span class="qty">{{ qtyText(r) }}</span>
          </div>
          <div class="reason"><i class="fa-solid fa-quote-left"></i>{{ r.reason }}</div>
          <div class="ctx">
            <span v-if="r.balance && r.balance.entitled !== null" :class="{ warn: r.balance.available < 0 }"><i class="fa-solid fa-scale-balanced"></i>Baki {{ r.balance.available + +r.quantity }} → {{ r.balance.available }} hari</span>
            <span v-if="r.details?.ot_type"><i class="fa-solid fa-percent"></i>Kadar {{ r.details.rate }}x</span>
            <span v-if="r.details?.attachment"><i class="fa-solid fa-paperclip"></i>{{ r.details.attachment }}</span>
            <span><i class="fa-regular fa-clock"></i>Dihantar {{ ago(r.created_at) }}</span>
          </div>
          <div class="tl">
            <template v-for="(s, i) in r.steps" :key="s.id">
              <span v-if="i" class="tl-line" :class="{ done: s.action === 'approved' || r.steps[i-1].action === 'approved' }"></span>
              <span class="tl-step"><span class="tl-dot" :class="[s.action, { current: s.step === r.step_no }]"><i class="fa-solid" :class="s.action === 'approved' ? 'fa-check' : 'fa-user-clock'"></i></span>{{ ROLE[s.role] }}<small v-if="s.approver" class="ms-1 muted">({{ s.approver.split(' ')[0] }})</small></span>
            </template>
          </div>
          <div v-if="rejecting === r.id" class="mt-2"><textarea v-model="comment" class="f-in" rows="2" placeholder="Sebab penolakan (wajib)…"></textarea></div>
          <div class="acts">
            <template v-if="rejecting === r.id">
              <button class="btn-soft" @click="rejecting = null">Batal</button>
              <button class="btn-rej" :disabled="!comment.trim() || busy" @click="act(r, 'reject')"><i class="fa-solid fa-xmark me-1"></i>Sahkan tolak</button>
            </template>
            <template v-else>
              <button class="btn-soft" @click="rejecting = r.id; comment = ''"><i class="fa-solid fa-xmark me-1"></i>Tolak</button>
              <button class="btn-pri" :disabled="busy" @click="act(r, 'approve')"><i class="fa-solid fa-check me-1"></i>Lulus{{ r.step_no < r.steps.length ? ' & hantar ke HR' : '' }}</button>
            </template>
          </div>
        </div>
      </transition-group>
      <div v-if="!shown.length && !loading" class="empty ess-card"><i class="fa-solid fa-mug-saucer"></i>Tiada permohonan menunggu. Bagus!</div>
    </template>

    <div v-else class="ess-card">
      <div v-for="x in history" :key="x.id + x.acted_at" class="hist">
        <span class="ic sm" :class="x.action === 'approved' ? 'ic-green' : 'ic-rose'"><i class="fa-solid" :class="x.action === 'approved' ? 'fa-check' : 'fa-xmark'"></i></span>
        <span class="min0 flex-grow-1"><b>{{ x.employee }}</b> — {{ x.leave_name || TYPE[x.type].label }} · {{ qtyText(x) }}<small class="d-block muted">{{ d3(x.start_date) }}{{ x.comment ? ' · “' + x.comment + '”' : '' }}</small></span>
        <span class="st" :class="STATUS[x.status].cls">{{ STATUS[x.status].label }}</span>
        <small class="muted d-none d-md-inline">{{ ago(x.acted_at) }}</small>
      </div>
      <div v-if="!history.length" class="empty"><i class="fa-solid fa-clock-rotate-left"></i>Belum ada tindakan.</div>
    </div>
    <transition name="ap"><div v-if="msg" class="toast2"><i class="fa-solid fa-circle-check"></i>{{ msg }}</div></transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ess, refreshHome, TYPE, ROLE, STATUS, CLAIM_CATS, dateText, qtyText, d3, ago, initials } from '../../services/ess'
const FILTERS = [{ k: 'all', l: 'Semua', i: 'fa-solid fa-layer-group' }, ...Object.entries(TYPE).map(([k, t]) => ({ k, l: t.label, i: t.icon }))]
const tab = ref('pending'), filter = ref('all'), pending = ref([]), history = ref([]), loading = ref(false), busy = ref(false), rejecting = ref(null), comment = ref(''), msg = ref('')
const shown = computed(() => pending.value.filter(r => filter.value === 'all' || r.type === filter.value))
const cnt = k => k === 'all' ? pending.value.length : pending.value.filter(r => r.type === k).length
const cat = r => CLAIM_CATS.find(c => c.k === r.details?.category)?.l
async function load() { loading.value = true; const d = await ess.approvals(); pending.value = d?.pending || []; history.value = d?.history || []; loading.value = false }
async function act(r, action) {
  busy.value = true
  try {
    await ess.act(r.id, action, action === 'reject' ? comment.value : null)
    msg.value = action === 'approve' ? `Diluluskan — ${r.employee}` : `Ditolak — ${r.employee}`; setTimeout(() => msg.value = '', 2500)
    rejecting.value = null; await load(); refreshHome()
  } catch (e) { alert(e.message) } finally { busy.value = false }
}
onMounted(load)
</script>

<style scoped>
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
.gap-12{gap:12px}
.av{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#6366f1,#a855f7);color:#fff;font-weight:800;display:grid;place-items:center;flex-shrink:0}
.nm{font-size:14.5px;display:block}
.lvl{font-size:11px;font-weight:700;background:#fff7ed;color:#c2410c;border-radius:999px;padding:3px 9px;white-space:nowrap}
.what{display:flex;gap:10px;align-items:center;background:#f8fafc;border-radius:14px;padding:10px 12px;margin-top:12px}
.what b{font-size:13.5px}.qty{margin-left:auto;font-weight:800;font-size:15px;white-space:nowrap}
.reason{font-size:13px;color:#334155;margin-top:10px;display:flex;gap:8px}.reason i{color:#c7d2fe;margin-top:3px}
.ctx{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.ctx span{font-size:11.5px;background:#f1f5f9;color:#475569;border-radius:8px;padding:3px 8px;display:inline-flex;gap:5px;align-items:center}
.ctx .warn{background:#ffe4e6;color:#be123c}
.acts{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}
.btn-rej{background:#e11d48;color:#fff;border:0;border-radius:12px;font-weight:700;padding:.5rem .9rem}.btn-rej:disabled{opacity:.5}
.hist{display:flex;gap:12px;align-items:center;padding:10px 0;border-bottom:1px dashed var(--line);font-size:13px}
.toast2{position:fixed;left:50%;bottom:90px;transform:translateX(-50%);background:#0f172a;color:#fff;border-radius:14px;padding:12px 18px;font-weight:600;z-index:3000;display:flex;gap:10px;align-items:center}
.toast2 i{color:#4ade80}
.ap-enter-from,.ap-leave-to{opacity:0;transform:scale(.97)}.ap-enter-active,.ap-leave-active{transition:all .25s}
@media (max-width:991.98px){ .grid{grid-template-columns:1fr} }
</style>
