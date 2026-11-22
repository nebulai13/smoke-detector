// When the outbox is sent without the user doing anything (HLD §6 "Background sync"): at the start,
// when the connection comes back, and when the app comes to the front.

export interface AutoSyncOptions {
  sync: () => Promise<unknown>;
  /** Gets every failed sync. Without it a failure is dropped quietly: the next trigger tries again. */
  onError?: (error: unknown) => void;
}

/** Starts listening. Returns the function that stops it. */
export function startAutoSync({ sync, onError }: AutoSyncOptions): () => void {
  const run = (): void => {
    void (async () => {
      try {
        await sync();
      } catch (error) {
        try {
          onError?.(error);
        } catch {
          // A failing error handler must not become an unhandled rejection either.
        }
      }
    })();
  };
  const onVisibilityChange = (): void => {
    if (document.visibilityState === 'visible') {
      run();
    }
  };

  window.addEventListener('online', run);
  document.addEventListener('visibilitychange', onVisibilityChange);
  run();

  return () => {
    window.removeEventListener('online', run);
    document.removeEventListener('visibilitychange', onVisibilityChange);
  };
}
