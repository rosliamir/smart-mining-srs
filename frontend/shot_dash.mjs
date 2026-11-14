import { chromium } from 'playwright'
const OUT = process.argv[2]
const b = await chromium.launch(); const errs = []
async function run(name, vp, mobile) {
  const ctx = await b.newContext({ viewport: vp, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: mobile ? 2 : 1 })
  const p = await ctx.newPage()
  p.on('pageerror', e => errs.push(`${name}: ${e.message}`))
  p.on('console', m => { if (m.type() === 'error') errs.push(`${name} console: ${m.text()}`) })
  await p.goto('http://localhost:5178/login', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(2500)
  await p.click('button:has-text("Submit")'); await p.waitForURL('**/map', { timeout: 45000 })
  await p.goto('http://localhost:5178/dashboard', { waitUntil: 'domcontentloaded' })
  await p.waitForSelector('.brief', { timeout: 30000 }); await p.waitForTimeout(1500)
  await p.screenshot({ path: `${OUT}/${name}-1-top.png`, fullPage: false })
  await p.screenshot({ path: `${OUT}/${name}-2-full.png`, fullPage: true })
  if (!mobile) {
    await p.hover('.kpi >> nth=2'); await p.waitForTimeout(300)
    await p.screenshot({ path: `${OUT}/${name}-3-kpihover.png` })
  }
  await p.click('.health tbody tr >> nth=0'); await p.waitForTimeout(2500)
  await p.screenshot({ path: `${OUT}/${name}-4-drawer.png` })
  await ctx.close()
}
await run('desk', { width: 1440, height: 900 }, false)
await run('mob', { width: 390, height: 844 }, true)
console.log(errs.length ? errs.join('\n') : 'no errors'); await b.close()
