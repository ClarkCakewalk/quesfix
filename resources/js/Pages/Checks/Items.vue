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
    items: Object,
    filters: Object,
});

const q = ref(props.filters.q ?? '');
const search = () =>
    router.get(route('checks.items.index', props.question.id), { q: q.value }, { preserveState: true });

const editing = ref(null);

const form = useForm({
    item_name: '',
    description: '',
    related_items: '',
    logic: '',
});

const openNew = () => {
    form.reset();
    form.clearErrors();
    editing.value = 'new';
};

const openEdit = (item) => {
    form.item_name = item.item_name;
    form.description = item.description;
    form.related_items = item.related_items;
    form.logic = item.logic;
    form.clearErrors();
    editing.value = item;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') {
        form.post(route('checks.items.store', props.question.id), opts);
    } else {
        form.patch(route('checks.items.update', [props.question.id, editing.value.id]), opts);
    }
};

const destroyItem = () => {
    if (confirm(`確定刪除檢核條件 ${editing.value.item_name}？將一併刪除其關聯的檢核結果與修正紀錄！`)) {
        router.delete(route('checks.items.destroy', [props.question.id, editing.value.id]), {
            preserveScroll: true,
            onSuccess: () => (editing.value = null),
        });
    }
};
</script>

<template>
    <Head title="檢核條件" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ question.code }}｜資料管理
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <ManageNav :question="question" />
                <FlashMessages />

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <div class="mb-4 flex items-center gap-2">
                        <form @submit.prevent="search" class="flex gap-2">
                            <input
                                v-model="q"
                                type="text"
                                placeholder="篩選條件代號、敘述、邏輯…"
                                class="w-72 rounded-md border-gray-300 text-sm"
                            />
                            <button class="rounded-md bg-gray-800 px-3 py-1.5 text-sm text-white">篩選</button>
                        </form>
                        <button @click="openNew" class="ml-auto rounded-md bg-indigo-600 px-3 py-1.5 text-sm text-white">
                            新增檢核條件
                        </button>
                    </div>

                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th class="py-2">條件代號</th>
                                <th>條件敘述</th>
                                <th>關聯題目</th>
                                <th>檢核邏輯</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in items.data" :key="item.id" class="border-b align-top last:border-0">
                                <td class="py-1.5 font-mono">{{ item.item_name }}</td>
                                <td class="max-w-64">{{ item.description }}</td>
                                <td class="max-w-40 font-mono text-xs">{{ item.related_items }}</td>
                                <td class="max-w-72 font-mono text-xs">{{ item.logic }}</td>
                                <td class="text-right">
                                    <button @click="openEdit(item)" class="text-indigo-600 hover:underline">編輯</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-if="!items.data.length" class="py-6 text-center text-sm text-gray-500">
                        尚無檢核條件。請於「資料匯入與檢核」頁批次匯入，或點「新增檢核條件」。
                    </p>

                    <div class="mt-4 flex gap-1" v-if="items.links?.length > 3">
                        <template v-for="link in items.links" :key="link.label">
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

        <Modal :show="editing !== null" @close="editing = null" max-width="2xl">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-medium">
                    {{ editing === 'new' ? '新增檢核條件' : `編輯檢核條件：${editing?.item_name}` }}
                </h3>

                <div class="space-y-3 text-sm">
                    <div v-if="editing === 'new'">
                        <label class="text-gray-600">條件代號</label>
                        <input v-model="form.item_name" class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono" />
                        <InputError :message="form.errors.item_name" />
                    </div>
                    <div>
                        <label class="text-gray-600">條件敘述</label>
                        <textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                        <InputError :message="form.errors.description" />
                    </div>
                    <div>
                        <label class="text-gray-600">關聯題目（逗號分隔題目名稱）</label>
                        <input v-model="form.related_items" class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono" />
                        <InputError :message="form.errors.related_items" />
                    </div>
                    <div>
                        <label class="text-gray-600">檢核邏輯（Stata 語法）</label>
                        <textarea
                            v-model="form.logic"
                            rows="3"
                            class="mt-1 w-full rounded-md border-gray-300 font-mono text-sm"
                        />
                        <InputError :message="form.errors.logic" />
                    </div>
                </div>

                <div class="mt-6 flex gap-3">
                    <button @click="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white" :disabled="form.processing">
                        儲存
                    </button>
                    <button
                        v-if="editing !== 'new'"
                        @click="destroyItem"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm text-white"
                    >
                        刪除條件
                    </button>
                    <button @click="editing = null" class="ml-auto text-sm text-gray-600 hover:underline">取消</button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
