import { describe, expect, it, vi } from "vitest";
import { EventFetcher } from "../event-fetcher.ts";
import { FetchAbortError } from "../events-types.ts";

function okResponse(body: unknown): Response {
  return new Response(JSON.stringify(body), { status: 200 });
}

function rawEvent(): unknown {
  return {
    id: "1",
    name: "e",
    scheduled_start_time: "2026-09-10T10:00:00",
    scheduled_end_time: "2026-09-10T11:00:00",
    all_day: false,
    tags: [],
  };
}

/** A fetch mock that returns a promise the test controls. */
function deferredReject() {
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<Response>((_resolve, r) => (reject = r));
  return { promise, reject };
}

describe("EventFetcher abort handling", () => {
  it("rejects with FetchAbortError instead of resolving empty when the caller cancels", async () => {
    const fetcher = new EventFetcher();
    const { promise: httpPromise, reject } = deferredReject();
    const fetchMock = vi.spyOn(globalThis, "fetch").mockImplementation(() => httpPromise);

    const promise = fetcher.fetch("k", "u", { signal: undefined });
    // Cancel as the store does on unsubscribe → aborts the in-flight request.
    fetcher.cancel("k");
    reject(new DOMException("Aborted", "AbortError"));

    await expect(promise).rejects.toBeInstanceOf(FetchAbortError);
    fetchMock.mockRestore();
  });

  it("does not report aborted requests as zero events", async () => {
    const fetcher = new EventFetcher();
    const { promise: httpPromise, reject } = deferredReject();
    const fetchMock = vi.spyOn(globalThis, "fetch").mockImplementation(() => httpPromise);

    let settled: "resolved" | "rejected" | "pending" = "pending";
    const promise = fetcher
      .fetch("k", "u", { signal: undefined })
      .then(() => {
        settled = "resolved";
      })
      .catch(() => {
        settled = "rejected";
      });

    fetcher.cancel("k");
    reject(new DOMException("Aborted", "AbortError"));
    await promise;
    expect(settled).toBe("rejected");

    fetchMock.mockRestore();
  });

  it("starts a fresh request after cancel instead of returning the cancelled promise", async () => {
    const fetcher = new EventFetcher();

    // First request hangs on whatever signal fetch() receives; the second
    // resolves normally with data.
    const fetchMock = vi
      .spyOn(globalThis, "fetch")
      .mockImplementationOnce((_input, init?: RequestInit) => {
        const signal = init?.signal;
        return new Promise<Response>((_resolve, reject) => {
          signal?.addEventListener(
            "abort",
            () => reject(new DOMException("Aborted", "AbortError")),
            { once: true },
          );
        });
      })
      .mockImplementationOnce(() => Promise.resolve(okResponse([rawEvent()])));

    // Start a fetch, then cancel it (as the store does on unsubscribe).
    const firstRun = fetcher.fetch("k", "u", { signal: undefined });
    fetcher.cancel("k");
    await expect(firstRun).rejects.toBeInstanceOf(FetchAbortError);

    // A new fetch for the same key must not be handed the cancelled promise.
    const result = await fetcher.fetch("k", "u", { signal: undefined });
    expect(result.events).toHaveLength(1);
    expect(result.events[0]!.id).toBe("1");

    expect(fetchMock).toHaveBeenCalledTimes(2);
    fetchMock.mockRestore();
  });
});
