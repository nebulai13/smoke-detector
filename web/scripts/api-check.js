// Fails when the checked-in src/api/schema.d.ts is not what openapi-typescript generates from
// ../api/openapi.yaml right now, i.e. when the contract changed and `npm run api:types` was not run.
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));
const checkedIn = join(root, 'src/api/schema.d.ts');
const dir = mkdtempSync(join(tmpdir(), 'smoke-detector-api-check-'));
const fresh = join(dir, 'schema.d.ts');

try {
  execFileSync(
    process.execPath,
    [join(root, 'node_modules/openapi-typescript/bin/cli.js'), join(root, '../api/openapi.yaml'), '--output', fresh],
    { cwd: root, stdio: ['ignore', 'ignore', 'inherit'] },
  );
  if (readFileSync(fresh, 'utf8') !== readFileSync(checkedIn, 'utf8')) {
    console.error('src/api/schema.d.ts is out of date with ../api/openapi.yaml. Run: npm run api:types');
    process.exitCode = 1;
  } else {
    console.log('src/api/schema.d.ts matches ../api/openapi.yaml');
  }
} finally {
  rmSync(dir, { recursive: true, force: true });
}
