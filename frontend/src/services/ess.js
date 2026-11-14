// Employee Self-Service helpers: API wrappers, labels, icons and formatting shared by the ESS pages.
import { reactive } from 'vue'
import { apiGet, apiSend } from './api'

export const ess = {
  home: () => apiGet('ess/home'),
  clock: (dir, body) => apiSend('POST', `ess/clock/${dir}`, body),
  attendance: month => apiGet(`ess/attendance?month=${month}`),
  balances: year => apiGet(`ess/balances?year=${year}`),
  requests: type => apiGet(`ess/requests${type ? `?type=${type}` : ''}`),
  submit: (type, body) => apiSend('POST', `ess/requests/${type}`, body),
  cancel: id => apiSend('POST', `ess/requests/${id}/cancel`),
  approvals: () => apiGet('ess/approvals'),
  act: (id, action, comment) => apiSend('POST', `ess/approvals/${id}`, { action, comment }),
  notifications: () => apiGet('ess/notifications'),
  readNotifications: id => apiSend('POST', 'ess/notifications/read', id ? { id } : {}),
  memos: () => apiGet('ess/memos'),
  memo: id => apiGet(`ess/memos/${id}`),
  ackMemo: id => apiSend('POST', `ess/memos/${id}/ack`),
  postMemo: body => apiSend('POST', 'ess/memos', body),
  holidays: year => apiGet(`ess/holidays?year=${year}`),
  team: (from, to) => apiGet(`ess/team?from=${from}&to=${to}`),
  report: year => apiGet(`ess/report?year=${year}`),
  password: body => apiSend('POST', 'ess/password', body),
}

// shared live state for badges in the shell
export const essState = reactive({ home: null, unread: 0, approvals: 0, memos: 0, formOpen: false, formType: 'leave', formPreset: null })
export async function refreshHome() {
  const h = await ess.home()
  if (h) { essState.home = h; essState.unread = h.unread; essState.approvals = h.approvals_pending; essState.memos = h.memos_unread }
  return h
}
export function openForm(type = 'leave', preset = null) { essState.formType = type; essState.formPreset = preset; essState.formOpen = true }

export const TYPE = {
  leave: { label: 'Cuti', en: 'Leave', icon: 'fa-solid fa-umbrella-beach', color: '#2563eb', bg: '#dbeafe' },
  overtime: { label: 'Kerja Lebih Masa', en: 'Overtime', icon: 'fa-solid fa-business-time', color: '#7c3aed', bg: '#ede9fe' },
  timeoff: { label: 'Time-off', en: 'Time-off', icon: 'fa-solid fa-person-walking-arrow-right', color: '#0891b2', bg: '#cffafe' },
  claim: { label: 'Tuntutan', en: 'Claims', icon: 'fa-solid fa-receipt', color: '#059669', bg: '#d1fae5' },
}
export const STATUS = {
  pending: { label: 'Dalam proses', icon: 'fa-solid fa-hourglass-half', cls: 'st-pending' },
  approved: { label: 'Lulus', icon: 'fa-solid fa-circle-check', cls: 'st-approved' },
  rejected: { label: 'Ditolak', icon: 'fa-solid fa-circle-xmark', cls: 'st-rejected' },
  cancelled: { label: 'Dibatalkan', icon: 'fa-solid fa-ban', cls: 'st-cancelled' },
}
export const ROLE = { supervisor: 'Penyelia', hr: 'HR / Pengurus' }
export const CLAIM_CATS = [
  { k: 'mileage', l: 'Perbatuan', icon: 'fa-solid fa-car' }, { k: 'travel', l: 'Perjalanan & penginapan', icon: 'fa-solid fa-hotel' },
  { k: 'meal', l: 'Makan (outstation)', icon: 'fa-solid fa-utensils' }, { k: 'medical', l: 'Perubatan (pesakit luar)', icon: 'fa-solid fa-kit-medical' },
  { k: 'phone', l: 'Telefon', icon: 'fa-solid fa-mobile-screen' }, { k: 'other', l: 'Lain-lain', icon: 'fa-solid fa-ellipsis' },
]
export const OT_TYPES = [
  { k: 'normal', l: 'Hari bekerja', rate: '1.5x' }, { k: 'rest', l: 'Hari rehat', rate: '2.0x' }, { k: 'holiday', l: 'Cuti umum', rate: '3.0x' },
]

const MS = ['Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ogo', 'Sep', 'Okt', 'Nov', 'Dis']
const DAYS = ['Ahd', 'Isn', 'Sel', 'Rab', 'Kha', 'Jum', 'Sab']
export const d2 = s => { if (!s) return '—'; const d = new Date(String(s).slice(0, 10) + 'T00:00:00'); return `${d.getDate()} ${MS[d.getMonth()]}` }
export const d3 = s => { if (!s) return '—'; const d = new Date(String(s).slice(0, 10) + 'T00:00:00'); return `${DAYS[d.getDay()]}, ${d.getDate()} ${MS[d.getMonth()]} ${d.getFullYear()}` }
export const hm = t => t ? String(t).slice(0, 5) : '—'
export const monthName = m => MS[m - 1]
export const today = () => new Date().toLocaleDateString('en-CA')   // YYYY-MM-DD in local time
export function ago(ts) {
  if (!ts) return ''; const s = (Date.now() - new Date(String(ts).replace(' ', 'T'))) / 1000
  if (s < 60) return 'baru sahaja'; if (s < 3600) return `${Math.round(s / 60)} min lalu`; if (s < 86400) return `${Math.round(s / 3600)} jam lalu`
  return `${Math.round(s / 86400)} hari lalu`
}
export function qtyText(r) {
  if (r.unit === 'days') return `${+r.quantity} hari${r.half_day ? ` (${r.half_day.toUpperCase()})` : ''}`
  if (r.unit === 'hours') return `${+r.quantity} jam`
  return `RM ${(+r.quantity).toLocaleString('en-MY', { minimumFractionDigits: 2 })}`
}
export function dateText(r) {
  const t = r.start_time ? ` · ${hm(r.start_time)}–${hm(r.end_time)}` : ''
  return (r.end_date && r.end_date !== r.start_date ? `${d2(r.start_date)} – ${d2(r.end_date)}` : d3(r.start_date)) + t
}
export const initials = n => (n || '').split(/\s+/).filter(w => !/^(BIN|BINTI|A\/L|A\/P)$/i.test(w)).slice(0, 2).map(w => w[0]).join('').toUpperCase()

/** Browser GPS → {lat,lng} or {} if unavailable/denied (check-in still works, location just isn't stored). */
export function locate() {
  return new Promise(res => {
    if (!navigator.geolocation) return res({})
    navigator.geolocation.getCurrentPosition(p => res({ lat: +p.coords.latitude.toFixed(6), lng: +p.coords.longitude.toFixed(6) }), () => res({}), { timeout: 6000, maximumAge: 60000 })
  })
}
