<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    question: Object,
    locked: Array,
});

const forceUnlock = (s) => {
    if (confirm(`確定強制解除樣本 ${s.sample_id} 的鎖定？`)) {
        router.post(route('checks.force-unlock', [props.question.id, s.id]), {}, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="樣本鎖定列表" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ question.code }}｜樣本鎖定列表
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <FlashMessages />

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <p v-if="!locked.length" class="py-6 text-center text-sm text-gray-500">目前沒有被鎖定的樣本。</p>

                    <table v-else class="w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th class="py-2">樣本編號</th>
                                <th>鎖定者</th>
                                <th>鎖定時間</th>
                                <th>逾期時間</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in locked" :key="s.id" class="border-b last:border-0">
                                <td class="py-2 font-mono">{{ s.sample_id }}</td>
                                <td>{{ s.locked_by }}</td>
                                <td>{{ s.locked_at }}</td>
                                <td>
                                    {{ s.expires_at }}
                                    <span v-if="s.expired" class="ml-1 rounded bg-gray-100 px-2 py-0.5 text-xs">已逾期</span>
                                </td>
                                <td class="text-right">
                                    <button @click="forceUnlock(s)" class="text-red-600 hover:underline">解除鎖定</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
