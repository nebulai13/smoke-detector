import { createFinalURL, createQuerySerializer, defaultPathSerializer } from 'openapi-fetch';
import type { ParamsOption, RequestBodyOption } from 'openapi-fetch';

import type { paths } from '@/api/schema';
import { ApiProblem, DEFAULT_TIMEOUT_MS, problemFromResponse, send } from '@/core/api/errors';
import { defaultBaseUrl } from '@/core/session/session';
import type { Session } from '@/core/session/session';

// The typed access to the API (HLD §6). Path, parameters and body are checked against the contract
// (src/api/schema.d.ts, generated from api/openapi.yaml) like openapi-fetch does, but a call answers
// with the body of the success itself and throws ApiProblem or NetworkError for everything else.

type HttpMethod = 'get' | 'put' | 'post' | 'delete' | 'patch';

/** The operation behind a path and a method, `never` when the path has no such method. */
type Operation<P extends keyof paths, M extends HttpMethod> = NonNullable<paths[P][M]>;

/** The paths that have the method. */
export type PathsWith<M extends HttpMethod> = { [P in keyof paths]: [Operation<P, M>] extends [never] ? never : P }[keyof paths];

type SuccessStatus = 200 | 201 | 202 | 204;

/** The JSON body of the success answers of an operation; `undefined` for an answer without a body (204). */
type SuccessBody<O> = O extends { responses: infer R }
  ? {
      [S in keyof R & SuccessStatus]: R[S] extends { content: { 'application/json': infer B } } ? B : undefined;
    }[keyof R & SuccessStatus]
  : never;

type RequiredKeysOf<T> = { [K in keyof T]-?: Record<never, never> extends Pick<T, K> ? never : K }[keyof T];

/** `{ params: { path, query }, body }` of an operation, each one required when the contract requires it. */
export type RequestInitOf<O> = ParamsOption<O> & RequestBodyOption<O>;

type InitArgs<O> = [RequiredKeysOf<RequestInitOf<O>>] extends [never] ? [init?: RequestInitOf<O>] : [init: RequestInitOf<O>];

type ApiMethod<M extends HttpMethod> = <P extends PathsWith<M>>(path: P, ...init: InitArgs<Operation<P, M>>) => Promise<SuccessBody<Operation<P, M>>>;

export interface Api {
  GET: ApiMethod<'get'>;
  POST: ApiMethod<'post'>;
  PUT: ApiMethod<'put'>;
  PATCH: ApiMethod<'patch'>;
  DELETE: ApiMethod<'delete'>;
}

export interface ApiOptions {
  /** The API root, e.g. `https://example.org/api/v1`. Default: `/api/v1` on the origin of the page. */
  baseUrl?: string;
  session: Session;
  fetch?: typeof fetch;
  /** How long a request may wait for its answer; then it fails as NetworkError. Default 30 s. */
  timeoutMs?: number;
}

/** What every typed call comes down to, without the types of the contract. */
interface RawInit {
  params?: { path?: Record<string, unknown>; query?: Record<string, unknown> };
  body?: unknown;
}

/**
 * A path parameter that is empty, `.` or `..` would lead the request to another path once the URL is
 * resolved (`/days/../me`): refused before anything is sent.
 */
function checkPathParameters(init: RawInit | undefined): void {
  for (const [name, value] of Object.entries(init?.params?.path ?? {})) {
    if (value === '' || value === '.' || value === '..') {
      throw new RangeError(`The path parameter "${name}" must not be empty, "." or "..".`);
    }
  }
}

export function createApi(options: ApiOptions): Api {
  const baseUrl = (options.baseUrl ?? defaultBaseUrl()).replace(/\/+$/, '');
  const { session } = options;
  const fetchFn: typeof fetch = (input, init) => (options.fetch ?? globalThis.fetch)(input, init);
  const querySerializer = createQuerySerializer();
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;

  function sendOnce(method: string, path: string, init: RawInit | undefined, token: string | null): Promise<Response> {
    const url = createFinalURL(path, {
      baseUrl,
      params: init?.params ?? {},
      querySerializer,
      pathSerializer: defaultPathSerializer,
    });
    const headers = new Headers({ Accept: 'application/json, application/problem+json' });
    if (token !== null) {
      headers.set('Authorization', `Bearer ${token}`);
    }
    const requestInit: RequestInit = { method, headers, credentials: 'same-origin' };
    if (init?.body !== undefined) {
      headers.set('Content-Type', 'application/json');
      requestInit.body = JSON.stringify(init.body);
    }

    return send(fetchFn, new Request(url, requestInit), timeoutMs);
  }

  async function successBody(response: Response): Promise<unknown> {
    const text = await response.text();
    if (response.status === 204 || text === '') {
      return undefined;
    }
    try {
      return JSON.parse(text);
    } catch {
      throw new ApiProblem({ status: response.status, type: 'unexpected_response', title: 'Unexpected response' });
    }
  }

  async function call(method: string, path: string, init: RawInit | undefined): Promise<unknown> {
    checkPathParameters(init);
    const generationUsed = session.generation();
    const tokenUsed = session.accessToken();
    const response = await sendOnce(method, path, init, tokenUsed);
    if (response.ok) {
      return successBody(response);
    }
    const problem = await problemFromResponse(response);
    // Only an expired or unknown access token is renewed: a wrong password (`invalid_credentials`)
    // is a 401 as well, and a new token would not change it. And only for a request that was sent
    // with a token of the session that is still signed in: after a log out (or a log in of someone
    // else) a renewal would act on behalf of a session that is gone.
    if (problem.status !== 401 || problem.type !== 'unauthorized' || tokenUsed === null || session.generation() !== generationUsed) {
      throw problem;
    }
    // Another request may have renewed the token while this one was in the air.
    const current = session.accessToken();
    const renewed = current !== null && current !== tokenUsed ? true : await session.refresh();
    const renewedToken = session.accessToken();
    if (!renewed || renewedToken === null || session.generation() !== generationUsed) {
      throw problem;
    }
    const again = await sendOnce(method, path, init, renewedToken);
    if (again.ok) {
      return successBody(again);
    }

    // Refused again, also with 401: there is no second renewal.
    throw await problemFromResponse(again);
  }

  // The one place where the typed signature meets the untyped implementation: the compiler cannot
  // follow a generic path through to the shape of its answer, so the method is typed from outside.
  const method = <M extends HttpMethod>(name: Uppercase<M>): ApiMethod<M> =>
    ((path: string, init?: RawInit) => call(name, path, init)) as ApiMethod<M>;

  return {
    GET: method<'get'>('GET'),
    POST: method<'post'>('POST'),
    PUT: method<'put'>('PUT'),
    PATCH: method<'patch'>('PATCH'),
    DELETE: method<'delete'>('DELETE'),
  };
}
