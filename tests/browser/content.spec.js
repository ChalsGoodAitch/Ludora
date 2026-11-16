const {test,expect}=require('@playwright/test');
test.describe('official Theme Test Data in the separate ZIP installation',()=>{
 test.skip(process.env.LUDORA_CONTENT_TEST!=='1','Use the clean site with official imported Theme Test Data.');
 for(const width of [360,768,1440])test('long content, archives, comments and pagination at '+width,async({page})=>{
  test.setTimeout(120000);await page.setViewportSize({width,height:1000});
  for(const slug of ['markup-html-tags-and-formatting','markup-image-alignment','title-should-not-overflow-the-content-area','edge-case-no-title','edge-case-no-content','edge-case-many-tags','edge-case-nested-and-mixed-lists','template-comments','template-paginated','block-gallery','column-blocks']){
   const r=await page.goto('/'+slug+'/',{waitUntil:'domcontentloaded'});expect(r.status(),slug).toBe(200);await expect(page.locator('main')).toBeVisible();
   expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),slug).toBeTruthy();
  }
  await page.goto('/template-comments/',{waitUntil:'domcontentloaded'});await expect(page.locator('.wp-block-comment-template li').first()).toBeVisible();await expect(page.locator('#commentform')).toBeVisible();
  await page.screenshot({path:'tests/artifacts/unit-comments-'+width+'.png'});
  await page.goto('/journal/',{waitUntil:'domcontentloaded'});const next=page.locator('.wp-block-query-pagination-next');await expect(next).toBeVisible();await next.click();await expect(page).toHaveURL(/page\/2/);
  for(const type of ['categories','tags']){const r=await page.request.get('/wp-json/wp/v2/'+type+'?per_page=1&hide_empty=true');const [term]=await r.json();const response=await page.goto(term.link,{waitUntil:'domcontentloaded'});expect(response.status()).toBe(200);await expect(page.locator('main')).toBeVisible();}
 });
});
