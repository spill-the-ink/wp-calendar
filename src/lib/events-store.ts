import { EventCache, DEFAULT_STALE_MS } from "./event-cache.ts";
import { EventFetcher } from "./event-fetcher.ts";
import type { FetchResult } from "./events-types.ts";
import { toMonthKey } from "./date-utils.ts";
import type { DataStatus, MonthKey, StoreCallback } from "./events-types.ts";
import { FetchAbortError } from "./events-types.ts";
import type { CalendarEventRecord, CalendarRange } from "../types.ts";

/**
 * A function that produces everything needed to fetch a month of events.
 * The React hook provides this (it owns config + runtime).  The store calls
 * it lazily when a fetch is actually needed, so the URL reflects the latest
 * config even if the subscriber's closure captured a stale range.
 */
export type MonthFetcher = () => { url: string; nonce?: string };

/**
 * Minimal HTTP interface the store depends on.  Keeps the store decoupled from
 * the concrete EventFetcher so it can be unit-tested with a stub and the real
 * implementation stays swappable.
 */
export interface FetchService {
  fetch(
    key: string,
    url: string,
    options: { nonce?: string; signal?: AbortSignal },
  ): Promise<FetchResult>;
  cancel(key: string): void;
  cancelAll(): void;
}

/**
 * Orchestrator between React, the in-memory cache, and the HTTP layer.
 *
 * The store does NOT own fetch scheduling, debounce timers, or the
 * AbortController lifecycle (those live in the React hook and the
 * EventFetcher).  It only:
 *
 *   1. Answers whether a month's data is in the cache and how fresh it is.
 *   2. Delegates fetches to the EventFetcher for cache misses / stale refreshes.
 *   3. Notifies subscribers when a month's data changes (fetch done / refresh).
 *
 * No React dependency, no timing policy, no global debounce.  It is a plain,
 * testable data layer.
 */
export class EventsStoreImpl {
  private cache: EventCache;
  private fetcher: FetchService;
  private staleMs: number;
  private subscribers = new Map<string, Set<StoreCallback>>();
  private inflight = new Map<string, Promise<unknown>>();

  constructor(cache?: EventCache, fetcher?: FetchService, options: { staleMs?: number } = {}) {
    this.cache = cache ?? new EventCache();
    this.fetcher = fetcher ?? new EventFetcher();
    this.staleMs = options.staleMs ?? DEFAULT_STALE_MS;
  }

  /**
   * Subscribes to a month bucket.  Invokes `callback` immediately with the
   * current state and again whenever that month's data changes.
   *
   *   - Fresh cache        → callback(events, "ready"), no fetch.
   *   - Stale cache        → callback(events, "stale"), background refresh.
   *   - Cache miss         → callback([], "loading"), foreground fetch.
   *
   * On a foreground failure the callback fires with "error".  A background
   * refresh failure keeps the stale data (silent).
   *
   * Returns an unsubscribe function.  When `signal` fires, the subscription
   * is removed automatically.
   */
  subscribe(
    monthKey: MonthKey,
    discriminator: string,
    makeFetcher: MonthFetcher,
    callback: StoreCallback,
    window: { signal?: AbortSignal } = {},
  ): () => void {
    const key = this.keyFor(monthKey, discriminator);
    const callbacks = this.subscribers.get(key) ?? new Set<StoreCallback>();
    callbacks.add(callback);
    this.subscribers.set(key, callbacks);

    const entry = this.cache.get(monthKey, discriminator);

    if (entry && this.cache.isFresh(monthKey, discriminator, this.staleMs)) {
      callback(entry.events, "fresh");
    } else if (entry && this.cache.isStale(monthKey, discriminator, this.staleMs)) {
      callback(entry.events, "stale");
      void this.ensureFetched(key, monthKey, discriminator, makeFetcher, { foreground: false });
    } else {
      callback([], "loading");
      void this.ensureFetched(key, monthKey, discriminator, makeFetcher, { foreground: true });
    }

    if (window.signal) {
      window.signal.addEventListener("abort", () => this.unsubscribe(key, callback), {
        once: true,
      });
    }

    return () => this.unsubscribe(key, callback);
  }

  /**
   * Warms the cache for a month without notifying any subscriber.  No-op when
   * the month is already cached (fresh or stale).  Used for prefetching.
   */
  prefetch(monthKey: MonthKey, discriminator: string, makeFetcher: MonthFetcher): void {
    if (this.cache.get(monthKey, discriminator)) {
      return;
    }
    const key = this.keyFor(monthKey, discriminator);
    void this.ensureFetched(key, monthKey, discriminator, makeFetcher, { foreground: false });
  }

  /**
   * Drops cached entries overlapping a range after a save, so a later fetch
   * reflects the change.
   */
  invalidate(range: CalendarRange, discriminator?: string): void {
    for (const key of this.keysMatching(range, discriminator)) {
      const callbacks = this.subscribers.get(key);
      callbacks?.clear();
      this.subscribers.delete(key);
      this.fetcher.cancel(key);
      this.cache.invalidate(range, discriminator);
    }
  }

  /**
   * Clears all cached data, cancels in-flight fetches, and drops subscribers.
   */
  clear(): void {
    this.cache.clear();
    this.fetcher.cancelAll();
    this.subscribers.clear();
    this.inflight.clear();
  }

  private keyFor(monthKey: MonthKey, discriminator: string): string {
    return discriminator ? `${discriminator}\u0000${monthKey}` : monthKey;
  }

  private monthKeyFromKey(key: string): MonthKey {
    const parts = key.split("\u0000");
    return parts[parts.length - 1]!;
  }

  private keysMatching(range: CalendarRange, discriminator?: string): string[] {
    const rangeStart = range.start;
    const rangeEnd = range.end;
    const out: string[] = [];

    for (const key of this.subscribers.keys()) {
      const monthKey = this.monthKeyFromKey(key);
      if (discriminator && !key.startsWith(`${discriminator}\u0000`)) continue;

      const [y, m] = monthKey.split("-").map(Number);
      const monthStart = new Date(y, m - 1, 1);
      const monthEnd = new Date(y, m, 0, 23, 59, 59, 999);

      if (monthEnd >= rangeStart && monthStart <= rangeEnd) {
        out.push(key);
      }
    }

    return out;
  }

  private ensureFetched(
    key: string,
    monthKey: MonthKey,
    discriminator: string,
    makeFetcher: MonthFetcher,
    options: { foreground: boolean },
  ): Promise<unknown> {
    const existing = this.inflight.get(key);
    if (existing) {
      return existing;
    }

    const promise = (async () => {
      const request = makeFetcher();

      try {
        const result = await this.fetcher.fetch(key, request.url, { nonce: request.nonce });
        this.cache.set(monthKey, discriminator, result.events);
        this.notifyKey(key, result.events, "ready");
      } catch (error) {
        if (error instanceof FetchAbortError) {
          // Cancelled navigation — a non-event. Never cache or notify.
          return;
        }
        if (options.foreground) {
          this.notifyKey(key, [], "error");
        }
        // Background failure: keep whatever stale data exists; silent.
      }
    })();

    this.inflight.set(key, promise);
    void promise.finally(() => {
      if (this.inflight.get(key) === promise) {
        this.inflight.delete(key);
      }
    });

    return promise;
  }

  private notifyKey(key: string, events: CalendarEventRecord[], status: DataStatus): void {
    const callbacks = this.subscribers.get(key);
    if (!callbacks) {
      return;
    }
    for (const callback of callbacks) {
      callback(events, status);
    }
  }

  private unsubscribe(key: string, callback: StoreCallback): void {
    const callbacks = this.subscribers.get(key);
    if (!callbacks) {
      return;
    }
    callbacks.delete(callback);
    if (callbacks.size === 0) {
      this.subscribers.delete(key);
      this.fetcher.cancel(key);
      // Drop the store-level inflight marker so a re-subscribe (e.g. React
      // StrictMode's mount/cleanup cycle) starts a fresh fetch instead of being
      // handed the promise of the just-cancelled request.
      this.inflight.delete(key);
    }
  }
}

/**
 * Derives the month bucket key for a range.
 */
export function rangeMonthKey(range: CalendarRange): MonthKey {
  return toMonthKey(range.start);
}

let eventsStore: EventsStoreImpl | null = null;

/**
 * Returns the process-wide singleton store.  Created lazily.
 */
export function getEventsStore(): EventsStoreImpl {
  eventsStore ??= new EventsStoreImpl();
  return eventsStore;
}

/**
 * Resets the singleton (used by tests / hot-reload boundaries).
 */
export function resetEventsStore(): void {
  eventsStore = null;
}

export type EventsStore = EventsStoreImpl;
export { DEFAULT_STALE_MS };
