<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import InputError from '@/Components/InputError.vue';
import ManageNav from '@/Components/ManageNav.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    question: Object,
    options: Object,
    filters: Object,
});

const q = ref(props.filters.q ?? '');
const search = () =>
    router.get(route('formats.labels.index', props.question.id), { q: q.value }, { preserveState: true });

const editing = ref(null);

const form = useForm({ group_name: '', value: '', label: '' });

const openNew = () => {
    form.reset();
    form.clearErrors();
    editing.value = 'new';
};

const openEdit = (o) => {
    form.group_name = o.group_name;
    form.value = o.value;
    form.label = o.label;
    form.clearErrors();
    editing.value = o;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') {
        form.post(route('formats.labels.store', props.question.id), opts);
    } else {
        form.patch(route('formats.options.update', [props.question.id, editing.value.id]), opts);
    }
};

const destroyOption = () => {
    if (confirm('確定刪除此數值標籤？')) {
        router.delete(route('formats.options.destroy', [props.question.id, editing.value.id]), {
            preserveScroll: true,
            onSuccess: () => (editing.value = null),
        });
    }
};
</script>

<template>
    <Head title="數值標籤" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ question.code }}｜資料管理
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <ManageNav :question="question" />
                <FlashMessages />

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <div class="mb-4 flex items-center gap-2">
                        <form @submit.prevent="search" class="flex gap-2">
                            <input
                                v-model="q"
                                type="text"
                                placeholder="篩選標籤代號或數值標籤…"
                                class="w-72 rounded-md border-gray-300 text-sm"
                            />
                            <button class="rounded-md bg-gray-800 px-3 py-1.5 text-sm text-white">篩選</button>
                        </form>
                        <button @click="openNew" class="ml-auto rounded-md bg-indigo-600 px-3 py-1.5 text-sm text-white">
                            新增數值標籤
                        </button>
                    </div>

                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr><th class="py-2">標籤代號</th><th>數值</th><th>數值標籤</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="o in options.data" :key="o.id" class="border-b last:border-0">
                                <td class="py-1.5 font-mono">{{ o.group_name }}</td>
                                <td class="font-mono">{{ o.value }}</td>
                                <td>{{ o.label }}</td>
                                <td class="text-right">
                                    <button @click="openEdit(o)" class="text-indigo-600 hover:underline">修改</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-if="!options.data.length" class="py-6 text-center text-sm text-gray-500">尚無數值標籤。</p>

                    <div class="mt-4 flex gap-1" v-if="options.links?.length > 3">
                        <template v-for="link in options.links" :key="link.label">
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

        <Modal :show="editing !== null" @close="editing = null" max-width="md">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-medium">{{ editing === 'new' ? '新增數值標籤' : '編輯數值標籤' }}</h3>

                <div class="space-y-3 text-sm">
                    <div>
                        <label class="text-gray-600">標籤代號（修改時所有相同代號一併變更）</label>
                        <input v-model="form.group_name" class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono" />
                        <InputError :message="form.errors.group_name" />
                    </div>
                    <div>
                        <label class="text-gray-600">數值</label>
                        <input v-model="form.value" class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono" />
                        <InputError :message="form.errors.value" />
                    </div>
                    <div>
                        <label class="text-gray-600">數值說明</label>
                        <input v-model="form.label" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                        <InputError :message="form.errors.label" />
                    </div>
                </div>

                <div class="mt-6 flex gap-3">
                    <button @click="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white" :disabled="form.processing">
                        儲存
                    </button>
                    <button
                        v-if="editing !== 'new'"
                        @click="destroyOption"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm text-white"
                    >
                        刪除標籤
                    </button>
                    <button @click="editing = null" class="ml-auto text-sm text-gray-600 hover:underline">取消</button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
