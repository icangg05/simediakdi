import prettier from 'eslint-config-prettier';
import vue from 'eslint-plugin-vue';

import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        /*
         * Folder skill agen (.agents, .claude, .codex) berisi kode pihak ketiga
         * yang ikut terbawa ke repositori, bukan sumber proyek. Skrip di
         * dalamnya menyumbang 302 error dan menenggelamkan temuan asli, padahal
         * tidak satu pun boleh kita sunting. Entri tailwind.config.js dicabut
         * karena berkasnya sudah tidak ada sejak migrasi ke Tailwind 4.
         */
        ignores: [
            'vendor',
            'node_modules',
            'public',
            'bootstrap/ssr',
            '.agents',
            '.claude',
            '.codex',
            'resources/js/components/ui/*',
        ],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'off',
        },
    },
    prettier,
);
