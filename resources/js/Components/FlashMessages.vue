<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const status = computed(() => page.props.flash?.status);
const warnings = computed(() => page.props.flash?.warnings ?? []);
</script>

<template>
    <div>
        <div v-if="status" class="mb-4 rounded-md bg-green-50 p-3 text-sm text-green-700">
            {{ status }}
        </div>
        <div
            v-if="warnings.length"
            class="mb-4 max-h-64 overflow-y-auto rounded-md border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-800"
        >
            <p class="mb-1 font-medium">警告（{{ warnings.length }}）：</p>
            <ul class="list-inside list-disc space-y-0.5">
                <li v-for="(w, i) in warnings" :key="i">{{ w }}</li>
            </ul>
        </div>
    </div>
</template>
