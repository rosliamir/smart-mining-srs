// Shared UI state between the knowledge-base popup and the Ask assistant
import { reactive } from 'vue'
export const kbUi = reactive({ open: false, focusId: null, askOpen: false })
export function openKb(id = null) { kbUi.focusId = id; kbUi.open = true }

// Minimal, safe Markdown → HTML for answers/KB text: escape first, then bold, bullets, [KB#id] chips
export function md(text) {
  const esc = String(text ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  const lines = esc.split('\n'); let html = '', inList = false
  for (const raw of lines) {
    const l = raw.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\[KB#(\d+)\]/g, '<a class="kb-cite" data-kb="$1">KB#$1</a>')
    const m = l.match(/^\s*[-•*]\s+(.*)$/)
    if (m) { if (!inList) { html += '<ul>'; inList = true } html += `<li>${m[1]}</li>` }
    else { if (inList) { html += '</ul>'; inList = false } html += l.trim() ? `<p>${l}</p>` : '' }
  }
  return html + (inList ? '</ul>' : '')
}
