export interface AnalyticsStat {
    id: number;
    label: string;
    value: string;
    change: string;
    trend: 'up' | 'down';
    icon: string;
}

export interface AnalyticsChartPoint {
    date: string;
    count: number;
}

export interface TopPage {
    path: string;
    views: string;
    unique: string;
    avgTime: string;
}

export interface TrafficSource {
    source: string;
    visits: number;
    percentage: number;
}

export type AnalyticsPeriod = '24h' | '7d' | '30d' | '90d' | 'year';

export interface Demographics {
    devices: Record<string, number>;
    browsers: { label: string; value: number }[];
    os: { label: string; value: number }[];
}

export interface GeoStat {
    country: string;
    city: string;
    iso_code: string;
    lat: number;
    lon: number;
    count: number;
}

export interface AnalyticsState {
    overview: AnalyticsStat[];
    chart: AnalyticsChartPoint[];
    topPages: TopPage[];
    sources: TrafficSource[];
    demographics: Demographics | null;
    geoStats: GeoStat[];
    loading: boolean;
    period: AnalyticsPeriod;
}
