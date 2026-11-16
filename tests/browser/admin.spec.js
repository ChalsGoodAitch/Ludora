const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fixture=(...args)=>execFileSync('docker',['compose','exec','--user','www-data','-T','wordpress','php','ludora-tests/browser/manual-fixture.php',...args],{encoding:'utf8'}).trim();
test('administrator creates, confirms, reschedules and cancels actual reservation',async({page})=>{
 test.skip(!process.env.LUDORA_ADMIN_PASSWORD,'Pass the local admin password through environment.');test.setTimeout(90000);
 const room=fixture('create');const errors=[];page.on('pageerror',e=>errors.push(e.message));
 try{
  await page.goto('/wp-login.php');await page.locator('#user_login').fill('ludora-admin');await page.locator('#user_pass').fill(process.env.LUDORA_ADMIN_PASSWORD);await page.locator('#wp-submit').click();await expect(page).toHaveURL(/wp-admin/);
  await page.goto('/wp-admin/admin.php?page=ludora');await expect(page.locator('.ludora-admin-calendar')).toBeVisible();
  const form=page.locator('form').filter({has:page.locator('input[name=operation][value=create]')});
  await form.locator('[name=name]').fill('Admin Browser Test');await form.locator('[name=email]').fill('admin-browser@example.test');await form.locator('[name=room_id]').selectOption(room);
  const date=new Date();date.setDate(date.getDate()+60);const day=date.toISOString().slice(0,10);await form.locator('[name=date]').fill(day);await form.getByRole('button').click();await expect(page).toHaveURL(/booking=/);
  let transition=page.locator('form').filter({has:page.locator('input[name=operation][value=transition]')});await transition.locator('[name=state]').selectOption('confirmed');await transition.locator('[name=note]').fill('Verified offline venue acceptance');await transition.getByRole('button').click();
  await expect(page.locator('.wrap')).toContainText('Confirmed');
  const move=page.locator('form').filter({has:page.locator('input[name=operation][value=reschedule]')});date.setDate(date.getDate()+1);const moved=date.toISOString().slice(0,10);await move.locator('[name=date]').fill(moved);await move.getByRole('button').click();await expect(page.locator('form [name=date]')).toHaveValue(moved);
  transition=page.locator('form').filter({has:page.locator('input[name=operation][value=transition]')});await transition.locator('[name=state]').selectOption('cancelled');await transition.locator('[name=note]').fill('Local test cancellation without refund');await transition.getByRole('button').click();await expect(page.locator('.wrap')).toContainText('Cancelled');
  await page.screenshot({path:'tests/artifacts/admin-reservation.png',fullPage:true});
  await page.goto('/wp-admin/post.php?post='+room+'&action=edit');await page.waitForFunction(()=>window.wp?.data?.select('core/editor')?.getCurrentPostId());const welcome=page.getByRole('dialog',{name:'Welcome to the editor'});try{await welcome.getByRole('button',{name:'Close',exact:true}).click({timeout:3000});}catch{}if(!await page.locator('.ludora-configuration').isVisible())await page.getByRole('button',{name:'Meta Boxes',exact:true}).press('Enter');await expect(page.locator('.ludora-configuration')).toBeVisible();await expect(page.locator('[name="ludora_fields[increment]"]')).toHaveValue('30');await page.locator('[name="ludora_fields[increment]"]').fill('15');await page.keyboard.press('Tab');await page.getByRole('button',{name:'Save',exact:true}).click();await expect.poll(()=>execFileSync('docker',['compose','exec','--user','www-data','-T','wordpress','php','-r','require "wp-load.php"; echo get_post_meta('+room+',"_ludora_config",true)["increment"];'],{encoding:'utf8'}).trim()).toBe('15');expect(errors).toEqual([]);
 }finally{fixture('delete',room);}
});
