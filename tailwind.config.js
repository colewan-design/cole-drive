import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    // The drive commits to a dark violet look rather than following the OS.
    // Switching from the default 'media' strategy to 'class' — with `dark` set
    // on <html> — means every dark: variant Breeze already ships (login,
    // profile, modals, form controls) lights up in the new palette instead of
    // needing to be rewritten one component at a time.
    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                // Tailwind's neutral, re-cut with violet in it. Overriding the
                // scale rather than adding a new one is what lets the untouched
                // Breeze components inherit the theme: their dark:bg-gray-800
                // becomes a deep violet panel with no edit to those files.
                gray: {
                    50: '#f8f7fc',
                    100: '#f0edf8',
                    200: '#ded8ee',
                    300: '#c4bade',
                    400: '#9d8fc0',
                    500: '#7b6ba2',
                    600: '#5d4e82',
                    700: '#3f3365',
                    750: '#332853',
                    800: '#271b49',
                    900: '#180e35',
                    950: '#0e0722',
                },

                // The accent. Used for the primary action, active nav, focus
                // rings and the storage meter.
                brand: {
                    300: '#c4b5fd',
                    400: '#a78bfa',
                    500: '#8b5cf6',
                    600: '#7c3aed',
                    700: '#6d28d9',
                },

                // One colour per file category, so a row is identifiable from
                // the icon alone before the name is read.
                filetype: {
                    document: '#60a5fa',
                    picture: '#c084fc',
                    video: '#f472b6',
                    audio: '#fbbf24',
                    archive: '#fb923c',
                    other: '#94a3b8',
                },
            },
        },
    },

    plugins: [forms],
};
