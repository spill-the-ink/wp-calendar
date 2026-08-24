import { resolveEventsUrl } from "./api.ts";
import type {
  CalendarConfig,
  CalendarEventInput,
  CalendarEventRecord,
  CalendarRange,
  CalendarRuntime,
} from "../types.ts";

/**
 * Normalizes raw API event payloads into the client-facing record type used by
 * the calendar views (dates as Date instances, guaranteed arrays/nulls).
 */
export function normalizeEvents(events: CalendarEventInput[]): CalendarEventRecord[] {
  return events.map((event) => ({
    ...event,
    scheduled_start_time: new Date(event.scheduled_start_time),
    scheduled_end_time: new Date(event.scheduled_end_time),
    allDay: Boolean(event.allDay),
    tags: Array.isArray(event.tags) ? event.tags : [],
    label: event.label ?? null,
    source: event.source ?? null,
    location: event.location ?? null,
    location_url: event.location_url ?? null,
  }));
}

export function getPreviewEvents(
  previewEvents?: CalendarEventInput[],
): CalendarEventRecord[] | null {
  return Array.isArray(previewEvents) ? normalizeEvents(previewEvents) : null;
}

/**
 * Builds the events REST URL for the given range and query configuration.
 */
export function buildRequestUrl(
  config: CalendarConfig,
  runtime: CalendarRuntime,
  activeRange: CalendarRange | null,
): string {
  const requestUrl = new URL(
    resolveEventsUrl(runtime),
    globalThis.location?.origin ?? "http://localhost",
  );

  requestUrl.searchParams.set("per_page", "1000");

  if (Array.isArray(config.postTypes) && config.postTypes.length > 0) {
    requestUrl.searchParams.set("post_types", config.postTypes.join(","));
  }

  if (config.queryVars && Object.keys(config.queryVars).length > 0) {
    requestUrl.searchParams.set("query_vars", JSON.stringify(config.queryVars));
  }

  if (activeRange?.start) {
    requestUrl.searchParams.set("start", activeRange.start.toISOString());
  }

  if (activeRange?.end) {
    requestUrl.searchParams.set("end", activeRange.end.toISOString());
  }

  return requestUrl.toString();
}
