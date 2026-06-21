<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    question: Object,
});

const form = useForm({ sample_id: '' });

const submit = () => {
    form.post(route('checks.edit-search', props.question.id));
};
</script>

<template>
    <Head title="資料修改" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ question.code }}｜資料修改
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <FlashMessages />

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="mb-1 text-lg font-medium">輸入樣本編號</h3>
                    <p class="mb-4 text-sm text-gray-500">
                        輸入欲修改的樣本編號，系統確認該樣本存在且未被鎖定後，進入資料修改介面。
                    </p>

                    <form @submit.prevent="submit" class="flex items-start gap-2">
                        <div class="flex-1">
                            <input
                                v-model="form.sample_id"
                                type="text"
                                placeholder="樣本編號"
                                class="w-full rounded-md border-gray-300 font-mono text-sm shadow-sm"
                                autofocus
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.sample_id" />
                        </div>
                        <button
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white hover:bg-indigo-500"
                            :disabled="form.processing"
                        >
                            進入修改
                        </button>
                    </form>
                </div>

                <Link
                    :href="route('checks.modes', question.id)"
                    class="mt-6 inline-block text-sm text-gray-600 hover:underline"
                >
                    ← 回檢核作業
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
