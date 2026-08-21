import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const [bootstrap, api, app, index] = await Promise.all([
  readFile(join(root, 'app', 'bootstrap.php'), 'utf8'),
  readFile(join(root, 'app', 'api.php'), 'utf8'),
  readFile(join(root, 'app', 'app.js'), 'utf8'),
  readFile(join(root, 'app', 'index.php'), 'utf8'),
]);

for (const marker of ["$_POST['_csrf']", 'function a12ReadInput()', "unset($input['_csrf'])"]) {
  if (!bootstrap.includes(marker)) throw new Error(`Formular-Transport enthält ${marker} nicht.`);
}
if (api.includes('a12ReadJson()') || !api.includes('a12ReadInput()')) {
  throw new Error('Die API verwendet nicht durchgehend den hosting-kompatiblen Eingabeleser.');
}
for (const marker of ['new URLSearchParams({_csrf:csrf})', "formData.set('_csrf',csrf)"]) {
  if (!app.includes(marker)) throw new Error(`Client-Transport enthält ${marker} nicht.`);
}
if (app.includes("options.headers['X-CSRF-Token']") || app.includes("options.headers['Content-Type']='application/json'")) {
  throw new Error('Der Client verwendet weiterhin blockierbare JSON-Sonder-Header.');
}
if (!index.includes('app.js?v=<?= rawurlencode(A12_APP_VERSION) ?>')) {
  throw new Error('Das aktualisierte Anwendungsskript wird nicht versionsabhängig geladen.');
}
if ((api.match(/exec\('BEGIN IMMEDIATE'\)/g) ?? []).length !== (api.match(/exec\('COMMIT'\)/g) ?? []).length) {
  throw new Error('Nicht jede manuell gestartete SQLite-Transaktion wird treiberneutral abgeschlossen.');
}

console.log('OK: Formulardaten, CSRF-Schutz, Cache-Busting und SQLite-Abschluss verifiziert');
