<script setup>
import { computed } from "vue";
import { CheckboxRoot, CheckboxIndicator } from "reka-ui";
import { Check } from "lucide-vue-next";
import { cn } from "@/lib/utils";

const props = defineProps({
    modelValue: [Boolean, String],
    disabled: Boolean,
    label: String,
    description: String,
    id: String,
    value: String,
});

const emit = defineEmits(["update:modelValue"]);

const proxyChecked = computed({
    get() {
        return props.modelValue;
    },
    set(val) {
        emit("update:modelValue", val);
    },
});

const checkboxId = computed(
    () => props.id || `checkbox-${Math.random().toString(36).slice(2, 9)}`,
);

</script>

<template>
    <div class="flex items-start gap-3">
        <CheckboxRoot
            v-model="proxyChecked"
            :id="checkboxId"
            :disabled="disabled"
            :value="value"
            :class="
                cn(
                    'peer h-5 w-5 shrink-0 rounded-md border transition-all duration-150 flex items-center justify-center cursor-pointer',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--interactive-primary)/20',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    proxyChecked
                        ? 'bg-(--interactive-primary) border-(--interactive-primary) shadow-[inset_0_1px_1px_rgba(255,255,255,0.2)]'
                        : 'border-(--border-default) bg-(--surface-elevated) hover:border-(--border-strong)',
                )
            "
        >
            <CheckboxIndicator class="flex items-center justify-center text-white">
                <Check class="h-3.5 w-3.5" :stroke-width="4" />
            </CheckboxIndicator>
        </CheckboxRoot>

        <div v-if="label || description || $slots.default" class="space-y-0.5">
            <label
                :for="checkboxId"
                :class="
                    cn(
                        'text-sm font-medium text-(--text-primary) cursor-pointer select-none',
                        disabled && 'cursor-not-allowed opacity-50',
                    )
                "
            >
                <slot>{{ label }}</slot>
            </label>
            <p v-if="description" class="text-sm text-(--text-muted)">
                {{ description }}
            </p>
        </div>
    </div>
</template>
