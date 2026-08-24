import { useEffect, useState } from "react";
import moment from "moment";
import { allowedViews, type CalendarView } from "./calendar-utils.ts";

const momentLocaleModules = import.meta.glob("../../node_modules/moment/dist/locale/*.js");

function normalizeCulture(locale?: string): string | undefined {
  if (!locale) {
    return undefined;
  }

  return locale.replace(/_/g, "-");
}

function setMomentLocale(locale?: string): void {
  if (!locale) {
    return;
  }

  const normalizedLocale = locale.replace(/_/g, "-").toLowerCase();
  const baseLocale = normalizedLocale.split("-")[0];

  moment.locale([normalizedLocale, baseLocale]);
}

async function ensureMomentLocale(locale?: string): Promise<string | undefined> {
  if (!locale) {
    return undefined;
  }

  const normalizedLocale = locale.replace(/_/g, "-").toLowerCase();
  const baseLocale = normalizedLocale.split("-")[0];
  const localeCandidates = [normalizedLocale, baseLocale];

  for (const localeCandidate of localeCandidates) {
    const loader =
      momentLocaleModules[`../../node_modules/moment/dist/locale/${localeCandidate}.js`];

    if (!loader) {
      continue;
    }

    await loader();
    setMomentLocale(localeCandidate);

    return normalizeCulture(localeCandidate);
  }

  setMomentLocale(locale);

  return normalizeCulture(locale);
}

/** Tracks the moment culture, loading locale modules on demand. */
export function useCalendarLocale(locale?: string): string | undefined {
  const [culture, setCulture] = useState<string | undefined>(() => normalizeCulture(locale));

  useEffect(() => {
    let cancelled = false;

    void ensureMomentLocale(locale).then((resolvedCulture) => {
      if (!cancelled) {
        setCulture(resolvedCulture);
      }
    });

    return () => {
      cancelled = true;
    };
  }, [locale]);

  return culture;
}

export function getInitialDateFromUrl(): Date | null {
  if (typeof window === "undefined") {
    return null;
  }

  const raw = new URLSearchParams(window.location.search).get("date");

  if (!raw) {
    return null;
  }

  const parsed = moment(raw, "YYYY-MM-DD", true);

  return parsed.isValid() ? parsed.toDate() : null;
}

export function getInitialViewFromUrl(enabledViews?: string[]): CalendarView | null {
  if (typeof window === "undefined") {
    return null;
  }

  const raw = new URLSearchParams(window.location.search).get("view");

  if (!raw || !allowedViews.includes(raw as CalendarView)) {
    return null;
  }

  const normalized = raw as CalendarView;

  if (Array.isArray(enabledViews) && enabledViews.length > 0) {
    const resolved = new Set(enabledViews.map((v) => v as CalendarView));
    if (!resolved.has(normalized)) {
      return null;
    }
  }

  return normalized;
}

export function parseCalendarParamsFromUrl(): {
  date: Date | null;
  view: CalendarView | null;
} {
  return {
    date: getInitialDateFromUrl(),
    view: getInitialViewFromUrl(),
  };
}

export function syncCalendarParamsToUrl(
  currentDate: Date,
  view: CalendarView,
  mode: "replace" | "push" = "replace",
): void {
  if (typeof window === "undefined" || typeof window.history?.replaceState !== "function") {
    return;
  }

  const url = new URL(window.location.href);
  const nextDate = moment(currentDate).format("YYYY-MM-DD");

  if (url.searchParams.get("date") === nextDate && url.searchParams.get("view") === view) {
    return;
  }

  url.searchParams.set("date", nextDate);
  url.searchParams.set("view", view);

  if (mode === "push" && typeof window.history.pushState === "function") {
    window.history.pushState({}, "", url);
  } else {
    window.history.replaceState({}, "", url);
  }
}
