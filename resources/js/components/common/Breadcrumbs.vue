<script setup>
import { computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ChevronRight, Home } from "lucide-vue-next";

const route = useRoute();
const router = useRouter();

const breadcrumbs = computed(() => {
    const crumbs = [];

    // Always start with home/dashboard
    crumbs.push({
        label: "Home",
        path: "/dashboard",
        icon: Home,
    });

    // Build parent chain from route meta
    const buildParentCrumbs = (routeMeta, params) => {
        const parentCrumbs = [];

        if (routeMeta?.breadcrumbParent) {
            const parent = routeMeta.breadcrumbParent;

            // Resolve parent path
            let parentPath = "";
            if (parent.name) {
                // Only pass params that the parent route needs
                // Don't pass all params to avoid "Discarded invalid param" warnings
                const parentParams = {};
                if (parent.paramKey) {
                    const sourceKey = parent.sourceParam || "team";
                    if (params[sourceKey]) {
                        parentParams[parent.paramKey] = params[sourceKey];
                    }
                }

                // Named route - resolve it with only relevant params
                const resolved = router.resolve({
                    name: parent.name,
                    params: parentParams,
                });
                parentPath = resolved.path;
            } else if (typeof parent.path === "function") {
                parentPath = parent.path(params);
            } else {
                parentPath = parent.path;
            }

            parentCrumbs.push({
                label: parent.label || parent.name,
                path: parentPath,
            });
        }

        return parentCrumbs;
    };

    // Add parent breadcrumbs
    const parentCrumbs = buildParentCrumbs(route.meta, route.params);
    crumbs.push(...parentCrumbs);

    // Get current route breadcrumb
    if (route.meta.breadcrumb && route.path !== "/dashboard") {
        crumbs.push({
            label: route.meta.breadcrumb,
            path: route.path,
            current: true,
        });
    }

    return crumbs;
});

function navigate(path) {
    router.push(path);
}
</script>

<template>
    <nav class="flex items-center gap-1.5 text-sm" aria-label="Breadcrumb">
        <ol class="flex items-center gap-1.5">
            <li
                v-for="(crumb, index) in breadcrumbs"
                :key="crumb.path + index"
                class="flex items-center gap-1.5"
            >
                <!-- Separator -->
                <ChevronRight
                    v-if="index > 0"
                    class="h-4 w-4 text-[var(--text-muted)]"
                />

                <!-- Crumb -->
                <button
                    v-if="!crumb.current"
                    class="flex items-center gap-1.5 text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                    @click="navigate(crumb.path)"
                >
                    <component
                        :is="crumb.icon"
                        v-if="crumb.icon"
                        class="h-4 w-4"
                    />
                    <span>{{ crumb.label }}</span>
                </button>

                <span
                    v-else
                    class="flex items-center gap-1.5 text-[var(--text-primary)] font-medium"
                >
                    <component
                        :is="crumb.icon"
                        v-if="crumb.icon"
                        class="h-4 w-4"
                    />
                    {{ crumb.label }}
                </span>
            </li>
        </ol>
    </nav>
</template>
