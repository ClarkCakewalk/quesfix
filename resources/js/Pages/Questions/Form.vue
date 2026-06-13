<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    question: Object,
    allUsers: Array,
    allVars: { type: Array, default: () => [] },
});

const isEdit = computed(() => props.question !== null);

const form = useForm({
    code: props.question?.code ?? '',
    name: props.question?.name ?? '',
    members: props.question?.members?.map((m) => ({ ...m })) ?? [],
    week_var_id: props.question?.week_var_id ?? null,
    interviewer_var_id: props.question?.interviewer_var_id ?? null,
    report_var_ids: props.question?.report_var_ids ?? [],
});

const newMemberId = ref('');

const availableUsers = computed(() =>
    props.allUsers.filter((u) => !form.members.some((m) => m.user_id === u.id)),
);

const addMember = () => {
    const user = props.allUsers.find((u) => u.id === Number(newMemberId.value));
    if (user) {
        form.members.push({
            user_id: user.id,
            username: user.username,
            name: user.name,
            role: 2,
        });
        newMemberId.value = '';
    }
};

const removeMember = (i) => form.members.splice(i, 1);

const submit = () => {
    if (isEdit.value) {
        form.put(route('questions.update', props.question.id));
    } else {
        form.post(route('questions.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? `專案設定：${question.code}` : '新增專案'" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ isEdit ? `專案設定：${question.code}` : '新增專案' }}
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <form @submit.prevent="submit" class="space-y-6">
                    <div class="bg-white p-6 shadow sm:rounded-lg">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel for="code" value="專案代號（英數）" />
                                <TextInput id="code" v-model="form.code" class="mt-1 block w-full" required />
                                <InputError class="mt-1" :message="form.errors.code" />
                            </div>
                            <div>
                                <InputLabel for="name" value="專案名稱" />
                                <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                                <InputError class="mt-1" :message="form.errors.name" />
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 shadow sm:rounded-lg">
                        <h3 class="mb-3 text-lg font-medium">人員列表（至少一位專案管理者）</h3>

                        <div class="mb-3 flex gap-2">
                            <select v-model="newMemberId" class="rounded-md border-gray-300 text-sm">
                                <option value="" disabled>選擇使用者…</option>
                                <option v-for="u in availableUsers" :key="u.id" :value="u.id">
                                    {{ u.username }}（{{ u.name }}）
                                </option>
                            </select>
                            <button type="button" @click="addMember" class="rounded-md bg-gray-800 px-3 py-1.5 text-sm text-white">
                                加入
                            </button>
                        </div>

                        <table class="w-full text-left text-sm">
                            <thead class="border-b text-gray-500">
                                <tr><th class="py-1.5">帳號</th><th>姓名</th><th>角色</th><th></th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="(m, i) in form.members" :key="m.user_id" class="border-b last:border-0">
                                    <td class="py-1.5 font-mono">{{ m.username }}</td>
                                    <td>{{ m.name }}</td>
                                    <td>
                                        <select v-model="m.role" class="rounded-md border-gray-300 py-1 text-sm">
                                            <option :value="1">專案管理者</option>
                                            <option :value="2">檢核人員</option>
                                        </select>
                                    </td>
                                    <td class="text-right">
                                        <button type="button" @click="removeMember(i)" class="text-red-600 hover:underline">移除</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <InputError class="mt-1" :message="form.errors.members" />
                    </div>

                    <div v-if="isEdit && allVars.length" class="bg-white p-6 shadow sm:rounded-lg">
                        <h3 class="mb-3 text-lg font-medium">報表設定（訪員錯誤報表）</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="週數變數" />
                                <select v-model="form.week_var_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                    <option :value="null">（未設定）</option>
                                    <option v-for="v in allVars" :key="v.id" :value="v.id">{{ v.variable }} — {{ v.label }}</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel value="訪員代號變數" />
                                <select v-model="form.interviewer_var_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                    <option :value="null">（未設定）</option>
                                    <option v-for="v in allVars" :key="v.id" :value="v.id">{{ v.variable }} — {{ v.label }}</option>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <InputLabel value="訪問相關訊息變數（可複選，依序各佔報表一欄）" />
                                <select
                                    v-model="form.report_var_ids"
                                    multiple
                                    size="6"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                                >
                                    <option v-for="v in allVars" :key="v.id" :value="v.id">{{ v.variable }} — {{ v.label }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white hover:bg-indigo-500" :disabled="form.processing">
                            {{ isEdit ? '儲存' : '建立專案' }}
                        </button>
                        <Link :href="route('questions.index')" class="text-sm text-gray-600 hover:underline">取消</Link>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
