<template>
  <div v-if="isLogin"><router-view/></div>
  <div v-else class="d-flex flex-column min-vh-100">
    <!-- TOP NAVBAR (matches live) -->
    <header class="app-nav border-0">
      <div class="container-fluid px-4 d-flex align-items-center">
        <img src="/img/kssb.png" class="logo-navbar me-4" @click="go('/map')" />
        <nav class="d-none d-lg-flex align-items-center gap-1 flex-grow-1">
          <router-link v-for="n in nav" :key="n.to" :to="n.to" class="nav-link app-link" active-class="active">
            <i :class="n.icon" class="me-1 d-none"></i>{{ n.label }}
          </router-link>
        </nav>
        <div class="ms-auto d-flex align-items-center gap-3">
          <img src="/img/ksmm.png" class="logo-navbar d-none d-lg-block" />
          <button class="btn btn-sm border-0 rounded-circle profile-btn kb-btn" @click="openKb()" title="Knowledge base">
            <i class="fa-solid fa-book-open"></i>
          </button>
          <div class="dropdown">
            <button class="btn btn-sm border-0 rounded-circle profile-btn" data-bs-toggle="dropdown">
              <i class="fa-regular fa-user"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:280px">
              <li class="user-box mx-2 my-1">
                <div class="fw-bold d-flex align-items-center gap-2"><i class="fa-solid fa-circle-check text-dark"></i>{{ (userRole||'SUPER ADMIN').toUpperCase() }}</div>
                <div class="d-flex align-items-center gap-2 mt-1"><i class="fa-regular fa-user text-primary"></i><span>{{ userEmail }}</span></div>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li><router-link class="dropdown-item" to="/account"><i class="fa-solid fa-gear me-2 text-secondary"></i>Account Settings</router-link></li>
              <li><hr class="dropdown-divider"></li>
              <li><router-link class="dropdown-item" to="/site-management"><i class="fa-solid fa-location-dot me-2 text-secondary"></i>Site Management</router-link></li>
              <li><router-link class="dropdown-item" to="/user-management"><i class="fa-solid fa-users me-2 text-secondary"></i>User Management</router-link></li>
              <li><hr class="dropdown-divider"></li>
              <li><button class="dropdown-item" @click="openKb()"><i class="fa-solid fa-book-open me-2 text-primary"></i>Knowledge Base</button></li>
              <li><button class="dropdown-item" @click="openSync"><i class="fa-solid fa-rotate me-2 text-primary"></i>Sync Data</button></li>
              <li><hr class="dropdown-divider"></li>
              <li><button class="dropdown-item text-danger" @click="logout"><i class="fa-solid fa-right-from-bracket me-2"></i>Sign out</button></li>
            </ul>
          </div>
        </div>
      </div>
    </header>

    <main class="flex-grow-1 app-main"><router-view/></main>

    <!-- MOBILE BOTTOM NAV (top links are hidden below lg) -->
    <nav class="mnav d-lg-none">
      <router-link v-for="n in nav" :key="n.to" :to="n.to" class="mnav-link" active-class="active">
        <i :class="n.icon"></i><span>{{ n.label }}</span>
      </router-link>
    </nav>

    <!-- Knowledge base popup + Ask assistant (every page) -->
    <KnowledgeBaseModal/>
    <AskAssistant/>

    <!-- Sync Data modal -->
    <div v-if="syncOpen" class="sync-backdrop" @click.self="!syncing && (syncOpen=false)">
      <div class="sync-card">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div class="d-flex align-items-center gap-2"><span class="sync-ic"><i class="fa-solid fa-rotate"></i></span><h5 class="fw-bold mb-0">Sync Data</h5></div>
          <button class="btn-x" @click="!syncing && (syncOpen=false)"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p class="text-muted2">Pull the latest data from the live system into this database, so your records stay in sync.</p>
        <div v-if="syncResult" class="sync-ok"><i class="fa-solid fa-circle-check me-2"></i>
          Synced <b>{{ syncResult.attendances?.toLocaleString() }}</b> attendance records,
          <b>{{ syncResult.daily_reports?.toLocaleString() }}</b> daily reports and
          <b>{{ syncResult.sales_records?.toLocaleString() }}</b> sales records.
          <div class="small mt-1">Latest attendance {{ syncResult.latest_date }} · sales range {{ syncResult.sales_date_range }}.</div></div>
        <div v-if="syncError" class="sync-err"><i class="fa-solid fa-triangle-exclamation me-2"></i>{{ syncError }}</div>
        <div class="d-flex justify-content-end gap-2 mt-3">
          <button class="btn btn-light px-3" :disabled="syncing" @click="syncOpen=false">Close</button>
          <button class="btn btn-primary px-3" :disabled="syncing" @click="runSync">
            <span v-if="syncing"><i class="fa-solid fa-spinner fa-spin me-2"></i>Pulling latest…</span>
            <span v-else><i class="fa-solid fa-cloud-arrow-down me-2"></i>Pull Latest Data</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiPost, currentUser } from './services/api'
import { openKb } from './services/kbStore'
import KnowledgeBaseModal from './components/KnowledgeBaseModal.vue'
import AskAssistant from './components/AskAssistant.vue'
const route = useRoute(); const router = useRouter()
const _u = currentUser()
const userEmail = computed(()=> _u?.email || 'admin@kssb.local')
const userRole = computed(()=> (_u?.roles?.[0] || 'super_admin').replace(/_/g,' '))
const syncOpen=ref(false), syncing=ref(false), syncResult=ref(null), syncError=ref('')
function openSync(){ syncOpen.value=true; syncResult.value=null; syncError.value='' }
async function runSync(){
  syncing.value=true; syncResult.value=null; syncError.value=''
  try { const d=await apiPost('sync'); if(d.ok) syncResult.value=d; else syncError.value=d.error||'Sync failed' }
  catch(e){ syncError.value=e.message||'Sync failed' }
  finally { syncing.value=false }
}
const isLogin = computed(()=> route.path==='/login' || route.path.startsWith('/ess'))   // ESS has its own shell
const nav = [
  {to:'/map',label:'Map',icon:'fa-solid fa-map'},
  {to:'/dashboard',label:'Dashboard',icon:'fa-solid fa-table-cells-large'},
  {to:'/monitoring',label:'Monitoring',icon:'fa-solid fa-chart-line'},
  {to:'/attendance',label:'Attendance',icon:'fa-solid fa-calendar-check'},
  {to:'/ess',label:'Self-Service',icon:'fa-solid fa-id-card-clip'},
]
function go(p){ router.push(p) }
function logout(){ localStorage.removeItem('sm_auth'); router.push('/login') }
</script>

<style scoped>
.profile-btn{width:38px;height:38px;color:#374151;background:#fff}
.profile-btn:hover{background:#f1f5f9}
.kb-btn{color:#4f46e5;background:#eef2ff}
.kb-btn:hover{background:#4f46e5!important;color:#fff}
.app-nav{box-shadow:0 1px 0 rgba(16,24,40,.04)}
.mnav{position:fixed;left:0;right:0;bottom:0;height:62px;background:#fff;border-top:1px solid #eef0f3;display:flex;z-index:1500;padding-bottom:env(safe-area-inset-bottom);box-shadow:0 -6px 20px rgba(16,24,40,.06)}
.mnav-link{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;color:#94a3b8;text-decoration:none;font-size:11px;font-weight:600;transition:color .15s}
.mnav-link i{font-size:18px;transition:transform .15s}
.mnav-link.active{color:#0d6efd}.mnav-link.active i{transform:translateY(-2px)}
@media (max-width:991.98px){ .app-main{padding-bottom:62px} }
.sync-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);display:grid;place-items:center;z-index:3000;padding:20px}
.sync-card{background:#fff;border-radius:18px;padding:24px;width:100%;max-width:480px;box-shadow:0 30px 80px rgba(0,0,0,.3)}
.sync-ic{width:38px;height:38px;border-radius:10px;background:#0d6efd;color:#fff;display:grid;place-items:center}
.btn-x{border:0;background:transparent;font-size:18px;color:#6b7280}
.user-box{background:#f3f4f6;border-radius:12px;padding:12px 14px;font-size:14px}
.user-box .fw-bold{color:#374151;letter-spacing:.03em}
.sync-ok{background:#dcfce7;color:#15803d;border-radius:10px;padding:12px 14px;font-size:14px}
.sync-err{background:#fee2e2;color:#b91c1c;border-radius:10px;padding:12px 14px;font-size:14px}
</style>
