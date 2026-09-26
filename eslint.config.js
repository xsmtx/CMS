import prettier from '@vue/eslint-config-prettier'
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'
import pluginVue from 'eslint-plugin-vue'

export default defineConfigWithVueTs(
  {
    name: 'infracms/ignores',
    ignores: [
      'public/**',
      'vendor/**',
      'node_modules/**',
      'storage/**',
      'bootstrap/cache/**',
      'docker/**',
    ],
  },

  {
    name: 'infracms/files',
    files: ['**/*.ts', '**/*.vue'],
  },

  pluginVue.configs['flat/recommended'],
  vueTsConfigs.recommended,
  prettier,

  {
    name: 'infracms/rules',
    rules: {
      // Debug statements must never reach a release build.
      'no-console': ['error', { allow: ['warn', 'error'] }],
      'no-debugger': 'error',
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/consistent-type-imports': 'error',
      'vue/multi-word-component-names': 'off',
      'vue/component-api-style': ['error', ['script-setup']],
      'vue/define-macros-order': 'error',
    },
  },

  {
    /*
     * A command-line tool's whole job is printing. `no-console` exists so a
     * debug statement cannot reach a release build, and nothing under
     * `tools/` is bundled - it is run by hand and by CI.
     */
    name: 'infracms/tools',
    files: ['tools/**/*.mjs', 'tools/**/*.js'],
    rules: {
      'no-console': 'off',
    },
  },
)
