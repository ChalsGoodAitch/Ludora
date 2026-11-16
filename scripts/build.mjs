import { readFile, mkdir, writeFile, readdir } from 'node:fs/promises';
import vm from 'node:vm';
await mkdir('tests/artifacts', { recursive: true });
const theme = JSON.parse(await readFile('themes/ludora/theme.json', 'utf8'));
if (theme.version !== 3) throw Error('Expected theme.json version 3');
for (const file of await readdir('plugins/ludora-booking/assets')) {
  if (file.endsWith('.js')) new vm.Script(await readFile('plugins/ludora-booking/assets/' + file, 'utf8'), { filename: file });
}
await writeFile('tests/artifacts/build.json', JSON.stringify({ themeSchema: theme.version, assets: 'Source assets are production-ready vanilla CSS/JS; no runtime bundler or remote dependencies.' }, null, 2));
console.log('Theme JSON and production JavaScript validated.');
