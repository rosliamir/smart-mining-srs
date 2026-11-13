import { chromium } from 'playwright'
const OUT='/private/tmp/claude-501/-Users-rosliamir/a631b6de-d1a5-436b-bc66-d43f1560af84/scratchpad/sm/capture'
const B='http://127.0.0.1:8008'
const b=await chromium.launch();const p=await b.newPage({viewport:{width:1440,height:900}})
await p.goto(`${B}/admin/login`,{waitUntil:'networkidle',timeout:30000})
await p.fill('input[type=email]','admin@selgem.local')
await p.fill('input[type=password]','password')
await p.click('button[type=submit]')
await p.waitForTimeout(3500)
console.log('after login:',p.url())
await p.goto(`${B}/admin/sites`,{waitUntil:'networkidle',timeout:30000}).catch(()=>{})
await p.waitForTimeout(2500)
await p.screenshot({path:`${OUT}/admin-sites.png`})
const err=await p.evaluate(()=>document.body.innerText.includes('Server Error')||document.body.innerText.includes('Exception'))
console.log('has error:',err)
await b.close()
