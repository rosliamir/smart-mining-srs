import { chromium } from 'playwright'
const OUT = process.argv[2]; const b = await chromium.launch(); const errs = []
async function login(ctx, id) {
  const p = await ctx.newPage()
  p.on('pageerror', e => errs.push(`${id}: ${e.message}`)); p.on('console', m => { if (m.type() === 'error' && !/geolocation|favicon/i.test(m.text())) errs.push(`${id} console: ${m.text()}`) })
  await p.goto('http://localhost:5178/login', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(2000)
  await p.fill('.glass input >> nth=0', id); await p.fill('.glass input[type=password]', 'password')
  await p.click('button:has-text("Submit")'); await p.waitForURL('**/ess', { timeout: 45000 }); await p.waitForSelector('.hero'); await p.waitForTimeout(1200)
  return p
}
const D = { viewport: { width: 1440, height: 900 }, permissions: ['geolocation'], geolocation: { latitude: 3.0738, longitude: 101.5183 } }
// staff desktop
let ctx = await b.newContext(D); let p = await login(ctx, 'ks0294')
await p.screenshot({ path: `${OUT}/s1-home.png` }); await p.screenshot({ path: `${OUT}/s1-home-full.png`, fullPage: true })
await p.click('.punch-btn'); await p.waitForTimeout(1500); await p.screenshot({ path: `${OUT}/s2-clockin.png` })
await p.click('.qa-i >> nth=0'); await p.waitForSelector('.rf'); await p.waitForTimeout(300)
await p.fill('.rf input[type=date] >> nth=0', '2026-11-26'); await p.fill('.rf input[type=date] >> nth=1', '2026-11-27')
await p.fill('.rf textarea', 'Urusan keluarga di kampung'); await p.screenshot({ path: `${OUT}/s3-form.png` })
await p.click('.rf .btn-pri'); await p.waitForTimeout(1500); await p.screenshot({ path: `${OUT}/s4-submitted.png` })
await p.goto('http://localhost:5178/ess/leave'); await p.waitForSelector('.bal-grid'); await p.waitForTimeout(800); await p.screenshot({ path: `${OUT}/s5-leave.png` })
await p.goto('http://localhost:5178/ess/attendance'); await p.waitForSelector('.cal .c'); await p.waitForTimeout(800); await p.screenshot({ path: `${OUT}/s6-attendance.png` })
await p.goto('http://localhost:5178/ess/policies'); await p.waitForSelector('.pc'); await p.click('.pc >> nth=0'); await p.waitForTimeout(800); await p.screenshot({ path: `${OUT}/s7-policies.png`, fullPage: true })
await p.click('.sugs button >> nth=0'); await p.waitForSelector('.bubble.bot:not(.typing)', { timeout: 60000 }); await p.waitForTimeout(800); await p.screenshot({ path: `${OUT}/s8-ask.png` })
await p.goto('http://localhost:5178/ess/reports'); await p.waitForSelector('.kp'); await p.waitForTimeout(1200); await p.screenshot({ path: `${OUT}/s9-reports.png` })
await p.goto('http://localhost:5178/dashboard'); await p.waitForTimeout(800); console.log('staff -> /dashboard redirected to', p.url())
await ctx.close()
// supervisor approves
ctx = await b.newContext(D); p = await login(ctx, 'A12930')
await p.goto('http://localhost:5178/ess/approvals'); await p.waitForSelector('.ap'); await p.waitForTimeout(600); await p.screenshot({ path: `${OUT}/a1-approvals.png` })
await p.click('.ap .btn-pri >> nth=0'); await p.waitForTimeout(1500); await ctx.close()
// HR: approve + memo
ctx = await b.newContext(D); p = await login(ctx, 'KS0197')
await p.screenshot({ path: `${OUT}/h1-home.png` })
await p.goto('http://localhost:5178/ess/approvals'); await p.waitForSelector('.ap'); await p.click('.ap .btn-pri >> nth=0'); await p.waitForTimeout(1500)
await p.goto('http://localhost:5178/ess/memos'); await p.waitForSelector('.mi'); await p.waitForTimeout(600); await p.screenshot({ path: `${OUT}/h2-memos.png` })
await ctx.close()
// staff mobile
ctx = await b.newContext({ ...D, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }); p = await login(ctx, 'ks0294')
await p.screenshot({ path: `${OUT}/m1-home.png` }); await p.screenshot({ path: `${OUT}/m1-home-full.png`, fullPage: true })
await p.click('.tab >> nth=4'); await p.waitForTimeout(500); await p.screenshot({ path: `${OUT}/m2-more.png` })
await p.click('.mg >> nth=6'); await p.waitForTimeout(400)
await p.goto('http://localhost:5178/ess/requests'); await p.waitForSelector('.list'); await p.waitForTimeout(800); await p.screenshot({ path: `${OUT}/m3-requests.png` })
await p.click('.tab.plus'); await p.waitForSelector('.rf'); await p.waitForTimeout(400); await p.screenshot({ path: `${OUT}/m4-form.png` })
console.log('mobile width', await p.evaluate(() => [document.documentElement.scrollWidth, innerWidth]))
await ctx.close()
console.log(errs.length ? errs.join('\n') : 'no errors'); await b.close()
