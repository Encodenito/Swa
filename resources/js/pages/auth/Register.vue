<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const form = useForm({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthBase title="Create an account" description="Enter your details below to create your account">
        <Head title="Register" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        :aria-invalid="!!form.errors.name || undefined"
                        aria-describedby="name-error"
                        type="text"
                        required
                        autofocus
                        tabindex="1"
                        autocomplete="name"
                        v-model="form.name"
                        placeholder="Full name"
                    />
                    <InputError id="name-error" :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        :aria-invalid="!!form.errors.email || undefined"
                        aria-describedby="email-error"
                        type="email"
                        required
                        tabindex="2"
                        autocomplete="email"
                        v-model="form.email"
                        placeholder="email@example.com"
                    />
                    <InputError id="email-error" :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="phone">Mobile number (optional)</Label>
                    <Input
                        id="phone"
                        :aria-invalid="!!form.errors.phone || undefined"
                        type="tel"
                        inputmode="tel"
                        tabindex="3"
                        autocomplete="tel"
                        maxlength="13"
                        v-model="form.phone"
                        placeholder="09171234567"
                        aria-describedby="phone-hint phone-error"
                    />
                    <p id="phone-hint" class="text-xs text-muted-foreground">So we can text you about pickup.</p>
                    <InputError id="phone-error" :message="form.errors.phone" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <Input
                        id="password"
                        :aria-invalid="!!form.errors.password || undefined"
                        aria-describedby="password-error"
                        type="password"
                        required
                        tabindex="4"
                        autocomplete="new-password"
                        v-model="form.password"
                        placeholder="Password"
                    />
                    <InputError id="password-error" :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        :aria-invalid="!!form.errors.password_confirmation || undefined"
                        aria-describedby="password_confirmation-error"
                        type="password"
                        required
                        tabindex="5"
                        autocomplete="new-password"
                        v-model="form.password_confirmation"
                        placeholder="Confirm password"
                    />
                    <InputError id="password_confirmation-error" :message="form.errors.password_confirmation" />
                </div>

                <div class="space-y-1 rounded-md border border-border bg-muted/40 p-3 text-xs text-muted-foreground">
                    <p>
                        We use your name, email and mobile number only to manage your reservations and contact you about pickup. We don't sell or
                        share your details. See our privacy notice at the shop counter.
                    </p>
                    <p>This follows the Data Privacy Act of 2012 (Republic Act No. 10173).</p>
                </div>

                <Button type="submit" class="w-full" tabindex="6" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Create account
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Already have an account?
                <TextLink :href="route('login')" class="underline underline-offset-4" :tabindex="7">Log in</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
