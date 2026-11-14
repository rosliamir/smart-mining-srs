<template>
  <div class="ess-page">
    <section class="pf">
      <span class="big">{{ initials(name) }}</span>
      <div class="min0"><h1 class="ess-h1 text-white">{{ name }}</h1>
        <div class="chips"><span v-for="c in chips" :key="c.t"><i :class="c.i"></i>{{ c.t }}</span></div></div>
    </section>
    <div class="g mt-3">
      <section class="ess-card">
        <h6 class="mb-3"><i class="fa-solid fa-id-card me-2 text-primary"></i>Maklumat pekerjaan</h6>
        <div v-for="r in rows" :key="r.l" class="kv"><span class="ic sm" :class="r.c"><i :class="r.i"></i></span><span class="muted">{{ r.l }}</span><b>{{ r.v || '—' }}</b></div>
        <div class="tiny muted mt-2">Maklumat peribadi lain (alamat, akaun bank, waris) diurus oleh HR. Hubungi HR untuk sebarang pembetulan.</div>
      </section>
      <section class="ess-card">
        <h6 class="mb-3"><i class="fa-solid fa-key me-2 text-warning"></i>Tukar kata laluan</h6>
        <div v-if="demo" class="err mb-3"><i class="fa-solid fa-shield-halved me-1"></i>Anda masih menggunakan kata laluan demo. Sila tukar sekarang.</div>
        <label class="f-lbl">Kata laluan semasa</label><input type="password" class="f-in mb-2" v-model="pw.current" autocomplete="current-password" />
        <label class="f-lbl">Kata laluan baharu (min. 8 aksara)</label><input type="password" class="f-in mb-2" v-model="pw.password" autocomplete="new-password" />
        <div class="meter"><div :style="{ width: strength * 25 + '%', background: ['#e2e8f0', '#e11d48', '#f59e0b', '#22c55e', '#16a34a'][strength] }"></div></div>
        <label class="f-lbl mt-2">Sahkan kata laluan baharu</label><input type="password" class="f-in" v-model="pw.password_confirmation" autocomplete="new-password" />
        <div v-if="err" class="err mt-2">{{ err }}</div><div v-if="ok" class="ok mt-2"><i class="fa-solid fa-circle-check me-1"></i>{{ ok }}</div>
        <button class="btn-pri w-100 mt-3" :disabled="busy || pw.password.length < 8 || pw.password !== pw.password_confirmation" @click="save">Kemas kini kata laluan</button>
      </section>
    </div>
  </div>
</template>
<script setup>
import { ref, reactive, computed } from 'vue'
import { ess, essState, initials } from '../../services/ess'
import { currentUser } from '../../services/api'
const u = currentUser(), h = computed(() => essState.home)
const name = computed(() => h.value?.employee?.name || u?.name || u?.username)
const e = computed(() => h.value?.employee || {})
const chips = computed(() => [
  e.value.code && { t: e.value.code, i: 'fa-solid fa-id-badge' }, e.value.role && { t: e.value.role.replace(/_/g, ' '), i: 'fa-solid fa-briefcase' },
  e.value.office && { t: e.value.office, i: 'fa-solid fa-building' }, h.value?.roles?.hr ? { t: 'HR / Pelulus', i: 'fa-solid fa-crown' } : h.value?.roles?.approver ? { t: 'Penyelia', i: 'fa-solid fa-user-shield' } : null,
].filter(Boolean))
const rows = computed(() => [
  { l: 'Kod staf', v: e.value.code, i: 'fa-solid fa-hashtag', c: 'ic-indigo' }, { l: 'Jawatan', v: (e.value.role || '').replace(/_/g, ' '), i: 'fa-solid fa-briefcase', c: 'ic-violet' },
  { l: 'Pejabat', v: { HQ: 'Ibu Pejabat (HQ)', PSU: 'Pejabat Satelit Utara', PSS: 'Pejabat Satelit Selatan' }[e.value.office], i: 'fa-solid fa-building', c: 'ic-blue' },
  { l: 'Syif', v: e.value.shift, i: 'fa-solid fa-sun', c: 'ic-amber' }, { l: 'Hari bekerja / minggu', v: e.value.workdays, i: 'fa-solid fa-calendar-week', c: 'ic-teal' },
  { l: 'E-mel log masuk', v: u?.email, i: 'fa-solid fa-envelope', c: 'ic-slate' },
])
const pw = reactive({ current: '', password: '', password_confirmation: '' }), busy = ref(false), err = ref(''), ok = ref('')
const demo = ref(true)
const strength = computed(() => { const p = pw.password; if (!p) return 0; return [/.{8,}/, /[A-Z]/, /[0-9]/, /[^A-Za-z0-9]/].filter(r => r.test(p)).length })
async function save() {
  busy.value = true; err.value = ''; ok.value = ''
  try { await ess.password({ ...pw }); ok.value = 'Kata laluan dikemas kini.'; demo.value = false; Object.assign(pw, { current: '', password: '', password_confirmation: '' }) }
  catch (x) { err.value = x.message } finally { busy.value = false }
}
</script>
<style scoped>
.pf{display:flex;gap:18px;align-items:center;border-radius:26px;padding:26px;background:linear-gradient(135deg,#0f172a,#334155 55%,#4f46e5);color:#fff;box-shadow:0 20px 44px rgba(15,23,42,.3)}
.big{width:84px;height:84px;border-radius:26px;background:linear-gradient(135deg,#818cf8,#c084fc);display:grid;place-items:center;font-size:30px;font-weight:800;flex-shrink:0;box-shadow:0 10px 24px rgba(0,0,0,.3)}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.chips span{background:rgba(255,255,255,.14);border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;display:inline-flex;gap:6px;align-items:center;text-transform:capitalize}
.g{display:grid;grid-template-columns:1.2fr 1fr;gap:16px;align-items:start}
.kv{display:flex;gap:10px;align-items:center;padding:8px 0;border-bottom:1px dashed var(--line);font-size:13.5px}.kv b{margin-left:auto;text-transform:capitalize;text-align:right}
.meter{height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden}.meter div{height:100%;transition:all .3s}
@media (max-width:991.98px){ .g{grid-template-columns:1fr} .pf{padding:20px} .big{width:64px;height:64px;font-size:22px} }
</style>
