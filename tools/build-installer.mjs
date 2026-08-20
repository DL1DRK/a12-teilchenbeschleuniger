import { readFile, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { gzipSync } from 'node:zlib';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const files = ['index.php', 'bootstrap.php', 'api.php', 'style.css', 'theme-dark-blue.css', 'app.js', 'logo.png', 'favicon.png'];
const payload = {};

for (const name of files) {
  const contents = await readFile(join(root, 'app', name));
  payload[name] = {
    sha256: createHash('sha256').update(contents).digest('hex'),
    size: contents.length,
    data: gzipSync(contents, { level: 9 }).toString('base64'),
  };
}

const template = await readFile(join(root, 'tools', 'installer.template.php'), 'utf8');
const phpPayload = Buffer.from(JSON.stringify(payload), 'utf8').toString('base64');
const output = template.replace('__A12_PAYLOAD_BASE64__', phpPayload);

if (output === template) {
  throw new Error('Payload placeholder was not found in installer template.');
}

await writeFile(join(root, 'installer.php'), output);
console.log(`installer.php created (${Buffer.byteLength(output).toLocaleString('de-DE')} bytes)`);
