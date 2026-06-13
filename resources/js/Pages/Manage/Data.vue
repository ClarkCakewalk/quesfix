<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import InputError from '@/Components/InputError.vue';
import ManageNav from '@/Components/ManageNav.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    question: Object,
    stats: Object,
});

const weeks = ref('');

const formatForm = useForm({ file: null });
const checksForm = useForm({ file: null });
const dataForm = useForm({ file: null });
const mediaForm = useForm({ file: null });

const upload = (form, routeName) => {
    form.post(route(routeName, props.question.id), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const runChecks = () => {
    if (confirm('將對全部樣本執行所有檢核條件，產生待處理清單。確定執行？')) {
        router.post(route('checks.run', props.question.id), {}, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="資料匯入與檢核" />

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

                <div class="mb-6 grid grid-cols-5 gap-3 text-center">
                    <div class="rounded-lg bg-white p-4 shadow">
                        <div class="text-2xl font-semibold">{{ stats.vars }}</div>
                        <div class="text-xs text-gray-500">變數</div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow">
                        <div class="text-2xl font-semibold">{{ stats.samples }}</div>
                        <div class="text-xs text-gray-500">樣本</div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow">
                        <div class="text-2xl font-semibold">{{ stats.check_items }}</div>
                        <div class="text-xs text-gray-500">檢核條件</div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow">
                        <div class="text-2xl font-semibold text-amber-600">{{ stats.pending_results }}</div>
                        <div class="text-xs text-gray-500">待處理結果</div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow">
                        <div class="text-2xl font-semibold">{{ stats.total_results }}</div>
                        <div class="text-xs text-gray-500">檢核結果總數</div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-white p-6 shadow sm:rounded-lg">
                        <h3 class="mb-1 text-lg font-medium">1. 資料格式匯入（Excel）</h3>
                        <p class="mb-3 text-sm text-gray-500">
                            檔案需包含「題項」與「數值標籤」兩個工作表。題項欄位：題目名稱、題目標籤、變數名稱、變數標籤、關聯數值標籤、型別（選用）；數值標籤欄位：選項代號、數值、數值說明。
                        </p>
                        <div class="flex items-center gap-2">
                            <input type="file" accept=".xlsx,.xls" @change="formatForm.file = $event.target.files[0]" class="text-sm" />
                            <button
                                @click="upload(formatForm, 'imports.format')"
                                :disabled="!formatForm.file || formatForm.processing"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white disabled:opacity-40"
                            >
                                匯入格式
                            </button>
                        </div>
                        <InputError class="mt-1" :message="formatForm.errors.file" />
                    </div>

                    <div class="bg-white p-6 shadow sm:rounded-lg">
                        <h3 class="mb-1 text-lg font-medium">2. 檢核條件匯入（Excel）</h3>
                        <p class="mb-3 text-sm text-gray-500">
                            欄位：條件代號、條件敘述、關聯題目（逗號分隔）、檢核邏輯（Stata 語法）。匯入時驗證語法、變數與型別。
                        </p>
                        <div class="flex items-center gap-2">
                            <input type="file" accept=".xlsx,.xls" @change="checksForm.file = $event.target.files[0]" class="text-sm" />
                            <button
                                @click="upload(checksForm, 'imports.checks')"
                                :disabled="!checksForm.file || checksForm.processing"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white disabled:opacity-40"
                            >
                                匯入檢核條件
                            </button>
                        </div>
                        <InputError class="mt-1" :message="checksForm.errors.file" />
                    </div>

                    <div class="bg-white p-6 shadow sm:rounded-lg">
                        <h3 class="mb-1 text-lg font-medium">3. 調查資料匯入（CSV）</h3>
                        <p class="mb-3 text-sm text-gray-500">
                            UTF-8 編碼、含表頭。表頭必須包含 id；所有變數需已存在於資料格式；已存在的 id 將略過。
                        </p>
                        <div class="flex items-center gap-2">
                            <input type="file" accept=".csv,.txt" @change="dataForm.file = $event.target.files[0]" class="text-sm" />
                            <button
                                @click="upload(dataForm, 'imports.data')"
                                :disabled="!dataForm.file || dataForm.processing"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white disabled:opacity-40"
                            >
                                匯入資料
                            </button>
                        </div>
                        <InputError class="mt-1" :message="dataForm.errors.file" />
                    </div>

                    <div class="bg-white p-6 shadow sm:rounded-lg">
                        <h3 class="mb-1 text-lg font-medium">3-1. 影音檔匯入（zip）</h3>
                        <p class="mb-3 text-sm text-gray-500">
                            zip 內以樣本 id 分資料夾（如 S001/A01.png、S001/A01.mp3），檔名對應題目名稱。重複檔案以新檔覆蓋。
                        </p>
                        <div class="flex items-center gap-2">
                            <input type="file" accept=".zip" @change="mediaForm.file = $event.target.files[0]" class="text-sm" />
                            <button
                                @click="upload(mediaForm, 'imports.media')"
                                :disabled="!mediaForm.file || mediaForm.processing"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white disabled:opacity-40"
                            >
                                匯入影音檔
                            </button>
                        </div>
                        <InputError class="mt-1" :message="mediaForm.errors.file" />
                    </div>

                    <div class="bg-white p-6 shadow sm:rounded-lg">
                        <h3 class="mb-1 text-lg font-medium">4. 執行檢核</h3>
                        <p class="mb-3 text-sm text-gray-500">
                            以 Stata 邏輯引擎對全部樣本執行所有檢核條件，產生待處理的檢核結果清單。可重複執行（已存在的結果不會重複建立）。
                        </p>
                        <div class="flex gap-3">
                            <button @click="runChecks" class="rounded-md bg-emerald-600 px-4 py-2 text-sm text-white">
                                執行檢核
                            </button>
                            <a
                                :href="route('checks.locks', question.id)"
                                class="rounded-md bg-gray-200 px-4 py-2 text-sm text-gray-700"
                            >
                                樣本鎖定列表
                            </a>
                        </div>
                    </div>

                    <div class="bg-white p-6 shadow sm:rounded-lg">
                        <h3 class="mb-1 text-lg font-medium">5. 匯出</h3>
                        <div class="mb-3 flex items-center gap-2 text-sm">
                            <label class="text-gray-600">週數（逗號分隔，僅訪員錯誤報表）</label>
                            <input v-model="weeks" type="text" placeholder="例：1,2,3（空白＝全部）" class="w-56 rounded-md border-gray-300 text-sm" />
                        </div>
                        <div class="flex flex-wrap gap-3 text-sm">
                            <a
                                :href="route('exports.interviewer-errors', question.id) + (weeks ? `?weeks=${weeks}` : '')"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-white"
                            >
                                訪員錯誤報表（xlsx）
                            </a>
                            <a :href="route('exports.check-results', question.id)" class="rounded-md bg-indigo-600 px-4 py-2 text-white">
                                檢核結果報表（xlsx）
                            </a>
                            <a :href="route('exports.fix-do', question.id)" class="rounded-md bg-gray-700 px-4 py-2 text-white">
                                資料修正程式（.do）
                            </a>
                            <a :href="route('exports.fixed-data', question.id)" class="rounded-md bg-gray-700 px-4 py-2 text-white">
                                修正後資料檔（CSV）
                            </a>
                            <a :href="route('exports.read-do', question.id)" class="rounded-md bg-gray-700 px-4 py-2 text-white">
                                讀檔程式（.do）
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
