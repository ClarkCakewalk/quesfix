<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    editUser: Object,
});

const emailForm = useForm({ email: props.editUser.email });

const status = () => usePage().props.flash?.status;

const updateRole = (q, role) => {
    router.patch(
        route('admin.users.project-role', [props.editUser.id, q.id]),
        { role: Number(role) },
        { preserveScroll: true },
    );
};

const revoke = (q) => {
    if (confirm(`確定移除「${q.name}」的所有權限？`)) {
        router.delete(route('admin.users.revoke-project', [props.editUser.id, q.id]), {
            preserveScroll: true,
        });
    }
};

const resetPassword = () => {
    if (confirm('將寄出重設密碼信給該使用者，並解除帳戶鎖定。確定？')) {
        router.post(route('admin.users.reset-password', props.editUser.id), {}, { preserveScroll: true });
    }
};

const makeAdmin = () => {
    if (confirm('確定將此使用者設為系統管理者？')) {
        router.post(route('admin.users.make-admin', props.editUser.id), {}, { preserveScroll: true });
    }
};

const destroyUser = () => {
    if (confirm('確定刪除此使用者？此動作無法復原。')) {
        router.delete(route('admin.users.destroy', props.editUser.id));
    }
};
</script>

<template>
    <Head :title="`使用者：${editUser.username}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                使用者：{{ editUser.username }}
                <span v-if="editUser.locked_at" class="ml-2 rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">已鎖定</span>
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="$page.props.flash?.status" class="rounded-md bg-green-50 p-3 text-sm text-green-700">
                    {{ $page.props.flash.status }}
                </div>

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="mb-4 text-lg font-medium">基本資料（不可修改）</h3>
                    <dl class="grid grid-cols-3 gap-3 text-sm">
                        <div><dt class="text-gray-500">姓名</dt><dd>{{ editUser.name }}</dd></div>
                        <div><dt class="text-gray-500">性別</dt><dd>{{ { 0: '未指定', 1: '男', 2: '女' }[editUser.gender] }}</dd></div>
                        <div><dt class="text-gray-500">服務單位</dt><dd>{{ editUser.unit }}</dd></div>
                    </dl>
                </div>

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="mb-4 text-lg font-medium">Email（修改後須重新驗證）</h3>
                    <form
                        @submit.prevent="emailForm.patch(route('admin.users.email', editUser.id), { preserveScroll: true })"
                        class="flex items-start gap-2"
                    >
                        <div class="flex-1">
                            <input
                                v-model="emailForm.email"
                                type="email"
                                class="w-full rounded-md border-gray-300 text-sm shadow-sm"
                                required
                            />
                            <InputError class="mt-1" :message="emailForm.errors.email" />
                        </div>
                        <button class="rounded-md bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-700">
                            更新 Email
                        </button>
                    </form>
                </div>

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="mb-4 text-lg font-medium">參與專案</h3>
                    <p v-if="!editUser.questions.length" class="text-sm text-gray-500">未參與任何專案。</p>
                    <table v-else class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr><th class="py-2">代號</th><th>專案名稱</th><th>角色</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="q in editUser.questions" :key="q.id" class="border-b last:border-0">
                                <td class="py-2 font-mono">{{ q.code }}</td>
                                <td>{{ q.name }}</td>
                                <td>
                                    <select
                                        :value="q.role"
                                        @change="updateRole(q, $event.target.value)"
                                        class="rounded-md border-gray-300 py-1 text-sm"
                                    >
                                        <option :value="1">專案管理者</option>
                                        <option :value="2">檢核人員</option>
                                    </select>
                                </td>
                                <td class="text-right">
                                    <button @click="revoke(q)" class="text-red-600 hover:underline">除權</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="mb-4 text-lg font-medium">帳號操作</h3>
                    <div class="flex gap-3">
                        <button @click="resetPassword" class="rounded-md bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-700">
                            重設密碼
                        </button>
                        <button
                            v-if="editUser.role !== 1"
                            @click="makeAdmin"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white hover:bg-indigo-500"
                        >
                            設定為系統管理者
                        </button>
                        <button
                            v-if="editUser.role !== 1"
                            @click="destroyUser"
                            class="rounded-md bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-500"
                        >
                            刪除使用者
                        </button>
                        <Link
                            :href="route('admin.users.index')"
                            class="ml-auto self-center text-sm text-gray-600 hover:underline"
                        >
                            回使用者列表
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
