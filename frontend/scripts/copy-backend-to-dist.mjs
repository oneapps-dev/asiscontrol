import { cp, mkdir, rm } from 'node:fs/promises';
import { basename, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const currentDir = dirname(fileURLToPath(import.meta.url));
const frontendRoot = resolve(currentDir, '..');
const projectRoot = resolve(frontendRoot, '..');
const distRoot = resolve(frontendRoot, 'dist');

// Operational code only. Keep product and architecture docs in GitHub, not in the public web root.
const copies = [
  ['backend', 'backend'],
];

await mkdir(distRoot, { recursive: true });

for (const [from, to] of copies) {
  const source = resolve(projectRoot, from);
  const target = resolve(distRoot, to);
  await rm(target, { recursive: true, force: true });
  await mkdir(dirname(target), { recursive: true });
  await cp(source, target, {
    recursive: true,
    // Never publish runtime secrets or operational data with the frontend artifact.
    filter: (entry) => {
      const name = basename(entry);
      if (name.startsWith('.env') || name === '.DS_Store') return false;
      // Public web artifacts never include installer SQL, seeds, tests, scripts
      // or operational documentation. Keep these in private deployment storage.
      if (from === 'backend' && ['storage', 'database', 'tests', 'scripts', 'docs'].includes(name)) return false;
      if (/\.(?:sql|md|docx|pdf|xlsx?|zip|bak|log)$/i.test(name)) return false;
      return true;
    },
  });
}

console.log('AsisControl operational backend copied to frontend/dist; documentation remains in GitHub.');
