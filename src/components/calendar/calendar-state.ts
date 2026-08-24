import { Views } from "react-big-calendar";
import { surfaceClassName } from "./styles.ts";
import type {
  CalendarConfig,
  CalendarEventRecord,
  CalendarRange,
  CalendarRuntime,
  CalendarRuntimeStrings,
} from "../../types.ts";
import { useCalendarEvents } from "./use-calendar-events.ts";
import { useCalendarNavigation } from "./use-calendar-navigation.ts";
import { useCalendarRange } from "./use-calendar-range.ts";
import {
  TIMELINE_VIEW,
  YEAR_VIEW,
  type CalendarRangeInput,
  type CalendarView,
} from "./calendar-utils.ts";

const defaultRuntimeStrings: Required<CalendarRuntimeStrings> = {
  allDay: "All-day",
  agenda: "Agenda",
  back: "Back",
  calendarViews: "Calendar views",
  configParseError: "Unable to parse the calendar configuration.",
  date: "Date",
  day: "Day",
  event: "Event",
  loadError: "Unable to load calendar events right now.",
  loading: "Loading...",
  later: "Later",
  missingApiUrl: "The calendar API URL is missing.",
  month: "Month",
  next: "Next",
  nextWeek: "Next week",
  noEvents: "No events to display.",
  showMore: "Show more",
  showMoreEventsForMonth: "Show %1$s more events for %2$s",
  thisWeek: "This week",
  time: "Time",
  today: "Today",
  timeline: "Agenda timeline",
  tomorrow: "Tomorrow",
  week: "Week",
  year: "Year",
};

export function getRuntimeStrings(runtime: CalendarRuntime): Required<CalendarRuntimeStrings> {
  return {
    ...defaultRuntimeStrings,
    ...(runtime.strings ?? {}),
  };
}

export interface UseCalendarStateResult {
  activeViews: readonly CalendarView[];
  agendaLength: number;
  currentDate: Date;
  errorMessage: string;
  events: CalendarEventRecord[];
  handleAgendaLoadMore: () => void;
  handleRangeChange: (range: CalendarRangeInput | null | undefined) => void;
  handleTimelineDateChange: (date: Date) => void;
  handleTimelineRangeRequest: (range: CalendarRange) => void;
  isLoading: boolean;
  isLoadingNext: boolean;
  loadedRange: CalendarRange;
  timelineRange: CalendarRange;
  setCurrentDate: (date: Date) => void;
  setView: (view: CalendarView) => void;
  surfaceClassName: string;
  view: CalendarView;
}

export function useCalendarState(
  config: CalendarConfig,
  runtime: CalendarRuntime,
  strings: Required<CalendarRuntimeStrings>,
  isNarrow = false,
): UseCalendarStateResult {
  const {
    activeViews,
    currentDate,
    effectiveView,
    handleTimelineDateChange,
    view,
    setCurrentDate,
    setView,
  } = useCalendarNavigation(config, isNarrow);

  const rangeState = useCalendarRange(config, view, currentDate, effectiveView, isNarrow);

  const { errorMessage, events, handleAgendaLoadMore, isLoading, isLoadingNext } =
    useCalendarEvents(
      config,
      runtime,
      strings,
      rangeState.effectiveRange,
      rangeState.extendLoadedRange,
    );

  const surface = surfaceClassName(
    effectiveView === Views.AGENDA
      ? "is-agenda-view"
      : effectiveView === TIMELINE_VIEW
        ? "is-timeline-view"
        : effectiveView === YEAR_VIEW
          ? "is-year-view"
          : "",
    isLoading,
  );

  return {
    activeViews,
    agendaLength: rangeState.agendaLength,
    currentDate,
    errorMessage,
    events,
    handleAgendaLoadMore,
    handleRangeChange: rangeState.handleRangeChange,
    handleTimelineDateChange,
    handleTimelineRangeRequest: rangeState.handleTimelineRangeRequest,
    isLoading,
    isLoadingNext,
    loadedRange: rangeState.loadedRange,
    timelineRange: rangeState.timelineRange,
    setCurrentDate,
    setView,
    surfaceClassName: surface,
    view: effectiveView,
  };
}

// Re-export the public calendar surface (constants, helpers, types) so existing
// consumers importing from "./calendar-state.ts" keep working unchanged.
export { useCalendarLocale } from "./use-calendar-locale.ts";

export {
  agendaRangeModes,
  AGENDA_DEFAULTS,
  allowedViews,
  autoSwitchViews,
  calculateAgendaWindow,
  calculateFallbackRange,
  calculateInitialLoadedRange,
  calculateYearRange,
  extendRangeForward,
  getEventClassName,
  getQueryDiscriminator,
  isNarrowContainer,
  normalizeAgendaRangeMode,
  normalizeNonNegativeInteger,
  normalizePositiveInteger,
  normalizeResponsiveBreakpoint,
  normalizeView,
  resolveActiveViews,
  resolveRange,
  TIMELINE_VIEW,
  YEAR_VIEW,
  type AgendaViewConfig,
  type CalendarRangeInput,
  type CalendarView,
} from "./calendar-utils.ts";
