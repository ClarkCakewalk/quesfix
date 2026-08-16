<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    question: Object,
    member: Object,
    checkItem: Object,
    results: Array,
    errorLabels: Object,
});
</script>

<template>
    <Head :title="`覆核：${checkItem.item_name}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ question.code }}｜覆核｜{{ member.name }}｜{{ checkItem.item_name }}
            </h2>
            <p class="mt-1 text-sm text-gray-600">{{ checkItem.description }}</p>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <FlashMessages />
                <div v-if="$page.props.errors?.lock" class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                    {{ $page.props.errors.lock }}
                </div>

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <p v-if="!results.length" class="py-6 text-center text-sm text-gray-500">無已完成的樣本。</p>

                    <table v-else class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th class="py-2">樣本編號</th>
                                <th>檢核結果</th>
                                <th>檢核時間</th>
                                <th>覆核狀態</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in results" :key="r.id" class="border-b last:border-0">
                                <td class="py-2 font-mono">
                                    <Link
                                        :href="route('checks.review', [question.id, r.sample_id, checkItem.id]) + `?from=recheck&member=${member.id}`"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        {{ r.sample_id }}
                                    </Link>
                                    <span v-if="r.locked" class="ml-2 rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">處理中</span>
                                </td>
                                <td class="text-xs">
                                    <span class="rounded bg-gray-100 px-2 py-0.5">{{ errorLabels[r.error] }}</span>
                                    <span v-if="r.re_survey" class="ml-1 rounded bg-blue-100 px-2 py-0.5 text-blue-700">補問</span>
                                </td>
                                <td class="text-xs text-gray-500">{{ r.checked_at }}</td>
                                <td class="text-xs">
                                    <span v-if="r.re_checked_by" class="rounded bg-green-100 px-2 py-0.5 text-green-700">
                                        已覆核（{{ r.re_checked_by }}，{{ r.re_checked_at }}）
                                    </span>
                                    <span v-else class="text-gray-400">未覆核</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Link :href="route('checks.recheck-logics', [question.id, member.id])" class="mt-6 inline-block text-sm text-gray-600 hover:underline">
                    ← 回該成員的邏輯列表
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
