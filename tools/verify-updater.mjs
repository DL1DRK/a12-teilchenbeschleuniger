import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { gunzipSync } from 'node:zlib';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const updater = await readFile(join(root, 'updater.php'), 'utf8');
const match = updater.match(/const A12_UPDATE_PAYLOAD = '([^']+)'/);
if (!match) throw new Error('No updater payload found.');
const payload = JSON.parse(Buffer.from(match[1], 'base64').toString('utf8'));
for (const [name, asset] of Object.entries(payload)) {
  const extracted = gunzipSync(Buffer.from(asset.data, 'base64'));
  const original = await readFile(join(root, 'app', name));
  const hash = createHash('sha256').update(extracted).digest('hex');
  if (hash !== asset.sha256 || !extracted.equals(original)) throw new Error(`Updater integrity failed for ${name}.`);
  console.log(`✓ ${name}`);
}
if (updater.includes('__A12_UPDATE_PAYLOAD_BASE64__')) throw new Error('Updater placeholder remains.');
console.log(`✓ ${Object.keys(payload).length} updater files verified`);
