// script.js
const translations = {
    ar: {
        title: "نظام أرشفة المرضى",
        patients: "المرضى",
        dashboard: "لوحة القيادة",
        addPatient: "إضافة مريض",
        name: "الاسم",
        phone: "رقم الهاتف",
        address: "العنوان",
        viewFiles: "عرض الملفات",
        save: "حفظ البيانات",
        cancel: "إلغاء",
        uploadFile: "رفع ملف",
        downloadPDF: "تنزيل التقرير الكامل (PDF)",
        downloadZIP: "تنزيل كل الملفات (ZIP)",
        fileTitle: "عنوان الملف",
        fileDesc: "التشخيص / الوصف",
        fileDate: "تاريخ التقرير / الملف",
        selectFile: "انقر لاختيار ملف أو التصوير بالكاميرا",
        orDragDrop: "يدعم (صور، فيديو، PDF، Word)",
        back: "العودة للرئيسية",
        viewFileTitle: "عرض الملف",
        viewBtn: "عرض المرفق",
        searchFiles: "البحث في الملفات بالاسم...",
        allFiles: "جميع الملفات",
        imagesOnly: "صور (روشتات/أشعة)",
        pdfsOnly: "تقارير (PDF)",
        videosOnly: "فيديو",
        edit: "تعديل",
        delete: "حذف",
        deleteConfirm: "هل أنت متأكد من حذف هذا المريض وكل ملفاته؟",
        totalPatients: "إجمالي المرضى",
        totalFiles: "إجمالي الملفات",
        recentActivity: "أحدث النشاطات",
        patientDetails: "تفاصيل المريض",
        files: "الملفات الخاصة بالمريض",
        uploadNewFile: "إضافة ملف جديد",
        noFiles: "لا توجد ملفات لهذا المريض بعد.",
        successAdded: "تم الإضافة بنجاح",
        downloadingMsg: "جاري تحضير التنزيل...",
        searchPlaceholder: "البحث بالاسم، رقم الهاتف، أو العنوان...",
        noSearchResults: "عفواً، لا توجد نتائج مطابقة لبحثك.",
        uploadedFileActivity: "قام برفع ملف جديد",
        adminPanel: "لوحة الإدارة",
        logout: "تسجيل الخروج",
        adminDashboard: "الإحصائيات",
        adminDoctors: "الأطباء",
        adminCategories: "أقسام الملفات",
        totalDoctors: "إجمالي الأطباء",
        totalProfits: "إجمالي الأرباح",
        addDoctor: "إضافة طبيب جديد",
        email: "البريد الإلكتروني",
        password: "كلمة المرور",
        specialization: "التخصص",
        saveDoctor: "حفظ الطبيب",
        registeredDoctors: "الأطباء المسجلين",
        noDoctors: "لا يوجد أطباء",
        addCategory: "إضافة قسم جديد",
        categoryName: "اسم القسم",
        categoryIcon: "أيقونة القسم (صورة)",
        categoryColor: "لون مميز (اختياري)",
        saveCategory: "إضافة القسم",
        currentCategories: "الأقسام الحالية",
        noCategories: "لا توجد أقسام مضافة بعد",
        editDoctorTitle: "تعديل بيانات الطبيب",
        editCategoryTitle: "تعديل القسم",
        passwordHint: "(اتركها فارغة لعدم التغيير)",
        iconHint: "(اختياري للتغيير)",
        visitsLogTitle: "سجل الزيارات",
        visitsLogSub: "تاريخ جميع زيارات المريض للعيادة",
        addNewVisit: "تسجيل زيارة جديدة",
        visitDate: "التاريخ",
        visitType: "نوع الزيارة",
        visitReason: "السبب",
        visitDetails: "تفاصيل الجلسة",
        visitDiagnosis: "التشخيص",
        visitCost: "التكلفة",
        nextVisit: "الزيارة القادمة",
        actions: "إجراءات",
        patientDiagnosis: "الشكوى / التشخيص (اختياري)",
        deleteConfirmTitle: "تأكيد الحذف",
        deleteConfirmText: "هل أنت متأكد من حذف هذا المريض؟ لا يمكن التراجع عن هذا الإجراء."
    },
    en: {
        title: "Patient Archive",
        patients: "Patients",
        dashboard: "Dashboard",
        addPatient: "Add Patient",
        name: "Full Name",
        phone: "Phone Number",
        address: "Address",
        viewFiles: "View Files",
        save: "Save Details",
        cancel: "Cancel",
        uploadFile: "Upload File",
        downloadPDF: "Download Report (PDF)",
        downloadZIP: "Download All (ZIP)",
        fileTitle: "File Title",
        fileDesc: "Diagnosis / Description",
        fileDate: "Report / File Date",
        selectFile: "Click to select a file or use Camera",
        orDragDrop: "Supports (Images, Video, PDF, Word)",
        back: "Back to Home",
        viewFileTitle: "View File",
        viewBtn: "View Attachment",
        searchFiles: "Search files...",
        allFiles: "All Files",
        imagesOnly: "Images (X-Ray/Prescription)",
        pdfsOnly: "Reports (PDF)",
        videosOnly: "Videos",
        edit: "Edit",
        delete: "Delete",
        deleteConfirm: "Are you sure you want to delete this patient and all their files?",
        totalPatients: "Total Patients",
        totalFiles: "Total Files",
        recentActivity: "Recent Activity",
        patientDetails: "Patient Details",
        files: "Patient Files",
        uploadNewFile: "Add New File",
        noFiles: "No files found for this patient yet.",
        successAdded: "Added successfully",
        downloadingMsg: "Preparing download...",
        searchPlaceholder: "Search by name, phone, or address...",
        noSearchResults: "Sorry, no results match your search.",
        uploadedFileActivity: "Uploaded a new file",
        storageUsed: "Storage Used",
        recentPatients: "Recently Added Patients",
        patientsArchive: "Patients Archive",
        prevPage: "Previous",
        nextPage: "Next",
        page: "Page",
        of: "of",
        adminPanel: "Admin Panel",
        logout: "Logout",
        adminDashboard: "Dashboard",
        adminDoctors: "Doctors",
        adminCategories: "Categories",
        totalDoctors: "Total Doctors",
        totalProfits: "Total Profits",
        addDoctor: "Add Doctor",
        email: "Email Address",
        password: "Password",
        specialization: "Specialization",
        saveDoctor: "Save Doctor",
        registeredDoctors: "Registered Doctors",
        noDoctors: "No doctors found",
        addCategory: "Add Category",
        categoryName: "Category Name",
        categoryIcon: "Category Icon (Image)",
        categoryColor: "Distinctive Color (Optional)",
        saveCategory: "Save Category",
        currentCategories: "Current Categories",
        noCategories: "No categories added yet",
        editDoctorTitle: "Edit Doctor Info",
        editCategoryTitle: "Edit Category",
        passwordHint: "(Leave empty to keep unchanged)",
        iconHint: "(Optional to change)",
        visitsLogTitle: "Visits Log",
        visitsLogSub: "History of all patient clinic visits",
        addNewVisit: "Add New Visit",
        visitDate: "Date",
        visitType: "Visit Type",
        visitReason: "Reason",
        visitDetails: "Session Details",
        visitDiagnosis: "Diagnosis",
        visitCost: "Cost",
        nextVisit: "Next Visit",
        actions: "Actions",
        patientDiagnosis: "Complaint / Diagnosis (Optional)",
        deleteConfirmTitle: "Confirm Deletion",
        deleteConfirmText: "Are you sure you want to delete this patient? This action cannot be undone."
    }
};

let currentLang = localStorage.getItem('lang') || 'ar';

let patientsData = [];
let filesData = [];
let dashboardStats = { totalPatients: 0, totalFiles: 0, recentPatients: 0 };
const API_BASE = window.MOBILE_API_BASE || '/api/v1';

function apiHeaders(extra = {}) {
    const token = localStorage.getItem('api_token');
    return token ? { ...extra, 'Authorization': `Bearer ${token}` } : extra;
}

function apiUrl(path) {
    return `${API_BASE}${path.startsWith('/') ? path : `/${path}`}`;
}

async function fetchPatients() {
    try {
        const response = await fetch(apiUrl('/patients'), { headers: apiHeaders({ 'Accept': 'application/json' }) });
        const data = await response.json();
        patientsData = data.data || data.patients || [];
        dashboardStats = data.stats;
        filteredPatients = [...patientsData];
        renderPatients();
        updateDashboardStats();
    } catch (error) {
        console.error("Error fetching patients:", error);
    }
}

let currentTheme = localStorage.getItem('theme') || 'light';
document.documentElement.setAttribute('data-theme', currentTheme);

function toggleTheme() {
    currentTheme = currentTheme === 'light' ? 'dark' : 'light';
    localStorage.setItem('theme', currentTheme);
    document.documentElement.setAttribute('data-theme', currentTheme);
    const icons = document.querySelectorAll('.theme-icon');
    icons.forEach(i => {
        i.className = currentTheme === 'light' ? 'fa-solid fa-moon theme-icon' : 'fa-solid fa-sun theme-icon';
    });
}

function initApp() {
    setLanguage(currentLang);
    toggleTheme(); toggleTheme(); // Quick way to init icons
}

function setLanguage(lang) {
    currentLang = lang;
    localStorage.setItem('lang', lang);
    document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
    document.documentElement.setAttribute('lang', lang);
    
    document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.getAttribute('data-i18n');
        if (translations[lang][key]) {
            if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
                el.placeholder = translations[lang][key];
            } else {
                el.textContent = translations[lang][key];
            }
        }
    });
    
    const langBtnText = document.getElementById('langText');
    if (langBtnText) {
        langBtnText.textContent = lang === 'ar' ? 'EN' : 'AR';
    }
}

let currentPage = 1;
const itemsPerPage = 8;
let filteredPatients = [];

function toggleLanguage() {
    setLanguage(currentLang === 'ar' ? 'en' : 'ar');
    if (window.location.pathname.includes('/patient/')) {
        renderPatientDetails();
    } else {
        const query = document.getElementById('searchInput') ? document.getElementById('searchInput').value.toLowerCase().trim() : '';
        if(query) {
            handleSearch({target: {value: query}});
        } else {
            renderPatients();
        }
        updateDashboardStats();
    }
}

function getInitials(name) {
    return name.trim().split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase() || name.substring(0, 2);
}

// Smart Search
function handleSearch(event) {
    const query = event.target.value.toLowerCase().trim();
    const clearBtn = document.getElementById('clearSearch');
    
    if (query.length > 0) {
        clearBtn.style.display = 'flex';
    } else {
        clearBtn.style.display = 'none';
    }
    
    filteredPatients = patientsData.filter(p => 
        p.name.toLowerCase().includes(query) || 
        p.phone.includes(query) || 
        p.address.toLowerCase().includes(query)
    );
    
    currentPage = 1;
    renderPatients(filteredPatients);
}

function clearSearch() {
    const searchInput = document.getElementById('searchInput');
    searchInput.value = '';
    document.getElementById('clearSearch').style.display = 'none';
    searchInput.focus();
    filteredPatients = [...patientsData];
    currentPage = 1;
    renderPatients();
}

function renderPatients(dataToRender = null) {
    const list = document.getElementById('patientsList');
    if (!list) return;
    
    if (dataToRender === null) {
        if (filteredPatients.length === 0 && document.getElementById('searchInput') && document.getElementById('searchInput').value === '') {
            filteredPatients = [...patientsData];
        }
        dataToRender = filteredPatients;
    }
    
    list.innerHTML = '';
    
    if (dataToRender.length === 0) {
        list.innerHTML = `
            <tr>
                <td colspan="4" style="text-align: center; padding: 4rem;">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 3.5rem; margin-bottom: 1.5rem; color: #CBD5E1;"></i>
                    <p style="font-size: 1.15rem; color: var(--text-muted); font-weight: 600;">${translations[currentLang].noSearchResults}</p>
                </td>
            </tr>
        `;
        return;
    }
    
    const startIdx = (currentPage - 1) * itemsPerPage;
    const paginatedData = dataToRender.slice(startIdx, startIdx + itemsPerPage);
    
    paginatedData.forEach(patient => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div class="avatar" style="width: 40px; height: 40px; font-size: 1rem; flex-shrink: 0;">${getInitials(patient.name)}</div>
                    <span style="font-weight: 700; color: var(--text-main); font-size: 1.05rem;">${patient.name}</span>
                </div>
            </td>
            <td style="font-family: Inter, sans-serif; font-weight: 600; color: var(--text-muted); letter-spacing: 0.5px;">${patient.phone}</td>
            <td style="color: var(--text-muted); font-weight: 500;">
                <i class="fa-solid fa-location-dot" style="margin: 0 0.5rem; color: var(--primary);"></i>${patient.address}
            </td>
            <td>
                <div class="table-actions">
                    <button class="btn btn-outline" onclick="openEditModal(${patient.id})" style="width: 40px; height: 40px; padding: 0; justify-content: center; color: #F59E0B; border-color: transparent;" title="${translations[currentLang].edit}">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    <button class="btn btn-outline" onclick="openDeleteModal(${patient.id})" style="width: 40px; height: 40px; padding: 0; justify-content: center; color: #EF4444; border-color: transparent;" title="${translations[currentLang].delete}">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                    <a href="/patient/${patient.id}" class="btn btn-primary" style="height: 40px; padding: 0 1.2rem; border-radius: 50px;" title="${translations[currentLang].viewFiles}">
                        <span data-i18n="viewFiles" style="font-weight: 700; font-size: 0.9rem;">${translations[currentLang].viewFiles}</span>
                    </a>
                </div>
            </td>
        `;
        list.appendChild(row);
    });

    updatePagination();
}

function updatePagination() {
    const totalPages = Math.ceil(filteredPatients.length / itemsPerPage) || 1;
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');
    
    if(document.getElementById('currentPage')) document.getElementById('currentPage').textContent = currentPage;
    if(document.getElementById('totalPages')) document.getElementById('totalPages').textContent = totalPages;
    
    if (prevBtn) prevBtn.disabled = currentPage === 1;
    if (nextBtn) nextBtn.disabled = currentPage === totalPages;
}

function changePage(direction) {
    const totalPages = Math.ceil(filteredPatients.length / itemsPerPage) || 1;
    currentPage += direction;
    
    if (currentPage < 1) currentPage = 1;
    if (currentPage > totalPages) currentPage = totalPages;
    
    renderPatients(filteredPatients);
}

function updateDashboardStats() {
    const pCount = document.getElementById('totalPatientsCount');
    const fCount = document.getElementById('totalFilesCount');
    const storageUsed = document.getElementById('storageUsed');
    const recentCount = document.getElementById('recentPatientsCount');
    const monthlyIncome = document.getElementById('monthlyIncomeCount');
    
    if(pCount) pCount.textContent = dashboardStats.totalPatients || 0;
    if(fCount) fCount.textContent = dashboardStats.totalFiles || 0;
    
    if(storageUsed) {
        const mockStorageMb = ((dashboardStats.totalFiles || 0) * 2.4).toFixed(1);
        storageUsed.textContent = mockStorageMb + " MB";
    }
    
    if(recentCount) {
        recentCount.textContent = dashboardStats.recentPatients || 0;
    }

    if(monthlyIncome) {
        const val = dashboardStats.monthlyIncome || 0;
        monthlyIncome.textContent = Number(val).toLocaleString() + " EGP";
    }
}

// removed renderActivityLog as it's not needed in this simplified version

function openAddPatientModal() {
    document.getElementById('addPatientModal').classList.add('active');
    document.getElementById('patientName').focus();
}

function closeAddPatientModal() {
    document.getElementById('addPatientModal').classList.remove('active');
    document.getElementById('addPatientForm').reset();
}

async function handleAddPatient(e) {
    e.preventDefault();
    const name = document.getElementById('patientName').value;
    const phone = document.getElementById('patientPhone').value;
    const address = document.getElementById('patientAddress').value;
    const diagnosis = document.getElementById('patientDiagnosis') ? document.getElementById('patientDiagnosis').value : '';
    
    try {
        const res = await fetch(apiUrl('/patients'), {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json', 'Accept': 'application/json' }),
            body: JSON.stringify({ name, phone, address, diagnosis })
        });
        if(res.ok) {
            closeAddPatientModal();
            fetchPatients();
        }
    } catch(err) { console.error(err); }
}

// Show Page Functions
function getPatientIdFromUrl() {
    const parts = window.location.pathname.split('/');
    return parseInt(parts[parts.length - 1]) || 0;
}

async function fetchPatientDetails() {
    const patientId = getPatientIdFromUrl();
    if (!patientId) return;
    
    try {
        const res = await fetch(apiUrl(`/patients/${patientId}`), { headers: apiHeaders({ 'Accept': 'application/json' }) });
        if(!res.ok) {
            window.location.href = '/';
            return;
        }
        const response = await res.json();
        const patient = response.data || response;
        
        const profileSection = document.getElementById('patientProfile');
        if (profileSection) {
            profileSection.innerHTML = `
                <div class="profile-info">
                    <div class="profile-avatar">${getInitials(patient.name)}</div>
                    <div class="profile-details">
                        <h1>${patient.name}</h1>
                        <p><i class="fa-solid fa-phone"></i> ${patient.phone}</p>
                        <p><i class="fa-solid fa-location-dot"></i> ${patient.address}</p>
                        ${patient.diagnosis ? `<p style="margin-top:0.25rem; font-weight:700;"><i class="fa-solid fa-stethoscope" style="color:#F59E0B;"></i> ${patient.diagnosis}</p>` : ''}
                    </div>
                </div>
                <div class="actions">
                    <button class="btn btn-outline" onclick="mockDownload('pdf')" style="color: #DC2626; border-color: #FEE2E2; background: #FEF2F2;">
                        <i class="fa-solid fa-file-pdf"></i>
                        <span data-i18n="downloadPDF">${translations[currentLang].downloadPDF}</span>
                    </button>
                    <button class="btn btn-primary" onclick="mockDownload('zip')">
                        <i class="fa-solid fa-file-zipper"></i>
                        <span data-i18n="downloadZIP">${translations[currentLang].downloadZIP}</span>
                    </button>
                </div>
            `;
        }
        filesData = patient.files || [];
        renderPatientFiles(patientId);
    } catch(err) {
        console.error(err);
        window.location.href = '/';
    }
}

function getFileIcon(type) {
    if (type.includes('image')) return 'fa-image text-primary';
    if (type.includes('pdf')) return 'fa-file-pdf text-danger';
    if (type.includes('video')) return 'fa-file-video text-secondary';
    return 'fa-file-lines text-muted';
}

let currentFileFilter = { query: '', type: 'all', from: '', to: '', category: 'all' };

function switchFileCategory(cat) {
    currentFileFilter.category = cat;
    
    // Update active class on sidebar
    document.querySelectorAll('.file-categories-sidebar .cat-btn').forEach(btn => {
        btn.classList.remove('active');
        if ((cat === 'all' && btn.textContent.includes('الكل') && !btn.textContent.includes('أدوية')) || btn.getAttribute('onclick').includes(`'${cat}'`)) {
            btn.classList.add('active');
        }
    });

    // Update title
    const titleEl = document.getElementById('currentCategoryTitle');
    if (titleEl) {
        if (cat === 'all') titleEl.textContent = 'جميع الملفات';
        else {
            const activeBtn = document.querySelector('.file-categories-sidebar .cat-btn.active');
            titleEl.textContent = activeBtn ? activeBtn.textContent.trim() : cat;
        }
    }
    
    // Pre-select category in upload modal if not 'all'
    const catSelect = document.getElementById('fileCategory');
    if (catSelect && cat !== 'all') {
        catSelect.value = cat;
    }

    const patientId = getPatientIdFromUrl();
    renderPatientFiles(patientId);
}

function handleFileFilter() {
    const query = document.getElementById('fileSearchInput') ? document.getElementById('fileSearchInput').value.toLowerCase().trim() : '';
    const type = document.getElementById('fileTypeFilter') ? document.getElementById('fileTypeFilter').value : 'all';
    const from = document.getElementById('fromDateFilter') ? document.getElementById('fromDateFilter').value : '';
    const to = document.getElementById('toDateFilter') ? document.getElementById('toDateFilter').value : '';
    
    currentFileFilter.query = query;
    currentFileFilter.type = type;
    currentFileFilter.from = from;
    currentFileFilter.to = to;
    
    const patientId = getPatientIdFromUrl();
    renderPatientFiles(patientId);
}

function renderPatientFiles(patientId) {
    const list = document.getElementById('filesList');
    if (!list) return;
    
    // Support both server-style (patient_id) and local style (patientId) keys
    let patientFiles = filesData.filter(f => (f.patient_id === patientId || f.patientId === patientId)).sort((a,b) => b.id - a.id);
    
    if (currentFileFilter.category !== 'all') {
        patientFiles = patientFiles.filter(f => f.category === currentFileFilter.category);
    }
    
    if (currentFileFilter.type !== 'all') {
        patientFiles = patientFiles.filter(f => f.type.includes(currentFileFilter.type));
    }
    
    if (currentFileFilter.query) {
        patientFiles = patientFiles.filter(f => f.title.toLowerCase().includes(currentFileFilter.query) || (f.desc && f.desc.toLowerCase().includes(currentFileFilter.query)));
    }
    
    if (currentFileFilter.from) {
        patientFiles = patientFiles.filter(f => f.date >= currentFileFilter.from);
    }
    
    if (currentFileFilter.to) {
        patientFiles = patientFiles.filter(f => f.date <= currentFileFilter.to);
    }
    
    list.innerHTML = '';
    
    if (patientFiles.length === 0) {
        list.innerHTML = `<div style="grid-column: 1/-1; text-align: center; padding: 4rem; background: var(--surface); border-radius: var(--radius-lg); border: 1px dashed var(--border);">
            <i class="fa-solid fa-folder-open" style="font-size: 4rem; margin-bottom: 1rem; color: #CBD5E1;"></i>
            <p style="font-size: 1.1rem; color: var(--text-muted); font-weight: 600;">${translations[currentLang].noFiles}</p>
        </div>`;
        return;
    }
    
    patientFiles.forEach(file => {
        const card = document.createElement('div');
        card.className = 'card file-card';
        const displayName = file.file_name || file.fileName || 'file';
        const ext = displayName.split('.').pop().toUpperCase();
        const fileSrc = file.file_path || file.data || null;
        
        card.innerHTML = `
            <i class="fa-solid ${getFileIcon(file.type)} file-icon"></i>
            <h4>${file.title}</h4>
            ${file.category ? `<span class="file-badge" style="margin-bottom:0.5rem;display:inline-block;">${file.category}</span>` : ''}
            <p>${file.desc || ''}</p>
            <div class="d-flex justify-between align-center" style="margin-top: auto; padding-bottom: 1rem; border-bottom: 1px solid var(--border);">
                <small style="color: var(--text-muted); font-weight: 600;">
                    <i class="fa-regular fa-calendar"></i> ${file.date}
                </small>
                <span style="font-size: 0.75rem; background: #F1F5F9; padding: 0.3rem 0.6rem; border-radius: var(--radius-sm); color: var(--text-main); font-weight: 700;">${ext}</span>
            </div>
            <div class="card-actions" style="margin-top: 0; padding-top: 1.25rem; display: flex; gap: 0.5rem;">
                <button class="btn btn-outline" onclick="deleteFile(${file.id}, ${patientId})" style="width: 45px; height: 45px; justify-content: center; padding: 0; flex-shrink: 0; color: #EF4444; border-color: #FEE2E2;" title="حذف الملف">
                    <i class="fa-solid fa-trash"></i>
                </button>
                ${fileSrc ? `<a href="${fileSrc}" download="${displayName}" class="btn btn-outline" style="width: 45px; height: 45px; justify-content: center; padding: 0; flex-shrink: 0;" title="تحميل">
                    <i class="fa-solid fa-download"></i>
                </a>` : ''}
                <button class="btn btn-primary" onclick="viewFile(${file.id})" style="flex-grow: 1; justify-content: center; gap: 0.5rem; height: 45px;">
                    <i class="fa-solid fa-eye"></i>
                    <span>${translations[currentLang].viewBtn}</span>
                </button>
            </div>
        `;
        list.appendChild(card);
    });
}

function openUploadModal() {
    document.getElementById('uploadModal').classList.add('active');
    document.getElementById('fileTitle').focus();
    const today = new Date().toISOString().split('T')[0];
    const dateInput = document.getElementById('fileDate');
    if(dateInput) dateInput.value = today;
}

function closeUploadModal() {
    document.getElementById('uploadModal').classList.remove('active');
    document.getElementById('uploadForm').reset();
    
    // Reset file name display
    const nameEl = document.getElementById('fileNameText');
    if (nameEl) { nameEl.textContent = 'لم يُختر ملف بعد'; nameEl.style.color = 'var(--text-muted)'; }
    const displayEl = document.getElementById('fileNameDisplay');
    if (displayEl) { const icon = displayEl.querySelector('i'); if(icon){ icon.className='fa-solid fa-paperclip'; icon.style.color='#CBD5E1'; } }
    const dropZone = document.getElementById('fileDropZone');
    if (dropZone) { dropZone.style.borderColor = ''; dropZone.style.background = ''; }
    
    const progressContainer = document.getElementById('uploadProgressContainer');
    if (progressContainer) {
        progressContainer.style.display = 'none';
        document.getElementById('uploadProgressBar').style.width = '0%';
        document.getElementById('uploadPercentage').textContent = '0%';
    }
}

function handleFileSelect(event) {
    const file = event.target.files[0];
    if (file) {
        const nameEl = document.getElementById('fileNameText');
        const displayEl = document.getElementById('fileNameDisplay');
        if (nameEl) {
            nameEl.textContent = file.name;
            nameEl.style.color = 'var(--primary)';
            if (displayEl) {
                displayEl.querySelector('i').className = 'fa-solid fa-check-circle';
                displayEl.querySelector('i').style.color = '#10B981';
            }
        }
        const dropZone = document.getElementById('fileDropZone');
        if (dropZone) {
            dropZone.style.borderColor = '#10B981';
            dropZone.style.background = 'rgba(16,185,129,0.04)';
        }
    }
}

// Drag-and-drop support
document.addEventListener('DOMContentLoaded', () => {
    const dropZone = document.getElementById('fileDropZone');
    if (!dropZone) return;
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            document.getElementById('fileInput').files = files;
            handleFileSelect({ target: { files } });
        }
    });
});

function handleUploadFile(e) {
    e.preventDefault();
    const patientId = getPatientIdFromUrl();
    const fileInput = document.getElementById('fileInput');
    
    if (fileInput.files.length === 0) {
        alert("يرجى اختيار ملف");
        return;
    }
    
    const file = fileInput.files[0];
    const title = document.getElementById('fileTitle').value;
    const desc = document.getElementById('fileDesc').value;
    const category = document.getElementById('fileCategory') ? document.getElementById('fileCategory').value : 'Medical history';
    const fileDate = document.getElementById('fileDate') ? document.getElementById('fileDate').value : new Date().toISOString().split('T')[0];
    
    let type = 'file';
    if (file.type.includes('image')) type = 'image';
    else if (file.type.includes('pdf')) type = 'pdf';
    else if (file.type.includes('video')) type = 'video';
    
    const formData = new FormData();
    formData.append('title', title);
    formData.append('desc', desc);
    formData.append('category', category);
    formData.append('type', type);
    formData.append('date', fileDate);
    formData.append('file', file);
    
    const submitBtn = document.getElementById('uploadSubmitBtn');
    const btnText = submitBtn.querySelector('.btn-text');
    const spinner = submitBtn.querySelector('.spinner-icon');
    const progressContainer = document.getElementById('uploadProgressContainer');
    const progressBar = document.getElementById('uploadProgressBar');
    const progressText = document.getElementById('uploadPercentage');
    
    submitBtn.disabled = true;
    btnText.textContent = 'جاري الرفع...';
    spinner.style.display = 'inline-block';
    if (progressContainer) {
        progressContainer.style.display = 'block';
        progressBar.style.width = '0%';
        progressText.textContent = '0%';
    }
    
    const xhr = new XMLHttpRequest();
    xhr.open('POST', apiUrl(`/patients/${patientId}/files`), true);
    xhr.setRequestHeader('Accept', 'application/json');
    const token = localStorage.getItem('api_token');
    if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`);
    
    xhr.upload.onprogress = function(event) {
        if (event.lengthComputable && progressContainer) {
            const percentComplete = Math.round((event.loaded / event.total) * 100);
            progressBar.style.width = percentComplete + '%';
            progressText.textContent = percentComplete + '%';
        }
    };
    
    xhr.onload = function() {
        submitBtn.disabled = false;
        btnText.textContent = 'حفظ';
        spinner.style.display = 'none';
        
        if (xhr.status === 201 || xhr.status === 200) {
            const response = JSON.parse(xhr.responseText);
            const newFile = response.data || response;
            filesData.unshift(newFile);
            renderPatientFiles(patientId);
            closeUploadModal();
        } else {
            console.error("Error saving file:", xhr.responseText);
            alert("تنبيه: حدث خطأ أثناء الرفع.");
        }
    };
    
    xhr.onerror = function() {
        submitBtn.disabled = false;
        btnText.textContent = 'حفظ';
        spinner.style.display = 'none';
        console.error("Network error");
        alert("تنبيه: حدث خطأ في الاتصال بالخادم.");
    };
    
    xhr.send(formData);
}

let currentZoom = 1;

function viewFile(id) {
    const file = filesData.find(f => f.id === id);
    if (!file) return;

    const titleEl = document.getElementById('viewerTitle');
    if(titleEl) titleEl.textContent = file.title;
    
    const content = document.getElementById('viewerContent');
    const zoomInBtn = document.getElementById('zoomInBtn');
    const zoomOutBtn = document.getElementById('zoomOutBtn');
    const fullscreenBtn = document.getElementById('fullscreenBtn');

    if(zoomInBtn) zoomInBtn.style.display = 'none';
    if(zoomOutBtn) zoomOutBtn.style.display = 'none';
    if(fullscreenBtn) fullscreenBtn.style.display = 'none';
    
    currentZoom = 1;
    content.innerHTML = '';

    if (file.type === 'image') {
        if(zoomInBtn) zoomInBtn.style.display = 'flex';
        if(zoomOutBtn) zoomOutBtn.style.display = 'flex';
        if(fullscreenBtn) fullscreenBtn.style.display = 'flex';
        const src = file.file_path || file.data || `https://placehold.co/800x600/0F172A/FFFFFF.png?text=Image`;
        content.innerHTML = `<img id="viewerImage" src="${src}" style="max-width: 100%; max-height: 100%; object-fit: contain; transition: transform 0.2s ease;">`;
    } else if (file.type === 'video') {
        if(fullscreenBtn) fullscreenBtn.style.display = 'flex';
        const src = file.file_path || file.data || '';
        content.innerHTML = `<video id="viewerVideo" controls style="max-width: 100%; max-height: 100%; width: 100%; border-radius: 8px;"><source src="${src}"></video>`;
    } else if (file.type === 'pdf') {
        if(fullscreenBtn) fullscreenBtn.style.display = 'flex';
        const src = file.file_path || file.data || '';
        content.innerHTML = `<iframe id="viewerPdf" src="${src}" style="width: 100%; height: 100%; border: none; border-radius: 8px;"></iframe>`;
    } else {
        const src = file.file_path || file.data;
        content.innerHTML = src 
            ? `<div style="color: white; text-align: center;"><i class="fa-solid fa-file-lines" style="font-size: 4rem; margin-bottom: 1rem;"></i><p style="margin-bottom:1rem">نوع الملف غير قابل للمعاينة</p><a href="${src}" download class="btn btn-primary"><i class="fa-solid fa-download"></i> تحميل الملف</a></div>`
            : `<div style="color: white; text-align: center;"><i class="fa-solid fa-file-lines" style="font-size: 4rem; margin-bottom: 1rem;"></i><p>لا يمكن معاينة هذا النوع من الملفات</p></div>`;
    }

    document.getElementById('viewerModal').classList.add('active');
}

function closeViewerModal() {
    document.getElementById('viewerModal').classList.remove('active');
    document.getElementById('viewerContent').innerHTML = '';
}

async function deleteFile(fileId, patientId) {
    if (!confirm('هل أنت متأكد من حذف هذا الملف؟')) return;
    try {
        const res = await fetch(apiUrl(`/patients/${patientId}/files/${fileId}`), { method: 'DELETE', headers: apiHeaders({ 'Accept': 'application/json' }) });
        if (res.ok) {
            filesData = filesData.filter(f => f.id !== fileId);
            renderPatientFiles(patientId);
        }
    } catch(err) { console.error(err); }
}

function zoomImage(step) {
    const img = document.getElementById('viewerImage');
    if (img) {
        currentZoom += step;
        if (currentZoom < 0.5) currentZoom = 0.5;
        if (currentZoom > 5) currentZoom = 5;
        img.style.transform = `scale(${currentZoom})`;
    }
}

function toggleFullscreen() {
    const content = document.getElementById('viewerContent');
    const video = document.getElementById('viewerVideo');
    const pdf = document.getElementById('viewerPdf');
    const el = video || pdf || content;
    
    if (!document.fullscreenElement) {
        if (el.requestFullscreen) {
            el.requestFullscreen();
        } else if (el.webkitRequestFullscreen) {
            el.webkitRequestFullscreen();
        }
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        }
    }
}

function openDeleteModal(id) {
    document.getElementById('deletePatientId').value = id;
    document.getElementById('deletePatientModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deletePatientModal').classList.remove('active');
}

async function confirmDeletePatient() {
    const id = parseInt(document.getElementById('deletePatientId').value);
    try {
        const res = await fetch(apiUrl(`/patients/${id}`), { method: 'DELETE', headers: apiHeaders({ 'Accept': 'application/json' }) });
        if(res.ok) {
            closeDeleteModal();
            fetchPatients();
        }
    } catch(err) { console.error(err); }
}

function openEditModal(id) {
    const p = patientsData.find(x => x.id === id);
    if(p) {
        document.getElementById('editPatientId').value = id;
        document.getElementById('editPatientName').value = p.name;
        document.getElementById('editPatientPhone').value = p.phone;
        document.getElementById('editPatientAddress').value = p.address;
        if(document.getElementById('editPatientDiagnosis')) document.getElementById('editPatientDiagnosis').value = p.diagnosis || '';
        document.getElementById('editPatientModal').classList.add('active');
    }
}

function closeEditModal() {
    document.getElementById('editPatientModal').classList.remove('active');
}

async function handleEditPatient(e) {
    e.preventDefault();
    const id = parseInt(document.getElementById('editPatientId').value);
    
    const name = document.getElementById('editPatientName').value.trim();
    const phone = document.getElementById('editPatientPhone').value.trim();
    const address = document.getElementById('editPatientAddress').value.trim();
    const diagnosis = document.getElementById('editPatientDiagnosis') ? document.getElementById('editPatientDiagnosis').value.trim() : '';
    
    try {
        const res = await fetch(apiUrl(`/patients/${id}`), {
            method: 'PUT',
            headers: apiHeaders({ 'Content-Type': 'application/json', 'Accept': 'application/json' }),
            body: JSON.stringify({ name, phone, address, diagnosis })
        });
        if(res.ok) {
            closeEditModal();
            fetchPatients();
        }
    } catch(err) { console.error(err); }
}

function mockDownload(type, name = '') {
    alert(translations[currentLang].downloadingMsg + (name ? '\n' + name : ''));
}
