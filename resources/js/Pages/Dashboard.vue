<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    files: Array,
    storage: Object,
    categories: Object,
});

const CATEGORY_ICON_PATHS = {
    Documents: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    Pictures: 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
    Video: 'M7 4v16M17 4v16M3 8h4m10 0h4M3 16h4m10 0h4M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z',
    Audio: 'M9 19V6l12-2v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-2c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z',
    'Apps & Archives': 'M5 8h14M5 8a2 2 0 01-2-2V4a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 01-2 2M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8M10 12h4',
    Other: 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z',
};

const CATEGORY_ORDER = ['Documents', 'Pictures', 'Video', 'Audio', 'Apps & Archives', 'Other'];

function humanSize(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let n = bytes;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }
    return `${n < 10 ? n.toFixed(1) : Math.round(n)} ${units[i]}`;
}

function formatDate(iso) {
    return new Date(iso).toLocaleDateString(undefined, {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

const search = ref('');
const activeCategory = ref(null);

const filteredFiles = computed(() => {
    return props.files.filter((file) => {
        const matchesSearch = file.name
            .toLowerCase()
            .includes(search.value.toLowerCase());
        const matchesCategory =
            !activeCategory.value || file.category === activeCategory.value;
        return matchesSearch && matchesCategory;
    });
});

const usedPercent = computed(() => {
    if (!props.storage.total) return 0;
    return Math.min(100, Math.round((props.storage.used / props.storage.total) * 100));
});

const gaugeStyle = computed(() => ({
    background: `conic-gradient(#7c3aed ${usedPercent.value * 3.6}deg, #e5e7eb ${usedPercent.value * 3.6}deg)`,
}));

const fileInput = ref(null);
const isDragging = ref(false);
const uploadForm = useForm({ file: null });

function triggerFilePicker() {
    fileInput.value?.click();
}

function onFileChosen(event) {
    const file = event.target.files[0];
    if (file) uploadFile(file);
    event.target.value = '';
}

function onDrop(event) {
    isDragging.value = false;
    const file = event.dataTransfer.files[0];
    if (file) uploadFile(file);
}

function uploadFile(file) {
    uploadForm.file = file;
    uploadForm.post(route('files.store'), {
        preserveScroll: true,
        onSuccess: () => uploadForm.reset(),
    });
}

function copyLink(file) {
    navigator.clipboard.writeText(file.download_url);
}

function deleteFile(file) {
    if (!confirm(`Delete "${file.name}"? This can't be undone.`)) return;
    useForm({}).delete(route('files.destroy', file.id), { preserveScroll: true });
}

const renamingFileId = ref(null);
const renameValue = ref('');
const renameInput = ref(null);

function startRename(file) {
    renamingFileId.value = file.id;
    renameValue.value = file.name;
    nextTick(() => renameInput.value?.focus());
}

function cancelRename() {
    renamingFileId.value = null;
}

function submitRename(file) {
    const name = renameValue.value.trim();
    if (!name || name === file.name) {
        cancelRename();
        return;
    }
    useForm({ name }).patch(route('files.update', file.id), {
        preserveScroll: true,
        onSuccess: cancelRename,
    });
}
</script>

<template>
    <Head title="My Drive" />

    <AuthenticatedLayout>
        <div
            class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="onDrop"
        >
            <!-- Top bar -->
            <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                        My Drive
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Dashboard <span class="mx-1">›</span> Files
                    </p>
                </div>

                <div class="flex flex-1 items-center gap-3 sm:max-w-md sm:justify-end">
                    <div class="relative flex-1 sm:max-w-xs">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Type to search..."
                            class="w-full rounded-lg border-gray-300 pl-9 text-sm focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                        />
                    </div>

                    <button
                        @click="triggerFilePicker"
                        :disabled="uploadForm.processing"
                        class="inline-flex items-center gap-2 rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700 disabled:opacity-50"
                    >
                        <span v-if="!uploadForm.processing">+ Upload</span>
                        <span v-else>Uploading… {{ uploadForm.progress?.percentage ?? 0 }}%</span>
                    </button>
                    <input ref="fileInput" type="file" class="hidden" @change="onFileChosen" />
                </div>
            </div>

            <div
                v-if="isDragging"
                class="mb-6 flex items-center justify-center rounded-xl border-2 border-dashed border-violet-400 bg-violet-50 py-10 text-violet-600 dark:bg-violet-950/30"
            >
                Drop the file to upload it
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Main column -->
                <div class="space-y-8 lg:col-span-2">
                    <!-- Category cards -->
                    <div>
                        <div class="mb-3 flex items-center justify-between">
                            <h2 class="font-semibold text-gray-900 dark:text-gray-100">Categories</h2>
                            <button
                                v-if="activeCategory"
                                @click="activeCategory = null"
                                class="text-xs font-medium text-violet-600 hover:underline"
                            >
                                Clear filter
                            </button>
                        </div>
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                            <button
                                v-for="name in CATEGORY_ORDER"
                                :key="name"
                                @click="activeCategory = activeCategory === name ? null : name"
                                class="rounded-xl border p-4 text-left transition hover:shadow-md"
                                :class="activeCategory === name
                                    ? 'border-violet-500 bg-violet-50 dark:bg-violet-950/30'
                                    : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'"
                            >
                                <svg class="h-6 w-6 text-violet-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="CATEGORY_ICON_PATHS[name]" />
                                </svg>
                                <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ name }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ categories[name]?.count ?? 0 }} files
                                </p>
                            </button>
                        </div>
                    </div>

                    <!-- Files table -->
                    <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                            <h2 class="font-semibold text-gray-900 dark:text-gray-100">
                                {{ activeCategory ?? 'Recent Files' }}
                            </h2>
                            <span class="text-xs text-gray-400">{{ filteredFiles.length }} files</span>
                        </div>

                        <p v-if="!filteredFiles.length" class="px-5 py-10 text-center text-sm text-gray-400">
                            No files yet — drag one in or hit Upload.
                        </p>

                        <ul v-else class="divide-y divide-gray-100 dark:divide-gray-700">
                            <li
                                v-for="file in filteredFiles"
                                :key="file.id"
                                class="flex items-center gap-4 px-5 py-3"
                            >
                                <svg class="h-5 w-5 shrink-0 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="CATEGORY_ICON_PATHS[file.category]" />
                                </svg>
                                <div class="min-w-0 flex-1">
                                    <input
                                        v-if="renamingFileId === file.id"
                                        ref="renameInput"
                                        v-model="renameValue"
                                        type="text"
                                        class="w-full rounded-md border-gray-300 py-1 text-sm focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                        @keyup.enter="submitRename(file)"
                                        @keyup.esc="cancelRename"
                                        @blur="submitRename(file)"
                                    />
                                    <p v-else class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ file.name }}
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ formatDate(file.created_at) }} · {{ file.human_size }} · {{ file.download_count }} downloads
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <button
                                        @click="startRename(file)"
                                        title="Rename"
                                        class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-violet-600 dark:hover:bg-gray-700"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button
                                        @click="copyLink(file)"
                                        title="Copy download link"
                                        class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-violet-600 dark:hover:bg-gray-700"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5M10.172 13.828a4 4 0 010-5.656l3-3a4 4 0 015.656 5.656l-1.5 1.5" />
                                        </svg>
                                    </button>
                                    <a
                                        :href="file.download_url"
                                        title="Download"
                                        class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-violet-600 dark:hover:bg-gray-700"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                                        </svg>
                                    </a>
                                    <button
                                        @click="deleteFile(file)"
                                        title="Delete"
                                        class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-700"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 text-center dark:border-gray-700 dark:bg-gray-800">
                        <h2 class="mb-4 font-semibold text-gray-900 dark:text-gray-100">Storage</h2>
                        <div class="relative mx-auto h-36 w-36">
                            <div class="h-full w-full rounded-full" :style="gaugeStyle"></div>
                            <div class="absolute inset-3 flex flex-col items-center justify-center rounded-full bg-white dark:bg-gray-800">
                                <span class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ usedPercent }}%</span>
                                <span class="text-xs text-gray-400">used</span>
                            </div>
                        </div>
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                            {{ humanSize(storage.used) }} of {{ humanSize(storage.total) }}
                        </p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-2 dark:border-gray-700 dark:bg-gray-800">
                        <div
                            v-for="name in CATEGORY_ORDER"
                            :key="name"
                            class="flex items-center justify-between rounded-lg px-4 py-3 text-sm"
                        >
                            <span class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="CATEGORY_ICON_PATHS[name]" />
                                </svg>
                                {{ name }}
                            </span>
                            <span class="text-gray-400">{{ humanSize(categories[name]?.size ?? 0) }}</span>
                        </div>
                    </div>

                    <div class="rounded-xl border-2 border-dashed border-violet-300 p-6 text-center dark:border-violet-800">
                        <svg class="mx-auto h-8 w-8 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 8l5-5 5 5M12 3v12" />
                        </svg>
                        <p class="mt-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                            Drop your files
                        </p>
                        <p class="text-xs text-gray-400">Supports all file formats</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
