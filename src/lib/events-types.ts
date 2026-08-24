import type { CalendarEventRecord } from "../types.ts";

/**
 * Month-bucket cache key in "YYYY-MM" format.
 */
export type MonthKey = string;

/**
 * Immutable request parameters for a single fetch. The EventFetcher owns
 * the AbortController; callers pass their signal for cancellation.
 */
export interface EventFetchRequest {
  url: string;
  nonce?: string;
  signal?: AbortSignal;
}

/**
 * Result of a successful fetch operation.
 */
export interface FetchResult {
  events: CalendarEventRecord[];
  fetchedAt: number;
}

/**
 * A cached month entry with metadata.
 */
export interface CacheEntry {
  events: CalendarEventRecord[];
  fetchedAt: number;
}

/**
 * Lifecycle status of a subscription's data.
 *
 * - "ready":   cache holds data (fresh or stale) — usable immediately.
 * - "loading": no cached data, fetch in progress.
 * - "error":   fetch failed, no usable data.
 *
 * The initial notification also reports whether the served data is "stale"
 * (holding old data while a background refresh runs) or "fresh" (data within
 * TTL, no refresh needed).  Both are treated by consumers as "ready".
 */
export type DataStatus = "ready" | "loading" | "error" | "stale" | "fresh";

/**
 * Callback signature for store subscriptions. Receives the current events
 * array and a status indicator so the React hook can derive UI state.
 */
export type StoreCallback = (events: CalendarEventRecord[], status: DataStatus) => void;

/**
 * Error thrown by the EventFetcher when an in-flight request is cancelled.
 * Callers (the store) catch this and treat it as a non-result — never caching
 * or notifying subscribers — rather than surfacing it as "zero events".
 */
export class FetchAbortError extends Error {
  constructor() {
    super("Fetch aborted");
    this.name = "FetchAbortError";
  }
}
