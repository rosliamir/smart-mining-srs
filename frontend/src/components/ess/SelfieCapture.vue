<template>
  <transition name="sc">
    <div v-if="open" class="sc-bg" @click.self="cancel">
      <div class="sc">
        <div class="sc-h"><b><i class="fa-solid fa-camera me-2"></i>Selfie {{ dir === 'in' ? 'Clock In' : 'Clock Out' }}</b>
          <button class="x" @click="cancel"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="frame">
          <video v-show="!shot && !err" ref="vid" autoplay playsinline muted></video>
          <img v-if="shot" :src="shot" />
          <div v-if="!shot && !err" class="oval"></div>
          <div v-if="count" class="count">{{ count }}</div>
          <div v-if="err" class="err-c"><i class="fa-solid fa-video-slash"></i><span>{{ err }}</span></div>
        </div>
        <div class="gps" :class="gps.state">
          <i class="fa-solid" :class="gps.state === 'ok' ? 'fa-location-dot' : gps.state === 'wait' ? 'fa-spinner fa-spin' : 'fa-location-crosshairs'"></i>
          {{ gps.state === 'ok' ? `Lokasi dikesan (±${gps.accuracy} m)` : gps.state === 'wait' ? 'Mengesan lokasi GPS…' : 'Lokasi tidak tersedia — benarkan akses lokasi' }}
        </div>
        <div class="sc-f">
          <template v-if="shot"><button class="btn-soft" @click="retake"><i class="fa-solid fa-rotate-left me-1"></i>Ambil semula</button>
            <button class="btn-pri" @click="confirm"><i class="fa-solid fa-check me-1"></i>Sahkan {{ dir === 'in' ? 'Clock In' : 'Clock Out' }}</button></template>
          <template v-else-if="err"><button class="btn-soft" @click="cancel">Batal</button>
            <button class="btn-pri" @click="confirm"><i class="fa-solid fa-forward me-1"></i>Teruskan tanpa selfie</button></template>
          <template v-else><button class="btn-soft" @click="confirm">Langkau</button>
            <button class="btn-pri" :disabled="!!count" @click="snap"><i class="fa-solid fa-camera me-1"></i>Ambil gambar</button></template>
        </div>
        <div class="tiny muted text-center mt-2">Selfie & lokasi digunakan untuk pengesahan kehadiran sahaja.</div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { ref, reactive, nextTick } from 'vue'
const open = ref(false), dir = ref('in'), vid = ref(null), shot = ref(null), err = ref(''), count = ref(0)
const gps = reactive({ state: 'wait', lat: null, lng: null, accuracy: null })
let stream = null, resolver = null

function stop() { stream?.getTracks().forEach(t => t.stop()); stream = null }
async function start() {
  err.value = ''; shot.value = null
  if (!navigator.mediaDevices?.getUserMedia) { err.value = 'Kamera tidak disokong pada peranti ini.'; return }
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 640 } }, audio: false })
    await nextTick(); if (vid.value) vid.value.srcObject = stream
  } catch { err.value = 'Akses kamera ditolak atau tiada kamera.' }
}
function locate() {
  gps.state = 'wait'
  if (!navigator.geolocation) { gps.state = 'none'; return }
  navigator.geolocation.getCurrentPosition(p => Object.assign(gps, { state: 'ok', lat: +p.coords.latitude.toFixed(6), lng: +p.coords.longitude.toFixed(6), accuracy: Math.round(p.coords.accuracy) }),
    () => { gps.state = 'none' }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 })
}
function snap() {
  count.value = 3
  const t = setInterval(() => {
    count.value--
    if (count.value > 0) return
    clearInterval(t)
    const v = vid.value, s = Math.min(v.videoWidth, v.videoHeight) || 480, c = document.createElement('canvas'); c.width = c.height = 480
    c.getContext('2d').drawImage(v, (v.videoWidth - s) / 2, (v.videoHeight - s) / 2, s, s, 0, 0, 480, 480)
    shot.value = c.toDataURL('image/jpeg', .78); stop()
  }, 600)
}
function retake() { start() }
function finish(result) { stop(); open.value = false; resolver?.(result); resolver = null }
function confirm() { finish({ photo: shot.value, lat: gps.lat ?? undefined, lng: gps.lng ?? undefined, accuracy: gps.accuracy ?? undefined }) }
function cancel() { finish(null) }

/** Opens the camera; resolves to {photo, lat, lng, accuracy} or null when cancelled. */
function capture(direction = 'in') { dir.value = direction; open.value = true; count.value = 0; start(); locate(); return new Promise(r => { resolver = r }) }
defineExpose({ capture })
</script>

<style scoped>
.sc-bg{position:fixed;inset:0;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);z-index:2600;display:grid;place-items:center;padding:16px}
.sc{width:100%;max-width:420px;background:#fff;border-radius:24px;padding:16px;box-shadow:0 40px 100px rgba(0,0,0,.4)}
.sc-h{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.x{width:32px;height:32px;border:0;border-radius:50%;background:#f1f5f9}
.frame{position:relative;aspect-ratio:1;border-radius:20px;overflow:hidden;background:#0f172a}
.frame video,.frame img{width:100%;height:100%;object-fit:cover;transform:scaleX(-1)}
.frame img{transform:none}
.oval{position:absolute;inset:12% 20%;border:3px dashed rgba(255,255,255,.7);border-radius:50%;pointer-events:none}
.count{position:absolute;inset:0;display:grid;place-items:center;font-size:96px;font-weight:800;color:#fff;text-shadow:0 4px 20px rgba(0,0,0,.5)}
.err-c{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;color:#cbd5e1;text-align:center;padding:20px}
.err-c i{font-size:40px}
.gps{margin-top:10px;font-size:12.5px;font-weight:600;border-radius:10px;padding:7px 10px;display:flex;gap:8px;align-items:center}
.gps.ok{background:#dcfce7;color:#15803d}.gps.wait{background:#eef2ff;color:#4338ca}.gps.none{background:#fef3c7;color:#b45309}
.sc-f{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
.sc-enter-from,.sc-leave-to{opacity:0}.sc-enter-active,.sc-leave-active{transition:opacity .2s}
</style>
