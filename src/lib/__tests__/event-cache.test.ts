import { describe, expect, it } from "vitest";
import { EventCache, DEFAULT_STALE_MS } from "../event-cache.ts";
import type { CalendarEventRecord } from "../../types.ts";

function ev(): CalendarEventRecord {
  return {
    name: "e",
    scheduled_start_time: new Date("2026-09-10T10:00:00"),
    scheduled_end_time: new Date("2026-09-10T11:00:00"),
    allDay: false,
    tags: [],
    label: null,
    source: null,
    location: null,
    location_url: null,
  };
}

describe("EventCache", () => {
  it("returns null for a missing month", () => {
    const cache = new EventCache();
    expect(cache.get("2026-09")).toBeNull();
  });

  it("stores and retrieves a month", () => {
    const cache = new EventCache();
    cache.set("2026-09", "default", [ev()]);
    const entry = cache.get("2026-09", "default");
    expect(entry).not.toBeNull();
    expect(entry!.events).toHaveLength(1);
  });

  it("separates entries by discriminator", () => {
    const cache = new EventCache();
    cache.set("2026-09", "a", [ev()]);
    expect(cache.get("2026-09", "a")).not.toBeNull();
    expect(cache.get("2026-09", "b")).toBeNull();
  });

  it("reports freshness within staleMs", () => {
    const cache = new EventCache();
    cache.set("2026-09", "default", [ev()]);
    expect(cache.isFresh("2026-09", "default")).toBe(true);
    expect(cache.isStale("2026-09", "default")).toBe(false);
  });

  it("reports staleness after staleMs", () => {
    const cache = new EventCache();
    cache.set("2026-09", "default", [ev()]);
    expect(cache.isStale("2026-09", "default", DEFAULT_STALE_MS)).toBe(false);
    // With a zero stale window the entry is immediately stale.
    expect(cache.isStale("2026-09", "default", 0)).toBe(true);
  });

  it("deletes a single month", () => {
    const cache = new EventCache();
    cache.set("2026-09", "default", [ev()]);
    cache.delete("2026-09", "default");
    expect(cache.get("2026-09", "default")).toBeNull();
  });

  it("invalidates months overlapping a range", () => {
    const cache = new EventCache();
    cache.set("2026-09", "default", [ev()]);
    cache.set("2026-08", "default", [ev()]);

    // Invalidate only September.
    cache.invalidate({ start: new Date(2026, 8, 10), end: new Date(2026, 8, 20) });
    expect(cache.get("2026-09", "default")).toBeNull();
    expect(cache.get("2026-08", "default")).not.toBeNull();
  });

  it("invalidates only matching discriminator", () => {
    const cache = new EventCache();
    cache.set("2026-09", "a", [ev()]);
    cache.set("2026-09", "b", [ev()]);

    cache.invalidate({ start: new Date(2026, 8, 10), end: new Date(2026, 8, 20) }, "a");
    expect(cache.get("2026-09", "a")).toBeNull();
    expect(cache.get("2026-09", "b")).not.toBeNull();
  });

  it("clears all entries", () => {
    const cache = new EventCache();
    cache.set("2026-09", "a", [ev()]);
    cache.set("2026-10", "a", [ev()]);
    cache.clear();
    expect(cache.size).toBe(0);
  });

  it("evicts the oldest entry beyond maxEntries", () => {
    const cache = new EventCache({ maxEntries: 2 });
    cache.set("2026-09", "a", [ev()]);
    cache.set("2026-10", "a", [ev()]);
    cache.set("2026-11", "a", [ev()]);
    expect(cache.size).toBeLessThanOrEqual(2);
  });
});
