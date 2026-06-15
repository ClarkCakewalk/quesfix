<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    question: Object,
    sampleId: String,
    checkItem: Object,
    relatedItems: Array,
    allItems: Array,
    result: Object,
    allConditions: Array,
    lockMinutes: Number,
    // 來源：'sample'（依樣本→檢核條件）或 'logic'（依邏輯→樣本列表）
    from: { type: String, default: 'sample' },
});

// 進入本頁的來源，供返回連結與條件切換時延續
const reviewQuery = computed(() => `?from=${props.from}`);

// 「回到前一動」：依來源回到該樣本的檢核條件列表，或該條件的樣本列表
const backRoute = computed(() =>
    props.from === 'logic'
        ? route('checks.logic-samples', [props.question.id, props.checkItem.id])
        : route('checks.sample-conditions', [props.question.id, props.sampleId]),
);

const backLabel = computed(() =>
    props.from === 'logic' ? '← 回該條件的樣本列表' : '← 回該樣本的檢核條件列表',
);

// 「回到檢核列表」（完成後選單）：依來源回到依樣本／依邏輯頂層列表
const topListRoute = computed(() =>
    props.from === 'logic'
        ? route('checks.by-logic', props.question.id)
        : route('checks.by-sample', props.question.id),
);

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

// ---- 數值修訂（暫存於前端，完成檢核時一併送出） ----
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

// ---- 檢核結果表單 ----
const form = useForm({
    error: props.result.error !== null && props.result.error !== 3 ? props.result.error : null,
    re_survey: props.result.re_survey ? 1 : 0,
    note: props.result.note ?? '',
    error_note: props.result.error_note ?? '',
    re_survey_note: props.result.re_survey_note ?? '',
    fixes: [],
});

const showConfirm = ref(false);

const submit = () => {
    form.fixes = Object.entries(pendingFixes.value).map(([var_id, value]) => ({
        var_id: Number(var_id),
        value,
    }));
    form.transform((data) => ({ ...data, re_survey: Boolean(data.re_survey) }))
        .post(route('checks.complete', [props.question.id, props.sampleId, props.checkItem.id]), {
            preserveScroll: true,
            onSuccess: () => {
                pendingFixes.value = {};
                showConfirm.value = true;
            },
        });
};

// ---- 影音彈出視窗與連續播放 ----
const mediaItem = ref(null); // 目前顯示影音的題目
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

// 返回前一動：釋放本樣本鎖定後，回到來源的上一層列表
const goBack = () => {
    router.post(
        route('checks.unlock', [props.question.id, props.sampleId]),
        {},
        { onFinish: () => router.get(backRoute.value) },
    );
};

const backToList = () => {
    router.post(
        route('checks.unlock', [props.question.id, props.sampleId]),
        {},
        { onFinish: () => router.get(topListRoute.value) },
    );
};

const continueSample = () => {
    router.get(route('checks.sample-conditions', [props.question.id, props.sampleId]));
};
</script>

<template>
    <Head :title="`檢核 ${sampleId} / ${checkItem.item_name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ question.code }}｜樣本 {{ sampleId }}｜條件 {{ checkItem.item_name }}
                </h2>
                <button @click="goBack" class="shrink-0 text-sm text-gray-600 hover:underline">
                    {{ backLabel }}
                </button>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto grid max-w-[110rem] grid-cols-2 gap-4 px-4">
                <!-- 左側：問卷資料 -->
                <div class="space-y-4">
                    <div class="rounded-lg bg-white p-4 shadow">
                        <h3 class="mb-2 border-b pb-2 font-medium text-indigo-700">關聯題目</h3>
                        <div class="max-h-[38vh] overflow-y-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="sticky top-0 bg-white text-gray-500">
                                    <tr><th class="py-1 w-24">題號</th><th class="w-64">題目</th><th>變數</th><th>數值</th></tr>
                                </thead>
                                <tbody>
                                    <template v-for="item in relatedItems" :key="item.item_id">
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
                    </div>

                    <div class="rounded-lg bg-white p-4 shadow">
                        <h3 class="mb-2 border-b pb-2 font-medium text-gray-700">全部題目</h3>
                        <div class="max-h-[38vh] overflow-y-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="sticky top-0 bg-white text-gray-500">
                                    <tr><th class="py-1 w-24">題號</th><th class="w-64">題目</th><th>變數</th><th>數值</th></tr>
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
                                                <button @click="startEdit(v)" class="text-left text-indigo-600 hover:underline">
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
                    </div>
                </div>

                <!-- 右側：檢核資訊 -->
                <div class="flex gap-4">
                    <div class="flex-1 space-y-4">
                        <div class="rounded-lg bg-white p-4 shadow">
                            <div class="flex items-baseline gap-2">
                                <span class="font-mono font-semibold text-indigo-700">{{ checkItem.item_name }}</span>
                                <span class="text-sm text-gray-700">{{ checkItem.description }}</span>
                            </div>
                        </div>

                        <div class="rounded-lg bg-white p-4 shadow">
                            <h3 class="mb-3 font-medium">檢核結果註記</h3>

                            <div class="space-y-4 text-sm">
                                <div>
                                    <div class="mb-1 text-gray-600">檢核結果</div>
                                    <div class="flex gap-4">
                                        <label class="flex items-center gap-1">
                                            <input type="radio" :value="0" v-model="form.error" /> 接受
                                        </label>
                                        <label class="flex items-center gap-1">
                                            <input type="radio" :value="1" v-model="form.error" /> 錯誤且算錯
                                        </label>
                                        <label class="flex items-center gap-1">
                                            <input type="radio" :value="2" v-model="form.error" /> 錯誤不算錯
                                        </label>
                                    </div>
                                    <InputError :message="form.errors.error" />
                                </div>

                                <div>
                                    <div class="mb-1 text-gray-600">是否補問</div>
                                    <div class="flex gap-4">
                                        <label class="flex items-center gap-1">
                                            <input type="radio" :value="0" v-model="form.re_survey" /> 不補問
                                        </label>
                                        <label class="flex items-center gap-1">
                                            <input type="radio" :value="1" v-model="form.re_survey" /> 補問
                                        </label>
                                    </div>
                                    <InputError :message="form.errors.re_survey" />
                                </div>

                                <div>
                                    <div class="mb-1 text-gray-600">內部註記（「錯誤不算錯」必填）</div>
                                    <textarea v-model="form.note" rows="2" class="w-full rounded-md border-gray-300 text-sm" />
                                    <InputError :message="form.errors.note" />
                                </div>

                                <div>
                                    <div class="mb-1 text-gray-600">訪員說明（「錯誤且算錯」必填）</div>
                                    <textarea v-model="form.error_note" rows="2" class="w-full rounded-md border-gray-300 text-sm" />
                                    <InputError :message="form.errors.error_note" />
                                </div>

                                <div>
                                    <div class="mb-1 text-gray-600">補問說明（選「補問」必填）</div>
                                    <textarea v-model="form.re_survey_note" rows="2" class="w-full rounded-md border-gray-300 text-sm" />
                                    <InputError :message="form.errors.re_survey_note" />
                                </div>

                                <div v-if="Object.keys(pendingFixes).length" class="rounded-md bg-red-50 p-2 text-xs text-red-700">
                                    有 {{ Object.keys(pendingFixes).length }} 筆數值修訂待儲存，將於完成檢核時寫入。
                                </div>
                                <InputError :message="form.errors.fixes" />
                                <InputError :message="form.errors.lock" />

                                <button
                                    @click="submit"
                                    :disabled="form.processing || form.error === null"
                                    class="w-full rounded-md bg-indigo-600 py-2 text-white disabled:opacity-40"
                                >
                                    完成檢核
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- 右緣：該樣本所有條件 -->
                    <div class="w-44 shrink-0 rounded-lg bg-white p-3 shadow">
                        <h4 class="mb-2 text-xs font-medium text-gray-500">本樣本全部條件</h4>
                        <ul class="space-y-1 text-xs">
                            <li v-for="c in allConditions" :key="c.check_item_id">
                                <Link
                                    :href="route('checks.review', [question.id, sampleId, c.check_item_id]) + reviewQuery"
                                    :title="c.description"
                                    class="block truncate rounded px-2 py-1 font-mono"
                                    :class="[
                                        c.check_item_id === checkItem.id
                                            ? 'bg-indigo-600 text-white'
                                            : c.pending
                                              ? 'text-amber-700 hover:bg-amber-50'
                                              : 'text-gray-400 hover:bg-gray-50',
                                    ]"
                                >
                                    {{ c.item_name }}
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- 影音播放彈出視窗 -->
        <Modal :show="mediaItem !== null" @close="mediaItem = null" max-width="2xl">
            <div v-if="mediaItem" class="p-6">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-medium">
                        {{ mediaItem.item_name }}　{{ mediaItem.item_label }}
                    </h3>
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

        <!-- 完成檢核後的確認選單 -->
        <Modal :show="showConfirm" @close="showConfirm = false" max-width="md">
            <div class="p-6 text-center">
                <h3 class="mb-2 text-lg font-medium">檢核結果已儲存</h3>
                <p class="mb-6 text-sm text-gray-600">{{ $page.props.flash?.status }}</p>
                <div class="flex justify-center gap-4">
                    <button @click="backToList" class="rounded-md bg-gray-700 px-4 py-2 text-sm text-white">
                        回到檢核列表
                    </button>
                    <button @click="continueSample" class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white">
                        繼續該樣本檢核
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
