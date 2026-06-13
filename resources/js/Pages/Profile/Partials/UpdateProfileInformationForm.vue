<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    gender: user.gender ?? 0,
    unit: user.unit ?? '',
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">基本資料</h2>

            <p class="mt-1 text-sm text-gray-600">
                更新姓名、性別與服務單位。
            </p>
        </header>

        <form
            @submit.prevent="form.patch(route('profile.update'))"
            class="mt-6 space-y-6"
        >
            <div>
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

            <div>
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

            <div>
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

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">儲存</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-gray-600"
                    >
                        已儲存。
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
