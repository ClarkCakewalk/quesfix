<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    maskedEmail: String,
    validMinutes: Number,
    trustDays: Number,
    status: String,
});

const form = useForm({
    code: '',
    remember_device: false,
});

const submit = () => {
    form.post(route('two-factor.challenge'), {
        onFinish: () => form.reset('code'),
    });
};

const resending = ref(false);
const resend = () => {
    resending.value = true;
    router.post(route('two-factor.resend'), {}, {
        preserveScroll: true,
        onFinish: () => (resending.value = false),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="雙重驗證" />

        <h2 class="mb-2 text-lg font-medium text-gray-900">雙重驗證</h2>

        <p class="mb-4 text-sm text-gray-600">
            系統已寄送 6 位英數字驗證碼至 <span class="font-mono">{{ maskedEmail }}</span>，
            請於 {{ validMinutes }} 分鐘內輸入以完成登入。
        </p>

        <div v-if="status" class="mb-4 text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="code" value="驗證碼" />

                <TextInput
                    id="code"
                    type="text"
                    class="mt-1 block w-full font-mono text-lg uppercase tracking-[0.4em]"
                    v-model="form.code"
                    required
                    autofocus
                    autocomplete="one-time-code"
                    maxlength="6"
                />

                <InputError class="mt-2" :message="form.errors.code" />
            </div>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox name="remember_device" v-model:checked="form.remember_device" />
                    <span class="ms-2 text-sm text-gray-600">記住此裝置（{{ trustDays }} 天內免重複驗證）</span>
                </label>
            </div>

            <div class="mt-4 flex items-center justify-between">
                <button
                    type="button"
                    @click="resend"
                    :disabled="resending"
                    class="text-sm text-gray-600 underline hover:text-gray-900 disabled:opacity-50"
                >
                    重新寄送驗證碼
                </button>

                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    驗證並登入
                </PrimaryButton>
            </div>
        </form>

        <div class="mt-6 border-t pt-4 text-center">
            <Link :href="route('login')" class="text-sm text-gray-500 hover:underline">
                改用其他帳號登入
            </Link>
        </div>
    </GuestLayout>
</template>
