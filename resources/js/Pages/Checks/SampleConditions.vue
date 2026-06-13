<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    question: Object,
    sampleId: String,
    results: Array,
    errorLabels: Object,
});

const pending = computed(() => props.results.filter((r) => r.pending));
const done = computed(() => props.results.filter((r) => !r.pending));
</script>

<template>
    <Head :title="`樣本 ${sampleId}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ question.code }}｜樣本 {{ sampleId }} 檢核條件
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <FlashMessages />
                <div v-if="$page.props.errors?.lock" class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                    {{ $page.props.errors.lock }}
                </div>

                <div class="space-y-6">
                    <div class="bg-white p-6 shadow sm:rounded-lg">
                        <h3 class="mb-3 font-medium text-amber-700">未處理（{{ pending.length }}）</h3>
                        <table class="w-full text-left text-sm">
                            <tbody>
                                <tr v-for="r in pending" :key="r.id" class="border-b last:border-0">
                                    <td class="py-2 font-mono">
                                        <Link
                                            :href="route('checks.review', [question.id, sampleId, r.check_item_id])"
                                            class="text-indigo-600 hover:underline"
                                        >
                                            {{ r.item_name }}
                                        </Link>
                                    </td>
                                    <td class="text-gray-600">{{ r.description }}</td>
                                    <td class="text-right text-xs">
                                        <span v-if="r.error === 3" class="rounded bg-amber-100 px-2 py-0.5 text-amber-700">重新確認</span>
                                    </td>
                                </tr>
                                <tr v-if="!pending.length"><td class="py-3 text-sm text-gray-400">無</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-white p-6 shadow sm:rounded-lg">
                        <h3 class="mb-3 font-medium text-gray-500">已處理（{{ done.length }}）</h3>
                        <table class="w-full text-left text-sm">
                            <tbody>
                                <tr v-for="r in done" :key="r.id" class="border-b last:border-0">
                                    <td class="py-2 font-mono">
                                        <Link
                                            :href="route('checks.review', [question.id, sampleId, r.check_item_id])"
                                            class="text-indigo-600 hover:underline"
                                        >
                                            {{ r.item_name }}
                                        </Link>
                                    </td>
                                    <td class="text-gray-600">{{ r.description }}</td>
                                    <td class="text-right text-xs">
                                        <span class="rounded bg-gray-100 px-2 py-0.5">{{ errorLabels[r.error] }}</span>
                                        <span v-if="r.re_survey" class="ml-1 rounded bg-blue-100 px-2 py-0.5 text-blue-700">補問</span>
                                    </td>
                                </tr>
                                <tr v-if="!done.length"><td class="py-3 text-sm text-gray-400">無</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <Link :href="route('checks.by-sample', question.id)" class="inline-block text-sm text-gray-600 hover:underline">
                        ← 回依樣本檢核列表
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
