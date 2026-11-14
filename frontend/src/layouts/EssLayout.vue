<template>
  <div class="ess ess-shell">
    <!-- sidebar (desktop) -->
    <aside class="side">
      <router-link to="/ess" class="brand"><img src="/img/kssb.png" /><div><b>KSSB</b><small>Layan Diri Pekerja</small></div></router-link>
      <nav>
        <template v-for="g in groups" :key="g.t">
          <div v-if="g.items.length" class="grp">{{ g.t }}</div>
          <router-link v-for="n in g.items" :key="n.to" :to="n.to" class="nl" :class="{ on: isOn(n) }">
            <span class="ic sm" :class="n.c"><i :class="n.i"></i></span><span class="flex-grow-1">{{ n.l }}</span>
            <span v-if="n.badge" class="bdg">{{ n.badge }}</span>
          </router-link>
        </template>
      </nav>
      <div class="side-foot">
        <router-link v-if="!essOnly" to="/dashboard" class="nl"><span class="ic sm ic-slate"><i class="fa-solid fa-display"></i></span>Konsol Admin</router-link>
        <button class="nl w-100" @click="logout"><span class="ic sm ic-rose"><i class="fa-solid fa-right-from-bracket"></i></span>Log keluar</button>
      </div>
    </aside>

    <div class="main">
      <!-- top bar -->
      <header class="top">
        <img src="/img/kssb.png" class="top-logo" @click="$router.push('/ess')" />
        <div class="top-t min0"><b>{{ title }}</b><span class="d-none d-md-inline muted">· {{ clock }}</span></div>
        <div class="ms-auto d-flex align-items-center gap-2">
          <button class="tb d-none d-md-grid" title="Mohon baharu" @click="openForm()"><i class="fa-solid fa-plus"></i></button>
          <button class="tb" title="Knowledge base / Polisi" @click="$router.push('/ess/policies')"><i class="fa-solid fa-book-open"></i></button>
          <div class="pos-rel" ref="bellWrap">
            <button class="tb" :class="{ ring: essState.unread }" title="Notifikasi" @click="toggleBell"><i class="fa-solid fa-bell"></i>
              <span v-if="essState.unread" class="dot">{{ essState.unread > 9 ? '9+' : essState.unread }}</span></button>
            <transition name="pop">
              <div v-if="bell" class="bell">
                <div class="d-flex justify-content-between align-items-center px-3 pt-3 pb-2"><b>Notifikasi</b>
                  <button class="link" @click="readAll">Tandakan semua dibaca</button></div>
                <div class="bell-list">
                  <button v-for="n in notes" :key="n.id" class="bn" :class="{ unread: !n.read_at }" @click="goNote(n)">
                    <span class="ic sm" :class="noteColor(n.type)"><i :class="n.icon || 'fa-solid fa-bell'"></i></span>
                    <span class="min0 text-start"><b>{{ n.title }}</b><small>{{ n.body }}</small><em>{{ ago(n.created_at) }}</em></span>
                  </button>
                  <div v-if="!notes.length" class="empty"><i class="fa-regular fa-bell-slash"></i>Tiada notifikasi</div>
                </div>
                <router-link to="/ess/notifications" class="bell-all" @click="bell = false">Lihat semua</router-link>
              </div>
            </transition>
          </div>
          <router-link to="/ess/profile" class="av" :title="user?.name">{{ initials(user?.name) }}</router-link>
        </div>
      </header>

      <main><router-view v-slot="{ Component }"><transition name="page" mode="out-in"><component :is="Component" :key="$route.path" /></transition></router-view></main>
    </div>

    <!-- mobile tab bar -->
    <nav class="tabbar">
      <router-link to="/ess" class="tab" :class="{ on: $route.path === '/ess' }"><i class="fa-solid fa-house"></i><span>Utama</span></router-link>
      <router-link to="/ess/attendance" class="tab" active-class="on"><i class="fa-solid fa-fingerprint"></i><span>Kehadiran</span></router-link>
      <button class="tab plus" @click="openForm()"><i class="fa-solid fa-plus"></i></button>
      <router-link to="/ess/requests" class="tab" active-class="on"><i class="fa-solid fa-folder-open"></i><span>Permohonan</span></router-link>
      <button class="tab" :class="{ on: more }" @click="more = !more"><i class="fa-solid fa-grip"></i><span>Lagi</span>
        <span v-if="essState.approvals + essState.memos" class="tdot"></span></button>
    </nav>
    <transition name="sheet">
      <div v-if="more" class="sheet-bg" @click.self="more = false">
        <div class="sheet">
          <div class="grab"></div>
          <div class="more-grid">
            <router-link v-for="n in allItems" :key="n.to" :to="n.to" class="mg" @click="more = false">
              <span class="ic lg" :class="n.c"><i :class="n.i"></i></span><span>{{ n.l }}</span>
              <span v-if="n.badge" class="bdg abs">{{ n.badge }}</span></router-link>
            <router-link v-if="!essOnly" to="/dashboard" class="mg" @click="more = false"><span class="ic lg ic-slate"><i class="fa-solid fa-display"></i></span><span>Konsol Admin</span></router-link>
            <button class="mg" @click="logout"><span class="ic lg ic-rose"><i class="fa-solid fa-right-from-bracket"></i></span><span>Log keluar</span></button>
          </div>
        </div>
      </div>
    </transition>

    <RequestForm />
    <KnowledgeBaseModal />
    <AskAssistant />
  </div>
</template>

<script setup>
import '../styles/ess.css'
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { currentUser } from '../services/api'
import { ess, essState, refreshHome, openForm, initials, ago } from '../services/ess'
import RequestForm from '../components/ess/RequestForm.vue'
import KnowledgeBaseModal from '../components/KnowledgeBaseModal.vue'
import AskAssistant from '../components/AskAssistant.vue'

const route = useRoute(), router = useRouter()
const user = currentUser()
const essOnly = computed(() => !!user?.ess_only)
const more = ref(false), bell = ref(false), notes = ref([]), bellWrap = ref(null), clock = ref('')

const groups = computed(() => {
  const ap = essState.home?.roles
  return [
    { t: 'Utama', items: [
      { to: '/ess', l: 'Papan Utama', i: 'fa-solid fa-house', c: 'ic-indigo', exact: true },
      { to: '/ess/attendance', l: 'Kehadiran', i: 'fa-solid fa-fingerprint', c: 'ic-blue' },
      { to: '/ess/leave', l: 'Cuti', i: 'fa-solid fa-umbrella-beach', c: 'ic-cyan' },
      { to: '/ess/overtime', l: 'Kerja Lebih Masa', i: 'fa-solid fa-business-time', c: 'ic-violet' },
      { to: '/ess/timeoff', l: 'Time-off', i: 'fa-solid fa-person-walking-arrow-right', c: 'ic-teal' },
      { to: '/ess/claims', l: 'Tuntutan', i: 'fa-solid fa-receipt', c: 'ic-green' },
      { to: '/ess/requests', l: 'Semua Permohonan', i: 'fa-solid fa-folder-open', c: 'ic-slate' },
    ] },
    { t: 'Pengurusan', items: ap?.approver ? [{ to: '/ess/approvals', l: 'Kelulusan', i: 'fa-solid fa-stamp', c: 'ic-orange', badge: essState.approvals },
      { to: '/ess/hr', l: 'Pemantauan Kehadiran', i: 'fa-solid fa-map-location-dot', c: 'ic-teal' }] : [] },
    { t: 'Maklumat', items: [
      { to: '/ess/memos', l: 'Memo Syarikat', i: 'fa-solid fa-bullhorn', c: 'ic-amber', badge: essState.memos },
      { to: '/ess/notifications', l: 'Notifikasi', i: 'fa-solid fa-bell', c: 'ic-rose', badge: essState.unread },
      { to: '/ess/policies', l: 'Polisi HR & Tanya', i: 'fa-solid fa-scale-balanced', c: 'ic-pink' },
      { to: '/ess/team', l: 'Kalendar & Pasukan', i: 'fa-solid fa-calendar-days', c: 'ic-teal' },
      { to: '/ess/reports', l: 'Laporan Saya', i: 'fa-solid fa-chart-pie', c: 'ic-blue' },
      { to: '/ess/profile', l: 'Profil & Akaun', i: 'fa-solid fa-id-badge', c: 'ic-slate' },
    ] },
  ]
})
const allItems = computed(() => groups.value.flatMap(g => g.items).filter(n => n.to !== '/ess'))
const isOn = n => n.exact ? route.path === n.to : route.path.startsWith(n.to)
const title = computed(() => allItems.value.concat(groups.value[0].items).find(n => isOn(n))?.l || 'Layan Diri Pekerja')
const noteColor = t => ({ approved: 'ic-green', rejected: 'ic-rose', approval: 'ic-orange', memo: 'ic-amber', progress: 'ic-blue' }[t] || 'ic-slate')

async function toggleBell() { bell.value = !bell.value; if (bell.value) notes.value = (await ess.notifications() || []).slice(0, 8) }
async function goNote(n) { if (!n.read_at) { await ess.readNotifications(n.id); refreshHome() } bell.value = false; if (n.link) router.push(n.link) }
async function readAll() { await ess.readNotifications(); notes.value = notes.value.map(n => ({ ...n, read_at: n.read_at || new Date().toISOString() })); refreshHome() }
function logout() { ['sm_auth', 'sm_token', 'sm_user', 'sm_ask'].forEach(k => { localStorage.removeItem(k); sessionStorage.removeItem(k) }); router.push('/login') }
const onDoc = e => { if (bellWrap.value && !bellWrap.value.contains(e.target)) bell.value = false }
const tick = () => { clock.value = new Date().toLocaleString('ms-MY', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) }
let t1, t2
onMounted(() => { refreshHome(); tick(); t1 = setInterval(tick, 30000); t2 = setInterval(refreshHome, 60000); document.addEventListener('click', onDoc) })
onBeforeUnmount(() => { clearInterval(t1); clearInterval(t2); document.removeEventListener('click', onDoc) })
watch(() => route.path, () => { more.value = false; bell.value = false })
</script>

<style scoped>
.ess-shell{min-height:100vh;background:radial-gradient(1200px 500px at 85% -10%,#e0e7ff 0,transparent 60%),radial-gradient(900px 400px at -10% 10%,#cffafe 0,transparent 55%),var(--bg)}
.side{position:fixed;left:0;top:0;bottom:0;width:256px;background:rgba(255,255,255,.85);backdrop-filter:blur(14px);border-right:1px solid var(--line);display:flex;flex-direction:column;z-index:50;padding:16px 12px}
.brand{display:flex;gap:10px;align-items:center;padding:6px 10px 16px;text-decoration:none;color:var(--ink)}
.brand img{height:34px}.brand b{display:block;font-size:15px}.brand small{color:var(--muted);font-size:11.5px}
nav{flex:1;overflow:auto}
.grp{font-size:10.5px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;padding:14px 12px 6px}
.nl{display:flex;align-items:center;gap:10px;padding:7px 10px;border-radius:12px;color:#334155;text-decoration:none;font-weight:600;font-size:13.5px;border:0;background:transparent;transition:background .15s,transform .15s}
.nl:hover{background:#f1f5f9;transform:translateX(2px)}
.nl.on{background:linear-gradient(135deg,#eef2ff,#f5f3ff);color:var(--pri)}
.bdg{background:#e11d48;color:#fff;border-radius:999px;font-size:10.5px;font-weight:800;padding:1px 7px}
.bdg.abs{position:absolute;top:4px;right:10px}
.side-foot{border-top:1px solid var(--line);padding-top:10px;display:flex;flex-direction:column;gap:2px}
.main{margin-left:256px;min-height:100vh}
.top{position:sticky;top:0;z-index:40;height:64px;display:flex;align-items:center;gap:12px;padding:0 28px;background:rgba(244,246,251,.8);backdrop-filter:blur(12px);border-bottom:1px solid rgba(226,232,240,.6)}
.top-logo{height:30px;display:none;cursor:pointer}
.top-t{display:flex;gap:8px;align-items:baseline;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.top-t b{font-size:16px}.top-t span{font-size:13px}
.tb{position:relative;width:40px;height:40px;border-radius:12px;border:1px solid var(--line);background:#fff;color:#334155;display:grid;place-items:center;transition:all .15s}
.tb:hover{background:var(--pri);color:#fff;border-color:var(--pri)}
.tb.ring i{animation:ring 2.4s infinite}
@keyframes ring{0%,90%,100%{transform:rotate(0)}92%{transform:rotate(14deg)}94%{transform:rotate(-12deg)}96%{transform:rotate(8deg)}}
.dot{position:absolute;top:-5px;right:-5px;background:#e11d48;color:#fff;border-radius:999px;font-size:10px;font-weight:800;padding:1px 5px;border:2px solid #fff}
.pos-rel{position:relative}
.bell{position:absolute;right:0;top:48px;width:360px;background:#fff;border-radius:18px;box-shadow:0 24px 60px rgba(15,23,42,.2);border:1px solid var(--line);overflow:hidden;z-index:60}
.link{border:0;background:none;color:var(--pri);font-size:12px;font-weight:600}
.bell-list{max-height:380px;overflow:auto}
.bn{display:flex;gap:10px;width:100%;border:0;background:#fff;padding:10px 14px;transition:background .12s}
.bn:hover{background:#f8fafc}.bn.unread{background:#f5f7ff}
.bn b{display:block;font-size:13px}.bn small{display:block;color:var(--muted);font-size:12px;line-height:1.3}.bn em{font-size:11px;color:#94a3b8;font-style:normal}
.bell-all{display:block;text-align:center;padding:10px;font-weight:700;font-size:13px;border-top:1px solid var(--line);text-decoration:none;color:var(--pri)}
.av{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--pri),var(--pri2));color:#fff;display:grid;place-items:center;font-weight:800;font-size:14px;text-decoration:none;box-shadow:0 6px 16px rgba(79,70,229,.35)}
.tabbar,.sheet-bg{display:none}
.page-enter-from{opacity:0;transform:translateY(8px)}.page-leave-to{opacity:0}
.page-enter-active,.page-leave-active{transition:all .18s}
.pop-enter-from,.pop-leave-to{opacity:0;transform:translateY(-6px)}.pop-enter-active,.pop-leave-active{transition:all .15s}
@media (max-width:991.98px){
  .side{display:none}.main{margin-left:0}
  .top{padding:0 14px}.top-logo{display:block}
  .bell{position:fixed;left:10px;right:10px;top:64px;width:auto}
  .tabbar{display:flex;position:fixed;left:0;right:0;bottom:0;height:66px;background:rgba(255,255,255,.95);backdrop-filter:blur(12px);border-top:1px solid var(--line);z-index:60;padding-bottom:env(safe-area-inset-bottom)}
  .tab{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;border:0;background:none;color:#94a3b8;font-size:10.5px;font-weight:600;text-decoration:none;position:relative}
  .tab i{font-size:18px}.tab.on{color:var(--pri)}
  .tab.plus i{width:52px;height:52px;border-radius:18px;display:grid;place-items:center;background:linear-gradient(135deg,var(--pri),var(--pri2));color:#fff;font-size:20px;margin-top:-26px;box-shadow:0 10px 24px rgba(79,70,229,.45)}
  .tdot{position:absolute;top:10px;right:calc(50% - 16px);width:8px;height:8px;border-radius:50%;background:#e11d48}
  .sheet-bg{display:block;position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:70}
  .sheet{position:absolute;left:0;right:0;bottom:0;background:#fff;border-radius:24px 24px 0 0;padding:10px 16px 90px}
  .grab{width:44px;height:5px;border-radius:99px;background:#e2e8f0;margin:0 auto 14px}
  .more-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px 6px}
  .mg{position:relative;display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;font-size:11.5px;font-weight:600;color:#334155;text-decoration:none;border:0;background:none}
  .sheet-enter-from,.sheet-leave-to{opacity:0}.sheet-enter-from .sheet,.sheet-leave-to .sheet{transform:translateY(40px)}
  .sheet-enter-active,.sheet-leave-active,.sheet-enter-active .sheet,.sheet-leave-active .sheet{transition:all .22s}
}
</style>
