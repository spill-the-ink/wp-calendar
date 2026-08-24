import type { CalendarEventRecord, CalendarRange } from "../types.ts";
import { normalizeDay, toMonthKey } from "./date-utils.ts";
import type { CacheEntry, MonthKey } from "./events-types.ts";

/**
 * Default time before a cached entry is considered stale.
 */
export const DEFAULT_STALE_MS = 60_000;

/**
 * Cache key format: `${discriminator}\u0000${monthKey}`.  The discriminator
 * separates different post-type/query-var filter combinations so they never
 * share cached data.
 */
function buildKey(monthKey: MonthKey, discriminator?: string): string {
  return discriminator ? `${discriminator}\u0000${monthKey}` : monthKey;
}

/**
 * Pure in-memory event cache keyed by month bucket + filter discriminator.
 *
 * Stores raw event arrays with a fetch timestamp.  No network, no React, no
 * side effects.  Staleness is determined by comparing `fetchedAt` to the
 * current time.
 *
 * Scroll-bounded: the number of retained entries is capped (by default 40),
 * which comfortably covers 12 months plus a few adjacent prefetched months
 * for several distinct filter combinations.
 */
export class EventCache {
  private entries = new Map<string, CacheEntry>();
  private maxEntries: number;

  constructor(options: { maxEntries?: number } = {}) {
    this.maxEntries = options.maxEntries ?? 40;
  }

  /**
   * Returns cached events for a month, or null if nothing is stored.
   */
  get(monthKey: MonthKey, discriminator?: string): CacheEntry | null {
    return this.entries.get(buildKey(monthKey, discriminator)) ?? null;
  }

  /**
   * Returns true when an entry exists and was fetched within `staleMs`.
   */
  isFresh(monthKey: MonthKey, discriminator?: string, staleMs: number = DEFAULT_STALE_MS): boolean {
    const entry = this.get(monthKey, discriminator);
    return entry !== null && Date.now() - entry.fetchedAt <= staleMs;
  }

  /**
   * Returns true when an entry exists but was fetched longer than `staleMs`
   * ago (or has no timestamp).
   */
  isStale(monthKey: MonthKey, discriminator?: string, staleMs: number = DEFAULT_STALE_MS): boolean {
    const entry = this.get(monthKey, discriminator);
    return entry !== null && (entry.fetchedAt === null || Date.now() - entry.fetchedAt >= staleMs);
  }

  /**
   * Stores events for a month.  Evicts the oldest entry if the cache exceeds
   * its size cap.
   */
  set(monthKey: MonthKey, discriminator: string, events: CalendarEventRecord[]): void {
    const key = buildKey(monthKey, discriminator);
    this.entries.set(key, { events, fetchedAt: Date.now() });

    if (this.entries.size > this.maxEntries) {
      let oldestKey: string | null = null;
      let oldestAt = Number.POSITIVE_INFINITY;

      for (const [candidateKey, entry] of this.entries) {
        if (entry.fetchedAt < oldestAt) {
          oldestAt = entry.fetchedAt;
          oldestKey = candidateKey;
        }
      }

      if (oldestKey !== null) {
        this.entries.delete(oldestKey);
      }
    }
  }

  /**
   * Removes a single month entry.
   */
  delete(monthKey: MonthKey, discriminator?: string): void {
    this.entries.delete(buildKey(monthKey, discriminator));
  }

  /**
   * Removes all entries whose month overlaps the given range.  Used to drop
   * cached data after events are created/edited/deleted so a later fetch
   * reflects the change.
   */
  invalidate(range: CalendarRange, discriminator?: string): void {
    const rangeStart = normalizeDay(range.start);
    const rangeEnd = normalizeDay(range.end);

    for (const [key, entry] of this.entries) {
      if (discriminator && !key.startsWith(`${discriminator}\u0000`)) {
        continue;
      }

      const entryHasEvents = entry.events.length > 0;
      const entryMonth = this.monthFromKey(key);
      const entryStart = entryMonth ? `${entryMonth}-01` : "";
      const entryEnd = entryMonth ? this.endOfMonthKey(entryMonth) : "";

      // Invalidate if the entry's month overlaps the range at day granularity,
      // unless the entry is empty (nothing to invalidate adds no benefit, but
      // dropping it is safe either way — treat empty entries as overlapping).
      if (entryHasEvents && (entryEnd < rangeStart || entryStart > rangeEnd)) {
        continue;
      }

      this.entries.delete(key);
    }
  }

  /**
   * Removes all entries.
   */
  clear(): void {
    this.entries.clear();
  }

  /**
   * Returns the number of retained entries (for tests / debugging).
   */
  get size(): number {
    return this.entries.size;
  }

  /**
   * Extract the month key portion from a full cache key.
   */
  private monthFromKey(key: string): MonthKey | null {
    const parts = key.split("\u0000");
    return parts[parts.length - 1] ?? null;
  }

  /**
   * Returns the last day of a "YYYY-MM" month key as a "YYYY-MM-DD" string.
   */
  private endOfMonthKey(monthKey: MonthKey): string {
    const [y, m] = monthKey.split("-").map(Number);
    const lastDay = new Date(y, m, 0).getDate();
    return `${monthKey}-${String(lastDay).padStart(2, "0")}`;
  }
}

/**
 * Derives the month key for a CalendarRange (the range's start month).
 */
export function rangeMonthKey(range: CalendarRange): MonthKey {
  return toMonthKey(range.start);
}
