// Tailwind class constants shared across calendar views.
//
// Keep this file for classes used by MORE THAN ONE component. Single-use
// classes live inline in the JSX (or as a local const in the component when
// they are reused within that file or are complex/stateful).

import { cn } from "../../lib/utils.ts";

/**
 * "Today / current" diagonal stripe. Shared by the month cell, year cell,
 * timeline day column and (in CSS) react-big-calendar's rbc-today.
 */
export const todayStripe =
  "bg-card [background-image:repeating-linear-gradient(-45deg,var(--card)_0_10px,var(--muted)_10px_12px)]";

/* -- Surface ------------------------------------------------------ */

export const surfaceBase = "bg-card text-foreground min-h-[52.5rem] max-[720px]:min-h-96";

/** Scrollable time-grid surfaces (month/week/day). */
export const surfaceScroll =
  "overflow-x-auto overflow-y-hidden max-[720px]:h-auto max-[720px]:overflow-visible";

/** Overflowing/auto-height surfaces (agenda, timeline). */
export const surfaceWide = "h-auto overflow-visible";

/** Year surface keeps a minimum width so the 4-column grid does not squish. */
export const surfaceYear = "h-auto min-h-[52.5rem] min-w-[min(100%,52.5rem)] overflow-visible";

/** Class-name hooks kept for react-big-calendar descendant overrides in global.css. */
const surfaceClass = "wp-calendar-surface";

export function surfaceClassName(viewHook: string, isLoading: boolean): string {
  const isWide = viewHook === "is-agenda-view" || viewHook === "is-timeline-view";
  const isYear = viewHook === "is-year-view";

  return cn(
    surfaceClass,
    surfaceBase,
    isYear ? surfaceYear : isWide ? surfaceWide : surfaceScroll,
    viewHook,
    isLoading && "opacity-80",
  );
}

/* -- Event pill (month / year / week-day views) ------------------- */

export const eventPill =
  "block max-w-full min-w-full overflow-hidden whitespace-nowrap text-ellipsis border-none rounded-sm bg-primary px-2.5 py-1 text-sm leading-normal text-primary-foreground";

/** Colored left accent driven by the event's label color (--pc-event-color). */
export const eventPillColor = "border-l-[3px]";

export const eventLink = "text-inherit no-underline hover:underline focus:underline";

export const eventSourceBadge =
  "mr-1 inline-flex h-3.5 w-5 items-center justify-center rounded-sm px-0.5 text-[9px] font-bold uppercase leading-none";

export const eventSourceBadgeIcal = "bg-emerald-500 text-white";

export const eventSourceBadgeDc = "bg-[#5865f2] text-white";
