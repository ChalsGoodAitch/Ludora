(function () {
  'use strict';
  if (!window.ludoraBooking) return;
  const cfg = window.ludoraBooking, t = cfg.text;
  let csrf;
  async function call(action, data) {
    if (!csrf) { const s = await fetch(cfg.api + 'session', {method: 'POST', credentials: 'same-origin'}); const v = await s.json(); if (!s.ok) throw Error(v.message); csrf = v.csrf; }
    const r = await fetch(cfg.api + action, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-Ludora-CSRF': csrf}, body: JSON.stringify(data)});
    const v = await r.json(); if (!r.ok) { if (r.status === 403) csrf = null; throw Error(v.message || t.error); } return v;
  }
  function node(tag, text) {const e = document.createElement(tag); if (text !== undefined) e.textContent = text; return e;}
  document.querySelectorAll('.ludora-app').forEach(app => {
    const form = app.querySelector('form'), feedback = app.querySelector('.ludora-feedback'), summary = app.querySelector('.ludora-summary'), result = app.querySelector('.ludora-result'), submit = form.querySelector('[type=submit]'), quoteButton = form.querySelector('[data-quote]');
    let quote, busy = false, key = crypto.randomUUID();
    const field = n => form.elements.namedItem(n);
    const money = (amount, q) => new Intl.NumberFormat(document.documentElement.lang || 'en', {style:'currency', currency:q.currency, minimumFractionDigits:q.precision, maximumFractionDigits:q.precision}).format(amount / Math.pow(10,q.precision));
    function message(text, error) { feedback.textContent = text; feedback.dataset.error = String(!!error); }
    function request() { const extras = {}; form.querySelectorAll('[data-extra]').forEach(e => {if (+e.value > 0) extras[e.dataset.extra] = +e.value;}); return {package_id:+field('package_id').value,room_id:+field('room_id').value,date:field('date').value,mode:field('mode').value,start:field('start').value,duration:+field('duration').value,shift:field('shift').value,children:+field('children').value,adults:+field('adults').value,extras}; }
    function controls() {
      const c = JSON.parse(field('room_id').selectedOptions[0].dataset.config || '{}');
      const step=+c.increment||30;field('start').step=step*60;field('duration').step=step;field('duration').min=+c.min_duration||60;field('duration').max=+c.max_duration||480;if(+field('duration').value<+field('duration').min||+field('duration').value>+field('duration').max||+field('duration').value%step)field('duration').value=Math.ceil((+field('duration').min)/step)*step;
      if(field('date').min){const maxDate=new Date(field('date').min+'T00:00:00Z');maxDate.setUTCDate(maxDate.getUTCDate()+Number(c.horizon_days??365));field('date').max=maxDate.toISOString().slice(0,10);}
      const select = field('shift'); const prior = select.value; select.replaceChildren();
      Object.entries(c.shifts || {}).forEach(([id,s]) => {const o = node('option',s.name || id);o.value=id;select.append(o);}); if ([...select.options].some(o=>o.value===prior)) select.value=prior;
      ['start','duration'].forEach(n=>form.querySelector('[data-field='+n+']').hidden=field('mode').value!=='hourly');
      form.querySelector('[data-field=shift]').hidden=field('mode').value!=='shift';
    }
    function invalidate(){quote=null;submit.disabled=true;summary.replaceChildren();message(t.changed);}
    form.addEventListener('input', e => { if (!['name','email','phone','terms','website'].includes(e.target.name)) invalidate(); });
    function usePackage() { const option=field('package_id').selectedOptions[0]; if(!+option.value)return;const p=JSON.parse(option.dataset.config);field('room_id').value=String(p.room_id);field('mode').value=p.mode;controls();if(p.shift)field('shift').value=p.shift;if(p.duration)field('duration').value=p.duration;Object.entries(p.extras||{}).forEach(([id,qty])=>{const e=form.querySelector('[data-extra="'+id+'"]');if(e)e.value=qty;}); }
    form.addEventListener('change', e => {if (e.target.name==='package_id') usePackage(); if (['room_id','mode'].includes(e.target.name)) {field('package_id').value='0';controls();}});
    async function run(task) { if (busy) return; busy = true; quoteButton.disabled = true;submit.disabled = true;app.setAttribute('aria-busy','true');message(t.loading); try {await task();} catch(e) {message(e.message || t.error,true);feedback.focus();} finally {busy=false;quoteButton.disabled=false;submit.disabled=!quote;app.removeAttribute('aria-busy');} }
    quoteButton.addEventListener('click',()=>run(async()=>{
      if (!field('date').reportValidity()) return;
      quote=await call('quote',{request:request()});summary.replaceChildren();const table=node('table'),caption=node('caption',t.total);table.append(caption);
      (quote.lines || []).forEach(line=>{const tr=node('tr');tr.append(node('th',line.name || line.label || line.type),node('td',String(line.quantity)+(line.basis==='hour'||line.basis==='hourly'?' min':'')+' · '+money(line.rate,quote)+(line.basis==='hour'||line.basis==='hourly'?' / h':'')),node('td',money(line.net ?? line.total ?? line.amount,quote)));table.append(tr);});
      const tax=node('tr');tax.append(node('th',t.tax),node('td',money(quote.tax,quote)));tax.firstChild.colSpan=2;table.append(tax);
      const total=node('tr');total.append(node('th',t.total),node('td',money(quote.total,quote)));total.firstChild.colSpan=2;table.append(total);summary.append(node('p',t.net),table);message('✓ '+t.total+': '+money(quote.total,quote));
    }));
    form.addEventListener('submit',e=>{e.preventDefault();if(!quote||!form.reportValidity())return;run(async()=>{
      const v=await call('reserve',{key,quote_signature:quote.signature,request:request(),customer:{name:field('name').value,email:field('email').value,phone:field('phone').value,terms:field('terms').checked,website:field('website').value}});
      if(v.checkout_url){window.location.assign(v.checkout_url);return;}show(v);form.hidden=true;quote=null;key=crypto.randomUUID();
    });});
    function show(v){result.replaceChildren(node('h2',v.reference),node('p',t[v.state] || cfg.states[v.state] || v.state),node('p',cfg.states[v.payment_state] || v.payment_state));const q=v.snapshot;const format=new Intl.DateTimeFormat(document.documentElement.lang||'en',{timeZone:q.timezone||cfg.timezone,dateStyle:'medium',timeStyle:'short'});result.append(node('p',q.lines[0].name),node('p',format.format(new Date(v.start*1000))+' — '+format.format(new Date(v.end*1000))),node('p',t.total+': '+money(q.total,q)));if(['pending','hold'].includes(v.state)&&v.expires_at)result.append(node('p',t.expires+': '+format.format(new Date(v.expires_at*1000))));if(v.payment_state==='paid'&&!['confirmed','completed'].includes(v.state))result.append(node('strong',t.review)); if(v.token){const url=new URL(location.href);url.hash='booking='+v.token;const a=node('a',t.private);a.href=url.href;result.append(a);history.replaceState(null,'',url);}result.focus();message('');}
    const selected=new URL(location.href).searchParams.get('room');if(selected&&[...field('room_id').options].some(o=>o.value===selected))field('room_id').value=selected;
    const now = new Date(); const date = new Intl.DateTimeFormat('en-CA',{timeZone:cfg.timezone,year:'numeric',month:'2-digit',day:'2-digit'}).format(now);field('date').min=date;
    const selectedDate=new URL(location.href).searchParams.get('date');if(selectedDate&&/^\d{4}-\d{2}-\d{2}$/.test(selectedDate))field('date').value=selectedDate;
    controls();const packageID=new URL(location.href).searchParams.get('package')||app.dataset.package;if(packageID&&[...field('package_id').options].some(o=>o.value===packageID)){field('package_id').value=packageID;usePackage();}
    const token=new URLSearchParams(location.hash.slice(1)).get('booking');if(token){form.hidden=true;run(async()=>show(await call('status',{token})));}
  });
})();
