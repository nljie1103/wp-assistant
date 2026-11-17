<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import {
  DialogRoot, DialogPortal, DialogOverlay, DialogContent,
  DialogTitle, DialogDescription
} from 'reka-ui'
import { AlertTriangle, Info, ShieldAlert, X } from 'lucide-vue-next'

const props = defineProps<{ service: any; finish: (answer: boolean) => void }>()
const dialog = computed(() => props.service.active)
const isOpen = computed(() => !!dialog.value)
const confirmButton = ref<HTMLButtonElement|null>(null)
const cancelButton = ref<HTMLButtonElement|null>(null)
watch(isOpen, async (opened) => {
  if (!opened) return
  await nextTick()
  // Focus the safe choice by default for potentially destructive operations.
  if (dialog.value?.danger) cancelButton.value?.focus()
  else confirmButton.value?.focus()
})
function change(opened: boolean) {
  if (!opened && dialog.value) props.finish(false)
}
</script>

<template>
  <DialogRoot :open="isOpen" @update:open="change">
    <DialogPortal>
      <DialogOverlay class="jlwa-ui-dialog-overlay"/>
      <DialogContent class="jlwa-ui-confirm-dialog" :aria-describedby="dialog ? 'jlwa-confirm-description' : undefined">
        <div class="jlwa-ui-confirm-header">
          <div class="jlwa-ui-confirm-symbol" :class="{'is-danger': dialog?.danger}">
            <ShieldAlert v-if="dialog?.danger" :size="22"/>
            <AlertTriangle v-else-if="dialog?.type === 'confirm'" :size="22"/>
            <Info v-else :size="22"/>
          </div>
          <button type="button" class="jlwa-ui-confirm-close" aria-label="关闭对话框" @click="finish(false)"><X :size="18"/></button>
        </div>
        <DialogTitle class="jlwa-ui-confirm-title">{{ dialog?.title || (dialog?.type === 'confirm' ? '请确认操作' : '操作提示') }}</DialogTitle>
        <DialogDescription id="jlwa-confirm-description" class="jlwa-ui-confirm-description">{{ dialog?.message }}</DialogDescription>
        <div class="jlwa-ui-confirm-actions">
          <button v-if="dialog?.type === 'confirm'" ref="cancelButton" type="button" class="jlwa-ui-confirm-cancel" @click="finish(false)">{{ dialog?.cancelLabel || '取消' }}</button>
          <button ref="confirmButton" type="button" class="jlwa-ui-confirm-accept" :class="{ 'is-danger': dialog?.danger }" @click="finish(true)">{{ dialog?.confirmLabel || (dialog?.type === 'confirm' ? '确认' : '知道了') }}</button>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>