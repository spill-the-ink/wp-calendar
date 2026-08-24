import { describe, expect, it, vi } from "vitest";
import { EventsStoreImpl as EventsStore } from "../events-store.ts";
import { EventCache } from "../event-cache.ts";
import { EventFetcher } from "../event-fetcher.ts";
import type { CalendarEventRecord } from "../../types.ts";
import type { FetchResult } from "../events-types.ts";

function ev(id: string): CalendarEventRecord {
  return {
    id,
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

/** A fetcher whose resolution is manually controlled by the test. */
function deferredFetcher() {
  let resolve!: (value: FetchResult) => void;
  const promise = new Promise<FetchResult>((r) => (resolve = r));
  const fetch = vi.fn(() => promise);
  const cancel = vi.fn();
  const cancelAll = vi.fn();
  const finish = (events: CalendarEventRecord[], fetchedAt = Date.now()) =>
    resolve({ events, fetchedAt });
  return { fetch, resolve, cancel, cancelAll, finish };
}

function subscribe(
  store: EventsStore,
  opts: { monthKey?: string; signal?: AbortSignal } = {},
): { seen: string[]; unsub?: () => void } {
  const seen: string[] = [];
  const unsub = store.subscribe(
    opts.monthKey ?? "2026-09",
    "default",
    () => ({ url: "u", nonce: undefined }),
    (events, status) => {
      seen.push(`${status}:${events.length}`);
    },
    { signal: opts.signal },
  );
  return { seen, unsub };
}

describe("EventsStore", () => {
  it("serves a fresh cache hit immediately, no fetch", () => {
    const cache = new EventCache();
    cache.set("2026-09", "default", [ev("1")]);
    const fetcher = deferredFetcher();
    const store = new EventsStore(cache, fetcher, { staleMs: 60_000 });

    const { seen } = subscribe(store);
    expect(seen).toEqual(["fresh:1"]);
    expect(fetcher.fetch).not.toHaveBeenCalled();
  });

  it("fetches on a cache miss, notifies loading then ready", async () => {
    const fetcher = deferredFetcher();
    const store = new EventsStore(new EventCache(), fetcher);

    const { seen } = subscribe(store);
    expect(seen).toEqual(["loading:0"]);
    expect(fetcher.fetch).toHaveBeenCalledTimes(1);

    fetcher.finish([ev("1")]);
    await new Promise((r) => setTimeout(r, 0));
    expect(seen).toContain("ready:1");
  });

  it("serves stale data then refreshes in the background", async () => {
    const cache = new EventCache();
    cache.set("2026-09", "default", [ev("old")]);
    const fetcher = deferredFetcher();
    // staleMs: -1 forces any cached entry to be immediately stale.
    const store = new EventsStore(cache, fetcher, { staleMs: -1 });

    const { seen } = subscribe(store);
    expect(seen).toEqual(["stale:1"]);
    expect(fetcher.fetch).toHaveBeenCalledTimes(1);

    fetcher.finish([ev("new")]);
    await new Promise((r) => setTimeout(r, 0));
    expect(seen).toContain("ready:1");
    expect(seen).not.toContain("error");
  });

  it("notifies error on a foreground fetch rejection", async () => {
    const fetch = vi.fn(() => Promise.reject(new Error("boom")));
    const store = new EventsStore(new EventCache(), { fetch, cancel: vi.fn(), cancelAll: vi.fn() });

    const { seen } = subscribe(store);
    expect(seen).toEqual(["loading:0"]);

    await new Promise((r) => setTimeout(r, 0));
    expect(seen).toContain("error:0");
  });

  it("unsubscribing stops updates and cancels the fetch", () => {
    const fetcher = deferredFetcher();
    const store = new EventsStore(new EventCache(), fetcher);

    const { seen, unsub } = subscribe(store);
    unsub!();
    expect(fetcher.cancel).toHaveBeenCalledTimes(1);
    expect(seen).toEqual(["loading:0"]);
  });

  it("unsubscribes automatically when the signal aborts", () => {
    const cache = new EventCache();
    cache.set("2026-09", "default", [ev("1")]);
    const store = new EventsStore(cache, deferredFetcher(), { staleMs: 60_000 });

    const controller = new AbortController();
    const { seen } = subscribe(store, { signal: controller.signal });
    controller.abort();
    store.clear();
    expect(seen).toEqual(["fresh:1"]);
  });

  it("prefetch warms the cache without a subscriber", async () => {
    const fetcher = deferredFetcher();
    const cache = new EventCache();
    const store = new EventsStore(cache, fetcher);

    store.prefetch("2026-09", "default", () => ({ url: "u", nonce: undefined }));
    expect(fetcher.fetch).toHaveBeenCalledTimes(1);

    fetcher.finish([ev("9")]);
    await new Promise((r) => setTimeout(r, 0));

    // A second, fresh store over the same cache should now hit.
    const freshStore = new EventsStore(cache, deferredFetcher(), { staleMs: 60_000 });
    const { seen } = subscribe(freshStore);
    expect(seen).toEqual(["fresh:1"]);
  });

  it("recovers after an abort caused by a StrictMode mount cleanup", async () => {
    // Simulates React StrictMode: effect → cleanup(abort) → effect. The first
    // fetch must be cancelled without poisoning the cache, and the second
    // subscription must issue a fresh request that succeeds.
    const fetcher = new EventFetcher();

    // First HTTP call hangs and rejects on the signal abort; second succeeds.
    const fetchMock = vi
      .spyOn(globalThis, "fetch")
      .mockImplementationOnce(
        (_input, init?: RequestInit) =>
          new Promise<Response>((_resolve, reject) => {
            init?.signal?.addEventListener(
              "abort",
              () => reject(new DOMException("Aborted", "AbortError")),
              { once: true },
            );
          }),
      )
      .mockImplementationOnce(() =>
        Promise.resolve(
          new Response(
            JSON.stringify([
              {
                id: "1",
                name: "e",
                scheduled_start_time: "2026-09-10T10:00:00",
                scheduled_end_time: "2026-09-10T11:00:00",
                all_day: false,
                tags: [],
              },
            ]),
            { status: 200 },
          ),
        ),
      );

    const store = new EventsStore(new EventCache(), fetcher);

    // Mount 1
    const c1 = new AbortController();
    const seen1: string[] = [];
    const u1 = store.subscribe(
      "2026-09",
      "default",
      () => ({ url: "u", nonce: undefined }),
      (events, status) => seen1.push(`${status}:${events.length}`),
      { signal: c1.signal },
    );

    // StrictMode cleanup: abort the shared controller, then run unsubscribers.
    c1.abort();
    u1();

    // Mount 2 (StrictMode re-runs synchronously, no awaited yield) — must start
    // a FRESH fetch and resolve with data.
    const seen2: string[] = [];
    store.subscribe(
      "2026-09",
      "default",
      () => ({ url: "u", nonce: undefined }),
      (events, status) => seen2.push(`${status}:${events.length}`),
    );

    await new Promise((r) => setTimeout(r, 0));

    expect(seen2).toContain("loading:0");
    expect(seen2).toContain("ready:1");
    expect(fetchMock).toHaveBeenCalledTimes(2);

    fetchMock.mockRestore();
  });
});
