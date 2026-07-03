<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>MedicalPlus</title>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/axios@1.x.x/dist/axios.min.js" defer></script>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        * { -webkit-tap-highlight-color: transparent; }
        html, body { overscroll-behavior: none; }
        .safe-top { padding-top: env(safe-area-inset-top, 0px); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom, 0px); }
        .page { display: none; }
        .page.active { display: block; }
        .loading-spinner { border: 3px solid #e5e7eb; border-top: 3px solid #14b8a6; border-radius: 50%; width: 24px; height: 24px; animation: spin 0.8s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .btn-primary { @apply bg-teal-500 text-white px-4 py-2 rounded-lg font-medium active:bg-teal-600 transition-colors; }
        .btn-secondary { @apply bg-gray-200 text-gray-700 px-4 py-2 rounded-lg font-medium active:bg-gray-300 transition-colors; }
        .btn-danger { @apply bg-red-500 text-white px-4 py-2 rounded-lg font-medium active:bg-red-600 transition-colors; }
        .card { @apply bg-white rounded-xl shadow-sm border border-gray-100 p-4; }
        .input { @apply w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-teal-500 focus:ring-1 focus:ring-teal-500 outline-none; }
        .label { @apply block text-sm font-medium text-gray-700 mb-1; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: white; border-top: 1px solid #e5e7eb; padding-bottom: env(safe-area-inset-bottom, 0px); z-index: 50; }
        .bottom-nav a { @apply flex flex-col items-center py-2 px-3 text-xs text-gray-400 active:text-teal-500 transition-colors; }
        .bottom-nav a.active { @apply text-teal-500; }
        .bottom-nav a svg { @apply mb-1; }
        .slide-in { animation: slideIn 0.25s ease-out; }
        @keyframes slideIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100; display: flex; align-items: flex-end; }
        .modal-content { background: white; border-radius: 16px 16px 0 0; width: 100%; max-height: 85vh; overflow-y: auto; padding: 20px; animation: slideUp 0.25s ease-out; }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
    </style>
</head>
<body class="h-full safe-top" x-data="app()" x-init="init()" :class="{ 'keyboard-visible': keyboardVisible }">
    <div class="min-h-screen bg-gray-50 pb-20">

        <!-- Loading Screen -->
        <div x-show="loading" class="flex items-center justify-center min-h-screen">
            <div class="text-center">
                <div class="loading-spinner mx-auto mb-4"></div>
                <p class="text-gray-500 text-sm">Loading...</p>
            </div>
        </div>

        <!-- Auth Screen -->
        <div x-show="!loading && !token" class="min-h-screen flex items-center justify-center p-6">
            <div class="w-full max-w-sm">
                <div class="text-center mb-8">
                    <div class="w-16 h-16 bg-teal-500 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900">MedicalPlus</h1>
                    <p class="text-gray-500 text-sm mt-1">Sign in to your account</p>
                </div>

                <div x-show="authError" class="bg-red-50 text-red-600 text-sm p-3 rounded-lg mb-4" x-text="authError"></div>

                <form @submit.prevent="login" class="space-y-4">
                    <div>
                        <label class="label">Email</label>
                        <input type="email" x-model="auth.email" class="input" placeholder="doctor@clinic.com" required>
                    </div>
                    <div>
                        <label class="label">Password</label>
                        <input type="password" x-model="auth.password" class="input" placeholder="••••••••" required>
                    </div>
                    <button type="submit" class="btn-primary w-full py-3" x-text="authLoading ? 'Signing in...' : 'Sign In'" :disabled="authLoading"></button>
                </form>
            </div>
        </div>

        <!-- Main App -->
        <template x-if="token">
            <div>
                <!-- Top Bar -->
                <div class="sticky top-0 z-40 bg-white border-b border-gray-200 px-4 py-3 safe-top flex items-center justify-between">
                    <div class="flex items-center">
                        <button x-show="currentPage !== 'dashboard'" @click="goBack" class="mr-3 p-1">
                            <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <h1 class="text-lg font-semibold text-gray-900" x-text="pageTitle"></h1>
                    </div>
                    <button @click="currentPage = 'profile'" class="w-8 h-8 bg-teal-100 rounded-full flex items-center justify-center">
                        <span class="text-sm font-bold text-teal-600" x-text="user ? user.name.charAt(0).toUpperCase() : 'M'"></span>
                    </button>
                </div>

                <!-- Pages -->
                <div class="p-4">
                    <!-- Dashboard -->
                    <div x-show="currentPage === 'dashboard'" class="page" :class="{ active: currentPage === 'dashboard' }">
                        <div class="space-y-4">
                            <div class="grid grid-cols-2 gap-3">
                                <div class="card text-center">
                                    <p class="text-2xl font-bold text-teal-600" x-text="stats.total_patients || 0"></p>
                                    <p class="text-xs text-gray-500 mt-1">Patients</p>
                                </div>
                                <div class="card text-center">
                                    <p class="text-2xl font-bold text-teal-600" x-text="stats.recent_files || 0"></p>
                                    <p class="text-xs text-gray-500 mt-1">Files</p>
                                </div>
                                <div class="card text-center" x-show="stats.total_doctors">
                                    <p class="text-2xl font-bold text-teal-600" x-text="stats.total_doctors"></p>
                                    <p class="text-xs text-gray-500 mt-1">Doctors</p>
                                </div>
                                <div class="card text-center" x-show="stats.active_doctors">
                                    <p class="text-2xl font-bold text-teal-600" x-text="stats.active_doctors"></p>
                                    <p class="text-xs text-gray-500 mt-1">Active</p>
                                </div>
                            </div>

                            <div class="flex items-center justify-between">
                                <h2 class="font-semibold text-gray-900">Recent Patients</h2>
                                <button @click="currentPage = 'patients'; getPatients()" class="text-sm text-teal-600 font-medium">View All</button>
                            </div>

                            <div class="space-y-2">
                                <template x-for="patient in recentPatients" :key="patient.uuid">
                                    <div @click="viewPatient(patient.uuid)" class="card flex items-center justify-between active:bg-gray-50 cursor-pointer">
                                        <div>
                                            <p class="font-medium text-gray-900" x-text="patient.name"></p>
                                            <p class="text-xs text-gray-500" x-text="patient.code || patient.phone || 'No code'"></p>
                                        </div>
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </div>
                                </template>
                                <p x-show="recentPatients.length === 0" class="text-gray-400 text-sm text-center py-8">No patients yet</p>
                            </div>
                        </div>
                    </div>

                    <!-- Patient List -->
                    <div x-show="currentPage === 'patients'" class="page" :class="{ active: currentPage === 'patients' }">
                        <div class="mb-4">
                            <input type="text" x-model="patientSearch" @input.debounce.300ms="getPatients" class="input" placeholder="Search patients...">
                        </div>
                        <div class="space-y-2">
                            <template x-for="patient in patients" :key="patient.uuid">
                                <div @click="viewPatient(patient.uuid)" class="card flex items-center justify-between active:bg-gray-50 cursor-pointer">
                                    <div>
                                        <p class="font-medium text-gray-900" x-text="patient.name"></p>
                                        <p class="text-xs text-gray-500" x-text="patient.code || patient.phone || 'No contact'"></p>
                                        <p x-show="patient.diagnosis" class="text-xs text-gray-400 mt-0.5" x-text="patient.diagnosis"></p>
                                    </div>
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </div>
                            </template>
                            <p x-show="patients.length === 0 && !loading" class="text-gray-400 text-sm text-center py-8">No patients found</p>
                            <div x-show="patientsLoading" class="flex justify-center py-4"><div class="loading-spinner"></div></div>
                            <button x-show="nextPageUrl" @click="loadMorePatients" class="btn-secondary w-full mt-2">Load More</button>
                        </div>
                    </div>

                    <!-- Patient Detail -->
                    <div x-show="currentPage === 'patient-detail'" class="page slide-in" :class="{ active: currentPage === 'patient-detail' }">
                        <template x-if="selectedPatient">
                            <div class="space-y-4">
                                <div class="card">
                                    <div class="flex items-center justify-between mb-3">
                                        <div>
                                            <h2 class="text-xl font-bold text-gray-900" x-text="selectedPatient.name"></h2>
                                            <p class="text-sm text-gray-500" x-text="selectedPatient.code || 'No code'"></p>
                                        </div>
                                        <div class="flex gap-2">
                                            <button @click="editPatient(selectedPatient)" class="p-2 text-teal-600 bg-teal-50 rounded-lg">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3 text-sm">
                                        <div><span class="text-gray-500">Phone:</span> <span class="text-gray-900" x-text="selectedPatient.phone || '-'"></span></div>
                                        <div><span class="text-gray-500">Email:</span> <span class="text-gray-900 truncate" x-text="selectedPatient.email || '-'"></span></div>
                                        <div><span class="text-gray-500">Diagnosis:</span> <span class="text-gray-900" x-text="selectedPatient.diagnosis || '-'"></span></div>
                                        <div class="col-span-2"><span class="text-gray-500">Address:</span> <span class="text-gray-900" x-text="selectedPatient.address || '-'"></span></div>
                                    </div>
                                </div>

                                <!-- Quick Actions -->
                                <div class="flex gap-2">
                                    <button @click="showAddVisit = true" class="btn-primary flex-1 text-sm py-2">+ Visit</button>
                                    <button @click="showAddNote = true" class="btn-secondary flex-1 text-sm py-2">+ Note</button>
                                    <button @click="showFileUpload = true" class="btn-secondary flex-1 text-sm py-2">+ File</button>
                                </div>

                                <!-- Tabs -->
                                <div class="flex border-b border-gray-200">
                                    <button @click="activeTab = 'visits'" class="flex-1 py-2 text-sm font-medium text-center" :class="activeTab === 'visits' ? 'text-teal-600 border-b-2 border-teal-500' : 'text-gray-500'">Visits</button>
                                    <button @click="activeTab = 'notes'" class="flex-1 py-2 text-sm font-medium text-center" :class="activeTab === 'notes' ? 'text-teal-600 border-b-2 border-teal-500' : 'text-gray-500'">Notes</button>
                                    <button @click="activeTab = 'files'" class="flex-1 py-2 text-sm font-medium text-center" :class="activeTab === 'files' ? 'text-teal-600 border-b-2 border-teal-500' : 'text-gray-500'">Files</button>
                                </div>

                                <!-- Visits Tab -->
                                <div x-show="activeTab === 'visits'" class="space-y-2">
                                    <template x-for="visit in selectedPatient.visits" :key="visit.id">
                                        <div class="card">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <p class="font-medium text-gray-900" x-text="visit.visit_type"></p>
                                                    <p class="text-xs text-gray-500" x-text="visit.visit_date || 'No date'"></p>
                                                </div>
                                                <span x-show="visit.cost" class="text-sm font-medium text-teal-600" x-text="'$' + visit.cost"></span>
                                            </div>
                                            <p x-show="visit.diagnosis" class="text-sm text-gray-600 mt-2" x-text="visit.diagnosis"></p>
                                            <p x-show="visit.prescription" class="text-sm text-gray-600 mt-1"><span class="font-medium">Rx:</span> <span x-text="visit.prescription"></span></p>
                                        </div>
                                    </template>
                                    <p x-show="(!selectedPatient.visits || selectedPatient.visits.length === 0)" class="text-gray-400 text-sm text-center py-4">No visits recorded</p>
                                </div>

                                <!-- Notes Tab -->
                                <div x-show="activeTab === 'notes'" class="space-y-2">
                                    <template x-for="note in notes" :key="note.id">
                                        <div class="card">
                                            <div class="flex justify-between items-start">
                                                <p class="text-xs text-gray-500" x-text="note.created_at ? new Date(note.created_at).toLocaleDateString() : ''"></p>
                                                <span class="text-xs bg-gray-100 px-2 py-0.5 rounded" x-text="note.category"></span>
                                            </div>
                                            <p class="text-sm text-gray-700 mt-1 whitespace-pre-wrap" x-text="note.content"></p>
                                            <p class="text-xs text-gray-400 mt-1" x-text="note.author ? 'by ' + note.author.name : ''"></p>
                                        </div>
                                    </template>
                                    <p x-show="notes.length === 0" class="text-gray-400 text-sm text-center py-4">No notes</p>
                                </div>

                                <!-- Files Tab -->
                                <div x-show="activeTab === 'files'" class="space-y-2">
                                    <template x-for="file in files" :key="file.uuid">
                                        <div class="card flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                                <template x-if="file.type === 'image'">
                                                    <img :src="file.thumbnail_url || file.url" class="w-10 h-10 rounded-lg object-cover">
                                                </template>
                                                <template x-if="file.type !== 'image'">
                                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                                </template>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-gray-900 truncate" x-text="file.title || file.file_name"></p>
                                                <p class="text-xs text-gray-500" x-text="file.type + ' · ' + (file.size ? Math.round(file.size/1024) + 'KB' : '')"></p>
                                            </div>
                                            <a :href="file.url" target="_blank" class="text-teal-600">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            </a>
                                        </div>
                                    </template>
                                    <p x-show="files.length === 0" class="text-gray-400 text-sm text-center py-4">No files</p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Add Patient -->
                    <div x-show="currentPage === 'add-patient'" class="page slide-in" :class="{ active: currentPage === 'add-patient' }">
                        <form @submit.prevent="savePatient" class="space-y-3">
                            <div>
                                <label class="label">Name *</label>
                                <input type="text" x-model="patientForm.name" class="input" required>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="label">Code</label>
                                    <input type="text" x-model="patientForm.code" class="input">
                                </div>
                                <div>
                                    <label class="label">Phone</label>
                                    <input type="text" x-model="patientForm.phone" class="input">
                                </div>
                            </div>
                            <div>
                                <label class="label">Email</label>
                                <input type="email" x-model="patientForm.email" class="input">
                            </div>
                            <div>
                                <label class="label">Address</label>
                                <textarea x-model="patientForm.address" class="input" rows="2"></textarea>
                            </div>
                            <div>
                                <label class="label">Diagnosis</label>
                                <textarea x-model="patientForm.diagnosis" class="input" rows="2"></textarea>
                            </div>
                            <div class="flex gap-3 pt-2">
                                <button type="submit" class="btn-primary flex-1 py-3" x-text="saving ? 'Saving...' : 'Save Patient'"></button>
                                <button type="button" @click="goBack" class="btn-secondary flex-1 py-3">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <!-- Profile -->
                    <div x-show="currentPage === 'profile'" class="page slide-in" :class="{ active: currentPage === 'profile' }">
                        <div class="space-y-4">
                            <div class="card text-center">
                                <div class="w-16 h-16 bg-teal-500 rounded-full flex items-center justify-center mx-auto mb-3">
                                    <span class="text-2xl font-bold text-white" x-text="user ? user.name.charAt(0).toUpperCase() : 'M'"></span>
                                </div>
                                <h2 class="text-lg font-bold text-gray-900" x-text="user?.name"></h2>
                                <p class="text-sm text-gray-500" x-text="user?.email"></p>
                                <p class="text-xs text-gray-400 mt-1" x-text="user?.role"></p>
                            </div>

                            <div class="space-y-2">
                                <h3 class="font-medium text-gray-700 text-sm">Account</h3>
                                <button @click="currentPage = 'edit-profile'" class="card w-full flex items-center justify-between text-left active:bg-gray-50">
                                    <span class="text-sm text-gray-700">Edit Profile</span>
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </button>
                                <button @click="currentPage = 'change-password'" class="card w-full flex items-center justify-between text-left active:bg-gray-50">
                                    <span class="text-sm text-gray-700">Change Password</span>
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </button>
                                <button @click="logout" class="card w-full flex items-center justify-between text-left active:bg-gray-50">
                                    <span class="text-sm text-red-600">Sign Out</span>
                                    <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Profile -->
                    <div x-show="currentPage === 'edit-profile'" class="page slide-in" :class="{ active: currentPage === 'edit-profile' }">
                        <form @submit.prevent="updateProfile" class="space-y-3">
                            <div>
                                <label class="label">Name</label>
                                <input type="text" x-model="profileForm.name" class="input">
                            </div>
                            <div>
                                <label class="label">Email</label>
                                <input type="email" x-model="profileForm.email" class="input">
                            </div>
                            <div>
                                <label class="label">Phone</label>
                                <input type="text" x-model="profileForm.phone" class="input">
                            </div>
                            <div>
                                <label class="label">Specialization</label>
                                <input type="text" x-model="profileForm.specialization" class="input">
                            </div>
                            <div class="flex gap-3 pt-2">
                                <button type="submit" class="btn-primary flex-1 py-3" x-text="saving ? 'Saving...' : 'Update Profile'"></button>
                                <button type="button" @click="goBack" class="btn-secondary flex-1 py-3">Back</button>
                            </div>
                            <p x-show="successMessage" class="text-green-600 text-sm text-center" x-text="successMessage"></p>
                        </form>
                    </div>

                    <!-- Change Password -->
                    <div x-show="currentPage === 'change-password'" class="page slide-in" :class="{ active: currentPage === 'change-password' }">
                        <form @submit.prevent="changePassword" class="space-y-3">
                            <div>
                                <label class="label">Current Password</label>
                                <input type="password" x-model="passwordForm.current_password" class="input" required>
                            </div>
                            <div>
                                <label class="label">New Password</label>
                                <input type="password" x-model="passwordForm.new_password" class="input" required minlength="8">
                            </div>
                            <div>
                                <label class="label">Confirm New Password</label>
                                <input type="password" x-model="passwordForm.new_password_confirmation" class="input" required>
                            </div>
                            <div class="flex gap-3 pt-2">
                                <button type="submit" class="btn-primary flex-1 py-3" x-text="saving ? 'Updating...' : 'Update Password'"></button>
                                <button type="button" @click="goBack" class="btn-secondary flex-1 py-3">Back</button>
                            </div>
                            <p x-show="successMessage" class="text-green-600 text-sm text-center" x-text="successMessage"></p>
                            <p x-show="authError" class="text-red-600 text-sm text-center" x-text="authError"></p>
                        </form>
                    </div>
                </div>

                <!-- Bottom Navigation -->
                <nav class="bottom-nav">
                    <div class="flex justify-around max-w-lg mx-auto">
                        <a href="#" @click.prevent="currentPage = 'dashboard'; getDashboard()" :class="{ active: currentPage === 'dashboard' }">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                            <span>Home</span>
                        </a>
                        <a href="#" @click.prevent="currentPage = 'patients'; getPatients()" :class="{ active: currentPage === 'patients' }">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            <span>Patients</span>
                        </a>
                        <a href="#" @click.prevent="showAddPatient = true" class="relative -mt-4">
                            <div class="w-12 h-12 bg-teal-500 rounded-full flex items-center justify-center shadow-lg">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            </div>
                            <span class="text-teal-600 mt-1">Add</span>
                        </a>
                        <a href="#" @click.prevent="currentPage = 'search'; searchQuery = ''; searchResults = []" :class="{ active: currentPage === 'search' }">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <span>Search</span>
                        </a>
                        <a href="#" @click.prevent="currentPage = 'profile'" :class="{ active: currentPage === 'profile' }">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            <span>Profile</span>
                        </a>
                    </div>
                </nav>

                <!-- Search Page -->
                <div x-show="currentPage === 'search'" class="page" :class="{ active: currentPage === 'search' }">
                    <div class="mb-4">
                        <input type="text" x-model="searchQuery" @input.debounce.300ms="doSearch" class="input" placeholder="Search patients, files...">
                    </div>
                    <div class="space-y-2">
                        <template x-for="result in searchResults" :key="result.type + result.id">
                            <div @click="result.type === 'patient' ? viewPatient(result.id) : ''" class="card flex items-center gap-3 active:bg-gray-50 cursor-pointer">
                                <div class="w-8 h-8 rounded-lg" :class="result.type === 'patient' ? 'bg-teal-100' : result.type === 'file' ? 'bg-blue-100' : 'bg-purple-100'"></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900" x-text="result.title"></p>
                                    <p class="text-xs text-gray-500" x-text="result.subtitle"></p>
                                </div>
                            </div>
                        </template>
                        <p x-show="searchResults.length === 0 && searchQuery.length >= 2" class="text-gray-400 text-sm text-center py-4">No results</p>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <!-- Modal: Add Visit -->
    <div x-show="showAddVisit" class="modal-overlay" @click.self="showAddVisit = false">
        <div class="modal-content">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Add Visit</h3>
            <form @submit.prevent="addVisit" class="space-y-3">
                <div>
                    <label class="label">Visit Type *</label>
                    <input type="text" x-model="visitForm.visit_type" class="input" required>
                </div>
                <div>
                    <label class="label">Reason</label>
                    <input type="text" x-model="visitForm.reason" class="input">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Date</label>
                        <input type="date" x-model="visitForm.visit_date" class="input">
                    </div>
                    <div>
                        <label class="label">Time</label>
                        <input type="time" x-model="visitForm.visit_time" class="input">
                    </div>
                </div>
                <div>
                    <label class="label">Diagnosis</label>
                    <textarea x-model="visitForm.diagnosis" class="input" rows="2"></textarea>
                </div>
                <div>
                    <label class="label">Prescription</label>
                    <textarea x-model="visitForm.prescription" class="input" rows="2"></textarea>
                </div>
                <div>
                    <label class="label">Cost</label>
                    <input type="number" x-model="visitForm.cost" class="input" step="0.01" min="0">
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="btn-primary flex-1 py-3" x-text="saving ? 'Saving...' : 'Save Visit'"></button>
                    <button type="button" @click="showAddVisit = false" class="btn-secondary flex-1 py-3">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Add Note -->
    <div x-show="showAddNote" class="modal-overlay" @click.self="showAddNote = false">
        <div class="modal-content">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Add Note</h3>
            <form @submit.prevent="addNote" class="space-y-3">
                <div>
                    <label class="label">Category</label>
                    <input type="text" x-model="noteForm.category" class="input" placeholder="general">
                </div>
                <div>
                    <label class="label">Content *</label>
                    <textarea x-model="noteForm.content" class="input" rows="4" required></textarea>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="btn-primary flex-1 py-3" x-text="saving ? 'Saving...' : 'Save Note'"></button>
                    <button type="button" @click="showAddNote = false" class="btn-secondary flex-1 py-3">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Upload File -->
    <div x-show="showFileUpload" class="modal-overlay" @click.self="showFileUpload = false">
        <div class="modal-content">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Upload File</h3>
            <form @submit.prevent="uploadFile" class="space-y-3">
                <div>
                    <label class="label">File *</label>
                    <input type="file" x-ref="fileInput" class="input" required>
                </div>
                <div>
                    <label class="label">Title</label>
                    <input type="text" x-model="fileForm.title" class="input">
                </div>
                <div>
                    <label class="label">Category</label>
                    <input type="text" x-model="fileForm.category" class="input">
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="btn-primary flex-1 py-3" x-text="saving ? 'Uploading...' : 'Upload'"></button>
                    <button type="button" @click="showFileUpload = false" class="btn-secondary flex-1 py-3">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = 'https://prof-hosam-fekry.online/api/v1/mobile';

        function app() {
            return {
                token: localStorage.getItem('api_token'),
                user: null,
                loading: true,
                authLoading: false,
                authError: null,
                auth: { email: '', password: '' },
                
                currentPage: 'dashboard',
                pageTitle: 'Dashboard',
                keyboardVisible: false,
                successMessage: null,
                saving: false,
                patientsLoading: false,
                
                stats: {},
                recentPatients: [],
                patients: [],
                selectedPatient: null,
                patientSearch: '',
                nextPageUrl: null,
                
                notes: [],
                files: [],
                activeTab: 'visits',
                
                patientForm: { name: '', code: '', phone: '', email: '', address: '', diagnosis: '' },
                visitForm: { visit_type: '', reason: '', visit_date: '', visit_time: '', diagnosis: '', prescription: '', cost: '' },
                noteForm: { category: 'general', content: '' },
                fileForm: { title: '', category: '' },
                profileForm: { name: '', email: '', phone: '', specialization: '' },
                passwordForm: { current_password: '', new_password: '', new_password_confirmation: '' },
                
                showAddVisit: false,
                showAddNote: false,
                showFileUpload: false,
                
                searchQuery: '',
                searchResults: [],

                get headers() {
                    return {
                        'Authorization': `Bearer ${this.token}`,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    };
                },

                init() {
                    if (this.token) {
                        this.getDashboard();
                    } else {
                        this.loading = false;
                    }

                    document.addEventListener('keyboard-show', () => this.keyboardVisible = true);
                    document.addEventListener('keyboard-hide', () => this.keyboardVisible = false);
                },

                async api(url, options = {}) {
                    try {
                        const res = await axios({ url: `${API_BASE}${url}`, ...options, headers: { ...this.headers, ...options.headers } });
                        return res.data;
                    } catch (e) {
                        if (e.response?.status === 401) {
                            this.token = null;
                            localStorage.removeItem('api_token');
                        }
                        throw e;
                    }
                },

                async login() {
                    this.authLoading = true;
                    this.authError = null;
                    try {
                        const res = await axios.post('https://prof-hosam-fekry.online/api/v1/login', this.auth);
                        this.token = res.data.token;
                        this.user = res.data.user;
                        localStorage.setItem('api_token', this.token);
                        this.getDashboard();
                    } catch (e) {
                        this.authError = e.response?.data?.message || e.response?.data?.errors?.email?.[0] || 'Login failed';
                    } finally {
                        this.authLoading = false;
                    }
                },

                async logout() {
                    try { await this.api('/logout', { method: 'POST' }); } catch(e) {}
                    this.token = null;
                    this.user = null;
                    localStorage.removeItem('api_token');
                    this.currentPage = 'dashboard';
                },

                async getDashboard() {
                    this.loading = true;
                    this.currentPage = 'dashboard';
                    this.pageTitle = 'Dashboard';
                    try {
                        const data = await this.api('/dashboard/stats');
                        this.stats = data.stats;
                        this.recentPatients = data.recent_patients;
                        this.user = data.user;
                    } catch(e) { console.error(e); }
                    this.loading = false;
                },

                async getPatients() {
                    this.patientsLoading = true;
                    this.currentPage = 'patients';
                    this.pageTitle = 'Patients';
                    const params = { per_page: 20 };
                    if (this.patientSearch) params.search = this.patientSearch;
                    try {
                        const res = await axios({ url: `${API_BASE}/patients`, params, headers: this.headers });
                        this.patients = res.data.data || res.data;
                        this.nextPageUrl = res.data.next_page_url || null;
                    } catch(e) { console.error(e); }
                    this.patientsLoading = false;
                },

                async loadMorePatients() {
                    if (!this.nextPageUrl) return;
                    try {
                        const res = await axios({ url: this.nextPageUrl, headers: this.headers });
                        this.patients = [...this.patients, ...(res.data.data || res.data)];
                        this.nextPageUrl = res.data.next_page_url || null;
                    } catch(e) { console.error(e); }
                },

                async viewPatient(uuid) {
                    this.currentPage = 'patient-detail';
                    this.pageTitle = 'Patient';
                    this.activeTab = 'visits';
                    try {
                        const data = await this.api(`/patients/${uuid}`);
                        this.selectedPatient = data.data || data;
                    } catch(e) { console.error(e); }
                    this.getNotes(uuid);
                    this.getFiles(uuid);
                },

                async getNotes(uuid) {
                    try {
                        const data = await this.api(`/patients/${this.selectedPatient.uuid}/notes`);
                        this.notes = data.data || data;
                    } catch(e) { this.notes = []; }
                },

                async getFiles(uuid) {
                    try {
                        const data = await this.api(`/patients/${this.selectedPatient.uuid}/files`);
                        this.files = data.data || data;
                    } catch(e) { this.files = []; }
                },

                showAddPatient() {
                    this.patientForm = { name: '', code: '', phone: '', email: '', address: '', diagnosis: '' };
                    this.currentPage = 'add-patient';
                    this.pageTitle = 'Add Patient';
                },

                editPatient(patient) {
                    this.patientForm = {
                        name: patient.name,
                        code: patient.code || '',
                        phone: patient.phone || '',
                        email: patient.email || '',
                        address: patient.address || '',
                        diagnosis: patient.diagnosis || '',
                    };
                    this.editingPatientUuid = patient.uuid;
                    this.currentPage = 'add-patient';
                    this.pageTitle = 'Edit Patient';
                },

                async savePatient() {
                    this.saving = true;
                    try {
                        const method = this.editingPatientUuid ? 'PUT' : 'POST';
                        const url = this.editingPatientUuid ? `/patients/${this.editingPatientUuid}` : '/patients';
                        await this.api(url, { method, data: this.patientForm });
                        this.editingPatientUuid = null;
                        this.goBack();
                        this.getPatients();
                    } catch(e) {
                        this.authError = e.response?.data?.message || 'Error saving patient';
                    }
                    this.saving = false;
                },

                async addVisit(e) {
                    this.saving = true;
                    try {
                        await this.api(`/patients/${this.selectedPatient.uuid}/visits`, { method: 'POST', data: this.visitForm });
                        this.showAddVisit = false;
                        this.visitForm = { visit_type: '', reason: '', visit_date: '', visit_time: '', diagnosis: '', prescription: '', cost: '' };
                        this.viewPatient(this.selectedPatient.uuid);
                    } catch(e) { this.authError = e.response?.data?.message || 'Error adding visit'; }
                    this.saving = false;
                },

                async addNote(e) {
                    this.saving = true;
                    try {
                        await this.api(`/patients/${this.selectedPatient.uuid}/notes`, { method: 'POST', data: this.noteForm });
                        this.showAddNote = false;
                        this.noteForm = { category: 'general', content: '' };
                        this.getNotes(this.selectedPatient.uuid);
                    } catch(e) { this.authError = e.response?.data?.message || 'Error adding note'; }
                    this.saving = false;
                },

                async uploadFile(e) {
                    this.saving = true;
                    const file = this.$refs.fileInput?.files?.[0];
                    if (!file) return;
                    const formData = new FormData();
                    formData.append('file', file);
                    if (this.fileForm.title) formData.append('title', this.fileForm.title);
                    if (this.fileForm.category) formData.append('category', this.fileForm.category);
                    try {
                        await axios.post(`${API_BASE}/patients/${this.selectedPatient.uuid}/files`, formData, {
                            headers: { ...this.headers, 'Content-Type': 'multipart/form-data' }
                        });
                        this.showFileUpload = false;
                        this.fileForm = { title: '', category: '' };
                        this.getFiles(this.selectedPatient.uuid);
                    } catch(e) { console.error(e); }
                    this.saving = false;
                },

                async doSearch() {
                    if (this.searchQuery.length < 2) { this.searchResults = []; return; }
                    try {
                        const data = await this.api(`/search?q=${encodeURIComponent(this.searchQuery)}`);
                        this.searchResults = data.results || [];
                    } catch(e) { this.searchResults = []; }
                },

                async updateProfile() {
                    this.saving = true;
                    this.successMessage = null;
                    try {
                        await this.api('/profile', { method: 'PUT', data: this.profileForm });
                        this.successMessage = 'Profile updated!';
                        if (this.user) Object.assign(this.user, this.profileForm);
                        setTimeout(() => this.goBack(), 1000);
                    } catch(e) { this.authError = e.response?.data?.message || 'Error updating profile'; }
                    this.saving = false;
                },

                async changePassword() {
                    this.saving = true;
                    this.successMessage = null;
                    this.authError = null;
                    try {
                        await axios.post('https://prof-hosam-fekry.online/api/v1/mobile/profile/password', this.passwordForm, { headers: this.headers });
                        this.successMessage = 'Password updated!';
                        this.passwordForm = { current_password: '', new_password: '', new_password_confirmation: '' };
                        setTimeout(() => this.goBack(), 1000);
                    } catch(e) { this.authError = e.response?.data?.message || 'Error updating password'; }
                    this.saving = false;
                },

                goBack() {
                    this.currentPage = 'dashboard';
                    this.pageTitle = 'Dashboard';
                    this.successMessage = null;
                    this.authError = null;
                }
            }
        }
    </script>
</body>
</html>
