import { afterEach, describe, expect, it, vi } from 'vitest';

import { startAutoSync } from '@/core/logging/autosync';

// When the outbox is sent without the user doing anything (HLD §6 "Background sync"): when the
// connection comes back, when the app comes to the front, and when it starts.

const setVisibility = (state: 'visible' | 'hidden') => {
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: state });
  document.dispatchEvent(new Event('visibilitychange'));
};

describe('auto sync', () => {
  afterEach(() => {
    setVisibility('visible');
  });

  it('NFR-03: syncs when it starts, when the connection comes back and when the app comes to the front', async () => {
    const sync = vi.fn(() => Promise.resolve());
    const stop = startAutoSync({ sync });
    await vi.waitFor(() => expect(sync).toHaveBeenCalledTimes(1));

    window.dispatchEvent(new Event('online'));
    await vi.waitFor(() => expect(sync).toHaveBeenCalledTimes(2));

    setVisibility('hidden');
    expect(sync).toHaveBeenCalledTimes(2);
    setVisibility('visible');
    await vi.waitFor(() => expect(sync).toHaveBeenCalledTimes(3));

    stop();
  });

  it('stops listening when it is stopped', async () => {
    const sync = vi.fn(() => Promise.resolve());
    const stop = startAutoSync({ sync });
    await vi.waitFor(() => expect(sync).toHaveBeenCalledTimes(1));

    stop();
    window.dispatchEvent(new Event('online'));
    setVisibility('hidden');
    setVisibility('visible');
    await new Promise((resolve) => setTimeout(resolve, 10));

    expect(sync).toHaveBeenCalledTimes(1);
  });

  it('NFR-03: a sync that fails is reported and does not stop the next one', async () => {
    const failure = new Error('the server is down');
    const sync = vi.fn<() => Promise<void>>().mockRejectedValueOnce(failure).mockResolvedValue(undefined);
    const failures: unknown[] = [];
    const stop = startAutoSync({ sync, onError: (error: unknown) => failures.push(error) });
    await vi.waitFor(() => expect(failures).toEqual([failure]));

    window.dispatchEvent(new Event('online'));
    await vi.waitFor(() => expect(sync).toHaveBeenCalledTimes(2));

    expect(failures).toEqual([failure]);
    stop();
  });
});
