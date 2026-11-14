<template>
  <div class="ess-page">
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
      <span class="ic lg ic-teal"><i class="fa-solid fa-map-location-dot"></i></span>
      <div class="flex-grow-1"><h1 class="ess-h1">Pemantauan Kehadiran</h1>
        <p class="ess-sub">Lokasi clock-in/out di peta, heatmap ketibaan & ketepatan masa, dan trend masuk/balik pasukan</p></div>
    </div>
    <HrAttendanceInsights />
  </div>
</template>
<script setup>
import HrAttendanceInsights from '../../components/ess/HrAttendanceInsights.vue'
</script>
