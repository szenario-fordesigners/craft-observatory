export interface AnalyticsStatus {
  configured: boolean;
  apiKeyValid: boolean;
  source?: string;
}

export interface SyncFreshness {
  _syncing: boolean;
  lastSyncedAt: string | null;
  missingDays: string[];
}

export interface AnalyticsMetric {
  x: string;
  y: number;
}

export interface AnalyticsPageviewPoint {
  t?: string;
  x?: string;
  y: number | string;
}

export interface AnalyticsPageviews {
  pageviews: AnalyticsPageviewPoint[];
  sessions?: AnalyticsPageviewPoint[];
}

export interface AnalyticsStats {
  visitors?: number;
  unique?: number;
  visits?: number;
  pageviews?: number;
  sessionDurationSeconds?: number;
  comparison?: {
    visitors?: number;
    unique?: number;
    visits?: number;
    pageviews?: number;
    sessionDurationSeconds?: number;
  };
}
