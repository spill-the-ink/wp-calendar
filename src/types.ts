export interface CalendarConfig {
  defaultView?: string;
  enabledViews?: string[];
  showToolbar?: boolean;
  showToolbarActions?: boolean;
  showToolbarLabel?: boolean;
  showViewMenu?: boolean;
  agendaRangeMode?: string;
  agendaRangeMonths?: number | string;
  timelineDays?: number | string;
  responsiveBreakpoint?: number | string;
  queryVars?: Record<string, unknown>;
  postTypes?: string[];
  error?: string;
}

export interface CalendarEventInput {
  id?: number | string;
  name: string;
  scheduled_start_time: string | Date;
  scheduled_end_time: string | Date;
  allDay?: boolean;
  url?: string;
  description?: string;
  tags?: string[];
  label?: { id: string; name: string; color: string } | null;
  location?: string | null;
  location_url?: string | null;
  source?: CalendarEventSource;
  postId?: number;
  postType?: string;
  eventIndex?: number;
}

export interface CalendarEventRecord extends Omit<
  CalendarEventInput,
  "scheduled_start_time" | "scheduled_end_time"
> {
  scheduled_start_time: Date;
  scheduled_end_time: Date;
  allDay: boolean;
  tags: string[];
  label: { id: string; name: string; color: string } | null;
  source: CalendarEventSource | null;
  location: string | null;
  location_url: string | null;
}

export interface CalendarEventSource {
  type: "wp" | "ical" | "discord";
  id: string | null;
  name: string | null;
}

export interface CalendarRuntime {
  restUrl?: string;
  restNonce?: string;
  locale?: string;
  previewEvents?: CalendarEventInput[];
  strings?: CalendarRuntimeStrings;
}

export interface CalendarRuntimeStrings {
  allDay?: string;
  agenda?: string;
  back?: string;
  calendarViews?: string;
  configParseError?: string;
  date?: string;
  day?: string;
  event?: string;
  loadError?: string;
  loading?: string;
  later?: string;
  missingApiUrl?: string;
  month?: string;
  next?: string;
  nextWeek?: string;
  noEvents?: string;
  showMore?: string;
  showMoreEventsForMonth?: string;
  thisWeek?: string;
  time?: string;
  today?: string;
  timeline?: string;
  tomorrow?: string;
  week?: string;
  year?: string;
}

export interface CalendarRange {
  start: Date;
  end: Date;
}

export type EventRepeatValue = "none" | "weekly" | "monthly" | "yearly";

export interface AdminEventRow {
  name: string;
  name_id: string;
  description?: string;
  location?: string;
  location_url?: string;
  all_day: boolean;
  scheduled_start_time_date: string;
  scheduled_start_time_time: string;
  scheduled_end_time_date: string;
  scheduled_end_time_time: string;
  frequency: EventRepeatValue;
  interval: number;
  by_weekday: string[];
  repeat_until: string;
}

export interface AdminRuntimeStrings {
  addEvent?: string;
  allDay?: string;
  byWeekday?: string;
  category?: string;
  categoryHelp?: string;
  description?: string;
  descriptionHelp?: string;
  doesNotRepeat?: string;
  endDate?: string;
  endTime?: string;
  every?: string;
  eventNumber?: string;
  eventsIntro?: string;
  friday?: string;
  labelNone?: string;
  labels?: Array<{ id: string; name: string; color: string }>;
  location?: string;
  locationHelp?: string;
  locationUrl?: string;
  locationUrlHelp?: string;
  monday?: string;
  monthly?: string;
  name?: string;
  nameHelp?: string;
  noEvents?: string;
  removeEvent?: string;
  repeat?: string;
  repeatIntervalHelp?: string;
  repeatOn?: string;
  repeatUntil?: string;
  saturday?: string;
  startDate?: string;
  startTime?: string;
  sunday?: string;
  thursday?: string;
  tuesday?: string;
  wednesday?: string;
  weekly?: string;
  yearly?: string;
}

export interface AdminRuntime {
  currentEvents?: AdminEventRow[];
  fieldName?: string;
  strings?: AdminRuntimeStrings;
}

export interface IcalFeed {
  id: string;
  name: string;
  url: string;
  color: string;
  enabled: boolean;
}

export interface SettingsPostType {
  name: string;
  label: string;
  singularName: string;
  eventCount: number;
  enabled: boolean;
}

export interface SettingsDiscordGuild {
  guild_id: string;
  name: string;
  enabled: boolean;
}

/** A single row in the Event Sources appendable list. */
export type SettingsSourceRow =
  | { type: "wp"; postType: string; eventCount: number }
  | { type: "ical"; id: string; name: string; url: string; color: string; enabled: boolean }
  | { type: "discord"; guildId: string; name: string; enabled: boolean };

export interface SettingsRuntimeStrings {
  eventSourcesTitle?: string;
  sourcesHeaderSummary?: string;
  totalEvents?: string;
  byPostType?: string;
  byIcal?: string;
  byDiscord?: string;
  noSources?: string;
  addSource?: string;
  addPostType?: string;
  addIcalFeed?: string;
  addDiscordGuild?: string;
  removeSource?: string;
  save?: string;
  discordConnected?: string;
  discordNotConfigured?: string;
  discordServers?: string;
}

export interface SettingsStatistics {
  totalWpEvents: number;
  totalIcalFeeds: number;
  totalDiscordGuilds: number;
}

export interface SettingsRuntime {
  postTypes?: SettingsPostType[];
  icalFeeds?: IcalFeed[];
  sourcesOptionName?: string;
  postTypesOptionName?: string;
  removeEventsAction?: string;
  statistics?: SettingsStatistics;
  discordConfigured?: boolean;
  discordGuilds?: SettingsDiscordGuild[];
  restUrl?: string;
  restNonce?: string;
  discordGuildsRoute?: string;
  strings?: SettingsRuntimeStrings;
}
