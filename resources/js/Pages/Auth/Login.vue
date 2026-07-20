<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Log in" />

    <div class="flex min-h-screen flex-col bg-gradient-to-br from-violet-600 via-violet-700 to-indigo-900">
        <div class="mx-auto w-full max-w-md px-6 pb-6 pt-8">
            <Link
                href="/"
                class="flex h-10 w-10 items-center justify-center rounded-full bg-white/15 text-white backdrop-blur transition hover:bg-white/25"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </Link>

            <h1 class="mt-8 text-3xl font-bold text-white">Welcome back</h1>
            <p class="mt-2 text-sm text-violet-100">Sign in to access your files</p>
        </div>

        <div class="mx-auto flex w-full max-w-md flex-1 flex-col rounded-t-3xl bg-white px-6 pb-10 pt-8 shadow-xl dark:bg-gray-800">
            <div
                v-if="status"
                class="mb-4 rounded-lg bg-green-50 px-4 py-2 text-sm font-medium text-green-600 dark:bg-green-900/30 dark:text-green-400"
            >
                {{ status }}
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <div>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <input
                            id="email"
                            type="email"
                            v-model="form.email"
                            required
                            autofocus
                            autocomplete="username"
                            placeholder="Email"
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 py-3.5 pl-12 pr-4 text-sm text-gray-900 placeholder-gray-400 focus:border-violet-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:focus:bg-gray-700"
                        />
                    </div>
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        <input
                            id="password"
                            type="password"
                            v-model="form.password"
                            required
                            autocomplete="current-password"
                            placeholder="Password"
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 py-3.5 pl-12 pr-4 text-sm text-gray-900 placeholder-gray-400 focus:border-violet-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:focus:bg-gray-700"
                        />
                    </div>
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center">
                        <Checkbox name="remember" v-model:checked="form.remember" />
                        <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">Remember me</span>
                    </label>

                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="text-sm font-medium text-violet-600 hover:text-violet-700 dark:text-violet-400"
                    >
                        Forgot password?
                    </Link>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    :class="{ 'opacity-50': form.processing }"
                    class="w-full rounded-xl bg-violet-600 py-3.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700"
                >
                    Log in
                </button>
            </form>
        </div>
    </div>
</template>
