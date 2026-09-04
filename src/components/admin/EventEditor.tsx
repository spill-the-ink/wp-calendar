import { useEffect, useState } from "react";
import type { AdminEventRow, AdminRuntime, EventRepeatValue } from "../../types.ts";

const ADMIN_SYNC_EVENT = "wp-calendar-admin-sync";

const WEEKDAYS = [
  { code: "MO", key: "monday" },
  { code: "TU", key: "tuesday" },
  { code: "WE", key: "wednesday" },
  { code: "TH", key: "thursday" },
  { code: "FR", key: "friday" },
  { code: "SA", key: "saturday" },
  { code: "SU", key: "sunday" },
] as const;

function createEmptyRow(): AdminEventRow {
  return {
    name: "",
    name_id: "",
    description: "",
    location: "",
    location_url: "",
    all_day: true,
    scheduled_start_time_date: "",
    scheduled_start_time_time: "09:00",
    scheduled_end_time_date: "",
    scheduled_end_time_time: "10:00",
    frequency: "none",
    interval: 1,
    by_weekday: [],
    repeat_until: "",
  };
}

function parseDateTimeString(value: string, allDay: boolean): { date: string; time: string } {
  if (!value) {
    return { date: "", time: allDay ? "" : "09:00" };
  }

  if (allDay) {
    return { date: value.slice(0, 10), time: "" };
  }

  const normalized = value.replace("T", " ");
  const parts = normalized.split(" ");
  return {
    date: parts[0]?.slice(0, 10) ?? "",
    time: parts[1]?.slice(0, 5) ?? "09:00",
  };
}

function formatDateTimeString(date: string, time: string, allDay: boolean, isEnd: boolean): string {
  if (!date) {
    return "";
  }

  if (allDay) {
    return `${date} ${isEnd ? "23:59:59" : "00:00:00"}`;
  }

  const timeValue = time || (isEnd ? "23:59" : "00:00");
  return `${date} ${timeValue}:00`;
}

function toggleAllDayRow(row: AdminEventRow, allDay: boolean): AdminEventRow {
  if (!allDay) {
    return {
      ...row,
      all_day: false,
    };
  }

  return {
    ...row,
    all_day: true,
    scheduled_start_time_time: "",
    scheduled_end_time_time: "",
  };
}

interface RawAdminEventRow {
  name?: string;
  label?: string;
  name_id?: string;
  label_id?: string;
  description?: string;
  location?: string;
  location_url?: string;
  all_day?: boolean;
  scheduled_start_time?: string;
  start?: string;
  scheduled_end_time?: string;
  end?: string;
  frequency?: string;
  repeat?: string;
  interval?: number;
  repeat_interval?: number;
  by_weekday?: string[];
  repeat_byday?: string[];
  repeat_until?: string;
}

function normalizeRows(rows: RawAdminEventRow[] | undefined): AdminEventRow[] {
  if (!Array.isArray(rows)) {
    return [];
  }

  return rows.map((row) => {
    const allDay = Boolean(row?.all_day);
    const startParsed = parseDateTimeString(row?.scheduled_start_time ?? row?.start ?? "", allDay);
    const endParsed = parseDateTimeString(row?.scheduled_end_time ?? row?.end ?? "", allDay);

    return {
      name: row?.name ?? row?.label ?? "",
      name_id: row?.name_id ?? row?.label_id ?? "",
      description: row?.description ?? "",
      location: row?.location ?? "",
      location_url: row?.location_url ?? "",
      all_day: allDay,
      scheduled_start_time_date: startParsed.date,
      scheduled_start_time_time: startParsed.time,
      scheduled_end_time_date: endParsed.date,
      scheduled_end_time_time: endParsed.time,
      frequency: (row?.frequency ?? row?.repeat ?? "none") as EventRepeatValue,
      interval: Math.max(1, Number(row?.interval ?? row?.repeat_interval ?? 1) || 1),
      by_weekday: Array.isArray(row?.by_weekday ?? row?.repeat_byday)
        ? (row.by_weekday ?? row.repeat_byday)
        : [],
      repeat_until: row?.repeat_until ?? "",
    };
  });
}

function getSharedRows(runtime: AdminRuntime): AdminEventRow[] {
  const sharedRows = globalThis.WpCalendarAdminSharedRows;

  if (Array.isArray(sharedRows)) {
    return normalizeRows(sharedRows);
  }

  return normalizeRows(runtime.currentEvents);
}

function broadcastRows(rows: AdminEventRow[]): void {
  globalThis.WpCalendarAdminSharedRows = rows;
  globalThis.dispatchEvent(new CustomEvent(ADMIN_SYNC_EVENT, { detail: rows }));
}

export default function AdminEventEditor({ runtime }: { runtime: AdminRuntime }) {
  const [instanceId] = useState(() => `wp-calendar-admin-${Math.random().toString(36).slice(2)}`);
  const [rows, setRows] = useState<AdminEventRow[]>(() => {
    if (Array.isArray(globalThis.WpCalendarAdminSharedRows)) {
      return normalizeRows(globalThis.WpCalendarAdminSharedRows);
    }
    const initial = getSharedRows(runtime);
    globalThis.WpCalendarAdminSharedRows = initial;
    return initial;
  });
  const fieldName = runtime.fieldName ?? "wp_calendar_events";
  const strings = runtime.strings ?? {};

  useEffect(() => {
    function handleSync(event: Event): void {
      const customEvent = event as CustomEvent<AdminEventRow[]>;

      if (!Array.isArray(customEvent.detail)) {
        return;
      }

      setRows(normalizeRows(customEvent.detail));
    }

    globalThis.addEventListener(ADMIN_SYNC_EVENT, handleSync);

    return () => {
      globalThis.removeEventListener(ADMIN_SYNC_EVENT, handleSync);
    };
  }, []);

  function syncRows(updater: (currentRows: AdminEventRow[]) => AdminEventRow[]): void {
    setRows((currentRows) => {
      const nextRows = updater(currentRows);
      broadcastRows(nextRows);
      return nextRows;
    });
  }

  function updateRow(index: number, nextRow: AdminEventRow): void {
    syncRows((currentRows) =>
      currentRows.map((row, rowIndex) => (rowIndex === index ? nextRow : row)),
    );
  }

  function appendRow(): void {
    syncRows((currentRows) => [...currentRows, createEmptyRow()]);
  }

  function removeRow(index: number): void {
    syncRows((currentRows) => currentRows.filter((_, rowIndex) => rowIndex !== index));
  }

  return (
    <div className="pc-admin-panel" data-wp-calendar-admin-instance={instanceId}>
      {rows.map((row, index) => {
        const startFull = formatDateTimeString(
          row.scheduled_start_time_date,
          row.scheduled_start_time_time,
          row.all_day,
          false,
        );
        const endFull = formatDateTimeString(
          row.scheduled_end_time_date,
          row.scheduled_end_time_time,
          row.all_day,
          true,
        );

        return (
          <div key={`hidden-${index}`} hidden>
            <input type="hidden" name={`${fieldName}[${index}][name]`} value={row.name} readOnly />
            <input
              type="hidden"
              name={`${fieldName}[${index}][name_id]`}
              value={row.name_id}
              readOnly
            />
            <input
              type="hidden"
              name={`${fieldName}[${index}][all_day]`}
              value={row.all_day ? "1" : "0"}
              readOnly
            />
            <input
              type="hidden"
              name={`${fieldName}[${index}][scheduled_start_time]`}
              value={startFull}
              readOnly
            />
            <input
              type="hidden"
              name={`${fieldName}[${index}][scheduled_end_time]`}
              value={endFull}
              readOnly
            />
            <input
              type="hidden"
              name={`${fieldName}[${index}][frequency]`}
              value={row.frequency}
              readOnly
            />
            <input
              type="hidden"
              name={`${fieldName}[${index}][interval]`}
              value={String(row.interval)}
              readOnly
            />
            <input
              type="hidden"
              name={`${fieldName}[${index}][recurrence_end]`}
              value={row.repeat_until}
              readOnly
            />
            <input
              type="hidden"
              name={`${fieldName}[${index}][location]`}
              value={row.location ?? ""}
              readOnly
            />
            <input
              type="hidden"
              name={`${fieldName}[${index}][location_url]`}
              value={row.location_url ?? ""}
              readOnly
            />
            {row.by_weekday.map((weekday, weekdayIndex) => (
              <input
                key={`${index}-${weekday}-${weekdayIndex}`}
                type="hidden"
                name={`${fieldName}[${index}][by_weekday][]`}
                value={weekday}
                readOnly
              />
            ))}
          </div>
        );
      })}

      <div className="pc-admin-panel__body">
        <div className="pc-admin-event__action_row">
          <button
            type="button"
            className="pc-admin-button pc-admin-button--secondary"
            onClick={appendRow}
          >
            {strings.addEvent ?? "Add event"}
          </button>
        </div>
      </div>

      <div className="pc-admin-panel__body">
        {rows.length === 0 ? (
          <div className="pc-admin-panel__body">
            <p className="pc-admin-intro">{strings.noEvents ?? "No event rows yet."}</p>
          </div>
        ) : null}

        {rows.map((row, index) => {
          const isRepeating = row.frequency !== "none";
          const isWeekly = row.frequency === "weekly";

          return (
            <div key={index} className="pc-admin-event-card">
              <div className="pc-admin-event-card__header">
                <h3 className="pc-admin-event-card__title">{`${index + 1}`}</h3>
                <button
                  type="button"
                  className="pc-admin-button pc-admin-button--danger pc-admin-button--small"
                  onClick={() => removeRow(index)}
                >
                  {strings.removeEvent ?? "Remove event"}
                </button>
              </div>

              <div className="pc-admin-event-card__body">
                <label className="pc-admin-label">
                  <span className="pc-admin-label__text">{strings.name ?? "Name"}</span>
                  <input
                    type="text"
                    className="pc-admin-input"
                    value={row.name}
                    onChange={(event) => updateRow(index, { ...row, name: event.target.value })}
                  />
                  <span className="pc-admin-label__help">
                    {strings.nameHelp ?? "Event title displayed in the calendar."}
                  </span>
                </label>

                <label className="pc-admin-label">
                  <span className="pc-admin-label__text">
                    {strings.description ?? "Description"}
                  </span>
                  <textarea
                    className="pc-admin-textarea"
                    value={row.description ?? ""}
                    onChange={(event) =>
                      updateRow(index, { ...row, description: event.target.value })
                    }
                    rows={3}
                  />
                  <span className="pc-admin-label__help">
                    {strings.descriptionHelp ?? "Optional description of the event."}
                  </span>
                </label>

                <div className="pc-admin-grid pc-admin-grid--2">
                  <label className="pc-admin-label">
                    <span className="pc-admin-label__text">{strings.category ?? "Category"}</span>
                    <select
                      className="pc-admin-select"
                      value={row.name_id}
                      onChange={(event) =>
                        updateRow(index, { ...row, name_id: event.target.value })
                      }
                    >
                      <option value="">{strings.labelNone ?? "No category"}</option>
                      {(strings.labels ?? []).map(
                        (lbl: { id: string; name: string; color: string }) => (
                          <option key={lbl.id} value={lbl.id}>
                            {lbl.name}
                          </option>
                        ),
                      )}
                    </select>
                    <span className="pc-admin-label__help">
                      {strings.categoryHelp ?? "Assign a category for color-coded grouping."}
                    </span>
                  </label>

                  <label className="pc-admin-label">
                    <span className="pc-admin-label__text">{strings.location ?? "Location"}</span>
                    <input
                      type="text"
                      className="pc-admin-input"
                      value={row.location ?? ""}
                      onChange={(event) =>
                        updateRow(index, { ...row, location: event.target.value })
                      }
                    />
                    <span className="pc-admin-label__help">
                      {strings.locationHelp ?? "Physical or virtual location of the event."}
                    </span>
                  </label>

                  <label className="pc-admin-label">
                    <span className="pc-admin-label__text">
                      {strings.locationUrl ?? "Location URL"}
                    </span>
                    <input
                      type="url"
                      className="pc-admin-input"
                      placeholder="https://..."
                      value={row.location_url ?? ""}
                      onChange={(event) =>
                        updateRow(index, { ...row, location_url: event.target.value })
                      }
                    />
                    <span className="pc-admin-label__help">
                      {strings.locationUrlHelp ??
                        "Optional link for the location (e.g. a map or message link)."}
                    </span>
                  </label>
                </div>

                <label className="pc-admin-panel__row">
                  <input
                    type="checkbox"
                    className="pc-admin-checkbox"
                    checked={row.all_day}
                    onChange={(event) =>
                      updateRow(index, toggleAllDayRow(row, event.target.checked))
                    }
                  />
                  <span>{strings.allDay ?? "All-day event"}</span>
                </label>

                <div className="pc-admin-grid pc-admin-grid--2">
                  <label className="pc-admin-label">
                    <span className="pc-admin-label__text">
                      {strings.startDate ?? "Start date"}
                    </span>
                    <input
                      type="date"
                      className="pc-admin-input"
                      value={row.scheduled_start_time_date}
                      onChange={(event) =>
                        updateRow(index, { ...row, scheduled_start_time_date: event.target.value })
                      }
                    />
                  </label>

                  <label className="pc-admin-label">
                    <span className="pc-admin-label__text">
                      {strings.startTime ?? "Start time"}
                    </span>
                    <input
                      type="time"
                      className="pc-admin-input"
                      value={row.scheduled_start_time_time}
                      disabled={row.all_day}
                      onChange={(event) =>
                        updateRow(index, { ...row, scheduled_start_time_time: event.target.value })
                      }
                    />
                  </label>

                  <label className="pc-admin-label">
                    <span className="pc-admin-label__text">{strings.endDate ?? "End date"}</span>
                    <input
                      type="date"
                      className="pc-admin-input"
                      value={row.scheduled_end_time_date}
                      onChange={(event) =>
                        updateRow(index, { ...row, scheduled_end_time_date: event.target.value })
                      }
                    />
                  </label>

                  <label className="pc-admin-label">
                    <span className="pc-admin-label__text">{strings.endTime ?? "End time"}</span>
                    <input
                      type="time"
                      className="pc-admin-input"
                      value={row.scheduled_end_time_time}
                      disabled={row.all_day}
                      onChange={(event) =>
                        updateRow(index, { ...row, scheduled_end_time_time: event.target.value })
                      }
                    />
                  </label>
                </div>

                <label className="pc-admin-label">
                  <span className="pc-admin-label__text">{strings.repeat ?? "Repeat"}</span>
                  <select
                    className="pc-admin-select"
                    value={row.frequency}
                    onChange={(event) =>
                      updateRow(index, {
                        ...row,
                        frequency: event.target.value as EventRepeatValue,
                        by_weekday: event.target.value === "weekly" ? row.by_weekday : [],
                        repeat_until: event.target.value === "none" ? "" : row.repeat_until,
                      })
                    }
                  >
                    <option value="none">{strings.doesNotRepeat ?? "Does not repeat"}</option>
                    <option value="weekly">{strings.weekly ?? "Weekly"}</option>
                    <option value="monthly">{strings.monthly ?? "Monthly"}</option>
                    <option value="yearly">{strings.yearly ?? "Yearly"}</option>
                  </select>
                </label>

                {isRepeating ? (
                  <div className="pc-admin-grid pc-admin-grid--2">
                    <label className="pc-admin-label">
                      <span className="pc-admin-label__text">{strings.every ?? "Every"}</span>
                      <input
                        type="number"
                        className="pc-admin-input"
                        min={1}
                        step={1}
                        value={row.interval}
                        onChange={(event) =>
                          updateRow(index, {
                            ...row,
                            interval: Math.max(1, Number(event.target.value) || 1),
                          })
                        }
                      />
                      <span className="pc-admin-label__help">
                        {strings.repeatIntervalHelp ?? "For example, every 2 weeks."}
                      </span>
                    </label>

                    <label className="pc-admin-label">
                      <span className="pc-admin-label__text">
                        {strings.repeatUntil ?? "Repeat until"}
                      </span>
                      <input
                        type="date"
                        className="pc-admin-input"
                        value={row.repeat_until}
                        onChange={(event) =>
                          updateRow(index, { ...row, repeat_until: event.target.value })
                        }
                      />
                    </label>
                  </div>
                ) : null}

                {isWeekly ? (
                  <label className="pc-admin-label">
                    <span className="pc-admin-label__text">{strings.repeatOn ?? "Repeat on"}</span>
                    <div className="pc-admin-weekdays">
                      {WEEKDAYS.map((weekday) => {
                        const checked = row.by_weekday.includes(weekday.code);

                        return (
                          <label key={weekday.code} className="pc-admin-weekday">
                            <input
                              type="checkbox"
                              className="pc-admin-checkbox"
                              checked={checked}
                              onChange={(event) =>
                                updateRow(index, {
                                  ...row,
                                  by_weekday: event.target.checked
                                    ? [...row.by_weekday, weekday.code]
                                    : row.by_weekday.filter((value) => value !== weekday.code),
                                })
                              }
                            />
                            <span className="pc-admin-weekday__label">
                              {strings[weekday.key] ?? weekday.code}
                            </span>
                          </label>
                        );
                      })}
                    </div>
                  </label>
                ) : null}
              </div>
            </div>
          );
        })}
      </div>

      <div className="pc-admin-panel__body">
        <div className="pc-admin-event__action_row">
          <button
            type="button"
            className="pc-admin-button pc-admin-button--secondary"
            onClick={appendRow}
          >
            {strings.addEvent ?? "Add event"}
          </button>
        </div>
      </div>
    </div>
  );
}
