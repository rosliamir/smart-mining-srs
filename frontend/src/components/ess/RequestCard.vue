<template>
  <div class="rc lift">
    <span class="ic" :style="{ background: (r.leave_color || T.color) + '1f', color: r.leave_color || T.color }"><i :class="r.leave_icon || T.icon"></i></span>
    <div class="min0 flex-grow-1">
      <div class="d-flex align-items-start gap-2 flex-wrap">
        <b class="t">{{ r.leave_name || T.label }}<span v-if="r.details?.category" class="muted fw-normal"> · {{ cat }}</span><span v-if="r.details?.ot_type" class="muted fw-normal"> · {{ rate }}</span></b>
        <span class="st ms-auto" :class="S.cls"><i :class="S.icon"></i>{{ S.label }}</span>
      </div>
      <div class="meta"><i class="fa-regular fa-calendar"></i>{{ dateText(r) }} <span class="q">{{ qtyText(r) }}</span></div>
      <div v-if="r.reason" class="reason">“{{ r.reason }}”</div>
      <div v-if="r.details?.attachment" class="att"><i class="fa-solid fa-paperclip"></i>{{ r.details.attachment }}</div>
      <div class="tl">
        <span class="tl-step"><span class="tl-dot approved"><i class="fa-solid fa-paper-plane"></i></span>Dihantar</span>
        <template v-for="s in r.steps" :key="s.id">
          <span class="tl-line" :class="{ done: s.action === 'approved' }"></span>
          <span class="tl-step" :title="s.comment || ''">
            <span class="tl-dot" :class="[s.action, { current: r.status === 'pending' && s.step === r.current_step }]">
              <i class="fa-solid" :class="s.action === 'approved' ? 'fa-check' : s.action === 'rejected' ? 'fa-xmark' : s.action === 'skipped' ? 'fa-minus' : 'fa-user-clock'"></i></span>
            <span>{{ ROLE[s.role] }}<small v-if="s.approver" class="d-block">{{ short(s.approver) }}</small></span>
          </span>
        </template>
      </div>
      <div v-if="lastComment" class="cmt"><i class="fa-solid fa-comment-dots"></i>{{ lastComment }}</div>
    </div>
    <button v-if="cancellable" class="cx" title="Batalkan permohonan" @click="$emit('cancel', r)"><i class="fa-solid fa-ban"></i></button>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { TYPE, STATUS, ROLE, CLAIM_CATS, OT_TYPES, dateText, qtyText, today } from '../../services/ess'
const props = defineProps({ r: { type: Object, required: true } })
defineEmits(['cancel'])
const T = computed(() => TYPE[props.r.type]), S = computed(() => STATUS[props.r.status])
const cat = computed(() => CLAIM_CATS.find(c => c.k === props.r.details?.category)?.l)
const rate = computed(() => { const o = OT_TYPES.find(o => o.k === props.r.details?.ot_type); return o ? `${o.l} ${o.rate}` : '' })
const lastComment = computed(() => [...(props.r.steps || [])].reverse().find(s => s.comment)?.comment)
const cancellable = computed(() => props.r.status === 'pending' || (props.r.status === 'approved' && props.r.start_date >= today()))
const short = n => (n || '').split(' ').slice(0, 2).join(' ')
</script>

<style scoped>
.rc{display:flex;gap:12px;align-items:flex-start;background:#fff;border:1px solid var(--line);border-radius:18px;padding:14px 16px}
.t{font-size:14.5px}
.meta{font-size:12.5px;color:var(--muted);margin-top:3px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.q{background:#f1f5f9;color:#334155;border-radius:7px;padding:1px 8px;font-weight:700}
.reason{font-size:13px;color:#475569;margin-top:6px;font-style:italic}
.att{font-size:12px;color:#4338ca;margin-top:4px;display:flex;gap:6px;align-items:center}
.tl{flex-wrap:wrap;row-gap:6px}
.tl-step small{font-size:10.5px;color:#94a3b8}
.cmt{margin-top:8px;font-size:12.5px;background:#f8fafc;border-radius:10px;padding:6px 10px;color:#334155;display:flex;gap:8px;align-items:baseline}
.cx{border:0;background:#fff1f2;color:#e11d48;width:34px;height:34px;border-radius:10px;flex-shrink:0;transition:all .15s}
.cx:hover{background:#e11d48;color:#fff}
</style>
