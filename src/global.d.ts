/// <reference types="vite/client" />

import type { AdminEventRow, AdminRuntime, CalendarRuntime } from "./types.ts";

declare global {
  interface Window {
    WpCalendarAdminSharedRows?: AdminEventRow[];
    WpCalendarAdmin?: AdminRuntime;
    WpCalendarRuntime?: CalendarRuntime;
  }
}

export {};
