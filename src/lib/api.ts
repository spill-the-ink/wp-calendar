import type { CalendarRuntime } from "../types.ts";

/**
 * Same-origin REST route of the plugin. In Vite dev mode this path is
 * proxied to the remote WordPress install (see vite.config.js), so the
 * app code is identical in development and production.
 */
export const EVENTS_API_PATH = "/wp-json/post-calendar/v1/events";

/**
 * Resolve the events endpoint for the current environment:
 * - Production: WordPress injects `runtime.restUrl`.
 * - Dev preview: fall back to the same-origin path handled by the Vite proxy.
 */
export function resolveEventsUrl(runtime: Partial<CalendarRuntime> | undefined): string {
  const restUrl = runtime?.restUrl;

  if (typeof restUrl === "string" && restUrl.trim() !== "") {
    return restUrl;
  }

  return EVENTS_API_PATH;
}
