import { useState } from "react";
import type {
  SettingsDiscordGuild,
  SettingsPostType,
  SettingsRuntime,
  SettingsRuntimeStrings,
  SettingsSourceRow,
} from "../../types.ts";

function generateId(): string {
  return "src-" + Math.random().toString(36).slice(2, 10);
}

function getRuntimeStrings(runtime: SettingsRuntime): Required<SettingsRuntimeStrings> {
  const defaults: Required<SettingsRuntimeStrings> = {
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
  };
  return { ...defaults, ...(runtime.strings ?? {}) };
}

/* ------------------------------------------------------------------ */
/*  Initial row builder                                                */
/* ------------------------------------------------------------------ */

function buildInitialRows(runtime: SettingsRuntime): SettingsSourceRow[] {
  const result: SettingsSourceRow[] = [];

  for (const pt of runtime.postTypes ?? []) {
    if (pt.enabled) {
      result.push({ type: "wp", postType: pt.name, eventCount: pt.eventCount });
    }
  }

  for (const feed of runtime.icalFeeds ?? []) {
    result.push({
      type: "ical",
      id: feed.id,
      name: feed.name,
      url: feed.url,
      color: feed.color,
      enabled: feed.enabled,
    });
  }

  for (const guild of runtime.discordGuilds ?? []) {
    if (guild.enabled) {
      result.push({
        type: "discord",
        guildId: guild.guild_id,
        name: guild.name,
        enabled: true,
      });
    }
  }

  return result;
}

/* ------------------------------------------------------------------ */
/*  Main component                                                     */
/* ------------------------------------------------------------------ */

interface AdminSettingsProps {
  runtime: SettingsRuntime;
}

export default function AdminSettings({ runtime }: AdminSettingsProps) {
  const [rows, setRows] = useState<SettingsSourceRow[]>(() => buildInitialRows(runtime));
  const [discordGuilds, setDiscordGuilds] = useState<SettingsDiscordGuild[]>(
    () => runtime.discordGuilds ?? [],
  );

  const strings = getRuntimeStrings(runtime);
  const fieldName = runtime.sourcesOptionName ?? "post_calendar_sources";
  const postTypesFieldName = runtime.postTypesOptionName ?? "post_calendar_post_types";

  const allPostTypes = runtime.postTypes ?? [];
  const allGuilds = discordGuilds;

  // ids of iCal feeds that were loaded from the server (i.e. already persisted).
  // Any iCal row whose id is not in this set is a brand-new, unsaved feed and
  // must remain in editing mode until the whole settings form is submitted.
  const persistedFeedIds = new Set((runtime.icalFeeds ?? []).map((feed) => feed.id));

  const wpTypesInRows = new Set(rows.filter((r) => r.type === "wp").map((r) => r.postType));
  const availableWpTypes = allPostTypes.filter((pt) => !wpTypesInRows.has(pt.name));
  const connected = runtime.discordConfigured ?? false;
  const canAddDiscord =
    connected &&
    allGuilds.some((g) => !rows.some((r) => r.type === "discord" && r.guildId === g.guild_id));

  function addRow(row: SettingsSourceRow): void {
    setRows((prev) => [...prev, row]);
  }

  function removeRow(index: number): void {
    setRows((prev) => prev.filter((_, i) => i !== index));
  }

  function updateRow(index: number, updates: Partial<SettingsSourceRow>): void {
    setRows((prev) =>
      prev.map((r, i) => (i === index ? ({ ...r, ...updates } as SettingsSourceRow) : r)),
    );
  }

  async function refreshDiscordGuilds(): Promise<void> {
    const baseUrl = runtime.restUrl;
    const route = runtime.discordGuildsRoute ?? "/discord/guilds";
    if (!baseUrl) {
      return;
    }
    const url = baseUrl.replace(/\/$/, "") + route;
    try {
      const response = await fetch(url, {
        headers: runtime.restNonce ? { "X-WP-Nonce": runtime.restNonce } : {},
      });
      if (!response.ok) {
        return;
      }
      const payload = (await response.json()) as {
        guilds?: SettingsDiscordGuild[];
        error?: string;
      };
      if (payload.error || !payload.guilds) {
        return;
      }
      setDiscordGuilds(payload.guilds);
    } catch {
      // Leave the current guild list in place on network failure.
    }
  }

  const stats = runtime.statistics;
  const wpCount =
    stats?.totalWpEvents ??
    rows.filter((r) => r.type === "wp").reduce((sum, r) => sum + r.eventCount, 0);
  const icalCount = stats?.totalIcalFeeds ?? rows.filter((r) => r.type === "ical").length;
  const totalEvents = wpCount;
  const discordConfigured = runtime.discordConfigured ?? false;
  const enabledDiscordGuilds = discordGuilds.filter((g) => g.enabled);

  return (
    <div className="pc-admin-panel">
      <div className="pc-admin-panel__body">
        <div className="pc-admin-event__action_row">
          <div style={{ marginRight: "auto" }}>
            <span className="pc-admin-settings__meta-badge pc-admin-settings__meta-badge--total">
              {strings.totalEvents.replace("%d", String(totalEvents))}
            </span>

            {icalCount > 0 && (
              <span className="pc-admin-settings__meta-badge">
                {strings.byIcal.replace("%d", String(icalCount))}
              </span>
            )}
            {discordConfigured && (
              <span className="pc-admin-settings__meta-badge pc-admin-settings__meta-badge--discord">
                {enabledDiscordGuilds.length > 0 ? (
                  <>
                    {strings.discordConnected} ·{" "}
                    {strings.discordServers.replace("%d", String(enabledDiscordGuilds.length))}
                  </>
                ) : (
                  strings.discordConnected
                )}
              </span>
            )}
            {!discordConfigured && (
              <span className="pc-admin-settings__meta-badge pc-admin-settings__meta-badge--muted">
                {strings.discordNotConfigured}
              </span>
            )}
          </div>

          <AddSourceButton
            strings={strings}
            availableWpTypes={availableWpTypes}
            canAddDiscord={canAddDiscord}
            allGuilds={allGuilds}
            rows={rows}
            addRow={addRow}
            onOpenDiscord={() => void refreshDiscordGuilds()}
          />
        </div>
      </div>

      <div className="pc-admin-panel__body">
        {rows.length === 0 ? <p className="pc-admin-intro">{strings.noSources}</p> : null}

        {rows.map((row, index) => {
          if (row.type === "wp") {
            return (
              <div key={`wp-${row.postType}`} className="pc-admin-event-card">
                <div className="pc-admin-event-card__header">
                  <div className="pc-admin-event-card__title">
                    <span className="pc-admin-settings__source-badge pc-admin-settings__source-badge--wp">
                      WP
                    </span>
                    <select
                      className="pc-admin-select"
                      value={row.postType}
                      onChange={(e) => {
                        const newName = e.target.value;
                        const pt = allPostTypes.find((p) => p.name === newName);
                        updateRow(index, {
                          postType: newName,
                          eventCount: pt?.eventCount ?? 0,
                        });
                      }}
                    >
                      {allPostTypes.map((pt) => (
                        <option
                          key={pt.name}
                          value={pt.name}
                          disabled={pt.name !== row.postType && wpTypesInRows.has(pt.name)}
                        >
                          {pt.label}
                        </option>
                      ))}
                    </select>
                  </div>
                  <button
                    type="button"
                    className="pc-admin-button pc-admin-button--danger pc-admin-button--small"
                    onClick={() => removeRow(index)}
                  >
                    {strings.removeSource}
                  </button>
                </div>
                <div className="pc-admin-event-card__body">
                  <span className="pc-admin-settings__source-meta">{row.eventCount} events</span>
                </div>
              </div>
            );
          }

          if (row.type === "ical") {
            // Keeps the row in editing mode until the settings form is submitted:
            // only feeds that were already persisted (loaded from the server) are
            // shown as committed rows. A newly added feed stays editable even once
            // its name and URL are filled in, so it never looks auto-saved.
            const isEditing = !persistedFeedIds.has(row.id) || row.name === "" || row.url === "";

            return (
              <div key={row.id} className="pc-admin-event-card">
                <div className="pc-admin-event-card__header">
                  <div className="pc-admin-event-card__title">
                    <span className="pc-admin-settings__source-badge pc-admin-settings__source-badge--ical">
                      iCal
                    </span>
                    {isEditing ? (
                      <>
                        <input
                          type="text"
                          className="pc-admin-input pc-admin-input--inline"
                          placeholder="My Calendar"
                          value={row.name}
                          onChange={(e) => updateRow(index, { name: e.target.value })}
                        />
                        <input
                          type="url"
                          className="pc-admin-input pc-admin-input--inline"
                          placeholder="https://calendar.google.com/..."
                          value={row.url}
                          onChange={(e) => updateRow(index, { url: e.target.value })}
                        />
                      </>
                    ) : (
                      <>
                        <span>{row.name}</span>
                        <code className="pc-admin-settings__source-url">{maskUrl(row.url)}</code>
                      </>
                    )}
                  </div>
                  <button
                    type="button"
                    className="pc-admin-button pc-admin-button--danger pc-admin-button--small"
                    onClick={() => removeRow(index)}
                  >
                    {strings.removeSource}
                  </button>
                </div>
              </div>
            );
          }

          if (row.type === "discord") {
            const dcGuildsInRows = new Set(
              rows
                .filter(
                  (r): r is SettingsSourceRow & { type: "discord" } =>
                    r.type === "discord" && r.guildId !== "",
                )
                .map((r) => r.guildId),
            );
            const availableGuilds = allGuilds.filter(
              (g) => !dcGuildsInRows.has(g.guild_id) || g.guild_id === row.guildId,
            );
            const isEditing = row.guildId === "";

            return (
              <div key={`dc-${row.guildId || index}`} className="pc-admin-event-card">
                <div className="pc-admin-event-card__header">
                  <div className="pc-admin-event-card__title">
                    <span className="pc-admin-settings__source-badge pc-admin-settings__source-badge--discord">
                      DC
                    </span>
                    {isEditing ? (
                      <select
                        className="pc-admin-select"
                        value={row.guildId}
                        onChange={(e) => {
                          const selected = allGuilds.find((g) => g.guild_id === e.target.value);
                          if (selected) {
                            updateRow(index, {
                              guildId: selected.guild_id,
                              name: selected.name,
                            });
                          }
                        }}
                      >
                        <option value="">Select a server…</option>
                        {availableGuilds.map((g) => (
                          <option key={g.guild_id} value={g.guild_id}>
                            {g.name}
                          </option>
                        ))}
                      </select>
                    ) : (
                      <>
                        <span>{row.name}</span>
                        <code className="pc-admin-settings__source-meta">{row.guildId}</code>
                      </>
                    )}
                  </div>
                  <button
                    type="button"
                    className="pc-admin-button pc-admin-button--danger pc-admin-button--small"
                    onClick={() => removeRow(index)}
                  >
                    {strings.removeSource}
                  </button>
                </div>
              </div>
            );
          }

          return null;
        })}
      </div>

      {/* Hidden inputs — always in DOM */}
      <div hidden>
        {rows.map((row, index) => {
          if (row.type === "wp") {
            return (
              <input
                key={`pt-${row.postType}`}
                type="hidden"
                name={`${postTypesFieldName}[]`}
                value={row.postType}
                readOnly
              />
            );
          }
          if (row.type === "ical") {
            return (
              <span key={row.id}>
                <input
                  type="hidden"
                  name={`${fieldName}[ical_feeds][${index}][id]`}
                  value={row.id}
                  readOnly
                />
                <input
                  type="hidden"
                  name={`${fieldName}[ical_feeds][${index}][name]`}
                  value={row.name}
                  readOnly
                />
                <input
                  type="hidden"
                  name={`${fieldName}[ical_feeds][${index}][url]`}
                  value={row.url}
                  readOnly
                />
                <input
                  type="hidden"
                  name={`${fieldName}[ical_feeds][${index}][color]`}
                  value={row.color}
                  readOnly
                />
                <input
                  type="hidden"
                  name={`${fieldName}[ical_feeds][${index}][enabled]`}
                  value={row.enabled ? "1" : "0"}
                  readOnly
                />
              </span>
            );
          }
          if (row.type === "discord") {
            if (!row.guildId) return null;
            return (
              <span key={`dc-${row.guildId}`}>
                <input
                  type="hidden"
                  name={`${fieldName}[discord][guilds][${row.guildId}][guild_id]`}
                  value={row.guildId}
                  readOnly
                />
                <input
                  type="hidden"
                  name={`${fieldName}[discord][guilds][${row.guildId}][name]`}
                  value={row.name}
                  readOnly
                />
                <input
                  type="hidden"
                  name={`${fieldName}[discord][guilds][${row.guildId}][enabled]`}
                  value="1"
                  readOnly
                />
              </span>
            );
          }
          return null;
        })}

        <input type="hidden" name={`${postTypesFieldName}[]`} value="" />
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/*  Add source button                                                  */
/* ------------------------------------------------------------------ */

interface AddSourceButtonProps {
  strings: Required<SettingsRuntimeStrings>;
  availableWpTypes: SettingsPostType[];
  canAddDiscord: boolean;
  allGuilds: Array<{ guild_id: string; name: string }>;
  rows: SettingsSourceRow[];
  addRow: (row: SettingsSourceRow) => void;
  onOpenDiscord?: () => void;
}

function AddSourceButton({
  strings,
  availableWpTypes,
  canAddDiscord,
  allGuilds: _allGuilds,
  rows: _rows,
  addRow,
  onOpenDiscord,
}: AddSourceButtonProps) {
  const [showAddMenu, setShowAddMenu] = useState(false);

  function toggleMenu(): void {
    const next = !showAddMenu;
    setShowAddMenu(next);
    // Lazily refresh the guild list whenever the add-source menu is opened so
    // Discord data is not fetched while simply viewing the settings page.
    if (next) {
      onOpenDiscord?.();
    }
  }

  return (
    <div className="pc-admin-settings__add-source">
      <button
        type="button"
        className="pc-admin-button pc-admin-button--secondary"
        onClick={toggleMenu}
      >
        {strings.addSource}
      </button>

      {showAddMenu && (
        <div className="pc-admin-settings__add-menu">
          {availableWpTypes.length > 0 && (
            <button
              type="button"
              className="pc-admin-settings__add-menu-item"
              onClick={() => {
                const pt = availableWpTypes[0];
                addRow({
                  type: "wp",
                  postType: pt.name,
                  eventCount: pt.eventCount,
                });
                setShowAddMenu(false);
              }}
            >
              {strings.addPostType}
            </button>
          )}
          <button
            type="button"
            className="pc-admin-settings__add-menu-item"
            onClick={() => {
              addRow({
                type: "ical",
                id: generateId(),
                name: "",
                url: "",
                color: "",
                enabled: true,
              });
              setShowAddMenu(false);
            }}
          >
            {strings.addIcalFeed}
          </button>
          {canAddDiscord && (
            <button
              type="button"
              className="pc-admin-settings__add-menu-item"
              onClick={() => {
                addRow({
                  type: "discord",
                  guildId: "",
                  name: "",
                  enabled: true,
                });
                setShowAddMenu(false);
              }}
            >
              {strings.addDiscordGuild}
            </button>
          )}
        </div>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function maskUrl(url: string): string {
  try {
    const parsed = new URL(url);
    const host = parsed.hostname;
    if (host.length > 12) {
      return host.slice(0, 8) + "\u2026" + host.slice(-4);
    }
    return host;
  } catch {
    return url.length > 20 ? url.slice(0, 16) + "\u2026" : url;
  }
}
