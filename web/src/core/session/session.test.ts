import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ApiProblem, NetworkError } from '@/core/api/errors';
import { createSession, LOGOUT_OWED_KEY } from '@/core/session/session';
import { server } from '@/test/server';

// The session of the web app (HLD §6 "Token storage", NFR-05): the access token lives in memory
// only, the refresh token is an HttpOnly cookie the script never sees.

const BASE = 'http://api.test/api/v1';
const SESSION = { accessToken: 'access-1', tokenType: 'Bearer', expiresIn: 900 };
/**
 * A fetch that notes how each request was sent. What a mocked server sees of a request says nothing
 * about cookies: only the request itself tells whether the browser was asked to send them.
 */
function recordingFetch() {
  const sent: { path: string; credentials: RequestCredentials }[] = [];
  const fetchAndRecord: typeof fetch = (input, init) => {
    const request = new Request(input, init);
    sent.push({ path: new URL(request.url).pathname, credentials: request.credentials });

    return fetch(request);
  };

  return { fetch: fetchAndRecord, sent };
}

const problem = (status: number, type: string, extra: object = {}) =>
  HttpResponse.json({ type, title: type, status, ...extra }, { status, headers: { 'Content-Type': 'application/problem+json' } });

describe('session', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('NFR-05: is unknown until it was restored, and nobody is signed in', () => {
    const session = createSession({ baseUrl: BASE });

    expect(session.status()).toBe('unknown');
    expect(session.accessToken()).toBeNull();
  });

  it('NFR-05: restores the session from the refresh cookie when the app starts', async () => {
    let request: Request | undefined;
    server.use(
      http.post(`${BASE}/auth/refresh`, ({ request: received }) => {
        request = received.clone();
        return HttpResponse.json(SESSION);
      }),
    );
    const recorded = recordingFetch();
    const session = createSession({ baseUrl: BASE, fetch: recorded.fetch });

    await expect(session.restore()).resolves.toBe('signedIn');

    expect(session.status()).toBe('signedIn');
    expect(session.accessToken()).toBe('access-1');
    // The cookie is the whole request: no body. Cookies go to the app's own origin and nowhere else.
    expect(await request?.text()).toBe('');
    expect(recorded.sent).toEqual([{ path: '/api/v1/auth/refresh', credentials: 'same-origin' }]);
  });

  it('NFR-05: without a valid refresh cookie the user is signed out', async () => {
    server.use(http.post(`${BASE}/auth/refresh`, () => problem(401, 'invalid_refresh_token')));
    const session = createSession({ baseUrl: BASE });

    await expect(session.restore()).resolves.toBe('signedOut');

    expect(session.status()).toBe('signedOut');
    expect(session.accessToken()).toBeNull();
  });

  it('NFR-03: without a connection the session stays unknown, so the app can work offline and try again', async () => {
    server.use(http.post(`${BASE}/auth/refresh`, () => HttpResponse.error()));
    const session = createSession({ baseUrl: BASE });

    await expect(session.restore()).rejects.toBeInstanceOf(NetworkError);

    expect(session.status()).toBe('unknown');
  });

  it('FR-ACC-04: logs in with the refresh token as a cookie and keeps the access token in memory only', async () => {
    let body: unknown;
    server.use(
      http.post(`${BASE}/auth/login`, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json(SESSION);
      }),
    );
    const session = createSession({ baseUrl: BASE });

    await session.logIn({ email: 'anna@example.org', password: 'correct horse battery staple', deviceLabel: 'Firefox' });

    expect(body).toEqual({ email: 'anna@example.org', password: 'correct horse battery staple', deviceLabel: 'Firefox', refreshIn: 'cookie' });
    expect(session.status()).toBe('signedIn');
    expect(session.accessToken()).toBe('access-1');
    // Nothing a script of another tab or an extension could read later.
    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
    expect(document.cookie).toBe('');
  });

  it('FR-ACC-04: a device label that was not given is not sent', async () => {
    let body: unknown;
    server.use(
      http.post(`${BASE}/auth/login`, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json(SESSION);
      }),
    );
    const session = createSession({ baseUrl: BASE });

    await session.logIn({ email: 'anna@example.org', password: 'pw' });

    expect(body).toEqual({ email: 'anna@example.org', password: 'pw', refreshIn: 'cookie' });
  });

  it('FR-ACC-04: a login that fails is passed on as the problem the server sent, and nobody is signed in', async () => {
    server.use(
      http.post(`${BASE}/auth/login`, () => problem(401, 'invalid_credentials', { detail: 'The email address or the password is wrong.' })),
    );
    const session = createSession({ baseUrl: BASE });

    const failure = await session.logIn({ email: 'anna@example.org', password: 'wrong' }).catch((error: unknown) => error);

    expect(failure).toBeInstanceOf(ApiProblem);
    expect(failure).toMatchObject({ status: 401, type: 'invalid_credentials', detail: 'The email address or the password is wrong.' });
    expect(session.status()).not.toBe('signedIn');
    expect(session.accessToken()).toBeNull();
  });

  it('NFR-05: a refresh replaces the access token', async () => {
    const tokens = ['access-1', 'access-2'];
    server.use(http.post(`${BASE}/auth/refresh`, () => HttpResponse.json({ ...SESSION, accessToken: tokens.shift() })));
    const session = createSession({ baseUrl: BASE });
    await session.restore();

    await expect(session.refresh()).resolves.toBe(true);

    expect(session.accessToken()).toBe('access-2');
  });

  it('NFR-05: refreshes that are asked for at the same moment are one request', async () => {
    let calls = 0;
    server.use(
      http.post(`${BASE}/auth/refresh`, async () => {
        calls += 1;
        await new Promise((resolve) => setTimeout(resolve, 20));
        return HttpResponse.json({ ...SESSION, accessToken: `access-${calls}` });
      }),
    );
    const session = createSession({ baseUrl: BASE });

    const results = await Promise.all([session.refresh(), session.refresh(), session.refresh()]);

    expect(results).toEqual([true, true, true]);
    expect(calls).toBe(1);
    expect(session.accessToken()).toBe('access-1');
  });

  it('NFR-05: a refresh the server refuses signs the user out', async () => {
    server.use(http.post(`${BASE}/auth/login`, () => HttpResponse.json(SESSION)));
    const session = createSession({ baseUrl: BASE });
    await session.logIn({ email: 'anna@example.org', password: 'pw' });
    server.use(http.post(`${BASE}/auth/refresh`, () => problem(401, 'invalid_refresh_token')));

    await expect(session.refresh()).resolves.toBe(false);

    expect(session.status()).toBe('signedOut');
    expect(session.accessToken()).toBeNull();
  });

  it('NFR-03: a refresh that fails for lack of a connection keeps the user signed in', async () => {
    server.use(http.post(`${BASE}/auth/login`, () => HttpResponse.json(SESSION)));
    const session = createSession({ baseUrl: BASE });
    await session.logIn({ email: 'anna@example.org', password: 'pw' });
    server.use(http.post(`${BASE}/auth/refresh`, () => HttpResponse.error()));

    await expect(session.refresh()).rejects.toBeInstanceOf(NetworkError);

    expect(session.status()).toBe('signedIn');
    expect(session.accessToken()).toBe('access-1');
  });

  it('FR-ACC-05: logging out tells the server and forgets the access token', async () => {
    let loggedOut = false;
    server.use(
      http.post(`${BASE}/auth/login`, () => HttpResponse.json(SESSION)),
      http.post(`${BASE}/auth/logout`, () => {
        loggedOut = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );
    const recorded = recordingFetch();
    const session = createSession({ baseUrl: BASE, fetch: recorded.fetch });
    await session.logIn({ email: 'anna@example.org', password: 'pw' });

    await session.logOut();

    expect(loggedOut).toBe(true);
    // Login sets the cookie and logout ends its session: both need the browser to handle cookies.
    expect(recorded.sent).toEqual([
      { path: '/api/v1/auth/login', credentials: 'same-origin' },
      { path: '/api/v1/auth/logout', credentials: 'same-origin' },
    ]);
    expect(session.status()).toBe('signedOut');
    expect(session.accessToken()).toBeNull();
  });

  it('FR-ACC-05: logging out works without a connection: this device forgets the token all the same', async () => {
    server.use(
      http.post(`${BASE}/auth/login`, () => HttpResponse.json(SESSION)),
      http.post(`${BASE}/auth/logout`, () => HttpResponse.error()),
    );
    const session = createSession({ baseUrl: BASE });
    await session.logIn({ email: 'anna@example.org', password: 'pw' });

    await expect(session.logOut()).resolves.toBeUndefined();

    expect(session.status()).toBe('signedOut');
    expect(session.accessToken()).toBeNull();
  });

  it('tells its listeners when the status changes, and only then', async () => {
    server.use(
      http.post(`${BASE}/auth/login`, () => HttpResponse.json(SESSION)),
      http.post(`${BASE}/auth/refresh`, () => HttpResponse.json({ ...SESSION, accessToken: 'access-2' })),
      http.post(`${BASE}/auth/logout`, () => new HttpResponse(null, { status: 204 })),
    );
    const session = createSession({ baseUrl: BASE });
    const seen: string[] = [];
    const listener = vi.fn((status: string) => seen.push(status));
    const unsubscribe = session.onChange(listener);

    await session.logIn({ email: 'anna@example.org', password: 'pw' });
    // A new token is no new status.
    await session.refresh();
    await session.logOut();
    unsubscribe();
    await session.logIn({ email: 'anna@example.org', password: 'pw' });

    expect(seen).toEqual(['signedIn', 'signedOut']);
  });

  it('FR-ACC-05: a logout that could not reach the server is delivered at the next start, and the user stays signed out', async () => {
    const calls: string[] = [];
    let online = false;
    server.use(
      http.post(`${BASE}/auth/login`, () => HttpResponse.json(SESSION)),
      http.post(`${BASE}/auth/logout`, () => {
        calls.push('logout');
        return online ? new HttpResponse(null, { status: 204 }) : HttpResponse.error();
      }),
      // The cookie is still good on the server: a refresh would sign the user in again.
      http.post(`${BASE}/auth/refresh`, () => {
        calls.push('refresh');
        return HttpResponse.json(SESSION);
      }),
    );
    const session = createSession({ baseUrl: BASE });
    await session.logIn({ email: 'anna@example.org', password: 'pw' });
    await session.logOut();

    // The next start of the app, still without a connection: signed out, and no refresh is tried.
    const stillOffline = createSession({ baseUrl: BASE });
    await expect(stillOffline.restore()).resolves.toBe('signedOut');
    expect(calls).toEqual(['logout', 'logout']);

    // The start after that, online: the logout is delivered first, and that is all.
    online = true;
    const backOnline = createSession({ baseUrl: BASE });
    await expect(backOnline.restore()).resolves.toBe('signedOut');
    expect(calls).toEqual(['logout', 'logout', 'logout']);
    expect(backOnline.accessToken()).toBeNull();

    // Nothing is owed any more: a later start asks the server as usual.
    await createSession({ baseUrl: BASE }).restore();
    expect(calls).toEqual(['logout', 'logout', 'logout', 'refresh']);
    // What was remembered on the device in between was never a token.
    expect(JSON.stringify({ ...localStorage })).not.toContain('access-1');
  });

  it('FR-ACC-05: a logout that reached the server leaves nothing to deliver', async () => {
    const calls: string[] = [];
    server.use(
      http.post(`${BASE}/auth/login`, () => HttpResponse.json(SESSION)),
      http.post(`${BASE}/auth/logout`, () => {
        calls.push('logout');
        return new HttpResponse(null, { status: 204 });
      }),
      http.post(`${BASE}/auth/refresh`, () => {
        calls.push('refresh');
        return problem(401, 'invalid_refresh_token');
      }),
    );
    const session = createSession({ baseUrl: BASE });
    await session.logIn({ email: 'anna@example.org', password: 'pw' });
    await session.logOut();

    await createSession({ baseUrl: BASE }).restore();

    expect(calls).toEqual(['logout', 'refresh']);
    expect(localStorage.length).toBe(0);
  });

  it('FR-ACC-04: a new login takes the place of a logout that was still to be delivered', async () => {
    const calls: string[] = [];
    server.use(
      http.post(`${BASE}/auth/login`, () => HttpResponse.json(SESSION)),
      http.post(`${BASE}/auth/logout`, () => {
        calls.push('logout');
        return HttpResponse.error();
      }),
      http.post(`${BASE}/auth/refresh`, () => {
        calls.push('refresh');
        return HttpResponse.json(SESSION);
      }),
    );
    const session = createSession({ baseUrl: BASE });
    await session.logIn({ email: 'anna@example.org', password: 'pw' });
    await session.logOut();
    // The cookie of the new login replaces the old one in the browser.
    await session.logIn({ email: 'anna@example.org', password: 'pw' });

    await expect(createSession({ baseUrl: BASE }).restore()).resolves.toBe('signedIn');

    // The next start must not log the new session out.
    expect(calls).toEqual(['logout', 'refresh']);
  });

  it('FR-ACC-05: a logout the server answered with a failure is still owed', async () => {
    const calls: string[] = [];
    let status = 503;
    server.use(
      http.post(`${BASE}/auth/login`, () => HttpResponse.json(SESSION)),
      http.post(`${BASE}/auth/logout`, () => {
        calls.push('logout');
        return status === 204 ? new HttpResponse(null, { status }) : problem(status, 'service_unavailable');
      }),
      http.post(`${BASE}/auth/refresh`, () => {
        calls.push('refresh');
        return HttpResponse.json(SESSION);
      }),
    );
    const session = createSession({ baseUrl: BASE });
    await session.logIn({ email: 'anna@example.org', password: 'pw' });
    // The server is down: it did not end the session.
    await session.logOut();

    await expect(createSession({ baseUrl: BASE }).restore()).resolves.toBe('signedOut');
    status = 429;
    await expect(createSession({ baseUrl: BASE }).restore()).resolves.toBe('signedOut');
    status = 204;
    await expect(createSession({ baseUrl: BASE }).restore()).resolves.toBe('signedOut');

    // Tried at every start until the server took it, and never a refresh in between.
    expect(calls).toEqual(['logout', 'logout', 'logout', 'logout']);
    expect(localStorage.length).toBe(0);
  });

  it('FR-ACC-05: while a logout is owed, nothing renews the session', async () => {
    let refreshes = 0;
    server.use(
      http.post(`${BASE}/auth/login`, () => HttpResponse.json(SESSION)),
      http.post(`${BASE}/auth/logout`, () => HttpResponse.error()),
      http.post(`${BASE}/auth/refresh`, () => {
        refreshes += 1;
        return HttpResponse.json(SESSION);
      }),
    );
    const session = createSession({ baseUrl: BASE });
    await session.logIn({ email: 'anna@example.org', password: 'pw' });
    await session.logOut();

    await expect(session.refresh()).resolves.toBe(false);
    await expect(createSession({ baseUrl: BASE }).refresh()).resolves.toBe(false);

    expect(refreshes).toBe(0);
    expect(session.status()).toBe('signedOut');
  });

  it('FR-ACC-05: a logout in one tab that is still owed signs the other tabs out too', async () => {
    server.use(http.post(`${BASE}/auth/login`, () => HttpResponse.json(SESSION)));
    const otherTab = createSession({ baseUrl: BASE });
    await otherTab.logIn({ email: 'anna@example.org', password: 'pw' });
    const seen: string[] = [];
    otherTab.onChange((status) => seen.push(status));

    // What the browser tells every other tab when one tab writes the note.
    localStorage.setItem(LOGOUT_OWED_KEY, '1');
    window.dispatchEvent(new StorageEvent('storage', { key: LOGOUT_OWED_KEY, newValue: '1', storageArea: localStorage }));

    expect(otherTab.status()).toBe('signedOut');
    expect(otherTab.accessToken()).toBeNull();
    expect(seen).toEqual(['signedOut']);

    // A change of anything else in the storage is none of the session's business.
    await otherTab.logIn({ email: 'anna@example.org', password: 'pw' });
    window.dispatchEvent(new StorageEvent('storage', { key: 'theme', newValue: 'dark', storageArea: localStorage }));

    expect(otherTab.status()).toBe('signedIn');
  });

  it('NFR-03: a request that gets no answer in time is given up as a network error', async () => {
    server.use(http.post(`${BASE}/auth/refresh`, () => new Promise<never>(() => undefined)));
    const session = createSession({ baseUrl: BASE, timeoutMs: 40 });

    await expect(session.restore()).rejects.toBeInstanceOf(NetworkError);

    expect(session.status()).toBe('unknown');
  });
});
