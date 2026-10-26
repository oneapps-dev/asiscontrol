import { cp, mkdir, rm } from 'node:fs/promises';
import { basename, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const currentDir = dirname(fileURLToPath(import.meta.url));
const frontendRoot = resolve(currentDir, '..');
const projectRoot = resolve(frontendRoot, '..');
const distRoot = resolve(frontendRoot, 'dist');

const copies = [
  ['backend', 'backend'],
  ['docs/phases', 'docs/phases'],
  ['docs/visitor_access_management_system_specification.md', 'docs/visitor_access_management_system_specification.md'],
  ['docs/qa-implementation-questions.md', 'docs/qa-implementation-questions.md'],
  ['02 AsisControl/Para entregar a Programación/00_README', 'docs/asiscontrol'],
  ['02 AsisControl/Para entregar a Programación/01_PRODUCT_FOUNDATION', 'docs/asiscontrol/01_PRODUCT_FOUNDATION'],
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
      if (name === '.env' || (name.startsWith('.env.') && name !== '.env.example')) return false;
      if (name === '.DS_Store') return false;
      if (name === 'storage' && from === 'backend') return false;
      return true;
    },
  });
}

console.log('Asiscontrol backend, database and phase docs copied to frontend/dist.');
