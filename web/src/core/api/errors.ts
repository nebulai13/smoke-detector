import type { components } from '@/api/schema';

export type FieldError = components['schemas']['FieldError'];

/** What an ApiProblem is made of: the problem document of the server (RFC 9457). */
export interface ProblemDetails {
  status: number;
  type: string;
  title: string;
  detail?: string;
  errors?: { field: string; code: string; message: string }[];
  retryAfterSeconds?: number;
}

/**
 * An answer of the server that is not a success. Decide by `type` (a stable slug of the contract),
 * never by `title` or `detail` (English, for developers). An error answer that is no problem document
 * (HTML of a proxy, for example) has the type `unexpected_response`.
 */
export class ApiProblem extends Error {
  readonly status: number;
  readonly type: string;
  readonly title: string;
  readonly detail?: string;
  readonly errors?: { field: string; code: string; message: string }[];
  /** From the `Retry-After` header, when it is a whole number of seconds. */
  readonly retryAfterSeconds?: number;

  constructor(problem: ProblemDetails) {
    super(`${String(problem.status)} ${problem.type}`);
    this.name = 'ApiProblem';
    this.status = problem.status;
    this.type = problem.type;
    this.title = problem.title;
    if (problem.detail !== undefined) {
      this.detail = problem.detail;
    }
    if (problem.errors !== undefined) {
      this.errors = problem.errors;
    }
    if (problem.retryAfterSeconds !== undefined) {
      this.retryAfterSeconds = problem.retryAfterSeconds;
    }
  }
}

/** A request that got no answer at all: no connection, DNS failure, connection reset. */
export class NetworkError extends Error {
  constructor(options?: { cause?: unknown }) {
    super('The request got no answer.', options);
    this.name = 'NetworkError';
  }
}

const isRecord = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);

const isFieldError = (value: unknown): value is { field: string; code: string; message: string } =>
  isRecord(value) && typeof value.field === 'string' && typeof value.code === 'string' && typeof value.message === 'string';

function retryAfter(response: Response): number | undefined {
  const header = response.headers.get('Retry-After')?.trim();

  return header !== undefined && /^\d+$/.test(header) ? Number(header) : undefined;
}

/** Turns an error answer into an ApiProblem. Reads the body, which must not have been read yet. */
export async function problemFromResponse(response: Response): Promise<ApiProblem> {
  const retryAfterSeconds = retryAfter(response);
  const optional = retryAfterSeconds === undefined ? {} : { retryAfterSeconds };
  let body: unknown;
  try {
    body = JSON.parse(await response.text());
  } catch {
    body = undefined;
  }

  if (isRecord(body) && typeof body.type === 'string' && typeof body.status === 'number') {
    const problem: ProblemDetails = {
      status: response.status,
      type: body.type,
      title: typeof body.title === 'string' ? body.title : body.type,
      ...optional,
    };
    if (typeof body.detail === 'string') {
      problem.detail = body.detail;
    }
    if (Array.isArray(body.errors)) {
      problem.errors = body.errors.filter(isFieldError).map(({ field, code, message }) => ({ field, code, message }));
    }

    return new ApiProblem(problem);
  }

  return new ApiProblem({ status: response.status, type: 'unexpected_response', title: 'Unexpected response', ...optional });
}

/** How long a request may wait for its answer before it is given up (NFR-03). */
export const DEFAULT_TIMEOUT_MS = 30_000;

/**
 * fetch, with a rejected request turned into a NetworkError (one aborted by its caller stays an
 * AbortError). A request without an answer after `timeoutMs` is aborted and fails as NetworkError:
 * a hanging request must not hold a sync (and its lock) forever.
 */
export async function send(fetchFn: typeof fetch, input: Request, timeoutMs: number = DEFAULT_TIMEOUT_MS): Promise<Response> {
  const controller = new AbortController();
  let timedOut = false;
  let timer: ReturnType<typeof setTimeout> | undefined;
  const onCallerAbort = (): void => {
    controller.abort(input.signal.reason);
  };
  input.signal.addEventListener('abort', onCallerAbort, { once: true });
  // Raced as well as aborted: a fetch that does not honour the signal is given up all the same.
  const timeout = new Promise<never>((_resolve, reject) => {
    timer = setTimeout(() => {
      timedOut = true;
      controller.abort();
      reject(new NetworkError({ cause: new DOMException('The request got no answer in time.', 'TimeoutError') }));
    }, timeoutMs);
  });
  try {
    return await Promise.race([fetchFn(input, { signal: controller.signal }), timeout]);
  } catch (error) {
    if (timedOut) {
      throw error instanceof NetworkError ? error : new NetworkError({ cause: error });
    }
    if (error instanceof DOMException && error.name === 'AbortError') {
      throw error;
    }
    throw new NetworkError({ cause: error });
  } finally {
    clearTimeout(timer);
    input.signal.removeEventListener('abort', onCallerAbort);
  }
}
