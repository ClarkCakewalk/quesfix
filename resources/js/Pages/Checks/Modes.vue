<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    question: Object,
    canManage: Boolean,
});
</script>

<template>
    <Head title="檢核作業" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ question.code }}｜檢核作業
                </h2>
                <Link :href="route('questions.index')" class="shrink-0 text-sm text-gray-600 hover:underline">
                    ← 回專案列表
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2" :class="{ 'lg:grid-cols-4': canManage, 'lg:grid-cols-3': !canManage }">
                    <Link
                        :href="route('checks.by-sample', question.id)"
                        class="rounded-lg bg-white p-10 text-center shadow hover:ring-2 hover:ring-indigo-400"
                    >
                        <div class="text-2xl font-semibold text-gray-800">依樣本檢核</div>
                        <p class="mt-2 text-sm text-gray-500">列出包含未完成檢核條件的樣本，逐樣本處理</p>
                    </Link>
                    <Link
                        :href="route('checks.by-logic', question.id)"
                        class="rounded-lg bg-white p-10 text-center shadow hover:ring-2 hover:ring-indigo-400"
                    >
                        <div class="text-2xl font-semibold text-gray-800">依邏輯檢核</div>
                        <p class="mt-2 text-sm text-gray-500">列出包含未完成樣本的檢核條件，逐條件處理</p>
                    </Link>
                    <Link
                        :href="route('checks.edit-entry', question.id)"
                        class="rounded-lg bg-white p-10 text-center shadow hover:ring-2 hover:ring-indigo-400"
                    >
                        <div class="text-2xl font-semibold text-gray-800">資料修改</div>
                        <p class="mt-2 text-sm text-gray-500">輸入樣本編號，不依檢核條件直接修改該樣本資料</p>
                    </Link>
                    <Link
                        v-if="canManage"
                        :href="route('checks.recheck-members', question.id)"
                        class="rounded-lg bg-white p-10 text-center shadow ring-1 ring-amber-200 hover:ring-2 hover:ring-amber-400"
                    >
                        <div class="text-2xl font-semibold text-gray-800">覆核模式</div>
                        <p class="mt-2 text-sm text-gray-500">覆核各成員已完成的檢核內容（限專案管理者）</p>
                    </Link>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
