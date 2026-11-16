const { test, expect } = require('@playwright/test');

test('native editor accepts all imported blocks and Site Editor opens', async ({page})=>{
  test.skip(!process.env.LUDORA_ADMIN_PASSWORD, 'Local admin password must be supplied through environment.');
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/wp-login.php');await page.locator('#user_login').fill(process.env.LUDORA_ADMIN_USER || 'ludora-admin');await page.locator('#user_pass').fill(process.env.LUDORA_ADMIN_PASSWORD);await page.locator('#wp-submit').click();
  await expect(page).toHaveURL(/wp-admin/);
  const home=await page.request.get('/wp-json/wp/v2/pages?slug=play-park');const pages=await home.json();expect(pages.length).toBe(1);
  await page.goto('/wp-admin/post.php?post='+pages[0].id+'&action=edit');
  await page.waitForFunction(()=>window.wp?.data?.select('core/block-editor')?.getBlocks()?.length>0);
  const invalid=await page.evaluate(()=>{const results=[];function inspect(blocks){blocks.forEach(b=>{if(b.isValid===false)results.push({name:b.name,original:b.originalContent});inspect(b.innerBlocks||[]);});}inspect(wp.data.select('core/block-editor').getBlocks());return results;});
  expect(invalid).toEqual([]);
  await page.screenshot({path:'tests/artifacts/editor-home.png',fullPage:true});
  await page.goto('/wp-admin/site-editor.php');await expect(page.locator('body')).not.toContainText('There has been a critical error');
  await page.screenshot({path:'tests/artifacts/site-editor.png',fullPage:true});expect(errors).toEqual([]);
});
