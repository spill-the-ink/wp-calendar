import moment from "moment";
import { Navigate } from "react-big-calendar";
import { cn } from "../../lib/utils.ts";
import type { CalendarEventRecord, CalendarRange, CalendarRuntimeStrings } from "../../types.ts";
import {
  type AgendaViewConfig,
  AGENDA_DEFAULTS,
  agendaRangeModes,
  calculateAgendaWindow,
  normalizeAgendaRangeMode,
  normalizePositiveInteger,
} from "./calendar-state.ts";
import { eventSourceBadge, eventSourceBadgeDc, eventSourceBadgeIcal } from "./styles.ts";

const agendaView = "min-h-96 max-h-[calc(100dvh_-_8rem)] overflow-y-auto";

const agendaSectionHeader =
  "sticky top-0 z-[1] flex items-baseline justify-between gap-4 border-b border-border bg-card px-5 py-2.5";

const agendaLoadMore =
  "cursor-pointer rounded-sm border border-border bg-card px-4 py-2 text-sm leading-5 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:text-foreground disabled:cursor-not-allowed disabled:opacity-55";

const skeletonRow = "flex items-center gap-3 border-b border-border px-4 py-2";

const skeletonTime = "h-3 w-24 rounded bg-muted";

const skeletonEvent = "h-3 flex-1 rounded bg-muted";

interface AgendaViewProps {
  date: Date;
  events: CalendarEventRecord[];
  loadedRange: CalendarRange;
  config: AgendaViewConfig;
  strings: Required<CalendarRuntimeStrings>;
  onLoadMore?: () => void;
  isLoading?: boolean;
}

type SectionKey = "today" | "tomorrow" | "this-week" | "next-week" | "later";

interface AgendaSection {
  key: SectionKey;
  events: CalendarEventRecord[];
}

const SECTION_ORDER: SectionKey[] = ["today", "tomorrow", "this-week", "next-week", "later"];

function getSectionKeyForDay(dayStart: moment.Moment): SectionKey | null {
  const now = moment().startOf("day");
  if (dayStart.isBefore(now)) return null;
  if (dayStart.isSame(now, "day")) return "today";
  if (dayStart.diff(now, "days") === 1) return "tomorrow";

  const todayEndOfWeek = now.clone().endOf("week");
  if (dayStart.isSameOrBefore(todayEndOfWeek)) return "this-week";

  const nextWeekStart = todayEndOfWeek.clone().add(1, "day").startOf("day");
  const nextWeekEnd = nextWeekStart.clone().endOf("week");
  if (dayStart.isSameOrBefore(nextWeekEnd)) return "next-week";

  return "later";
}

function sectionSubLabel(key: SectionKey, events: CalendarEventRecord[]): string {
  if (events.length === 0) return "";
  const firstStart = moment(events[0].scheduled_start_time);
  const lastStart = moment(events[events.length - 1].scheduled_start_time);

  if (key === "this-week" || key === "next-week") {
    const startOfWeek = firstStart.clone().startOf("week");
    const endOfWeek = lastStart.clone().endOf("week");
    return `${startOfWeek.format("MMM D")} – ${endOfWeek.format("MMM D, YYYY")}`;
  }

  return firstStart.format("dddd, MMMM D");
}

function buildSections(events: CalendarEventRecord[], loadedRange: CalendarRange): AgendaSection[] {
  const now = moment().startOf("day");
  const loadedEndDay = moment(loadedRange.end).startOf("day");
  const sections: AgendaSection[] = SECTION_ORDER.map((key) => ({ key, events: [] }));

  const visible = events
    .filter((event) => {
      const startDay = moment(event.scheduled_start_time).startOf("day");
      const endDay = moment(event.scheduled_end_time).startOf("day");
      // Only events that reach today (or later) and start before the loaded end.
      return endDay.isSameOrAfter(now) && startDay.isSameOrBefore(loadedEndDay);
    })
    .map((event) => {
      const startDay = moment(event.scheduled_start_time).startOf("day");
      const anchor = startDay.isBefore(now) ? now : startDay;
      return { event, anchor };
    })
    .sort((a, b) => {
      const byDay = a.anchor.diff(b.anchor, "days");
      if (byDay !== 0) return byDay;
      if (a.event.allDay !== b.event.allDay) return a.event.allDay ? -1 : 1;
      return a.event.scheduled_start_time.getTime() - b.event.scheduled_start_time.getTime();
    });

  for (const { event, anchor } of visible) {
    const key = getSectionKeyForDay(anchor);
    if (!key) continue;
    const section = sections.find((s) => s.key === key);
    if (section) {
      section.events.push(event);
    }
  }

  return sections.filter((section) => section.events.length > 0);
}

function sourceBadgeFor(source: CalendarEventRecord["source"]): string | null {
  const type = source?.type;
  return type === "ical" ? "ical" : type === "discord" ? "dc" : null;
}

function AgendaEventRow({
  event,
  strings,
}: {
  event: CalendarEventRecord;
  strings: Required<CalendarRuntimeStrings>;
}) {
  const sourceBadge = sourceBadgeFor(event.source);
  const isPast = moment(event.scheduled_end_time).isBefore(moment());
  const timeLabel = event.allDay
    ? strings.allDay
    : `${moment(event.scheduled_start_time).format("h:mm A")} – ${moment(event.scheduled_end_time).format("h:mm A")}`;

  return (
    <li
      className={cn(
        "flex items-start gap-3 border-b border-border px-5 py-2.5",
        "wp-calendar-agenda-row",
        sourceBadge && `wp-calendar-agenda-row--${sourceBadge}`,
        isPast && "opacity-55",
      )}
    >
      <span
        className="flex-[0_0_3px] self-stretch rounded-sm border-l-[3px] border-solid"
        aria-hidden="true"
        style={{ borderLeftColor: event.label?.color ?? "var(--primary)" }}
      />
      <div className="min-w-0 flex-1">
        <span className="mr-2 inline-block whitespace-nowrap text-sm leading-5 text-muted-foreground">
          {timeLabel}
        </span>
        {event.url ? (
          <a href={event.url} className="text-sm font-medium leading-5 text-foreground">
            {sourceBadge && (
              <span
                className={cn(
                  eventSourceBadge,
                  sourceBadge === "ical" ? eventSourceBadgeIcal : eventSourceBadgeDc,
                )}
              >
                {sourceBadge}
              </span>
            )}
            {event.name}
          </a>
        ) : (
          <span className="text-sm font-medium leading-5 text-foreground">
            {sourceBadge && (
              <span
                className={cn(
                  eventSourceBadge,
                  sourceBadge === "ical" ? eventSourceBadgeIcal : eventSourceBadgeDc,
                )}
              >
                {sourceBadge}
              </span>
            )}
            {event.name}
          </span>
        )}

        {event.description ? (
          <p className="m-0 text-sm leading-5 text-muted-foreground">{event.description}</p>
        ) : null}

        {event.location ? (
          <p className="m-0 text-sm leading-5 text-muted-foreground">
            {event.location_url ? (
              <a href={event.location_url} target="_blank" rel="noopener noreferrer">
                {event.location}
              </a>
            ) : (
              event.location
            )}
          </p>
        ) : null}
      </div>

      {event.source?.name && (
        <span className="whitespace-nowrap text-right text-sm leading-5 text-muted-foreground">
          {event.source.name}
        </span>
      )}
    </li>
  );
}

function AgendaSkeleton({ rows = 4 }: { rows?: number }) {
  return (
    <div
      className={cn("animate-pulse", "wp-calendar-agenda-skeleton")}
      role="status"
      aria-hidden="true"
    >
      {Array.from({ length: rows }, (_, i) => (
        <div className={skeletonRow} key={`agenda-sk-${i}`}>
          <div className={skeletonTime} />
          <div className={skeletonEvent} />
        </div>
      ))}
    </div>
  );
}

function calculateAgendaVisibleRange(date: Date, config?: AgendaViewConfig): CalendarRange {
  const rangeMode = normalizeAgendaRangeMode(config?.rangeMode);
  if (rangeMode === agendaRangeModes.UPCOMING_WINDOW) {
    const rangeMonths = normalizePositiveInteger(config?.rangeMonths, AGENDA_DEFAULTS.rangeMonths);
    const window = calculateAgendaWindow(date, rangeMonths);
    return { start: window.start, end: window.end };
  }

  const dayStart = moment(date).startOf("day");
  return {
    start: dayStart.toDate(),
    end: dayStart
      .clone()
      .add(AGENDA_DEFAULTS.agendaDefaultDays - 1, "days")
      .endOf("day")
      .toDate(),
  };
}

function formatAgendaTitle(bounds: CalendarRange): string {
  return `${moment(bounds.start).format("MMMM D")} - ${moment(bounds.end).format("MMMM D, YYYY")}`;
}

const AgendaView = Object.assign(
  function AgendaViewComponent({
    events,
    loadedRange,
    strings,
    onLoadMore,
    isLoading,
  }: AgendaViewProps) {
    const sections = buildSections(events, loadedRange);
    const hasEvents = sections.length > 0;

    return (
      <div className={cn(agendaView, "flex flex-col", "wp-calendar-agenda-feed")}>
        {sections.map((section) => (
          <section key={section.key} className="wp-calendar-agenda-section">
            <header className={agendaSectionHeader}>
              <span className="text-base leading-5 text-foreground">
                {
                  {
                    today: strings.today,
                    tomorrow: strings.tomorrow,
                    "this-week": strings.thisWeek,
                    "next-week": strings.nextWeek,
                    later: strings.later,
                  }[section.key]
                }
              </span>
              <span className="text-sm leading-5 text-muted-foreground">
                {sectionSubLabel(section.key, section.events)}
              </span>
            </header>
            <ul className="m-0 list-none p-0">
              {section.events.map((event, eventIndex) => (
                <AgendaEventRow
                  key={`agenda-row-${String(event.id)}-${eventIndex}`}
                  event={event}
                  strings={strings}
                />
              ))}
            </ul>
          </section>
        ))}

        {isLoading && <AgendaSkeleton />}

        {!hasEvents && !isLoading && (
          <div className="min-h-32 p-5 text-muted-foreground">
            <p>{strings.noEvents}</p>
          </div>
        )}

        {onLoadMore && (
          <div className="flex justify-center p-4">
            <button
              type="button"
              className={agendaLoadMore}
              onClick={onLoadMore}
              disabled={isLoading}
            >
              {isLoading ? strings.loading : strings.showMore}
            </button>
          </div>
        )}
      </div>
    );
  },
  {
    navigate: (date: Date, action: string, config?: { config?: AgendaViewConfig }): Date => {
      const rangeMode = normalizeAgendaRangeMode(config?.config?.rangeMode);
      const rangeMonths = normalizePositiveInteger(
        config?.config?.rangeMonths,
        AGENDA_DEFAULTS.rangeMonths,
      );

      if (rangeMode === agendaRangeModes.UPCOMING_WINDOW) {
        const step = Math.max(1, Math.floor((rangeMonths * 30) / 2));
        switch (action) {
          case Navigate.PREVIOUS:
            return moment(date).subtract(step, "days").toDate();
          case Navigate.NEXT:
            return moment(date).add(step, "days").toDate();
          default:
            return date;
        }
      }

      switch (action) {
        case Navigate.PREVIOUS:
          return moment(date).subtract(AGENDA_DEFAULTS.agendaDefaultDays, "days").toDate();
        case Navigate.NEXT:
          return moment(date).add(AGENDA_DEFAULTS.agendaDefaultDays, "days").toDate();
        default:
          return date;
      }
    },
    range: (date: Date, config?: { config?: AgendaViewConfig }): CalendarRange => {
      return calculateAgendaVisibleRange(date, config?.config);
    },
    title: (date: Date, config?: { config?: AgendaViewConfig }): string => {
      const bounds = calculateAgendaVisibleRange(date, config?.config);
      return formatAgendaTitle(bounds);
    },
  },
);

export default AgendaView;
