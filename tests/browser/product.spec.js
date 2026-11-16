const { test, expect } = require('@playwright/test');
const {execFileSync} = require('node:child_process');
const fixture = (...args) => execFileSync('docker',['compose',...(process.env.LUDORA_COMPOSE_FILE?['-f',process.env.LUDORA_COMPOSE_FILE]:[]),'exec','--user','www-data','-T','wordpress','php','ludora-tests/browser/manual-fixture.php',...args],{encoding:'utf8'}).trim();
const AxeBuilder = require('@axe-core/playwright').default;

for (const width of [360, 768, 1440]) {
  test('real homepage and booking at ' + width + 'px', async ({page}) => {
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    await page.setViewportSize({width,height:1000});
    await page.goto('/'); await expect(page.locator('main')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.screenshot({path:'tests/artifacts/home-'+width+'.png',fullPage:true});
    await page.locator('.ludora-card h3 a').first().click();await expect(page.locator('.ludora-room-details dl dt')).toHaveCount(3);await expect(page.locator('.ludora-room-details')).toContainText('45.00 EUR');expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();await page.screenshot({path:'tests/artifacts/room-'+width+'.png',fullPage:true});
    await page.goto('/booking/'); await expect(page.locator('.ludora-form')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.screenshot({path:'tests/artifacts/booking-'+width+'.png',fullPage:true});
    expect(errors).toEqual([]);
    const audit = await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
    expect(audit.violations.map(v=>({id:v.id,description:v.description,targets:v.nodes.map(n=>n.target)}))).toEqual([]);
  });
}

test('keyboard guest request, private status and forged-session rejection', async ({page, request})=>{
  const forged=await request.post('/wp-json/ludora/v1/reserve',{data:{request:{},customer:{},key:'forged-key-123456',quote_signature:'0'.repeat(64)}});expect(forged.status()).toBe(403);
  const id=fixture('create');
  try {
    const date=new Date();date.setDate(date.getDate()+47);const day=date.toISOString().slice(0,10);
    await page.goto('/booking/?room='+id+'&date='+day);
    async function tabUntil(test) {for(let i=0;i<100;i++){if(await page.evaluate(test))return;await page.keyboard.press('Tab');}throw Error('Keyboard target not reachable');}
    await tabUntil(()=>document.activeElement?.hasAttribute('data-quote'));await page.keyboard.press('Enter');
    await expect(page.locator('.ludora-summary table')).toBeVisible();
    await tabUntil(()=>document.activeElement?.name==='name');await page.keyboard.type('Keyboard Test Adult');
    await tabUntil(()=>document.activeElement?.name==='email');await page.keyboard.type('keyboard@example.test');
    await tabUntil(()=>document.activeElement?.name==='terms');await page.keyboard.press('Space');
    await tabUntil(()=>document.activeElement?.getAttribute('type')==='submit');await page.keyboard.press('Enter');
    await expect(page.locator('.ludora-result h2')).toHaveText(/LD-/);await expect(page.locator('.ludora-result')).toContainText(/awaiting|pendiente/);
    expect(page.url()).toContain('#booking=');await page.reload();await expect(page.locator('.ludora-result h2')).toHaveText(/LD-/);
    await page.screenshot({path:'tests/artifacts/booking-received.png',fullPage:true});
  } finally {fixture('delete',id);}
});

test('keyboard navigation and empty search/404',async({page})=>{
  await page.goto('/booking/');await page.keyboard.press('Tab');
  await expect(page.locator(':focus')).toBeVisible();
  await page.goto('/?s=ludora-impossible-search-729104');await expect(page.locator('main')).toBeVisible();
  await page.screenshot({path:'tests/artifacts/search-empty.png',fullPage:true});
  const response=await page.goto('/ludora-does-not-exist-729104/');expect(response.status()).toBe(404);await expect(page.locator('main')).toBeVisible();
});
