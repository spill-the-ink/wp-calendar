import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { buildRequestUrl, getPreviewEvents } from "../../lib/events-api.ts";
import { monthsInRange, toMonthKey } from "../../lib/date-utils.ts";
import { getEventsStore } from "../../lib/events-store.ts";
import { getQueryDiscriminator, normalizePositiveInteger } from "./calendar-utils.ts";
import type { MonthKey } from "../../lib/events-types.ts";
import type {
  CalendarConfig,
  CalendarEventRecord,
  CalendarRange,
  CalendarRuntime,
  CalendarRuntimeStrings,
} from "../../types.ts";

export interface CalendarEventsState {
  errorMessage: string;
  events: CalendarEventRecord[];
  handleAgendaLoadMore: () => void;
  isLoading: boolean;
  isLoadingNext: boolean;
}

const LOAD_COOLDOWN_MS = 2000;

export function useCalendarEvents(
  config: CalendarConfig,
  runtime: CalendarRuntime,
  strings: Required<CalendarRuntimeStrings>,
  effectiveRange: CalendarRange,
  extendLoadedRange: (days: number) => void,
): CalendarEventsState {
  const previewEvents = useMemo(
    () => getPreviewEvents(runtime.previewEvents),
    [runtime.previewEvents],
  );

  const discriminator = useMemo(() => getQueryDiscriminator(config), [config]);

  // The range the user is viewing may be narrower than a month.  We always
  // fetch complete months and slice locally in the views, so the fetch window
  // is the collection of months covering `effectiveRange`.
  const fetchMonths = useMemo(() => monthsInRange(effectiveRange), [effectiveRange]);

  const store = useMemo(() => getEventsStore(), []);

  const [results, setResults] = useState<
    Record<MonthKey, { events: CalendarEventRecord[]; status: "ready" | "loading" | "error" }>
  >({});
  const [isLoadingNext, setIsLoadingNext] = useState(false);
  const lastLoadAtRef = useRef({ next: 0 });

  // Subscribe to all months covering the effective range.  Each month resolves
  // independently; the merged results are derived below.
  useEffect(() => {
    if (previewEvents) {
      return;
    }

    const controller = new AbortController();

    const makeFetcher = (monthRangeValue: CalendarRange) => () => ({
      url: buildRequestUrl(config, runtime, monthRangeValue),
      nonce: runtime.restNonce,
    });

    const unsubscribers: Array<() => void> = [];

    fetchMonths.forEach((month) => {
      const monthKey = toMonthKey(month.start);
      const unsubscribe = store.subscribe(
        monthKey,
        discriminator,
        makeFetcher(month),
        (events, status) => {
          setResults((prev) => ({
            ...prev,
            [monthKey]: {
              events,
              status: status === "error" ? "error" : status === "loading" ? "loading" : "ready",
            },
          }));
        },
        { signal: controller.signal },
      );
      unsubscribers.push(unsubscribe);
    });

    return () => {
      controller.abort();
      unsubscribers.forEach((unsub) => unsub());
    };
  }, [config, discriminator, fetchMonths, previewEvents, runtime, store]);

  // Prefetch the months adjacent to the current view so intra-month and
  // month-boundary navigation is instant.  Only for single-month views
  // (month/week/day/agenda); the year view already fetches all 12 months.
  useEffect(() => {
    if (previewEvents || fetchMonths.length !== 1) {
      return;
    }

    const month = fetchMonths[0]!;
    const year = month.start.getFullYear();
    const monthIndex = month.start.getMonth();

    const makeFetcher = (monthRangeValue: CalendarRange) => () => ({
      url: buildRequestUrl(config, runtime, monthRangeValue),
      nonce: runtime.restNonce,
    });

    const prevRange = {
      start: new Date(year, monthIndex - 1, 1),
      end: new Date(year, monthIndex, 0, 23, 59, 59, 999),
    };
    const nextRange = {
      start: new Date(year, monthIndex + 1, 1),
      end: new Date(year, monthIndex + 2, 0, 23, 59, 59, 999),
    };

    store.prefetch(toMonthKey(prevRange.start), discriminator, makeFetcher(prevRange));
    store.prefetch(toMonthKey(nextRange.start), discriminator, makeFetcher(nextRange));

    return () => undefined;
  }, [config, discriminator, fetchMonths, previewEvents, runtime, store]);

  // Merge subscribed months into a single events array (deduplicated across
  // months by event id, since a cross-month event appears in two buckets).
  const months = useMemo(() => fetchMonths.map((m) => toMonthKey(m.start)), [fetchMonths]);
  const mergedEvents = useMemo(() => {
    const seen = new Set<string>();
    const out: CalendarEventRecord[] = [];
    for (const monthKey of months) {
      const entry = results[monthKey];
      if (!entry) continue;
      for (const event of entry.events) {
        const id = String(event.id ?? "");
        if (id && seen.has(id)) continue;
        if (id) seen.add(id);
        out.push(event);
      }
    }
    return out;
  }, [months, results]);

  // Aggregate status.
  const loading = months.some((m) => {
    const entry = results[m];
    return !entry || entry.status === "loading";
  });
  const anyReady = months.some((m) => results[m]?.status === "ready");

  const events = previewEvents ?? mergedEvents;
  const isLoading = previewEvents ? false : loading && !isLoadingNext;
  const errorMessage =
    !previewEvents &&
    !anyReady &&
    months.length > 0 &&
    months.every((m) => results[m]?.status === "error")
      ? strings.loadError
      : (config.error ?? "");

  // Clear the load-more indicator once the store settles.
  useEffect(() => {
    if (!isLoadingNext) return;
    if (loading) return;
    // Reflects a store-side data arrival into local UI state.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setIsLoadingNext(false);
  }, [isLoadingNext, loading]);

  const handleAgendaLoadMore = useCallback(() => {
    const now = Date.now();
    if (now - lastLoadAtRef.current.next < LOAD_COOLDOWN_MS) return;
    lastLoadAtRef.current.next = now;
    const days = normalizePositiveInteger(config.agendaRangeMonths, 3) * 30;
    extendLoadedRange(days);
    setIsLoadingNext(true);
  }, [config.agendaRangeMonths, extendLoadedRange]);

  return {
    errorMessage,
    events,
    handleAgendaLoadMore,
    isLoading,
    isLoadingNext,
  };
}
