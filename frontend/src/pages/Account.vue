<template>
  <div class="page-wrap">
    <div class="mx-auto" style="max-width:760px">
      <h1 class="page-h1">My Account</h1>
      <p class="page-sub">View your profile and reset your password.</p>

      <!-- Profile -->
      <div class="card p-4 mt-4">
        <div class="d-flex align-items-center mb-4">
          <div class="icon-circle bg-primary-subtle text-primary me-3"><i class="fa-regular fa-user"></i></div>
          <div><h5 class="mb-0 fw-bold">Profile</h5><div class="text-muted2 small">Account details from your session</div></div>
        </div>
        <label class="lbl">Email</label>
        <div class="input-group mb-3">
          <span class="input-group-text bg-light border-end-0"><i class="fa-regular fa-user text-secondary"></i></span>
          <input class="form-control bg-light border-start-0" value="admin@kssb.local" readonly />
        </div>
        <label class="lbl">Role</label>
        <div class="d-flex align-items-center justify-content-between form-control bg-light mb-3">
          <span><i class="fa-solid fa-circle-check text-primary me-2"></i>Super Admin</span>
          <span class="tile-label">SUPER ADMIN</span>
        </div>
        <label class="lbl">Employee ID</label>
        <input class="form-control mb-3" placeholder="E.g: A123980" disabled />
        <label class="lbl">Position</label>
        <input class="form-control" placeholder="E.g: Manager" disabled />
        <div class="text-end mt-4"><button class="btn btn-primary px-3"><i class="fa-solid fa-pen me-2"></i>Update Info</button></div>
      </div>

      <!-- Reset password -->
      <div class="card p-4 mt-4 mb-4">
        <div class="d-flex align-items-center mb-4">
          <div class="icon-circle bg-primary-subtle text-primary me-3"><i class="fa-solid fa-shield-halved"></i></div>
          <div><h5 class="mb-0 fw-bold">Reset Password</h5><div class="text-muted2 small">Enter your current password, then choose a new one.</div></div>
        </div>
        <label class="lbl">Current password</label>
        <div class="input-group mb-3">
          <input class="form-control" :type="show.cur?'text':'password'" placeholder="Enter current password" />
          <button class="btn btn-dark-eye" @click="show.cur=!show.cur"><i class="fa-regular fa-eye"></i></button>
        </div>
        <label class="lbl">New password</label>
        <div class="input-group mb-3">
          <input class="form-control" :type="show.nw?'text':'password'" placeholder="At least 6 characters" />
          <button class="btn btn-dark-eye" @click="show.nw=!show.nw"><i class="fa-regular fa-eye"></i></button>
        </div>
        <label class="lbl">Confirm new password</label>
        <div class="input-group mb-3">
          <input class="form-control" :type="show.cf?'text':'password'" placeholder="Re-enter new password" />
          <button class="btn btn-dark-eye" @click="show.cf=!show.cf"><i class="fa-regular fa-eye"></i></button>
        </div>
        <div class="text-end mt-3">
          <button class="btn btn-light me-2 px-3">Clear</button>
          <button class="btn btn-primary px-3">Reset password</button>
        </div>
      </div>
    </div>
  </div>
</template>
<script setup>
import { reactive } from 'vue'
const show = reactive({cur:false,nw:false,cf:false})
</script>
<style scoped>
.icon-circle{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;font-size:18px}
</style>
