<script setup lang="ts">
/**
 * The confirmation ladder from §8, as one component.
 *
 * There are four levels in the handoff and only three of them are a dialog:
 *
 * 1. **normal** — no dialog at all. Opening a screen, saving a field,
 *    applying a filter. A confirmation here is a click somebody learns to
 *    dismiss without reading, which is how the next three stop working.
 * 2. **consequential** — `level="consequential"`. Say what will happen and
 *    ask. Suspending a service, issuing an invoice, cancelling an order.
 * 3. **high-risk** — `level="high-risk"`. Ask, and require a reason. The
 *    reason is not paperwork: it is written to the audit record, and the
 *    person reading that record six weeks later is usually the person who
 *    pressed the button.
 * 4. **destructive** — `level="destructive"`. Ask, require a reason, and
 *    require the operator to type the name of the thing. Typing it is the
 *    only guard that survives muscle memory, because there is nothing to
 *    click through.
 *
 * Step-up authentication, which §8 names for level 4 "as policy requires",
 * is a server decision and is **not** implemented: this installation has
 * two-factor at sign-in and no re-challenge. A dialog that claimed to have
 * re-authenticated somebody would be worse than one that does not pretend.
 *
 * The primary button is never the destructive one by default, and it stays
 * disabled until whatever the level requires has been given.
 */
import { computed, nextTick, ref, useId, watch } from 'vue'

import AppButton from './AppButton.vue'
import AppIcon from './AppIcon.vue'
import AppInput from './AppInput.vue'
import AppTextarea from './AppTextarea.vue'

const props = withDefaults(
  defineProps<{
    level?: 'consequential' | 'high-risk' | 'destructive'
    title: string
    /** What will happen, in a sentence. Not "are you sure". */
    description?: string
    confirmLabel?: string
    /**
     * The exact text a destructive confirmation asks to be typed, normally
     * the record's own name. Ignored at the other levels.
     */
    phrase?: string
    /** The request is in flight. Nothing can be pressed twice. */
    busy?: boolean
  }>(),
  {
    level: 'consequential',
    description: undefined,
    confirmLabel: undefined,
    phrase: undefined,
    busy: false,
  },
)

const emit = defineEmits<{ confirm: [reason: string | null] }>()

const open = defineModel<boolean>('open', { required: true })

const reason = ref('')
const typed = ref('')
const dialog = ref<HTMLElement | null>(null)

const titleId = useId()
const descriptionId = useId()

const needsReason = computed(() => props.level !== 'consequential')
const needsPhrase = computed(() => props.level === 'destructive' && props.phrase !== undefined)

const ready = computed(() => {
  if (props.busy) return false
  if (needsReason.value && reason.value.trim().length === 0) return false

  // Trimmed on both sides but case-sensitive: a name is a name, and
  // matching loosely would give away that the check is not really a check.
  return !needsPhrase.value || typed.value.trim() === props.phrase?.trim()
})

const label = computed(
  () => props.confirmLabel ?? (props.level === 'destructive' ? 'Delete' : 'Confirm'),
)

watch(open, async (isOpen) => {
  if (!isOpen) {
    reason.value = ''
    typed.value = ''

    return
  }

  await nextTick()

  // The first thing that has to be filled in, or the dialog itself when
  // there is nothing to fill in. Focus on the confirm button would be a
  // dialog somebody dismisses with the space bar they were already pressing.
  const field = dialog.value?.querySelector('textarea, input')

  if (field instanceof HTMLElement) field.focus()
  else dialog.value?.focus()
})

function cancel(): void {
  if (props.busy) return

  open.value = false
}

function confirm(): void {
  if (!ready.value) return

  emit('confirm', needsReason.value ? reason.value.trim() : null)
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 pt-[12vh]"
      @keydown.esc="cancel"
    >
      <!-- The scrim is a button for the pointer and invisible to everything
           else: Escape is the keyboard's way out and it already works. -->
      <div class="bg-background/70 fixed inset-0" aria-hidden="true" @click="cancel" />

      <div
        ref="dialog"
        role="alertdialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        :aria-describedby="description ? descriptionId : undefined"
        tabindex="-1"
        class="panel-enter border-line bg-surface-primary relative w-full max-w-lg origin-top rounded-[var(--radius-xl)] border p-5 shadow-(--shadow-panel)"
      >
        <div class="flex items-start gap-3">
          <span
            class="grid size-8 shrink-0 place-items-center rounded-full"
            :class="
              level === 'consequential' ? 'bg-warning/12 text-warning' : 'bg-danger/12 text-danger'
            "
            aria-hidden="true"
          >
            <AppIcon name="warning" :size="17" />
          </span>

          <div class="min-w-0 flex-1">
            <h2 :id="titleId" class="text-title font-semibold">{{ title }}</h2>
            <p v-if="description" :id="descriptionId" class="text-content-muted text-body mt-1">
              {{ description }}
            </p>
          </div>
        </div>

        <!--
          What is being agreed to, when it is a list rather than a sentence.
          Added for the adapter write confirmation, which has to name each
          capability individually: "allow changes" is not a thing anybody can
          consent to, and a description string is the wrong place for four
          bullet points.
        -->
        <div v-if="$slots.default" class="border-line mt-4 rounded-[var(--radius-md)] border p-3">
          <slot />
        </div>

        <div v-if="needsReason || needsPhrase" class="mt-4 flex flex-col gap-3">
          <AppTextarea
            v-if="needsReason"
            v-model="reason"
            label="Reason"
            :rows="2"
            hint="Written to the audit record. The person reading it later is usually you."
          />

          <AppInput
            v-if="needsPhrase"
            v-model="typed"
            :label="`Type ${phrase} to confirm`"
            autocomplete="off"
          />
        </div>

        <div class="mt-5 flex items-center justify-end gap-2">
          <AppButton variant="ghost" :disabled="busy" @click="cancel">Cancel</AppButton>
          <AppButton
            :variant="level === 'consequential' ? 'primary' : 'danger'"
            :disabled="!ready"
            :loading="busy"
            @click="confirm"
          >
            {{ label }}
          </AppButton>
        </div>
      </div>
    </div>
  </Teleport>
</template>
