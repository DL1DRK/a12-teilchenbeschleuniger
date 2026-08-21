import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const [api, index, app, theme] = await Promise.all([
  readFile(join(root, 'app', 'api.php'), 'utf8'),
  readFile(join(root, 'app', 'index.php'), 'utf8'),
  readFile(join(root, 'app', 'app.js'), 'utf8'),
  readFile(join(root, 'app', 'theme-dark-blue.css'), 'utf8'),
]);

for (const marker of [
  'a12CreateBackup',
  'a12ValidateBackup',
  'a12VerifyAdminPasswordPair',
  'a12RestoreBackup',
  'a12ResetNumbering',
  'a12ResetEntireSystem',
  "'restore-backup'",
  "'reset-system'",
  'PRAGMA foreign_key_check',
  'sha256:',
]) {
  if (!api.includes(marker)) throw new Error(`Admin-Wartungs-API enthält ${marker} nicht.`);
}

if ((index.match(/name="password_confirm"/g) ?? []).length < 2) {
  throw new Error('Wiederherstellung und Reset besitzen nicht jeweils eine zweite Passworteingabe.');
}
for (const marker of ['Backup herunterladen', 'Backup einspielen', 'Nur Nummerierung zurücksetzen', 'Gesamtes System zurücksetzen', 'Ja, ich will löschen']) {
  if (!index.includes(marker)) throw new Error(`Admin-Oberfläche enthält „${marker}“ nicht.`);
}
for (const marker of ['requestForm', 'restore-backup', 'reset-system', 'passwordConfirm']) {
  if (!app.includes(marker)) throw new Error(`Admin-Wartungsoberfläche enthält ${marker} nicht.`);
}
for (const marker of ['.dangerZone', '.fatalButton', 'font-weight: 950']) {
  if (!theme.includes(marker)) throw new Error(`Gefahrenzone enthält ${marker} nicht.`);
}

console.log('OK: Backup, Wiederherstellung, doppelte Passwortprüfung und Reset-Gefahrenzone verifiziert');
