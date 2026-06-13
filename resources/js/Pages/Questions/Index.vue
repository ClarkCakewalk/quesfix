<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({
    questions: Array,
    canCreate: Boolean,
});

const destroyQuestion = (q) => {
    if (confirm(`確定刪除專案「${q.name}」？將一併清除該專案的所有資料與檢核紀錄！`)) {
        router.delete(route('questions.destroy', q.id));
    }
};
</script>

<template>
    <Head title="專案列表" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">專案列表</h2>
                <Link
                    v-if="canCreate"
                    :href="route('questions.create')"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white hover:bg-indigo-500"
                >
                    新增專案
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div v-if="$page.props.flash?.status" class="mb-4 rounded-md bg-green-50 p-3 text-sm text-green-700">
                    {{ $page.props.flash.status }}
                </div>

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <p v-if="!questions.length" class="text-sm text-gray-500">
                        尚無可存取的專案。
                    </p>

                    <table v-else class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th class="py-2">代號</th>
                                <th>專案名稱</th>
                                <th>成員數</th>
                                <th class="text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="q in questions" :key="q.id" class="border-b last:border-0">
                                <td class="py-2 font-mono">{{ q.code }}</td>
                                <td>{{ q.name }}</td>
                                <td>{{ q.members_count }}</td>
                                <td class="space-x-3 text-right">
                                    <Link
                                        :href="route('checks.modes', q.id)"
                                        class="text-emerald-600 hover:underline"
                                    >
                                        檢核作業
                                    </Link>
                                    <Link
                                        v-if="q.can_manage"
                                        :href="route('formats.items.index', q.id)"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        資料管理
                                    </Link>
                                    <Link
                                        v-if="q.can_manage"
                                        :href="route('questions.edit', q.id)"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        設定
                                    </Link>
                                    <button
                                        v-if="$page.props.auth.user.role === 1"
                                        @click="destroyQuestion(q)"
                                        class="text-red-600 hover:underline"
                                    >
                                        刪除
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
