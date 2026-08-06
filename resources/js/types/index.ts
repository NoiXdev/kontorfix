import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
    can?: {
        console: boolean;
        super: boolean;
    };
}

export interface OrgScopeOption {
    id: string;
    name: string;
}

export interface RegistryTypeMeta {
    value: string;
    label: string;
    publish_based: boolean;
}

export interface OrgScope {
    active: string | null;
    organizations: OrgScopeOption[];
    canSelectAll: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: {
        success?: string | null;
        plainTextToken?: string | null;
        plainApiKey?: string | null;
        incomingWebhookSecret?: string | null;
        incomingWebhookUrl?: string | null;
    };
    registrationEnabled?: boolean;
    appVersion?: string;
    scope?: OrgScope | null;
    registryTypeMeta?: RegistryTypeMeta[];
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: string;
    name: string;
    email: string;
    role: 'admin' | 'maintainer' | 'member';
    is_super_admin?: boolean;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
