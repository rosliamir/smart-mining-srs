<template>
  <div>
    <div class="ac">
      <button v-for="(n, f) in data?.counts || {}" :key="f" class="acc" :class="[`f-${f}`, { on: flag === f }]" @click="flag = flag === f ? '' : f">
        <i :class="ICON[f]"></i><b>{{ n }}</b><small>{{ data.labels[f] }}</small></button>
      <label class="all"><input type="checkbox" v-model="all" /> Termasuk yang telah disemak</label>
    </div>
    <div class="grid">
      <div v-for="a in shown" :key="a.id" class="card-a lift" :class="{ done: a.reviewed_at }">
        <div class="ph">
          <img v-if="a.photo_in" :src="a.photo_in" @click="zoom = a.photo_in" />
          <div v-else class="noph"><i class="fa-solid fa-user-slash"></i><small>Tiada selfie</small></div>
        </div>
        <div class="min0 flex-grow-1">
          <div class="d-flex gap-2 align-items-start"><b class="nm">{{ a.name }}</b><span class="ms-auto t">{{ a.date.slice(5) }} · {{ a.tin || '—' }}</span></div>
          <div class="tiny muted">{{ a.code }} · {{ a.office }} · {{ a.loc?.label || 'tiada lokasi' }}{{ a.accuracy_m ? ` · ±${a.accuracy_m} m` : '' }}</div>
          <div class="fl"><span v-for="f in a.flags" :key="f" :class="`f-${f}`"><i :class="ICON[f]"></i>{{ data.labels[f] }}</span></div>
          <div class="acts">
            <a v-if="a.loc" class="btn-soft sm" :href="`https://maps.google.com/?q=${a.loc.lat},${a.loc.lng}`" target="_blank" rel="noopener"><i class="fa-solid fa-map-pin me-1"></i>Peta</a>
            <template v-if="!a.reviewed_at">
              <input v-model="notes[a.id]" class="f-in note" placeholder="Catatan semakan (pilihan)" />
              <button class="btn-pri sm" @click="review(a)"><i class="fa-solid fa-check me-1"></i>Tandakan disemak</button>
            </template>
            <span v-else class="ok-s"><i class="fa-solid fa-circle-check"></i>Disemak{{ a.review_note ? ': ' + a.review_note : '' }}</span>
          </div>
        </div>
      </div>
    </div>
    <div v-if="data && !shown.length" class="empty ess-card"><i class="fa-solid fa-shield-halved"></i>Tiada amaran {{ flag ? 'untuk kategori ini' : '' }} — semua clock-in disahkan.</div>
    <div v-if="!data" class="empty"><span class="spinner-border spinner-border-sm"></span></div>
    <div v-if="zoom" class="zoom" @click="zoom = null"><img :src="zoom" /></div>
  </div>
</template>
<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { apiGet, apiSend } from '../../services/api'
const props = defineProps({ office: String })
const ICON = { outside_fence: 'fa-solid fa-location-crosshairs', same_spot: 'fa-solid fa-user-secret', low_accuracy: 'fa-solid fa-signal', no_gps: 'fa-solid fa-location-pin-lock', no_photo: 'fa-solid fa-eye-slash' }
const data = ref(null), flag = ref(''), all = ref(false), notes = reactive({}), zoom = ref(null)
const shown = computed(() => (data.value?.alerts || []).filter(a => !flag.value || a.flags.includes(flag.value)))
async function load() { data.value = await apiGet(`hr/attendance/alerts?${new URLSearchParams({ ...(props.office ? { office: props.office } : {}), ...(all.value ? { all: 1 } : {}) })}`) }
async function review(a) { await apiSend('POST', `hr/attendance/${a.id}/review`, { note: notes[a.id] || null }); load() }
watch([() => props.office, all], load); onMounted(load)
</script>
<style scoped>
.ac{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.acc{display:flex;gap:10px;align-items:center;border:1px solid var(--line,#eef0f4);background:#fff;border-radius:14px;padding:10px 14px;transition:all .15s}
.acc b{font-size:20px}.acc small{color:#64748b;font-size:12px;max-width:120px;text-align:left;line-height:1.2}
.acc.on{box-shadow:0 0 0 3px rgba(99,102,241,.25);border-color:#a5b4fc}
.acc i{width:32px;height:32px;border-radius:10px;display:grid;place-items:center}
.f-outside_fence i,.fl .f-outside_fence{background:#ffe4e6;color:#be123c}.f-same_spot i,.fl .f-same_spot{background:#ede9fe;color:#6d28d9}
.f-low_accuracy i,.fl .f-low_accuracy{background:#fef3c7;color:#b45309}.f-no_gps i,.fl .f-no_gps{background:#f1f5f9;color:#475569}.f-no_photo i,.fl .f-no_photo{background:#e0f2fe;color:#0369a1}
.all{margin-left:auto;font-size:12.5px;color:#475569}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.card-a{display:flex;gap:14px;background:#fff;border:1px solid var(--line,#eef0f4);border-radius:18px;padding:12px}
.card-a.done{opacity:.6}
.ph img,.noph{width:96px;height:96px;border-radius:14px;object-fit:cover;cursor:zoom-in;flex-shrink:0}
.noph{background:#f1f5f9;color:#94a3b8;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;cursor:default}.noph i{font-size:24px}
.nm{font-size:14px}.t{font-weight:800;font-size:13px;white-space:nowrap}
.fl{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}.fl span{font-size:11px;font-weight:700;border-radius:7px;padding:2px 8px;display:inline-flex;gap:5px;align-items:center}
.acts{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:10px}
.sm{padding:.35rem .7rem!important;font-size:12px!important;text-decoration:none}
.note{flex:1;min-width:140px;padding:.35rem .6rem;font-size:12.5px}
.ok-s{font-size:12px;color:#15803d;font-weight:700;display:flex;gap:6px;align-items:center}
.zoom{position:fixed;inset:0;background:rgba(15,23,42,.8);z-index:3000;display:grid;place-items:center;cursor:zoom-out}.zoom img{max-width:90vw;max-height:85vh;border-radius:20px}
@media (max-width:991.98px){ .grid{grid-template-columns:1fr} .all{margin-left:0} }
</style>
