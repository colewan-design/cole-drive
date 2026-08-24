<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);

const initials = computed(() =>
    (user.value?.name ?? '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join(''),
);

// The sidebar is permanent from lg up and a slide-over below it, so the file
// table keeps the full width on a phone.
const sidebarOpen = ref(false);
</script>

<template>
    <div class="flex h-screen overflow-hidden bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-gray-900/40 backdrop-blur-sm dark:bg-gray-950/70 lg:hidden"
            @click="sidebarOpen = false"
        />

        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col border-r border-gray-200 bg-white transition-transform duration-200 dark:border-white/5 dark:bg-gray-900 lg:static lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 shrink-0 items-center gap-2.5 px-5">
                <Link :href="route('dashboard')" class="flex items-center gap-2.5">
                    <img src="/icon-512.png" alt="" class="h-8 w-8 rounded-lg" />
                    <span class="text-[15px] font-semibold tracking-tight">
                        UITPH <span class="text-brand-600 dark:text-brand-400">Drive</span>
                    </span>
                </Link>
            </div>

            <slot name="sidebar" />
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header
                class="flex h-16 shrink-0 items-center gap-3 border-b border-gray-200 bg-white/80 px-4 backdrop-blur dark:border-white/5 dark:bg-gray-900/60 sm:px-6"
            >
                <button
                    class="-ml-1 rounded-lg p-2 text-gray-500 hover:bg-gray-900/5 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-100 lg:hidden"
                    aria-label="Open navigation"
                    @click="sidebarOpen = true"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <slot name="topbar" />

                <ThemeToggle />

                <Dropdown align="right" width="48">
                    <template #trigger>
                        <button
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white ring-2 ring-transparent transition hover:ring-brand-400/40"
                            :title="user?.name"
                        >
                            {{ initials }}
                        </button>
                    </template>

                    <template #content>
                        <div class="border-b border-gray-200 px-4 py-3 dark:border-white/5">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ user?.name }}
                            </p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ user?.email }}
                            </p>
                        </div>
                        <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button">
                            Log Out
                        </DropdownLink>
                    </template>
                </Dropdown>
            </header>

            <main class="flex-1 overflow-y-auto">
                <slot />
            </main>
        </div>
    </div>
</template>
