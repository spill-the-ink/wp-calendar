import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import CalendarApp from "../components/calendar/CalendarApp.tsx";
import AdminEventEditor from "../components/admin/EventEditor.tsx";
import AdminSettings from "../components/admin/Settings.tsx";
import "../styles/global.css";
import "../styles/admin.css";
import type {
  AdminEventRow,
  AdminRuntime,
  CalendarConfig,
  CalendarRuntime,
  SettingsRuntime,
} from "../types.ts";
import { demoEvents } from "./demo-events.ts";

const previewConfig: CalendarConfig = {
  defaultView: "month",
  enabledViews: ["year", "month", "week", "day", "agenda", "agenda-timeline"],
  showToolbar: true,
  agendaRangeMode: "upcoming-window",
  agendaRangeMonths: 3,
  timelineDays: 21,
  responsiveBreakpoint: 640,
  postTypes: ["event"],
};

/**
 * `?mode=local`   — curated demo fixtures, no API required
 * `?mode=remote`  — live API via Vite proxy (default)
 * `?mock`         — alias for `?mode=local` (backward compat)
 */
const params = new URLSearchParams(window.location.search);
const isLocal = params.get("mode") === "local" || params.has("mock");

const runtime: CalendarRuntime = {
  locale: "en-US",
  restUrl: "/wp-json/wp-calendar/v1/events",
  ...(isLocal ? { previewEvents: demoEvents } : {}),
  strings: {
    allDay: "All-day",
    agenda: "Agenda",
    back: "Back",
    calendarViews: "Calendar views",
    configParseError: "Unable to parse the calendar configuration.",
    date: "Date",
    day: "Day",
    event: "Event",
    loadError: "Unable to load calendar events right now.",
    missingApiUrl: "The calendar API URL is missing.",
    month: "Month",
    next: "Next",
    noEvents: isLocal
      ? "No preview events are scheduled in this range."
      : "No events are scheduled in this range.",
    showMore: "more",
    showMoreEventsForMonth: "Show %1$s more events for %2$s",
    time: "Time",
    today: "Today",
    week: "Week",
    year: "Year",
  },
};

// Admin editor demo data
const adminEvents: AdminEventRow[] = [
  {
    name: "",
    name_id: "",
    description: "",
    location: "",
    all_day: true,
    scheduled_start_time_date: "2026-08-25",
    scheduled_start_time_time: "",
    scheduled_end_time_date: "2026-08-25",
    scheduled_end_time_time: "",
    frequency: "none",
    interval: 1,
    by_weekday: [],
    repeat_until: "",
  },
  {
    name: "Team Meeting",
    name_id: "meeting",
    description: "Weekly sync with engineering team",
    location: "Conference Room A",
    location_url: "",
    all_day: false,
    scheduled_start_time_date: "2026-08-26",
    scheduled_start_time_time: "09:00",
    scheduled_end_time_date: "2026-08-26",
    scheduled_end_time_time: "10:00",
    frequency: "weekly",
    interval: 1,
    by_weekday: ["MO", "WE", "FR"],
    repeat_until: "2026-12-31",
  },
];

const adminRuntime: AdminRuntime = {
  fieldName: "wp_calendar_events",
  currentEvents: adminEvents,
  strings: {
    eventsIntro: "Add one or more event rows to make this post appear in the calendar.",
    noEvents: "No event rows yet.",
    addEvent: "Add event",
    removeEvent: "Remove event",
    eventNumber: "Event",
    name: "Name",
    nameHelp: "Event title displayed in the calendar.",
    description: "Description",
    descriptionHelp: "Optional description of the event.",
    category: "Category",
    labelNone: "No category",
    categoryHelp: "Assign a category for color-coded grouping.",
    location: "Location",
    locationHelp: "Physical or virtual location of the event.",
    locationUrl: "Location URL",
    locationUrlHelp: "Optional link for the location (e.g. a map or message link).",
    allDay: "All-day event",
    startDate: "Start date",
    startTime: "Start time",
    endDate: "End date",
    endTime: "End time",
    repeat: "Repeat",
    doesNotRepeat: "Does not repeat",
    every: "Every",
    weekly: "Weekly",
    monthly: "Monthly",
    yearly: "Yearly",
    repeatIntervalHelp: "For example, every 2 weeks.",
    repeatUntil: "Repeat until",
    repeatOn: "Repeat on",
    monday: "Monday",
    tuesday: "Tuesday",
    wednesday: "Wednesday",
    thursday: "Thursday",
    friday: "Friday",
    saturday: "Saturday",
    sunday: "Sunday",
    labels: [
      { id: "meeting", name: "Meeting", color: "#3b82f6" },
      { id: "personal", name: "Personal", color: "#10b981" },
      { id: "deadline", name: "Deadline", color: "#ef4444" },
    ],
  },
};

// Settings demo data
const settingsRuntime: SettingsRuntime = {
  postTypes: [
    { name: "post", label: "Posts", singularName: "Post", eventCount: 12, enabled: true },
    { name: "page", label: "Pages", singularName: "Page", eventCount: 3, enabled: false },
    { name: "event", label: "Events", singularName: "Event", eventCount: 25, enabled: true },
  ],
  icalFeeds: [
    {
      id: "ical-1",
      name: "Google Calendar",
      url: "https://calendar.google.com/calendar/ical/example/basic.ics",
      color: "#3b82f6",
      enabled: true,
    },
  ],
  sourcesOptionName: "wp_calendar_sources",
  postTypesOptionName: "wp_calendar_post_types",
  statistics: {
    totalWpEvents: 37,
    totalIcalFeeds: 1,
    totalDiscordGuilds: 0,
  },
  discordConfigured: false,
  discordGuilds: [],
  strings: {
    eventSourcesTitle: "Event Sources",
    sourcesHeaderSummary: "Event sources provide data to the calendar.",
    totalEvents: "%d total events",
    byPostType: "%d from posts",
    byIcal: "%d from iCal feeds",
    byDiscord: "%d from Discord",
    noSources: "No event sources configured. Add a source to get started.",
    addSource: "Add source",
    addPostType: "Add post type",
    addIcalFeed: "Add iCal feed",
    addDiscordGuild: "Add Discord server",
    removeSource: "Remove",
    save: "Save Changes",
    discordConnected: "Discord connected",
    discordNotConfigured: "Discord not configured",
    discordServers: "%d servers",
  },
};

// Mount calendar
const previewRoot = document.getElementById("preview-root");
if (previewRoot) {
  createRoot(previewRoot).render(
    <StrictMode>
      <CalendarApp config={previewConfig} runtime={runtime} />
    </StrictMode>,
  );
}

// Mount admin editor
const adminRoot = document.querySelector(".js-wp-calendar-admin-root");
if (adminRoot) {
  createRoot(adminRoot).render(
    <StrictMode>
      <AdminEventEditor runtime={adminRuntime} />
    </StrictMode>,
  );
}

// Mount settings
const settingsRoot = document.querySelector(".js-wp-calendar-settings-root");
if (settingsRoot) {
  createRoot(settingsRoot).render(
    <StrictMode>
      <AdminSettings runtime={settingsRuntime} />
    </StrictMode>,
  );
}
