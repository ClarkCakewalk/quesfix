<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';

const user = usePage().props.auth.user;

const form = useForm({
    email: user.email,
    email_confirmation: '',
});

const submit = () => {
    form.patch(route('profile.email'));
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Email 更新</h2>

            <p class="mt-1 text-sm text-gray-600">
                更新 Email 後系統將強制登出並寄出驗證信，完成驗證前無法登入。
            </p>
        </header>

        <form @submit.prevent="submit" class="mt-6 space-y-6">
            <div>
                <InputLabel for="new_email" value="Email" />

                <TextInput
                    id="new_email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="new_email_confirmation" value="Email 確認" />

                <TextInput
                    id="new_email_confirmation"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email_confirmation"
                    required
                />

                <InputError
                    class="mt-2"
                    :message="form.errors.email_confirmation"
                />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">
                    更新 Email
                </PrimaryButton>
            </div>
        </form>
    </section>
</template>
