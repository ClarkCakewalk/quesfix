<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    question: Object,
});

const tabs = [
    { route: 'formats.items.index', label: '題項格式' },
    { route: 'formats.labels.index', label: '數值標籤' },
    { route: 'checks.items.index', label: '檢核條件' },
    { route: 'manage.data', label: '資料匯入與檢核' },
];
</script>

<template>
    <div>
        <Link
            :href="route('questions.index')"
            class="mb-3 inline-block text-sm text-gray-600 hover:underline"
        >
            ← 回專案列表
        </Link>

        <div class="mb-4 flex gap-1 border-b">
            <Link
                v-for="t in tabs"
                :key="t.route"
                :href="route(t.route, question.id)"
                class="-mb-px border-b-2 px-4 py-2 text-sm"
                :class="
                    route().current(t.route)
                        ? 'border-indigo-600 font-medium text-indigo-700'
                        : 'border-transparent text-gray-500 hover:text-gray-800'
                "
            >
                {{ t.label }}
            </Link>
        </div>
    </div>
</template>
