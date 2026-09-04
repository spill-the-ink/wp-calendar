import { useMemo } from "react";
import { Navigate } from "react-big-calendar";
import moment from "moment";
import { cn } from "../../lib/utils.ts";
import type { CalendarEventRecord, CalendarRange } from "../../types.ts";
import { getRuntimeStrings } from "./calendar-state.ts";
import { eventLink, eventPill, eventPillColor, todayStripe } from "./styles.ts";

const MONTH_EVENT_LIMIT = 4;

const yearView =
  "flex min-h-[inherit] min-w-[52.5rem] flex-auto max-[720px]:min-h-0 max-[720px]:min-w-0";

const yearTable =
  "w-full table-fixed border-collapse border border-border h-full max-[720px]:block max-[720px]:h-auto max-[720px]:min-h-0 max-[720px]:w-full";

const yearTableBody =
  "table-row-group h-full max-[720px]:block max-[720px]:h-auto max-[720px]:min-h-0 max-[720px]:w-full";

const yearTableRow = "max-[720px]:block max-[720px]:h-auto max-[720px]:min-h-0 max-[720px]:w-full";

const yearCell =
  "bg-card p-0 align-top text-foreground w-1/4 border-l border-t border-border first:border-l-0 max-[960px]:w-1/2 max-[720px]:block max-[720px]:h-auto max-[720px]:min-h-0 max-[720px]:w-full max-[720px]:border-l-0";

const yearCellInner = "flex h-full min-h-[13rem] flex-col max-[720px]:min-h-0";

const yearMonthButton =
  "m-0 cursor-pointer border-0 bg-transparent p-0 text-right font-[inherit] text-base leading-5 text-muted-foreground hover:text-foreground hover:underline focus-visible:text-foreground focus-visible:underline";

const yearMoreButton =
  "m-0 cursor-pointer border-0 bg-transparent px-5 py-2.5 text-left font-[inherit] text-sm leading-5 text-muted-foreground hover:text-foreground hover:underline focus-visible:text-foreground focus-visible:underline";

interface YearMonthSummary {
  events: CalendarEventRecord[];
  isCurrentMonth: boolean;
  label: string;
  start: Date;
}

interface CalendarYearViewProps {
  date: Date;
  events: CalendarEventRecord[];
  onYearMonthSelect?: (date: Date) => void;
  strings?: {
    showMore?: string;
    showMoreEventsForMonth?: string;
  };
}

function formatYearMoreAriaLabel(template: string, count: number, monthLabel: string): string {
  return template.replace("%1$s", String(count)).replace("%2$s", monthLabel);
}

function buildYearRange(date: Date): CalendarRange {
  const start = moment(date).startOf("year");

  return {
    start: start.toDate(),
    end: start.clone().endOf("year").toDate(),
  };
}

function buildMonthSummaries(date: Date, events: CalendarEventRecord[]): YearMonthSummary[] {
  const activeYear = moment(date).year();
  const currentMonth = moment();

  const summaries = Array.from({ length: 12 }, (_, monthIndex) => {
    const monthStart = moment({ year: activeYear, month: monthIndex, day: 1 }).startOf("month");
    const monthEnd = monthStart.clone().endOf("month");
    const monthEvents = events
      .filter(
        (event) =>
          moment(event.scheduled_start_time).isSameOrBefore(monthEnd) &&
          moment(event.scheduled_end_time).isSameOrAfter(monthStart),
      )
      .sort(
        (left, right) => left.scheduled_start_time.getTime() - right.scheduled_start_time.getTime(),
      );

    return {
      events: monthEvents,
      isCurrentMonth: currentMonth.isSame(monthStart, "month"),
      label: monthStart.format("MMMM"),
      start: monthStart.toDate(),
    };
  });

  return summaries;
}

function chunkMonths(months: YearMonthSummary[], chunkSize: number): YearMonthSummary[][] {
  return months.reduce<YearMonthSummary[][]>((rows, month, monthIndex) => {
    const rowIndex = Math.floor(monthIndex / chunkSize);

    if (!rows[rowIndex]) {
      rows[rowIndex] = [];
    }

    rows[rowIndex].push(month);

    return rows;
  }, []);
}

const CalendarYearView = Object.assign(
  function CalendarYearView({
    date,
    events,
    onYearMonthSelect,
    strings: runtimeStrings,
  }: CalendarYearViewProps) {
    const months = useMemo(() => buildMonthSummaries(date, events), [date, events]);
    const monthRows = useMemo(() => chunkMonths(months, 4), [months]);
    const strings = getRuntimeStrings({ strings: runtimeStrings });

    return (
      <div className={cn(yearView, "wp-calendar-year-view")}>
        <table className={cn(yearTable, "wp-calendar-year-table")}>
          <tbody className={yearTableBody}>
            {monthRows.map((row, rowIndex) => (
              <tr key={`year-row-${rowIndex}`} className={yearTableRow}>
                {row.map((month) => {
                  const visibleEvents = month.events.slice(0, MONTH_EVENT_LIMIT);
                  const hiddenCount = month.events.length - visibleEvents.length;

                  return (
                    <td
                      key={month.start.toISOString()}
                      className={cn(
                        yearCell,
                        "wp-calendar-year-cell",
                        rowIndex === 0 && "border-t-0",
                      )}
                    >
                      <div className={yearCellInner}>
                        <div className="flex min-h-10 items-center justify-between border-b-2 border-border px-5 py-0">
                          <button
                            type="button"
                            className={yearMonthButton}
                            onClick={() => onYearMonthSelect?.(month.start)}
                            aria-label={`${month.label} ${moment(month.start).format("YYYY")}`}
                          >
                            {month.label}
                          </button>
                        </div>

                        <div
                          className={cn(
                            "flex min-h-40 flex-auto flex-col gap-y-1 py-3",
                            month.isCurrentMonth && todayStripe,
                          )}
                          role="list"
                        >
                          {visibleEvents.map((event) => {
                            const eventColor = event.label?.color;
                            const labelStyle = eventColor
                              ? ({
                                  borderLeftColor: eventColor,
                                  ["--pc-event-color" as string]: eventColor,
                                } as React.CSSProperties)
                              : undefined;
                            return (
                              <span
                                key={String(event.id)}
                                className={cn(
                                  eventPill,
                                  eventColor && eventPillColor,
                                  "wp-calendar-event-pill",
                                )}
                                style={labelStyle}
                                role="listitem"
                              >
                                {event.url ? (
                                  <a
                                    href={event.url}
                                    className={cn(eventLink, "wp-calendar-event-link")}
                                  >
                                    {event.name}
                                  </a>
                                ) : (
                                  <span>{event.name}</span>
                                )}
                              </span>
                            );
                          })}

                          {hiddenCount > 0 ? (
                            <button
                              type="button"
                              className={yearMoreButton}
                              onClick={() => onYearMonthSelect?.(month.start)}
                              aria-label={formatYearMoreAriaLabel(
                                strings.showMoreEventsForMonth,
                                hiddenCount,
                                month.label,
                              )}
                            >
                              +{hiddenCount} {strings.showMore}
                            </button>
                          ) : null}
                        </div>
                      </div>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  },
  {
    navigate: (date: Date, action: string): Date => {
      switch (action) {
        case Navigate.PREVIOUS:
          return moment(date).subtract(1, "year").toDate();
        case Navigate.NEXT:
          return moment(date).add(1, "year").toDate();
        default:
          return date;
      }
    },
    range: (date: Date): CalendarRange => buildYearRange(date),
    title: (date: Date): string => moment(date).format("YYYY"),
  },
);

export default CalendarYearView;
