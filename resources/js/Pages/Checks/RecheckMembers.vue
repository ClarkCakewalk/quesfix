<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    question: Object,
    members: Array,
});
</script>

<template>
    <Head title="覆核模式" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ question.code }}｜覆核模式
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <FlashMessages />

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <p class="mb-4 text-sm text-gray-500">
                        點選成員姓名，覆核該成員已完成的檢核內容。
                    </p>

                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th class="py-2">成員</th>
                                <th class="text-right">完成檢核數</th>
                                <th class="text-right">已覆核數</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in members" :key="m.id" class="border-b last:border-0">
                                <td class="py-2">
                                    <Link
                                        v-if="m.done_count > 0"
                                        :href="route('checks.recheck-logics', [question.id, m.id])"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        {{ m.name }}
                                    </Link>
                                    <span v-else class="text-gray-400">{{ m.name }}</span>
                                    <span class="ml-1 font-mono text-xs text-gray-400">{{ m.username }}</span>
                                </td>
                                <td class="text-right">{{ m.done_count }}</td>
                                <td class="text-right text-gray-500">{{ m.rechecked_count }}</td>
                            </tr>
                            <tr v-if="!members.length">
                                <td colspan="3" class="py-6 text-center text-gray-500">此專案尚無成員。</td>
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
