import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { createApi } from '@/core/api/api';
import { ApiProblem } from '@/core/api/errors';
import { createLogbook, logbookDatabaseName, openLogbookDatabase } from '@/core/logging/logbook';
import { createSession } from '@/core/session/session';
import { server } from '@/test/server';

// Offline-first logging (HLD §5.1, FR-LOG-01 to -03, -07, NFR-03): a log is stored on the device
// first and sent when there is a connection. Nothing the user logged is lost, and nothing is sent twice
// in a way that could count twice.

const BASE = 'http://api.test/api/v1';
const NOW = '2026-11-17T12:00:00.000Z';
const DAY = { date: '2026-11-17', count: 1, limit: 9, remaining: 8, checkedIn: false, status: 'provisional' as const };

type Item = { id: string; smokedAt?: string; productType?: string; count?: number; deleted?: boolean };
type SyncAnswer = (items: Item[]) => Response | Promise<Response>;

let databaseNumber = 0;
let batches: Item[][];

/** A logbook on an empty database, whose ids are "…0001", "…0002", … and whose clock stands still. */
async function givenALogbook(answer?: SyncAnswer) {
  batches = [];
  server.use(
    http.post(`${BASE}/auth/login`, () => HttpResponse.json({ accessToken: 'access-1', tokenType: 'Bearer', expiresIn: 900 })),
    http.post(`${BASE}/logs/sync`, async ({ request }) => {
      const { items } = (await request.json()) as { items: Item[] };
      batches.push(items);

      return answer === undefined
        ? HttpResponse.json({ results: items.map((item) => ({ id: item.id, status: 'accepted' })), days: [DAY] })
        : answer(items);
    }),
  );
  const session = createSession({ baseUrl: BASE });
  await session.logIn({ email: 'anna@example.org', password: 'pw' });
  databaseNumber += 1;
  const database = await openLogbookDatabase(`logbook-test-${databaseNumber}`);
  let nextId = 0;
  let now = new Date(NOW);
  const logbook = createLogbook({
    api: createApi({ baseUrl: BASE, session }),
    database,
    now: () => now,
    newId: () => `00000000-0000-4000-8000-${String((nextId += 1)).padStart(12, '0')}`,
  });

  return { logbook, database, travelTo: (instant: string) => (now = new Date(instant)) };
}

const id = (number: number) => `00000000-0000-4000-8000-${String(number).padStart(12, '0')}`;

describe('logbook', () => {
  beforeEach(() => {
    batches = [];
  });

  it('FR-LOG-02: a log is stored on the device at once, with an id of its own and the time of now', async () => {
    const { logbook } = await givenALogbook();

    const logged = await logbook.log({ productType: 'rolled' });

    expect(logged).toEqual({ id: id(1), smokedAt: '2026-11-17T12:00:00Z', productType: 'rolled', count: 1 });
    expect(await logbook.pending()).toBe(1);
    // Nothing was sent yet: logging never waits for the network.
    expect(batches).toEqual([]);
  });

  it('FR-LOG-03: a log can be back-dated and can count several sessions', async () => {
    const { logbook } = await givenALogbook();

    const logged = await logbook.log({ productType: 'shisha', count: 3, smokedAt: new Date('2026-11-16T21:30:15.250Z') });

    expect(logged).toEqual({ id: id(1), smokedAt: '2026-11-16T21:30:15Z', productType: 'shisha', count: 3 });
  });

  it('NFR-03: a sync sends what is pending, oldest first, and forgets what the server accepted', async () => {
    const { logbook, travelTo } = await givenALogbook();
    await logbook.log({ productType: 'cigarette' });
    travelTo('2026-11-17T12:05:00.000Z');
    await logbook.log({ productType: 'vape', count: 2 });

    const outcome = await logbook.sync();

    expect(batches).toEqual([
      [
        { id: id(1), smokedAt: '2026-11-17T12:00:00Z', productType: 'cigarette', count: 1 },
        { id: id(2), smokedAt: '2026-11-17T12:05:00Z', productType: 'vape', count: 2 },
      ],
    ]);
    expect(outcome).toEqual({ status: 'synced', accepted: 2, rejected: [], days: [DAY] });
    expect(await logbook.pending()).toBe(0);
  });

  it('NFR-03: with nothing pending a sync sends nothing', async () => {
    const { logbook } = await givenALogbook();

    expect(await logbook.sync()).toEqual({ status: 'synced', accepted: 0, rejected: [], days: [] });
    expect(batches).toEqual([]);
  });

  it('NFR-03: without a connection everything stays pending and is sent by the next sync, unchanged', async () => {
    let online = false;
    const { logbook } = await givenALogbook((items) =>
      online ? HttpResponse.json({ results: items.map((item) => ({ id: item.id, status: 'accepted' })), days: [DAY] }) : HttpResponse.error(),
    );
    await logbook.log({ productType: 'cigarette' });

    const offline = await logbook.sync();
    online = true;
    const later = await logbook.sync();

    expect(offline).toEqual({ status: 'offline', accepted: 0, rejected: [], days: [] });
    expect(later).toMatchObject({ status: 'synced', accepted: 1 });
    // The same item with the same id both times: the server stores it once.
    expect(batches[0]).toEqual(batches[1]);
    expect(await logbook.pending()).toBe(0);
  });

  it('NFR-03: what is pending survives a restart of the app', async () => {
    const { logbook, database } = await givenALogbook();
    await logbook.log({ productType: 'cigarette' });
    const name = database.name;
    database.close();

    const reopened = await openLogbookDatabase(name);
    const session = createSession({ baseUrl: BASE });
    await session.logIn({ email: 'anna@example.org', password: 'pw' });
    const again = createLogbook({ api: createApi({ baseUrl: BASE, session }), database: reopened, now: () => new Date(NOW), newId: () => id(99) });

    expect(await again.pending()).toBe(1);
    await again.sync();
    expect(batches).toEqual([[{ id: id(1), smokedAt: '2026-11-17T12:00:00Z', productType: 'cigarette', count: 1 }]]);
  });

  it('FR-LOG-07: a log the server refuses leaves the outbox and is kept with its reason for the user to see', async () => {
    const { logbook } = await givenALogbook((items) =>
      HttpResponse.json({
        results: items.map((item) => (item.id === id(1) ? { id: item.id, status: 'rejected', reason: 'day_finalized' } : { id: item.id, status: 'accepted' })),
        days: [DAY],
      }),
    );
    const refused = await logbook.log({ productType: 'pipe', count: 2, smokedAt: new Date('2026-11-10T10:00:00Z') });
    await logbook.log({ productType: 'cigarette' });

    const outcome = await logbook.sync();

    expect(outcome).toEqual({ status: 'synced', accepted: 1, rejected: [{ ...refused, reason: 'day_finalized' }], days: [DAY] });
    // It will be refused every time: sending it again would be pointless.
    expect(await logbook.pending()).toBe(0);
    expect(await logbook.rejections()).toEqual([{ ...refused, reason: 'day_finalized' }]);

    await logbook.dismissRejection(id(1));

    expect(await logbook.rejections()).toEqual([]);
  });

  it('FR-LOG-03: a log that was sent is deleted by sending its id as deleted', async () => {
    const { logbook } = await givenALogbook();
    await logbook.log({ productType: 'cigarette' });
    await logbook.sync();

    await logbook.remove(id(1));
    await logbook.sync();

    expect(batches[1]).toEqual([{ id: id(1), deleted: true }]);
    expect(await logbook.pending()).toBe(0);
  });

  it('FR-LOG-03: a log that was never sent is simply taken out of the outbox', async () => {
    const { logbook } = await givenALogbook();
    await logbook.log({ productType: 'cigarette' });
    await logbook.log({ productType: 'vape' });

    await logbook.remove(id(1));
    await logbook.sync();

    expect(batches).toEqual([[{ id: id(2), smokedAt: '2026-11-17T12:00:00Z', productType: 'vape', count: 1 }]]);
  });

  it('FR-LOG-03: a correction of a log replaces what is pending for its id, or is sent as a new item for it', async () => {
    const { logbook } = await givenALogbook();
    await logbook.log({ productType: 'cigarette' });

    // Not sent yet: the pending item itself is corrected.
    await logbook.correct(id(1), { productType: 'cigar', count: 2, smokedAt: new Date('2026-11-17T11:00:00Z') });
    await logbook.sync();
    // Sent already: the correction goes out as an item with the same id.
    await logbook.correct(id(1), { productType: 'cigar', count: 3, smokedAt: new Date('2026-11-17T11:00:00Z') });
    await logbook.sync();

    expect(batches).toEqual([
      [{ id: id(1), smokedAt: '2026-11-17T11:00:00Z', productType: 'cigar', count: 2 }],
      [{ id: id(1), smokedAt: '2026-11-17T11:00:00Z', productType: 'cigar', count: 3 }],
    ]);
  });

  it('FR-LOG-02: more than 200 pending items go out in batches of 200, in order', async () => {
    const { logbook } = await givenALogbook();
    for (let number = 1; number <= 205; number += 1) {
      await logbook.log({ productType: 'cigarette' });
    }

    const outcome = await logbook.sync();

    expect(batches.map((batch) => batch.length)).toEqual([200, 5]);
    expect(batches[0]?.[0]?.id).toBe(id(1));
    expect(batches[1]?.[4]?.id).toBe(id(205));
    expect(outcome).toMatchObject({ status: 'synced', accepted: 205 });
    expect(await logbook.pending()).toBe(0);
  });

  it('NFR-03: two syncs at the same moment are one: nothing is sent twice', async () => {
    const { logbook } = await givenALogbook(async (items) => {
      await new Promise((resolve) => setTimeout(resolve, 20));
      return HttpResponse.json({ results: items.map((item) => ({ id: item.id, status: 'accepted' })), days: [DAY] });
    });
    await logbook.log({ productType: 'cigarette' });

    const [first, second] = await Promise.all([logbook.sync(), logbook.sync()]);

    expect(batches).toHaveLength(1);
    expect(first).toEqual(second);
  });

  it('NFR-03: a log made while a sync is on its way stays pending for the next one', async () => {
    let logbookInFlight: Awaited<ReturnType<typeof givenALogbook>>['logbook'] | undefined;
    const given = await givenALogbook(async (items) => {
      // The user taps "+1" again while the request is in the air.
      await logbookInFlight?.log({ productType: 'vape' });
      logbookInFlight = undefined;
      return HttpResponse.json({ results: items.map((item) => ({ id: item.id, status: 'accepted' })), days: [DAY] });
    });
    logbookInFlight = given.logbook;
    await given.logbook.log({ productType: 'cigarette' });

    await given.logbook.sync();

    expect(batches).toEqual([[{ id: id(1), smokedAt: '2026-11-17T12:00:00Z', productType: 'cigarette', count: 1 }]]);
    expect(await given.logbook.pending()).toBe(1);
  });

  it('NFR-03: a failure of the server keeps everything pending and is passed on', async () => {
    const { logbook } = await givenALogbook(() =>
      HttpResponse.json({ type: 'service_unavailable', title: 'Service unavailable', status: 503 }, { status: 503, headers: { 'Content-Type': 'application/problem+json' } }),
    );
    await logbook.log({ productType: 'cigarette' });

    const failure = await logbook.sync().catch((error: unknown) => error);

    expect(failure).toBeInstanceOf(ApiProblem);
    expect(failure).toMatchObject({ status: 503 });
    expect(await logbook.pending()).toBe(1);
  });

  it('FR-ACC-08: clearing the logbook leaves nothing of the user on the device', async () => {
    const { logbook } = await givenALogbook((items) =>
      HttpResponse.json({ results: items.map((item) => ({ id: item.id, status: 'rejected', reason: 'in_future' })), days: [] }),
    );
    await logbook.log({ productType: 'cigarette' });
    await logbook.sync();
    await logbook.log({ productType: 'cigarette' });

    await logbook.clear();

    expect(await logbook.pending()).toBe(0);
    expect(await logbook.rejections()).toEqual([]);
  });

  it('NFR-03: what the server answered before the connection broke is not thrown away', async () => {
    let requests = 0;
    const { logbook } = await givenALogbook((items) => {
      requests += 1;
      return requests === 1
        ? HttpResponse.json({ results: items.map((item) => ({ id: item.id, status: 'accepted' })), days: [DAY] })
        : HttpResponse.error();
    });
    for (let number = 1; number <= 205; number += 1) {
      await logbook.log({ productType: 'cigarette' });
    }

    const outcome = await logbook.sync();

    expect(outcome).toEqual({ status: 'offline', accepted: 200, rejected: [], days: [DAY] });
    expect(await logbook.pending()).toBe(5);
  });

  it('NFR-03: an answer that is not what the contract says keeps everything pending', async () => {
    const { logbook } = await givenALogbook(() => HttpResponse.json({}));
    await logbook.log({ productType: 'cigarette' });

    const failure = await logbook.sync().catch((error: unknown) => error);

    expect(failure).toBeInstanceOf(ApiProblem);
    expect(failure).toMatchObject({ type: 'unexpected_response' });
    expect(await logbook.pending()).toBe(1);
  });

  it('NFR-03: a log the server could never take is refused when it is made, not when it blocks the outbox', async () => {
    const { logbook } = await givenALogbook();

    for (const smokedAt of [new Date('+010000-01-01T00:00:00Z'), new Date('-000001-01-01T00:00:00Z'), new Date(Number.NaN)]) {
      await expect(logbook.log({ productType: 'cigarette', smokedAt })).rejects.toBeInstanceOf(RangeError);
    }
    for (const count of [0, 51, 1.5]) {
      await expect(logbook.log({ productType: 'cigarette', count })).rejects.toBeInstanceOf(RangeError);
    }

    expect(await logbook.pending()).toBe(0);
  });

  it('FR-LOG-03: a log that is deleted while it is being sent does not stay on the server', async () => {
    // A server that applies what it gets, in the order it gets it. A deleted log stays deleted.
    const stored = new Map<string, 'live' | 'deleted'>();
    const apply = (items: Item[]) => {
      for (const item of items) {
        if (item.deleted === true) {
          if (stored.has(item.id)) {
            stored.set(item.id, 'deleted');
          }
        } else if (stored.get(item.id) !== 'deleted') {
          stored.set(item.id, 'live');
        }
      }

      return HttpResponse.json({ results: items.map((item) => ({ id: item.id, status: 'accepted' })), days: [DAY] });
    };

    for (const removeFirst of [false, true]) {
      stored.clear();
      const { logbook } = await givenALogbook(apply);
      const log = await logbook.log({ productType: 'cigarette' });

      // The user deletes the log at the very moment the outbox goes out.
      await Promise.all(removeFirst ? [logbook.remove(log.id), logbook.sync()] : [logbook.sync(), logbook.remove(log.id)]);
      await logbook.sync();

      expect(stored.get(log.id), removeFirst ? 'removed first' : 'sync first').not.toBe('live');
      expect(await logbook.pending()).toBe(0);
    }
  });

  it('NFR-03: two tabs take turns, so an old item cannot overtake its correction', async () => {
    // One lock for both tabs, as the browser gives it to all tabs of the app.
    let last: Promise<unknown> = Promise.resolve();
    const exclusive = <T>(run: () => Promise<T>): Promise<T> => {
      const result = last.then(run, run);
      last = result.catch(() => undefined);

      return result;
    };
    const counts = new Map<string, number>();
    let requests = 0;
    let release: () => void = () => undefined;
    const slow = new Promise<void>((resolve) => (release = resolve));
    const first = await givenALogbook(async (items) => {
      requests += 1;
      if (requests === 1) {
        // The request of the first tab hangs in the network.
        await slow;
      }
      for (const item of items) {
        counts.set(item.id, item.count ?? 0);
      }

      return HttpResponse.json({ results: items.map((item) => ({ id: item.id, status: 'accepted' })), days: [DAY] });
    });
    const session = createSession({ baseUrl: BASE });
    await session.logIn({ email: 'anna@example.org', password: 'pw' });
    const tabOne = createLogbook({ api: createApi({ baseUrl: BASE, session }), database: first.database, now: () => new Date(NOW), newId: () => id(1), exclusive });
    const tabTwo = createLogbook({
      api: createApi({ baseUrl: BASE, session }),
      database: await openLogbookDatabase(first.database.name),
      now: () => new Date(NOW),
      newId: () => id(2),
      exclusive,
    });
    await tabOne.log({ productType: 'cigarette', count: 1 });

    const sendingOld = tabOne.sync();
    await vi.waitFor(() => expect(requests).toBe(1));
    await tabTwo.correct(id(1), { productType: 'cigarette', count: 3, smokedAt: new Date(NOW) });
    const sendingNew = tabTwo.sync();
    await new Promise((resolve) => setTimeout(resolve, 30));
    // The second tab waits for the first instead of sending past it.
    expect(requests).toBe(1);
    release();
    await Promise.all([sendingOld, sendingNew]);

    expect(requests).toBe(2);
    expect(counts.get(id(1))).toBe(3);
    expect(await tabTwo.pending()).toBe(0);
  });

  it('FR-ACC-08: every user has a logbook of their own on the device', async () => {
    const anna = '00000000-0000-7000-8000-000000000001';
    const bob = '00000000-0000-7000-8000-000000000002';
    const { logbook: ofAnna } = await givenALogbook();
    const session = createSession({ baseUrl: BASE });
    await session.logIn({ email: 'bob@example.org', password: 'pw' });
    const api = createApi({ baseUrl: BASE, session });
    const annas = createLogbook({ api, database: await openLogbookDatabase(logbookDatabaseName(anna)), now: () => new Date(NOW), newId: () => id(1) });
    const bobs = createLogbook({ api, database: await openLogbookDatabase(logbookDatabaseName(bob)), now: () => new Date(NOW), newId: () => id(2) });
    await annas.clear();
    await bobs.clear();

    await annas.log({ productType: 'cigarette' });

    // What Anna has not sent yet is not Bob's to send, also on a shared computer.
    expect(logbookDatabaseName(anna)).not.toBe(logbookDatabaseName(bob));
    expect(logbookDatabaseName(anna)).toContain(anna);
    expect(await bobs.pending()).toBe(0);
    await bobs.sync();
    expect(batches).toEqual([]);
    expect(await annas.pending()).toBe(1);
    expect(await ofAnna.pending()).toBe(0);
  });

  it('FR-LOG-03: a log this device only corrected is known to the server, so deleting it sends the deletion', async () => {
    const { logbook } = await givenALogbook();
    // A log from another device, shown from the server and corrected here.
    await logbook.correct(id(77), { productType: 'cigarette', count: 2, smokedAt: new Date(NOW) });

    await logbook.remove(id(77));
    await logbook.sync();

    expect(batches).toEqual([[{ id: id(77), deleted: true }]]);
  });

  it('FR-LOG-03: deleting the same log twice still sends the deletion', async () => {
    const { logbook } = await givenALogbook();

    // A double tap on "delete" for a log the server has.
    await logbook.remove(id(77));
    await logbook.remove(id(77));
    await logbook.sync();

    expect(batches).toEqual([[{ id: id(77), deleted: true }]]);
    expect(await logbook.pending()).toBe(0);
  });

  it('NFR-03: a sync whose request hangs gives up as offline and does not block the next sync', async () => {
    let hang = true;
    server.use(http.post(`${BASE}/auth/login`, () => HttpResponse.json({ accessToken: 'access-1', tokenType: 'Bearer', expiresIn: 900 })));
    const session = createSession({ baseUrl: BASE });
    await session.logIn({ email: 'anna@example.org', password: 'pw' });
    const sent: Item[][] = [];
    server.use(
      http.post(`${BASE}/logs/sync`, async ({ request }) => {
        const { items } = (await request.json()) as { items: Item[] };
        if (hang) {
          return new Promise<never>(() => undefined);
        }
        sent.push(items);

        return HttpResponse.json({ results: items.map((item) => ({ id: item.id, status: 'accepted' })), days: [DAY] });
      }),
    );
    databaseNumber += 1;
    const logbook = createLogbook({
      api: createApi({ baseUrl: BASE, session, timeoutMs: 40 }),
      database: await openLogbookDatabase(`logbook-test-${databaseNumber}`),
      now: () => new Date(NOW),
      newId: () => id(1),
    });
    await logbook.log({ productType: 'cigarette' });

    const stalled = await logbook.sync();
    hang = false;
    const later = await logbook.sync();

    expect(stalled).toEqual({ status: 'offline', accepted: 0, rejected: [], days: [] });
    expect(later).toMatchObject({ status: 'synced', accepted: 1 });
    expect(sent).toHaveLength(1);
  });
});
