<template>
  <div class="ess-page home">
    <SelfieCapture ref="selfie" />
    <div v-if="!h" class="empty"><span class="spinner-border spinner-border-sm me-2"></span>Memuatkan…</div>
    <template v-else>
      <!-- HERO: greeting + clock-in -->
      <section class="hero">
        <div class="hero-l">
          <div class="hello">{{ greet }}, <b>{{ firstName }}</b> 👋</div>
          <div class="chips">
            <span v-if="h.employee"><i class="fa-solid fa-id-badge"></i>{{ h.employee.code }}</span>
            <span v-if="h.employee"><i class="fa-solid fa-briefcase"></i>{{ role }}</span>
            <span v-if="h.employee"><i class="fa-solid fa-building"></i>{{ h.employee.office }}</span>
            <span v-if="h.roles.hr" class="gold"><i class="fa-solid fa-crown"></i>HR / Pelulus</span>
            <span v-else-if="h.roles.approver" class="gold"><i class="fa-solid fa-user-shield"></i>Penyelia</span>
          </div>
          <div class="clock">{{ now.time }}<small>{{ now.sec }}</small></div>
          <div class="date">{{ now.date }}</div>
        </div>
        <div v-if="h.employee" class="punch">
          <div class="ring-wrap">
            <svg viewBox="0 0 120 120" class="ring"><circle cx="60" cy="60" r="52" class="bg" /><circle cx="60" cy="60" r="52" class="fg" :style="{ strokeDashoffset: 327 - 327 * dayPct }" /></svg>
            <button class="punch-btn" :class="punchState" :disabled="punchState === 'done' || punching" @click="punch">
              <i class="fa-solid" :class="punching ? 'fa-spinner fa-spin' : punchState === 'in' ? 'fa-fingerprint' : punchState === 'out' ? 'fa-right-from-bracket' : 'fa-circle-check'"></i>
              <span>{{ punchState === 'in' ? 'Clock In' : punchState === 'out' ? 'Clock Out' : 'Selesai' }}</span>
            </button>
          </div>
          <div class="punch-meta">
            <div><small>Masuk</small><b>{{ hm(h.today?.timeclock_in) }}</b></div>
            <div><small>Keluar</small><b>{{ hm(h.today?.timeclock_out) }}</b></div>
            <div><small>Bekerja</small><b>{{ workedText }}</b></div>
          </div>
          <div v-if="punchMsg" class="punch-msg" :class="punchErr ? 'bad' : ''">{{ punchMsg }}</div>
        </div>
        <div v-else class="noemp"><i class="fa-solid fa-link-slash"></i>Akaun ini belum dipautkan kepada rekod pekerja — anda boleh meluluskan permohonan dan membaca memo/polisi.</div>
      </section>

      <!-- NUDGES -->
      <div v-if="h.nudges?.length" class="nudges">
        <button v-for="(n, i) in h.nudges" :key="i" class="nudge lift" :class="n.tone" @click="nudgeGo(n)"><i :class="n.icon"></i><span>{{ n.text }}</span><i class="fa-solid fa-chevron-right go"></i></button>
      </div>

      <!-- QUICK ACTIONS -->
      <section class="qa">
        <button v-for="a in actions" :key="a.l" class="qa-i lift" @click="a.do()">
          <span class="ic lg" :class="a.c"><i :class="a.i"></i></span><span>{{ a.l }}</span>
          <span v-if="a.badge" class="qa-b">{{ a.badge }}</span>
        </button>
      </section>

      <div class="ess-grid g2">
        <!-- LEAVE BALANCES -->
        <section v-if="h.employee" class="ess-card">
          <div class="sec-h"><span class="ic sm ic-cyan"><i class="fa-solid fa-umbrella-beach"></i></span><h6>Baki cuti {{ year }}</h6>
            <router-link to="/ess/leave" class="more">Semua <i class="fa-solid fa-arrow-right"></i></router-link></div>
          <div class="bals">
            <button v-for="b in mainBalances" :key="b.code" class="bal lift" @click="openForm('leave', { leave_type: b.code })">
              <svg viewBox="0 0 64 64" class="mini-ring"><circle cx="32" cy="32" r="27" class="bg" />
                <circle cx="32" cy="32" r="27" class="fg" :stroke="b.color" :style="{ strokeDashoffset: 170 - 170 * (b.entitled ? Math.max(0, b.available) / b.entitled : 0) }" /></svg>
              <span class="bal-n" :style="{ color: b.color }">{{ b.available }}</span>
              <b>{{ b.name_ms }}</b><small>daripada {{ b.entitled }} hari{{ b.pending ? ` · ${b.pending} menunggu` : '' }}</small>
            </button>
          </div>
        </section>

        <!-- MONTH STATS -->
        <section v-if="h.employee" class="ess-card">
          <div class="sec-h"><span class="ic sm ic-blue"><i class="fa-solid fa-chart-simple"></i></span><h6>Prestasi kehadiran bulan ini</h6>
            <router-link to="/ess/attendance" class="more">Butiran <i class="fa-solid fa-arrow-right"></i></router-link></div>
          <div class="stats">
            <div v-for="s in stats" :key="s.l" class="stat"><span class="ic sm" :class="s.c"><i :class="s.i"></i></span><b>{{ s.v }}</b><small>{{ s.l }}</small></div>
          </div>
          <div class="week">
            <div v-for="d in week" :key="d.date" class="wd" :class="d.cls" :title="d.title"><small>{{ d.dow }}</small><span><i :class="d.icon"></i></span><em>{{ d.d }}</em></div>
          </div>
        </section>
      </div>

      <div class="ess-grid g3 mt-3">
        <!-- MY REQUESTS -->
        <section class="ess-card">
          <div class="sec-h"><span class="ic sm ic-violet"><i class="fa-solid fa-hourglass-half"></i></span><h6>Permohonan dalam proses</h6>
            <router-link to="/ess/requests" class="more">Semua <i class="fa-solid fa-arrow-right"></i></router-link></div>
          <div v-for="r in h.my_pending || []" :key="r.id" class="li">
            <span class="ic sm" :style="{ background: TYPE[r.type].bg, color: TYPE[r.type].color }"><i :class="TYPE[r.type].icon"></i></span>
            <span class="min0 flex-grow-1"><b>{{ TYPE[r.type].label }}</b><small>{{ dateText(r) }} · {{ qtyText(r) }}</small></span>
            <span class="st st-pending">Peringkat {{ r.current_step }}</span>
          </div>
          <div v-if="!(h.my_pending || []).length" class="empty sm"><i class="fa-regular fa-circle-check"></i>Tiada permohonan tertunggak</div>
          <div class="mini-meters" v-if="h.employee">
            <div><small>OT bulan ini</small><div class="meter"><div :style="{ width: Math.min(100, h.ot.hours / h.ot.cap * 100) + '%' }"></div></div><b>{{ h.ot.hours }} / {{ h.ot.cap }} j</b></div>
            <div><small>Slip time-off</small><div class="meter t"><div :style="{ width: Math.min(100, h.timeoff.used / h.timeoff.limit * 100) + '%' }"></div></div><b>{{ h.timeoff.used }} / {{ h.timeoff.limit }}</b></div>
          </div>
        </section>

        <!-- TEAM -->
        <section class="ess-card">
          <div class="sec-h"><span class="ic sm ic-teal"><i class="fa-solid fa-people-group"></i></span><h6>Rakan bercuti (7 hari)</h6>
            <router-link to="/ess/team" class="more">Kalendar <i class="fa-solid fa-arrow-right"></i></router-link></div>
          <div v-for="(t, i) in h.team_out || []" :key="i" class="li">
            <span class="av" :style="{ background: t.color || '#64748b' }">{{ initials(t.name) }}</span>
            <span class="min0 flex-grow-1"><b class="ell">{{ t.name }}</b><small>{{ t.leave }} · {{ d2(t.start_date) }}{{ t.end_date !== t.start_date ? ' – ' + d2(t.end_date) : '' }}</small></span>
            <span v-if="t.status === 'pending'" class="st st-pending">Menunggu</span>
          </div>
          <div v-if="!(h.team_out || []).length" class="empty sm"><i class="fa-solid fa-mug-hot"></i>Semua rakan bertugas</div>
          <div class="sec-h mt-3"><span class="ic sm ic-rose"><i class="fa-solid fa-star"></i></span><h6>Cuti umum akan datang</h6></div>
          <div v-for="x in h.holidays" :key="x.id" class="hol"><span class="hd"><b>{{ new Date(String(x.date).slice(0,10)).getDate() }}</b><small>{{ monthName(+String(x.date).slice(5,7)) }}</small></span><span>{{ x.name }}</span></div>
        </section>

        <!-- MEMOS + NOTIFICATIONS -->
        <section class="ess-card">
          <div class="sec-h"><span class="ic sm ic-amber"><i class="fa-solid fa-bullhorn"></i></span><h6>Memo syarikat</h6>
            <router-link to="/ess/memos" class="more">Semua <i class="fa-solid fa-arrow-right"></i></router-link></div>
          <router-link v-for="m in h.memos" :key="m.id" :to="`/ess/memos?id=${m.id}`" class="memo lift" :class="{ unread: !m.read_at }">
            <span class="pri" :class="m.priority"></span>
            <span class="min0"><b class="ell">{{ m.pinned ? '📌 ' : '' }}{{ m.title }}</b><small>{{ d2(m.published_at) }} · {{ m.author || 'HR' }}</small></span>
          </router-link>
          <div v-if="!h.memos.length" class="empty sm"><i class="fa-regular fa-envelope-open"></i>Tiada memo</div>
          <div class="sec-h mt-3"><span class="ic sm ic-indigo"><i class="fa-solid fa-bell"></i></span><h6>Terkini</h6></div>
          <div v-for="n in h.notifications.slice(0, 4)" :key="n.id" class="li note" :class="{ unread: !n.read_at }">
            <i :class="n.icon || 'fa-solid fa-bell'"></i><span class="min0"><b class="ell">{{ n.title }}</b><small>{{ ago(n.created_at) }}</small></span>
          </div>
          <div v-if="!h.notifications.length" class="empty sm"><i class="fa-regular fa-bell"></i>Tiada notifikasi</div>
        </section>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { ess, essState, refreshHome, openForm, TYPE, dateText, qtyText, d2, hm, initials, ago, monthName, locate } from '../../services/ess'
import { kbUi } from '../../services/kbStore'
import SelfieCapture from '../../components/ess/SelfieCapture.vue'
const selfie = ref(null)

const router = useRouter()
const h = computed(() => essState.home)
const year = new Date().getFullYear()
const now = ref({}), punching = ref(false), punchMsg = ref(''), punchErr = ref(false)
const firstName = computed(() => { const n = (h.value?.employee?.name || h.value?.user?.name || '').split(' '); return n.slice(0, n[1] && n[1].length > 2 ? 2 : 1).join(' ') })
const role = computed(() => (h.value?.employee?.role || '').replace(/_/g, ' '))
const greet = computed(() => { const x = new Date().getHours(); return x < 12 ? 'Selamat pagi' : x < 15 ? 'Selamat tengah hari' : x < 19 ? 'Selamat petang' : 'Selamat malam' })
const punchState = computed(() => !h.value?.today?.timeclock_in ? 'in' : !h.value?.today?.timeclock_out ? 'out' : 'done')
const workedMin = computed(() => {
  const t = h.value?.today; if (!t?.timeclock_in) return 0
  const s = new Date(`1970-01-01T${t.timeclock_in}`), e = t.timeclock_out ? new Date(`1970-01-01T${t.timeclock_out}`) : new Date(`1970-01-01T${new Date().toTimeString().slice(0, 8)}`)
  return Math.max(0, (e - s) / 60000)
})
const workedText = computed(() => workedMin.value ? `${Math.floor(workedMin.value / 60)}j ${Math.round(workedMin.value % 60)}m` : '—')
const dayPct = computed(() => Math.min(1, workedMin.value / 540))   // 9-hour day
const mainBalances = computed(() => (h.value?.balances || []).filter(b => ['annual', 'sick', 'replacement', 'hospital'].includes(b.code) && b.entitled !== null).slice(0, 4))
const stats = computed(() => {
  const m = h.value?.month || {}
  return [
    { l: 'Hari hadir', v: m.present ?? 0, i: 'fa-solid fa-calendar-check', c: 'ic-green' },
    { l: 'Tepat masa', v: m.on_time_pct != null ? m.on_time_pct + '%' : '—', i: 'fa-solid fa-stopwatch', c: 'ic-blue' },
    { l: 'Purata masuk', v: m.avg_in || '—', i: 'fa-solid fa-door-open', c: 'ic-indigo' },
    { l: 'Jam bekerja', v: m.hours ?? 0, i: 'fa-solid fa-hourglass-end', c: 'ic-violet' },
    { l: 'Lewat', v: m.late ?? 0, i: 'fa-solid fa-person-running', c: m.late ? 'ic-amber' : 'ic-slate' },
    { l: 'Tiada clock-out', v: m.missing_out ?? 0, i: 'fa-solid fa-triangle-exclamation', c: m.missing_out ? 'ic-rose' : 'ic-slate' },
  ]
})
const week = computed(() => {
  const by = Object.fromEntries((h.value?.week || []).map(w => [String(w.date).slice(0, 10), w])); const out = []
  for (let i = 6; i >= 0; i--) {
    const d = new Date(); d.setDate(d.getDate() - i); const iso = d.toLocaleDateString('en-CA'), w = by[iso]
    const late = w?.timeclock_in && w.timeclock_in > '08:30' && w.timeclock_in < '12:00'
    const cls = !w ? (d.getDay() === 0 ? 'rest' : 'none') : w.status === 'on_leave' ? 'leave' : late ? 'late' : w.timeclock_in ? 'ok' : 'none'
    out.push({ date: iso, d: d.getDate(), dow: ['Ahd', 'Isn', 'Sel', 'Rab', 'Kha', 'Jum', 'Sab'][d.getDay()], cls,
      icon: { ok: 'fa-solid fa-check', late: 'fa-solid fa-clock', leave: 'fa-solid fa-umbrella-beach', rest: 'fa-solid fa-mug-hot', none: 'fa-solid fa-minus' }[cls],
      title: w ? `${w.status || ''} ${hm(w.timeclock_in)}–${hm(w.timeclock_out)}` : '' })
  }
  return out
})
const actions = computed(() => {
  const a = [
    { l: 'Mohon Cuti', i: 'fa-solid fa-umbrella-beach', c: 'ic-cyan', do: () => openForm('leave') },
    { l: 'Kerja Lebih Masa', i: 'fa-solid fa-business-time', c: 'ic-violet', do: () => openForm('overtime') },
    { l: 'Time-off', i: 'fa-solid fa-person-walking-arrow-right', c: 'ic-teal', do: () => openForm('timeoff') },
    { l: 'Tuntutan', i: 'fa-solid fa-receipt', c: 'ic-green', do: () => openForm('claim') },
    { l: 'Kehadiran', i: 'fa-solid fa-fingerprint', c: 'ic-blue', do: () => router.push('/ess/attendance') },
    { l: 'Memo', i: 'fa-solid fa-bullhorn', c: 'ic-amber', badge: essState.memos, do: () => router.push('/ess/memos') },
    { l: 'Polisi HR', i: 'fa-solid fa-scale-balanced', c: 'ic-pink', do: () => router.push('/ess/policies') },
    { l: 'Kalendar', i: 'fa-solid fa-calendar-days', c: 'ic-orange', do: () => router.push('/ess/team') },
    { l: 'Laporan', i: 'fa-solid fa-chart-pie', c: 'ic-indigo', do: () => router.push('/ess/reports') },
    { l: 'Tanya AI', i: 'fa-solid fa-wand-magic-sparkles', c: 'ic-violet', do: () => { kbUi.askOpen = true } },
  ]
  if (h.value?.roles?.approver) a.splice(5, 0, { l: 'Kelulusan', i: 'fa-solid fa-stamp', c: 'ic-rose', badge: essState.approvals, do: () => router.push('/ess/approvals') })
  return a
})

async function punch() {
  const dir = punchState.value === 'in' ? 'in' : 'out'
  const cap = await selfie.value.capture(dir)          // selfie + GPS (with accuracy)
  if (!cap) return
  punching.value = true; punchMsg.value = ''; punchErr.value = false
  try {
    const r = await ess.clock(dir, cap)
    await refreshHome()
    const t = new Date().toTimeString().slice(0, 5), pl = r.place
    const where = !cap.lat ? 'tanpa lokasi (GPS tidak dibenarkan)' : pl ? (pl.inside ? `✅ dalam kawasan ${pl.code} (${pl.dist} m)` : `⚠️ di luar kawasan — ${pl.dist} m dari ${pl.code}, penyelia dimaklumkan`) : 'lokasi GPS direkod'
    punchErr.value = !!(pl && !pl.inside)
    punchMsg.value = `${dir === 'in' ? 'Clock-in' : 'Clock-out'} ${t} · ${where}${cap.photo ? ' · selfie disimpan' : ''}`
  } catch (e) { punchErr.value = true; punchMsg.value = e.message } finally { punching.value = false }
}
function nudgeGo(n) {
  if (n.action === 'checkin') return punch()
  router.push({ attendance: '/ess/attendance', leave: '/ess/leave', overtime: '/ess/overtime', approvals: '/ess/approvals', memos: '/ess/memos' }[n.action] || '/ess')
}
const tick = () => { const d = new Date(); now.value = { time: d.toTimeString().slice(0, 5), sec: ':' + d.toTimeString().slice(6, 8), date: d.toLocaleDateString('ms-MY', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) } }
let t; onMounted(() => { tick(); t = setInterval(tick, 1000); refreshHome() }); onBeforeUnmount(() => clearInterval(t))
</script>

<style scoped>
.hero{display:flex;gap:24px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:26px 28px;border-radius:26px;color:#fff;
  background:linear-gradient(135deg,#312e81 0%,#4f46e5 45%,#7c3aed 75%,#c026d3 100%);box-shadow:0 24px 50px rgba(79,70,229,.35);position:relative;overflow:hidden}
.hero::before{content:'';position:absolute;width:420px;height:420px;border-radius:50%;right:-120px;top:-180px;background:radial-gradient(circle,rgba(255,255,255,.22),transparent 65%)}
.hero::after{content:'';position:absolute;width:260px;height:260px;border-radius:50%;left:30%;bottom:-170px;background:radial-gradient(circle,rgba(34,211,238,.35),transparent 65%)}
.hero-l,.punch{position:relative;z-index:1}
.hello{font-size:22px}.hello b{font-weight:800}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.chips span{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;display:inline-flex;gap:6px;align-items:center;text-transform:capitalize}
.chips .gold{background:rgba(250,204,21,.25);border-color:rgba(250,204,21,.5)}
.clock{font-size:64px;font-weight:800;letter-spacing:-.03em;line-height:1;margin-top:16px;font-variant-numeric:tabular-nums}
.clock small{font-size:26px;opacity:.6}
.date{opacity:.85;font-size:14px;margin-top:4px}
.punch{display:flex;flex-direction:column;align-items:center;gap:12px}
.ring-wrap{position:relative;width:170px;height:170px}
.ring{position:absolute;inset:0;transform:rotate(-90deg)}
.ring circle{fill:none;stroke-width:8;stroke-linecap:round}.ring .bg{stroke:rgba(255,255,255,.18)}
.ring .fg{stroke:#4ade80;stroke-dasharray:327;transition:stroke-dashoffset .6s}
.punch-btn{position:absolute;inset:18px;border-radius:50%;border:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;font-weight:800;font-size:15px;
  color:#4338ca;background:#fff;box-shadow:0 12px 30px rgba(0,0,0,.25),inset 0 -4px 0 rgba(79,70,229,.15);transition:transform .15s}
.punch-btn i{font-size:34px}
.punch-btn:hover:not(:disabled){transform:scale(1.05)}
.punch-btn.in{animation:breathe 2.4s infinite}
.punch-btn.out{color:#e11d48}
.punch-btn.done{color:#16a34a;opacity:.95}
@keyframes breathe{0%,100%{box-shadow:0 12px 30px rgba(0,0,0,.25),0 0 0 0 rgba(255,255,255,.5)}50%{box-shadow:0 12px 30px rgba(0,0,0,.25),0 0 0 14px rgba(255,255,255,0)}}
.punch-meta{display:flex;gap:18px;text-align:center}
.punch-meta small{display:block;font-size:11px;opacity:.75;text-transform:uppercase;letter-spacing:.06em}.punch-meta b{font-size:17px}
.punch-msg{font-size:12.5px;background:rgba(255,255,255,.18);border-radius:10px;padding:6px 10px;max-width:320px;text-align:center;line-height:1.4}
.punch-msg.bad{background:rgba(225,29,72,.35)}
.noemp{position:relative;z-index:1;max-width:340px;background:rgba(255,255,255,.15);border-radius:16px;padding:14px;font-size:13px;display:flex;gap:10px}
.nudges{display:flex;gap:10px;margin-top:16px;overflow-x:auto;padding-bottom:4px;scrollbar-width:none}
.nudge{flex-shrink:0;display:flex;align-items:center;gap:10px;border:1px solid var(--line);background:#fff;border-radius:16px;padding:10px 14px;font-size:13px;font-weight:600;color:#334155;max-width:380px;text-align:left}
.nudge>i:first-child{width:30px;height:30px;border-radius:10px;display:grid;place-items:center;flex-shrink:0}
.nudge.info>i:first-child{background:#e0e7ff;color:#4f46e5}.nudge.warn>i:first-child{background:#fef3c7;color:#d97706}.nudge.good>i:first-child{background:#d1fae5;color:#059669}
.nudge .go{color:#cbd5e1;font-size:11px}
.qa{display:grid;grid-template-columns:repeat(11,1fr);gap:10px;margin:16px 0}
.qa-i{position:relative;border:1px solid var(--line);background:#fff;border-radius:18px;padding:14px 6px 12px;display:flex;flex-direction:column;align-items:center;gap:8px;font-size:12px;font-weight:700;color:#334155;text-align:center;line-height:1.2}
.qa-b{position:absolute;top:8px;right:10px;background:#e11d48;color:#fff;border-radius:999px;font-size:10px;padding:0 6px}
.g2{grid-template-columns:1fr 1.2fr}.g3{grid-template-columns:repeat(3,1fr)}
.sec-h{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.more{margin-left:auto;font-size:12.5px;font-weight:700;color:var(--pri);text-decoration:none;white-space:nowrap}
.bals{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.bal{position:relative;border:1px solid var(--line);background:#fff;border-radius:16px;padding:12px 8px;text-align:center;display:flex;flex-direction:column;align-items:center}
.mini-ring{width:64px;height:64px;transform:rotate(-90deg)}
.mini-ring circle{fill:none;stroke-width:6;stroke-linecap:round}.mini-ring .bg{stroke:#f1f5f9}.mini-ring .fg{stroke-dasharray:170;transition:stroke-dashoffset .6s}
.bal-n{position:absolute;top:30px;font-weight:800;font-size:18px}
.bal b{font-size:12.5px;margin-top:6px;line-height:1.2}.bal small{font-size:10.5px;color:var(--muted)}
.stats{display:grid;grid-template-columns:repeat(6,1fr);gap:8px}
.stat{display:flex;flex-direction:column;align-items:center;gap:4px;background:#f8fafc;border-radius:14px;padding:10px 4px;text-align:center}
.stat b{font-size:17px}.stat small{font-size:10.5px;color:var(--muted);line-height:1.2}
.week{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-top:12px}
.wd{display:flex;flex-direction:column;align-items:center;gap:4px;border-radius:14px;padding:8px 0;background:#f8fafc}
.wd small{font-size:10.5px;color:var(--muted);font-weight:700}.wd em{font-style:normal;font-size:11px;color:#94a3b8}
.wd span{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;font-size:11px;background:#e2e8f0;color:#94a3b8}
.wd.ok span{background:#16a34a;color:#fff}.wd.late span{background:#f59e0b;color:#fff}.wd.leave span{background:#0891b2;color:#fff}.wd.rest span{background:#f1f5f9;color:#cbd5e1}
.li{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px dashed var(--line)}
.li:last-of-type{border-bottom:0}
.li b{display:block;font-size:13px}.li small{display:block;font-size:11.5px;color:var(--muted)}
.ell{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.av{width:32px;height:32px;border-radius:50%;color:#fff;font-size:11px;font-weight:800;display:grid;place-items:center;flex-shrink:0}
.empty.sm{padding:14px;font-size:12.5px}.empty.sm i{font-size:22px;margin-bottom:6px}
.mini-meters{display:grid;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)}
.mini-meters>div{display:grid;grid-template-columns:100px 1fr auto;gap:10px;align-items:center;font-size:12px}
.meter{height:8px;background:#f1f5f9;border-radius:99px;overflow:hidden}.meter div{height:100%;background:linear-gradient(90deg,#8b5cf6,#6366f1);border-radius:99px}
.meter.t div{background:linear-gradient(90deg,#22d3ee,#0891b2)}
.hol{display:flex;gap:10px;align-items:center;font-size:13px;padding:5px 0}
.hd{width:42px;border-radius:10px;background:#fff1f2;color:#e11d48;text-align:center;line-height:1;padding:5px 0;flex-shrink:0}.hd b{display:block;font-size:16px}.hd small{font-size:10px;font-weight:700}
.memo{display:flex;gap:10px;align-items:center;padding:9px 10px;border-radius:12px;text-decoration:none;color:var(--ink);background:#f8fafc;margin-bottom:6px}
.memo.unread{background:#fffbeb}.memo b{display:block;font-size:13px}.memo small{color:var(--muted);font-size:11.5px}
.pri{width:4px;align-self:stretch;border-radius:4px;background:#cbd5e1}.pri.important{background:#f59e0b}.pri.urgent{background:#e11d48}
.note i{width:28px;height:28px;border-radius:9px;background:#f1f5f9;display:grid;place-items:center;font-size:12px;color:#64748b;flex-shrink:0}
.note.unread i{background:#e0e7ff;color:#4f46e5}
@media (max-width:1399.98px){ .qa{grid-template-columns:repeat(6,1fr)} .g3{grid-template-columns:1fr 1fr} }
@media (max-width:991.98px){ .g2,.g3{grid-template-columns:1fr} .stats{grid-template-columns:repeat(3,1fr)} }
@media (max-width:767.98px){
  .hero{padding:20px;border-radius:22px;gap:16px}.clock{font-size:46px}.hello{font-size:18px}
  .punch{width:100%}.ring-wrap{width:150px;height:150px}
  .qa{grid-template-columns:repeat(4,1fr);gap:8px}.qa-i{padding:10px 4px;font-size:11px}
  .bals{grid-template-columns:repeat(2,1fr)}
}
</style>
