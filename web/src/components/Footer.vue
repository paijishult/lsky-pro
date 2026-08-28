<script setup lang="ts">
import {useConfigStore} from "@/stores/config";

const configStore = useConfigStore()
</script>

<template>
  <footer class="flex items-center shrink-0 w-full h-20 bg-white dark:bg-[var(--n-color)] border-t dark:border-gray-600 app-footer">
    <div class="flex flex-col md:flex-row md:justify-between mx-auto px-6 md:px-0 container max-w-screen-lg w-full text-gray-500 dark:text-gray-300 app-footer-inner">
      <div class="divide-x-2 divide-gray-300 app-footer-links">
        <a href="https://beian.miit.gov.cn/">{{ configStore.configs?.app.icp_no }}</a>
      </div>
      <div class="app-footer-copyright">
        {{ $t('Copyright', {
          year: new Date((configStore.configs?.app.timestamp || 0) * 1000).getFullYear(),
          name: configStore.configs?.app.name
        }) }}
      </div>
    </div>
  </footer>
</template>