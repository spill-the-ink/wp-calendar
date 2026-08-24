import {
  createContext,
  useContext,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { Calendar, Views, momentLocalizer } from "react-big-calendar";
import { ChevronDown } from "lucide-react";
import moment from "moment";
import type {
  CalendarConfig,
  CalendarEventRecord,
  CalendarRange,
  CalendarRuntime,
  CalendarRuntimeStrings,
} from "../../types.ts";
import {
  getEventClassName,
  getRuntimeStrings,
  isNarrowContainer,
  normalizeView,
  TIMELINE_VIEW,
  YEAR_VIEW,
  type CalendarRangeInput,
  type CalendarView,
  useCalendarLocale,
  useCalendarState,
} from "./calendar-state.ts";
import type { AgendaViewConfig } from "./calendar-state.ts";
import CalendarMonthView from "./MonthView.tsx";
import AgendaView from "./AgendaView.tsx";
import AgendaTimelineView from "./AgendaTimelineView.tsx";
import CalendarYearView from "./YearView.tsx";
import { cn } from "../../lib/utils.ts";
import {
  eventLink,
  eventPill,
  eventPillColor,
  eventSourceBadge,
  eventSourceBadgeDc,
  eventSourceBadgeIcal,
} from "./styles.ts";

const localizer = momentLocalizer(moment);

const focusRing =
  "focus-visible:outline-none focus-visible:outline-2 focus-visible:outline-primary focus-visible:outline-offset-2";

/* -- Toolbar ----------------------------------------------------- */

const toolbar =
  "flex min-h-[2.75rem] flex-wrap items-center justify-between gap-3 py-2 max-[720px]:grid max-[720px]:grid-cols-1";

const toolbarLabelWrap =
  "relative min-w-0 flex-auto max-[720px]:-order-1 max-[720px]:justify-start";

const toolbarLabelButton = `m-0 inline-flex w-full cursor-pointer items-center justify-center gap-1.5 whitespace-normal rounded-sm border-0 bg-transparent px-3 py-1.5 font-[inherit] text-base font-normal leading-5 text-foreground transition-colors hover:bg-muted ${focusRing}`;

const toolbarButton = `flex min-h-9 min-w-9 cursor-pointer items-center justify-center gap-1.5 whitespace-nowrap rounded-sm border-0 bg-transparent px-3 py-1.5 font-[inherit] text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:text-foreground disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-40 ${focusRing}`;

const toolbarButtonNav = "gap-1 px-2";

const toolbarButtonDesktopText = "hidden sm:inline";

/* -- Date picker ------------------------------------------------ */

const datePickerPopover =
  "absolute left-0 right-0 top-full z-[100] mt-1.5 rounded-xl border border-border bg-card p-1.5 shadow-lg";

const datePickerInput =
  "min-w-48 w-full border-0 bg-transparent py-1 font-[inherit] text-sm text-foreground outline-none [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-70 [&::-webkit-calendar-picker-indicator]:[filter:invert(0.5)] [&::-webkit-calendar-picker-indicator]:hover:opacity-100";

/* -- Views ------------------------------------------------------- */

const viewButton = `inline-flex min-h-9 cursor-pointer items-center justify-center gap-1.5 whitespace-nowrap rounded-sm border-0 bg-transparent px-3 py-1.5 font-[inherit] text-sm font-medium text-muted-foreground transition-colors hover:text-foreground ${focusRing}`;

const dropdownTrigger = `inline-flex min-h-9 min-w-32 cursor-pointer items-center justify-between gap-2 rounded-sm border border-border bg-transparent px-3 py-1.5 font-[inherit] text-sm font-medium text-foreground transition-colors hover:bg-muted ${focusRing}`;

const dropdownList =
  "absolute right-0 top-full z-[100] m-1.5 min-w-full list-none rounded-xl border border-border bg-card p-1 shadow-lg";

const dropdownItem =
  "relative flex cursor-default select-none items-center rounded-sm px-2 py-1.5 text-sm outline-none transition-colors hover:bg-muted focus:bg-muted";

/* -- React big calendar overrides -------------------------------- */

const columnLabel = "text-base leading-5 text-muted-foreground";

interface CalendarAppProps {
  config: CalendarConfig;
  runtime: CalendarRuntime;
}

interface CalendarEventProps {
  event: CalendarEventRecord;
}

interface MonthHeaderProps {
  label: string;
}

interface WeekHeaderProps {
  date: Date;
}

interface CalendarToolbarProps {
  culture?: string;
  currentDate: Date;
  isNarrow?: boolean;
  label: string;
  onDateJump: (dateString: string) => void;
  onNavigate: (action: string) => void;
  onView: (view: CalendarView) => void;
  showDateJump: boolean;
  showToolbarActions: boolean;
  showToolbarLabel: boolean;
  showViewMenu: boolean;
  strings: Required<CalendarRuntimeStrings>;
  view: CalendarView;
  views: readonly CalendarView[];
}

function getToolbarLabel(
  label: string,
  view: CalendarView,
  currentDate: Date,
  culture?: string,
): string {
  switch (view) {
    case Views.DAY:
      return localizer.format(currentDate, "MMMM D, YYYY", culture);
    case Views.WEEK:
      return `${localizer.format(moment(currentDate).startOf("week").toDate(), "MMM D", culture)} \u2013 ${localizer.format(moment(currentDate).endOf("week").toDate(), "MMM D, YYYY", culture)}`;
    case TIMELINE_VIEW:
      return `${localizer.format(moment(currentDate).startOf("week").toDate(), "MMM D", culture)} \u2013 ${localizer.format(moment(currentDate).clone().add(13, "days").endOf("day").toDate(), "MMM D, YYYY", culture)}`;
    case Views.MONTH:
      return localizer.format(currentDate, "MMMM YYYY", culture);
    case YEAR_VIEW:
      return localizer.format(currentDate, "YYYY", culture);
    case Views.AGENDA:
      return localizer.format(currentDate, "MMMM YYYY", culture);
    default:
      return label;
  }
}

function getViewLabels(strings: Required<CalendarRuntimeStrings>): Record<CalendarView, string> {
  return {
    [Views.MONTH]: strings.month,
    [Views.WEEK]: strings.week,
    [TIMELINE_VIEW]: strings.timeline ?? strings.agenda,
    [Views.DAY]: strings.day,
    [Views.AGENDA]: strings.agenda,
    [YEAR_VIEW]: strings.year,
  };
}

// Runtime data for the timeline view. This is provided via context so the
// timeline view component can keep a STABLE identity (react-big-calendar
// unmounts+remounts a custom view whenever its component type changes, which
// would reset the virtual scroll position on every data load). The provider
// re-renders on each app render, so consumers always read fresh values without
// remounting.
interface TimelineViewContextValue {
  strings: Required<CalendarRuntimeStrings>;
  config: AgendaViewConfig;
  fetchedRange?: CalendarRange;
  isLoading?: boolean;
  onDateChange?: (date: Date) => void;
  onRangeRequest?: (range: CalendarRange) => void;
}

const TimelineViewContext = createContext<TimelineViewContextValue | null>(null);

function useTimelineViewContext(): TimelineViewContextValue {
  const value = useContext(TimelineViewContext);
  if (!value) {
    throw new Error("TimelineViewContext must be used within a TimelineViewContextProvider");
  }
  return value;
}

function TimelineViewContextProvider({
  value,
  children,
}: {
  value: TimelineViewContextValue;
  children: React.ReactNode;
}) {
  return <TimelineViewContext.Provider value={value}>{children}</TimelineViewContext.Provider>;
}

// Stable wrapper consumed by react-big-calendar as the timeline view. It reads
// the latest runtime values from context so its identity never changes with
// data-loading state.
function LocalizedAgendaTimelineView(props: { date: Date; events: CalendarEventRecord[] }) {
  const { strings, config, fetchedRange, isLoading, onDateChange, onRangeRequest } =
    useTimelineViewContext();
  return (
    <AgendaTimelineView
      {...props}
      strings={strings}
      config={config}
      fetchedRange={fetchedRange}
      isLoading={isLoading}
      onDateChange={onDateChange}
      onRangeRequest={onRangeRequest}
    />
  );
}

const localizedAgendaTimelineView = Object.assign(LocalizedAgendaTimelineView, {
  navigate: AgendaTimelineView.navigate,
  range: AgendaTimelineView.range,
  title: AgendaTimelineView.title,
});

function CalendarEvent({ event }: CalendarEventProps) {
  const sourceType = event.source?.type;
  const sourceBadge = sourceType === "ical" ? "ical" : sourceType === "discord" ? "dc" : null;
  const eventColor = event.label?.color;
  const labelStyle = eventColor
    ? ({
        borderLeftColor: eventColor,
        ["--pc-event-color" as string]: eventColor,
      } as React.CSSProperties)
    : undefined;

  return (
    <span
      className={cn(
        eventPill,
        eventColor && eventPillColor,
        "post-calendar-event-pill",
        sourceBadge && `post-calendar-event-pill--${sourceBadge}`,
        "flex-none",
      )}
      style={labelStyle}
    >
      {event.url ? (
        <a href={event.url} className={cn(eventLink, "post-calendar-event-link")}>
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
        <span>
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
    </span>
  );
}

function MonthHeader({ label }: MonthHeaderProps) {
  return <span className={columnLabel}>{label}</span>;
}

function WeekHeader({ date }: WeekHeaderProps) {
  return (
    <div className="flex w-full items-center justify-between">
      <span className={columnLabel}>{moment(date).format("ddd")}</span>
      <span className="text-sm leading-5 text-muted-foreground">{moment(date).format("DD")}</span>
    </div>
  );
}

function TimeGutterHeader({ label }: { label: string }) {
  return <span className="text-sm leading-5 text-muted-foreground">{label}</span>;
}

function CalendarToolbar({
  culture,
  currentDate,
  isNarrow: isNarrowProp,
  label,
  onDateJump,
  onNavigate,
  onView,
  showDateJump,
  showToolbarActions,
  showToolbarLabel,
  showViewMenu,
  strings,
  view,
  views,
}: CalendarToolbarProps) {
  const viewLabels = getViewLabels(strings);
  const toolbarLabel = getToolbarLabel(label, view, currentDate, culture);
  const todayDateValue = moment().format("MMM D");
  const isToday = moment(currentDate).isSame(moment(), "day");

  const [viewsDropdownOpen, setViewsDropdownOpen] = useState(false);
  const [focusedViewIndex, setFocusedViewIndex] = useState(views.findIndex((v) => v === view));
  const [datePickerOpen, setDatePickerOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const datePickerRef = useRef<HTMLDivElement>(null);
  const toolbarRef = useRef<HTMLDivElement>(null);

  // View-specific date input configuration
  const dateInputConfig = getDateInputConfig(view, currentDate);

  function handleDateChange(event: React.ChangeEvent<HTMLInputElement>) {
    if (event.target.value) {
      onDateJump(event.target.value);
      setDatePickerOpen(false);
    }
  }

  // Keep focused index in sync when view changes externally (popstate, etc.)
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setFocusedViewIndex(views.findIndex((v) => v === view));
  }, [views, view]);

  // Scoped keyboard navigation for view tabs — only when toolbar has focus
  useEffect(() => {
    const el = toolbarRef.current;
    if (!el || !showViewMenu || viewsDropdownOpen) return;

    function handleKeyDown(event: KeyboardEvent) {
      // Only handle when focus is inside toolbar's view tabs
      const target = event.target as HTMLElement | null;
      if (!target || !el.contains(target)) return;
      if (!target.closest(".post-calendar-views-inline")) return;

      const index = views.findIndex((v) => v === view);
      if (index === -1) return;

      switch (event.key) {
        case "ArrowRight":
          event.preventDefault();
          setFocusedViewIndex((index + 1) % views.length);
          break;
        case "ArrowLeft":
          event.preventDefault();
          setFocusedViewIndex((index - 1 + views.length) % views.length);
          break;
        case "Home":
          event.preventDefault();
          setFocusedViewIndex(0);
          break;
        case "End":
          event.preventDefault();
          setFocusedViewIndex(views.length - 1);
          break;
        case "Enter":
        case " ":
          event.preventDefault();
          onView(views[focusedViewIndex]);
          break;
      }
    }

    el.addEventListener("keydown", handleKeyDown);
    return () => el.removeEventListener("keydown", handleKeyDown);
  }, [showViewMenu, views, view, onView, viewsDropdownOpen, focusedViewIndex]);

  useEffect(() => {
    if (viewsDropdownOpen) {
      function handleClickOutside(event: MouseEvent) {
        if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
          setViewsDropdownOpen(false);
        }
      }
      document.addEventListener("mousedown", handleClickOutside);
      return () => document.removeEventListener("mousedown", handleClickOutside);
    }
  }, [viewsDropdownOpen]);

  useEffect(() => {
    if (datePickerOpen) {
      function handleClickOutside(event: MouseEvent) {
        if (datePickerRef.current && !datePickerRef.current.contains(event.target as Node)) {
          setDatePickerOpen(false);
        }
      }
      document.addEventListener("mousedown", handleClickOutside);
      return () => document.removeEventListener("mousedown", handleClickOutside);
    }
  }, [datePickerOpen]);

  // Also close date picker on Escape
  useEffect(() => {
    if (!datePickerOpen) return;
    function handleEsc(event: KeyboardEvent) {
      if (event.key === "Escape") setDatePickerOpen(false);
    }
    document.addEventListener("keydown", handleEsc);
    return () => document.removeEventListener("keydown", handleEsc);
  }, [datePickerOpen]);

  if (!showToolbarActions && !showToolbarLabel && !showViewMenu) {
    return null;
  }

  const isNarrow = isNarrowProp ?? views.length > 4;

  return (
    <div className={cn(toolbar, "post-calendar-toolbar")} ref={toolbarRef}>
      {showToolbarActions ? (
        <div className={cn("flex flex-wrap items-center gap-2.5", "post-calendar-toolbar-actions")}>
          <button
            type="button"
            className={cn(
              toolbarButton,
              "post-calendar-toolbar-button",
              "post-calendar-toolbar-button--today",
            )}
            onClick={() => onNavigate("TODAY")}
            disabled={isToday}
            aria-disabled={isToday}
            aria-label={
              isToday
                ? `${strings.today}, ${todayDateValue}`
                : `${strings.today} (${todayDateValue})`
            }
            title={isToday ? strings.today : `${strings.today}: ${todayDateValue}`}
          >
            <span>{strings.today}</span>
          </button>
          <span
            className="inline-flex items-center gap-1"
            role="group"
            aria-label={`${strings.back} / ${strings.next}`}
          >
            <button
              type="button"
              className={cn(toolbarButton, toolbarButtonNav, "post-calendar-toolbar-button--nav")}
              onClick={() => onNavigate("PREV")}
              aria-label={`${strings.back} (${getPrevRangeLabel(view, currentDate, culture)})`}
              title={`${strings.back}: ${getPrevRangeLabel(view, currentDate, culture)}`}
            >
              <span className={toolbarButtonDesktopText}>{strings.back}</span>
            </button>
            <button
              type="button"
              className={cn(toolbarButton, toolbarButtonNav, "post-calendar-toolbar-button--nav")}
              onClick={() => onNavigate("NEXT")}
              aria-label={`${strings.next} (${getNextRangeLabel(view, currentDate, culture)})`}
              title={`${strings.next}: ${getNextRangeLabel(view, currentDate, culture)}`}
            >
              <span className={toolbarButtonDesktopText}>{strings.next}</span>
            </button>
          </span>
        </div>
      ) : null}

      {showToolbarLabel && showDateJump ? (
        <div
          className={cn(toolbarLabelWrap, "post-calendar-toolbar-label-wrap")}
          ref={datePickerRef}
        >
          <button
            type="button"
            className={cn(toolbarLabelButton, "post-calendar-toolbar-label-button")}
            onClick={() => setDatePickerOpen(!datePickerOpen)}
            aria-expanded={datePickerOpen}
            aria-haspopup="dialog"
            aria-label={`${toolbarLabel}, ${strings.date}`}
          >
            <span className="flex-1 overflow-hidden text-ellipsis whitespace-nowrap">
              {toolbarLabel}
            </span>
          </button>
          {datePickerOpen && (
            <div
              className={cn(datePickerPopover, "post-calendar-date-picker-popover")}
              role="dialog"
              aria-label={strings.date}
            >
              <input
                type={dateInputConfig.type}
                className={cn(datePickerInput, "post-calendar-date-picker-input")}
                aria-label={strings.date}
                value={dateInputConfig.value}
                onChange={handleDateChange}
                autoFocus
                min={dateInputConfig.min}
                max={dateInputConfig.max}
                step={dateInputConfig.step}
                onKeyDown={(e) => {
                  if (e.key === "Escape") setDatePickerOpen(false);
                  if (e.key === "Enter" && dateInputConfig.type === "number") {
                    // For year number input, Enter confirms
                    const val = (e.target as HTMLInputElement).value;
                    if (val) {
                      onDateJump(`${val}-01-01`);
                      setDatePickerOpen(false);
                    }
                  }
                }}
              />
            </div>
          )}
        </div>
      ) : showToolbarLabel ? (
        <div
          className={cn(
            "m-0 max-w-full items-center justify-center break-words whitespace-normal text-center text-base font-normal leading-5 text-foreground max-[720px]:text-left",
            "post-calendar-toolbar-label",
          )}
          aria-live="polite"
        >
          {toolbarLabel}
        </div>
      ) : null}

      {showViewMenu ? (
        <div
          className={cn("flex flex-wrap items-center gap-1", "post-calendar-toolbar-views")}
          role="tablist"
          aria-label={strings.calendarViews}
          ref={dropdownRef}
        >
          {isNarrow ? (
            <div className={cn("relative", "post-calendar-views-dropdown")}>
              <button
                type="button"
                className={cn(dropdownTrigger, "post-calendar-views-dropdown-trigger")}
                onClick={() => setViewsDropdownOpen(!viewsDropdownOpen)}
                aria-expanded={viewsDropdownOpen}
                aria-haspopup="listbox"
                aria-label={`${viewLabels[view]}, ${strings.calendarViews}`}
              >
                <span
                  className={cn(
                    "min-w-0 flex-1 overflow-hidden text-ellipsis whitespace-nowrap text-start",
                    "post-calendar-views-dropdown-label",
                  )}
                >
                  {viewLabels[view]}
                </span>
                <ChevronDown
                  size={14}
                  className={cn(
                    "inline-flex shrink-0 transition-transform",
                    "post-calendar-views-dropdown-chevron",
                    viewsDropdownOpen && "rotate-180",
                  )}
                  aria-hidden="true"
                />
              </button>
              {viewsDropdownOpen && (
                <ul
                  className={cn(dropdownList, "post-calendar-views-dropdown-list")}
                  role="listbox"
                >
                  {views.map((calendarView) => (
                    <li
                      key={calendarView}
                      role="option"
                      aria-selected={calendarView === view}
                      onClick={() => {
                        onView(calendarView);
                        setViewsDropdownOpen(false);
                      }}
                      className={cn(
                        dropdownItem,
                        calendarView === view && "bg-muted",
                        "post-calendar-views-dropdown-item",
                        calendarView === view && "is-active",
                      )}
                      data-view={calendarView}
                    >
                      <span className="post-calendar-views-dropdown-item-label">
                        {viewLabels[calendarView]}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ) : (
            <div
              className={cn("flex flex-wrap gap-1", "post-calendar-views-inline")}
              role="tablist"
              aria-label={strings.calendarViews}
            >
              {views.map((calendarView, index) => (
                <button
                  key={calendarView}
                  type="button"
                  role="tab"
                  aria-selected={calendarView === view}
                  aria-controls={`panel-${calendarView}`}
                  id={`tab-${calendarView}`}
                  tabIndex={calendarView === view ? 0 : -1}
                  onClick={() => onView(calendarView)}
                  onFocus={() => setFocusedViewIndex(index)}
                  className={cn(
                    viewButton,
                    calendarView === view && "bg-primary text-primary-foreground",
                    "post-calendar-view-button",
                    calendarView === view && "is-active",
                  )}
                >
                  <span className="post-calendar-view-button-label">
                    {viewLabels[calendarView]}
                  </span>
                </button>
              ))}
            </div>
          )}
        </div>
      ) : null}
    </div>
  );
}

function getPrevRangeLabel(view: CalendarView, currentDate: Date, culture?: string): string {
  const m = moment(currentDate);
  switch (view) {
    case Views.MONTH:
      return localizer.format(m.clone().subtract(1, "month").toDate(), "MMMM YYYY", culture);
    case Views.WEEK:
      return localizer.format(m.clone().subtract(1, "week").toDate(), "MMM D", culture);
    case TIMELINE_VIEW:
      return localizer.format(m.clone().subtract(14, "days").toDate(), "MMM D", culture);
    case Views.DAY:
      return localizer.format(m.clone().subtract(1, "day").toDate(), "MMM D, YYYY", culture);
    case YEAR_VIEW:
      return localizer.format(m.clone().subtract(1, "year").toDate(), "YYYY", culture);
    case Views.AGENDA:
      return localizer.format(m.clone().subtract(1, "month").toDate(), "MMMM YYYY", culture);
    default:
      return "";
  }
}

function getNextRangeLabel(view: CalendarView, currentDate: Date, culture?: string): string {
  const m = moment(currentDate);
  switch (view) {
    case Views.MONTH:
      return localizer.format(m.clone().add(1, "month").toDate(), "MMMM YYYY", culture);
    case Views.WEEK:
      return localizer.format(m.clone().add(1, "week").toDate(), "MMM D", culture);
    case TIMELINE_VIEW:
      return localizer.format(m.clone().add(14, "days").toDate(), "MMM D", culture);
    case Views.DAY:
      return localizer.format(m.clone().add(1, "day").toDate(), "MMM D, YYYY", culture);
    case YEAR_VIEW:
      return localizer.format(m.clone().add(1, "year").toDate(), "YYYY", culture);
    case Views.AGENDA:
      return localizer.format(m.clone().add(1, "month").toDate(), "MMMM YYYY", culture);
    default:
      return "";
  }
}

interface DateInputConfig {
  type: "date" | "month" | "week" | "number";
  value: string;
  min?: string;
  max?: string;
  step?: string | number;
}

function getDateInputConfig(view: CalendarView, currentDate: Date): DateInputConfig {
  const m = moment(currentDate);

  switch (view) {
    case YEAR_VIEW:
      return {
        type: "number",
        value: m.format("YYYY"),
        min: "1900",
        max: "2100",
        step: 1,
      };
    case Views.MONTH:
      return {
        type: "month",
        value: m.format("YYYY-MM"),
      };
    case Views.WEEK:
      // Week input uses YYYY-Www format
      return {
        type: "week",
        value: m.format("YYYY-[W]WW"),
      };
    case Views.DAY:
    case TIMELINE_VIEW:
    case Views.AGENDA:
    default:
      return {
        type: "date",
        value: m.format("YYYY-MM-DD"),
      };
  }
}

const calendarFormats = {
  dayFormat: (date: Date, culture: string | undefined, nextLocalizer: typeof localizer) =>
    nextLocalizer.format(date, "ddd", culture),
  dayHeaderFormat: (date: Date, culture: string | undefined, nextLocalizer: typeof localizer) =>
    nextLocalizer.format(date, "ddd", culture),
  dayRangeHeaderFormat: (
    { start, end }: CalendarRange,
    culture: string | undefined,
    nextLocalizer: typeof localizer,
  ) => {
    if (moment(start).isSame(end, "day")) {
      return nextLocalizer.format(start, "dddd, D MMM YYYY", culture);
    }

    const startLabel = nextLocalizer.format(start, "D MMM", culture);
    const endLabel = nextLocalizer.format(end, "D MMM YYYY", culture);
    return `${startLabel} - ${endLabel}`;
  },
  agendaDateFormat: (date: Date, culture: string | undefined, nextLocalizer: typeof localizer) =>
    nextLocalizer.format(date, "MMMM D, YYYY", culture),
  agendaTimeFormat: (date: Date, culture: string | undefined, nextLocalizer: typeof localizer) =>
    nextLocalizer.format(date, "h:mm A", culture),
  agendaHeaderFormat: (
    { start, end }: CalendarRange,
    culture: string | undefined,
    nextLocalizer: typeof localizer,
  ) => {
    const startLabel = nextLocalizer.format(start, "MMMM D", culture);
    const endLabel = nextLocalizer.format(end, "MMMM D, YYYY", culture);
    return `${startLabel} - ${endLabel}`;
  },
  timeGutterFormat: (date: Date, culture: string | undefined, nextLocalizer: typeof localizer) =>
    nextLocalizer.format(date, "hh:mm A", culture),
};

function parseDateJumpValue(value: string, _view: CalendarView): Date | null {
  if (!value) return null;
  // Year view supplies YYYY, month supplies YYYY-MM, week supplies YYYY-Www, others YYYY-MM-DD
  const tryFormats = ["YYYY-MM-DD", "YYYY-MM", "YYYY-[W]WW", "YYYY"];
  for (const fmt of tryFormats) {
    const m = moment(value, fmt, true);
    if (m.isValid()) return m.toDate();
  }
  // Fallback: let moment parse flexibly (handles week input edge cases)
  const loose = moment(value);
  return loose.isValid() ? loose.toDate() : null;
}

export default function CalendarApp({ config, runtime }: CalendarAppProps) {
  const strings = getRuntimeStrings(runtime);
  const culture = useCalendarLocale(runtime.locale);
  const appRef = useRef<HTMLDivElement>(null);
  const [containerWidth, setContainerWidth] = useState<number | undefined>(undefined);
  const isNarrow = isNarrowContainer(containerWidth, config.responsiveBreakpoint);
  const {
    activeViews,
    agendaLength,
    currentDate,
    errorMessage,
    events,
    handleAgendaLoadMore,
    handleRangeChange,
    handleTimelineDateChange,
    handleTimelineRangeRequest,
    isLoading,
    isLoadingNext,
    loadedRange,
    timelineRange,
    setCurrentDate,
    setView,
    surfaceClassName,
    view,
  } = useCalendarState(config, runtime, strings, isNarrow);

  // Measure synchronously before paint to avoid isNarrow flip CLS
  useLayoutEffect(() => {
    const element = appRef.current;
    if (element) {
      const w = element.getBoundingClientRect().width;
      if (w > 0) setContainerWidth(w);
    }
  }, []);

  useEffect(() => {
    const element = appRef.current;

    if (!element || typeof ResizeObserver === "undefined") {
      return undefined;
    }

    const observer = new ResizeObserver((entries) => {
      for (const entry of entries) {
        setContainerWidth(entry.contentRect.width);
      }
    });

    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  const toolbarIsNarrow =
    containerWidth === undefined ? activeViews.length > 4 : isNarrowContainer(containerWidth, 560);

  const isMeasuring = containerWidth === undefined;

  const localizedYearView = useMemo(
    () =>
      Object.assign(
        (props: Parameters<typeof CalendarYearView>[0]) => (
          <CalendarYearView {...props} strings={strings} />
        ),
        {
          navigate: CalendarYearView.navigate,
          range: CalendarYearView.range,
          title: CalendarYearView.title,
        },
      ),
    [strings],
  );
  const agendaConfig: AgendaViewConfig = useMemo(
    () => ({
      rangeMode: config.agendaRangeMode === "upcoming-window" ? "upcoming-window" : "visible-range",
      rangeMonths: Number.parseInt(String(config.agendaRangeMonths ?? 3), 10) || 3,
      timelineDays: config.timelineDays
        ? Number.parseInt(String(config.timelineDays), 10)
        : undefined,
    }),
    [config.agendaRangeMode, config.agendaRangeMonths, config.timelineDays],
  );

  const localizedAgendaView = useMemo(
    () =>
      Object.assign(
        (props: { date: Date; events: CalendarEventRecord[] }) => (
          <AgendaView
            {...props}
            strings={strings}
            config={agendaConfig}
            loadedRange={loadedRange}
            onLoadMore={handleAgendaLoadMore}
            isLoading={isLoadingNext}
          />
        ),
        {
          navigate: AgendaView.navigate,
          range: AgendaView.range,
          title: AgendaView.title,
        },
      ),
    [strings, agendaConfig, loadedRange, handleAgendaLoadMore, isLoadingNext],
  );

  const calendarViews = activeViews.reduce<Record<string, boolean | React.ComponentType>>(
    (viewMap, activeView) => {
      if (activeView === YEAR_VIEW) {
        viewMap[activeView] = localizedYearView;

        return viewMap;
      }

      if (activeView === TIMELINE_VIEW) {
        viewMap[activeView] = localizedAgendaTimelineView;

        return viewMap;
      }

      if (activeView === Views.MONTH) {
        viewMap[activeView] = CalendarMonthView;

        return viewMap;
      }

      if (activeView === Views.AGENDA) {
        viewMap[activeView] = localizedAgendaView;

        return viewMap;
      }

      viewMap[activeView] = true;
      return viewMap;
    },
    {},
  );

  const showEmptyHint = !isLoading && !errorMessage && events.length === 0;

  return (
    <div
      className={cn(
        "relative text-foreground transition-opacity duration-[0.12s] ease-linear",
        isMeasuring && "is-measuring",
        isMeasuring && "opacity-[0.99]",
        "post-calendar-app",
      )}
      ref={appRef}
      aria-busy={isLoading}
      style={{ fontFamily: '"Inter", "Segoe UI", sans-serif' }}
    >
      {errorMessage && (
        <p
          className={cn(
            "mb-4 rounded-xl border px-3.5 py-3 text-destructive [background:var(--pc-error-bg)] [border-color:var(--pc-error-border)]",
            "post-calendar-error",
          )}
          role="alert"
        >
          {errorMessage}
        </p>
      )}

      {(isLoading || showEmptyHint) && (
        <div
          className={cn(
            "absolute bottom-3 right-3 z-10 inline-flex max-w-[calc(100%-1.5rem)] items-center gap-2 rounded-sm border-none bg-foreground px-4 py-2 text-sm text-background [backdrop-filter:blur(8px)]",
            isLoading ? "is-loading" : "is-empty",
            "post-calendar-status",
          )}
          role="status"
          aria-live="polite"
          aria-atomic="true"
        >
          {isLoading && (
            <span
              className={cn(
                "inline-block h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-muted border-t-foreground",
                "post-calendar-status-spinner",
              )}
              aria-hidden="true"
            />
          )}
          <span
            className={cn(
              "overflow-hidden text-ellipsis whitespace-nowrap",
              "post-calendar-status-text",
            )}
          >
            {isLoading ? "Loading…" : strings.noEvents}
          </span>
        </div>
      )}

      <div className={surfaceClassName}>
        <TimelineViewContextProvider
          value={{
            strings,
            config: agendaConfig,
            fetchedRange: timelineRange,
            isLoading,
            onDateChange: handleTimelineDateChange,
            onRangeRequest: handleTimelineRangeRequest,
          }}
        >
          <Calendar
            components={{
              event: CalendarEvent,
              month: {
                header: MonthHeader,
              },
              timeGutterHeader: () => <TimeGutterHeader label={strings.allDay} />,
              toolbar: (props) => (
                <CalendarToolbar
                  {...props}
                  culture={culture}
                  currentDate={currentDate}
                  isNarrow={toolbarIsNarrow}
                  strings={strings}
                  showDateJump
                  onDateJump={(dateString) => {
                    const parsed = parseDateJumpValue(dateString, view);
                    if (parsed) setCurrentDate(parsed);
                  }}
                  showToolbarActions={config.showToolbarActions !== false}
                  showToolbarLabel={config.showToolbarLabel !== false}
                  showViewMenu={config.showViewMenu !== false}
                  views={activeViews}
                />
              ),
              week: {
                header: WeekHeader,
              },
            }}
            culture={culture}
            date={currentDate}
            events={events}
            formats={calendarFormats}
            length={agendaLength}
            localizer={localizer}
            messages={{
              agenda: strings.agenda,
              date: strings.date,
              day: strings.day,
              event: strings.event,
              month: strings.month,
              next: strings.next,
              noEventsInRange: "",
              previous: strings.back,
              showMore: (count) => `+${count} ${strings.showMore}`,
              time: strings.time,
              today: strings.today,
              timeline: strings.timeline ?? strings.agenda,
              week: strings.week,
            }}
            eventPropGetter={(event: CalendarEventRecord) => {
              const className = getEventClassName(event.scheduled_start_time, currentDate, view);
              const style: React.CSSProperties = {};
              if (event.label?.color) {
                style["--pc-event-color" as string] = event.label.color;
                style.borderLeftColor = event.label.color;
              }
              return {
                className,
                style: Object.keys(style).length ? style : undefined,
              };
            }}
            onNavigate={(nextDate) => {
              setCurrentDate(nextDate);
            }}
            onRangeChange={(nextRange) => {
              handleRangeChange(nextRange as CalendarRangeInput);
            }}
            onSelectEvent={(event) => {
              if (event.url) {
                globalThis.location?.assign(event.url);
              }
            }}
            onYearMonthSelect={(nextDate: Date) => {
              setCurrentDate(nextDate);
              setView(Views.MONTH);
            }}
            onDaySelect={(nextDate: Date) => {
              setCurrentDate(nextDate);
              setView(Views.DAY);
            }}
            onView={(nextView) => {
              setView(normalizeView(nextView));
            }}
            popup
            showMultiDayTimes
            startAccessor="scheduled_start_time"
            endAccessor="scheduled_end_time"
            allDayAccessor="allDay"
            titleAccessor="name"
            timelineDays={config.timelineDays}
            toolbar={config.showToolbar !== false}
            view={view}
            views={calendarViews}
          />
        </TimelineViewContextProvider>
      </div>
    </div>
  );
}
