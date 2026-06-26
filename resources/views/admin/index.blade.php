<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Panel | لوحة الإدارة</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563EB;
            --bg: #F1F5F9;
            --surface: #FFFFFF;
            --text: #0F172A;
            --border: #E2E8F0;
            --success: #10B981;
            --danger: #EF4444;
            --warning: #F59E0B;
        }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); margin: 0; display: flex; height: 100vh; overflow: hidden; transition: direction 0.3s; }

        /* Layout */
        .sidebar { width: 350px; background: var(--surface); display: flex; flex-direction: column; z-index: 10; box-shadow: 0 0 15px rgba(0,0,0,0.05); }
        [dir="rtl"] .sidebar { border-left: 2px solid var(--border); }
        [dir="ltr"] .sidebar { border-right: 2px solid var(--border); }

        .main-content { flex: 1; padding: 2.5rem; overflow-y: auto; background: var(--bg); }

        /* Sidebar Items */
        .brand { padding: 2rem; border-bottom: 2px solid var(--border); text-align: center; font-size: 1.8rem; font-weight: bold; color: var(--primary); display: flex; flex-direction: column; gap: 0.5rem; }
        .brand i { font-size: 3rem; margin-bottom: 0.5rem; color: var(--primary); }

        .nav-menu { flex: 1; display: flex; flex-direction: column; padding: 1rem 0; gap: 0.5rem; }
        .nav-btn { padding: 1.5rem; border: none; background: transparent; text-align: start; font-size: 1.4rem; font-weight: bold; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 1rem; color: var(--text); }
        .nav-btn:hover { background: #F8FAFC; color: var(--primary); }
        .nav-btn.active { background: #DBEAFE; color: var(--primary); }
        [dir="rtl"] .nav-btn.active { border-right: 6px solid var(--primary); }
        [dir="ltr"] .nav-btn.active { border-left: 6px solid var(--primary); }

        /* Sidebar Footer */
        .sidebar-footer { display: flex; padding: 1.2rem; background: #F8FAFC; border-top: 2px solid var(--border); justify-content: space-between; align-items: center; gap: 1rem; }
        .lang-btn { background: var(--success); color: white; border: none; padding: 1rem; border-radius: 8px; font-weight: bold; font-size: 1.2rem; cursor: pointer; flex: 1; display: flex; justify-content: center; align-items: center; gap: 0.5rem; }
        .logout-btn { background: var(--danger); color: white; border: none; padding: 1rem; border-radius: 8px; font-weight: bold; font-size: 1.2rem; cursor: pointer; flex: 1; display: flex; justify-content: center; align-items: center; gap: 0.5rem; }

        /* Main Content */
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .page-title { font-size: 2.2rem; font-weight: bold; margin-top: 0; margin-bottom: 2rem; color: var(--text); border-bottom: 3px solid var(--primary); display: inline-block; padding-bottom: 0.5rem; }

        /* Stats Dashboard */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; }
        .stat-card { background: var(--surface); border: 2px solid var(--border); border-radius: 16px; padding: 2rem; display: flex; align-items: center; gap: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .stat-icon { width: 80px; height: 80px; border-radius: 20px; display: flex; justify-content: center; align-items: center; font-size: 3rem; color: white; }
        .stat-info h3 { margin: 0 0 0.5rem 0; font-size: 1.4rem; color: #64748B; }
        .stat-info p { margin: 0; font-size: 2.5rem; font-weight: bold; color: var(--text); }

        /* Action Panels */
        .action-panel { background: var(--surface); border: 2px solid var(--border); border-radius: 16px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .panel-title { font-size: 1.8rem; font-weight: bold; color: var(--primary); margin: 0; }

        .btn { padding: 1rem 1.5rem; border: none; border-radius: 8px; font-size: 1.2rem; font-weight: bold; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; transition: 0.2s; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: #1D4ED8; }
        .btn-success { background: var(--success); color: white; }
        .btn-outline { background: transparent; border: 2px solid var(--border); color: var(--text); }
        .btn-outline:hover { background: var(--bg); }

        /* Lists */
        .list-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }
        .list-item { background: var(--bg); border: 2px solid var(--border); border-radius: 12px; padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem; }
        .item-title { font-size: 1.5rem; font-weight: bold; margin: 0; color: var(--text); }
        .item-subtitle { font-size: 1.1rem; color: #64748B; margin: 0; }
        .item-actions { display: flex; gap: 1rem; margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--border); }

        /* Modals */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000; justify-content: center; align-items: center; padding: 1rem; }
        .modal.active { display: flex; }
        .modal-content { background: var(--surface); padding: 2.5rem; border-radius: 16px; width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto; }
        .modal-header { font-size: 2rem; font-weight: bold; margin-bottom: 2rem; color: var(--primary); border-bottom: 2px solid var(--border); padding-bottom: 1rem; display: flex; justify-content: space-between; }

        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.8rem; font-size: 1.3rem; font-weight: bold; color: var(--text); }
        .form-control { width: 100%; padding: 1.2rem; font-size: 1.2rem; border: 2px solid var(--border); border-radius: 8px; box-sizing: border-box; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem; }

        .alert { padding: 1.5rem; border-radius: 12px; margin-bottom: 2rem; font-size: 1.4rem; font-weight: bold; }
        .alert-success { background: #D1FAE5; color: #065F46; border: 2px solid #34D399; }
        .alert-danger { background: #FEE2E2; color: #991B1B; border: 2px solid #F87171; }

        @media (max-width: 768px) {
            body { flex-direction: column; overflow-x: hidden; overflow-y: auto; height: auto; }
            .sidebar { width: 100%; height: auto; border: none !important; border-bottom: 2px solid var(--border) !important; }
            .nav-menu { flex-direction: row; flex-wrap: wrap; justify-content: center; padding: 0.5rem; }
            .nav-btn { padding: 1rem; flex: 1; min-width: 120px; justify-content: center; font-size: 1.1rem; border-bottom: 3px solid transparent; border-right: none !important; border-left: none !important; }
            .nav-btn.active { border-bottom-color: var(--primary) !important; background: #DBEAFE; }
            .main-content { width: 100%; height: auto; padding: 1rem; }
            .stats-grid { grid-template-columns: 1fr; }
            .list-grid { grid-template-columns: 1fr; }
            .panel-header { flex-direction: column; gap: 1rem; align-items: stretch; text-align: center; }
            .modal-content { padding: 1.5rem; margin: 1rem; max-height: 85vh; }
        }
    </style>
    <style>
        /* Mobile Safe Area Insets */
        body {
            padding-top: env(safe-area-inset-top);
            padding-bottom: env(safe-area-inset-bottom);
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="brand">
            <i class="fa-solid fa-shield-halved"></i>
            <span data-i18n="adminPanel">لوحة الإدارة</span>
        </div>

        <div class="nav-menu">
            <button class="nav-btn active" id="btn_dashboard" onclick="switchTab('dashboard')">
                <i class="fa-solid fa-chart-pie"></i> <span data-i18n="stats">الإحصائيات</span>
            </button>
            <button class="nav-btn" id="btn_doctors" onclick="switchTab('doctors')">
                <i class="fa-solid fa-user-doctor"></i> <span data-i18n="doctors">الأطباء</span>
            </button>
        </div>

        <div class="sidebar-footer">
            <button class="lang-btn" onclick="toggleLang()"><i class="fa-solid fa-language" style="font-size:1.5rem;"></i> <span data-i18n="langName">English</span></button>
            <form method="POST" action="/logout" style="margin:0; flex:1; display:flex;">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <button type="submit" class="logout-btn" style="width:100%;"><i class="fa-solid fa-arrow-right-from-bracket"></i> <span data-i18n="logout">خروج</span></button>
            </form>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger">
                <ul style="margin:0; padding-right:1rem;">
                    @foreach($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                </ul>
            </div>
        @endif

        <!-- Dashboard Tab -->
        <div class="tab-content active" id="tab_dashboard">
            <h1 class="page-title" data-i18n="statsOverview">نظرة عامة على الإحصائيات</h1>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #3B82F6, #2563EB);"><i class="fa-solid fa-user-doctor"></i></div>
                    <div class="stat-info">
                        <h3 data-i18n="totalDocs">إجمالي الأطباء</h3>
                        <p>{{ $doctorsCount }}</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #10B981, #059669);"><i class="fa-solid fa-users"></i></div>
                    <div class="stat-info">
                        <h3 data-i18n="totalPatients">إجمالي المرضى</h3>
                        <p>{{ $patientsCount }}</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #F59E0B, #D97706);"><i class="fa-solid fa-money-bill-trend-up"></i></div>
                    <div class="stat-info">
                        <h3 data-i18n="totalProfits">إجمالي الأرباح</h3>
                        <p style="direction:ltr;">{{ number_format($profits, 2) }} EGP</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Doctors Tab -->
        <div class="tab-content" id="tab_doctors">
            <h1 class="page-title" data-i18n="doctorsManagement">إدارة الأطباء</h1>
            <div class="action-panel">
                <div class="panel-header">
                    <h2 class="panel-title" data-i18n="doctorsList">قائمة الأطباء المسجلين</h2>
                    <button class="btn btn-primary" onclick="openDoctorModal()"><i class="fa-solid fa-plus"></i> <span data-i18n="addDoctor">إضافة طبيب جديد</span></button>
                </div>

                <div class="list-grid">
                    @foreach($doctors as $doctor)
                    <div class="list-item">
                        <h3 class="item-title">{{ $doctor->name }}</h3>
                        <p class="item-subtitle">{{ $doctor->email }}<br>{{ $doctor->specialization ?? 'تخصص عام' }}</p>
                        <div class="item-actions">
                            <button class="btn btn-outline" style="flex:1; color:#3B82F6;" onclick='openDoctorModal(@json($doctor))'><i class="fa-solid fa-pen"></i> <span data-i18n="edit">تعديل</span></button>
                            <form id="del_doc_{{ $doctor->id }}" action="{{ url('admin/doctors/'.$doctor->id) }}" method="POST" style="flex:1; display:flex;">
                                @csrf @method('DELETE')
                                <button type="button" onclick="confirmAdminDelete('del_doc_{{ $doctor->id }}', 'تأكيد مسح الطبيب؟')" class="btn btn-outline" style="width:100%; color:#EF4444;"><i class="fa-solid fa-trash"></i> <span data-i18n="delete">مسح</span></button>
                            </form>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Doctor Modal -->
    <div class="modal" id="doctorModal">
        <div class="modal-content">
            <div class="modal-header">
                <span id="doctorModalTitle" data-i18n="addDoctor">إضافة طبيب جديد</span>
                <button type="button" style="background:none;border:none;font-size:2rem;cursor:pointer;" onclick="closeModal('doctorModal')">&times;</button>
            </div>
            <form id="doctorForm" method="POST" action="{{ url('admin/doctors') }}">
                @csrf
                <input type="hidden" name="_method" id="docMethod" value="POST">

                <div class="form-group"><label data-i18n="name">الاسم</label><input type="text" id="docName" name="name" class="form-control" required></div>
                <div class="form-group"><label data-i18n="email">البريد الإلكتروني</label><input type="email" id="docEmail" name="email" class="form-control" required></div>
                <div class="form-group">
                    <label><span data-i18n="password">كلمة المرور</span> <small style="color:var(--danger);" id="docPassHint" data-i18n="passwordHint">(اتركها فارغة لعدم التغيير)</small></label>
                    <input type="password" id="docPass" name="password" class="form-control" required>
                </div>
                <div class="form-group"><label data-i18n="specialization">التخصص</label><input type="text" id="docSpec" name="specialization" class="form-control"></div>
                <div class="form-group"><label data-i18n="phone">التليفون</label><input type="text" id="docPhone" name="phone" class="form-control"></div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('doctorModal')" data-i18n="cancel">إلغاء</button>
                    <button type="submit" class="btn btn-primary" data-i18n="save">حفظ البيانات</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Category Modal -->
    <div class="modal" id="categoryModal">
        <div class="modal-content">
            <div class="modal-header">
                <span id="catModalTitle" data-i18n="addCategory">إضافة قسم جديد</span>
                <button type="button" style="background:none;border:none;font-size:2rem;cursor:pointer;" onclick="closeModal('categoryModal')">&times;</button>
            </div>
            <form id="categoryForm" method="POST" action="{{ url('admin/categories') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="_method" id="catMethod" value="POST">

                <div class="form-group"><label data-i18n="categoryName">اسم القسم</label><input type="text" id="catName" name="name" class="form-control" required></div>
                <div class="form-group"><label data-i18n="categoryIcon">الأيقونة (اختياري)</label><input type="file" name="icon" class="form-control" accept="image/*" style="padding:0.8rem;"></div>
                <div class="form-group"><label data-i18n="categoryColor">لون القسم (اختياري)</label><input type="color" id="catColor" name="color" class="form-control" value="#8B5CF6" style="height:60px; padding:0.5rem;"></div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('categoryModal')" data-i18n="cancel">إلغاء</button>
                    <button type="submit" class="btn btn-primary" style="background:#8B5CF6;" data-i18n="save">حفظ القسم</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirm Modal -->
    <div class="modal" id="confirmModal">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <div style="color: var(--danger); font-size: 4rem; margin-bottom: 1rem;">
                <i class="fa-solid fa-circle-exclamation"></i>
            </div>
            <h2 id="confirmMessage" style="font-size: 1.5rem; margin-bottom: 2rem;"></h2>
            <div class="modal-actions" style="justify-content: center; gap: 1rem; margin-top: 0;">
                <button class="btn btn-outline" onclick="closeModal('confirmModal')" data-i18n="cancel">إلغاء</button>
                <button class="btn btn-primary" style="background: var(--danger); padding: 0.8rem 2rem;" id="confirmBtn" data-i18n="delete">مسح</button>
            </div>
        </div>
    </div>

    <script>
        // Translations
        const i18n = {
            ar: {
                adminPanel: "لوحة الإدارة", stats: "الإحصائيات", doctors: "الأطباء",
                logout: "خروج", langName: "English", statsOverview: "نظرة عامة على الإحصائيات", totalDocs: "إجمالي الأطباء", totalPatients: "إجمالي المرضى",
                totalProfits: "إجمالي الأرباح", doctorsManagement: "إدارة الأطباء", doctorsList: "قائمة الأطباء المسجلين", addDoctor: "إضافة طبيب",
                edit: "تعديل", delete: "مسح", categoriesManagement: "إدارة أقسام الملفات", categoriesList: "الأقسام المتاحة حالياً", addCategory: "إضافة قسم",
                name: "الاسم", email: "البريد الإلكتروني", password: "كلمة المرور", passwordHint: "(اتركه فارغاً لعدم التغيير)", specialization: "التخصص",
                phone: "التليفون", categoryName: "اسم القسم", categoryIcon: "الأيقونة (اختياري)", categoryColor: "لون القسم", save: "حفظ", cancel: "إلغاء",
                editDoctorTitle: "تعديل بيانات طبيب", editCategoryTitle: "تعديل بيانات القسم"
            },
            en: {
                adminPanel: "Admin Panel", stats: "Dashboard", doctors: "Doctors",
                logout: "Logout", langName: "عربي", statsOverview: "Stats Overview", totalDocs: "Total Doctors", totalPatients: "Total Patients",
                totalProfits: "Total Profits", doctorsManagement: "Doctors Management", doctorsList: "Registered Doctors", addDoctor: "Add Doctor",
                edit: "Edit", delete: "Delete", categoriesManagement: "Categories Management", categoriesList: "Available Categories", addCategory: "Add Category",
                name: "Name", email: "Email", password: "Password", passwordHint: "(Leave blank to keep unchanged)", specialization: "Specialization",
                phone: "Phone", categoryName: "Category Name", categoryIcon: "Icon (Optional)", categoryColor: "Color", save: "Save", cancel: "Cancel",
                editDoctorTitle: "Edit Doctor", editCategoryTitle: "Edit Category"
            }
        };

        let lang = localStorage.getItem('lang') || 'ar';

        function setLang(l) {
            lang = l;
            localStorage.setItem('lang', l);
            document.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr';
            document.body.dir = l === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.lang = l;

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (i18n[lang][key]) el.textContent = i18n[lang][key];
            });
        }

        function toggleLang() { setLang(lang === 'ar' ? 'en' : 'ar'); }

        document.addEventListener('DOMContentLoaded', () => {
            setLang(lang);
            const activeTab = localStorage.getItem('adminActiveTab') || 'dashboard';
            switchTab(activeTab);
        });

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.nav-btn').forEach(el => el.classList.remove('active'));

            document.getElementById('tab_' + tabId).classList.add('active');
            const btn = document.getElementById('btn_' + tabId);
            if(btn) btn.classList.add('active');

            localStorage.setItem('adminActiveTab', tabId);
        }

        function openDoctorModal(doc = null) {
            document.getElementById('doctorForm').reset();
            const actionUrl = doc ? `/admin/doctors/${doc.id}` : `/admin/doctors`;
            document.getElementById('doctorForm').action = actionUrl;

            if (doc) {
                document.getElementById('doctorModalTitle').textContent = i18n[lang].editDoctorTitle;
                document.getElementById('docMethod').value = 'PUT';
                document.getElementById('docName').value = doc.name;
                document.getElementById('docEmail').value = doc.email;
                document.getElementById('docSpec').value = doc.specialization || '';
                document.getElementById('docPhone').value = doc.phone || '';
                document.getElementById('docPass').required = false;
                document.getElementById('docPassHint').style.display = 'inline';
            } else {
                document.getElementById('doctorModalTitle').textContent = i18n[lang].addDoctor;
                document.getElementById('docMethod').value = 'POST';
                document.getElementById('docPass').required = true;
                document.getElementById('docPassHint').style.display = 'none';
            }
            document.getElementById('doctorModal').classList.add('active');
        }

        function openCategoryModal(cat = null) {
            document.getElementById('categoryForm').reset();
            const actionUrl = cat ? `/admin/categories/${cat.id}` : `/admin/categories`;
            document.getElementById('categoryForm').action = actionUrl;

            if (cat) {
                document.getElementById('catModalTitle').textContent = i18n[lang].editCategoryTitle;
                document.getElementById('catMethod').value = 'PUT';
                document.getElementById('catName').value = cat.name;
                document.getElementById('catColor').value = cat.color || '#8B5CF6';
            } else {
                document.getElementById('catModalTitle').textContent = i18n[lang].addCategory;
                document.getElementById('catMethod').value = 'POST';
            }
            document.getElementById('categoryModal').classList.add('active');
        }

        let formToSubmit = null;
        function confirmAdminDelete(formId, msg) {
            formToSubmit = document.getElementById(formId);
            document.getElementById('confirmMessage').textContent = msg;
            document.getElementById('confirmModal').classList.add('active');
        }

        document.getElementById('confirmBtn').onclick = function() {
            if(formToSubmit) formToSubmit.submit();
        };

        function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    </script>
</body>
</html>
