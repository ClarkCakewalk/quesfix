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
    vars: Object,
    filters: Object,
    groups: Array,
});

const q = ref(props.filters.q ?? '');
const search = () =>
    router.get(route('formats.items.index', props.question.id), { q: q.value }, { preserveState: true });

const editing = ref(null); // null=關閉, 'new'=新增, 物件=編輯

const form = useForm({
    item_name: '',
    item_label: '',
    variable: '',
    label: '',
    group_name: '',
    var_type: 2,
});

const openNew = () => {
    form.reset();
    form.clearErrors();
    editing.value = 'new';
};

const openEdit = (v) => {
    form.item_name = v.item_name;
    form.item_label = v.item_label;
    form.variable = v.variable;
    form.label = v.label;
    form.group_name = v.group_name ?? '';
    form.var_type = v.var_type;
    form.clearErrors();
    editing.value = v;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') {
        form.post(route('formats.items.store', props.question.id), opts);
    } else {
        form.patch(route('formats.vars.update', [props.question.id, editing.value.id]), opts);
    }
};

const destroyVar = () => {
    if (confirm(`確定刪除變數 ${editing.value.variable}？若題目已無其他變數，題目將一併刪除。`)) {
        router.delete(route('formats.vars.destroy', [props.question.id, editing.value.id]), {
            preserveScroll: true,
            onSuccess: () => (editing.value = null),
        });
    }
};

const typeLabel = { 1: '選項', 2: '數值', 3: '文字' };
</script>

<template>
    <Head title="題項格式" />

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
                                placeholder="篩選題目、變數、標籤…"
                                class="w-72 rounded-md border-gray-300 text-sm"
                            />
                            <button class="rounded-md bg-gray-800 px-3 py-1.5 text-sm text-white">篩選</button>
                        </form>
                        <button @click="openNew" class="ml-auto rounded-md bg-indigo-600 px-3 py-1.5 text-sm text-white">
                            新增題項
                        </button>
                    </div>

                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th class="py-2">題目名稱</th>
                                <th>題目標籤</th>
                                <th>變數名稱</th>
                                <th>變數標籤</th>
                                <th>數值標籤</th>
                                <th>型別</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="v in vars.data" :key="v.id" class="border-b last:border-0">
                                <td class="py-1.5 font-mono">{{ v.item_name }}</td>
                                <td class="max-w-56 truncate" :title="v.item_label">{{ v.item_label }}</td>
                                <td class="font-mono">{{ v.variable }}</td>
                                <td class="max-w-56 truncate" :title="v.label">{{ v.label }}</td>
                                <td class="font-mono">{{ v.group_name }}</td>
                                <td>{{ typeLabel[v.var_type] }}</td>
                                <td class="text-right">
                                    <button @click="openEdit(v)" class="text-indigo-600 hover:underline">修改</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-if="!vars.data.length" class="py-6 text-center text-sm text-gray-500">
                        尚無題項。請使用「資料匯入與檢核」頁批次匯入，或點「新增題項」。
                    </p>

                    <div class="mt-4 flex gap-1" v-if="vars.links?.length > 3">
                        <template v-for="link in vars.links" :key="link.label">
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

        <Modal :show="editing !== null" @close="editing = null">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-medium">{{ editing === 'new' ? '新增題項' : '編輯題項' }}</h3>

                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <label class="text-gray-600">題目名稱</label>
                        <input v-model="form.item_name" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                        <InputError :message="form.errors.item_name" />
                    </div>
                    <div>
                        <label class="text-gray-600">題目標籤</label>
                        <input v-model="form.item_label" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                        <InputError :message="form.errors.item_label" />
                    </div>
                    <div>
                        <label class="text-gray-600">變數名稱</label>
                        <input v-model="form.variable" class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono" />
                        <InputError :message="form.errors.variable" />
                    </div>
                    <div>
                        <label class="text-gray-600">變數標籤</label>
                        <input v-model="form.label" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                        <InputError :message="form.errors.label" />
                    </div>
                    <div>
                        <label class="text-gray-600">關聯數值標籤</label>
                        <input
                            v-model="form.group_name"
                            list="group-names"
                            class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono"
                            placeholder="（可空白）"
                        />
                        <datalist id="group-names">
                            <option v-for="g in groups" :key="g" :value="g" />
                        </datalist>
                        <InputError :message="form.errors.group_name" />
                    </div>
                    <div>
                        <label class="text-gray-600">型別</label>
                        <select v-model="form.var_type" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option :value="1">選項</option>
                            <option :value="2">數值</option>
                            <option :value="3">文字</option>
                        </select>
                    </div>
                </div>

                <div class="mt-6 flex gap-3">
                    <button @click="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white" :disabled="form.processing">
                        儲存
                    </button>
                    <button
                        v-if="editing !== 'new'"
                        @click="destroyVar"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm text-white"
                    >
                        刪除變數
                    </button>
                    <button @click="editing = null" class="ml-auto text-sm text-gray-600 hover:underline">取消</button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
