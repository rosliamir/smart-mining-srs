import { chromium } from 'playwright'
const OUT = process.argv[2]; const b = await chromium.launch(); const errs = []
async function login(ctx) {
  const p = await ctx.newPage()
  p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (m.type() === 'error') errs.push(m.text()) })
  await p.goto('http://localhost:5178/login', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(2500)
  await p.click('button:has-text("Submit")'); await p.waitForURL('**/map', { timeout: 45000 }); return p
}
// desktop
let ctx = await b.newContext({ viewport: { width: 1440, height: 900 } }); let p = await login(ctx)
await p.goto('http://localhost:5178/attendance', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(2500)
await p.click('.kb-btn'); await p.waitForSelector('.kb-item'); await p.waitForTimeout(600)
await p.screenshot({ path: `${OUT}/kb-1-list.png` })
await p.click('.kb-item >> nth=2'); await p.waitForTimeout(800); await p.screenshot({ path: `${OUT}/kb-2-entry.png` })
await p.click('button:has-text("Add note")'); await p.fill('.kb-detail input', 'Waktu masuk rasmi pejabat');
await p.fill('.kb-detail textarea', 'Waktu masuk rasmi semua pejabat (HQ, PSU, PSS) ialah 08:00 pagi. Staf dianggap lewat jika clock-in selepas 08:30 (tempoh tenggang 30 minit). Waktu balik 17:00.')
await p.selectOption('.kb-detail select', 'attendance'); await p.click('.kb-detail button:has-text("Save")'); await p.waitForTimeout(1200)
await p.screenshot({ path: `${OUT}/kb-3-note.png` })
await p.click('.x-btn >> nth=0'); await p.waitForTimeout(400)
await p.click('.ask-fab'); await p.waitForTimeout(500); await p.screenshot({ path: `${OUT}/ask-1-open.png` })
await p.click('.sug >> nth=0'); await p.waitForSelector('.bubble.bot', { timeout: 60000 }); await p.waitForTimeout(800)
await p.fill('.ask-input textarea', 'pukul berapa waktu masuk rasmi pejabat?'); await p.keyboard.press('Enter')
await p.waitForFunction(() => document.querySelectorAll('.bubble.bot:not(.typing)').length >= 2, null, { timeout: 60000 }); await p.waitForTimeout(800)
await p.screenshot({ path: `${OUT}/ask-2-answer.png` })
console.log('answer2:', (await p.$$eval('.bubble.bot .mdx', e => e.map(x => x.innerText.slice(0, 300))))[1])
await ctx.close()
// mobile
ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }); p = await login(ctx)
await p.goto('http://localhost:5178/dashboard', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(3000)
await p.screenshot({ path: `${OUT}/mob-fab.png` })
await p.click('.ask-fab'); await p.waitForTimeout(600); await p.screenshot({ path: `${OUT}/mob-ask.png` })
await p.click('.hbtn >> nth=1'); await p.click('.kb-btn'); await p.waitForSelector('.kb-item'); await p.waitForTimeout(600); await p.screenshot({ path: `${OUT}/mob-kb.png` })
console.log(await p.evaluate(() => [document.documentElement.scrollWidth, innerWidth]))
await ctx.close()
console.log(errs.length ? errs.join('\n') : 'no errors'); await b.close()
