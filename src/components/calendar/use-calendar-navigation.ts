import { useCallback, useEffect, useRef, useState } from "react";
import { Views } from "react-big-calendar";
import moment from "moment";
import type { CalendarConfig } from "../../types.ts";
import {
  autoSwitchViews,
  normalizeView,
  resolveActiveViews,
  type CalendarView,
} from "./calendar-utils.ts";
import {
  getInitialDateFromUrl,
  getInitialViewFromUrl,
  parseCalendarParamsFromUrl,
  syncCalendarParamsToUrl,
} from "./use-calendar-locale.ts";

export interface CalendarNavigationState {
  activeViews: readonly CalendarView[];
  currentDate: Date;
  effectiveView: CalendarView;
  handleTimelineDateChange: (date: Date) => void;
  view: CalendarView;
  setCurrentDate: (date: Date) => void;
  setView: (view: CalendarView) => void;
}

export function useCalendarNavigation(
  config: CalendarConfig,
  isNarrow = false,
): CalendarNavigationState {
  const activeViews = resolveActiveViews(config.enabledViews);
  const [view, setView] = useState<CalendarView>(() => {
    const initialViews = resolveActiveViews(config.enabledViews);
    const urlView = getInitialViewFromUrl(config.enabledViews);
    if (urlView && initialViews.includes(urlView)) {
      return urlView;
    }
    const normalized = normalizeView(config.defaultView);
    return initialViews.includes(normalized) ? normalized : (initialViews[0] ?? Views.MONTH);
  });
  const [currentDate, setCurrentDate] = useState<Date>(() => getInitialDateFromUrl() ?? new Date());
  const viewRef = useRef(view);
  const dateRef = useRef(currentDate);
  const pendingHistoryUpdateRef = useRef(false);
  const scrollDateUpdateRef = useRef(false);
  const isInitialMountRef = useRef(true);

  // Keep refs in sync for the popstate handler without re-subscribing.
  useEffect(() => {
    viewRef.current = view;
  }, [view]);
  useEffect(() => {
    dateRef.current = currentDate;
  }, [currentDate]);

  useEffect(() => {
    // Initial URL sync — ensure a shareable URL exists without adding a history entry.
    syncCalendarParamsToUrl(currentDate, view, "replace");
    isInitialMountRef.current = false;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (isInitialMountRef.current) return;
    if (pendingHistoryUpdateRef.current) {
      pendingHistoryUpdateRef.current = false;
      return;
    }
    // Scroll-driven updates (timeline/agenda) replace the URL entry rather than
    // pushing, so continuous scrolling doesn't flood browser history.
    if (scrollDateUpdateRef.current) {
      scrollDateUpdateRef.current = false;
      syncCalendarParamsToUrl(currentDate, view, "replace");
      return;
    }
    syncCalendarParamsToUrl(currentDate, view, "push");
  }, [currentDate, view]);

  useEffect(() => {
    function handlePopState() {
      const params = parseCalendarParamsFromUrl();
      const nextDate = params.date;
      const nextView = params.view;
      const allowed = resolveActiveViews(config.enabledViews);

      const dateChanged = nextDate && !moment(nextDate).isSame(dateRef.current, "day");
      const viewChanged = nextView && nextView !== viewRef.current && allowed.includes(nextView);

      if (dateChanged || viewChanged) {
        pendingHistoryUpdateRef.current = true;
        if (dateChanged && nextDate) setCurrentDate(nextDate);
        if (viewChanged && nextView) setView(nextView);
        // If only one changed, the other pending flag still prevents the push
        // for the effect that follows. Ensure flag lasts for React batch.
        return;
      }

      // Navigated to URL without valid params (e.g., stripped query) — keep URL in sync
      // with current calendar state but without pushing a new entry.
      syncCalendarParamsToUrl(dateRef.current, viewRef.current, "replace");
    }

    window.addEventListener("popstate", handlePopState);
    return () => window.removeEventListener("popstate", handlePopState);
  }, [config.enabledViews]);

  const effectiveView =
    isNarrow && (autoSwitchViews as readonly string[]).includes(view) ? Views.AGENDA : view;

  // Updates the focused date (toolbar + URL) as the user scrolls the timeline,
  // without resetting the loaded/infinite-scroll buffer when the new date is
  // already within it. The URL is replaced (not pushed) via scrollDateUpdateRef.
  const handleTimelineDateChange = useCallback(
    (nextDate: Date) => {
      if (moment(currentDate).isSame(nextDate, "day")) {
        return;
      }
      scrollDateUpdateRef.current = true;
      setCurrentDate(nextDate);
    },
    [currentDate],
  );

  return {
    activeViews,
    currentDate,
    effectiveView,
    handleTimelineDateChange,
    view,
    setCurrentDate,
    setView,
  };
}
