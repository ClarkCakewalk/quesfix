<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    question: Object,
    items: Array,
});
</script>

<template>
    <Head title="依邏輯檢核" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ question.code }}｜依邏輯檢核
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <FlashMessages />

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <p v-if="!items.length" class="py-6 text-center text-sm text-gray-500">
                        目前沒有待檢核的條件。
                    </p>

                    <table v-else class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr><th class="py-2">條件代號</th><th>條件描述</th><th class="text-right">未完成樣本數</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id" class="border-b last:border-0">
                                <td class="py-2 font-mono">
                                    <Link
                                        :href="route('checks.logic-samples', [question.id, item.id])"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        {{ item.item_name }}
                                    </Link>
                                </td>
                                <td class="text-gray-600">{{ item.description }}</td>
                                <td class="text-right">{{ item.pending_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
