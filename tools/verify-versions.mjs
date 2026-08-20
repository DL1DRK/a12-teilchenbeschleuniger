import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const version = (await readFile(join(root, 'VERSION'), 'utf8')).trim();
if (!/^\d+\.\d+\.\d+$/.test(version)) throw new Error(`Ungültige VERSION: ${version}`);

const checks = [
  ['app/bootstrap.php', /const A12_APP_VERSION = '([^']+)'/],
  ['tools/installer.template.php', /const A12_INSTALLER_VERSION = '([^']+)'/],
  ['tools/updater.template.php', /const A12_UPDATER_VERSION = '([^']+)'/],
];

for (const [path, pattern] of checks) {
  const contents = await readFile(join(root, path), 'utf8');
  const match = contents.match(pattern);
  if (!match || match[1] !== version) throw new Error(`${path} stimmt nicht mit VERSION ${version} überein.`);
}

const changelog = await readFile(join(root, 'CHANGELOG.md'), 'utf8');
if (!changelog.includes(`## [${version}]`)) throw new Error(`CHANGELOG.md enthält Version ${version} nicht.`);

const api = await readFile(join(root, 'app/api.php'), 'utf8');
for (const marker of ['prepare-update', 'updaterDigest', 'a12ValidateUpdaterUrl', 'a12DownloadUpdater']) {
  if (!api.includes(marker)) throw new Error(`Update-Vorbereitung enthält ${marker} nicht.`);
}

console.log(`OK: Version ${version} und sichere Update-Vorbereitung verifiziert`);
