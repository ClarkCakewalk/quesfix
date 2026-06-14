<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    users: Object,
    filters: Object,
});

const q = ref(props.filters.q ?? '');

const search = () => {
    router.get(route('admin.users.index'), { q: q.value }, { preserveState: true });
};

const genderLabel = (g) => ({ 0: '未指定', 1: '男', 2: '女' })[g] ?? '';
</script>

<template>
    <Head title="使用者管理" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">使用者管理</h2>
                <Link
                    :href="route('admin.invitations.index')"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white hover:bg-indigo-500"
                >
                    邀請使用者
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <form @submit.prevent="search" class="mb-4 flex gap-2">
                        <input
                            v-model="q"
                            type="text"
                            placeholder="搜尋帳號、姓名、服務單位、Email"
                            class="w-80 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <button class="rounded-md bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-700">
                            搜尋
                        </button>
                    </form>

                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th class="py-2">帳號</th>
                                <th>姓名</th>
                                <th>性別</th>
                                <th>服務單位</th>
                                <th>Email</th>
                                <th>身分</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="u in users.data" :key="u.id" class="border-b last:border-0">
                                <td class="py-2 font-mono">{{ u.username }}</td>
                                <td>{{ u.name }}</td>
                                <td>{{ genderLabel(u.gender) }}</td>
                                <td>{{ u.unit }}</td>
                                <td>{{ u.email }}</td>
                                <td>
                                    <span v-if="u.role === 1" class="rounded bg-indigo-100 px-2 py-0.5 text-xs text-indigo-700">系統管理者</span>
                                    <span v-else-if="u.locked_at" class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">已鎖定</span>
                                </td>
                                <td class="text-right">
                                    <Link
                                        :href="route('admin.users.edit', u.id)"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        修改
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="mt-4 flex gap-1" v-if="users.links.length > 3">
                        <template v-for="link in users.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                v-html="link.label"
                                class="rounded px-3 py-1 text-sm"
                                :class="link.active ? 'bg-gray-800 text-white' : 'text-gray-700 hover:bg-gray-100'"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
