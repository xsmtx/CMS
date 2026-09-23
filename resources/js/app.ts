import { createInertiaApp } from '@inertiajs/vue3'
import type { DefineComponent } from 'vue'
import { createApp, h } from 'vue'

import '../css/app.css'

/**
 * First-party admin and client applications.
 *
 * The public storefront is deliberately not part of this bundle: it is
 * server-rendered through the storefront renderer so that it stays themeable
 * and indexable.
 */
void createInertiaApp({
  title: (title) => (title ? `${title} · ${appName()}` : appName()),

  resolve: (name) => {
    const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { eager: true })
    const page = pages[`./Pages/${name}.vue`]

    if (!page) {
      throw new Error(`Inertia page [${name}] was not found in resources/js/Pages.`)
    }

    return page
  },

  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el)
  },

  progress: {
    color: 'var(--brand-primary)',
  },
})

function appName(): string {
  return document.querySelector('title')?.dataset.appName ?? 'InfraCMS'
}
