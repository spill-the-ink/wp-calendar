import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Views } from "react-big-calendar";
import moment from "moment";
import type { CalendarConfig, CalendarRange } from "../../types.ts";
import {
  agendaRangeModes,
  calculateFallbackRange,
  calculateInitialLoadedRange,
  calculateYearRange,
  extendRangeForward,
  normalizeAgendaRangeMode,
  normalizePositiveInteger,
  resolveRange,
  TIMELINE_VIEW,
  YEAR_VIEW,
  type CalendarRangeInput,
  type CalendarView,
} from "./calendar-utils.ts";

export interface CalendarRangeState {
  activeRange: CalendarRange | null;
  agendaLength: number;
  agendaWindow: CalendarRange & { length: number };
  effectiveRange: CalendarRange;
  extendLoadedRange: (days: number) => void;
  fallbackRange: CalendarRange;
  handleRangeChange: (range: CalendarRangeInput | null | undefined) => void;
  handleTimelineRangeRequest: (range: CalendarRange) => void;
  loadedRange: CalendarRange;
  timelineRange: CalendarRange;
}

export function useCalendarRange(
  config: CalendarConfig,
  view: CalendarView,
  currentDate: Date,
  effectiveView: CalendarView,
  isNarrow = false,
): CalendarRangeState {
  const [loadedRange, setLoadedRange] = useState<CalendarRange>(() =>
    calculateInitialLoadedRange(view, currentDate, config),
  );
  const [timelineRange, setTimelineRange] = useState<CalendarRange>(() =>
    calculateInitialLoadedRange(TIMELINE_VIEW, currentDate, config),
  );
  const [activeRange, setActiveRange] = useState<CalendarRange | null>(null);

  const resetTrackerRef = useRef<{
    dateKey: string;
    view: string;
  }>({
    dateKey: moment(currentDate).format("YYYY-MM-DD"),
    view,
  });

  // Reset the loaded (fetch) range when the user navigates the focused date or
  // switches views, so the infinite-scroll buffer is re-seeded from the new
  // visible range. Date changes that stay within the currently loaded range
  // (e.g. timeline/agenda infinite scroll) only update the URL/toolbar.
  const currentDateKey = moment(currentDate).format("YYYY-MM-DD");
  useEffect(() => {
    const dateChanged = resetTrackerRef.current.dateKey !== currentDateKey;
    const viewChanged = resetTrackerRef.current.view !== effectiveView;

    if (effectiveView === TIMELINE_VIEW) {
      const dateInsideTimeline = moment(currentDate).isBetween(
        moment(timelineRange.start).startOf("day"),
        moment(timelineRange.end).endOf("day"),
        undefined,
        "[]",
      );
      if (dateChanged || viewChanged) {
        resetTrackerRef.current = { dateKey: currentDateKey, view: effectiveView };
        if (viewChanged || !dateInsideTimeline) {
          setTimelineRange(calculateInitialLoadedRange(TIMELINE_VIEW, currentDate, config));
        }
      }
    } else {
      const dateInsideLoaded = moment(currentDate).isBetween(
        moment(loadedRange.start).startOf("day"),
        moment(loadedRange.end).endOf("day"),
        undefined,
        "[]",
      );
      if (dateChanged || viewChanged) {
        resetTrackerRef.current = { dateKey: currentDateKey, view: effectiveView };
        if (viewChanged || !dateInsideLoaded) {
          setLoadedRange(calculateInitialLoadedRange(effectiveView, currentDate, config));
        }
      }
    }
  }, [
    config,
    currentDate,
    currentDateKey,
    effectiveView,
    loadedRange.start,
    loadedRange.end,
    timelineRange.start,
    timelineRange.end,
  ]);

  const agendaRangeMode = normalizeAgendaRangeMode(config.agendaRangeMode);
  const agendaRangeMonths = normalizePositiveInteger(config.agendaRangeMonths, 3);
  const agendaWindow = useMemo(() => {
    const now = moment().startOf("day");
    const end = now.clone().add(agendaRangeMonths, "months").endOf("day");
    return {
      start: now.toDate(),
      end: end.toDate(),
      length: Math.max(1, end.diff(now, "days") + 1),
    };
  }, [agendaRangeMonths]);
  const fallbackRange = useMemo(
    () => calculateFallbackRange(view, currentDate, config.timelineDays),
    [config.timelineDays, currentDate, view],
  );
  const effectiveRange = useMemo(() => {
    if (effectiveView === Views.AGENDA && agendaRangeMode === agendaRangeModes.UPCOMING_WINDOW) {
      return loadedRange;
    }

    if (effectiveView === YEAR_VIEW) {
      return calculateYearRange(currentDate);
    }

    if (isNarrow && effectiveView === Views.AGENDA) {
      return loadedRange;
    }

    if (effectiveView === TIMELINE_VIEW) {
      return timelineRange;
    }

    return activeRange ?? fallbackRange;
  }, [
    activeRange,
    agendaRangeMode,
    currentDate,
    effectiveView,
    fallbackRange,
    isNarrow,
    loadedRange,
    timelineRange,
  ]);

  const extendLoadedRange = useCallback((days: number) => {
    setLoadedRange((prev) => extendRangeForward(prev, days));
  }, []);

  const handleTimelineRangeRequest = useCallback((range: CalendarRange) => {
    setTimelineRange((prev) => {
      if (
        moment(prev.start).isSame(range.start, "day") &&
        moment(prev.end).isSame(range.end, "day")
      ) {
        return prev;
      }
      return range;
    });
  }, []);

  const handleRangeChangeCallback = useCallback(
    (range: CalendarRangeInput | null | undefined) => {
      if (
        (effectiveView === Views.AGENDA && agendaRangeMode === agendaRangeModes.UPCOMING_WINDOW) ||
        effectiveView === YEAR_VIEW
      ) {
        return;
      }

      const resolvedRange = resolveRange(range);

      if (resolvedRange) {
        setActiveRange(resolvedRange);
      }
    },
    [agendaRangeMode, effectiveView],
  );

  const agendaLength = useMemo(
    () =>
      effectiveView === Views.AGENDA && agendaRangeMode === agendaRangeModes.UPCOMING_WINDOW
        ? agendaWindow.length
        : 30,
    [agendaRangeMode, effectiveView, agendaWindow.length],
  );

  return {
    activeRange,
    agendaLength,
    agendaWindow,
    effectiveRange,
    extendLoadedRange,
    fallbackRange,
    handleRangeChange: handleRangeChangeCallback,
    handleTimelineRangeRequest,
    loadedRange,
    timelineRange,
  };
}
