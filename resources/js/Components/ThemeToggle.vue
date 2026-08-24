<script setup>
import { computed } from 'vue';
import { useTheme } from '@/composables/useTheme';

const { mode, resolved, cycleMode } = useTheme();

// Three states rather than a plain on/off: without "system" there is no way
// back to following the OS once a choice has been made.
const LABELS = {
    system: 'Theme: system',
    light: 'Theme: light',
    dark: 'Theme: dark',
};

const label = computed(() => LABELS[mode.value]);
</script>

<template>
    <button
        type="button"
        :title="`${label} — click to change`"
        :aria-label="label"
        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-900/5 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-100"
        @click="cycleMode"
    >
        <!-- Following the OS: show a monitor, whichever way it currently resolves. -->
        <svg
            v-if="mode === 'system'"
            class="h-[1.125rem] w-[1.125rem]"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="1.8"
        >
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
        </svg>

        <svg
            v-else-if="mode === 'light'"
            class="h-[1.125rem] w-[1.125rem]"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="1.8"
        >
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1.5m0 15V21m9-9h-1.5m-15 0H3m15.364-6.364l-1.06 1.06M6.696 17.304l-1.06 1.06m12.728 0l-1.06-1.06M6.696 6.696l-1.06-1.06M16.5 12a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" />
        </svg>

        <svg
            v-else
            class="h-[1.125rem] w-[1.125rem]"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="1.8"
        >
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3a7 7 0 009.79 9.79z" />
        </svg>

        <span class="sr-only">{{ resolved }}</span>
    </button>
</template>
