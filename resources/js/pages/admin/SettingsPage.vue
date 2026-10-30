<template>
  <div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-lg font-bold text-gray-900 mb-4">Settings</h1>

    <!-- Account Information -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Account Information</h2>
      <dl class="space-y-3">
        <div class="flex justify-between">
          <dt class="text-sm text-gray-500">Name</dt>
          <dd class="text-sm font-medium text-gray-900">{{ user?.title }} {{ user?.surname }} {{ user?.firstname }}</dd>
        </div>
        <div class="flex justify-between">
          <dt class="text-sm text-gray-500">Email</dt>
          <dd class="text-sm font-medium text-gray-900">{{ user?.email }}</dd>
        </div>
        <div class="flex justify-between">
          <dt class="text-sm text-gray-500">Role</dt>
          <dd>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :class="roleBadgeClass">
              {{ roleLabel }}
            </span>
          </dd>
        </div>
      </dl>
    </div>

    <!-- Payment Gateway Settings (Approver/Super Admin only) -->
    <template v-if="adminAuthStore.isApprover || adminAuthStore.isSuperAdmin">
      <div v-for="gw in gateways" :key="gw.group" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-sm font-semibold text-gray-900 mb-1">{{ gw.title }}</h2>
        <p class="text-xs text-gray-500 mb-4">{{ gw.description }}</p>

        <div v-if="gw.loading" class="py-6 text-center text-sm text-gray-400">Loading settings...</div>

        <form v-else @submit.prevent="handleSaveGroup(gw)" class="space-y-4">
          <div v-for="field in gw.fields" :key="field.key">
            <label class="block text-xs font-medium text-gray-600 mb-1">{{ field.label }}</label>
            <div v-if="field.type === 'select'">
              <select
                v-model="gw.values[field.key]"
                class="w-full max-w-md px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-run-dark focus:border-run-dark outline-none"
              >
                <option v-for="opt in field.options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </div>
            <div v-else>
              <input
                v-model="gw.values[field.key]"
                :type="field.sensitive && !showSecrets[field.key] ? 'password' : 'text'"
                :placeholder="field.placeholder"
                class="w-full max-w-md px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-run-dark focus:border-run-dark outline-none"
              />
              <button
                v-if="field.sensitive"
                type="button"
                @click="showSecrets[field.key] = !showSecrets[field.key]"
                class="text-[11px] text-gray-400 hover:text-gray-600 mt-1"
              >
                {{ showSecrets[field.key] ? 'Hide' : 'Show' }}
              </button>
            </div>
            <p v-if="field.hint" class="text-[11px] text-gray-400 mt-1">{{ field.hint }}</p>
          </div>

          <div class="flex items-center gap-3 pt-2">
            <button
              type="submit"
              :disabled="gw.saving"
              class="bg-run-dark text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-gray-800 transition disabled:opacity-50"
            >
              {{ gw.saving ? 'Saving...' : 'Save Settings' }}
            </button>
            <p v-if="gw.success" class="text-xs text-green-600">{{ gw.success }}</p>
          </div>
        </form>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useToast } from 'vue-toastification'
import { useAdminAuthStore } from '@/stores/adminAuth'
import * as adminApi from '@/api/adminApi'

const toast = useToast()
const adminAuthStore = useAdminAuthStore()
const user = computed(() => adminAuthStore.user)

const roleLabel = computed(() => {
  const map = { '200': 'Recommender', '300': 'Approver', '400': 'Super Admin' }
  return map[user.value?.role] || 'Admin'
})

const roleBadgeClass = computed(() => {
  const map = {
    '200': 'bg-blue-100 text-blue-800',
    '300': 'bg-purple-100 text-purple-800',
    '400': 'bg-amber-100 text-amber-800',
  }
  return map[user.value?.role] || 'bg-gray-100 text-gray-800'
})

const showSecrets = reactive({})

const gateways = reactive([
  {
    group: 'interswitch',
    title: 'Payment Gateway — Interswitch',
    description: 'Configure Interswitch WebPay credentials. Changes take effect immediately.',
    loading: true,
    saving: false,
    success: '',
    values: {
      interswitch_merchant_code: '',
      interswitch_pay_item_id: '',
      interswitch_mac_key: '',
      interswitch_redirect_url: '',
      interswitch_mode: 'TEST',
    },
    fields: [
      { key: 'interswitch_merchant_code', label: 'Merchant Code', placeholder: 'e.g. MX12345' },
      { key: 'interswitch_pay_item_id', label: 'Pay Item ID', placeholder: 'e.g. 2122679' },
      { key: 'interswitch_mac_key', label: 'MAC Key / Data Ref', placeholder: 'Enter MAC key', sensitive: true, hint: 'Keep this confidential.' },
      { key: 'interswitch_redirect_url', label: 'Redirect URL', placeholder: 'https://example.com/', hint: 'Where the user returns after payment.' },
      { key: 'interswitch_mode', label: 'Mode', type: 'select', options: [{ value: 'TEST', label: 'Test' }, { value: 'LIVE', label: 'Live' }], hint: 'Use Test mode for development, Live for production.' },
    ],
  },
  {
    group: 'remita',
    title: 'Payment Gateway — Remita',
    description: 'Configure Remita payment credentials and service type IDs per destination.',
    loading: true,
    saving: false,
    success: '',
    values: {
      remita_merchant_id: '',
      remita_api_key: '',
      remita_st_soft: '',
      remita_st_wes: '',
      remita_st_nigeria: '',
      remita_st_africa: '',
      remita_st_america: '',
      remita_st_asia: '',
      remita_st_australia: '',
      remita_st_europe: '',
      remita_st_canada: '',
      remita_st_degree: '',
    },
    fields: [
      { key: 'remita_merchant_id', label: 'Merchant ID', placeholder: 'e.g. 4161150426' },
      { key: 'remita_api_key', label: 'API Key', placeholder: 'e.g. 258341', sensitive: true, hint: 'Keep this confidential.' },
      { key: 'remita_st_soft', label: 'Service Type — Soft Copy', placeholder: 'e.g. 9928138149' },
      { key: 'remita_st_wes', label: 'Service Type — WES', placeholder: 'Service Type ID' },
      { key: 'remita_st_nigeria', label: 'Service Type — Nigeria', placeholder: 'Service Type ID' },
      { key: 'remita_st_africa', label: 'Service Type — Africa', placeholder: 'Service Type ID' },
      { key: 'remita_st_america', label: 'Service Type — America', placeholder: 'Service Type ID' },
      { key: 'remita_st_asia', label: 'Service Type — Asia', placeholder: 'Service Type ID' },
      { key: 'remita_st_australia', label: 'Service Type — Australia', placeholder: 'Service Type ID' },
      { key: 'remita_st_europe', label: 'Service Type — Europe', placeholder: 'Service Type ID' },
      { key: 'remita_st_canada', label: 'Service Type — Canada', placeholder: 'Service Type ID' },
      { key: 'remita_st_degree', label: 'Service Type — Degree Verification', placeholder: 'Service Type ID' },
    ],
  },
])

async function fetchGroupSettings(gw) {
  gw.loading = true
  try {
    const { data } = await adminApi.getAppSettings(gw.group)
    const settings = data.data || []
    settings.forEach(s => {
      if (s.key in gw.values) {
        gw.values[s.key] = s.value || ''
      }
    })
  } catch (e) {
    // fall through with empty values
  } finally {
    gw.loading = false
  }
}

async function handleSaveGroup(gw) {
  gw.saving = true
  gw.success = ''
  try {
    const settings = Object.entries(gw.values).map(([key, value]) => ({ key, value: value || '' }))
    await adminApi.updateAppSettings(settings)
    gw.success = 'Settings saved.'
    toast.success(`${gw.title.replace('Payment Gateway — ', '')} settings updated.`)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to save settings.')
  } finally {
    gw.saving = false
  }
}

onMounted(() => {
  if (adminAuthStore.isApprover || adminAuthStore.isSuperAdmin) {
    gateways.forEach(gw => fetchGroupSettings(gw))
  }
})
</script>
