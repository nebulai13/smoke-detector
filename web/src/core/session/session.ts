import type { components } from '@/api/schema';
import { ApiProblem, DEFAULT_TIMEOUT_MS, problemFromResponse, send } from '@/core/api/errors';

export type SessionStatus = 'unknown' | 'signedIn' | 'signedOut';

export interface LogInRequest {
  email: string;
  password: string;
  deviceLabel?: string;
}

export interface Session {
  /** `unknown` until `restore()`, `logIn()` or `refresh()` found out. */
  status(): SessionStatus;
  /** The access token, held in this variable only (HLD §6 "Token storage"). */
  accessToken(): string | null;
  /**
   * Asks for a new token with the refresh cookie. Rejects with NetworkError without a connection
   * (or without an answer in time). A logout that is still owed to the server is delivered first, and then the answer is `signedOut`
   * without asking for a token (also when it still cannot be delivered).
   */
  restore(): Promise<'signedIn' | 'signedOut'>;
  /** Throws the problem of the server as ApiProblem, a missing connection as NetworkError. */
  logIn(request: LogInRequest): Promise<void>;
  /**
   * `true` with a new token, `false` when the server refuses (then signed out). Overlapping calls share
   * one request. While a logout is owed to the server: `false` (signed out) without asking at all.
   */
  refresh(): Promise<boolean>;
  /**
   * Forgets the token whatever the server says. Never rejects. Unless the server took the logout (a
   * 2xx answer), the device remembers (with a flag that holds nothing personal) that it is still owed
   * to the server; the other tabs of the app see the flag and sign out too.
   */
  logOut(): Promise<void>;
  /**
   * Changes with every log in and log out. A request sent while it had another value belongs to
   * another session: its answer must not renew the token of this one.
   */
  generation(): number;
  /** Called when the status changes. Returns the function that unsubscribes. */
  onChange(listener: (status: SessionStatus) => void): () => void;
}

export interface SessionOptions {
  /** The API root, e.g. `https://example.org/api/v1` (no trailing slash). */
  baseUrl?: string;
  fetch?: typeof fetch;
  /** How long a request may wait for its answer; then it fails as NetworkError. Default 30 s. */
  timeoutMs?: number;
}

type SessionBody = components['schemas']['Session'];
type LoginBody = components['schemas']['LoginRequest'];

/** The API on the origin of the page: `/api/v1` (HLD AD-9, same origin in production and dev). */
export function defaultBaseUrl(): string {
  return `${window.location.origin}/api/v1`;
}

/** The flag of a logout the server has not received yet. Holds no token and nothing personal. */
export const LOGOUT_OWED_KEY = 'smoke-detector.logout-owed';

// Storage may be missing or refuse (private windows, blocked site data): the flag is then not kept,
// which is no worse than before it existed.
function logoutOwed(): boolean {
  try {
    return localStorage.getItem(LOGOUT_OWED_KEY) !== null;
  } catch {
    return false;
  }
}

function setLogoutOwed(owed: boolean): void {
  try {
    if (owed) {
      localStorage.setItem(LOGOUT_OWED_KEY, '1');
    } else {
      localStorage.removeItem(LOGOUT_OWED_KEY);
    }
  } catch {
    // See logoutOwed().
  }
}

const isSessionBody = (value: unknown): value is SessionBody =>
  typeof value === 'object' && value !== null && typeof (value as { accessToken?: unknown }).accessToken === 'string';

export function createSession(options: SessionOptions = {}): Session {
  const baseUrl = (options.baseUrl ?? defaultBaseUrl()).replace(/\/+$/, '');
  const fetchFn: typeof fetch = (input, init) => (options.fetch ?? globalThis.fetch)(input, init);
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;

  let status: SessionStatus = 'unknown';
  let token: string | null = null;
  // Raised by every log in and log out: an answer to a request made before belongs to another
  // session and must not change this one (a refresh that returns after a log out, for example).
  let generation = 0;
  let refreshing: Promise<boolean> | null = null;
  const listeners = new Set<(status: SessionStatus) => void>();

  function setState(nextStatus: SessionStatus, nextToken: string | null): void {
    token = nextToken;
    if (nextStatus !== status) {
      status = nextStatus;
      for (const listener of [...listeners]) {
        listener(nextStatus);
      }
    }
  }

  /** Forgets the token on this device only: what a log out does here, without telling the server. */
  function forgetHere(): void {
    if (token !== null || status === 'signedIn') {
      // Like a log out: an answer to a request of the session before must not renew it.
      generation += 1;
    }
    refreshing = null;
    setState('signedOut', null);
  }

  // Another tab logged out and could not deliver it: the refresh cookie is shared by all tabs, so
  // this tab is signed out as well. (The browser fires `storage` in the other tabs only.)
  if (typeof window !== 'undefined') {
    window.addEventListener('storage', (event) => {
      if (event.key === LOGOUT_OWED_KEY && event.newValue !== null) {
        forgetHere();
      }
    });
  }

  async function post(path: string, body?: LoginBody): Promise<Response> {
    const init: RequestInit = { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json, application/problem+json' } };
    if (body !== undefined) {
      init.headers = { ...init.headers, 'Content-Type': 'application/json' };
      init.body = JSON.stringify(body);
    }

    return send(fetchFn, new Request(`${baseUrl}${path}`, init), timeoutMs);
  }

  async function readSession(response: Response): Promise<SessionBody> {
    const body: unknown = await response.json().catch(() => undefined);
    if (!isSessionBody(body)) {
      throw new ApiProblem({ status: response.status, type: 'unexpected_response', title: 'Unexpected response' });
    }

    return body;
  }

  /** Throws NetworkError, and ApiProblem for an answer that is neither a session nor a refusal. */
  async function requestRefresh(): Promise<boolean> {
    const started = generation;
    const response = await post('/auth/refresh');
    if (response.ok) {
      const body = await readSession(response);
      if (started === generation) {
        setState('signedIn', body.accessToken);
      }

      return started === generation;
    }
    if (response.status === 400 || response.status === 401 || response.status === 422) {
      // The cookie is missing, unknown, expired or malformed: nobody is signed in on this device.
      await response.body?.cancel();
      if (started === generation) {
        setState('signedOut', null);
      }

      return false;
    }

    // 5xx and the like: the server could not answer, which says nothing about the cookie.
    throw await problemFromResponse(response);
  }

  const session: Session = {
    status: () => status,
    accessToken: () => token,

    async restore() {
      if (logoutOwed()) {
        // The refresh cookie may still be good on the server: a refresh would sign the user in again.
        const started = generation;
        try {
          const response = await post('/auth/logout');
          await response.body?.cancel();
          // Delivered only when the server took it; a 429, a 5xx or the error page of a proxy did not
          // end the session, and it stays owed for the next start.
          if (response.ok && started === generation) {
            setLogoutOwed(false);
          }
        } catch {
          // Still no connection (or no answer in time): it stays owed for the next start.
        }
        if (started !== generation) {
          // Logged in meanwhile: that session is the one that counts.
          return status === 'signedIn' ? 'signedIn' : 'signedOut';
        }
        setState('signedOut', null);

        return 'signedOut';
      }

      return (await session.refresh()) ? 'signedIn' : 'signedOut';
    },

    async logIn({ email, password, deviceLabel }) {
      const body: LoginBody = { email, password, ...(deviceLabel === undefined ? {} : { deviceLabel }), refreshIn: 'cookie' };
      const response = await post('/auth/login', body);
      if (!response.ok) {
        throw await problemFromResponse(response);
      }
      const answer = await readSession(response);
      generation += 1;
      refreshing = null;
      // The cookie of this login has replaced the one an owed logout was meant for.
      setLogoutOwed(false);
      setState('signedIn', answer.accessToken);
    },

    refresh() {
      if (logoutOwed()) {
        // The cookie may still be good on the server: renewing it would undo the logout.
        forgetHere();

        return Promise.resolve(false);
      }
      if (refreshing === null) {
        const current: Promise<boolean> = requestRefresh().finally(() => {
          if (refreshing === current) {
            refreshing = null;
          }
        });
        refreshing = current;
      }

      return refreshing;
    },

    async logOut() {
      generation += 1;
      const started = generation;
      refreshing = null;
      setState('signedOut', null);
      let delivered = false;
      try {
        const response = await post('/auth/logout');
        // Only a 2xx is delivery: a 429, a 5xx or the error page of a proxy did not end the session.
        delivered = response.ok;
        await response.body?.cancel();
      } catch {
        // No connection, or no answer in time.
      }
      // This device has forgotten the token all the same; an undelivered logout is sent at the next
      // start. Not when a login came in between: its cookie replaced this one.
      if (started === generation) {
        setLogoutOwed(!delivered);
      }
    },

    generation: () => generation,

    onChange(listener) {
      listeners.add(listener);

      return () => {
        listeners.delete(listener);
      };
    },
  };

  return session;
}
