<script setup>
import DriveLayout from '@/Layouts/DriveLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    files: Array,
    storage: Object,
    categories: Object,
});

// Tone classes are written out in full rather than composed at runtime —
// Tailwind scans this file as text, so an interpolated class name would never
// make it into the stylesheet.
const CATEGORIES = [
    {
        name: 'Documents',
        tone: 'text-filetype-document bg-filetype-document/10',
        icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    },
    {
        name: 'Pictures',
        tone: 'text-filetype-picture bg-filetype-picture/10',
        icon: 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
    },
    {
        name: 'Video',
        tone: 'text-filetype-video bg-filetype-video/10',
        icon: 'M7 4v16M17 4v16M3 8h4m10 0h4M3 16h4m10 0h4M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z',
    },
    {
        name: 'Audio',
        tone: 'text-filetype-audio bg-filetype-audio/10',
        icon: 'M9 19V6l12-2v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-2c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z',
    },
    {
        name: 'Apps & Archives',
        tone: 'text-filetype-archive bg-filetype-archive/10',
        icon: 'M5 8h14M5 8a2 2 0 01-2-2V4a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 01-2 2M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8M10 12h4',
    },
    {
        name: 'Other',
        tone: 'text-filetype-other bg-filetype-other/10',
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
    return d.toLocaleDateString(undefined, {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
    }) + ', ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false });
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

/* ---------------------------------------------------------------- uploads */

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
                <button
                    :disabled="uploadForm.processing"
                    class="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/20 transition hover:bg-brand-500 disabled:cursor-not-allowed disabled:opacity-60"
                    @click="triggerFilePicker"
                >
                    <svg v-if="!uploadForm.processing" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>{{ uploadForm.processing ? `Uploading ${uploadForm.progress?.percentage ?? 0}%` : 'New' }}</span>
                </button>

                <div v-if="uploadForm.processing" class="mt-2 h-1 overflow-hidden rounded-full bg-white/10">
                    <div
                        class="h-full rounded-full bg-brand-400 transition-all duration-200"
                        :style="{ width: `${uploadForm.progress?.percentage ?? 0}%` }"
                    />
                </div>
            </div>

            <nav class="mt-6 flex-1 space-y-0.5 overflow-y-auto px-3">
                <button
                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm transition"
                    :class="activeCategory === null
                        ? 'bg-brand-600/15 font-medium text-brand-300'
                        : 'text-gray-300 hover:bg-white/5'"
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
                        ? 'bg-brand-600/15 font-medium text-brand-300'
                        : 'text-gray-300 hover:bg-white/5'"
                    @click="activeCategory = activeCategory === category.name ? null : category.name"
                >
                    <svg class="shrink-0" style="width:1.125rem;height:1.125rem" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="category.icon" />
                    </svg>
                    <span class="flex-1 truncate text-left">{{ category.name }}</span>
                    <span class="text-xs text-gray-500">{{ categories[category.name]?.count ?? 0 }}</span>
                </button>
            </nav>

            <div class="border-t border-white/5 p-4">
                <div class="mb-2 flex items-baseline justify-between text-xs">
                    <span class="font-medium text-gray-300">Storage</span>
                    <span class="text-gray-500">{{ usedPercent }}%</span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-white/10">
                    <div
                        class="h-full rounded-full bg-gradient-to-r from-brand-500 to-brand-400"
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
                    class="w-full rounded-xl border-0 bg-white/5 py-2 pl-9 pr-3 text-sm text-gray-100 ring-1 ring-inset ring-white/10 transition placeholder:text-gray-500 focus:bg-white/10 focus:ring-2 focus:ring-inset focus:ring-brand-500"
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
            <input ref="fileInput" type="file" class="hidden" @change="onFileChosen" />

            <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <nav class="flex items-center gap-1.5 text-xs text-gray-500">
                        <span>My Files</span>
                        <template v-if="activeCategory">
                            <span>/</span>
                            <span class="text-gray-300">{{ activeCategory }}</span>
                        </template>
                    </nav>
                    <h1 class="mt-1 text-xl font-semibold tracking-tight text-gray-50">
                        {{ activeCategory ?? 'My Files' }}
                    </h1>
                </div>

                <p class="text-xs text-gray-500">
                    {{ visibleFiles.length }}
                    {{ visibleFiles.length === 1 ? 'item' : 'items' }}
                    <template v-if="search || activeCategory"> · filtered</template>
                </p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-white/5 bg-gray-900/50">
                <!-- Column headers double as the sort control. Hidden on small
                     screens, where the row collapses to two stacked lines. -->
                <div class="hidden border-b border-white/5 px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-gray-500 sm:flex">
                    <button class="flex flex-1 items-center gap-1 text-left transition hover:text-gray-300" @click="toggleSort('name')">
                        Name
                        <span v-if="sortKey === 'name'" class="text-brand-400">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
                    </button>
                    <button class="flex w-28 items-center gap-1 transition hover:text-gray-300" @click="toggleSort('size')">
                        Size
                        <span v-if="sortKey === 'size'" class="text-brand-400">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
                    </button>
                    <button class="flex w-48 items-center gap-1 transition hover:text-gray-300" @click="toggleSort('created_at')">
                        Modified
                        <span v-if="sortKey === 'created_at'" class="text-brand-400">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
                    </button>
                    <span class="w-32 text-right">Actions</span>
                </div>

                <div v-if="!visibleFiles.length" class="px-6 py-20 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-white/5">
                        <svg class="h-6 w-6 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-8m0 0l-3 3m3-3l3 3M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2" />
                        </svg>
                    </div>
                    <p class="mt-4 text-sm font-medium text-gray-300">
                        {{ files.length ? 'Nothing matches that filter' : 'No files yet' }}
                    </p>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ files.length ? 'Try a different search or category.' : 'Drop a file anywhere, or use the New button.' }}
                    </p>
                </div>

                <ul v-else class="divide-y divide-white/5">
                    <li
                        v-for="file in visibleFiles"
                        :key="file.id"
                        class="group flex flex-col gap-2 px-4 py-2.5 transition hover:bg-white/[0.03] sm:flex-row sm:items-center sm:gap-0"
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
                                    class="w-full rounded-lg border-0 bg-white/10 py-1 text-sm text-gray-100 ring-1 ring-inset ring-brand-500 focus:ring-2 focus:ring-inset focus:ring-brand-400"
                                    @keyup.enter="submitRename(file)"
                                    @keyup.esc="cancelRename"
                                    @blur="submitRename(file)"
                                />
                                <p v-else class="truncate text-sm text-gray-100">{{ file.name }}</p>
                                <p class="truncate text-xs text-gray-500 sm:hidden">
                                    {{ file.human_size }} · {{ formatDate(file.created_at) }}
                                </p>
                            </div>
                        </div>

                        <div class="hidden w-28 shrink-0 text-sm text-gray-400 sm:block">
                            {{ file.human_size }}
                        </div>
                        <div class="hidden w-48 shrink-0 text-sm text-gray-400 sm:block">
                            {{ formatDate(file.created_at) }}
                        </div>

                        <!-- Actions stay mounted so keyboard users can reach
                             them; they only fade in on hover for the mouse. -->
                        <div class="flex shrink-0 items-center justify-end gap-0.5 sm:w-32 sm:opacity-0 sm:transition sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
                            <button
                                title="Share link"
                                class="rounded-lg p-2 text-gray-400 transition hover:bg-white/10 hover:text-brand-300"
                                @click="openShare(file)"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5M10.172 13.828a4 4 0 010-5.656l3-3a4 4 0 015.656 5.656l-1.5 1.5" />
                                </svg>
                            </button>
                            <a
                                :href="file.download_url"
                                title="Download"
                                class="rounded-lg p-2 text-gray-400 transition hover:bg-white/10 hover:text-brand-300"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                                </svg>
                            </a>
                            <button
                                title="Rename"
                                class="rounded-lg p-2 text-gray-400 transition hover:bg-white/10 hover:text-brand-300"
                                @click="startRename(file)"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>
                            <button
                                title="Delete"
                                class="rounded-lg p-2 text-gray-400 transition hover:bg-white/10 hover:text-red-400"
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
                class="pointer-events-none absolute inset-4 z-20 flex items-center justify-center rounded-2xl border-2 border-dashed border-brand-400 bg-gray-950/80 backdrop-blur-sm"
            >
                <div class="text-center">
                    <svg class="mx-auto h-8 w-8 text-brand-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-8m0 0l-3 3m3-3l3 3M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2" />
                    </svg>
                    <p class="mt-2 text-sm font-medium text-brand-300">Drop to upload</p>
                </div>
            </div>
        </div>

        <!-- -------------------------------------------------- share dialog -->
        <div
            v-if="sharing"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/70 p-4 backdrop-blur-sm"
            @click.self="sharing = null"
        >
            <div class="w-full max-w-md rounded-2xl border border-white/10 bg-gray-800 p-5 shadow-2xl">
                <div class="mb-1 flex items-start justify-between gap-4">
                    <h2 class="text-sm font-semibold text-gray-100">Share via link</h2>
                    <button
                        class="-mr-1 -mt-1 rounded-lg p-1 text-gray-500 transition hover:bg-white/10 hover:text-gray-200"
                        aria-label="Close"
                        @click="sharing = null"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <p class="mb-4 truncate text-xs text-gray-400">
                    Shareable link for <span class="text-gray-300">{{ sharing.name }}</span>
                </p>

                <div class="flex items-center gap-2">
                    <input
                        :value="sharing.download_url"
                        readonly
                        class="min-w-0 flex-1 rounded-lg border-0 bg-white/5 py-2 text-xs text-gray-300 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-inset focus:ring-brand-500"
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
