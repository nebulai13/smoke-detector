import { openDB } from 'idb';
import type { DBSchema, IDBPDatabase } from 'idb';

import type { components } from '@/api/schema';
import type { Api } from '@/core/api/api';
import { ApiProblem, NetworkError } from '@/core/api/errors';

// Offline-first logging (HLD §5.1, FR-LOG-02, -03, -07, NFR-03). A log is written to the outbox on the
// device first and sent by sync(). The server stores items by their id, so an item sent twice
// (an answer lost on the way back) is stored once.

export type ProductType = components['schemas']['ProductType'];
export type Day = components['schemas']['Day'];
export type LogItem = components['schemas']['LogItem'];
export type RejectReason = NonNullable<components['schemas']['LogItemResult']['reason']>;

export interface Log {
  id: string;
  /** UTC, whole seconds: `YYYY-MM-DDTHH:MM:SSZ`. */
  smokedAt: string;
  productType: ProductType;
  count: number;
}

/** A log the server refused, kept for the user to see until dismissed. `unknown` when the server gave no reason. */
export type Rejected = (Log | { id: string; deleted: true }) & { reason: RejectReason | 'unknown' };

/**
 * What a sync achieved. `offline` when the connection broke: what the server answered to the batches
 * before is in it all the same, the rest stays pending.
 */
export interface SyncOutcome {
  status: 'synced' | 'offline';
  accepted: number;
  rejected: Rejected[];
  days: Day[];
}

export interface LogInput {
  productType: ProductType;
  /** Sessions of this log, a whole number from 1 to 50. Default 1. */
  count?: number;
  /** Default: now. */
  smokedAt?: Date;
}

export interface Logbook {
  /** Stores the log as pending. Sends nothing. Rejects with RangeError for a log the server could never take. */
  log(input: LogInput): Promise<Log>;
  /**
   * Replaces what is pending for the id, or queues the correction as a new item for it. The id then
   * counts as known to the server (a log from another device is corrected here), so a later remove()
   * sends a deletion.
   */
  correct(id: string, log: { productType: ProductType; count: number; smokedAt: Date }): Promise<Log>;
  /** Drops a pending log that never went out; queues a deletion otherwise (once: a pending deletion stays as it is). */
  remove(id: string): Promise<void>;
  /** How many items wait to be sent. */
  pending(): Promise<number>;
  /** Sends what is pending. Throws ApiProblem when the server fails; everything then stays pending. */
  sync(): Promise<SyncOutcome>;
  rejections(): Promise<Rejected[]>;
  dismissRejection(id: string): Promise<void>;
  /** Empties outbox, rejections and what is known about sent logs: at logout and account deletion. */
  clear(): Promise<void>;
}

/** The most items one `POST /logs/sync` takes (`LogSyncRequest.items.maxItems`). */
export const SYNC_BATCH_SIZE = 200;

/** The database of the logbook of one user: what one user has not sent yet is never another's to send. */
export function logbookDatabaseName(userId: string): string {
  return `smoke-detector-logbook-${userId}`;
}

interface OutboxRecord {
  /** Order of the outbox, given by IndexedDB. A replaced item gets a new one. */
  seq?: number;
  /** The id of the log; at most one record per id. */
  id: string;
  item: LogItem;
}

interface LogbookSchema extends DBSchema {
  /** The items waiting to be sent, oldest first. */
  outbox: { key: number; value: OutboxRecord; indexes: { byId: string } };
  /** The ids of logs that have gone out in a request at least once, or were corrected: the server may know them. */
  sent: { key: string; value: { id: string } };
  rejections: { key: string; value: Rejected };
}

export type LogbookDatabase = IDBPDatabase<LogbookSchema>;

/** Opens (and creates or upgrades) a database of the logbook, see logbookDatabaseName(). Version 1. */
export function openLogbookDatabase(name: string): Promise<LogbookDatabase> {
  return openDB<LogbookSchema>(name, 1, {
    upgrade(database, oldVersion) {
      if (oldVersion < 1) {
        const outbox = database.createObjectStore('outbox', { keyPath: 'seq', autoIncrement: true });
        outbox.createIndex('byId', 'id', { unique: true });
        database.createObjectStore('sent', { keyPath: 'id' });
        database.createObjectStore('rejections', { keyPath: 'id' });
      }
    },
  });
}

export interface LogbookOptions {
  api: Api;
  database: LogbookDatabase;
  now?: () => Date;
  newId?: () => string;
  /**
   * Runs a sync (all its batches) while no other tab of the app syncs. Default: the Web Locks API
   * when the browser has it, otherwise the run starts at once.
   */
  exclusive?: <T>(run: () => Promise<T>) => Promise<T>;
}

const SYNC_LOCK_NAME = 'smoke-detector-logbook-sync';

/** Two tabs that send the same outbox at once could let an old item overtake its correction. */
function webLock<T>(run: () => Promise<T>): Promise<T> {
  if (typeof navigator === 'undefined' || !('locks' in navigator)) {
    return run();
  }

  // The lock is held until the run has settled; its result is passed on as it is.
  return new Promise<T>((resolve, reject) => {
    navigator.locks.request(SYNC_LOCK_NAME, () => run().then(resolve, reject)).catch(reject);
  });
}

/** A UTC instant in whole seconds, as the API takes it. */
export function toApiInstant(instant: Date): string {
  return instant.toISOString().replace(/\.\d+Z$/, 'Z');
}

function checkSmokedAt(smokedAt: Date): void {
  // The API takes `YYYY-MM-DDTHH:MM:SSZ` only: a year of four digits from 0001.
  const time = smokedAt.getTime();
  if (Number.isNaN(time)) {
    throw new RangeError('smokedAt must be a valid date');
  }
  const year = smokedAt.getUTCFullYear();
  if (year < 1 || year > 9999) {
    throw new RangeError('smokedAt must lie in the years 0001 to 9999');
  }
}

function checkCount(count: number): void {
  // An item the server cannot read fails the whole request (422) and would block the outbox.
  if (!Number.isInteger(count) || count < 1 || count > 50) {
    throw new RangeError('count must be a whole number from 1 to 50');
  }
}

function toLog(id: string, input: { productType: ProductType; count: number; smokedAt: Date }): Log {
  checkCount(input.count);
  checkSmokedAt(input.smokedAt);

  return { id, smokedAt: toApiInstant(input.smokedAt), productType: input.productType, count: input.count };
}

const isRecord = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);

type SyncAnswer = components['schemas']['LogSyncResult'];

/** The arrays the logbook relies on. What is in them is the contract's word. */
const isSyncAnswer = (value: unknown): value is SyncAnswer => isRecord(value) && Array.isArray(value.results) && Array.isArray(value.days);

function rejectedFrom(item: LogItem, reason: RejectReason | undefined): Rejected {
  const because = reason ?? 'unknown';
  if (item.deleted === true || item.smokedAt === undefined || item.productType === undefined || item.count === undefined) {
    return { id: item.id, deleted: true, reason: because };
  }

  return { id: item.id, smokedAt: item.smokedAt, productType: item.productType, count: item.count, reason: because };
}

export function createLogbook(options: LogbookOptions): Logbook {
  const { api, database } = options;
  const now = options.now ?? (() => new Date());
  const newId = options.newId ?? (() => crypto.randomUUID());
  const exclusive = options.exclusive ?? webLock;

  let running: Promise<SyncOutcome> | null = null;
  // Raised by clear(): the answer to a request sent before must not bring anything back.
  let generation = 0;

  /**
   * Puts the item at the end of the outbox, in place of what was pending for its id. With `known`,
   * the id is marked as possibly known to the server in the same step.
   */
  async function enqueue(item: LogItem, known = false): Promise<void> {
    const transaction = database.transaction(['outbox', 'sent'], 'readwrite');
    const outbox = transaction.objectStore('outbox');
    const existing = await outbox.index('byId').getKey(item.id);
    if (existing !== undefined) {
      await outbox.delete(existing);
    }
    await outbox.add({ id: item.id, item });
    if (known) {
      await transaction.objectStore('sent').put({ id: item.id });
    }
    await transaction.done;
  }

  async function sendBatches(): Promise<SyncOutcome> {
    const started = generation;
    // Only what is pending now: an item logged or corrected during the run is for the next one.
    const last = await database.transaction('outbox').store.openCursor(null, 'prev');
    const upTo = last?.key;
    let accepted = 0;
    const rejected: Rejected[] = [];
    const days = new Map<string, Day>();
    let after = 0;
    const outcome = (status: SyncOutcome['status']): SyncOutcome => ({
      status,
      accepted,
      rejected,
      days: [...days.values()].sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0)),
    });

    while (upTo !== undefined && after < upTo && started === generation) {
      // Read and marked as sent in one step: once the request is in the air, the server may know
      // the log, and remove() must send a deletion for it instead of only forgetting it here. In one
      // transaction, remove() and correct() see the batch either before it was read or after it was
      // marked, never in between.
      const reading = database.transaction(['outbox', 'sent'], 'readwrite');
      const batch = await reading.objectStore('outbox').getAll(IDBKeyRange.bound(after, upTo, true, false), SYNC_BATCH_SIZE);
      const sent = reading.objectStore('sent');
      await Promise.all([...batch.map((record) => sent.put({ id: record.id })), reading.done]);
      if (batch.length === 0) {
        break;
      }
      after = batch[batch.length - 1]?.seq ?? upTo;

      let response: unknown;
      try {
        response = await api.POST('/logs/sync', { body: { items: batch.map((record) => record.item) } });
      } catch (error) {
        if (error instanceof NetworkError) {
          return outcome('offline');
        }
        throw error;
      }
      if (started !== generation) {
        // Cleared while the request was in the air: nothing of it is kept.
        return { status: 'synced', accepted: 0, rejected: [], days: [] };
      }
      if (!isSyncAnswer(response)) {
        // The only success of POST /logs/sync is 200; the body is not what the contract says.
        throw new ApiProblem({ status: 200, type: 'unexpected_response', title: 'Unexpected response' });
      }
      const answer = response;

      const results = new Map(answer.results.map((result) => [result.id, result]));
      const transaction = database.transaction(['outbox', 'rejections'], 'readwrite');
      const outbox = transaction.objectStore('outbox');
      const rejections = transaction.objectStore('rejections');
      const writes: Promise<unknown>[] = [];
      for (const record of batch) {
        const result = results.get(record.id);
        if (result === undefined || record.seq === undefined) {
          // No answer for it: it stays pending for the next sync.
          continue;
        }
        // By its seq: an item that was corrected meanwhile has a new seq and stays pending.
        writes.push(outbox.delete(record.seq));
        if (result.status === 'accepted') {
          accepted += 1;
        } else {
          const rejection = rejectedFrom(record.item, result.reason);
          rejected.push(rejection);
          writes.push(rejections.put(rejection));
        }
      }
      await Promise.all([...writes, transaction.done]);
      for (const day of answer.days) {
        days.set(day.date, day);
      }
    }

    return outcome('synced');
  }

  return {
    async log({ productType, count = 1, smokedAt = now() }) {
      const log = toLog(newId(), { productType, count, smokedAt });
      await enqueue(log);

      return log;
    },

    async correct(id, input) {
      const log = toLog(id, input);
      // A correction may be of a log this device never sent (one from another device, shown from
      // the server): its deletion must then reach the server too.
      await enqueue(log, true);

      return log;
    },

    async remove(id) {
      const transaction = database.transaction(['outbox', 'sent'], 'readwrite');
      const outbox = transaction.objectStore('outbox');
      const pending = await outbox.index('byId').getKey(id);
      if (pending !== undefined && (await outbox.get(pending))?.item.deleted === true) {
        // Already a deletion on the way (a double tap): it stays as it is.
        await transaction.done;

        return;
      }
      const sent = (await transaction.objectStore('sent').getKey(id)) !== undefined;
      if (pending !== undefined) {
        await outbox.delete(pending);
      }
      if (sent || pending === undefined) {
        await outbox.add({ id, item: { id, deleted: true } });
      }
      await transaction.done;
    },

    pending: () => database.count('outbox'),

    sync() {
      if (running === null) {
        const current: Promise<SyncOutcome> = exclusive(sendBatches).finally(() => {
          if (running === current) {
            running = null;
          }
        });
        running = current;
      }

      return running;
    },

    rejections: () => database.getAll('rejections'),

    dismissRejection: (id) => database.delete('rejections', id),

    async clear() {
      generation += 1;
      const transaction = database.transaction(['outbox', 'sent', 'rejections'], 'readwrite');
      await Promise.all([
        transaction.objectStore('outbox').clear(),
        transaction.objectStore('sent').clear(),
        transaction.objectStore('rejections').clear(),
        transaction.done,
      ]);
    },
  };
}
