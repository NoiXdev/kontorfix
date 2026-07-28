<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Mail, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface UserRow {
    id: string;
    name: string;
    email: string;
    role: string;
    organization: string | null;
}

interface OrganizationOption {
    id: string;
    name: string;
}

const props = defineProps<{
    users: UserRow[];
    organizations: OrganizationOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Nutzer', href: '/admin/users' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const roleOptions = [
    { value: 'admin', label: 'Admin' },
    { value: 'maintainer', label: 'Maintainer' },
    { value: 'member', label: 'Member' },
];

const dialogOpen = ref(false);
const mode = ref<'invite' | 'password'>('invite');

const form = useForm({
    name: '',
    email: '',
    organization_id: '',
    role: 'member',
    password: '',
});

function setMode(next: 'invite' | 'password') {
    mode.value = next;
    if (next === 'invite') {
        // Clear the password so it isn't sent along in invite mode.
        form.password = '';
    }
}

function submit() {
    // Don't send a password in invite mode, so the backend sends an invitation.
    form.transform((data) => {
        if (mode.value === 'invite') {
            const payload = { ...data };
            delete (payload as Partial<typeof data>).password;
            return payload;
        }
        return data;
    }).post(route('admin.users.store'), {
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
            mode.value = 'invite';
        },
    });
}

function sendInvite(id: string) {
    router.post(route('admin.users.invite', id), {}, { preserveScroll: true });
}

function changeRole(id: string, role: string) {
    router.put(route('admin.users.update', id), { role }, { preserveScroll: true });
}

function destroyUser(id: string) {
    router.delete(route('admin.users.destroy', id), {
        onBefore: () => confirm('Nutzer wirklich löschen?'),
    });
}
</script>

<template>
    <Head title="Nutzer" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed right-4 top-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Nutzer</h1>
                <Button @click="dialogOpen = true">
                    <Plus class="size-4" />
                    Nutzer anlegen
                </Button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">E-Mail</th>
                            <th class="px-4 py-3 font-medium">Organisation</th>
                            <th class="px-4 py-3 font-medium">Rolle</th>
                            <th class="px-4 py-3 font-medium">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in props.users"
                            :key="user.id"
                            class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                        >
                            <td class="px-4 py-3 font-medium">{{ user.name }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ user.email }}</td>
                            <td class="px-4 py-3">{{ user.organization ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <select
                                    :value="user.role"
                                    @change="changeRole(user.id, ($event.target as HTMLSelectElement).value)"
                                    class="flex h-9 w-full rounded-md border border-input bg-background px-2 py-1 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    aria-label="Rolle ändern"
                                >
                                    <option v-for="opt in roleOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1">
                                    <Button variant="ghost" size="sm" @click="sendInvite(user.id)">
                                        <Mail class="size-4" />
                                        Einladung senden
                                    </Button>
                                    <Button variant="ghost" size="icon" @click="destroyUser(user.id)" aria-label="Nutzer löschen">
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="props.users.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">Noch keine Nutzer angelegt.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Nutzer anlegen</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" placeholder="Vor- und Nachname" autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">E-Mail</Label>
                        <Input id="email" type="email" v-model="form.email" placeholder="name@kunde.de" autocomplete="off" />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="organization_id">Organisation</Label>
                        <select
                            id="organization_id"
                            v-model="form.organization_id"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            <option value="" disabled>Bitte wählen</option>
                            <option v-for="org in props.organizations" :key="org.id" :value="org.id">{{ org.name }}</option>
                        </select>
                        <InputError :message="form.errors.organization_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="role">Rolle</Label>
                        <select
                            id="role"
                            v-model="form.role"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            <option v-for="opt in roleOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                        <InputError :message="form.errors.role" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Zugang</Label>
                        <div class="flex flex-col gap-2">
                            <label class="flex items-start gap-2 text-sm">
                                <input
                                    type="radio"
                                    name="mode"
                                    value="invite"
                                    :checked="mode === 'invite'"
                                    @change="setMode('invite')"
                                    class="mt-1"
                                />
                                <span>
                                    Einladung per E-Mail senden
                                    <span class="block text-xs text-muted-foreground">
                                        Der Nutzer bekommt eine E-Mail mit einem Link, um sein Passwort selbst zu setzen.
                                    </span>
                                </span>
                            </label>
                            <label class="flex items-start gap-2 text-sm">
                                <input
                                    type="radio"
                                    name="mode"
                                    value="password"
                                    :checked="mode === 'password'"
                                    @change="setMode('password')"
                                    class="mt-1"
                                />
                                <span>Passwort direkt setzen</span>
                            </label>
                        </div>
                    </div>

                    <div v-if="mode === 'password'" class="grid gap-2">
                        <Label for="password">Passwort</Label>
                        <Input id="password" type="password" v-model="form.password" autocomplete="new-password" />
                        <InputError :message="form.errors.password" />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">Abbrechen</Button>
                        <Button type="submit" :disabled="form.processing">Anlegen</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
