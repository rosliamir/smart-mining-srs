<template>
  <div class="login-bg">
    <video class="bg-video" autoplay loop muted playsinline poster="/img/bg-login.jpg">
      <source src="https://mining-api.s3.ap-southeast-5.amazonaws.com/uploads/HORAS_3000_10b5f98d06.mp4" type="video/mp4"/>
    </video>
    <div class="overlay"></div>

    <!-- center glass sign-in card (the timeline + project stats are part of the background video) -->
    <div class="glass card">
      <img src="/img/kssb.png" class="mx-auto d-block mb-4" style="height:120px"/>
      <label class="glbl">Email / Staff code</label>
      <input class="form-control mb-3" v-model="u" placeholder="e.g. KS0123" />
      <label class="glbl">Password</label>
      <input class="form-control mb-4" type="password" v-model="p" @keyup.enter="login"/>
      <div v-if="err" class="lerr"><i class="fa-solid fa-circle-exclamation me-1"></i>{{ err }}</div>
      <button class="btn btn-primary w-100 py-2" :disabled="busy" @click="login">{{ busy ? 'Signing in…' : 'Submit' }}</button>
      <div class="lhint"><i class="fa-solid fa-id-card-clip me-1"></i>Staff: sign in with your staff code to open Employee Self-Service.</div>
    </div>
  </div>
</template>
<script setup>
import { ref } from 'vue'; import { useRouter } from 'vue-router'
import { login as apiLogin } from '../services/api'
const u=ref('admin@kssb.local'), p=ref('password'), err=ref(''), busy=ref(false); const router=useRouter()
async function login(){
  err.value=''; busy.value=true
  try {
    const d = await apiLogin(u.value.trim(), p.value)
    router.push(d.user?.ess_only ? '/ess' : '/map')
  } catch(e){
    if (e instanceof TypeError) { localStorage.setItem('sm_auth','1'); router.push('/map') }   // API unreachable -> offline demo with mock data
    else err.value = 'Invalid staff code / e-mail or password.'
  } finally { busy.value=false }
}
</script>
<style scoped>
.login-bg{position:fixed;inset:0;background:#0a141e;display:flex;align-items:center;justify-content:center;overflow:hidden}
.bg-video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.overlay{position:absolute;inset:0;background:rgba(10,20,30,.35)}
.glass{position:relative;z-index:3;width:400px;max-width:92vw;padding:30px 34px;background:rgba(255,255,255,.14);
  backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.25);border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.35)}
.glbl{color:#fff;font-weight:600;font-size:14px;margin-bottom:5px;display:block}
.lerr{background:rgba(225,29,72,.85);color:#fff;border-radius:10px;padding:8px 12px;font-size:13px;margin-bottom:12px}
.lhint{color:rgba(255,255,255,.8);font-size:12px;text-align:center;margin-top:14px}
.glass .form-control{border:0;border-radius:10px;height:44px}
.powered-in{color:rgba(255,255,255,.75);font-size:11px;font-style:italic}
</style>
