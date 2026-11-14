import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  { path:'/login', component:()=>import('../pages/Login.vue'), meta:{guest:true} },
  { path:'/', redirect:'/map' },
  { path:'/dashboard', component:()=>import('../pages/Dashboard.vue') },
  { path:'/map', component:()=>import('../pages/MapPage.vue') },
  { path:'/live-view', component:()=>import('../pages/LiveView.vue') },
  { path:'/monitoring', component:()=>import('../pages/Monitoring.vue') },
  { path:'/reports', component:()=>import('../pages/Reports.vue') },
  { path:'/attendance', component:()=>import('../pages/Attendance.vue') },
  { path:'/site-management', component:()=>import('../pages/SiteManagement.vue') },
  { path:'/site/:id', component:()=>import('../pages/SiteDetails.vue') },
  { path:'/site/:id/f001-weekly-report/:weeklyId', component:()=>import('../pages/F001Report.vue') },
  { path:'/user-management', component:()=>import('../pages/UserManagement.vue') },
  { path:'/account', component:()=>import('../pages/Account.vue') },
  // ── Employee Self-Service portal ──
  { path:'/ess', component:()=>import('../layouts/EssLayout.vue'), meta:{ess:true}, children:[
    { path:'', component:()=>import('../pages/ess/Home.vue') },
    { path:'attendance', component:()=>import('../pages/ess/Attendance.vue') },
    { path:'leave', component:()=>import('../pages/ess/Requests.vue'), meta:{type:'leave'} },
    { path:'overtime', component:()=>import('../pages/ess/Requests.vue'), meta:{type:'overtime'} },
    { path:'timeoff', component:()=>import('../pages/ess/Requests.vue'), meta:{type:'timeoff'} },
    { path:'claims', component:()=>import('../pages/ess/Requests.vue'), meta:{type:'claim'} },
    { path:'requests', component:()=>import('../pages/ess/Requests.vue') },
    { path:'approvals', component:()=>import('../pages/ess/Approvals.vue') },
    { path:'hr', component:()=>import('../pages/ess/HrMonitor.vue') },
    { path:'memos', component:()=>import('../pages/ess/Memos.vue') },
    { path:'notifications', component:()=>import('../pages/ess/Notifications.vue') },
    { path:'policies', component:()=>import('../pages/ess/Policies.vue') },
    { path:'team', component:()=>import('../pages/ess/Team.vue') },
    { path:'reports', component:()=>import('../pages/ess/Reports.vue') },
    { path:'profile', component:()=>import('../pages/ess/Profile.vue') },
  ]},
]

const router = createRouter({ history: createWebHistory(), routes })
const essOnly = ()=>{ try { return !!JSON.parse(localStorage.getItem('sm_user')||'null')?.ess_only } catch { return false } }
router.beforeEach((to)=>{
  const authed = localStorage.getItem('sm_auth')==='1'
  if(!to.meta.guest && !authed) return '/login'
  if(to.meta.guest && authed) return essOnly() ? '/ess' : '/dashboard'
  // staff-only accounts live in the self-service portal; the operations console is for management
  if(authed && essOnly() && !to.path.startsWith('/ess')) return '/ess'
})
export default router
