<template>
    <MainLayout />

    <div class="m-3">
        <DataTable>
            <template #title>API Tokens</template>

            <template #action>
                <button type="button" @click.prevent="handleCreateButtonClick()"
                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-2.5 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                    <PlusIcon aria-hidden="true" class="h-5 w-5" />
                    Create token
                </button>
            </template>

            <template #table-header>
                <TableColumnHeader header="Name"
                    class="whitespace-nowrap px-4 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Abilities"
                    class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Created"
                    class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Last Used"
                    class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Expires"
                    class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header=""
                    class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
            </template>

            <template #table-body>
                <tr v-for="row in data" :key="row.id">
                    <TableField class="whitespace-nowrap px-4 py-2 text-sm text-gray-500" :text="row.name" />
                    <TableField class="whitespace-nowrap px-2 py-2 text-sm text-gray-500"
                        :text="(row.abilities || []).join(', ')" />
                    <TableField class="whitespace-nowrap px-2 py-2 text-sm text-gray-500"
                        :text="row.created_at_formatted" />
                    <TableField class="whitespace-nowrap px-2 py-2 text-sm text-gray-500"
                        :text="row.last_used_at_formatted" />
                    <TableField class="whitespace-nowrap px-2 py-2 text-sm text-gray-500"
                        :text="row.expires_at_formatted" />
                    <TableField class="whitespace-nowrap px-2 py-1 text-sm text-gray-500">
                        <template #action-buttons>
                            <div class="flex items-center justify-end whitespace-nowrap">
                                <TrashIcon @click="handleSingleItemDeleteRequest(row.destroy_route)"
                                    class="h-9 w-9 transition duration-500 ease-in-out py-2 rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-600 active:bg-gray-300 active:duration-150 cursor-pointer" />
                            </div>
                        </template>
                    </TableField>
                </tr>
            </template>

            <template #empty>
                <div v-if="data.length === 0" class="text-center my-5">
                    <KeyIcon class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-semibold text-gray-900">No API tokens</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Create a token to call the CDR API for this account.
                    </p>
                </div>
            </template>

            <template #loading>
                <Loading :show="loading" />
            </template>
        </DataTable>
    </div>

    <AddEditItemModal :show="showCreateModal" :header="'Create API token'" :loading="false" @close="handleModalClose">
        <template #modal-body>
            <div class="p-4">
                <div v-if="!createdToken">
                    <div class="mb-4">
                        <label for="token-name" class="block text-sm font-medium text-gray-700">Name</label>
                        <input id="token-name" v-model="createForm.name" type="text"
                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm"
                            placeholder="e.g. reporting-dashboard" />
                        <p v-if="formErrors?.name" class="mt-1 text-sm text-rose-600">{{ formErrors.name[0] }}</p>
                    </div>
                    <div class="mb-4">
                        <label for="token-expiry" class="block text-sm font-medium text-gray-700">
                            Expires after (days, optional)
                        </label>
                        <input id="token-expiry" v-model="createForm.expires_days" type="number" min="1" max="3650"
                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm"
                            placeholder="Never" />
                        <p v-if="formErrors?.expires_days" class="mt-1 text-sm text-rose-600">
                            {{ formErrors.expires_days[0] }}</p>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="handleModalClose"
                            class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="button" :disabled="createFormSubmiting" @click="handleCreateRequest"
                            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50">
                            {{ createFormSubmiting ? 'Creating…' : 'Create' }}
                        </button>
                    </div>
                </div>

                <div v-else>
                    <div class="rounded-md bg-green-50 p-4 mb-4">
                        <p class="text-sm font-medium text-green-800 mb-2">
                            Copy this token now — it will not be shown again.
                        </p>
                        <div class="flex items-center gap-2">
                            <code class="block w-full break-all rounded bg-white p-2 text-xs text-gray-900 ring-1 ring-inset ring-green-300">
                                {{ createdToken }}
                            </code>
                            <button type="button" @click="copyToClipboard(createdToken)"
                                class="rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                                {{ copied ? 'Copied' : 'Copy' }}
                            </button>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="button" @click="handleModalClose"
                            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                            Done
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </AddEditItemModal>

    <DeleteConfirmationModal :show="confirmationModalTrigger" @close="confirmationModalTrigger = false"
        @confirm="confirmDeleteAction" />

    <Notification :show="notificationShow" :type="notificationType" :messages="notificationMessages"
        @update:show="hideNotification" />
</template>

<script setup>
import { ref } from "vue";
import axios from "axios";
import { router } from "@inertiajs/vue3";
import DataTable from "./components/general/DataTable.vue";
import TableColumnHeader from "./components/general/TableColumnHeader.vue";
import TableField from "./components/general/TableField.vue";
import AddEditItemModal from "./components/modal/AddEditItemModal.vue";
import DeleteConfirmationModal from "./components/modal/DeleteConfirmationModal.vue";
import Loading from "./components/general/Loading.vue";
import { PlusIcon } from "@heroicons/vue/24/outline";
import { TrashIcon, KeyIcon } from "@heroicons/vue/24/solid";
import MainLayout from "../Layouts/MainLayout.vue";
import Notification from "./components/notifications/Notification.vue";

const loading = ref(false);
const showCreateModal = ref(false);
const confirmationModalTrigger = ref(false);
const createFormSubmiting = ref(false);
const confirmDeleteAction = ref(null);
const formErrors = ref(null);
const notificationType = ref(null);
const notificationMessages = ref(null);
const notificationShow = ref(null);
const createdToken = ref(null);
const copied = ref(false);

const props = defineProps({
    data: Array,
    routes: Object,
});

const createForm = ref({
    name: "",
    expires_days: null,
});

const handleCreateButtonClick = () => {
    createForm.value = { name: "", expires_days: null };
    createdToken.value = null;
    copied.value = false;
    formErrors.value = null;
    showCreateModal.value = true;
};

const handleCreateRequest = () => {
    createFormSubmiting.value = true;
    formErrors.value = null;

    axios.post(props.routes.store, createForm.value)
        .then((response) => {
            createFormSubmiting.value = false;
            createdToken.value = response.data.token;
            showNotification("success", response.data.messages);
            refreshData();
        })
        .catch((error) => {
            createFormSubmiting.value = false;
            handleFormErrorResponse(error);
        });
};

const handleSingleItemDeleteRequest = (url) => {
    confirmationModalTrigger.value = true;
    confirmDeleteAction.value = () => executeSingleDelete(url);
};

const executeSingleDelete = (url) => {
    router.delete(url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            if (page.props.flash.error) {
                showNotification("error", page.props.flash.error);
            }
            if (page.props.flash.message) {
                showNotification("success", page.props.flash.message);
            }
        },
        onFinish: () => {
            confirmationModalTrigger.value = false;
        },
    });
};

const refreshData = () => {
    loading.value = true;
    router.visit(props.routes.current_page, {
        preserveScroll: true,
        preserveState: true,
        only: ["data"],
        onSuccess: () => {
            loading.value = false;
        },
    });
};

const copyToClipboard = (text) => {
    navigator.clipboard.writeText(text).then(() => {
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    });
};

const handleModalClose = () => {
    showCreateModal.value = false;
    confirmationModalTrigger.value = false;
    createdToken.value = null;
};

const handleFormErrorResponse = (error) => {
    if (error.request?.status == 419) {
        showNotification("error", { request: ["Session expired. Reload the page"] });
    } else if (error.response) {
        showNotification("error", error.response.data.errors || { request: [error.message] });
        formErrors.value = error.response.data.errors;
    } else {
        showNotification("error", { request: [error.message] });
    }
};

const hideNotification = () => {
    notificationShow.value = false;
    notificationType.value = null;
    notificationMessages.value = null;
};

const showNotification = (type, messages = null) => {
    notificationType.value = type;
    notificationMessages.value = messages;
    notificationShow.value = true;
};
</script>
