import type { CalendarEventInput } from "../types.ts";

/*
 * Curated event fixtures anchored to "today" so the demo always looks alive
 * regardless of the calendar date the viewer opens.
 *
 * Coverage targets:
 *   - Lane stacking: 3+ same-time events today
 *   - Short event (20 min): shows as a dot on month cells
 *   - All-day events: header row / agenda stripe
 *   - Multi-day all-day: spans across week/timeline rows
 *   - Multi-day timed: visible in timeline as a spanning bar
 *   - Month-boundary crossing: month-start/end span
 *   - Density gradient across months for year-view bars
 *   - Past, today, near-future, and far-future events
 */

// Demo label objects (simulating configured labels)
const labelWorkshop = { id: "workshops", name: "Workshops", color: "#8fc6ff" };
const labelTalk = { id: "talks", name: "Talks", color: "#f87171" };
const labelSocial = { id: "social", name: "Social", color: "#a8e6a0" };
const labelOutdoors = { id: "outdoors", name: "Outdoors", color: "#e0a8ff" };
const labelArt = { id: "art", name: "Art", color: "#ff8fab" };

const today = new Date();
const year = today.getFullYear();
const month = today.getMonth();
const day = today.getDate();

/** Offset from today (negative = past, positive = future). Returns midnight. */
function d(offset: number): Date {
  const dt = new Date(year, month, day + offset, 0, 0, 0, 0);
  return dt;
}

/** Build a Date at a specific clock time on a given day offset. */
function at(offset: number, hour = 0, minute = 0): Date {
  const dt = d(offset);
  dt.setHours(hour, minute, 0, 0);
  return dt;
}

/** Fixed month/day in the current year (1-indexed month). */
function fixed(m: number, dayOfMonth: number, hour = 0, minute = 0): Date {
  return new Date(year, m - 1, dayOfMonth, hour, minute, 0, 0);
}

// ---------------------------------------------------------------------------
// Today — the busiest day (week/timeline showcase)
// ---------------------------------------------------------------------------

const todayEvents: CalendarEventInput[] = [
  // 3 overlapping timed events → lane stacking
  {
    id: "t-1",
    name: "Open studio morning",
    scheduled_start_time: at(0, 9, 0),
    scheduled_end_time: at(0, 14, 30),
    url: "#open-studio",
    label: labelArt,
  },
  {
    id: "t-2",
    name: "Tea and mending circle",
    scheduled_start_time: at(0, 11, 0),
    scheduled_end_time: at(0, 17, 0),
    label: labelWorkshop,
  },
  {
    id: "t-3",
    name: "Slow lunch on the steps",
    scheduled_start_time: at(0, 12, 30),
    scheduled_end_time: at(0, 15, 30),
    label: labelSocial,
  },
  // Very short event → dot on month cell
  {
    id: "t-4",
    name: "Five-minute reading",
    scheduled_start_time: at(0, 8, 0),
    scheduled_end_time: at(0, 8, 20),
    label: labelWorkshop,
  },
  // All-day today
  {
    id: "t-5",
    name: "Community seed swap",
    scheduled_start_time: d(0),
    scheduled_end_time: d(0),
    allDay: true,
    label: labelOutdoors,
  },
];

// ---------------------------------------------------------------------------
// Multi-day events spanning the current week
// ---------------------------------------------------------------------------

const multiDayEvents: CalendarEventInput[] = [
  // 4-day all-day: Mon → Thu of the current week
  {
    id: "md-1",
    name: "Weekend market setup",
    scheduled_start_time: at(-2),
    scheduled_end_time: at(2),
    allDay: true,
    url: "#market-setup",
    label: labelSocial,
  },
  // 3-day timed: Wed → Fri (visible as a spanning bar in timeline)
  {
    id: "md-2",
    name: "Greenhouse seeding sprint",
    scheduled_start_time: at(1, 7, 0),
    scheduled_end_time: at(3, 18, 0),
    label: labelWorkshop,
  },
];

// ---------------------------------------------------------------------------
// Near-future and recent past (agenda upcoming-window / week navigation)
// ---------------------------------------------------------------------------

const nearEvents: CalendarEventInput[] = [
  // Yesterday
  {
    id: "n-1",
    name: "Porch music rehearsal",
    scheduled_start_time: at(-1, 15, 0),
    scheduled_end_time: at(-1, 19, 30),
    url: "#rehearsal",
    label: labelArt,
  },
  // Tomorrow
  {
    id: "n-2",
    name: "Patchwork picnic setup",
    scheduled_start_time: at(1, 10, 0),
    scheduled_end_time: at(1, 18, 0),
    label: labelWorkshop,
  },
  {
    id: "n-3",
    name: "River path bird walk",
    scheduled_start_time: at(1, 8, 30),
    scheduled_end_time: at(1, 11, 30),
    allDay: false,
    url: "#bird-walk",
    label: labelOutdoors,
  },
  // +2 days
  {
    id: "n-4",
    name: "Firelight soup night",
    scheduled_start_time: at(2, 17, 0),
    scheduled_end_time: at(2, 20, 30),
    url: "#soup-night",
    label: labelSocial,
  },
  // +3 days
  {
    id: "n-5",
    name: "Workshop: basic carpentry",
    scheduled_start_time: at(3, 13, 0),
    scheduled_end_time: at(3, 17, 0),
    label: labelWorkshop,
  },
  // +5 days
  {
    id: "n-6",
    name: "Lantern-making afternoon",
    scheduled_start_time: at(5, 14, 0),
    scheduled_end_time: at(5, 17, 30),
    label: labelWorkshop,
  },
  // -3 days
  {
    id: "n-7",
    name: "Rain barrel installation",
    scheduled_start_time: at(-3, 9, 0),
    scheduled_end_time: at(-3, 12, 0),
    label: labelOutdoors,
  },
];

// ---------------------------------------------------------------------------
// Month-boundary events (test visibility at month edges)
// ---------------------------------------------------------------------------

const lastDayOfPrevMonth = fixed(month + 1, 0, 10, 0); // Last day of previous month
const firstDayOfNextMonth = fixed(month + 2, 1, 10, 0); // 1st of next month

const boundaryEvents: CalendarEventInput[] = [
  // Spans last 2 days of prev month → 1st of this month
  {
    id: "b-1",
    name: "Month-end inventory count",
    scheduled_start_time: new Date(lastDayOfPrevMonth.getTime() - 1 * 86400000),
    scheduled_end_time: new Date(firstDayOfNextMonth.getTime() + 86400000),
    allDay: true,
    label: labelWorkshop,
  },
  // Starts on the last day of this month, ends 2 days into next month
  {
    id: "b-2",
    name: "Season-end plant sale",
    scheduled_start_time: fixed(month + 1, 0, 9, 0),
    scheduled_end_time: fixed(month + 2, 2, 17, 0),
    allDay: false,
    label: labelOutdoors,
  },
];

// ---------------------------------------------------------------------------
// Events spread across the year (year-view density bars)
// Each entry: [month (1-indexed), day, hour, durationHours, title, tags]
// ---------------------------------------------------------------------------

function spreadEvent(
  m: number,
  dayOfMonth: number,
  hour: number,
  durationHours: number,
  name: string,
  id: string,
  label: { id: string; name: string; color: string },
  tags: string[],
  allDay = false,
): CalendarEventInput {
  if (allDay) {
    return {
      id,
      name,
      scheduled_start_time: fixed(m, dayOfMonth),
      scheduled_end_time: fixed(m, dayOfMonth),
      allDay: true,
      label,
      tags,
    };
  }
  const scheduled_start_time = fixed(m, dayOfMonth, hour, 0);
  const scheduled_end_time = new Date(scheduled_start_time.getTime() + durationHours * 3600000);
  return { id, name, scheduled_start_time, scheduled_end_time, label, tags };
}

const yearSpread: CalendarEventInput[] = [
  spreadEvent(1, 8, 14, 3, "Winter reading circle", "ys-1", labelWorkshop, ["Reading"]),
  spreadEvent(1, 22, 10, 4, "Snowshoe trek", "ys-2", labelOutdoors, ["Outdoors"]),
  spreadEvent(1, 30, 18, 2, "Potluck dinner", "ys-3", labelSocial, ["Food"]),

  spreadEvent(2, 5, 13, 2, "Seed catalog review", "ys-4", labelWorkshop, ["Garden"]),
  spreadEvent(2, 19, 11, 3, "Maple sugaring walk", "ys-5", labelOutdoors, ["Outdoors"]),

  spreadEvent(3, 12, 10, 5, "Greenhouse cleanup day", "ys-6", labelWorkshop, ["Garden"]),
  spreadEvent(3, 14, 12, 2, "Tool sharpening clinic", "ys-7", labelWorkshop, ["Workshop"]),
  spreadEvent(3, 18, 12, 4, "Window herb swap", "ys-8", labelSocial, ["Swap"], true),
  spreadEvent(3, 25, 9, 8, "Spring planting blitz", "ys-9", labelWorkshop, ["Garden"]),
  spreadEvent(3, 31, 15, 2, "End-of-month tea", "ys-10", labelSocial, ["Social"]),

  spreadEvent(4, 10, 14, 3, "Watercolor in the park", "ys-11", labelArt, ["Art"]),
  spreadEvent(4, 22, 18, 2, "Film screening", "ys-12", labelArt, ["Film"]),

  spreadEvent(5, 3, 10, 6, "Plant sale weekend", "ys-13", labelSocial, ["Garden", "Sale"]),
  spreadEvent(5, 11, 16, 2, "Choir practice", "ys-14", labelTalk, ["Music"]),
  spreadEvent(5, 24, 18, 3, "Late porch singalong", "ys-15", labelArt, ["Music"]),
  spreadEvent(5, 30, 9, 8, "Porch music rehearsal", "ys-16", labelArt, ["Music"]),

  spreadEvent(6, 8, 11, 3, "Bird walk", "ys-17", labelOutdoors, ["Outdoors"]),
  spreadEvent(6, 21, 17, 4, "Midsummer gathering", "ys-18", labelSocial, ["Community"]),

  spreadEvent(7, 5, 13, 5, "Creekside sketch picnic", "ys-19", labelArt, ["Art"]),
  spreadEvent(7, 14, 10, 3, "Open studio morning", "ys-20", labelArt, ["Art"]),
  spreadEvent(7, 28, 18, 2, "Stargazing night", "ys-21", labelOutdoors, ["Outdoors"]),

  // August events are clustered around "today" via todayEvents/nearEvents above,
  // but we add a few for density
  spreadEvent(8, 5, 14, 2, "Late-summer reading", "ys-22", labelWorkshop, ["Reading"]),
  spreadEvent(8, 18, 10, 4, "Meadow walk", "ys-23", labelOutdoors, ["Outdoors"]),
  spreadEvent(8, 25, 16, 3, "Preserving workshop", "ys-24", labelWorkshop, ["Workshop"]),

  spreadEvent(9, 2, 10, 3, "First fall hike", "ys-25", labelOutdoors, ["Outdoors"]),
  spreadEvent(9, 14, 19, 2, "Night garden lantern walk", "ys-26", labelSocial, ["Community"]),
  spreadEvent(9, 20, 13, 4, "Apple press day", "ys-27", labelWorkshop, ["Garden"]),
  spreadEvent(9, 28, 11, 2, "Harvest dinner", "ys-28", labelSocial, ["Food"]),

  spreadEvent(10, 10, 14, 3, "Leaf-peeping walk", "ys-29", labelOutdoors, ["Outdoors"]),
  spreadEvent(10, 25, 18, 2, "Soup and stories night", "ys-30", labelSocial, ["Food"]),

  spreadEvent(11, 5, 13, 3, "Coat mending table", "ys-31", labelWorkshop, ["Craft"]),
  spreadEvent(11, 15, 10, 5, "Workshop: basic carpentry", "ys-32", labelWorkshop, ["Workshop"]),
  spreadEvent(11, 30, 17, 3, "Firelight soup night", "ys-33", labelSocial, ["Food"]),

  spreadEvent(12, 10, 14, 2, "Candle-making afternoon", "ys-34", labelWorkshop, ["Craft"]),
  spreadEvent(12, 21, 17, 5, "Longest night potluck", "ys-35", labelSocial, ["Gathering"]),
];

export const demoEvents: CalendarEventInput[] = [
  ...todayEvents,
  ...multiDayEvents,
  ...nearEvents,
  ...boundaryEvents,
  ...yearSpread,
];
