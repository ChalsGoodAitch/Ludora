import {marked} from 'marked';
import {readFile,writeFile,mkdir} from 'node:fs/promises';
const names=['versions','test-report','requirements-matrix','marketplace-requirements','assets-and-licenses','decisions','external-actions','booking-model'];
await mkdir('docs/guides',{recursive:true});
for(const name of names){
  const markdown=await readFile('docs/'+name+'.md','utf8');
  // Only repository-owned documentation is compiled; this is not an untrusted public Markdown renderer.
  const body=marked.parse(markdown).replace(/href="([^"/:#]+)\.md(#[^"]*)?"/g,'href="$1.html$2"');
  await writeFile('docs/guides/'+name+'.html','<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ludora · '+name+'</title><link rel="stylesheet" href="style.css"></head><body><header><a href="index.html">Ludora documentation</a> · <a href="es.html">Español</a></header><main>'+body+'</main></body></html>');
}
for(const name of ['en','es']){
 let html=await readFile('docs/guides/'+name+'.html','utf8');
 html=html.replace(/href="\.\.\/([^"/]+)\.md"/g,'href="$1.html"');
 await writeFile('docs/guides/'+name+'.html',html);
}
console.log('Bilingual standalone HTML documentation and local technical appendices generated.');
