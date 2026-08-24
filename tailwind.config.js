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

    // 'class' rather than the default 'media' so the theme can be chosen in the
    // app instead of only following the OS. useTheme.js owns the `dark` class
    // on <html>; a snippet in app.blade.php sets it before first paint.
    //
    // It also means every dark: variant Breeze already ships (login, profile,
    // modals, form controls) works in the new palette without being rewritten
    // one component at a time.
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
                //
                // Two shades each: the DEFAULT is tuned for a dark panel, the
                // `light` variant is darkened for the same icon on white, where
                // the pale shade would drop to roughly 2:1 against the
                // background. Used as
                // `text-filetype-document-light dark:text-filetype-document`.
                filetype: {
                    document: { light: '#2563eb', DEFAULT: '#60a5fa' },
                    picture: { light: '#9333ea', DEFAULT: '#c084fc' },
                    video: { light: '#db2777', DEFAULT: '#f472b6' },
                    audio: { light: '#b45309', DEFAULT: '#fbbf24' },
                    archive: { light: '#ea580c', DEFAULT: '#fb923c' },
                    other: { light: '#475569', DEFAULT: '#94a3b8' },
                },
            },
        },
    },

    plugins: [forms],
};
