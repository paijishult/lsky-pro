<script setup lang="ts">
import {useRouter} from "vue-router";
import {NButton} from "naive-ui";
import {getSiteThemeAssets, readServerSiteTheme} from "@/themes";

const router = useRouter()
const siteThemeAssets = getSiteThemeAssets(readServerSiteTheme())
function goHome() {
  router.push('/')
}
</script>

<template>
  <div class="flex flex-col items-center justify-center min-h-screen bg-gray-50 text-gray-700 dark:bg-gray-900 dark:text-gray-300 px-4 not-found-page">
    <img
      :src="siteThemeAssets.empty"
      alt=""
      aria-hidden="true"
      class="w-full max-w-[360px] max-h-[360px] object-contain not-found-illustration"
      decoding="async"
      draggable="false"
    />
    <h1 class="text-6xl font-extrabold text-blue-500 dark:text-blue-400 not-found-code">404</h1>
    <h2 class="text-lg md:text-xl font-medium mt-4 not-found-title">{{ $t('Sorry, page not found') }}</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 text-center max-w-xs not-found-desc">
      {{ $t('The page you are looking for does not exist.') }}<br />
      {{ $t('Please check if the URL is correct or return to the homepage.') }}
    </p>
    <NButton class="mt-2 not-found-btn" @click="goHome">{{ $t('Return to Homepage') }}</NButton>
  </div>
</template>