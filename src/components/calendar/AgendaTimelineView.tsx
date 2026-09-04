import { useEffect, useLayoutEffect, useRef, useMemo, useState, useCallback } from "react";
import moment from "moment";
import { Navigate } from "react-big-calendar";
import { cn } from "../../lib/utils.ts";
import type { CalendarEventRecord, CalendarRange, CalendarRuntimeStrings } from "../../types.ts";
import type { AgendaViewConfig } from "./calendar-state.ts";
import {
  type TimelineLane,
  buildTimelineLanes,
  calculateTimelineVisibleRange,
  formatTimelineTitle,
  resolveTimelineDays,
  TimelineSkeleton,
} from "./timeline-utils.tsx";
import { eventLink, todayStripe } from "./styles.ts";

const timelineView =
  "@container flex min-h-[32rem] max-h-[calc(100dvh_-_8rem)] flex-col overflow-hidden border border-border";

const timelineScroll =
  "relative min-h-0 min-w-[640px] flex-1 overflow-y-hidden border-border [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden";

const timelineHeader = "flex h-12 shrink-0 border-b-2 border-border";

const timelineDayLabel =
  "flex min-w-0 flex-[0_0_var(--pc-timeline-day-width)] flex-col py-1.5 text-center text-sm text-muted-foreground";

const timelineLane = "relative min-h-8 w-full";

const timelineDayColumn =
  "relative min-w-0 flex-[0_0_var(--pc-timeline-day-width)] border-r border-border";

const timelineBar =
  "absolute top-1/2 flex -translate-y-1/2 items-center overflow-hidden whitespace-nowrap rounded-sm border border-l-[3px] bg-primary px-1.5 py-[0.1875rem] text-sm text-primary-foreground max-w-none";

interface AgendaTimelineViewProps {
  date: Date;
  events: CalendarEventRecord[];
  config: AgendaViewConfig;
  strings: Required<CalendarRuntimeStrings>;
  fetchedRange?: CalendarRange;
  isLoading?: boolean;
  onDateChange?: (date: Date) => void;
  onRangeRequest?: (range: CalendarRange) => void;
}

function TimelineDayLabel({
  day,
  _strings,
}: {
  day: Date;
  _strings: Required<CalendarRuntimeStrings>;
}) {
  return (
    <span className={cn(timelineDayLabel, "wp-calendar-timeline-day-label")}>
      <span>{moment(day).format("ddd")}</span>
      <span>{moment(day).format("D")}</span>
    </span>
  );
}

function TimelineEventBar({
  event,
  startPercent,
  widthPercent,
  eventColor,
  _strings,
}: {
  event: CalendarEventRecord;
  startPercent: number;
  widthPercent: number;
  eventColor?: string;
  _strings: Required<CalendarRuntimeStrings>;
}) {
  const timeLabel = event.allDay
    ? ""
    : `${moment(event.scheduled_start_time).format("H:mm")} – ${moment(event.scheduled_end_time).format("H:mm")} · `;

  const barStyle: React.CSSProperties = {
    left: `${startPercent}%`,
    width: `${widthPercent}%`,
  };

  if (eventColor) {
    barStyle["--pc-event-color" as string] = eventColor;
    barStyle.borderLeftColor = eventColor;
  }

  return (
    <span
      className={cn(timelineBar, "wp-calendar-timeline-bar")}
      style={barStyle}
      title={`${timeLabel}${event.name}`}
      role="button"
      tabIndex={0}
      aria-label={`${event.name}${event.allDay ? "" : `, ${timeLabel}`}`}
    >
      {event.url ? (
        <a href={event.url} className={cn(eventLink, "overflow-hidden text-ellipsis")}>
          {event.name}
        </a>
      ) : (
        event.name
      )}
    </span>
  );
}

function TimelineLaneComponent({
  lane,
  laneIndex,
  _strings,
}: {
  lane: TimelineLane;
  laneIndex: number;
  _strings: Required<CalendarRuntimeStrings>;
}) {
  return (
    <div
      key={`timeline-lane-${laneIndex}`}
      className={cn(timelineLane, "wp-calendar-timeline-lane")}
    >
      {lane.bars.map((bar, barIndex) => (
        <TimelineEventBar
          key={`${String(bar.event.id)}-${laneIndex}-${barIndex}`}
          event={bar.event}
          startPercent={bar.startPercent}
          widthPercent={bar.widthPercent}
          eventColor={bar.event.label?.color}
          _strings={_strings}
        />
      ))}
    </div>
  );
}

// ─── Virtualized timeline constants ──────────────────────────────────
// Fixed virtual span provides smooth scroll independent of data. Scroll
// position is pure math (index * dayWidth); data is painted on top.
const VIRTUAL_DAYS = 1095; // ~3 years
const OVERSCAN = 8;
const SCROLL_IDLE_MS = 100;

const AgendaTimelineView = Object.assign(
  function AgendaTimelineViewComponent({
    date,
    events,
    strings,
    config,
    fetchedRange,
    isLoading,
    onDateChange,
    onRangeRequest,
  }: AgendaTimelineViewProps) {
    const scrollContainerRef = useRef<HTMLDivElement>(null);

    // Virtual window is anchored at mount so scrollLeft is stable.
    // It covers VIRTUAL_DAYS centered at the initial date.
    const virtualStartRef = useRef<Date | null>(null);
    if (virtualStartRef.current === null) {
      virtualStartRef.current = moment(date)
        .subtract(Math.floor(VIRTUAL_DAYS / 2), "days")
        .startOf("day")
        .toDate();
    }
    const virtualStart = virtualStartRef.current;

    const timelineDays = resolveTimelineDays(config.timelineDays);
    const totalWidthStyle = `calc(var(--pc-timeline-day-width) * ${VIRTUAL_DAYS})`;

    // Window indices into the virtual span
    const [windowRange, setWindowRange] = useState(() => {
      // Initial window centered at date, before layout measurement we estimate
      const centerIdx = Math.floor(VIRTUAL_DAYS / 2);
      return {
        startIdx: Math.max(0, centerIdx - 12),
        endIdx: Math.min(VIRTUAL_DAYS - 1, centerIdx + 12),
      };
    });

    const onDateChangeRef = useRef(onDateChange);
    const onRangeRequestRef = useRef(onRangeRequest);
    useEffect(() => {
      onDateChangeRef.current = onDateChange;
    }, [onDateChange]);
    useEffect(() => {
      onRangeRequestRef.current = onRangeRequest;
    }, [onRangeRequest]);

    const idleTimeoutRef = useRef<number | null>(null);
    const lastCenterKeyRef = useRef(moment(date).format("YYYY-MM-DD"));
    const windowRangeRef = useRef(windowRange);
    useEffect(() => {
      windowRangeRef.current = windowRange;
    }, [windowRange]);

    // Helper to get dayWidth in px (spacer width / VIRTUAL_DAYS)
    const getDayWidth = useCallback(() => {
      const container = scrollContainerRef.current;
      if (!container) return 104;
      // scrollWidth is total virtual width after spacer renders
      const totalW = container.scrollWidth || VIRTUAL_DAYS * 104;
      return totalW / VIRTUAL_DAYS || 104;
    }, []);

    // Center scroll on initial mount and when date changes externally (toolbar/nav)
    // We distinguish external nav vs scroll-driven by comparing to lastCenterKeyRef.
    useLayoutEffect(() => {
      const container = scrollContainerRef.current;
      if (!container) return;
      const dayWidth = getDayWidth();
      const dateKey = moment(date).format("YYYY-MM-DD");
      // If this date update came from our own idle handler, don't re-center (avoid fighting scroll)
      if (dateKey === lastCenterKeyRef.current) {
        const currentCenterIdx = Math.floor(
          (container.scrollLeft + container.clientWidth / 2) / dayWidth,
        );
        const targetIdxCheck = Math.floor(
          moment(date).diff(moment(virtualStartRef.current as Date).startOf("day"), "days"),
        );
        if (Math.abs(currentCenterIdx - targetIdxCheck) <= 1) return;
      }
      let targetIdx = Math.floor(
        moment(date).diff(moment(virtualStartRef.current as Date).startOf("day"), "days"),
      );
      // Re-anchor virtual window if target is out of bounds or too close to edge
      // so the requested date stays centered rather than clamped to the edge.
      if (targetIdx < 50 || targetIdx >= VIRTUAL_DAYS - 50) {
        const newVirtualStart = moment(date)
          .subtract(Math.floor(VIRTUAL_DAYS / 2), "days")
          .startOf("day")
          .toDate();
        virtualStartRef.current = newVirtualStart;
        targetIdx = Math.floor(VIRTUAL_DAYS / 2);
      }
      const clampedIdx = Math.max(0, Math.min(VIRTUAL_DAYS - 1, targetIdx));
      const targetLeft = clampedIdx * dayWidth - container.clientWidth / 2 + dayWidth / 2;
      container.scrollLeft = Math.max(0, targetLeft);
      // Update window to reflect new center
      const newStart = Math.max(0, clampedIdx - 14 - OVERSCAN);
      const newEnd = Math.min(VIRTUAL_DAYS - 1, clampedIdx + 14 + OVERSCAN);
      setWindowRange({ startIdx: newStart, endIdx: newEnd });
    }, [date, getDayWidth]);

    // Scroll handler: update virtual window and idle fetch.
    // The header row now lives inside the scroll container, so it scrolls with
    // the body automatically and needs no separate scrollLeft sync.
    const handleScroll = useCallback(() => {
      const container = scrollContainerRef.current;
      if (!container) return;

      const dayWidth = getDayWidth();
      const startIdxRaw = Math.floor(container.scrollLeft / dayWidth) - OVERSCAN;
      const endIdxRaw =
        Math.ceil((container.scrollLeft + container.clientWidth) / dayWidth) + OVERSCAN;
      const startIdx = Math.max(0, startIdxRaw);
      const endIdx = Math.min(VIRTUAL_DAYS - 1, endIdxRaw);
      const prev = windowRangeRef.current;
      if (startIdx !== prev.startIdx || endIdx !== prev.endIdx) {
        setWindowRange({ startIdx, endIdx });
      }

      // Debounced idle: when user stops scrolling, report center date and request range
      if (idleTimeoutRef.current !== null) window.clearTimeout(idleTimeoutRef.current);
      idleTimeoutRef.current = window.setTimeout(() => {
        const c = scrollContainerRef.current;
        if (!c) return;
        const dw = getDayWidth();
        const centerIdx = Math.floor((c.scrollLeft + c.clientWidth / 2) / dw);
        const clampedCenter = Math.max(0, Math.min(VIRTUAL_DAYS - 1, centerIdx));
        const centerDate = moment(virtualStart).add(clampedCenter, "days").toDate();
        const centerKey = moment(centerDate).format("YYYY-MM-DD");
        if (centerKey !== lastCenterKeyRef.current) {
          lastCenterKeyRef.current = centerKey;
          onDateChangeRef.current?.(centerDate);
        }
        // Request range aligned to fixed timelineDays blocks (week-anchored),
        // so we only fetch when the center crosses a block boundary. This
        // matches the initial week-aligned window and avoids fetching on
        // every tiny scroll stop.
        const blockStart = moment(centerDate).startOf("week").toDate();
        const blockEnd = moment(blockStart)
          .add(timelineDays - 1, "days")
          .endOf("day")
          .toDate();
        onRangeRequestRef.current?.({ start: blockStart, end: blockEnd });
      }, SCROLL_IDLE_MS);
    }, [getDayWidth, timelineDays, virtualStart]);

    useEffect(() => {
      const container = scrollContainerRef.current;
      if (!container) return;
      container.addEventListener("scroll", handleScroll, { passive: true });
      // Initialize window from current scroll after layout
      handleScroll();
      return () => {
        container.removeEventListener("scroll", handleScroll);
        if (idleTimeoutRef.current !== null) window.clearTimeout(idleTimeoutRef.current);
      };
    }, [handleScroll]);

    // Visible window derived state
    const { startIdx, endIdx } = windowRange;
    const visibleDayCount = Math.max(1, endIdx - startIdx + 1);
    const visibleStart = useMemo(
      () => moment(virtualStart).add(startIdx, "days").startOf("day").toDate(),
      [virtualStart, startIdx],
    );
    const visibleEnd = useMemo(
      () => moment(virtualStart).add(endIdx, "days").endOf("day").toDate(),
      [virtualStart, endIdx],
    );
    const visibleBounds: CalendarRange = useMemo(
      () => ({ start: visibleStart, end: visibleEnd }),
      [visibleStart, visibleEnd],
    );

    const { lanes } = useMemo(
      () => buildTimelineLanes(events, visibleBounds, visibleDayCount),
      [events, visibleBounds, visibleDayCount],
    );
    const hasEvents = lanes.length > 0;

    const dayHeaders = useMemo(
      () => Array.from({ length: visibleDayCount }, (_, i) => moment(visibleStart).add(i, "days")),
      [visibleStart, visibleDayCount],
    );

    // Whether visible window is outside fetchedRange -> show skeleton
    const needsSkeleton = useMemo(() => {
      if (!fetchedRange) return false;
      const visStart = moment(visibleStart).startOf("day");
      const visEnd = moment(visibleEnd).endOf("day");
      const fetchStart = moment(fetchedRange.start).startOf("day");
      const fetchEnd = moment(fetchedRange.end).endOf("day");
      // If visible window not fully inside fetched range, data is missing for edges
      return visStart.isBefore(fetchStart) || visEnd.isAfter(fetchEnd);
    }, [visibleStart, visibleEnd, fetchedRange]);

    const visibleWidthStyle = `calc(var(--pc-timeline-day-width) * ${visibleDayCount})`;
    const offsetStyle = `calc(var(--pc-timeline-day-width) * ${startIdx})`;

    return (
      <div
        className={cn(timelineView, "wp-calendar-timeline-view")}
        style={{
          ["--pc-timeline-days" as string]: VIRTUAL_DAYS,
          ["--pc-timeline-day-width" as string]: "6.5rem",
        }}
      >
        <div
          className={cn(timelineScroll, "wp-calendar-timeline-scroll")}
          ref={scrollContainerRef}
          style={{ position: "relative" }}
        >
          {/* Spacer provides fixed virtual scroll width */}
          <div aria-hidden="true" style={{ width: totalWidthStyle, height: 1 }} />

          {/* Single continuous day-background strip spanning header + body, so the
              today/weekend stripe is continuous across the day labels and the
              columns below. Positions with the same absolute flex windowing as
              the (removed) per-region backgrounds. */}
          <div
            className={cn(
              "pointer-events-none absolute inset-y-0 flex",
              "wp-calendar-timeline-day-columns",
            )}
            aria-hidden="true"
            style={{ left: offsetStyle, width: visibleWidthStyle }}
          >
            {dayHeaders.map((day) => (
              <div
                key={`timeline-day-col-${day.format("YYYY-MM-DD")}`}
                className={cn(
                  timelineDayColumn,
                  "wp-calendar-timeline-day-column",
                  (day.day() === 0 || day.day() === 6) && "bg-muted",
                  day.isSame(moment(), "day") && todayStripe,
                )}
              />
            ))}
          </div>

          {/* Header labels + body lanes painted on top of the day strip */}
          <div
            className={cn(
              "absolute top-0 flex h-full min-h-0 flex-col",
              "wp-calendar-timeline-content",
            )}
            style={{ left: offsetStyle, width: visibleWidthStyle }}
          >
            <div className={cn(timelineHeader, "wp-calendar-timeline-header")} aria-hidden="true">
              {dayHeaders.map((day) => (
                <TimelineDayLabel
                  key={`timeline-day-${day.format("YYYY-MM-DD")}`}
                  day={day.toDate()}
                  _strings={strings}
                />
              ))}
            </div>

            <div
              className={cn(
                "relative min-h-0 flex-1",
                !hasEvents && "flex flex-col",
                "wp-calendar-timeline-body",
              )}
            >
              {isLoading && needsSkeleton ? (
                <TimelineSkeleton columns={Math.min(7, visibleDayCount)} />
              ) : hasEvents ? (
                lanes.map((lane, laneIndex) => (
                  <TimelineLaneComponent
                    key={`timeline-lane-${laneIndex}`}
                    lane={lane}
                    laneIndex={laneIndex}
                    _strings={strings}
                  />
                ))
              ) : (
                <div className={cn(timelineLane, "flex-1")} />
              )}
              {/* Inline skeleton when loading but window partially outside fetched range */}
              {isLoading && hasEvents && needsSkeleton && (
                <div style={{ opacity: 0.6 }}>
                  <TimelineSkeleton columns={3} />
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    );
  },
  {
    navigate: (date: Date, action: string, props?: { config?: AgendaViewConfig }): Date => {
      const step = resolveTimelineDays(props?.config?.timelineDays);
      switch (action) {
        case Navigate.PREVIOUS:
          return moment(date).subtract(step, "days").toDate();
        case Navigate.NEXT:
          return moment(date).add(step, "days").toDate();
        default:
          return date;
      }
    },
    range: (date: Date, props?: { config?: AgendaViewConfig }): CalendarRange => {
      return calculateTimelineVisibleRange(date, props?.config);
    },
    title: (date: Date, props?: { config?: AgendaViewConfig }): string => {
      const bounds = calculateTimelineVisibleRange(date, props?.config);
      return formatTimelineTitle(bounds);
    },
  },
);

export default AgendaTimelineView;
