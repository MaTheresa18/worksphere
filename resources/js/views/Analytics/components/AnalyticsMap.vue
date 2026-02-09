<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Card } from '@/components/ui';
import { useAnalyticsStore } from '@/stores/analytics';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const store = useAnalyticsStore();
const mapContainer = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
let markers: L.LayerGroup | null = null;

// Fix Leaflet icon paths
delete (L.Icon.Default.prototype as any)._getIconUrl;
L.Icon.Default.mergeOptions({
    iconRetinaUrl: new URL('leaflet/dist/images/marker-icon-2x.png', import.meta.url).href,
    iconUrl: new URL('leaflet/dist/images/marker-icon.png', import.meta.url).href,
    shadowUrl: new URL('leaflet/dist/images/marker-shadow.png', import.meta.url).href,
});

onMounted(() => {
    if (mapContainer.value) {
        initMap();
    }
});

watch(() => store.geoStats, () => {
    refreshMarkers();
}, { deep: true });

function initMap() {
    if (!mapContainer.value) return;

    map = L.map(mapContainer.value).setView([20, 0], 2);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    markers = L.layerGroup().addTo(map);
    refreshMarkers();
}

function refreshMarkers() {
    if (!map || !markers) return;

    markers.clearLayers();

    store.geoStats.forEach(stat => {
        if (stat.lat && stat.lon) {
            const popupContent = `
                <div class="text-sm">
                    <strong>${stat.city}, ${stat.country}</strong><br>
                    ${stat.count} visits
                </div>
            `;
            
            L.circleMarker([stat.lat, stat.lon], {
                radius: Math.min(Math.max(stat.count * 2, 4), 20), // Scale radius by count
                fillColor: '#3b82f6',
                color: '#2563eb',
                weight: 1,
                opacity: 1,
                fillOpacity: 0.6
            })
            .bindPopup(popupContent)
            .addTo(markers!);
        }
    });
}
</script>

<template>
    <Card padding="lg">
        <h2 class="text-lg font-semibold text-[var(--text-primary)] mb-4">Visitor Locations</h2>
        <div class="h-96 w-full rounded-lg overflow-hidden z-0" ref="mapContainer"></div>
    </Card>
</template>

<style scoped>
/* Ensure map tiles render correctly */
:deep(.leaflet-pane) {
    z-index: 10;
}
</style>
