import { describe, expect, it } from "vitest";
import {
  adjacentMonths,
  filterEventsByRange,
  monthRange,
  monthsInRange,
  normalizeDay,
  toMonthKey,
} from "../date-utils.ts";
import type { CalendarEventRecord } from "../../types.ts";

function event(start: string, end?: string): CalendarEventRecord {
  return {
    name: "ev",
    scheduled_start_time: new Date(start),
    scheduled_end_time: new Date(end ?? start),
    allDay: false,
    tags: [],
    label: null,
    source: null,
    location: null,
    location_url: null,
  };
}

describe("toMonthKey", () => {
  it("formats YYYY-MM", () => {
    expect(toMonthKey(new Date(2026, 8, 15))).toBe("2026-09");
    expect(toMonthKey(new Date(2026, 0, 3))).toBe("2026-01");
    expect(toMonthKey(new Date(2026, 11, 31))).toBe("2026-12");
  });
});

describe("normalizeDay", () => {
  it("formats YYYY-MM-DD", () => {
    expect(normalizeDay(new Date(2026, 8, 5))).toBe("2026-09-05");
  });
});

describe("monthRange", () => {
  it("covers the full month of a date", () => {
    const r = monthRange(new Date(2026, 8, 15));
    expect(r.start).toEqual(new Date(2026, 8, 1));
    expect(r.end).toEqual(new Date(2026, 8, 30, 23, 59, 59, 999));
  });
});

describe("adjacentMonths", () => {
  it("returns N consecutive months centered on the date", () => {
    const ranges = adjacentMonths(new Date(2026, 8, 15), 3);
    expect(ranges).toHaveLength(3);
    expect(toMonthKey(ranges[0]!.start)).toBe("2026-08");
    expect(toMonthKey(ranges[1]!.start)).toBe("2026-09");
    expect(toMonthKey(ranges[2]!.start)).toBe("2026-10");
  });
});

describe("monthsInRange", () => {
  it("splits a single month into one range", () => {
    const ranges = monthsInRange(monthRange(new Date(2026, 8, 15)));
    expect(ranges).toHaveLength(1);
    expect(toMonthKey(ranges[0]!.start)).toBe("2026-09");
  });

  it("splits a year into 12 ranges", () => {
    const ranges = monthsInRange({
      start: new Date(2026, 0, 1),
      end: new Date(2026, 11, 31),
    });
    expect(ranges).toHaveLength(12);
    expect(toMonthKey(ranges[0]!.start)).toBe("2026-01");
    expect(toMonthKey(ranges[11]!.start)).toBe("2026-12");
  });
});

describe("filterEventsByRange", () => {
  const sep = {
    start: new Date(2026, 8, 1),
    end: new Date(2026, 8, 30, 23, 59, 59, 999),
  };

  it("keeps events inside the range", () => {
    const events = [event("2026-09-10T10:00:00")];
    expect(filterEventsByRange(events, sep)).toHaveLength(1);
  });

  it("keeps events overlapping the range start/end", () => {
    const events = [
      event("2026-08-28T10:00:00", "2026-09-03T10:00:00"),
      event("2026-09-28T10:00:00", "2026-10-02T10:00:00"),
    ];
    expect(filterEventsByRange(events, sep)).toHaveLength(2);
  });

  it("drops events outside the range", () => {
    const events = [event("2026-06-01T10:00:00"), event("2026-10-15T10:00:00")];
    expect(filterEventsByRange(events, sep)).toHaveLength(0);
  });

  it("drops events with an invalid start time", () => {
    const bad = {
      ...event("2026-09-10T10:00:00"),
      scheduled_start_time: new Date("invalid"),
    } as CalendarEventRecord;
    expect(filterEventsByRange([bad], sep)).toHaveLength(0);
  });
});
