import { chromium } from 'playwright'
import fs from 'fs'
const NARR = '/private/tmp/claude-501/-Users-rosliamir/a631b6de-d1a5-436b-bc66-d43f1560af84/scratchpad/sm/narr'
const VDIR = '/private/tmp/claude-501/-Users-rosliamir/a631b6de-d1a5-436b-bc66-d43f1560af84/scratchpad/sm/video'
const segs = JSON.parse(fs.readFileSync(`${NARR}/segs.json`))
const byId = Object.fromEntries(segs.map(s => [s.id, s]))
fs.rmSync(VDIR, { recursive: true, force: true }); fs.mkdirSync(VDIR, { recursive: true })
const B = 'http://localhost:5178'
const TAIL = 1200 // ms jeda selepas narasi

const browser = await chromium.launch()
const ctx = await browser.newContext({ viewport: { width: 1440, height: 810 }, recordVideo: { dir: VDIR, size: { width: 1440, height: 810 } } })
const page = await ctx.newPage()

async function cap(t, s='') {
  await page.evaluate(([t, s]) => {
    let el = document.getElementById('__cap')
    if (!el) { el = document.createElement('div'); el.id = '__cap'; document.body.appendChild(el)
      el.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:2147483647;padding:46px 54px 32px;text-align:center;font-family:Inter,system-ui,sans-serif;background:linear-gradient(transparent,rgba(8,15,25,.85));pointer-events:none' }
    el.innerHTML = `<div style="color:#fff;font-weight:800;font-size:30px;text-shadow:0 2px 8px rgba(0,0,0,.7)">${t}</div>` +
      (s ? `<div style="color:#dbe7f3;font-weight:500;font-size:19px;margin-top:8px;text-shadow:0 2px 6px rgba(0,0,0,.7)">${s}</div>` : '')
  }, [t, s])
}
const sleep = ms => page.waitForTimeout(ms)

let vStart
const offsets = []
function mark(id) { offsets.push({ id, start: (Date.now() - vStart) / 1000 }) }
// prep ran, screen ready & caption shown -> narration starts now, then wait its length
async function play(id) { mark(id); await sleep(byId[id].dur * 1000 + TAIL) }

try {
  await page.goto(B + '/login', { waitUntil: 'domcontentloaded' }); await sleep(2000)
  vStart = Date.now()

  // LOGIN — narration plays while typing
  await cap(byId.login.title, byId.login.sub)
  mark('login')
  const em = await page.$('input'); const pw = (await page.$$('input'))[1]
  await em.click(); await em.fill(''); await page.keyboard.type('admin@kssb.local', { delay: 80 })
  await sleep(400); await pw.click(); await pw.fill(''); await page.keyboard.type('password', { delay: 100 })
  const loginEnd = vStart + offsets[0].start * 1000 + byId.login.dur * 1000 + TAIL
  const rem = loginEnd - Date.now() - 1400
  if (rem > 0) await sleep(rem)
  await page.hover('button:has-text("Submit")'); await sleep(500)
  await page.click('button:has-text("Submit")'); await sleep(900)

  // MAP (already on /map after submit)
  await sleep(2200); await cap(byId.map_all.title, byId.map_all.sub); await play('map_all')
  await page.click('button:has-text("Northern Region")').catch(()=>{}); await sleep(1300); await cap(byId.map_north.title, byId.map_north.sub); await play('map_north')
  await page.click('button:has-text("Southern Region")').catch(()=>{}); await sleep(1300); await cap(byId.map_south.title, byId.map_south.sub); await play('map_south')
  await page.locator('.leaflet-marker-icon .site-pill').first().click({ timeout: 4000, force: true }).catch(()=>{}); await sleep(1200); await cap(byId.site_panel.title, byId.site_panel.sub); await play('site_panel')

  const nav = async (path, settle, id) => { await page.goto(B + path, { waitUntil: 'domcontentloaded' }).catch(()=>{}); await sleep(settle); await cap(byId[id].title, byId[id].sub); await play(id) }
  await nav('/dashboard', 2800, 'dashboard')
  await nav('/monitoring', 1900, 'monitoring')
  await page.goto(B + '/attendance', { waitUntil: 'domcontentloaded' }).catch(()=>{}); await sleep(1600)
  await page.evaluate(()=>{const i=document.querySelector('input[type=date]');if(i){i.value='2026-11-10';i.dispatchEvent(new Event('change'))}}); await sleep(1500)
  await cap(byId.attendance.title, byId.attendance.sub); await play('attendance')
  await nav('/site-management', 2300, 'site_mgmt')
  await nav('/user-management', 1900, 'user_mgmt')
  await nav('/account', 1600, 'account')
  await page.click('.profile-btn').catch(()=>{}); await sleep(1000); await cap(byId.logout.title, byId.logout.sub); await play('logout')
  await page.click('button:has-text("Sign out")').catch(()=>{}); await sleep(600)

  const total = (Date.now() - vStart) / 1000
  fs.writeFileSync(`${NARR}/offsets.json`, JSON.stringify({ total, offsets }, null, 1))
} catch (e) { console.log('ERR', String(e).slice(0, 200)) }

await ctx.close(); await browser.close()
console.log('video:', fs.readdirSync(VDIR).filter(f => f.endsWith('.webm')), 'offsets:', offsets.length)
