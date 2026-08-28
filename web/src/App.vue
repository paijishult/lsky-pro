<script lang="ts" setup>
import {
  darkTheme,
  dateZhCN,
  dateEnUS,
  lightTheme,
  NConfigProvider,
  NGlobalStyle,
  NModalProvider,
  NDialogProvider,
  NMessageProvider,
  NNotificationProvider,
  zhCN,
  enUS,
  type GlobalThemeOverrides,
} from 'naive-ui';
import {computed, h, onMounted, ref, watch} from "vue";
import {useThemeStore} from "@/stores/theme";
import {applySiteTheme, getSiteThemeDefinition, readServerSiteTheme, type SiteThemeId} from "@/themes";
import Boot from "@/Boot.vue";
import {useLocaleStore} from "@/stores/locale";

const themeStore = useThemeStore()
const localeStore = useLocaleStore()
const theme = computed(() => themeStore.isDark ? darkTheme : lightTheme)

// Site style is independent from the existing light/dark preference. The
// server marker is normalized once before the SPA mounts, then re-applied to
// protect against malformed markup or navigation-time DOM replacement.
const siteTheme = ref<SiteThemeId>(applySiteTheme(readServerSiteTheme()))
const siteThemeDefinition = computed(() => getSiteThemeDefinition(siteTheme.value))
const themeOverrides = computed<GlobalThemeOverrides>(() => {
  const definition = siteThemeDefinition.value

  return {
    common: {
      fontWeightStrong: '600',
      borderRadius: '8px',
      borderRadiusSmall: '5px',
      ...definition.naiveThemeOverrides,
    },
    ...(definition.naiveButtonOverrides
      ? {Button: definition.naiveButtonOverrides}
      : {}),
  }
})
const componentOptions = computed(() => ({
  Empty: {
    renderIcon: () => h('img', {
      src: siteThemeDefinition.value.assets.empty,
      alt: '',
      'aria-hidden': 'true',
      class: 'theme-empty-illustration',
      decoding: 'async',
      draggable: false,
    }),
  },
}))

const setBodyTheme = (isDark: boolean) => {
  if (isDark) {
    document.body.classList.add('dark')
  } else {
    document.body.classList.remove('dark')
  }
}

onMounted(() => {
  // 设置 body 上的 dark 类
  // 这么做是因为 native ui 中使用编程式 api 的组件时，dom 在 app 外，会导致 tailwindcss 的 dark 失效
  setBodyTheme(themeStore.isDark)
  watch(() => themeStore.isDark, value => setBodyTheme(value))

  // Keep the server-provided site marker authoritative and separate from the
  // localStorage-backed light/dark Store.
  applySiteTheme(siteTheme.value)
})

</script>

<template>
  <NConfigProvider
    :date-locale="localeStore.locale === 'zh-CN' ? dateZhCN : dateEnUS"
    :locale="localeStore.locale === 'zh-CN' ? zhCN : enUS"
    :theme="theme"
    :theme-overrides="themeOverrides"
    :component-options="componentOptions"
    abstract
  >
    <NGlobalStyle/>

    <NModalProvider>
      <NNotificationProvider placement="top-right" :max="6">
        <NMessageProvider placement="bottom-right">
          <NDialogProvider>
            <Boot/>
          </NDialogProvider>
        </NMessageProvider>
      </NNotificationProvider>
    </NModalProvider>
  </NConfigProvider>
</template>
