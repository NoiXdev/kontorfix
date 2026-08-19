import prettier from 'eslint-config-prettier';
import vue from 'eslint-plugin-vue';

import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        ignores: ['vendor', 'node_modules', 'public', 'bootstrap/ssr', '.claude', 'resources/js/components/ui/*'],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'off',
            // Shared Form.vue components (admin/*/Form.vue) receive an Inertia `useForm()`
            // object as a prop and bind directly into its fields (`v-model="form.name"`) —
            // that is how Inertia forms work, the object itself is never reassigned. Only
            // flag reassigning the prop reference, not writing into its fields.
            'vue/no-mutating-props': ['error', { shallowOnly: true }],
        },
    },
    prettier,
);
