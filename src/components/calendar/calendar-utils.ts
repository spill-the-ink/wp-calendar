import moment from "moment";
import { Views } from "react-big-calendar";
import type { CalendarConfig, CalendarRange } from "../../types.ts";

export const YEAR_VIEW = "year";
export const TIMELINE_VIEW = "agenda-timeline";
export const allowedViews = [
  YEAR_VIEW,
  Views.MONTH,
  Views.WEEK,
  Views.DAY,
  Views.AGENDA,
  TIMELINE_VIEW,
] as const;
export const agendaRangeModes = {
  VISIBLE_RANGE: "visible-range",
  UPCOMING_WINDOW: "upcoming-window",
} as const;

export interface AgendaViewConfig {
  rangeMode: "visible-range" | "upcoming-window";
  rangeMonths: number;
  timelineDays?: number;
}

export const AGENDA_DEFAULTS = {
  rangeMode: "upcoming-window" as const,
  rangeMonths: 3,
  timelineDays: 14,
  minTimelineDays: 1,
  maxTimelineDays: 120,
  agendaDefaultDays: 30,
};

export type CalendarView = (typeof allowedViews)[number];
type AgendaRangeMode = (typeof agendaRangeModes)[keyof typeof agendaRangeModes];
export type CalendarRangeInput = Date[] | CalendarRange;

export const autoSwitchViews = [Views.WEEK, TIMELINE_VIEW, "agenda"] as const;

export function normalizeAgendaRangeMode(mode?: string): AgendaRangeMode {
  return mode === agendaRangeModes.UPCOMING_WINDOW
    ? agendaRangeModes.UPCOMING_WINDOW
    : agendaRangeModes.VISIBLE_RANGE;
}

export function normalizePositiveInteger(
  value: number | string | undefined,
  fallback: number,
): number {
  const parsedValue = Number.parseInt(String(value), 10);

  return Number.isFinite(parsedValue) && parsedValue > 0 ? parsedValue : fallback;
}

export function normalizeNonNegativeInteger(
  value: number | string | undefined,
  fallback: number,
): number {
  const parsedValue = Number.parseInt(String(value), 10);

  return Number.isFinite(parsedValue) && parsedValue >= 0 ? parsedValue : fallback;
}

export function normalizeResponsiveBreakpoint(value: number | string | undefined): number {
  return normalizeNonNegativeInteger(value, 640);
}

export function resolveActiveViews(enabledViews?: string[]): readonly CalendarView[] {
  if (!Array.isArray(enabledViews) || enabledViews.length === 0) {
    return allowedViews;
  }

  const filtered = allowedViews.filter((v) => enabledViews.includes(v));

  return filtered.length > 0 ? filtered : allowedViews;
}

export function normalizeView(view?: string): CalendarView {
  return allowedViews.includes(view as CalendarView) ? (view as CalendarView) : Views.MONTH;
}

function isCalendarRange(range: CalendarRangeInput | null | undefined): range is CalendarRange {
  return (
    Boolean(range) &&
    !Array.isArray(range) &&
    range.start instanceof Date &&
    range.end instanceof Date
  );
}

export function resolveRange(range: CalendarRangeInput | null | undefined): CalendarRange | null {
  if (Array.isArray(range) && range.length > 0) {
    return {
      start: range[0],
      end: range[range.length - 1],
    };
  }

  if (isCalendarRange(range)) {
    return {
      start: range.start,
      end: range.end,
    };
  }

  return null;
}

export function calculateInitialLoadedRange(
  view: CalendarView,
  date: Date,
  config: CalendarConfig,
): CalendarRange {
  const agendaRangeModeValue = normalizeAgendaRangeMode(config.agendaRangeMode);
  const agendaRangeMonthsValue = normalizePositiveInteger(config.agendaRangeMonths, 3);

  if (view === Views.AGENDA && agendaRangeModeValue === agendaRangeModes.UPCOMING_WINDOW) {
    // Future-only feed: the loaded window always starts from today so past
    // events are never fetched or shown.
    const now = moment().startOf("day");
    return {
      start: now.toDate(),
      end: now.clone().add(agendaRangeMonthsValue, "months").endOf("day").toDate(),
    };
  }

  if (view === TIMELINE_VIEW) {
    const dayCount = normalizeNonNegativeInteger(config.timelineDays, 14) || 14;
    const rangeStart = moment(date).startOf("week");

    return {
      start: rangeStart.toDate(),
      end: rangeStart
        .clone()
        .add(dayCount - 1, "days")
        .endOf("day")
        .toDate(),
    };
  }

  const start = moment(date).startOf("day");

  return {
    start: start.toDate(),
    end: start.clone().add(29, "days").endOf("day").toDate(),
  };
}

export function calculateFallbackRange(
  view: CalendarView,
  date: Date,
  timelineDays?: number | string,
): CalendarRange {
  if (view === Views.WEEK) {
    const weekStart = moment(date).startOf("week");

    return {
      start: weekStart.toDate(),
      end: weekStart.clone().endOf("week").toDate(),
    };
  }

  if (view === TIMELINE_VIEW) {
    const dayCount = normalizeNonNegativeInteger(timelineDays, 14) || 14;
    const rangeStart = moment(date).startOf("week");

    return {
      start: rangeStart.toDate(),
      end: rangeStart
        .clone()
        .add(dayCount - 1, "days")
        .endOf("day")
        .toDate(),
    };
  }

  if (view === Views.DAY) {
    const dayStart = moment(date).startOf("day");

    return {
      start: dayStart.toDate(),
      end: dayStart.clone().endOf("day").toDate(),
    };
  }

  if (view === Views.AGENDA) {
    const agendaStart = moment(date).startOf("day");

    return {
      start: agendaStart.toDate(),
      end: agendaStart.clone().add(29, "days").endOf("day").toDate(),
    };
  }

  const monthStart = moment(date).startOf("month").startOf("week");
  const monthEnd = moment(date).endOf("month").endOf("week");

  return {
    start: monthStart.toDate(),
    end: monthEnd.toDate(),
  };
}

export function calculateYearRange(date: Date): CalendarRange {
  const yearStart = moment(date).startOf("year");

  return {
    start: yearStart.toDate(),
    end: yearStart.clone().endOf("year").toDate(),
  };
}

export function calculateAgendaWindow(
  date: Date,
  monthCount: number,
): CalendarRange & { length: number } {
  const windowStart = moment(date).startOf("day");
  const windowEnd = windowStart.clone().add(monthCount, "months").endOf("day");

  return {
    start: windowStart.toDate(),
    end: windowEnd.toDate(),
    length: Math.max(1, windowEnd.diff(windowStart, "days") + 1),
  };
}

export function extendRangeForward(range: CalendarRange, days: number): CalendarRange {
  return {
    start: range.start,
    end: moment(range.end).add(days, "days").toDate(),
  };
}

export function getEventClassName(
  start: Date,
  currentDate: Date,
  view: CalendarView,
): string | undefined {
  if (view !== Views.MONTH) {
    return undefined;
  }

  return moment(start).isSame(currentDate, "month") ? undefined : "is-outside-current-month";
}

export function isNarrowContainer(
  width: number | undefined,
  breakpoint: number | string | undefined,
): boolean {
  const px = normalizeResponsiveBreakpoint(breakpoint);

  if (px <= 0 || width === undefined) {
    return false;
  }

  return width < px;
}

/**
 * Builds a stable string discriminator for the cache key from the query-affecting
 * config so calendar instances with different filters never share cached data.
 */
export function getQueryDiscriminator(config: CalendarConfig): string {
  const postTypes =
    Array.isArray(config.postTypes) && config.postTypes.length > 0
      ? config.postTypes.join(",")
      : "";
  const queryVars =
    config.queryVars && Object.keys(config.queryVars).length > 0
      ? JSON.stringify(config.queryVars)
      : "";
  return `${postTypes}\u0000${queryVars}`;
}
