<script setup lang="ts">
/**
 * A textarea with a toolbar, not a WYSIWYG editor.
 *
 * What is stored is Markdown — the characters the agent can see and edit —
 * and the server renders it on the way out. A rich editor that produced
 * HTML would mean storing markup somebody typed, which is the thing a
 * support inbox must never do: anybody can open a ticket and an operator
 * will read it.
 *
 * The toolbar wraps the selection rather than replacing it, and puts the
 * caret back where it belongs afterwards. A button that emptied the field
 * every time somebody pressed it by accident would be worse than no
 * toolbar at all.
 */
import { computed, ref } from 'vue'

const props = withDefaults(
  defineProps<{
    label: string
    rows?: number
    error?: string
    hint?: string
  }>(),
  { rows: 12, error: undefined, hint: undefined },
)

const model = defineModel<string>({ required: true })

const field = ref<HTMLTextAreaElement | null>(null)

interface Tool {
  key: string
  label: string
  title: string
  /** Wrapped around the selection. */
  wrap?: [string, string]
  /** Put at the start of each selected line. */
  prefix?: string
}

const TOOLS: Tool[] = [
  { key: 'bold', label: 'B', title: 'Bold', wrap: ['**', '**'] },
  { key: 'italic', label: 'I', title: 'Italic', wrap: ['_', '_'] },
  { key: 'code', label: '</>', title: 'Code', wrap: ['`', '`'] },
  { key: 'quote', label: '❝', title: 'Quote', prefix: '> ' },
  { key: 'list', label: '•', title: 'Bulleted list', prefix: '- ' },
  { key: 'ordered', label: '1.', title: 'Numbered list', prefix: '1. ' },
  { key: 'link', label: '🔗', title: 'Link', wrap: ['[', '](https://)'] },
]

const characters = computed(() => model.value.length)

function apply(tool: Tool): void {
  const element = field.value

  if (!element) return

  const start = element.selectionStart
  const end = element.selectionEnd
  const value = model.value
  const selected = value.slice(start, end)

  let replacement: string
  let caret: number

  if (tool.prefix) {
    // Line prefixes apply to every line the selection touches, which is
    // what somebody highlighting three lines and pressing "list" means.
    const lineStart = value.lastIndexOf('\n', start - 1) + 1
    const block = value.slice(lineStart, end)
    const prefixed = block
      .split('\n')
      .map((line) => (line.startsWith(tool.prefix as string) ? line : tool.prefix + line))
      .join('\n')

    model.value = value.slice(0, lineStart) + prefixed + value.slice(end)
    caret = lineStart + prefixed.length
  } else {
    const [open, close] = tool.wrap ?? ['', '']

    replacement = open + selected + close
    model.value = value.slice(0, start) + replacement + value.slice(end)
    // An empty selection leaves the caret between the markers, so the
    // next keystroke lands inside the thing that was just created.
    caret = selected === '' ? start + open.length : start + replacement.length
  }

  requestAnimationFrame(() => {
    element.focus()
    element.setSelectionRange(caret, caret)
  })
}

/** Appends text at the caret, for the inserters beside the field. */
function insert(text: string): void {
  const element = field.value
  const at = element ? element.selectionStart : model.value.length
  const value = model.value
  const spacer = value === '' || value.endsWith('\n') ? '' : '\n\n'

  model.value = value.slice(0, at) + spacer + text + value.slice(at)

  const caret = at + spacer.length + text.length

  requestAnimationFrame(() => {
    element?.focus()
    element?.setSelectionRange(caret, caret)
  })
}

defineExpose({ insert })
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label class="text-body font-medium">{{ props.label }}</label>

    <div
      class="border-line focus-within:border-brand overflow-hidden rounded-sm border transition-colors duration-(--duration-fast)"
    >
      <div
        class="border-line bg-surface-secondary flex flex-wrap items-center gap-0.5 border-b px-1.5 py-1"
      >
        <button
          v-for="tool in TOOLS"
          :key="tool.key"
          type="button"
          class="pressable text-content-muted hover:bg-surface-primary hover:text-content text-chrome rounded-sm px-2 py-1 transition-colors duration-(--duration-fast)"
          :class="tool.key === 'bold' ? 'font-bold' : tool.key === 'italic' ? 'italic' : ''"
          :title="tool.title"
          :aria-label="tool.title"
          @click="apply(tool)"
        >
          {{ tool.label }}
        </button>

        <span class="text-content-subtle text-label ml-auto pr-1 tabular-nums">
          {{ characters }}
        </span>
      </div>

      <textarea
        ref="field"
        v-model="model"
        :rows="props.rows"
        class="bg-surface-primary text-content placeholder:text-content-subtle text-body block w-full resize-y px-3 py-2.5 leading-relaxed outline-none"
      />
    </div>

    <p v-if="props.hint" class="text-content-muted text-chrome">{{ props.hint }}</p>
    <p v-if="props.error" class="text-danger text-chrome">{{ props.error }}</p>
  </div>
</template>
