<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    question: Object,
    member: Object,
    items: Array,
});
</script>

<template>
    <Head :title="`覆核：${member.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ question.code }}｜覆核｜{{ member.name }} 完成的邏輯檢核
                </h2>
                <Link :href="route('checks.recheck-members', question.id)" class="shrink-0 text-sm text-gray-600 hover:underline">
                    ← 回成員列表
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <FlashMessages />

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <p v-if="!items.length" class="py-6 text-center text-sm text-gray-500">
                        該成員尚無已完成的檢核。
                    </p>

                    <table v-else class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th class="py-2">條件代號</th>
                                <th>條件描述</th>
                                <th class="text-right">完成樣本數</th>
                                <th class="text-right">已覆核</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id" class="border-b last:border-0">
                                <td class="py-2 font-mono">
                                    <Link
                                        :href="route('checks.recheck-samples', [question.id, member.id, item.id])"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        {{ item.item_name }}
                                    </Link>
                                </td>
                                <td class="text-gray-600">{{ item.description }}</td>
                                <td class="text-right">{{ item.done_count }}</td>
                                <td class="text-right text-gray-500">{{ item.rechecked_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
