<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    question: Object,
    sampleId: String,
    allItems: Array,
    lockMinutes: Number,
});

// ---- 鎖定心跳 ----
let heartbeatTimer = null;
onMounted(() => {
    heartbeatTimer = setInterval(
        () => {
            window.axios
                .post(route('checks.heartbeat', [props.question.id, props.sampleId]))
                .catch(() => {});
        },
        Math.max((props.lockMinutes - 2) * 60 * 1000, 60 * 1000),
    );
});
onBeforeUnmount(() => clearInterval(heartbeatTimer));

// ---- 數值修訂（暫存於前端，完成修訂時一併送出） ----
const editingVar = ref(null);
const editValue = ref('');
const pendingFixes = ref({}); // var_id -> value

const startEdit = (v) => {
    editingVar.value = v.var_id;
    editValue.value = pendingFixes.value[v.var_id] ?? v.fix_value ?? v.origin_value ?? '';
};

const saveEdit = (v) => {
    pendingFixes.value = { ...pendingFixes.value, [v.var_id]: editValue.value };
    editingVar.value = null;
};

const cancelEdit = () => (editingVar.value = null);

const displayValue = (v) => {
    if (v.var_id in pendingFixes.value) {
        const val = pendingFixes.value[v.var_id];
        const label = v.all_labels?.[val];
        return { text: label ? `${val} ${label}` : val, cls: 'font-bold text-red-600' };
    }
    if (v.fix_value !== null) {
        const label = v.fix_label;
        return {
            text: label ? `${v.fix_value} ${label}` : v.fix_value,
            cls: 'font-bold text-blue-600',
            origin: v.origin_label ? `${v.origin_value} ${v.origin_label}` : v.origin_value,
        };
    }
    const label = v.origin_label;
    return { text: label ? `${v.origin_value} ${label}` : v.origin_value, cls: '' };
};

// 編輯數值時對照用的標籤清單（依數值大小排序，無對應標籤者排在後）
const labelEntries = (v) =>
    Object.entries(v.all_labels ?? {}).sort((a, b) => {
        const na = Number(a[0]);
        const nb = Number(b[0]);
        if (Number.isNaN(na) || Number.isNaN(nb)) return a[0].localeCompare(b[0]);
        return na - nb;
    });

// ---- 影音彈出視窗與連續播放 ----
const mediaItem = ref(null);
const continuous = ref(false);
const audioRef = ref(null);

const openMedia = (item) => {
    if (item.has_media) mediaItem.value = item;
};

const mediaUrl = (id) => route('media.stream', [props.question.id, id]);

const onAudioEnded = () => {
    if (!continuous.value) return;
    const list = props.allItems;
    const idx = list.findIndex((i) => i.item_id === mediaItem.value.item_id);
    const next = list.slice(idx + 1).find((i) => i.has_media);
    if (next) mediaItem.value = next;
};

// ---- 完成修訂 ----
const form = useForm({ fixes: [] });

const submit = () => {
    form.fixes = Object.entries(pendingFixes.value).map(([var_id, value]) => ({
        var_id: Number(var_id),
        value,
    }));
    form.post(route('checks.edit-complete', [props.question.id, props.sampleId]), {
        onSuccess: () => {
            pendingFixes.value = {};
        },
    });
};

// 返回（不修訂）：釋放鎖定後回到樣本編號輸入頁
const goBack = () => {
    router.post(
        route('checks.unlock', [props.question.id, props.sampleId]),
        {},
        { onFinish: () => router.get(route('checks.edit-entry', props.question.id)) },
    );
};
</script>

<template>
    <Head :title="`資料修改 ${sampleId}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ question.code }}｜資料修改｜樣本 {{ sampleId }}
                </h2>
                <button @click="goBack" class="shrink-0 text-sm text-gray-600 hover:underline">
                    ← 返回（不修訂）
                </button>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-5xl px-4">
                <div class="rounded-lg bg-white p-4 shadow">
                    <h3 class="mb-2 border-b pb-2 font-medium text-gray-700">全部題目</h3>
                    <div class="max-h-[68vh] overflow-y-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="sticky top-0 bg-white text-gray-500">
                                <tr><th class="py-1 w-24">題號</th><th class="w-72">題目</th><th>變數</th><th>數值</th></tr>
                            </thead>
                            <tbody>
                                <template v-for="item in allItems" :key="item.item_id">
                                    <tr v-for="(v, vi) in item.vars" :key="v.var_id" class="border-b align-top last:border-0">
                                        <td v-if="vi === 0" :rowspan="item.vars.length" class="py-1.5 font-mono">
                                            <button
                                                v-if="item.has_media"
                                                @click="openMedia(item)"
                                                class="text-indigo-600 underline"
                                                title="點選播放系統截圖與錄音"
                                            >
                                                {{ item.item_name }} 🎧
                                            </button>
                                            <template v-else>{{ item.item_name }}</template>
                                        </td>
                                        <td v-if="vi === 0" :rowspan="item.vars.length" class="py-1.5 text-gray-700">
                                            {{ item.item_label }}
                                        </td>
                                        <td class="py-1.5">
                                            <button
                                                @click="startEdit(v)"
                                                class="text-left text-indigo-600 hover:underline"
                                                :title="`點選修改 ${v.variable} 的數值`"
                                            >
                                                {{ v.label }}
                                            </button>
                                        </td>
                                        <td class="py-1.5">
                                            <template v-if="editingVar === v.var_id">
                                                <div>
                                                    <div class="flex gap-1">
                                                        <input
                                                            v-model="editValue"
                                                            class="w-28 rounded border-gray-300 px-1 py-0.5 text-sm"
                                                            @keyup.enter="saveEdit(v)"
                                                        />
                                                        <button @click="saveEdit(v)" class="rounded bg-indigo-600 px-2 text-xs text-white">儲存</button>
                                                        <button @click="cancelEdit" class="text-xs text-gray-500">取消</button>
                                                    </div>
                                                    <ul
                                                        v-if="labelEntries(v).length"
                                                        class="mt-1 inline-block rounded border border-indigo-100 bg-indigo-50 px-2 py-1 text-xs leading-5 text-gray-600"
                                                    >
                                                        <li v-for="[val, lab] in labelEntries(v)" :key="val" class="font-mono">
                                                            {{ val }} {{ lab }}
                                                        </li>
                                                    </ul>
                                                </div>
                                            </template>
                                            <template v-else>
                                                <span :class="displayValue(v).cls">{{ displayValue(v).text }}</span>
                                                <span v-if="displayValue(v).origin !== undefined" class="ml-1 text-gray-700">
                                                    （{{ displayValue(v).origin }}）
                                                </span>
                                            </template>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 flex items-center gap-3 border-t pt-4">
                        <button
                            @click="submit"
                            :disabled="form.processing"
                            class="rounded-md bg-indigo-600 px-6 py-2 text-white hover:bg-indigo-500 disabled:opacity-40"
                        >
                            完成修訂
                        </button>
                        <span v-if="Object.keys(pendingFixes).length" class="text-sm text-red-600">
                            有 {{ Object.keys(pendingFixes).length }} 筆數值修訂待儲存。
                        </span>
                        <InputError :message="form.errors.fixes" />
                        <InputError :message="form.errors.lock" />
                    </div>
                </div>
            </div>
        </div>

        <!-- 影音播放彈出視窗 -->
        <Modal :show="mediaItem !== null" @close="mediaItem = null" max-width="2xl">
            <div v-if="mediaItem" class="p-6">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-medium">{{ mediaItem.item_name }}　{{ mediaItem.item_label }}</h3>
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" v-model="continuous" class="rounded" />
                        連續播放
                    </label>
                </div>

                <img
                    v-if="mediaItem.media.image"
                    :src="mediaUrl(mediaItem.media.image)"
                    class="mb-3 max-h-[60vh] w-full rounded border object-contain"
                />

                <audio
                    v-if="mediaItem.media.audio"
                    ref="audioRef"
                    :src="mediaUrl(mediaItem.media.audio)"
                    controls
                    autoplay
                    class="w-full"
                    @ended="onAudioEnded"
                />

                <div class="mt-4 text-right">
                    <button @click="mediaItem = null" class="text-sm text-gray-600 hover:underline">關閉</button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
