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
            // Some components (e.g. DataTable) receive a state object made of refs from a
            // composable and are meant to mutate its leaves (state.search.value = ...) for
            // two-way binding. shallowOnly still flags reassigning the prop itself.
            'vue/no-mutating-props': ['error', { shallowOnly: true }],
        },
    },
    prettier,
);
