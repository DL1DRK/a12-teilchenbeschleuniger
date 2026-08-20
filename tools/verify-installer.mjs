import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { gunzipSync } from 'node:zlib';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const installer = await readFile(join(root, 'installer.php'), 'utf8');
const match = installer.match(/const A12_PAYLOAD_BASE64 = '([^']+)'/);

if (!match) {
  throw new Error('No embedded payload found in installer.php.');
}

const payload = JSON.parse(Buffer.from(match[1], 'base64').toString('utf8'));

for (const [name, asset] of Object.entries(payload)) {
  const extracted = gunzipSync(Buffer.from(asset.data, 'base64'));
  const original = await readFile(join(root, 'app', name));
  const digest = createHash('sha256').update(extracted).digest('hex');

  if (digest !== asset.sha256 || !extracted.equals(original) || extracted.length !== asset.size) {
    throw new Error(`Integrity check failed for ${name}.`);
  }
  console.log(`✓ ${name} (${asset.size.toLocaleString('de-DE')} bytes)`);
}

if (installer.includes('__A12_PAYLOAD_BASE64__')) {
  throw new Error('Unreplaced payload placeholder remains in installer.php.');
}

console.log(`✓ ${Object.keys(payload).length} files verified`);
