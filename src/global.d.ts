/// <reference types="vite/client" />

import type { AdminEventRow, AdminRuntime, CalendarRuntime } from "./types.ts";

declare global {
  interface Window {
    PostCalendarAdminSharedRows?: AdminEventRow[];
    PostCalendarAdmin?: AdminRuntime;
    PostCalendarRuntime?: CalendarRuntime;
  }
}

export {};
