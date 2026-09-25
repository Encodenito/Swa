<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/shop/staff/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Eye, EyeOff } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/** Creates a staff or admin account with a temporary password (POST admin.users.store). */
const props = defineProps<{ roles: { value: string; label: string }[] }>();

const open = defineModel<boolean>('open', { default: false });

const staffRoles = computed(() => props.roles.filter((r) => r.value === 'staff' || r.value === 'admin'));

const form = useForm({ name: '', email: '', phone: '', role: 'staff', password: '' });
const showPassword = ref(false);

function submit() {
    form.post(route('admin.users.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showPassword.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[calc(100dvh-2rem)] max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Add staff account</DialogTitle>
                <DialogDescription>The account can log in right away. Customers create their own accounts.</DialogDescription>
            </DialogHeader>
            <form id="add-staff-form" class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-1.5">
                    <Label for="staff-name">Full name</Label>
                    <Input
                        id="staff-name"
                        v-model="form.name"
                        required
                        maxlength="255"
                        autocomplete="off"
                        :aria-invalid="!!form.errors.name"
                        aria-describedby="staff-name-error"
                    />
                    <InputError id="staff-name-error" :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="staff-email">Email</Label>
                    <Input
                        id="staff-email"
                        v-model="form.email"
                        type="email"
                        required
                        maxlength="255"
                        autocomplete="off"
                        :aria-invalid="!!form.errors.email"
                        aria-describedby="staff-email-error"
                    />
                    <InputError id="staff-email-error" :message="form.errors.email" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="staff-phone">Mobile number (optional)</Label>
                    <Input
                        id="staff-phone"
                        v-model="form.phone"
                        type="tel"
                        inputmode="tel"
                        maxlength="13"
                        placeholder="09XXXXXXXXX"
                        autocomplete="off"
                        :aria-invalid="!!form.errors.phone"
                        aria-describedby="staff-phone-error"
                    />
                    <InputError id="staff-phone-error" :message="form.errors.phone" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="staff-role">Role</Label>
                    <NativeSelect
                        id="staff-role"
                        v-model="form.role"
                        required
                        :aria-invalid="!!form.errors.role"
                        aria-describedby="staff-role-hint staff-role-error"
                    >
                        <option v-for="r in staffRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
                    </NativeSelect>
                    <p id="staff-role-hint" class="text-sm text-muted-foreground">Admins can also change prices, settings and accounts.</p>
                    <InputError id="staff-role-error" :message="form.errors.role" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="staff-password">Temporary password</Label>
                    <div class="flex gap-2">
                        <Input
                            id="staff-password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            :aria-invalid="!!form.errors.password"
                            aria-describedby="staff-password-hint staff-password-error"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            class="size-10 shrink-0"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            :aria-pressed="showPassword"
                            @click="showPassword = !showPassword"
                        >
                            <EyeOff v-if="showPassword" class="size-4" aria-hidden="true" />
                            <Eye v-else class="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                    <p id="staff-password-hint" class="text-sm text-muted-foreground">
                        At least 8 characters. Share it privately, in person or by phone, never in a group chat. Ask them to change it after their
                        first login.
                    </p>
                    <InputError id="staff-password-error" :message="form.errors.password" />
                </div>
            </form>
            <DialogFooter class="gap-2 sm:gap-0">
                <Button type="button" variant="outline" :disabled="form.processing" @click="open = false">Cancel</Button>
                <Button type="submit" form="add-staff-form" :disabled="form.processing">
                    {{ form.processing ? 'Creating…' : 'Create account' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
