import type { CalendarEventRecord, CalendarRange } from "../types.ts";
import type { MonthKey } from "./events-types.ts";

/**
 * Returns "YYYY-MM" for a Date, suitable for use as a cache key.
 */
export function toMonthKey(date: Date): MonthKey {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  return `${y}-${m}`;
}

/**
 * Returns start-of-month (midnight) for any Date.
 */
export function monthStart(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1);
}

/**
 * Returns end-of-month (23:59:59.999) for any Date.
 */
export function monthEnd(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth() + 1, 0, 23, 59, 59, 999);
}

/**
 * Returns a month-aligned CalendarRange covering the month of the given Date.
 */
export function monthRange(date: Date): CalendarRange {
  return { start: monthStart(date), end: monthEnd(date) };
}

/**
 * Returns month-aligned ranges for N consecutive months centered on the given
 * Date's month.  `count` is the total number of months returned.
 *
 *   adjacentMonths(new Date(2026, 8, 15), 3)
 *   → [{ start: Aug 1, end: Aug 31 }, { start: Sep 1, end: Sep 30 }, { start: Oct 1, end: Oct 31 }]
 */
export function adjacentMonths(date: Date, count: number): CalendarRange[] {
  const ranges: CalendarRange[] = [];
  const startMonth = new Date(date.getFullYear(), date.getMonth() - Math.floor(count / 2), 1);

  for (let i = 0; i < count; i++) {
    const d = new Date(startMonth.getFullYear(), startMonth.getMonth() + i, 1);
    ranges.push({ start: monthStart(d), end: monthEnd(d) });
  }

  return ranges;
}

/**
 * Returns month-aligned ranges for every month within a CalendarRange.
 * Used by the year view to split a 12-month span into individual fetches.
 */
export function monthsInRange(range: CalendarRange): CalendarRange[] {
  const ranges: CalendarRange[] = [];
  const cursor = monthStart(range.start);
  const end = monthStart(range.end);

  while (cursor <= end) {
    ranges.push({ start: monthStart(cursor), end: monthEnd(cursor) });
    cursor.setMonth(cursor.getMonth() + 1);
  }

  return ranges;
}

/**
 * Day-granularity normalisation for cache keys.  Produces "YYYY-MM-DD".
 */
export function normalizeDay(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

/**
 * Filters events to those overlapping a date range.  Events without a start
 * time are dropped; a null bound is treated as unbounded on that side.
 */
export function filterEventsByRange(
  events: CalendarEventRecord[],
  range: CalendarRange,
): CalendarEventRecord[] {
  const rangeStart = normalizeDay(range.start);
  const rangeEnd = normalizeDay(range.end);

  return events.filter((event) => {
    const start = event.scheduled_start_time;
    if (!(start instanceof Date) || Number.isNaN(start.getTime())) {
      return false;
    }

    const eventStart = normalizeDay(start);
    const eventEnd =
      event.scheduled_end_time instanceof Date && !Number.isNaN(event.scheduled_end_time.getTime())
        ? normalizeDay(event.scheduled_end_time)
        : eventStart;

    return eventEnd >= rangeStart && eventStart <= rangeEnd;
  });
}
