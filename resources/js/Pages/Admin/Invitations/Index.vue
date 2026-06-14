<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

defineProps({
    invitations: Array,
});

const form = useForm({ email: '' });

const submit = () => {
    form.post(route('admin.invitations.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const resend = (inv) => {
    router.post(route('admin.invitations.resend', inv.id), {}, { preserveScroll: true });
};

const destroy = (inv) => {
    if (confirm(`確定刪除對 ${inv.email} 的邀請？`)) {
        router.delete(route('admin.invitations.destroy', inv.id), { preserveScroll: true });
    }
};

const statusLabel = {
    pending: { text: '待註冊', cls: 'bg-amber-100 text-amber-700' },
    accepted: { text: '已註冊', cls: 'bg-green-100 text-green-700' },
    expired: { text: '已過期', cls: 'bg-gray-100 text-gray-500' },
};
</script>

<template>
    <Head title="使用者邀請" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">使用者邀請</h2>
                <Link :href="route('admin.users.index')" class="text-sm text-gray-600 hover:underline">
                    使用者管理 →
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <FlashMessages />

                <div class="mb-6 bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="mb-1 text-lg font-medium">寄送註冊邀請</h3>
                    <p class="mb-4 text-sm text-gray-500">
                        輸入受邀者 Email，系統將寄出專屬註冊連結（{{ 7 }} 天內有效）。對方點擊連結填寫帳號、姓名、密碼等資料即完成註冊。
                    </p>
                    <form @submit.prevent="submit" class="flex items-start gap-2">
                        <div class="flex-1">
                            <input
                                v-model="form.email"
                                type="email"
                                placeholder="user@example.com"
                                class="w-full rounded-md border-gray-300 text-sm shadow-sm"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.email" />
                        </div>
                        <button
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white hover:bg-indigo-500"
                            :disabled="form.processing"
                        >
                            寄送邀請
                        </button>
                    </form>
                </div>

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="mb-4 text-lg font-medium">邀請紀錄</h3>

                    <p v-if="!invitations.length" class="py-6 text-center text-sm text-gray-500">
                        尚無邀請紀錄。
                    </p>

                    <table v-else class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th class="py-2">Email</th>
                                <th>狀態</th>
                                <th>邀請人</th>
                                <th>有效至</th>
                                <th class="text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="inv in invitations" :key="inv.id" class="border-b last:border-0">
                                <td class="py-2">{{ inv.email }}</td>
                                <td>
                                    <span
                                        class="rounded px-2 py-0.5 text-xs"
                                        :class="statusLabel[inv.status].cls"
                                    >
                                        {{ statusLabel[inv.status].text }}
                                    </span>
                                </td>
                                <td class="text-gray-600">{{ inv.invited_by }}</td>
                                <td class="text-gray-600">{{ inv.expires_at }}</td>
                                <td class="space-x-3 text-right">
                                    <button
                                        v-if="inv.status !== 'accepted'"
                                        @click="resend(inv)"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        重寄
                                    </button>
                                    <button @click="destroy(inv)" class="text-red-600 hover:underline">
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
