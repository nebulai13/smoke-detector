import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';

import { createApi } from '@/core/api/api';
import { ApiProblem, NetworkError } from '@/core/api/errors';
import { createSession } from '@/core/session/session';
import { server } from '@/test/server';

// The typed access to the API (HLD §6): every call is checked against the contract by the compiler,
// answers with the data of a success, and throws for everything else.

const BASE = 'http://api.test/api/v1';
const SESSION = { accessToken: 'access-1', tokenType: 'Bearer', expiresIn: 900 };
const ME = {
  id: '00000000-0000-7000-8000-000000000001',
  email: 'anna@example.org',
  displayName: 'Anna',
  avatar: 'avatar-03',
  locale: 'de',
  timeZone: 'Europe/Berlin',
  onboardingComplete: false,
};
const problem = (status: number, type: string, extra: object = {}, headers: Record<string, string> = {}) =>
  HttpResponse.json({ type, title: type, status, ...extra }, { status, headers: { 'Content-Type': 'application/problem+json', ...headers } });

async function signedIn() {
  server.use(http.post(`${BASE}/auth/login`, () => HttpResponse.json(SESSION)));
  const session = createSession({ baseUrl: BASE });
  await session.logIn({ email: 'anna@example.org', password: 'pw' });

  return { session, api: createApi({ baseUrl: BASE, session }) };
}

describe('api', () => {
  it('sends the access token and answers with the data', async () => {
    let authorization: string | null = null;
    server.use(
      http.get(`${BASE}/me`, ({ request }) => {
        authorization = request.headers.get('Authorization');
        return HttpResponse.json(ME);
      }),
    );
    const { api } = await signedIn();

    const me = await api.GET('/me');

    expect(me.displayName).toBe('Anna');
    expect(authorization).toBe('Bearer access-1');
  });

  it('sends a body as JSON and fills path and query parameters', async () => {
    const seen: string[] = [];
    let body: unknown;
    server.use(
      http.post(`${BASE}/logs/sync`, async ({ request }) => {
        body = await request.json();
        seen.push(`${request.method} ${new URL(request.url).pathname} ${request.headers.get('Content-Type')}`);
        return HttpResponse.json({ results: [], days: [] });
      }),
      http.post(`${BASE}/days/:date/check-in`, ({ request }) => {
        seen.push(`${request.method} ${new URL(request.url).pathname}`);
        return problem(409, 'day_in_future');
      }),
      http.get(`${BASE}/days`, ({ request }) => {
        seen.push(`${request.method} ${new URL(request.url).pathname}${new URL(request.url).search}`);
        return HttpResponse.json({ days: [] });
      }),
    );
    const { api } = await signedIn();
    const items = [{ id: '00000000-0000-4000-8000-000000000001', smokedAt: '2026-11-17T08:15:00Z', productType: 'cigarette' as const, count: 1 }];

    const synced = await api.POST('/logs/sync', { body: { items } });
    const days = await api.GET('/days', { params: { query: { from: '2026-11-10', to: '2026-11-17' } } });
    const refused = await api.POST('/days/{date}/check-in', { params: { path: { date: '2026-11-18' } } }).catch((error: unknown) => error);

    expect(synced).toEqual({ results: [], days: [] });
    expect(days).toEqual({ days: [] });
    expect(body).toEqual({ items });
    expect(refused).toMatchObject({ status: 409, type: 'day_in_future' });
    expect(seen).toEqual([
      'POST /api/v1/logs/sync application/json',
      'GET /api/v1/days?from=2026-11-10&to=2026-11-17',
      'POST /api/v1/days/2026-11-18/check-in',
    ]);
  });

  it('answers with nothing for a success without a body', async () => {
    server.use(http.delete(`${BASE}/me`, () => new HttpResponse(null, { status: 204 })));
    const { api } = await signedIn();

    await expect(api.DELETE('/me', { body: { password: 'pw' } })).resolves.toBeUndefined();
  });

  it('throws the problem of the server with its field errors', async () => {
    server.use(
      http.put(`${BASE}/me/plan`, () =>
        problem(422, 'validation_failed', { errors: [{ field: 'target', code: 'must_be_below_baseline', message: 'Must be below the current baseline.' }] }),
      ),
    );
    const { api } = await signedIn();

    const failure = await api
      .PUT('/me/plan', { body: { startingBaseline: 12, goalMode: 'reduce', target: 12, taperSpeed: 'normal', defaultProduct: 'rolled' } })
      .catch((error: unknown) => error);

    expect(failure).toBeInstanceOf(ApiProblem);
    expect(failure).toMatchObject({
      status: 422,
      type: 'validation_failed',
      errors: [{ field: 'target', code: 'must_be_below_baseline', message: 'Must be below the current baseline.' }],
    });
  });

  it('tells how long to wait when the server says so', async () => {
    server.use(http.get(`${BASE}/me/export`, () => problem(429, 'rate_limited', {}, { 'Retry-After': '120' })));
    const { api } = await signedIn();

    const failure = await api.GET('/me/export').catch((error: unknown) => error);

    expect(failure).toMatchObject({ status: 429, type: 'rate_limited', retryAfterSeconds: 120 });
  });

  it('turns an answer that is no problem document into a problem with the status', async () => {
    // What a proxy or the hosting sends when the application is down.
    server.use(http.get(`${BASE}/me`, () => new HttpResponse('<html>Bad Gateway</html>', { status: 502, headers: { 'Content-Type': 'text/html' } })));
    const { api } = await signedIn();

    const failure = await api.GET('/me').catch((error: unknown) => error);

    expect(failure).toBeInstanceOf(ApiProblem);
    expect(failure).toMatchObject({ status: 502, type: 'unexpected_response' });
  });

  it('NFR-03: a request that does not reach the server is a network error, not a problem', async () => {
    server.use(http.get(`${BASE}/me`, () => HttpResponse.error()));
    const { api } = await signedIn();

    await expect(api.GET('/me')).rejects.toBeInstanceOf(NetworkError);
  });

  it('NFR-05: an expired access token is renewed once and the request is sent again', async () => {
    const tokensSeen: (string | null)[] = [];
    server.use(
      http.get(`${BASE}/me`, ({ request }) => {
        const authorization = request.headers.get('Authorization');
        tokensSeen.push(authorization);
        return authorization === 'Bearer access-2' ? HttpResponse.json(ME) : problem(401, 'unauthorized', {}, { 'WWW-Authenticate': 'Bearer' });
      }),
      http.post(`${BASE}/auth/refresh`, () => HttpResponse.json({ ...SESSION, accessToken: 'access-2' })),
    );
    const { api, session } = await signedIn();

    const me = await api.GET('/me');

    expect(me.email).toBe('anna@example.org');
    expect(tokensSeen).toEqual(['Bearer access-1', 'Bearer access-2']);
    expect(session.accessToken()).toBe('access-2');
  });

  it('NFR-05: requests that find the token expired at the same moment renew it once', async () => {
    let refreshes = 0;
    server.use(
      http.get(`${BASE}/me`, ({ request }) =>
        request.headers.get('Authorization') === 'Bearer access-2' ? HttpResponse.json(ME) : problem(401, 'unauthorized'),
      ),
      http.get(`${BASE}/me/dashboard`, ({ request }) =>
        request.headers.get('Authorization') === 'Bearer access-2' ? problem(409, 'plan_required') : problem(401, 'unauthorized'),
      ),
      http.post(`${BASE}/auth/refresh`, async () => {
        refreshes += 1;
        await new Promise((resolve) => setTimeout(resolve, 20));
        return HttpResponse.json({ ...SESSION, accessToken: 'access-2' });
      }),
    );
    const { api } = await signedIn();

    const [me, dashboard] = await Promise.all([api.GET('/me'), api.GET('/me/dashboard').catch((error: unknown) => error)]);

    expect(me.id).toBe(ME.id);
    expect(dashboard).toMatchObject({ status: 409, type: 'plan_required' });
    expect(refreshes).toBe(1);
  });

  it('NFR-05: when the token cannot be renewed the user is signed out and the request fails as unauthorized', async () => {
    let calls = 0;
    server.use(
      http.get(`${BASE}/me`, () => {
        calls += 1;
        return problem(401, 'unauthorized');
      }),
      http.post(`${BASE}/auth/refresh`, () => problem(401, 'invalid_refresh_token')),
    );
    const { api, session } = await signedIn();

    const failure = await api.GET('/me').catch((error: unknown) => error);

    expect(failure).toMatchObject({ status: 401, type: 'unauthorized' });
    expect(session.status()).toBe('signedOut');
    // Not sent a second time: there is no new token to send it with.
    expect(calls).toBe(1);
  });

  it('NFR-05: a request that is refused again with the new token is not renewed a second time', async () => {
    let refreshes = 0;
    server.use(
      http.get(`${BASE}/me`, () => problem(401, 'unauthorized')),
      http.post(`${BASE}/auth/refresh`, () => {
        refreshes += 1;
        return HttpResponse.json({ ...SESSION, accessToken: 'access-2' });
      }),
    );
    const { api } = await signedIn();

    const failure = await api.GET('/me').catch((error: unknown) => error);

    expect(failure).toMatchObject({ status: 401, type: 'unauthorized' });
    expect(refreshes).toBe(1);
  });

  it('FR-ACC-08: a wrong password is no reason to renew the token', async () => {
    let refreshes = 0;
    server.use(
      http.delete(`${BASE}/me`, () => problem(401, 'invalid_credentials', { detail: 'The password is wrong.' })),
      http.post(`${BASE}/auth/refresh`, () => {
        refreshes += 1;
        return HttpResponse.json(SESSION);
      }),
    );
    const { api, session } = await signedIn();

    const failure = await api.DELETE('/me', { body: { password: 'wrong' } }).catch((error: unknown) => error);

    expect(failure).toMatchObject({ status: 401, type: 'invalid_credentials' });
    expect(refreshes).toBe(0);
    expect(session.status()).toBe('signedIn');
  });

  it('sends no token when nobody is signed in', async () => {
    let authorization: string | null = 'not asked';
    server.use(
      http.get(`${BASE}/legal/consents`, ({ request }) => {
        authorization = request.headers.get('Authorization');
        return HttpResponse.json({ age18: 'v1', art9: 'v1', privacy: 'v1' });
      }),
    );
    const api = createApi({ baseUrl: BASE, session: createSession({ baseUrl: BASE }) });

    await api.GET('/legal/consents');

    expect(authorization).toBeNull();
  });

  it('NFR-05: sends cookies and the token to the app\'s own origin only', async () => {
    server.use(http.get(`${BASE}/me`, () => HttpResponse.json(ME)));
    const { session } = await signedIn();
    const sent: RequestCredentials[] = [];
    const api = createApi({
      baseUrl: BASE,
      session,
      fetch: (input, init) => {
        const request = new Request(input, init);
        sent.push(request.credentials);

        return fetch(request);
      },
    });

    await api.GET('/me');

    expect(sent).toEqual(['same-origin']);
  });

  it('NFR-05: a request that comes back unauthorized after the user logged out does not sign the user in again', async () => {
    let refreshes = 0;
    let release: () => void = () => undefined;
    const answered = new Promise<void>((resolve) => (release = resolve));
    let received: () => void = () => undefined;
    const arrived = new Promise<void>((resolve) => (received = resolve));
    server.use(
      http.get(`${BASE}/me`, async () => {
        received();
        await answered;
        return problem(401, 'unauthorized');
      }),
      http.post(`${BASE}/auth/logout`, () => new HttpResponse(null, { status: 204 })),
      http.post(`${BASE}/auth/refresh`, () => {
        refreshes += 1;
        return HttpResponse.json({ ...SESSION, accessToken: 'access-2' });
      }),
    );
    const { api, session } = await signedIn();

    const request = api.GET('/me').catch((error: unknown) => error);
    await arrived;
    await session.logOut();
    release();

    expect(await request).toMatchObject({ status: 401, type: 'unauthorized' });
    expect(refreshes).toBe(0);
    expect(session.status()).toBe('signedOut');
    expect(session.accessToken()).toBeNull();
  });

  it('NFR-05: a path parameter cannot lead the request to another path', async () => {
    const { api } = await signedIn();

    for (const date of ['..', '.', '']) {
      const failure = await api.POST('/days/{date}/check-in', { params: { path: { date } } }).catch((error: unknown) => error);

      // Refused before anything is sent (a request nobody mocked would fail as a network error).
      expect(failure, `"${date}"`).toBeInstanceOf(RangeError);
    }
  });

  it('turns a success that is not what the contract says into a problem, not into wrong data', async () => {
    // What a captive portal answers: 200 and a login page.
    server.use(http.get(`${BASE}/me`, () => new HttpResponse('<html>Please log in to the Wi-Fi</html>', { status: 200, headers: { 'Content-Type': 'text/html' } })));
    const { api } = await signedIn();

    const failure = await api.GET('/me').catch((error: unknown) => error);

    expect(failure).toBeInstanceOf(ApiProblem);
    expect(failure).toMatchObject({ status: 200, type: 'unexpected_response' });
  });

  it('NFR-03: a request that gets no answer in time is given up as a network error', async () => {
    server.use(http.get(`${BASE}/me`, () => new Promise<never>(() => undefined)));
    const { session } = await signedIn();
    const api = createApi({ baseUrl: BASE, session, timeoutMs: 40 });

    await expect(api.GET('/me')).rejects.toBeInstanceOf(NetworkError);
  });
});
