<template>
    <MainLayout />

    <div class="m-3">
        <DataTable>
            <template #title>API Webhooks</template>

            <template #action>
                <button type="button" @click.prevent="handleCreateButtonClick()"
                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-2.5 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                    <PlusIcon aria-hidden="true" class="h-5 w-5" />
                    Add webhook
                </button>
            </template>

            <template #table-header>
                <TableColumnHeader header="URL"
                    class="whitespace-nowrap px-4 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Events"
                    class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Health"
                    class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Last Success"
                    class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header=""
                    class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
            </template>

            <template #table-body>
                <tr v-for="row in data" :key="row.webhook_uuid">
                    <TableField class="px-4 py-2 text-sm text-gray-500">
                        <template #action-buttons>
                            <div>
                                <div class="break-all">{{ row.url }}</div>
                                <div v-if="row.description" class="text-xs text-gray-400">{{ row.description }}</div>
                            </div>
                        </template>
                    </TableField>
                    <TableField class="whitespace-nowrap px-2 py-2 text-sm text-gray-500"
                        :text="(row.events || []).join(', ')" />
                    <TableField class="whitespace-nowrap px-2 py-2 text-sm">
                        <template #action-buttons>
                            <span v-if="row.consecutive_failures === 0"
                                class="inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                                healthy
                            </span>
                            <span v-else
                                class="inline-flex items-center rounded-md bg-rose-50 px-2 py-1 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-600/20">
                                {{ row.consecutive_failures }} consecutive failures
                            </span>
                        </template>
                    </TableField>
                    <TableField class="whitespace-nowrap px-2 py-2 text-sm text-gray-500"
                        :text="row.last_success_at_formatted" />
                    <TableField class="whitespace-nowrap px-2 py-1 text-sm text-gray-500">
                        <template #action-buttons>
                            <div class="flex items-center justify-end whitespace-nowrap gap-1">
                                <button type="button" @click="handleShowDeliveries(row)"
                                    class="rounded-md px-2 py-1 text-xs font-semibold text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                                    Deliveries
                                </button>
                                <button type="button" @click="handleRotateRequest(row)"
                                    class="rounded-md px-2 py-1 text-xs font-semibold text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                                    Rotate secret
                                </button>
                                <TrashIcon @click="handleSingleItemDeleteRequest(row.destroy_route)"
                                    class="h-9 w-9 transition duration-500 ease-in-out py-2 rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-600 active:bg-gray-300 active:duration-150 cursor-pointer" />
                            </div>
                        </template>
                    </TableField>
                </tr>
            </template>

            <template #empty>
                <div v-if="data.length === 0" class="text-center my-5">
                    <BoltIcon class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-semibold text-gray-900">No webhooks</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Add a webhook to receive cdr.finalized events for this account.
                    </p>
                </div>
            </template>

            <template #loading>
                <Loading :show="loading" />
            </template>
        </DataTable>
    </div>

    <AddEditItemModal :show="showCreateModal" :header="'Add webhook'" :loading="false" @close="handleModalClose">
        <template #modal-body>
            <div class="p-4">
                <div v-if="!createdSecret">
                    <div class="mb-4">
                        <label for="webhook-url" class="block text-sm font-medium text-gray-700">Endpoint URL</label>
                        <input id="webhook-url" v-model="createForm.url" type="url"
                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm"
                            placeholder="https://example.com/webhooks/voxra/cdr" />
                        <p class="mt-1 text-xs text-gray-500">HTTPS only. Receives signed cdr.finalized events.</p>
                        <p v-if="formErrors?.url" class="mt-1 text-sm text-rose-600">{{ formErrors.url[0] }}</p>
                    </div>
                    <div class="mb-4">
                        <label for="webhook-description" class="block text-sm font-medium text-gray-700">
                            Description (optional)
                        </label>
                        <input id="webhook-description" v-model="createForm.description" type="text"
                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm"
                            placeholder="e.g. CRM call sync" />
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="handleModalClose"
                            class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="button" :disabled="createFormSubmiting" @click="handleCreateRequest"
                            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50">
                            {{ createFormSubmiting ? 'Saving…' : 'Add webhook' }}
                        </button>
                    </div>
                </div>

                <div v-else>
                    <div class="rounded-md bg-green-50 p-4 mb-4">
                        <p class="text-sm font-medium text-green-800 mb-2">
                            Signing secret — copy it now, it will not be shown again.
                        </p>
                        <div class="flex items-center gap-2">
                            <code class="block w-full break-all rounded bg-white p-2 text-xs text-gray-900 ring-1 ring-inset ring-green-300">
                                {{ createdSecret }}
                            </code>
                            <button type="button" @click="copyToClipboard(createdSecret)"
                                class="rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                                {{ copied ? 'Copied' : 'Copy' }}
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-green-700">
                            Verify requests with HMAC-SHA256 over "&lt;timestamp&gt;.&lt;body&gt;" —
                            same scheme as the recording webhooks.
                        </p>
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

    <AddEditItemModal :show="showDeliveriesModal" :header="'Recent deliveries'" :loading="deliveriesLoading"
        @close="showDeliveriesModal = false">
        <template #modal-body>
            <div class="p-4">
                <div v-if="deliveries.length === 0 && !deliveriesLoading" class="text-sm text-gray-500 text-center my-4">
                    No deliveries yet.
                </div>
                <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-gray-900">
                            <th class="py-2 pr-2">Created</th>
                            <th class="py-2 pr-2">Status</th>
                            <th class="py-2 pr-2">Attempts</th>
                            <th class="py-2">Last error</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-gray-500">
                        <tr v-for="d in deliveries" :key="d.delivery_uuid">
                            <td class="py-2 pr-2 whitespace-nowrap">{{ d.created_at_formatted }}</td>
                            <td class="py-2 pr-2">
                                <span :class="deliveryStatusClass(d.status)"
                                    class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset">
                                    {{ d.status }}
                                </span>
                            </td>
                            <td class="py-2 pr-2">{{ d.attempts }}</td>
                            <td class="py-2 text-xs break-all">{{ d.last_error || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
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
import { TrashIcon, BoltIcon } from "@heroicons/vue/24/solid";
import MainLayout from "../Layouts/MainLayout.vue";
import Notification from "./components/notifications/Notification.vue";

const loading = ref(false);
const showCreateModal = ref(false);
const showDeliveriesModal = ref(false);
const deliveriesLoading = ref(false);
const deliveries = ref([]);
const confirmationModalTrigger = ref(false);
const createFormSubmiting = ref(false);
const confirmDeleteAction = ref(null);
const formErrors = ref(null);
const notificationType = ref(null);
const notificationMessages = ref(null);
const notificationShow = ref(null);
const createdSecret = ref(null);
const copied = ref(false);

const props = defineProps({
    data: Array,
    routes: Object,
});

const createForm = ref({
    url: "",
    description: "",
});

const handleCreateButtonClick = () => {
    createForm.value = { url: "", description: "" };
    createdSecret.value = null;
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
            createdSecret.value = response.data.secret;
            showNotification("success", response.data.messages);
            refreshData();
        })
        .catch((error) => {
            createFormSubmiting.value = false;
            handleFormErrorResponse(error);
        });
};

const handleRotateRequest = (row) => {
    axios.post(row.rotate_route)
        .then((response) => {
            createdSecret.value = response.data.secret;
            copied.value = false;
            showCreateModal.value = true;
            showNotification("success", response.data.messages);
        })
        .catch((error) => {
            handleFormErrorResponse(error);
        });
};

const handleShowDeliveries = (row) => {
    deliveries.value = [];
    deliveriesLoading.value = true;
    showDeliveriesModal.value = true;

    axios.get(row.deliveries_route)
        .then((response) => {
            deliveries.value = response.data.data;
            deliveriesLoading.value = false;
        })
        .catch((error) => {
            deliveriesLoading.value = false;
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

const deliveryStatusClass = (status) => {
    switch (status) {
        case "sent":
            return "bg-green-50 text-green-700 ring-green-600/20";
        case "failed":
            return "bg-rose-50 text-rose-700 ring-rose-600/20";
        case "pending":
            return "bg-yellow-50 text-yellow-700 ring-yellow-600/20";
        default:
            return "bg-gray-50 text-gray-700 ring-gray-600/20";
    }
};

const handleModalClose = () => {
    showCreateModal.value = false;
    confirmationModalTrigger.value = false;
    createdSecret.value = null;
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
