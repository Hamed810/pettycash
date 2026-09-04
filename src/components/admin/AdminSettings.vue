<script setup lang="ts">

import { onMounted, reactive, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

import {
    getAdminSettings,
    saveAdminSettings,
    type AdminSettings
} from '../../services/api'


const loading = ref(false)
const saving = ref(false)
const message = ref('')
const error = ref('')


const settings = reactive<AdminSettings>({
    allowMultipleOpenCostLists: false,
    allowUserDeleteOpenCostLists: false,
    requireVehicleKilometer: false,
    requireHiringPermit: false,
    requireFingerprint: false,
    ocrEnabled: false,
    ocrLanguage: 'fa',
    timezone: 'Asia/Tehran',
    defaultCurrency: 'IRR'
})


function explainError(e:any):string {
    return e?.response?.data?.ocs?.data?.message
        || e?.message
        || 'Unknown error'
}


async function load(){

    loading.value = true

    try {

        Object.assign(
            settings,
            await getAdminSettings()
        )

    } catch(e){

        error.value = explainError(e)

    } finally {

        loading.value = false

    }
}


async function save(){

    saving.value = true
    message.value=''
    error.value=''

    try {

        Object.assign(
            settings,
            await saveAdminSettings(settings)
        )

        message.value =
            t('pettycash','Settings saved')

    } catch(e){

        error.value = explainError(e)

    } finally {

        saving.value=false

    }

}


onMounted(load)

</script>


<template>

<section class="panel">

<h2>
{{t('pettycash','Petty Cash Settings')}}
</h2>


<p v-if="loading">
{{t('pettycash','Loading...')}}
</p>


<p v-if="error" class="error">
{{error}}
</p>


<p v-if="message" class="success">
{{message}}
</p>



<h3>
{{t('pettycash','Cost Lists')}}
</h3>


<label>
<input
type="checkbox"
v-model="settings.allowMultipleOpenCostLists"
/>

{{t('pettycash','Allow multiple open cost lists')}}
</label>


<br>


<label>
<input
type="checkbox"
v-model="settings.allowUserDeleteOpenCostLists"
/>

{{t('pettycash','Allow purchaser delete open cost lists')}}
</label>



<h3>
{{t('pettycash','Expense Validation')}}
</h3>


<label>
<input
type="checkbox"
v-model="settings.requireVehicleKilometer"
/>

{{t('pettycash','Require vehicle kilometer')}}
</label>


<br>


<label>
<input
type="checkbox"
v-model="settings.requireHiringPermit"
/>

{{t('pettycash','Require hiring permit')}}
</label>


<br>


<label>
<input
type="checkbox"
v-model="settings.requireFingerprint"
/>

{{t('pettycash','Require fingerprint evidence')}}
</label>



<h3>
{{t('pettycash','OCR')}}
</h3>


<label>
<input
type="checkbox"
v-model="settings.ocrEnabled"
/>

{{t('pettycash','Enable receipt OCR')}}
</label>


<br>


<label>

{{t('pettycash','OCR Language')}}

<select v-model="settings.ocrLanguage">

<option value="fa">
Persian
</option>

<option value="en">
English
</option>

</select>

</label>



<h3>
{{t('pettycash','Regional')}}
</h3>


<p>
Timezone:
<strong>
{{settings.timezone}}
</strong>
</p>


<p>
Default currency:
<strong>
{{settings.defaultCurrency}}
</strong>
</p>



<NcButton
variant="primary"
@click="save"
:disabled="saving"
>

{{t('pettycash','Save')}}

</NcButton>


</section>

</template>


<style scoped>

.panel{
padding:24px;
border:1px solid var(--color-border);
border-radius:var(--border-radius-large);
background:var(--color-main-background);
}


h3{
margin-top:25px;
}


label{
display:block;
margin:10px 0;
}


.error{
color:var(--color-error);
}


.success{
color:var(--color-success);
}

</style>
