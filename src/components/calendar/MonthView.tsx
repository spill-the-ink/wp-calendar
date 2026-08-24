import { Navigate } from "react-big-calendar";
import moment from "moment";
import { cn } from "../../lib/utils.ts";
import type { CalendarEventRecord, CalendarRange, CalendarRuntimeStrings } from "../../types.ts";
import { getRuntimeStrings } from "./calendar-state.ts";
import { eventLink, eventPill, eventPillColor, todayStripe } from "./styles.ts";

/**
 * Custom month grid.
 *
 * Unlike react-big-calendar's built-in month view, every week row grows to
 * list ALL of its events — there is no "+n more" truncation. Event labels
 * adapt to available space via container queries (title -> dot).
 */

const pillTime = "mr-1 text-muted-foreground";

const monthCell =
  "align-top cursor-default border-l border-t border-border first:border-l-0 @container";

const monthCellInner =
  "flex max-h-72 min-h-35 flex-col gap-1 overflow-x-hidden overflow-y-auto p-0 [scrollbar-gutter:stable]";

const monthCellHeader = "sticky top-0 z-[1] flex justify-end px-1.5 pb-1 pt-1.5";

const monthDayNumber =
  "min-h-6 min-w-6 cursor-pointer rounded-sm border-0 bg-transparent px-1.5 py-[0.125rem] text-sm font-[inherit] leading-[1.2] text-muted-foreground";

interface MonthCellEvent {
  event: CalendarEventRecord;
  isAllDay: boolean;
}

interface MonthDay {
  date: Date;
  events: MonthCellEvent[];
  isCurrentMonth: boolean;
  isToday: boolean;
}

interface CalendarMonthViewProps {
  date: Date;
  events: CalendarEventRecord[];
  onDaySelect?: (date: Date) => void;
  strings?: CalendarRuntimeStrings;
}

function buildMonthRange(date: Date): CalendarRange {
  const start = moment(date).startOf("month").startOf("week");
  const end = moment(date).endOf("month").endOf("week");

  return {
    start: start.toDate(),
    end: end.toDate(),
  };
}

function buildMonthDays(date: Date, events: CalendarEventRecord[]): MonthDay[][] {
  const monthStart = moment(date).startOf("month");
  const monthEnd = moment(date).endOf("month");
  const gridStart = monthStart.clone().startOf("week");
  const gridEnd = monthEnd.clone().endOf("week");

  const weeks: MonthDay[][] = [];
  const cursor = gridStart.clone();
  const today = moment();

  while (!cursor.isAfter(gridEnd, "day")) {
    const week: MonthDay[] = [];

    for (let dayIndex = 0; dayIndex < 7; ++dayIndex) {
      const dayStart = cursor.clone().startOf("day");
      const dayEnd = cursor.clone().endOf("day");

      const dayEvents = events
        .filter(
          (event) =>
            moment(event.scheduled_start_time).isSameOrBefore(dayEnd) &&
            moment(event.scheduled_end_time).isSameOrAfter(dayStart),
        )
        .sort((left, right) => {
          if (left.allDay !== right.allDay) {
            return left.allDay ? -1 : 1;
          }

          return left.scheduled_start_time.getTime() - right.scheduled_start_time.getTime();
        })
        .map((event) => ({ event, isAllDay: event.allDay }));

      week.push({
        date: dayStart.toDate(),
        events: dayEvents,
        isCurrentMonth: cursor.isSame(moment(date), "month"),
        isToday: today.isSame(cursor, "day"),
      });

      cursor.add(1, "day");
    }

    weeks.push(week);
  }

  return weeks;
}

function formatEventTime(event: CalendarEventRecord): string {
  if (event.allDay) {
    return "";
  }

  return moment(event.scheduled_start_time).format("H:mm ");
}

const CalendarMonthView = Object.assign(
  function CalendarMonthView({
    date,
    events,
    onDaySelect,
    strings: runtimeStrings,
  }: CalendarMonthViewProps) {
    const strings = getRuntimeStrings({ strings: runtimeStrings });
    const weeks = buildMonthDays(date, events);
    const weekdayLabels = Array.from({ length: 7 }, (_, index) =>
      moment().startOf("week").add(index, "days").format("ddd"),
    );

    return (
      <div className={cn("min-h-max", "post-calendar-month-view")}>
        <table
          className={cn(
            "min-w-[640px] w-full table-fixed border-collapse border border-border",
            "post-calendar-month-table",
          )}
        >
          <thead>
            <tr>
              {weekdayLabels.map((label) => (
                <th
                  key={label}
                  scope="col"
                  className="border-b-2 border-border px-2 py-1.5 text-left text-sm font-medium text-muted-foreground"
                >
                  {label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {weeks.map((week, weekIndex) => (
              <tr key={`month-week-${weekIndex}`} className="post-calendar-month-row">
                {week.map((day) => {
                  const dayKey = moment(day.date).format("YYYY-MM-DD");

                  return (
                    <td
                      key={dayKey}
                      className={cn(
                        monthCell,
                        "post-calendar-month-cell",
                        !day.isCurrentMonth && "bg-muted",
                        day.isToday && todayStripe,
                      )}
                    >
                      <div className={monthCellInner}>
                        <div className={monthCellHeader}>
                          <button
                            type="button"
                            className={monthDayNumber}
                            onClick={() => onDaySelect?.(day.date)}
                            aria-label={`${moment(day.date).format("LL")}, ${day.events.length} events`}
                          >
                            {moment(day.date).date()}
                          </button>
                        </div>

                        <div className="flex flex-col gap-[3px] px-1.5 pb-1.5" role="list">
                          {day.events.map(({ event, isAllDay }) => {
                            const eventColor = event.label?.color;
                            const labelStyle = eventColor
                              ? ({
                                  borderLeftColor: eventColor,
                                  ["--pc-event-color" as string]: eventColor,
                                } as React.CSSProperties)
                              : undefined;
                            return (
                              <span
                                key={`${String(event.id)}-${dayKey}`}
                                className={cn(
                                  eventPill,
                                  eventColor && eventPillColor,
                                  "flex-none max-w-full min-w-0",
                                  "post-calendar-event-pill",
                                )}
                                style={labelStyle}
                                role="listitem"
                              >
                                {event.url ? (
                                  <a
                                    href={event.url}
                                    className={cn(eventLink, "post-calendar-event-link")}
                                    title={`${isAllDay ? `${strings.allDay} · ` : formatEventTime(event)}${event.name}`}
                                  >
                                    {!isAllDay && (
                                      <span className={cn(pillTime, "post-calendar-pill-time")}>
                                        {formatEventTime(event)}
                                      </span>
                                    )}
                                    <span className="post-calendar-pill-title">{event.name}</span>
                                  </a>
                                ) : (
                                  <>
                                    {!isAllDay && (
                                      <span className={cn(pillTime, "post-calendar-pill-time")}>
                                        {formatEventTime(event)}
                                      </span>
                                    )}
                                    <span className="post-calendar-pill-title">{event.name}</span>
                                  </>
                                )}
                              </span>
                            );
                          })}
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
          return moment(date).subtract(1, "month").toDate();
        case Navigate.NEXT:
          return moment(date).add(1, "month").toDate();
        default:
          return date;
      }
    },
    range: (date: Date): CalendarRange => buildMonthRange(date),
    title: (date: Date): string => moment(date).format("MMMM YYYY"),
  },
);

export default CalendarMonthView;
