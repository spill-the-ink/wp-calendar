import { normalizeEvents } from "./events-api.ts";
import { FetchAbortError } from "./events-types.ts";
import type { FetchResult } from "./events-types.ts";

/**
 * Retry policy for transient failures (network errors and 5xx).
 */
const RETRY_DELAYS_MS = [1_000, 2_000];

/**
 * Retry policy for rate-limit (429) responses.  Uses the Retry-After header
 * when present, falling back to a fixed delay.
 */
const RATE_LIMIT_DEFAULT_DELAY_MS = 2_000;

/**
 * HTTP fetcher for calendar events.
 *
 * Deduplicates concurrent requests for the same month key (two subscribers
 * asking for the same month share one in-flight request).  Owns the
 * AbortController for each key so a caller's signal can cancel an active
 * request safely.  Applies a small retry policy for transient failures and
 * swallows AbortErrors (expected during navigation).
 */
export class EventFetcher {
  private inflight = new Map<string, Promise<FetchResult>>();
  private controllers = new Map<string, AbortController>();

  /**
   * Fetches events for a month.  Resolves with the fetched events and a
   * timestamp.  Concurrent calls for the same key return the same promise.
   *
   * Rejects on non-OK status (after retries for transient failures).  Rejects
   * with `FetchAbortError` when the request is cancelled so callers can tell
   * an abort (a non-event) from a genuine zero-event month.
   */
  async fetch(
    key: string,
    url: string,
    options: { nonce?: string; signal?: AbortSignal },
  ): Promise<FetchResult> {
    const existing = this.inflight.get(key);
    if (existing) {
      return existing;
    }

    const promise = this.runFetch(key, url, options);
    this.inflight.set(key, promise);

    // Clean up the dedup/controller maps when the request settles. The handler
    // is attached via `then` with an explicit rejection arm so an abort does
    // not leave a detached, unhandled rejection.
    promise.then(
      () => {
        if (this.inflight.get(key) === promise) {
          this.inflight.delete(key);
          this.controllers.delete(key);
        }
      },
      () => {
        if (this.inflight.get(key) === promise) {
          this.inflight.delete(key);
          this.controllers.delete(key);
        }
      },
    );

    return promise;
  }

  /**
   * Cancels an in-flight fetch for a key.  Aborts the HTTP request if one is
   * active.  No-op when nothing is in flight.
   */
  cancel(key: string): void {
    const controller = this.controllers.get(key);
    controller?.abort();
    // Eagerly drop the dedup entry so a subscriber that re-subscribes right
    // after a cancel (e.g. React StrictMode mount/cleanup) starts a fresh
    // request instead of being handed the already-cancelled promise.
    this.inflight.delete(key);
  }

  /**
   * Cancels all in-flight fetches.
   */
  cancelAll(): void {
    for (const controller of this.controllers.values()) {
      controller.abort();
    }
  }

  private async runFetch(
    key: string,
    url: string,
    options: { nonce?: string; signal?: AbortSignal },
  ): Promise<FetchResult> {
    const controller = new AbortController();
    this.controllers.set(key, controller);

    const linkAbort = () => controller.abort();
    options.signal?.addEventListener("abort", linkAbort, { once: true });

    try {
      const startedAt = Date.now();
      const events = await this.fetchWithRetry(url, options.nonce, controller.signal);
      return { events, fetchedAt: startedAt };
    } finally {
      options.signal?.removeEventListener("abort", linkAbort);
      this.controllers.delete(key);
    }
  }

  private async fetchWithRetry(
    url: string,
    nonce: string | undefined,
    signal: AbortSignal,
  ): Promise<ReturnType<typeof normalizeEvents>> {
    for (let attempt = 0; ; attempt++) {
      try {
        const response = await this.singleFetch(url, nonce, signal);

        if (response.ok) {
          const payload = (await response.json()) as unknown;
          return normalizeEvents(Array.isArray(payload) ? payload : []);
        }

        if (response.status === 429) {
          const retryAfterMs = parseRetryAfter(response.headers.get("Retry-After"));
          const delay = retryAfterMs ?? RATE_LIMIT_DEFAULT_DELAY_MS;
          await sleep(delay, signal);
          continue;
        }

        if (response.status >= 500) {
          if (attempt < RETRY_DELAYS_MS.length) {
            await sleep(RETRY_DELAYS_MS[attempt], signal);
            continue;
          }
          throw new Error(`Request failed with status ${response.status}`);
        }

        throw new Error(`Request failed with status ${response.status}`);
      } catch (error) {
        if (isAbortError(error)) {
          throw new FetchAbortError();
        }

        const isNetworkError = error instanceof TypeError;
        if (isNetworkError && attempt < RETRY_DELAYS_MS.length) {
          await sleep(RETRY_DELAYS_MS[attempt], signal);
          continue;
        }

        throw error;
      }
    }
  }

  private async singleFetch(
    url: string,
    nonce: string | undefined,
    signal: AbortSignal,
  ): Promise<Response> {
    const headers: Record<string, string> = {};

    if (nonce) {
      headers["X-WP-Nonce"] = nonce;
    }

    return fetch(url, { headers, signal });
  }
}

function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === "AbortError";
}

function parseRetryAfter(value: string | null): number | null {
  if (!value) {
    return null;
  }
  const seconds = Number.parseInt(value, 10);
  return Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : null;
}

function sleep(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(new DOMException("Aborted", "AbortError"));
      return;
    }

    const timer = setTimeout(resolve, ms);
    signal?.addEventListener(
      "abort",
      () => {
        clearTimeout(timer);
        reject(new DOMException("Aborted", "AbortError"));
      },
      { once: true },
    );
  });
}
