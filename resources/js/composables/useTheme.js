import { computed, ref } from 'vue';

// Kept in step with the inline snippet in resources/views/app.blade.php, which
// applies the same choice before first paint. If this key changes, change it
// there too or a reload will flash the wrong theme.
const STORAGE_KEY = 'uitph-drive-theme';

export const THEME_MODES = ['system', 'light', 'dark'];

const media =
    typeof window !== 'undefined' && window.matchMedia
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;

function readStored() {
    try {
        const value = localStorage.getItem(STORAGE_KEY);
        return THEME_MODES.includes(value) ? value : 'system';
    } catch {
        // Private windows and blocked site data throw on access rather than
        // returning null, so this has to be caught, not null-checked.
        return 'system';
    }
}

// Module-level, so every component that calls useTheme() shares one source of
// truth rather than each holding its own copy.
const mode = ref(readStored());

const systemPrefersDark = ref(media?.matches ?? false);
media?.addEventListener('change', (event) => {
    systemPrefersDark.value = event.matches;
    apply();
});

const resolved = computed(() =>
    mode.value === 'system' ? (systemPrefersDark.value ? 'dark' : 'light') : mode.value,
);

function apply() {
    if (typeof document === 'undefined') return;
    document.documentElement.classList.toggle('dark', resolved.value === 'dark');
}

function setMode(next) {
    if (!THEME_MODES.includes(next)) return;

    mode.value = next;

    try {
        // 'system' is stored as the absence of a key, so a browser that later
        // changes OS theme keeps following it.
        if (next === 'system') {
            localStorage.removeItem(STORAGE_KEY);
        } else {
            localStorage.setItem(STORAGE_KEY, next);
        }
    } catch {
        // Persisting is a convenience; the theme still applies for this page.
    }

    apply();
}

function cycleMode() {
    setMode(THEME_MODES[(THEME_MODES.indexOf(mode.value) + 1) % THEME_MODES.length]);
}

// Re-assert on load. The blade snippet has already done this, but Inertia page
// visits do not re-run it and the two must not drift.
apply();

export function useTheme() {
    return { mode, resolved, setMode, cycleMode };
}
