<template>
    <MainLayout />

    <div class="m-3">
        <div class="bg-white shadow sm:rounded-lg">
            <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                <h3 class="text-base font-semibold leading-6 text-gray-900">Reception Agent</h3>
                <p class="mt-1 text-sm text-gray-500">
                    The AI receptionist users summon mid-call by dialling
                    <code class="px-1 bg-gray-100 rounded">{{ form.feature_code || '*9' }}</code>.
                    It can transfer, park, three-way, and answer simple
                    questions like time and weather. One agent per domain.
                </p>
            </div>

            <div v-if="loading" class="px-4 py-10 text-center text-gray-500">Loading…</div>

            <Vueform v-if="!loading" ref="form$"
                     :endpoint="submit"
                     @success="onSuccess"
                     @error="onError"
                     :default="form"
                     :float-placeholders="false">
                <template #empty>
                    <div class="space-y-6 px-4 py-6 sm:p-6">
                        <FormElements>
                            <ToggleElement name="agent_enabled" label="Enabled"
                                           true-value="true" false-value="false" />

                            <TextElement name="agent_name" label="Agent name"
                                         placeholder="Reception Agent"
                                         :columns="{ sm: { container: 8 } }" />

                            <TextElement name="feature_code" label="Feature code"
                                         placeholder="*9"
                                         description="What users dial mid-call to summon the agent."
                                         :columns="{ sm: { container: 4 } }" />

                            <TextareaElement name="system_prompt" label="System prompt"
                                             :rows="6"
                                             description="Defines the agent's voice and rules. Tools are advertised separately." />

                            <TextareaElement name="first_message" label="First message"
                                             :rows="2"
                                             description="What the agent says when it joins the call." />

                            <SelectElement name="voice_id" :items="voiceOptions"
                                           :search="true" :native="false"
                                           label="Voice"
                                           input-type="search" autocomplete="off"
                                           placeholder="Choose a voice" />

                            <StaticElement name="tools_title" tag="h4" content="Tools" />
                            <p class="text-sm text-gray-500">
                                Toggle which actions the agent is allowed to take.
                            </p>

                            <ToggleElement v-for="(label, key) in toolList" :key="key"
                                           :name="`tools_enabled.${key}`"
                                           :label="label"
                                           true-value="true" false-value="false" />

                            <ButtonElement name="submit" button-label="Save"
                                           :submits="true" align="right" />
                        </FormElements>
                    </div>
                </template>
            </Vueform>

            <div v-if="!loading && permissions.test" class="px-4 py-4 sm:px-6 border-t border-gray-200 bg-gray-50">
                <h4 class="text-sm font-semibold text-gray-700 mb-2">Test summon</h4>
                <div class="flex space-x-2">
                    <input v-model="testExt" type="text" placeholder="Extension to call"
                           class="rounded-md border-0 py-1.5 px-3 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600" />
                    <button type="button" @click="runTest"
                            class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                            :disabled="!testExt">
                        Summon to extension
                    </button>
                </div>
                <div v-if="testMessage" class="mt-2 text-sm text-gray-600">{{ testMessage }}</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import MainLayout from '../Layouts/MainLayout.vue';

const props = defineProps({
    routes: Object,
    permissions: Object,
});

const loading = ref(true);
const form$ = ref(null);
const voices = ref([]);
const testExt = ref('');
const testMessage = ref('');

const toolList = {
    lookup_user:        'Lookup user by name',
    transfer_call:      'Blind transfer',
    announced_transfer: 'Announced transfer (caller listens)',
    park_call:          'Park call',
    bring_back:         'Retrieve from park',
    three_way_add:      'Three-way add',
    complete_and_exit:  'Complete and exit',
    get_time_in_city:   'Time in a city',
    get_weather:        'Weather in a city',
};

const form = ref({
    agent_name:    'Reception Agent',
    feature_code:  '*9',
    system_prompt: '',
    first_message: '',
    voice_id:      null,
    language:      'en',
    agent_enabled: 'true',
    tools_enabled: Object.keys(toolList).reduce((acc, k) => { acc[k] = 'true'; return acc; }, {}),
});

const voiceOptions = computed(() => {
    return (voices.value || []).reduce((acc, v) => {
        acc[v.value ?? v.voice_id] = v.label ?? v.name;
        return acc;
    }, {});
});

onMounted(async () => {
    try {
        const { data } = await axios.get(props.routes.show);
        voices.value = data.voices || [];
        const a = data.agent;
        if (a) {
            form.value = {
                agent_name:    a.agent_name,
                feature_code:  a.feature_code || data.defaults.feature_code,
                system_prompt: a.system_prompt || data.defaults.system_prompt,
                first_message: a.first_message || data.defaults.first_message,
                voice_id:      a.voice_id,
                language:      a.language || 'en',
                agent_enabled: a.agent_enabled || 'true',
                tools_enabled: stringifyMap({
                    ...data.defaults.tools_enabled,
                    ...(a.tools_enabled || {}),
                }),
            };
        } else {
            form.value = {
                ...form.value,
                system_prompt: data.defaults.system_prompt,
                first_message: data.defaults.first_message,
                feature_code:  data.defaults.feature_code,
                tools_enabled: stringifyMap(data.defaults.tools_enabled),
            };
        }
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
});

function stringifyMap(m) {
    const out = {};
    for (const [k, v] of Object.entries(m || {})) out[k] = v ? 'true' : 'false';
    return out;
}

const submit = async (FormData, instance) => {
    const data = instance.requestData;
    // Coerce tool toggle strings to booleans for the API.
    if (data.tools_enabled) {
        for (const k of Object.keys(data.tools_enabled)) {
            data.tools_enabled[k] = data.tools_enabled[k] === 'true' || data.tools_enabled[k] === true;
        }
    }
    return await instance.$vueform.services.axios.put(props.routes.update, data);
};

const onSuccess = () => {
    testMessage.value = 'Saved.';
    setTimeout(() => (testMessage.value = ''), 2000);
};

const onError = (err) => {
    console.error(err);
};

const runTest = async () => {
    testMessage.value = 'Sending…';
    try {
        const { data } = await axios.post(props.routes.test, { extension: testExt.value });
        testMessage.value = data.message || 'OK';
    } catch (e) {
        testMessage.value = e.response?.data?.error || 'Failed';
    }
};
</script>
