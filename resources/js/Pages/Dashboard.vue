<script setup>
import DriveLayout from '@/Layouts/DriveLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    files: Array,
    storage: Object,
    categories: Object,
});

const OFFICE_VIEWER_BASE_URL = 'https://view.officeapps.live.com/op/embed.aspx?src=';
const TEXT_PREVIEW_LIMIT_BYTES = 2 * 1024 * 1024;

// Tone classes are written out in full rather than composed at runtime —
// Tailwind scans this file as text, so an interpolated class name would never
// make it into the stylesheet. Each carries a light pair and a dark pair,
// because the pale shade that reads well on a dark panel drops to roughly 2:1
// against white.
const CATEGORIES = [
    {
        name: 'Documents',
        tone: 'text-filetype-document-light bg-filetype-document-light/10 dark:text-filetype-document dark:bg-filetype-document/10',
        icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    },
    {
        name: 'Pictures',
        tone: 'text-filetype-picture-light bg-filetype-picture-light/10 dark:text-filetype-picture dark:bg-filetype-picture/10',
        icon: 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
    },
    {
        name: 'Video',
        tone: 'text-filetype-video-light bg-filetype-video-light/10 dark:text-filetype-video dark:bg-filetype-video/10',
        icon: 'M7 4v16M17 4v16M3 8h4m10 0h4M3 16h4m10 0h4M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z',
    },
    {
        name: 'Audio',
        tone: 'text-filetype-audio-light bg-filetype-audio-light/10 dark:text-filetype-audio dark:bg-filetype-audio/10',
        icon: 'M9 19V6l12-2v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-2c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z',
    },
    {
        name: 'Apps & Archives',
        tone: 'text-filetype-archive-light bg-filetype-archive-light/10 dark:text-filetype-archive dark:bg-filetype-archive/10',
        icon: 'M5 8h14M5 8a2 2 0 01-2-2V4a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 01-2 2M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8M10 12h4',
    },
    {
        name: 'Other',
        tone: 'text-filetype-other-light bg-filetype-other-light/10 dark:text-filetype-other dark:bg-filetype-other/10',
        icon: 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z',
    },
];

const CATEGORY_BY_NAME = Object.fromEntries(CATEGORIES.map((c) => [c.name, c]));

function categoryOf(file) {
    return CATEGORY_BY_NAME[file.category] ?? CATEGORY_BY_NAME.Other;
}

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
    const d = new Date(iso);
    return (
        d.toLocaleDateString(undefined, {
            month: 'short',
            day: '2-digit',
            year: 'numeric',
        }) +
        ', ' +
        d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false })
    );
}

const search = ref('');
const activeCategory = ref(null);
const sortKey = ref('created_at');
const sortDir = ref('desc');

function toggleSort(key) {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDir.value = key === 'name' ? 'asc' : 'desc';
    }
}

const visibleFiles = computed(() => {
    const term = search.value.trim().toLowerCase();

    const filtered = props.files.filter((file) => {
        const matchesSearch = !term || file.name.toLowerCase().includes(term);
        const matchesCategory =
            !activeCategory.value || file.category === activeCategory.value;
        return matchesSearch && matchesCategory;
    });

    const dir = sortDir.value === 'asc' ? 1 : -1;

    return [...filtered].sort((a, b) => {
        if (sortKey.value === 'name') return a.name.localeCompare(b.name) * dir;
        if (sortKey.value === 'size') return (a.size - b.size) * dir;
        return (new Date(a.created_at) - new Date(b.created_at)) * dir;
    });
});

const usedPercent = computed(() => {
    if (!props.storage.total) return 0;
    return Math.min(100, Math.round((props.storage.used / props.storage.total) * 100));
});

function strStartsWith(value, prefix) {
    return value.slice(0, prefix.length) === prefix;
}

function previewKindOf(file) {
    const mime = file.mime_type ?? '';

    if (strStartsWith(mime, 'image/')) return 'image';
    if (mime === 'application/pdf') return 'pdf';
    if (strStartsWith(mime, 'video/')) return 'video';
    if (strStartsWith(mime, 'audio/')) return 'audio';
    if (isOfficePreviewable(file)) return 'office';
    if (strStartsWith(mime, 'text/') && file.size <= TEXT_PREVIEW_LIMIT_BYTES) return 'text';
    if (['application/json', 'application/xml'].includes(mime) && file.size <= TEXT_PREVIEW_LIMIT_BYTES) return 'text';

    return null;
}

function isOfficePreviewable(file) {
    return [
        'application/msword',
        'application/rtf',
        'text/rtf',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ].includes(file.mime_type ?? '');
}

function officeViewerUrl(file) {
    return `${OFFICE_VIEWER_BASE_URL}${encodeURIComponent(file.public_preview_url)}`;
}

/* ---------------------------------------------------------------- uploads */

const fileInput = ref(null);
const isDragging = ref(false);
const uploadForm = useForm({ file: null });

// A selection is queued and sent one file per request rather than as a single
// multi-file post. Inertia keeps only one visit in flight — a second post would
// cancel the first — and one request per file also keeps every upload inside
// the pool's post_max_size, which a batch of large files would blow past.
const uploadQueue = ref([]);
const uploadTotal = ref(0);
const uploadSettled = ref(0);
const failedUploads = ref([]);

const isUploading = computed(() => uploadForm.processing || uploadQueue.value.length > 0);

// Progress across the whole batch: the files already settled, plus however far
// the one on the wire has got.
const uploadPercent = computed(() => {
    if (!uploadTotal.value) return 0;
    const current = (uploadForm.progress?.percentage ?? 0) / 100;

    return Math.round(((uploadSettled.value + current) / uploadTotal.value) * 100);
});

const uploadLabel = computed(() => {
    if (uploadTotal.value <= 1) return `Uploading ${uploadPercent.value}%`;

    const position = Math.min(uploadSettled.value + 1, uploadTotal.value);

    return `Uploading ${position}/${uploadTotal.value} · ${uploadPercent.value}%`;
});

function triggerFilePicker() {
    fileInput.value?.click();
}

function onFileChosen(event) {
    enqueueUploads(event.target.files);
    event.target.value = '';
}

function onDrop(event) {
    isDragging.value = false;
    enqueueUploads(event.dataTransfer.files);
}

function enqueueUploads(fileList) {
    const files = Array.from(fileList ?? []);
    if (!files.length) return;

    // Files added while a run is going join that run, so the counter stays
    // honest; only a fresh run clears the previous one's failures.
    if (!isUploading.value) failedUploads.value = [];

    uploadQueue.value.push(...files);
    uploadTotal.value += files.length;

    if (!uploadForm.processing) uploadNext();
}

function uploadNext() {
    const file = uploadQueue.value.shift();

    if (!file) {
        uploadTotal.value = 0;
        uploadSettled.value = 0;

        return;
    }

    uploadForm.file = file;
    uploadForm.post(route('files.store'), {
        preserveScroll: true,
        // Without this the rest of the batch would be dropped on the floor with
        // nothing on screen to say why, since the form's errors are cleared by
        // the next file's request.
        onError: (errors) => failedUploads.value.push({
            name: file.name,
            message: errors.file ?? 'Upload failed.',
        }),
        onFinish: () => {
            uploadSettled.value += 1;
            uploadForm.reset();
            uploadNext();
        },
    });
}

/* ----------------------------------------------------------------- rename */

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

/* ------------------------------------------------------------------ share */

const sharing = ref(null);
const copied = ref(false);

function openShare(file) {
    sharing.value = file;
    copied.value = false;
}

async function copyShareLink() {
    try {
        await navigator.clipboard.writeText(sharing.value.download_url);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Clipboard access is denied outside a secure context; the input is
        // selectable so the link is still reachable by hand.
        copied.value = false;
    }
}

/* ---------------------------------------------------------------- preview */

const previewing = ref(null);
const previewText = ref('');
const previewLoading = ref(false);
const previewError = ref('');

async function openPreview(file) {
    const kind = previewKindOf(file);
    if (!kind) return;

    previewing.value = { ...file, preview_kind: kind };
    previewText.value = '';
    previewError.value = '';

    if (kind !== 'text') return;

    previewLoading.value = true;

    try {
        const response = await fetch(file.preview_url, {
            headers: { Accept: 'text/plain' },
        });

        if (!response.ok) throw new Error(`Preview failed with status ${response.status}`);

        previewText.value = await response.text();
    } catch {
        previewError.value = 'This file could not be previewed right now.';
    } finally {
        previewLoading.value = false;
    }
}

function closePreview() {
    previewing.value = null;
    previewText.value = '';
    previewLoading.value = false;
    previewError.value = '';
}

/* ----------------------------------------------------------------- delete */

function deleteFile(file) {
    if (!confirm(`Delete "${file.name}"? This can't be undone.`)) return;
    useForm({}).delete(route('files.destroy', file.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="My Files" />

    <DriveLayout>
        <!-- ------------------------------------------------------ sidebar -->
        <template #sidebar>
            <div class="px-3">
                <!-- Left clickable while a batch runs: more files can be added
                     to the queue mid-run. -->
                <button
                    class="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/20 transition hover:bg-brand-500"
                    @click="triggerFilePicker"
                >
                    <svg v-if="!isUploading" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>{{ isUploading ? uploadLabel : 'New' }}</span>
                </button>

                <div v-if="isUploading" class="mt-2 h-1 overflow-hidden rounded-full bg-gray-900/10 dark:bg-white/10">
                    <div
                        class="h-full rounded-full bg-brand-500 transition-all duration-200 dark:bg-brand-400"
                        :style="{ width: `${uploadPercent}%` }"
                    />
                </div>

                <ul v-if="failedUploads.length" class="mt-2 space-y-1">
                    <li
                        v-for="failure in failedUploads"
                        :key="failure.name"
                        class="text-xs text-red-600 dark:text-red-400"
                    >
                        <span class="block truncate font-medium">{{ failure.name }}</span>
                        <span class="block truncate">{{ failure.message }}</span>
                    </li>
                </ul>
            </div>

            <nav class="mt-6 flex-1 space-y-0.5 overflow-y-auto px-3">
                <button
                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm transition"
                    :class="activeCategory === null
                        ? 'bg-brand-500/10 font-medium text-brand-700 dark:bg-brand-600/15 dark:text-brand-300'
                        : 'text-gray-600 hover:bg-gray-900/5 dark:text-gray-300 dark:hover:bg-white/5'"
                    @click="activeCategory = null"
                >
                    <svg class="h-[1.125rem] w-[1.125rem] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                    </svg>
                    <span class="flex-1 text-left">My Files</span>
                    <span class="text-xs text-gray-500">{{ files.length }}</span>
                </button>

                <p class="px-3 pb-1 pt-5 text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                    Categories
                </p>

                <button
                    v-for="category in CATEGORIES"
                    :key="category.name"
                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm transition"
                    :class="activeCategory === category.name
                        ? 'bg-brand-500/10 font-medium text-brand-700 dark:bg-brand-600/15 dark:text-brand-300'
                        : 'text-gray-600 hover:bg-gray-900/5 dark:text-gray-300 dark:hover:bg-white/5'"
                    @click="activeCategory = activeCategory === category.name ? null : category.name"
                >
                    <svg class="h-[1.125rem] w-[1.125rem] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="category.icon" />
                    </svg>
                    <span class="flex-1 truncate text-left">{{ category.name }}</span>
                    <span class="text-xs text-gray-500">{{ categories[category.name]?.count ?? 0 }}</span>
                </button>
            </nav>

            <div class="border-t border-gray-200 p-4 dark:border-white/5">
                <div class="mb-2 flex items-baseline justify-between text-xs">
                    <span class="font-medium text-gray-700 dark:text-gray-300">Storage</span>
                    <span class="text-gray-500">{{ usedPercent }}%</span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-gray-900/10 dark:bg-white/10">
                    <div
                        class="h-full rounded-full bg-gradient-to-r from-brand-600 to-brand-400"
                        :style="{ width: `${Math.max(usedPercent, 1)}%` }"
                    />
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    {{ humanSize(storage.used) }} of {{ humanSize(storage.total) }} used
                </p>
            </div>
        </template>

        <!-- ------------------------------------------------------- topbar -->
        <template #topbar>
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search drive"
                    class="w-full rounded-xl border-0 bg-gray-900/5 py-2 pl-9 pr-3 text-sm text-gray-900 ring-1 ring-inset ring-gray-900/10 transition placeholder:text-gray-500 focus:bg-white focus:ring-2 focus:ring-inset focus:ring-brand-500 dark:bg-white/5 dark:text-gray-100 dark:ring-white/10 dark:focus:bg-white/10"
                />
            </div>
        </template>

        <!-- --------------------------------------------------------- main -->
        <div
            class="relative min-h-full px-4 py-6 sm:px-6 lg:px-8"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="onDrop"
        >
            <input ref="fileInput" type="file" multiple class="hidden" @change="onFileChosen" />

            <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <nav class="flex items-center gap-1.5 text-xs text-gray-500">
                        <span>My Files</span>
                        <template v-if="activeCategory">
                            <span>/</span>
                            <span class="text-gray-700 dark:text-gray-300">{{ activeCategory }}</span>
                        </template>
                    </nav>
                    <h1 class="mt-1 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-50">
                        {{ activeCategory ?? 'My Files' }}
                    </h1>
                </div>

                <p class="text-xs text-gray-500">
                    {{ visibleFiles.length }}
                    {{ visibleFiles.length === 1 ? 'item' : 'items' }}
                    <template v-if="search || activeCategory"> · filtered</template>
                </p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/5 dark:bg-gray-900/50 dark:shadow-none">
                <!-- Column headers double as the sort control. Hidden on small
                     screens, where the row collapses to two stacked lines. -->
                <div class="hidden border-b border-gray-200 px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:border-white/5 sm:flex">
                    <button class="flex flex-1 items-center gap-1 text-left transition hover:text-gray-900 dark:hover:text-gray-300" @click="toggleSort('name')">
                        Name
                        <span v-if="sortKey === 'name'" class="text-brand-600 dark:text-brand-400">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
                    </button>
                    <button class="flex w-28 items-center gap-1 transition hover:text-gray-900 dark:hover:text-gray-300" @click="toggleSort('size')">
                        Size
                        <span v-if="sortKey === 'size'" class="text-brand-600 dark:text-brand-400">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
                    </button>
                    <button class="flex w-48 items-center gap-1 transition hover:text-gray-900 dark:hover:text-gray-300" @click="toggleSort('created_at')">
                        Modified
                        <span v-if="sortKey === 'created_at'" class="text-brand-600 dark:text-brand-400">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
                    </button>
                    <span class="w-40 text-right">Actions</span>
                </div>

                <div v-if="!visibleFiles.length" class="px-6 py-20 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-900/5 dark:bg-white/5">
                        <svg class="h-6 w-6 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-8m0 0l-3 3m3-3l3 3M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2" />
                        </svg>
                    </div>
                    <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ files.length ? 'Nothing matches that filter' : 'No files yet' }}
                    </p>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ files.length ? 'Try a different search or category.' : 'Drop files anywhere, or use the New button.' }}
                    </p>
                </div>

                <ul v-else class="divide-y divide-gray-200 dark:divide-white/5">
                    <li
                        v-for="file in visibleFiles"
                        :key="file.id"
                        class="group flex flex-col gap-2 px-4 py-2.5 transition hover:bg-gray-900/[0.03] dark:hover:bg-white/[0.03] sm:flex-row sm:items-center sm:gap-0"
                    >
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <span
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                                :class="categoryOf(file).tone"
                            >
                                <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" :d="categoryOf(file).icon" />
                                </svg>
                            </span>

                            <div class="min-w-0 flex-1">
                                <input
                                    v-if="renamingFileId === file.id"
                                    :ref="(el) => { if (el) renameInput = el }"
                                    v-model="renameValue"
                                    type="text"
                                    class="w-full rounded-lg border-0 bg-gray-900/5 py-1 text-sm text-gray-900 ring-1 ring-inset ring-brand-500 focus:ring-2 focus:ring-inset focus:ring-brand-500 dark:bg-white/10 dark:text-gray-100 dark:focus:ring-brand-400"
                                    @keyup.enter="submitRename(file)"
                                    @keyup.esc="cancelRename"
                                    @blur="submitRename(file)"
                                />
                                <button
                                    v-else-if="previewKindOf(file)"
                                    class="truncate text-left text-sm text-gray-900 transition hover:text-brand-600 dark:text-gray-100 dark:hover:text-brand-300"
                                    @click="openPreview(file)"
                                >
                                    {{ file.name }}
                                </button>
                                <p v-else class="truncate text-sm text-gray-900 dark:text-gray-100">{{ file.name }}</p>
                                <p class="truncate text-xs text-gray-500 dark:text-gray-400 sm:hidden">
                                    {{ file.human_size }} · {{ formatDate(file.created_at) }}
                                </p>
                            </div>
                        </div>

                        <div class="hidden w-28 shrink-0 text-sm text-gray-500 dark:text-gray-400 sm:block">
                            {{ file.human_size }}
                        </div>
                        <div class="hidden w-48 shrink-0 text-sm text-gray-500 dark:text-gray-400 sm:block">
                            {{ formatDate(file.created_at) }}
                        </div>

                        <!-- Actions stay mounted so keyboard users can reach
                             them; they only fade in on hover for the mouse. -->
                        <div class="flex shrink-0 items-center justify-end gap-0.5 sm:w-40 sm:opacity-0 sm:transition sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
                            <button
                                v-if="previewKindOf(file)"
                                title="Preview"
                                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-900/10 hover:text-brand-600 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-brand-300"
                                @click="openPreview(file)"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5s8.268 2.943 9.542 7c-1.274 4.057-5.065 7-9.542 7S3.732 16.057 2.458 12z" />
                                </svg>
                            </button>
                            <button
                                title="Share link"
                                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-900/10 hover:text-brand-600 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-brand-300"
                                @click="openShare(file)"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5M10.172 13.828a4 4 0 010-5.656l3-3a4 4 0 015.656 5.656l-1.5 1.5" />
                                </svg>
                            </button>
                            <a
                                :href="file.download_url"
                                title="Download"
                                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-900/10 hover:text-brand-600 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-brand-300"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                                </svg>
                            </a>
                            <button
                                title="Rename"
                                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-900/10 hover:text-brand-600 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-brand-300"
                                @click="startRename(file)"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>
                            <button
                                title="Delete"
                                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-900/10 hover:text-red-600 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-red-400"
                                @click="deleteFile(file)"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Drop target covers the whole content area, so a file can be
                 released anywhere rather than onto a small well. -->
            <div
                v-if="isDragging"
                class="pointer-events-none absolute inset-4 z-20 flex items-center justify-center rounded-2xl border-2 border-dashed border-brand-500 bg-gray-50/85 backdrop-blur-sm dark:border-brand-400 dark:bg-gray-950/80"
            >
                <div class="text-center">
                    <svg class="mx-auto h-8 w-8 text-brand-600 dark:text-brand-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-8m0 0l-3 3m3-3l3 3M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2" />
                    </svg>
                    <p class="mt-2 text-sm font-medium text-brand-700 dark:text-brand-300">Drop to upload</p>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------ preview dialog -->
        <div
            v-if="previewing"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4 backdrop-blur-sm dark:bg-gray-950/70"
            @click.self="closePreview"
        >
            <div class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-white/10">
                    <div class="min-w-0">
                        <h2 class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">Preview</h2>
                        <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                            {{ previewing.name }} - {{ previewing.human_size }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <a
                            :href="previewing.download_url"
                            class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-brand-500"
                        >
                            Download
                        </a>
                        <button
                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-900/10 hover:text-gray-900 dark:hover:bg-white/10 dark:hover:text-gray-200"
                            aria-label="Close preview"
                            @click="closePreview"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-auto bg-gray-50 p-4 dark:bg-gray-950/70">
                    <div
                        v-if="previewing.preview_kind === 'image'"
                        class="flex min-h-[24rem] items-center justify-center"
                    >
                        <img
                            :src="previewing.preview_url"
                            :alt="previewing.name"
                            class="max-h-[72vh] max-w-full rounded-xl object-contain shadow-lg"
                        />
                    </div>

                    <iframe
                        v-else-if="previewing.preview_kind === 'pdf'"
                        :src="previewing.preview_url"
                        :title="`Preview of ${previewing.name}`"
                        class="h-[72vh] w-full rounded-xl border border-gray-200 bg-white dark:border-white/10"
                    />

                    <div
                        v-else-if="previewing.preview_kind === 'office'"
                        class="space-y-3"
                    >
                        <iframe
                            :src="officeViewerUrl(previewing)"
                            :title="`Preview of ${previewing.name}`"
                            class="h-[72vh] w-full rounded-xl border border-gray-200 bg-white dark:border-white/10"
                        />
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            This preview is rendered by Microsoft Office for the web. If it fails to load, use Download.
                        </p>
                    </div>

                    <div
                        v-else-if="previewing.preview_kind === 'video'"
                        class="flex min-h-[24rem] items-center justify-center"
                    >
                        <video
                            :src="previewing.preview_url"
                            controls
                            class="max-h-[72vh] w-full rounded-xl bg-black shadow-lg"
                        />
                    </div>

                    <div
                        v-else-if="previewing.preview_kind === 'audio'"
                        class="flex min-h-[24rem] flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-gray-300 bg-white px-6 py-10 dark:border-white/10 dark:bg-gray-900"
                    >
                        <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-600/10 text-brand-600 dark:bg-brand-400/10 dark:text-brand-300">
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 19V6l12-2v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-2c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z" />
                            </svg>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-300">Audio preview</p>
                        <audio :src="previewing.preview_url" controls class="w-full max-w-2xl" />
                    </div>

                    <div
                        v-else-if="previewing.preview_kind === 'text'"
                        class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900"
                    >
                        <div v-if="previewLoading" class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">
                            Loading preview...
                        </div>
                        <div v-else-if="previewError" class="px-4 py-6 text-sm text-red-600 dark:text-red-400">
                            {{ previewError }}
                        </div>
                        <pre v-else class="max-h-[72vh] overflow-auto p-4 text-sm leading-6 text-gray-800 dark:text-gray-200">{{ previewText }}</pre>
                    </div>

                    <div
                        v-else
                        class="flex min-h-[24rem] items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white px-6 py-10 text-sm text-gray-500 dark:border-white/10 dark:bg-gray-900 dark:text-gray-400"
                    >
                        Preview is not available for this file type.
                    </div>
                </div>
            </div>
        </div>

        <!-- -------------------------------------------------- share dialog -->
        <div
            v-if="sharing"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4 backdrop-blur-sm dark:bg-gray-950/70"
            @click.self="sharing = null"
        >
            <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-gray-800">
                <div class="mb-1 flex items-start justify-between gap-4">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Share via link</h2>
                    <button
                        class="-mr-1 -mt-1 rounded-lg p-1 text-gray-500 transition hover:bg-gray-900/10 hover:text-gray-900 dark:hover:bg-white/10 dark:hover:text-gray-200"
                        aria-label="Close"
                        @click="sharing = null"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <p class="mb-4 truncate text-xs text-gray-500 dark:text-gray-400">
                    Shareable link for
                    <span class="text-gray-700 dark:text-gray-300">{{ sharing.name }}</span>
                </p>

                <div class="flex items-center gap-2">
                    <input
                        :value="sharing.download_url"
                        readonly
                        class="min-w-0 flex-1 rounded-lg border-0 bg-gray-900/5 py-2 text-xs text-gray-700 ring-1 ring-inset ring-gray-900/10 focus:ring-2 focus:ring-inset focus:ring-brand-500 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10"
                        @focus="$event.target.select()"
                    />
                    <button
                        class="shrink-0 rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-brand-500"
                        @click="copyShareLink"
                    >
                        {{ copied ? 'Copied' : 'Copy link' }}
                    </button>
                </div>

                <p class="mt-3 text-xs text-gray-500">
                    Anyone with this link can download the file — it does not require signing in.
                </p>
            </div>
        </div>
    </DriveLayout>
</template>
