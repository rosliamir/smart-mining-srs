<template>
  <transition name="rf">
    <div v-if="essState.formOpen" class="rf-bg" @click.self="close">
      <div class="rf">
        <div class="rf-head">
          <span class="ic" :style="{ background: T.bg, color: T.color }"><i :class="T.icon"></i></span>
          <div class="flex-grow-1"><b>Permohonan {{ T.label }}</b><div class="tiny muted">Dihantar kepada {{ chainText }}</div></div>
          <button class="x" @click="close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="rf-types">
          <button v-for="(t, k) in TYPE" :key="k" :class="{ on: type === k }" @click="setType(k)"><i :class="t.icon" :style="{ color: t.color }"></i>{{ t.label }}</button>
        </div>

        <div class="rf-body">
          <!-- LEAVE -->
          <template v-if="type === 'leave'">
            <label class="f-lbl">Jenis cuti</label>
            <div class="lt-grid">
              <button v-for="b in leaveTypes" :key="b.code" class="lt" :class="{ on: f.leave_type === b.code }" @click="f.leave_type = b.code">
                <span class="ic sm" :style="{ background: b.color + '1f', color: b.color }"><i :class="b.icon"></i></span>
                <span class="min0"><b>{{ b.name_ms }}</b><small>{{ balText(b) }}</small></span>
              </button>
            </div>
            <div v-if="selLeave" class="lt-note">
              <i class="fa-solid fa-circle-info"></i>
              <span>{{ selLeave.min_notice_days ? `Mohon ${selLeave.min_notice_days} hari lebih awal. ` : '' }}{{ selLeave.max_backdate_days ? `Boleh mohon sehingga ${selLeave.max_backdate_days} hari ke belakang. ` : '' }}{{ selLeave.requires_attachment ? 'Perlu dokumen sokongan. ' : '' }}Kelulusan: {{ selLeave.chain.map(c => ROLE[c]).join(' → ') }}.</span>
            </div>
            <div class="row g-2 mt-1">
              <div class="col-6"><label class="f-lbl">Dari</label><input type="date" class="f-in" v-model="f.start_date" /></div>
              <div class="col-6"><label class="f-lbl">Hingga</label><input type="date" class="f-in" v-model="f.end_date" :min="f.start_date" :disabled="!!f.half_day" /></div>
            </div>
            <div class="half">
              <span class="f-lbl mb-0">Separuh hari</span>
              <button v-for="h in ['', 'am', 'pm']" :key="h" :class="{ on: (f.half_day || '') === h }" @click="setHalf(h)">{{ h ? h.toUpperCase() : 'Tidak' }}</button>
              <span class="calc ms-auto"><i class="fa-solid fa-calculator me-1"></i>{{ leaveDays }} hari bekerja</span>
            </div>
          </template>

          <!-- OVERTIME -->
          <template v-else-if="type === 'overtime'">
            <div class="row g-2">
              <div class="col-12 col-md-4"><label class="f-lbl">Tarikh</label><input type="date" class="f-in" v-model="f.start_date" /></div>
              <div class="col-6 col-md-4"><label class="f-lbl">Mula</label><input type="time" class="f-in" v-model="f.start_time" /></div>
              <div class="col-6 col-md-4"><label class="f-lbl">Tamat</label><input type="time" class="f-in" v-model="f.end_time" /></div>
            </div>
            <label class="f-lbl mt-3">Jenis hari</label>
            <div class="chips3">
              <button v-for="o in OT_TYPES" :key="o.k" :class="{ on: f.ot_type === o.k }" @click="f.ot_type = o.k"><b>{{ o.rate }}</b>{{ o.l }}</button>
            </div>
            <div class="calc mt-2"><i class="fa-solid fa-clock me-1"></i>{{ hours }} jam · OT bulan ini {{ essState.home?.ot?.hours ?? 0 }} / 104 jam</div>
          </template>

          <!-- TIME-OFF -->
          <template v-else-if="type === 'timeoff'">
            <div class="row g-2">
              <div class="col-12 col-md-4"><label class="f-lbl">Tarikh</label><input type="date" class="f-in" v-model="f.start_date" /></div>
              <div class="col-6 col-md-4"><label class="f-lbl">Keluar</label><input type="time" class="f-in" v-model="f.start_time" /></div>
              <div class="col-6 col-md-4"><label class="f-lbl">Masuk semula</label><input type="time" class="f-in" v-model="f.end_time" /></div>
            </div>
            <div class="calc mt-2" :class="{ bad: hours > 4 }"><i class="fa-solid fa-hourglass-half me-1"></i>{{ hours }} jam (maks 4 jam) · slip bulan ini {{ essState.home?.timeoff?.used ?? 0 }} / 2</div>
          </template>

          <!-- CLAIM -->
          <template v-else>
            <label class="f-lbl">Kategori</label>
            <div class="cat-grid">
              <button v-for="c in CLAIM_CATS" :key="c.k" :class="{ on: f.category === c.k }" @click="f.category = c.k"><i :class="c.icon"></i>{{ c.l }}</button>
            </div>
            <div class="row g-2 mt-1">
              <div class="col-6"><label class="f-lbl">Tarikh perbelanjaan</label><input type="date" class="f-in" v-model="f.start_date" /></div>
              <div class="col-6"><label class="f-lbl">Jumlah (RM)</label><input type="number" min="0" step="0.01" class="f-in" v-model.number="f.amount" placeholder="0.00" /></div>
            </div>
          </template>

          <label class="f-lbl mt-3">{{ type === 'claim' ? 'Butiran' : 'Sebab' }}</label>
          <textarea class="f-in" rows="3" v-model="f.reason" :placeholder="PH[type]"></textarea>

          <label v-if="needsFile" class="file mt-3">
            <input type="file" @change="e => f.attachment = e.target.files[0]?.name || ''" />
            <i class="fa-solid fa-paperclip"></i><span>{{ f.attachment || (type === 'claim' ? 'Lampirkan resit' : 'Lampirkan dokumen sokongan (MC, surat)') }}</span>
          </label>
          <div v-if="needsFile" class="tiny muted mt-1">Nama fail direkodkan bersama permohonan; serahkan salinan asal kepada HR.</div>

          <div v-if="err" class="err mt-3"><i class="fa-solid fa-triangle-exclamation me-1"></i>{{ err }}</div>
        </div>

        <div class="rf-foot">
          <button class="btn-soft" @click="close">Batal</button>
          <button class="btn-pri" :disabled="busy || !valid" @click="submit"><i class="fa-solid fa-paper-plane me-2"></i>{{ busy ? 'Menghantar…' : 'Hantar permohonan' }}</button>
        </div>
      </div>
    </div>
  </transition>
  <transition name="toast"><div v-if="toast" class="toast-ok"><i class="fa-solid fa-circle-check"></i>{{ toast }}</div></transition>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { ess, essState, refreshHome, TYPE, ROLE, CLAIM_CATS, OT_TYPES, today } from '../../services/ess'

const PH = { leave: 'Contoh: urusan keluarga di kampung', overtime: 'Contoh: jualan pasir lewat petang di BJ04E', timeoff: 'Contoh: temujanji klinik / urusan bank', claim: 'Contoh: perjalanan ke tapak BJ04E, 120 km pergi balik' }
const type = ref('leave'), busy = ref(false), err = ref(''), toast = ref(''), holidays = ref([])
const f = reactive({})
const T = computed(() => TYPE[type.value])
const leaveTypes = computed(() => (essState.home?.balances || []))
const selLeave = computed(() => leaveTypes.value.find(b => b.code === f.leave_type))
const chainText = computed(() => {
  const c = type.value === 'leave' ? (selLeave.value?.chain || ['supervisor', 'hr']) : type.value === 'timeoff' ? ['supervisor'] : ['supervisor', 'hr']
  return c.map(x => ROLE[x]).join(' → ')
})
const needsFile = computed(() => type.value === 'claim' || (type.value === 'leave' && selLeave.value?.requires_attachment))
function reset(preset) {
  Object.keys(f).forEach(k => delete f[k])
  Object.assign(f, { start_date: today(), end_date: today(), start_time: type.value === 'overtime' ? '17:30' : '10:00', end_time: type.value === 'overtime' ? '20:30' : '12:00',
    leave_type: 'annual', half_day: '', ot_type: 'normal', category: 'mileage', amount: null, reason: '', attachment: '' }, preset || {})
  err.value = ''
}
function setType(k) { type.value = k; reset() }
function setHalf(h) { f.half_day = h; if (h) f.end_date = f.start_date }
function balText(b) {
  if (b.uses === 'annual' && b.code === 'emergency') return 'Tolak dari cuti tahunan'
  if (b.available === null) return b.deducts ? '—' : 'Tiada baki ditolak'
  return `${b.available} / ${b.entitled} hari tersedia`
}
const hours = computed(() => {
  if (!f.start_time || !f.end_time) return 0
  const [a, b] = [f.start_time, f.end_time].map(t => { const [h, m] = t.split(':'); return +h * 60 + +m })
  return Math.round(((b > a ? b - a : b + 1440 - a) / 60) * 100) / 100
})
const leaveDays = computed(() => {   // mirrors the server rule: skip Sundays (and Saturdays for 5-day staff) and public holidays
  if (!f.start_date) return 0
  const wd = essState.home?.employee?.workdays || 6, hol = new Set(holidays.value.map(h => String(h.date).slice(0, 10)))
  let n = 0
  for (let d = new Date(f.start_date + 'T00:00:00'), end = new Date((f.end_date || f.start_date) + 'T00:00:00'); d <= end; d.setDate(d.getDate() + 1)) {
    const iso = d.toLocaleDateString('en-CA'), dow = d.getDay()
    if (dow === 0 || (wd <= 5 && dow === 6) || hol.has(iso)) continue; n++
  }
  return f.half_day ? Math.min(n, 0.5) : n
})
const valid = computed(() => f.reason?.trim() && f.start_date && (type.value !== 'claim' || (f.amount > 0 && f.category)) && (type.value !== 'leave' || leaveDays.value > 0))

async function submit() {
  busy.value = true; err.value = ''
  const body = { start_date: f.start_date, reason: f.reason, attachment: f.attachment || undefined }
  if (type.value === 'leave') Object.assign(body, { leave_type: f.leave_type, end_date: f.end_date, half_day: f.half_day || undefined })
  if (type.value === 'overtime') Object.assign(body, { start_time: f.start_time, end_time: f.end_time, ot_type: f.ot_type })
  if (type.value === 'timeoff') Object.assign(body, { start_time: f.start_time, end_time: f.end_time })
  if (type.value === 'claim') Object.assign(body, { category: f.category, amount: f.amount })
  try {
    await ess.submit(type.value, body)
    essState.formOpen = false; toast.value = `Permohonan ${T.value.label} dihantar untuk kelulusan`; setTimeout(() => toast.value = '', 3500)
    await refreshHome(); window.dispatchEvent(new CustomEvent('ess-submitted', { detail: type.value }))
  } catch (e) { err.value = e.message } finally { busy.value = false }
}
function close() { essState.formOpen = false }
watch(() => essState.formOpen, async v => {
  if (!v) return
  type.value = essState.formType || 'leave'; reset(essState.formPreset)
  if (!holidays.value.length) { const y = new Date().getFullYear(); holidays.value = [...(await ess.holidays(y) || []), ...(await ess.holidays(y + 1) || [])] }
})
</script>

<style scoped>
.rf-bg{position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(3px);z-index:2500;display:grid;place-items:center;padding:20px}
.rf{width:100%;max-width:620px;max-height:92vh;background:#fff;border-radius:24px;box-shadow:0 40px 100px rgba(15,23,42,.35);display:flex;flex-direction:column;overflow:hidden}
.rf-head{display:flex;gap:12px;align-items:center;padding:18px 20px 12px}
.rf-head b{font-size:17px}
.x{width:34px;height:34px;border:0;border-radius:50%;background:#f1f5f9;color:#475569;transition:all .15s}.x:hover{background:#0f172a;color:#fff;transform:rotate(90deg)}
.rf-types{display:flex;gap:6px;padding:0 20px 12px;overflow-x:auto;scrollbar-width:none}
.rf-types button{border:1px solid var(--line);background:#fff;border-radius:999px;padding:6px 12px;font-size:12.5px;font-weight:600;white-space:nowrap;display:flex;gap:6px;align-items:center;transition:all .15s}
.rf-types button.on{background:#0f172a;color:#fff;border-color:#0f172a}.rf-types button.on i{color:#fff!important}
.rf-body{padding:4px 20px 16px;overflow:auto}
.lt-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.lt{display:flex;gap:8px;align-items:center;text-align:left;border:1.5px solid var(--line);background:#fff;border-radius:14px;padding:8px;transition:all .15s}
.lt:hover{border-color:#c7d2fe;transform:translateY(-1px)}.lt.on{border-color:var(--pri);background:#f5f7ff;box-shadow:0 0 0 3px rgba(79,70,229,.12)}
.lt b{display:block;font-size:12.5px;line-height:1.2}.lt small{display:block;font-size:10.5px;color:var(--muted)}
.lt-note{display:flex;gap:8px;font-size:12px;color:#3730a3;background:#eef2ff;border-radius:12px;padding:8px 10px;margin:10px 0 6px}
.half{display:flex;gap:6px;align-items:center;margin-top:10px;flex-wrap:wrap}
.half button,.chips3 button,.cat-grid button{border:1.5px solid var(--line);background:#fff;border-radius:12px;padding:6px 12px;font-size:12.5px;font-weight:600;transition:all .15s}
.half button.on,.chips3 button.on,.cat-grid button.on{border-color:var(--pri);background:#f5f7ff;color:var(--pri)}
.calc{font-size:12.5px;font-weight:700;color:#4338ca;background:#eef2ff;border-radius:10px;padding:5px 10px;display:inline-block}
.calc.bad{color:#be123c;background:#ffe4e6}
.chips3{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.chips3 button{display:flex;flex-direction:column;align-items:center;padding:10px}.chips3 b{font-size:18px}
.cat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.cat-grid button{display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 6px}.cat-grid i{font-size:18px}
.file{display:flex;gap:10px;align-items:center;border:1.5px dashed #c7d2fe;border-radius:14px;padding:12px;cursor:pointer;color:#4338ca;font-weight:600;font-size:13px;background:#f8faff;transition:background .15s}
.file:hover{background:#eef2ff}.file input{display:none}
.rf-foot{display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid var(--line);background:#fafbff}
.toast-ok{position:fixed;left:50%;bottom:90px;transform:translateX(-50%);background:#0f172a;color:#fff;border-radius:14px;padding:12px 18px;font-weight:600;font-size:14px;z-index:3000;display:flex;gap:10px;align-items:center;box-shadow:0 14px 34px rgba(15,23,42,.35)}
.toast-ok i{color:#4ade80}
.rf-enter-from,.rf-leave-to{opacity:0}.rf-enter-from .rf,.rf-leave-to .rf{transform:translateY(20px) scale(.98)}
.rf-enter-active,.rf-leave-active,.rf-enter-active .rf,.rf-leave-active .rf{transition:all .22s cubic-bezier(.2,.8,.2,1)}
.toast-enter-from,.toast-leave-to{opacity:0;transform:translate(-50%,10px)}.toast-enter-active,.toast-leave-active{transition:all .25s}
@media (max-width:767.98px){
  .rf-bg{padding:0;place-items:end stretch}
  .rf{max-width:none;border-radius:24px 24px 0 0;max-height:94vh}
  .lt-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
