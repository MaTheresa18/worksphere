<script setup>
import { ref, onMounted } from "vue";

import {
    ShieldCheckIcon,
    NoSymbolIcon,
    ExclamationTriangleIcon,
    ClockIcon,
    LockClosedIcon,
    ArrowPathIcon,
    GlobeAmericasIcon,
    MapIcon,
    UserMinusIcon, // Added UserMinusIcon
    PauseCircleIcon, // Added PauseCircleIcon
} from "@heroicons/vue/24/outline";
import DashboardStatsCard from "@/components/admin/DashboardStatsCard.vue";
import SecurityActivityFeed from "@/components/admin/SecurityActivityFeed.vue";
import BlockedIpsTable from "@/components/admin/BlockedIpsTable.vue";
import BannedUsersTable from "@/components/admin/BannedUsersTable.vue";
import SuspiciousActivityTable from "@/components/admin/SuspiciousActivityTable.vue";
import WhitelistedIpsTable from "@/components/admin/WhitelistedIpsTable.vue";
import DashboardLineChart from "@/components/charts/DashboardLineChart.vue";
import DashboardDoughnutChart from "@/components/charts/DashboardDoughnutChart.vue";
import SecurityMap from "@/components/admin/SecurityMap.vue";
import api from "@/lib/api";

// Alias api to axios to avoid changing all calls
const axios = api;

const activeTab = ref("overview");
const stats = ref({
    blocked_ips: 0,
    whitelisted_ips: 0,
    banned_users: 0,
    suspended_users: 0,
    incidents_today: 0,
});
const loadingStats = ref(true);

// Chart Data
const chartData = ref({
    trend: { labels: [], datasets: [] },
    distribution: { labels: [], data: [] }
});
const loadingCharts = ref(true);

// Map Data
const mapData = ref([]);
const loadingMap = ref(true);

const fetchMapData = async () => {
    loadingMap.value = true;
    try {
        const response = await api.get("/api/admin/security/map-data");
        mapData.value = response.data || [];
    } catch (error) {
        console.error("Failed to fetch map data", error);
    } finally {
        loadingMap.value = false;
    }
};

const fetchStats = async () => {
    loadingStats.value = true;
    try {
        const response = await api.get("/api/admin/security/stats");
        stats.value = { ...stats.value, ...response.data };
    } catch (error) {
        console.error("Failed to fetch security stats", error);
    } finally {
        loadingStats.value = false;
    }
};

const fetchCharts = async () => {
    loadingCharts.value = true;
    try {
        const response = await api.get("/api/admin/security/charts");
        const { trend = [], distribution = [] } = response.data || {};

        // Process Trend Data
        chartData.value.trend = {
            labels: trend.map(t => t.label),
            datasets: [
                {
                    label: 'Security Incidents',
                    data: trend.map(t => t.count),
                    borderColor: 'rgb(139, 92, 246)',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                }
            ]
        };

        // Process Distribution Data
        chartData.value.distribution = {
            labels: distribution.map(d => d.label),
            data: distribution.map(d => d.count)
        };
    } catch (error) {
        console.error("Failed to fetch security charts", error);
    } finally {
        loadingCharts.value = false;
    }
};

const refreshDashboard = () => {
    fetchStats();
    fetchCharts();
    fetchMapData();
};

onMounted(() => {
    refreshDashboard();
});
</script>

<template>
    <div class="p-4 sm:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-8">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-[var(--surface-card)] p-6 rounded-2xl shadow-sm border border-[var(--border-default)]">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-[var(--text-primary)]">
                    Security Dashboard
                </h1>
                <p class="text-[var(--text-secondary)] mt-1 text-lg">
                    Real-time monitoring and management of system security protocols.
                </p>
            </div>
            <div class="flex gap-3">
                <button
                    @click="refreshDashboard"
                    class="btn btn-secondary flex items-center gap-2 px-6 hover:shadow-md transition-all duration-200"
                    :disabled="loadingStats || loadingCharts"
                >
                    <ArrowPathIcon class="w-5 h-5" :class="{ 'animate-spin': loadingStats || loadingCharts }" />
                    Refresh Data
                </button>
            </div>
        </div>

        <!-- Stats Overview -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <DashboardStatsCard
                title="Blocked IP Addresses"
                :value="stats.blocked_ips ?? 0"
                icon="NoSymbolIcon"
                color="text-red-500"
                bg-color="bg-red-500/10"
                class="hover:scale-[1.02] transition-transform duration-200"
            />
            <DashboardStatsCard
                title="Whitelisted IPs"
                :value="stats.whitelisted_ips ?? 0"
                icon="ShieldCheckIcon"
                color="text-emerald-500"
                bg-color="bg-emerald-500/10"
                class="hover:scale-[1.02] transition-transform duration-200"
            />
            <DashboardStatsCard
                title="Banned User Logins"
                :value="stats.banned_users ?? 0"
                icon="UserMinusIcon"
                color="text-orange-500"
                bg-color="bg-orange-500/10"
                class="hover:scale-[1.02] transition-transform duration-200"
            />
            <DashboardStatsCard
                title="Suspended Accounts"
                :value="stats.suspended_users ?? 0"
                icon="PauseCircleIcon"
                color="text-yellow-500"
                bg-color="bg-yellow-500/10"
                class="hover:scale-[1.02] transition-transform duration-200"
            />
            <DashboardStatsCard
                title="Critical Incidents (24h)"
                :value="stats.incidents_today ?? 0"
                icon="ExclamationTriangleIcon"
                color="text-blue-500"
                bg-color="bg-blue-500/10"
                class="hover:scale-[1.02] transition-transform duration-200"
            />
        </div>

        <!-- Navigation Tabs -->
        <div class="flex border-b border-[var(--border-default)] overflow-x-auto gap-8 no-scrollbar bg-[var(--surface-card)] px-6 rounded-xl shadow-sm">
            <button
                @click="activeTab = 'overview'"
                class="py-4 px-2 text-base font-semibold border-b-2 transition-all duration-200 whitespace-nowrap flex items-center gap-2"
                :class="activeTab === 'overview' ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]' : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)]'"
            >
                <ShieldCheckIcon class="w-5 h-5" />
                Overview
            </button>
            <button
                @click="activeTab = 'threat-map'"
                class="py-4 px-2 text-base font-semibold border-b-2 transition-all duration-200 whitespace-nowrap flex items-center gap-2"
                :class="activeTab === 'threat-map' ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]' : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)]'"
            >
                <GlobeAmericasIcon class="w-5 h-5" />
                Threat Map
            </button>
            <button
                @click="activeTab = 'suspicious-activity'"
                class="py-4 px-2 text-base font-semibold border-b-2 transition-all duration-200 whitespace-nowrap flex items-center gap-2"
                :class="activeTab === 'suspicious-activity' ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]' : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)]'"
            >
                <ExclamationTriangleIcon class="w-5 h-5" />
                Suspicious Activity
            </button>
            <button
                @click="activeTab = 'blocked-ips'"
                class="py-4 px-2 text-base font-semibold border-b-2 transition-all duration-200 whitespace-nowrap flex items-center gap-2"
                :class="activeTab === 'blocked-ips' ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]' : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)]'"
            >
                <NoSymbolIcon class="w-5 h-5" />
                Blocked IPs
            </button>
            <button
                @click="activeTab = 'banned-users'"
                class="py-4 px-2 text-base font-semibold border-b-2 transition-all duration-200 whitespace-nowrap flex items-center gap-2"
                :class="activeTab === 'banned-users' ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]' : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)]'"
            >
                <LockClosedIcon class="w-5 h-5" />
                Banned Users
            </button>
            <button
                @click="activeTab = 'whitelisted-ips'"
                class="py-4 px-2 text-base font-semibold border-b-2 transition-all duration-200 whitespace-nowrap flex items-center gap-2"
                :class="activeTab === 'whitelisted-ips' ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]' : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)]'"
            >
                <ShieldCheckIcon class="w-5 h-5" />
                Whitelist
            </button>
        </div>

        <!-- Tab Content -->
        <div class="mt-8 transition-all duration-300">
            <!-- Overview Tab -->
            <div v-if="activeTab === 'overview'" class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
                    <!-- Charts Column -->
                    <div class="xl:col-span-2 space-y-8">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <div class="bg-[var(--surface-card)] p-6 rounded-2xl shadow-sm border border-[var(--border-default)]">
                                <h3 class="text-lg font-bold mb-6 text-[var(--text-primary)] flex items-center gap-2">
                                    <ClockIcon class="w-5 h-5 text-[var(--interactive-primary)]" />
                                    Security Incidents Trend
                                </h3>
                                <div v-if="loadingCharts" class="h-64 flex items-center justify-center">
                                    <ArrowPathIcon class="w-8 h-8 animate-spin text-[var(--text-tertiary)]" />
                                </div>
                                <DashboardLineChart
                                    v-else
                                    :labels="chartData.trend.labels"
                                    :datasets="chartData.trend.datasets"
                                />
                            </div>

                            <div class="bg-[var(--surface-card)] p-6 rounded-2xl shadow-sm border border-[var(--border-default)]">
                                <h3 class="text-lg font-bold mb-6 text-[var(--text-primary)] flex items-center gap-2">
                                    <ShieldCheckIcon class="w-5 h-5 text-[var(--interactive-primary)]" />
                                    Incident Distribution
                                </h3>
                                <div v-if="loadingCharts" class="h-64 flex items-center justify-center">
                                    <ArrowPathIcon class="w-8 h-8 animate-spin text-[var(--text-tertiary)]" />
                                </div>
                                <DashboardDoughnutChart
                                    v-else
                                    :labels="chartData.distribution.labels"
                                    :data="chartData.distribution.data"
                                />
                            </div>
                        </div>

                        <!-- Mini Map in Overview -->
                        <div class="bg-[var(--surface-card)] p-6 rounded-2xl shadow-sm border border-[var(--border-default)]">
                             <div class="flex justify-between items-center mb-6">
                                <h3 class="text-lg font-bold text-[var(--text-primary)] flex items-center gap-2">
                                    <GlobeAmericasIcon class="w-5 h-5 text-[var(--interactive-primary)]" />
                                    Global Threat Surface
                                </h3>
                                <button @click="activeTab = 'threat-map'" class="text-sm font-medium text-[var(--interactive-primary)] hover:underline">
                                    Expand View &rarr;
                                </button>
                            </div>
                            <SecurityMap :data="mapData" :loading="loadingMap" class="h-[400px]" />
                        </div>
                    </div>

                    <!-- Side Activity Feed -->
                    <div class="xl:col-span-1">
                        <div class="bg-[var(--surface-card)] p-6 rounded-2xl shadow-sm border border-[var(--border-default)] h-full">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-lg font-bold text-[var(--text-primary)] flex items-center gap-2">
                                    <ClockIcon class="w-5 h-5 text-[var(--interactive-primary)]" />
                                    Recent Security Activity
                                </h3>
                                <RouterLink
                                    :to="{ name: 'system-logs', query: { category: 'security' } }"
                                    class="text-sm font-medium text-[var(--interactive-primary)] hover:underline"
                                >
                                    View All &rarr;
                                </RouterLink>
                            </div>
                            <SecurityActivityFeed />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Threat Map Tab -->
            <div v-if="activeTab === 'threat-map'" class="animate-in fade-in slide-in-from-bottom-4 duration-500">
                <div class="bg-[var(--surface-card)] p-8 rounded-2xl shadow-sm border border-[var(--border-default)]">
                    <div class="mb-8">
                        <h2 class="text-2xl font-bold text-[var(--text-primary)]">Global Threat Map</h2>
                        <p class="text-[var(--text-secondary)] mt-2">Visualizing the origin and intensity of detected security threats across the globe.</p>
                    </div>
                    <SecurityMap :data="mapData" :loading="loadingMap" class="h-[600px]" />
                </div>
            </div>

            <!-- Suspicious Activity Tab -->
            <div v-if="activeTab === 'suspicious-activity'" class="animate-in fade-in slide-in-from-bottom-4 duration-500">
                <div class="bg-[var(--surface-card)] p-8 rounded-2xl shadow-sm border border-[var(--border-default)]">
                    <div class="mb-8">
                        <h2 class="text-2xl font-bold text-[var(--text-primary)]">Suspicious Activity Detection</h2>
                        <p class="text-[var(--text-secondary)] mt-2">Real-time detection of unusual rate limit hits, failed login attempts, and potential brute force attacks.</p>
                    </div>
                    <SuspiciousActivityTable />
                </div>
            </div>

            <!-- Blocked IPs Tab -->
            <div v-if="activeTab === 'blocked-ips'" class="animate-in fade-in duration-300">
                <BlockedIpsTable @updated="refreshDashboard" />
            </div>

            <!-- Banned Users Tab -->
            <div v-if="activeTab === 'banned-users'" class="animate-in fade-in duration-300">
                <BannedUsersTable />
            </div>

            <!-- Whitelisted IPs Tab -->
            <div v-if="activeTab === 'whitelisted-ips'" class="animate-in fade-in duration-300">
                <WhitelistedIpsTable @updated="refreshDashboard" />
            </div>
        </div>
    </div>
</template>
