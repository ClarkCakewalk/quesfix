<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    question: Object,
});
</script>

<template>
    <Head title="檢核作業" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ question.code }}｜檢核作業
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto grid max-w-3xl grid-cols-2 gap-6 sm:px-6 lg:px-8">
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
            </div>
        </div>
    </AuthenticatedLayout>
</template>
