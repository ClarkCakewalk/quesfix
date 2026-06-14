<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    question: Object,
    samples: Array,
});
</script>

<template>
    <Head title="依樣本檢核" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ question.code }}｜依樣本檢核
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <FlashMessages />
                <div v-if="$page.props.errors?.lock" class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                    {{ $page.props.errors.lock }}
                </div>

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <p v-if="!samples.length" class="py-6 text-center text-sm text-gray-500">
                        目前沒有待檢核的樣本。
                    </p>

                    <table v-else class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr><th class="py-2">樣本編號</th><th class="text-right">未完成條件數</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in samples" :key="s.sample_id" class="border-b last:border-0">
                                <td class="py-2">
                                    <Link
                                        :href="route('checks.sample-conditions', [question.id, s.sample_id])"
                                        class="font-mono text-indigo-600 hover:underline"
                                    >
                                        {{ s.sample_id }}
                                    </Link>
                                    <span v-if="s.locked" class="ml-2 rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">檢核中</span>
                                </td>
                                <td class="text-right">{{ s.pending_count }}</td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Link :href="route('checks.modes', question.id)" class="mt-6 inline-block text-sm text-gray-600 hover:underline">
                    ← 回檢核作業
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
