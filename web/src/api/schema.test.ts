import { describe, expectTypeOf, it } from 'vitest';
import type { components, operations, paths } from '@/api/schema';

// These checks run in the type checker (npm run typecheck), not at runtime: they fail the build
// when a contract change in ../api/openapi.yaml removes or renames something the web app relies on.
describe('generated API types (api/openapi.yaml)', () => {
  it('has the log sync endpoint as a POST operation', () => {
    expectTypeOf<paths['/logs/sync']['post']>().toEqualTypeOf<operations['syncLogs']>();
    expectTypeOf<paths['/logs/sync']['get']>().toEqualTypeOf<undefined>();
  });

  it('sends and answers log sync as JSON', () => {
    expectTypeOf<
      operations['syncLogs']['requestBody']['content']['application/json']
    >().toEqualTypeOf<components['schemas']['LogSyncRequest']>();
    expectTypeOf<
      operations['syncLogs']['responses'][200]['content']['application/json']
    >().toEqualTypeOf<components['schemas']['LogSyncResult']>();
  });

  it('has the RFC 9457 problem schema with a type slug', () => {
    expectTypeOf<components['schemas']['Problem']>().toHaveProperty('type');
  });
});
