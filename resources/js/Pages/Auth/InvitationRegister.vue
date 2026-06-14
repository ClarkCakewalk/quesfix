<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    token: String,
    email: String,
});

const form = useForm({
    username: '',
    name: '',
    gender: 0,
    unit: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register.invitation', props.token), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="完成註冊" />

        <div class="mb-4 text-sm text-gray-600">
            您受邀註冊「調查資料檢核輔助系統」帳號。請填寫以下資料完成註冊。
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel value="Email（由邀請指定，不可修改）" />
                <TextInput
                    type="email"
                    class="mt-1 block w-full bg-gray-100"
                    :model-value="email"
                    disabled
                    readonly
                />
            </div>

            <div class="mt-4">
                <InputLabel for="username" value="登入帳號（英數字）" />
                <TextInput
                    id="username"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.username"
                    required
                    autofocus
                    autocomplete="username"
                />
                <InputError class="mt-2" :message="form.errors.username" />
            </div>

            <div class="mt-4">
                <InputLabel for="name" value="姓名" />
                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autocomplete="name"
                />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div class="mt-4">
                <InputLabel for="gender" value="性別" />
                <select
                    id="gender"
                    v-model="form.gender"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option :value="0">不提供</option>
                    <option :value="1">男</option>
                    <option :value="2">女</option>
                </select>
                <InputError class="mt-2" :message="form.errors.gender" />
            </div>

            <div class="mt-4">
                <InputLabel for="unit" value="服務單位" />
                <TextInput
                    id="unit"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.unit"
                    required
                />
                <InputError class="mt-2" :message="form.errors.unit" />
            </div>

            <div class="mt-4">
                <InputLabel
                    for="password"
                    value="密碼（至少 8 碼，含大小寫字母與數字）"
                />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4">
                <InputLabel for="password_confirmation" value="密碼確認" />
                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />
                <InputError
                    class="mt-2"
                    :message="form.errors.password_confirmation"
                />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <Link
                    :href="route('login')"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    已有帳號？登入
                </Link>

                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    完成註冊
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
