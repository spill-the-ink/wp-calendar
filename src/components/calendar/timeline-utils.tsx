import moment from "moment";
import { cn } from "../../lib/utils.ts";
import type { CalendarEventRecord, CalendarRange } from "../../types.ts";
import { type AgendaViewConfig, AGENDA_DEFAULTS } from "./calendar-state.ts";

const skeletonColumn = "flex flex-[0_0_var(--pc-timeline-day-width)] flex-col gap-2 p-2";

const skeletonDayLabel = "h-3 w-3/5 rounded bg-muted";

const skeletonBar = "h-3 w-[70%] rounded bg-muted";

export interface TimelineLane {
  bars: TimelineEventBar[];
}

export interface TimelineEventBar {
  event: CalendarEventRecord;
  lane: number;
  startPercent: number;
  widthPercent: number;
  dayIndex: number;
}

export function resolveTimelineDays(value: number | string | undefined): number {
  const parsed = Number.parseInt(String(value ?? AGENDA_DEFAULTS.timelineDays), 10);
  return Number.isFinite(parsed)
    ? Math.min(AGENDA_DEFAULTS.maxTimelineDays, Math.max(AGENDA_DEFAULTS.minTimelineDays, parsed))
    : AGENDA_DEFAULTS.timelineDays;
}

export function calculateTimelineBounds(date: Date, dayCount: number): CalendarRange {
  const start = moment(date).startOf("week");
  const end = start
    .clone()
    .add(dayCount - 1, "days")
    .endOf("day");
  return {
    start: start.toDate(),
    end: end.toDate(),
  };
}

/** Visible window for the timeline view: week-anchored, sized by timelineDays. */
export function calculateTimelineVisibleRange(
  date: Date,
  config?: AgendaViewConfig,
): CalendarRange {
  const dayCount = resolveTimelineDays(config?.timelineDays);
  return calculateTimelineBounds(date, dayCount);
}

export function formatTimelineTitle(bounds: CalendarRange): string {
  return `${moment(bounds.start).format("MMM D")} - ${moment(bounds.end).format("MMM D, YYYY")}`;
}

function percentBetween(value: Date, start: Date, end: Date): number {
  const total = end.getTime() - start.getTime();
  if (total <= 0) return 0;
  const offset = Math.min(Math.max(0, value.getTime() - start.getTime()), total);
  return (offset / total) * 100;
}

function buildTimelineBar(
  event: CalendarEventRecord,
  bounds: CalendarRange,
  dayCount: number,
): TimelineEventBar {
  const clampedStart = moment.max(moment(event.scheduled_start_time), moment(bounds.start));
  const clampedEnd = moment.min(moment(event.scheduled_end_time), moment(bounds.end));
  const startPercent = percentBetween(clampedStart.toDate(), bounds.start, bounds.end);
  const endPercent = percentBetween(clampedEnd.toDate(), bounds.start, bounds.end);
  const minWidth = Math.max(0.6, 9 / dayCount);
  const dayIndex = Math.floor(
    (percentBetween(clampedStart.toDate(), bounds.start, bounds.end) * dayCount) / 100,
  );

  return {
    event,
    startPercent,
    widthPercent: Math.max(minWidth, endPercent - startPercent),
    lane: 0,
    dayIndex: Math.max(0, Math.min(dayCount - 1, dayIndex)),
  };
}

export function buildTimelineLanes(
  events: CalendarEventRecord[],
  bounds: CalendarRange,
  dayCount: number,
): { lanes: TimelineLane[]; dayCount: number } {
  const relevant = events
    .filter(
      (event) =>
        moment(event.scheduled_end_time).isSameOrAfter(bounds.start) &&
        moment(event.scheduled_start_time).isSameOrBefore(bounds.end),
    )
    .sort((left, right) => {
      if (left.allDay !== right.allDay) return left.allDay ? -1 : 1;
      return left.scheduled_start_time.getTime() - right.scheduled_start_time.getTime();
    });

  const lanes: TimelineLane[] = [];

  for (const event of relevant) {
    let placed = false;

    for (const lane of lanes) {
      const overlaps = lane.bars.some(
        (bar) =>
          !(
            moment(event.scheduled_end_time).isBefore(bar.event.scheduled_start_time) ||
            moment(event.scheduled_start_time).isAfter(bar.event.scheduled_end_time)
          ),
      );

      if (!overlaps) {
        lane.bars.push(buildTimelineBar(event, bounds, dayCount));
        placed = true;
        break;
      }
    }

    if (!placed) {
      lanes.push({ bars: [buildTimelineBar(event, bounds, dayCount)] });
    }
  }

  return { lanes, dayCount };
}

export function TimelineSkeleton({ columns = 7 }: { columns?: number }) {
  return (
    <div
      className={cn("flex w-full animate-pulse", "post-calendar-timeline-skeleton")}
      role="status"
      aria-hidden="true"
    >
      {Array.from({ length: columns }, (_, i) => (
        <div className={skeletonColumn} key={`timeline-sk-${i}`}>
          <div className={skeletonDayLabel} />
          <div className={skeletonBar} />
        </div>
      ))}
    </div>
  );
}
