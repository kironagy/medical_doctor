<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Patient Files | أرشفة المرضى</title>
    <meta name="mobile-api-url" content="{{ rtrim(config('mobile.api_url'), '/') }}/v1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alexandria:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ════════════════════════════════════════════════════════
           THEME SYSTEM - Light & Dark Mode
        ════════════════════════════════════════════════════════ */
        :root {
            /* Medical Color Palette - Vibrant & Professional */
            --primary: #0D9488;
            --primary-light: #14B8A6;
            --primary-dark: #0F766E;
            --primary-bg: #CCFBF1;
            
            --bg: #F0FDFA;
            --surface: #FFFFFF;
            --surface-hover: #F5FFFE;
            --surface-elevated: #FFFFFF;
            
            --text: #134E4A;
            --text-secondary: #2D6A4F;
            --text-muted: #52796F;
            --text-on-primary: #FFFFFF;
            
            --border: #99F6E4;
            --border-light: #CCFBF1;
            --border-focus: #14B8A6;
            
            --success: #059669;
            --success-bg: #D1FAE5;
            --danger: #DC2626;
            --danger-bg: #FEE2E2;
            --info: #2563EB;
            --info-bg: #DBEAFE;
            --warning: #D97706;
            --warning-bg: #FEF3C7;
            
            --shadow-sm: 0 1px 2px rgba(13,148,136,0.08);
            --shadow: 0 4px 12px rgba(13,148,136,0.12);
            --shadow-lg: 0 8px 24px rgba(13,148,136,0.18);
            --shadow-colored: 0 4px 14px rgba(13,148,136,0.25);
            
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            --glow-primary: 0 0 20px rgba(13,148,136,0.3);
        }
        
        [data-theme="dark"] {
            /* Black with Green Accent */
            --primary: #10B981;
            --primary-light: #34D399;
            --primary-dark: #059669;
            --primary-bg: #064E3B;
            
            --bg: #000000;
            --surface: #0A0A0A;
            --surface-hover: #141414;
            --surface-elevated: #1A1A1A;
            
            --text: #F0FDF4;
            --text-secondary: #A7F3D0;
            --text-muted: #6EE7B7;
            --text-on-primary: #000000;
            
            --border: #1A1A1A;
            --border-light: #0F0F0F;
            --border-focus: #10B981;
            
            --success: #34D399;
            --success-bg: #064E3B;
            --danger: #F87171;
            --danger-bg: #450A0A;
            --info: #60A5FA;
            --info-bg: #1E3A5F;
            --warning: #FBBF24;
            --warning-bg: #451A03;
            
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.8);
            --shadow: 0 4px 12px rgba(0,0,0,0.9);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.95);
            --shadow-colored: 0 4px 14px rgba(16,185,129,0.3);
            
            --glow-primary: 0 0 20px rgba(16,185,129,0.4);
        }
        
        * { box-sizing: border-box; }
        body { 
            font-family: 'Alexandria', system-ui, -apple-system, sans-serif; 
            background: var(--bg); 
            color: var(--text); 
            margin: 0; 
            display: flex; 
            height: 100vh; 
            overflow: hidden; 
            transition: background 0.3s, color 0.3s;
        }
        
        /* ════════════════════════════════════════════════════════
           SIDEBAR
        ════════════════════════════════════════════════════════ */
        .sidebar { 
            width: 300px; 
            background: var(--surface); 
            display: flex; 
            flex-direction: column; 
            z-index: 10; 
            box-shadow: var(--shadow);
            transition: background 0.3s, transform 0.3s ease;
        }
        [dir="rtl"] .sidebar { border-left: 1px solid var(--border); }
        [dir="ltr"] .sidebar { border-right: 1px solid var(--border); }
        
        .add-btn { 
            background: var(--primary); 
            color: var(--text-on-primary); 
            border: none; 
            padding: 0.8rem; 
            font-size: 0.9rem; 
            font-weight: 600; 
            cursor: pointer; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            gap: 0.5rem; 
            transition: var(--transition);
            letter-spacing: 0.3px;
        }
        .add-btn:hover { background: var(--primary-dark); color: var(--text-on-primary); transform: translateY(-1px); box-shadow: var(--shadow-colored); }
        .add-btn:active { transform: translateY(0); }
        
        .search-box { padding: 0.7rem; border-bottom: 1px solid var(--border); background: var(--surface); }
        .search-box input { 
            width: 100%; 
            padding: 0.6rem 0.8rem; 
            font-size: 0.85rem; 
            border: 1px solid var(--border); 
            border-radius: var(--radius-sm); 
            box-sizing: border-box; 
            background: var(--bg);
            color: var(--text);
            transition: var(--transition);
        }
        .search-box input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(13,148,136,0.2); }
        
        #patientList { flex: 1; overflow-y: auto; }
        .patient-btn { 
            padding: 0.75rem 0.8rem; 
            border: none; 
            border-bottom: 1px solid var(--border-light); 
            background: none; 
            text-align: start; 
            width: 100%; 
            cursor: pointer; 
            font-size: 0.9rem; 
            font-weight: 600; 
            transition: var(--transition); 
            display: flex; 
            flex-direction: column; 
            gap: 0.25rem; 
            color: var(--text);
        }
        .patient-btn:hover { background: var(--surface-hover); }
        .patient-btn.active { background: var(--primary-bg); color: var(--primary-dark); }
        [dir="rtl"] .patient-btn.active { border-right: 3px solid var(--primary); }
        [dir="ltr"] .patient-btn.active { border-left: 3px solid var(--primary); }
        .patient-phone { font-size: 0.78rem; color: var(--text-secondary); font-weight: 400; }

        /* Pagination */
        .pagination { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 0.6rem; 
            background: var(--surface); 
            border-top: 1px solid var(--border); 
        }
        .pagination button { 
            padding: 0.4rem 0.7rem; 
            border: 1px solid var(--border); 
            border-radius: var(--radius-sm); 
            background: var(--surface); 
            color: var(--text);
            cursor: pointer; 
            font-weight: 600; 
            font-size: 0.78rem; 
            transition: var(--transition);
        }
        .pagination button:hover:not(:disabled) { background: var(--bg); border-color: var(--primary); color: var(--primary); }
        .pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
        .pagination-info { font-size: 0.85rem; font-weight: 700; color: var(--text); }

        /* Sidebar Footer */
        .sidebar-footer { 
            display: flex; 
            padding: 0.6rem; 
            background: var(--bg); 
            border-top: 1px solid var(--border); 
            justify-content: space-between; 
            align-items: center; 
            gap: 0.4rem; 
        }
        .lang-btn { 
            background: var(--success); 
            color: var(--bg); 
            border: none; 
            padding: 0.5rem; 
            border-radius: var(--radius-sm); 
            font-weight: 600; 
            font-size: 0.78rem; 
            cursor: pointer; 
            flex: 1; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            gap: 0.3rem; 
            transition: var(--transition);
        }
        .lang-btn:hover { filter: brightness(1.1); transform: translateY(-1px); color: var(--bg); }
        .logout-btn { 
            background: var(--danger); 
            color: var(--bg); 
            border: none; 
            padding: 0.5rem; 
            border-radius: var(--radius-sm); 
            font-weight: 600; 
            font-size: 0.78rem; 
            cursor: pointer; 
            flex: 1; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            gap: 0.3rem; 
            transition: var(--transition);
        }
        .logout-btn:hover { filter: brightness(1.1); transform: translateY(-1px); color: var(--bg); }
        
        .theme-btn {
            background: var(--surface);
            color: var(--text);
            border: 1px solid var(--border);
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.78rem;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.3rem;
            transition: var(--transition);
        }
        .theme-btn:hover { background: var(--surface-hover); border-color: var(--primary); color: var(--primary); }

        /* ════════════════════════════════════════════════════════
           MAIN CONTENT
        ════════════════════════════════════════════════════════ */
        .main-content { 
            flex: 1; 
            padding: 1.2rem; 
            overflow-y: auto; 
            background: var(--bg); 
            display: none; 
            transition: background 0.3s;
        }
        .placeholder-content { 
            flex: 1; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            flex-direction: column; 
            color: var(--text-secondary); 
            text-align: center; 
        }
        
        /* Info Card */
        .info-card { 
            background: var(--surface); 
            padding: 1rem 1.2rem; 
            border-radius: var(--radius); 
            border: 2px solid var(--primary-light); 
            margin-bottom: 1.2rem; 
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        .info-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
        }
        .info-card:hover { box-shadow: var(--shadow); }
        .info-card-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 0.6rem; 
            flex-wrap: wrap; 
            gap: 0.5rem; 
        }
        .info-card h2 { margin: 0; font-size: 1.15rem; color: var(--text); font-weight: 700; }
        .info-card .info-details { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); 
            gap: 0.3rem 1.2rem; 
        }
        .info-card p { 
            margin: 0; 
            font-size: 0.85rem; 
            color: var(--text-secondary); 
            display: flex; 
            align-items: center; 
            gap: 0.3rem; 
        }
        .info-card .btn { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
        
        /* Section blocks */
        .section-block { 
            background: var(--surface); 
            border-radius: var(--radius); 
            padding: 1rem; 
            margin-bottom: 1rem; 
            border: 1px solid var(--border); 
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        .section-block:hover { 
            border-color: var(--primary-light); 
            box-shadow: var(--shadow-colored);
        }
        .section-block:hover { box-shadow: var(--shadow); }
        .section-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 0.8rem; 
            border-bottom: 1px solid var(--border-light); 
            padding-bottom: 0.6rem; 
            flex-wrap: wrap; 
            gap: 0.5rem; 
        }
        .section-title { 
            font-size: 1rem; 
            font-weight: 700; 
            color: var(--primary); 
            margin: 0; 
            letter-spacing: 0.2px;
        }
        
        /* Buttons */
        .btn { 
            padding: 0.5rem 0.9rem; 
            border: none; 
            border-radius: var(--radius-sm); 
            font-size: 0.82rem; 
            font-weight: 600; 
            cursor: pointer; 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
            gap: 0.35rem; 
            transition: var(--transition);
            letter-spacing: 0.2px;
        }
        .btn-primary { background: var(--primary); color: var(--text-on-primary); }
        .btn-primary:hover { background: var(--primary-dark); color: var(--text-on-primary); transform: translateY(-1px); box-shadow: var(--shadow-colored); }
        .btn-primary:active { transform: translateY(0); }
        .btn-print { background: var(--info); color: var(--bg); padding: 0.35rem 0.6rem; font-size: 0.75rem; }
        .btn-print:hover { filter: brightness(1.1); color: var(--bg); }
        
        /* Grid for items */
        .items-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.8rem; }
        .item-card { 
            background: var(--bg); 
            padding: 0.8rem; 
            border-radius: var(--radius-sm); 
            border: 1px solid var(--border-light); 
            display: flex; 
            flex-direction: column;
            transition: var(--transition);
        }
        .item-card:hover { 
            transform: translateY(-3px); 
            box-shadow: var(--shadow-colored); 
            border-color: var(--primary-light);
        }
        .item-preview-box { 
            height: 140px; 
            background: linear-gradient(135deg, var(--bg), var(--border-light)); 
            border-radius: var(--radius-sm); 
            margin-bottom: 0.6rem; 
            border: 1px solid var(--border); 
            cursor: pointer; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            overflow: hidden; 
            position: relative;
            transition: var(--transition);
        }
        .item-preview-box:hover { border-color: var(--primary-light); }
        .item-preview-box img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; }
        .item-preview-box:hover img { transform: scale(1.05); }
        .item-preview-box .file-icon { font-size: 2.5rem; color: var(--text-secondary); transition: var(--transition); }
        .item-preview-box:hover .file-icon { color: var(--primary); }
        
        .item-card .text-content { 
            font-size: 0.85rem; 
            white-space: pre-wrap; 
            line-height: 1.5; 
            flex: 1; 
            margin-bottom: 0.5rem; 
            color: var(--text) !important;
        }
        .item-date { 
            font-size: 0.78rem; 
            color: var(--text-secondary); 
            margin-bottom: 0.5rem; 
            font-weight: 600; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
        }
        .card-actions { display: flex; gap: 0.3rem; margin-top: auto; }
        .delete-btn { 
            flex: 1; 
            background: var(--danger-bg); 
            color: var(--danger); 
            border: none; 
            padding: 0.4rem; 
            border-radius: var(--radius-sm); 
            cursor: pointer; 
            font-weight: 600; 
            font-size: 0.72rem; 
            display: flex; 
            justify-content: center; 
            gap: 0.25rem; 
            transition: var(--transition);
        }
        .delete-btn:hover { background: var(--danger); color: var(--bg); }
        .print-btn { 
            flex: 1; 
            background: var(--info-bg); 
            color: var(--info); 
            border: none; 
            padding: 0.4rem; 
            border-radius: var(--radius-sm); 
            cursor: pointer; 
            font-weight: 600; 
            font-size: 0.72rem; 
            display: flex; 
            justify-content: center; 
            gap: 0.25rem; 
            transition: var(--transition);
        }
        .print-btn:hover { background: var(--info); color: var(--bg); }

        .load-more-btn { 
            width: 100%; 
            padding: 0.6rem; 
            margin-top: 0.6rem; 
            background: transparent; 
            border: 2px dashed var(--primary-light); 
            color: var(--primary); 
            font-size: 0.85rem; 
            font-weight: 600; 
            border-radius: var(--radius-sm); 
            cursor: pointer; 
            transition: var(--transition);
        }
        .load-more-btn:hover { 
            border-color: var(--primary); 
            background: var(--primary-bg); 
            border-style: solid;
        }
        
        /* ════════════════════════════════════════════════════════
           MODALS
        ════════════════════════════════════════════════════════ */
        .modal { 
            display: none; 
            position: fixed; 
            inset: 0; 
            background: rgba(13,148,136,0.15); 
            z-index: 1000; 
            justify-content: center; 
            align-items: center; 
            padding: 1rem; 
            backdrop-filter: blur(4px);
        }
        .modal.active { display: flex; }
        .modal-content { 
            background: var(--surface); 
            padding: 1.5rem; 
            border-radius: var(--radius); 
            width: 100%; 
            max-width: 500px; 
            max-height: 90vh; 
            overflow-y: auto; 
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
        }
        .modal-header { 
            font-size: 1.15rem; 
            font-weight: 700; 
            margin-bottom: 1.2rem; 
            color: var(--primary); 
            border-bottom: 1px solid var(--border-light); 
            padding-bottom: 0.7rem; 
        }
        
        /* Type Selection */
        .type-selection-cards { display: flex; gap: 0.8rem; margin-bottom: 1rem; }
        .type-card { 
            flex: 1; 
            padding: 1rem 0.6rem; 
            text-align: center; 
            border: 2px solid var(--border); 
            border-radius: var(--radius-sm); 
            cursor: pointer; 
            transition: var(--transition); 
            background: var(--bg); 
            display: flex; 
            flex-direction: column; 
            gap: 0.4rem; 
            align-items: center; 
        }
        .type-card i { font-size: 1.8rem; color: var(--text-secondary); transition: var(--transition); }
        .type-card span { font-size: 0.9rem; font-weight: 600; color: var(--text); }
        .type-card:hover { border-color: var(--primary-light); background: var(--surface-hover); }
        .type-card.active { border-color: var(--primary); background: var(--primary-bg); }
        .type-card.active i { color: var(--primary); }

        .form-group { margin-bottom: 0.8rem; }
        .form-group label { 
            display: block; 
            margin-bottom: 0.3rem; 
            font-size: 0.85rem; 
            font-weight: 600; 
            color: var(--text); 
        }
        .form-control { 
            width: 100%; 
            padding: 0.6rem; 
            font-size: 0.85rem; 
            border: 1px solid var(--border); 
            border-radius: var(--radius-sm); 
            box-sizing: border-box; 
            font-family: 'Alexandria', system-ui, -apple-system, sans-serif; 
            background: var(--bg);
            color: var(--text);
            transition: var(--transition);
        }
        .form-control:focus { 
            outline: none; 
            border-color: var(--primary); 
            box-shadow: 0 0 0 3px rgba(13,148,136,0.2); 
        }
        textarea.form-control { min-height: 80px; resize: vertical; }
        
        .modal-actions { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1rem; }
        .btn-cancel { background: linear-gradient(135deg, var(--bg), var(--border-light)); color: var(--text); }
        .btn-cancel:hover { background: var(--border); }
        
        /* File Upload */
        .file-upload-area { 
            border: 2px dashed var(--primary-light); 
            border-radius: var(--radius-sm); 
            padding: 2rem 1rem; 
            text-align: center; 
            cursor: pointer; 
            background: var(--primary-bg); 
            transition: var(--transition); 
            position: relative; 
            margin-bottom: 0.8rem; 
        }
        .file-upload-area:hover { border-color: var(--primary); background: var(--primary-bg); }
        .file-upload-area input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .file-upload-icon { font-size: 2.5rem; color: var(--primary-light); margin-bottom: 0.4rem; }
        .file-upload-text { font-size: 0.9rem; font-weight: 600; color: var(--primary); }
        
        /* Viewers */
        #mediaViewer { 
            display: none; 
            position: fixed; 
            inset: 0; 
            background: rgba(12,26,26,0.92); 
            z-index: 2000; 
            justify-content: center; 
            align-items: center; 
            backdrop-filter: blur(8px);
        }
        .viewer-content { max-width: 90%; max-height: 90%; position: relative; }
        #mediaViewer img, #mediaViewer video, #mediaViewer iframe { 
            max-width: 100%; 
            max-height: 90vh; 
            border-radius: var(--radius-sm); 
            background: var(--surface); 
            box-shadow: var(--shadow-lg);
        }
        .close-viewer { 
            position: absolute; 
            top: 15px; 
            right: 20px; 
            color: white; 
            font-size: 2.5rem; 
            cursor: pointer; 
            z-index: 2010; 
            text-shadow: 0 2px 4px rgba(0,0,0,0.5);
            transition: var(--transition);
        }
        .close-viewer:hover { transform: scale(1.1); }

        /* ════════════════════════════════════════════════════════
           TOAST NOTIFICATIONS
        ════════════════════════════════════════════════════════ */
        #toastContainer {
            position: fixed;
            top: 1rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 3000;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            pointer-events: none;
        }
        .toast {
            background: var(--surface);
            color: var(--text);
            padding: 0.7rem 1.2rem;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: toastIn 0.3s ease;
            pointer-events: auto;
            min-width: 250px;
            justify-content: center;
        }
        .toast.success { border-color: var(--success); color: var(--success); background: var(--success-bg); }
        .toast.error { border-color: var(--danger); color: var(--danger); background: var(--danger-bg); }
        .toast.info { border-color: var(--info); color: var(--info); background: var(--info-bg); }
        @keyframes toastIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes toastOut {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(-20px); }
        }

        /* ════════════════════════════════════════════════════════
           LOADING SPINNER
        ════════════════════════════════════════════════════════ */
        .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 5;
            border-radius: var(--radius);
        }
        [data-theme="dark"] .loading-overlay {
            background: rgba(45,55,72,0.8);
        }

        /* ════════════════════════════════════════════════════════
           KEYBOARD SHORTCUTS HINT
        ════════════════════════════════════════════════════════ */
        .kbd {
            display: inline-block;
            padding: 0.1rem 0.4rem;
            font-size: 0.7rem;
            font-weight: 700;
            line-height: 1.4;
            color: var(--text);
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 4px;
            box-shadow: 0 1px 0 var(--border);
            font-family: monospace;
        }

        /* ════════════════════════════════════════════════════════
           RESPONSIVE
        ════════════════════════════════════════════════════════ */
        /* ════════════════════════════════════════════════════════
           MOBILE APP STYLE
        ════════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            body { 
                display: block; 
                overflow-x: hidden; 
                overflow-y: auto; 
                height: auto;
                -webkit-tap-highlight-color: transparent;
            }
            
            /* Sidebar - Full Screen App Style */
            .sidebar { 
                width: 100%; 
                height: 100dvh; 
                border: none !important; 
                position: fixed; 
                top: 0; 
                left: 0; 
                right: 0; 
                z-index: 50; 
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }
            .sidebar.mobile-hidden { transform: translateX(100%); }
            [dir="ltr"] .sidebar.mobile-hidden { transform: translateX(-100%); }
            
            /* Add Patient Button - Large Touch Target */
            .add-btn { 
                padding: 1rem; 
                font-size: 1rem; 
                min-height: 52px;
            }
            
            /* Search - Comfortable */
            .search-box { padding: 0.8rem; }
            .search-box input { 
                padding: 0.85rem 1rem; 
                font-size: 1rem; 
                min-height: 48px;
                border-radius: var(--radius);
            }
            
            /* Patient List Items - Large Touch Targets */
            .patient-btn { 
                padding: 1rem; 
                font-size: 1rem; 
                min-height: 64px;
                gap: 0.4rem;
            }
            .patient-phone { font-size: 0.85rem; }
            
            /* Pagination */
            .pagination { padding: 0.8rem; }
            .pagination button { 
                padding: 0.6rem 1rem; 
                font-size: 0.9rem;
                min-height: 40px;
                min-width: 44px;
            }
            .pagination-info { font-size: 1rem; }
            
            /* Sidebar Footer */
            .sidebar-footer { padding: 0.7rem; gap: 0.5rem; }
            .lang-btn, .logout-btn, .theme-btn { 
                padding: 0.7rem; 
                font-size: 0.85rem;
                min-height: 44px;
            }

            /* Main Content */
            .main-content { 
                width: 100%; 
                padding: 0.75rem; 
                padding-top: 3.5rem; 
                min-height: 100dvh; 
                display: none; 
            }
            .placeholder-content { display: none !important; }
            
            /* Info Card - Clean Mobile Style */
            .info-card { 
                padding: 1rem; 
                margin-top: 0.5rem; 
                border-radius: var(--radius);
                border-width: 1px;
            }
            .info-card-header { 
                flex-direction: row; 
                align-items: center;
                gap: 0.5rem;
            }
            .info-card h2 { font-size: 1.1rem; }
            .info-card .info-details { 
                grid-template-columns: 1fr; 
                gap: 0.5rem;
            }
            .info-card p { font-size: 0.9rem; }
            .info-card .btn { 
                padding: 0.6rem 0.9rem; 
                font-size: 0.85rem; 
                min-height: 40px;
                min-width: 44px;
            }

            /* Section Blocks */
            .section-block { 
                padding: 1rem; 
                margin-bottom: 0.75rem; 
                border-radius: var(--radius);
            }
            .section-header { 
                flex-direction: row; 
                align-items: center; 
                gap: 0.5rem; 
                border-bottom: 1px solid var(--border-light); 
                padding-bottom: 0.6rem; 
            }
            .section-title { font-size: 0.95rem; text-align: right; }
            .section-header .btn { 
                width: auto; 
                font-size: 0.85rem; 
                padding: 0.5rem 0.8rem;
                min-height: 40px;
                min-width: 44px;
                white-space: nowrap;
            }
            
            .load-more-btn { 
                padding: 0.8rem; 
                font-size: 0.9rem;
                min-height: 48px;
            }

            /* Type Selection Cards */
            .type-selection-cards { flex-direction: row; gap: 0.75rem; }
            .type-card { 
                padding: 1.2rem 0.5rem; 
                min-height: 80px;
            }
            .type-card i { font-size: 2rem; }
            .type-card span { font-size: 0.9rem; }
            
            /* Items Grid - 2 Columns with Good Size */
            .items-grid { 
                grid-template-columns: repeat(2, 1fr); 
                gap: 0.6rem; 
            }
            .item-card { 
                padding: 0.6rem; 
                border-radius: var(--radius-sm);
            }
            .item-preview-box { 
                height: 110px; 
                border-radius: var(--radius-sm);
            }
            .item-preview-box .file-icon { font-size: 2rem; }
            .item-card .text-content { font-size: 0.8rem; }
            .item-date { font-size: 0.75rem; }
            
            /* Card Action Buttons - Clear & Touchable */
            .card-actions { gap: 0.3rem; }
            .delete-btn, .print-btn { 
                font-size: 0.7rem; 
                padding: 0.4rem 0.3rem;
                min-height: 36px;
                min-width: 44px;
                border-radius: var(--radius-sm);
            }
            
            /* Modals - Full Screen App Style */
            .modal-content { 
                padding: 1.25rem; 
                margin: 0.5rem; 
                max-height: 85vh; 
                width: calc(100% - 1rem); 
                border-radius: var(--radius);
            }
            .modal-header { font-size: 1.1rem; margin-bottom: 1rem; }
            .form-group label { font-size: 0.9rem; }
            .form-control { 
                font-size: 1rem; 
                padding: 0.75rem;
                min-height: 48px;
            }
            textarea.form-control { min-height: 100px; }
            .modal-actions { 
                gap: 0.75rem; 
                margin-top: 1.25rem; 
            }
            .modal-actions .btn { 
                min-height: 48px; 
                font-size: 0.95rem;
                flex: 1;
            }
            
            /* File Upload Area */
            .file-upload-area { 
                padding: 2rem 1rem; 
                min-height: 120px;
            }
            .file-upload-icon { font-size: 2.5rem; }
            .file-upload-text { font-size: 0.9rem; }
            
            /* Toast - Bottom Position for Mobile */
            #toastContainer {
                top: auto;
                bottom: 1rem;
                left: 1rem;
                right: 1rem;
                transform: none;
            }
            .toast {
                width: 100%;
                justify-content: center;
            }

            .mobile-back-btn { display: flex !important; }
        }

        .mobile-back-btn { 
            display: none; 
            background: var(--surface); 
            border: 1px solid var(--border); 
            color: var(--text); 
            padding: 0.6rem; 
            border-radius: 0; 
            font-weight: 600; 
            font-size: 0.85rem; 
            margin-bottom: 0.5rem; 
            align-items: center; 
            justify-content: center; 
            gap: 0.3rem; 
            cursor: pointer; 
            box-shadow: var(--shadow-sm);
            position: fixed; 
            top: 0; 
            left: 0; 
            right: 0; 
            z-index: 40; 
            border-top: none; 
            border-left: none; 
            border-right: none; 
        }
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

    <!-- Sidebar: Patient List -->
    <div class="sidebar">
        <button class="add-btn" onclick="openPatientModal()"><i class="fa-solid fa-user-plus"></i> <span data-i18n="newPatient">إضافة مريض جديد</span></button>
        <div class="search-box">
            <input type="text" id="searchInput" data-i18n-ph="searchPlaceholder" placeholder="البحث بالاسم أو التليفون..." oninput="handleSearch()">
        </div>
        <div id="patientList"></div>
        <div class="pagination">
            <button id="prevBtn" onclick="changePage(-1)" disabled><i class="fa-solid fa-chevron-right"></i> <span data-i18n="prev">السابق</span></button>
            <span class="pagination-info" id="pageInfo">1 / 1</span>
            <button id="nextBtn" onclick="changePage(1)" disabled><span data-i18n="next">التالي</span> <i class="fa-solid fa-chevron-left"></i></button>
        </div>
        <div class="sidebar-footer">
            <button class="theme-btn" onclick="toggleTheme()" title="Toggle Dark Mode">
                <i class="fa-solid fa-moon" id="themeIcon"></i>
            </button>
            <button class="lang-btn" onclick="toggleLang()"><i class="fa-solid fa-language"></i> <span data-i18n="langName">English</span></button>
            <form method="POST" action="/logout" id="logoutForm" style="margin:0; flex: 1; display:none;">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
            </form>
            <button type="button" class="logout-btn" style="width: 100%; flex: 1;" onclick="openLogoutModal()"><i class="fa-solid fa-arrow-right-from-bracket"></i> <span data-i18n="logout">خروج</span></button>
        </div>
    </div>

    <!-- Placeholder Content -->
    <div class="placeholder-content" id="placeholderContent">
        <i class="fa-solid fa-notes-medical" style="font-size: 8rem; margin-bottom: 2rem; color: var(--border);"></i>
        <h2 style="font-size: 2.5rem;" data-i18n="noPatients">قم باختيار مريض لعرض بياناته</h2>
    </div>

        <!-- Main Content -->
    <button class="mobile-back-btn" onclick="backToSidebar()"><i class="fa-solid fa-arrow-right"></i> <span data-i18n="backToList">العودة لقائمة المرضى</span></button>
    
    <div class="main-content" id="mainContent">
        <div class="info-card">
            <div class="info-card-header">
                <h2 id="lbl_name"><span data-i18n="name">الاسم</span>: <span class="val"></span></h2>
                <div style="display:flex; gap:0.5rem;">
                    <button class="btn btn-primary" onclick="openPatientModal(currentPatient)">
                        <i class="fa-solid fa-pen-to-square"></i> <span data-i18n="editPatient">تعديل</span>
                    </button>
                    <button class="btn" onclick="confirmDeletePatient()" style="background:var(--danger-bg); color:var(--danger); border:1px solid var(--danger);">
                        <i class="fa-solid fa-trash"></i> <span data-i18n="deletePatient">مسح</span>
                    </button>
                </div>
            </div>
            <div class="info-details">
                <p id="lbl_code"><i class="fa-solid fa-hashtag"></i> <span data-i18n="patientCode">الكود</span>: <span class="val" style="font-weight: bold; color: var(--primary); letter-spacing: 2px;"></span></p>
                <p id="lbl_address"><i class="fa-solid fa-location-dot"></i> <span data-i18n="address">العنوان</span>: <span class="val"></span></p>
                <p id="lbl_phone"><i class="fa-solid fa-phone"></i> <span data-i18n="phone">التليفون</span>: <span class="val"></span></p>
                <p id="lbl_diagnosis" style="color: var(--danger); font-weight: bold;"><i class="fa-solid fa-stethoscope"></i> <span data-i18n="diagnosis">التشخيص</span>: <span class="val"></span></p>
            </div>
        </div>

        <div id="sectionsContainer"></div>
<div id="dedicatedView" style="display:none;">
        <!-- Header -->
        <div class="section-block" style="padding:1.5rem;">
            <div class="section-header" style="margin-bottom:0;">
                <button class="btn" onclick="closeDedicatedView()" style="background:var(--surface); border:2px solid var(--border); color:var(--text); display:flex; align-items:center; gap:0.5rem; font-size:1.1rem;">
                    <i class="fa-solid fa-arrow-right"></i> <span data-i18n="backToList">العودة للأقسام</span>
                </button>
                <h2 id="dedicatedTitle" class="section-title" style="margin:0;"></h2>
                <span id="dedicatedCount" style="font-size:1.1rem; color:var(--text-muted); font-weight:bold;"></span>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="section-block" style="padding:1.5rem; display:flex; gap:1rem; align-items:center;">
            <div style="flex:1; position:relative;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute; right:1rem; top:50%; transform:translateY(-50%); color:var(--text-muted);"></i>
                <input type="text" id="dedicatedSearch" placeholder="البحث في الملفات..." 
                    style="width:100%; padding:1rem 2.5rem 1rem 1rem; border:2px solid var(--border); border-radius:8px; font-size:1.1rem; background:var(--surface); color:var(--text); box-sizing:border-box;"
                    oninput="handleDedicatedSearch()">
            </div>
            <button class="btn btn-primary" onclick="openItemModal(currentDedicatedCategory)" style="white-space:nowrap;">
                <i class="fa-solid fa-plus"></i> <span data-i18n="addEntry">إضافة</span>
            </button>
        </div>

        <!-- Items Grid -->
        <div class="items-grid" id="dedicatedGrid" style="margin-bottom:2rem;"></div>

        <!-- Pagination -->
        <div id="dedicatedPagination" style="display:flex; justify-content:center; gap:0.5rem; margin-bottom:2rem; padding:1rem; background:var(--surface); border-radius:16px; border:2px solid var(--border);"></div>
    </div>
    
    </div>

    <!-- Patient Modal -->
    <div class="modal" id="patientModal">
        <div class="modal-content">
            <div class="modal-header" id="patientModalTitle" data-i18n="addPatientTitle">إضافة مريض</div>
            <form id="patientForm" onsubmit="savePatient(event)">
                <input type="hidden" id="patientId">
                <div class="form-group">
                    <label data-i18n="name">الاسم</label>
                    <input type="text" id="p_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label data-i18n="phone">التليفون</label>
                    <input type="text" id="p_phone" class="form-control" required>
                </div>
                <div class="form-group">
                    <label data-i18n="address">العنوان</label>
                    <input type="text" id="p_address" class="form-control">
                </div>
                <div class="form-group">
                    <label data-i18n="diagnosis">التشخيص</label>
                    <textarea id="p_diagnosis" class="form-control"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeModal('patientModal')" data-i18n="cancel">إلغاء</button>
                    <button type="submit" class="btn btn-primary" data-i18n="save">حفظ</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Item Modal -->
    <div class="modal" id="itemModal">
        <div class="modal-content">
            <div class="modal-header" id="itemModalTitle" data-i18n="addEntry">إضافة سجل جديد</div>
            <form id="itemForm" onsubmit="saveItem(event)">
                <input type="hidden" id="itemCategory">
                
                <!-- Type Selection Cards -->
                <div class="type-selection-cards">
                    <div class="type-card active" id="card_text" onclick="selectEntryType('text')">
                        <i class="fa-solid fa-align-right"></i>
                        <span data-i18n="typeText">كتابة نص</span>
                    </div>
                    <div class="type-card" id="card_file" onclick="selectEntryType('file')">
                        <i class="fa-solid fa-file-arrow-up"></i>
                        <span data-i18n="typeFile">إرفاق ملف</span>
                    </div>
                </div>
                
                <input type="hidden" id="selectedType" value="text">

                <div id="fileInputContainer" style="display:none;">
                    <div class="file-upload-area">
                        <input type="file" id="itemFile" onchange="updateFileName(this)">
                        <i class="fa-solid fa-cloud-arrow-up file-upload-icon"></i>
                        <div class="file-upload-text" id="fileNameDisplay" data-i18n="fileSelectHint">اضغط هنا لاختيار صورة أو ملف PDF أو فيديو</div>
                    </div>
                </div>

                <div id="textInputContainer" class="form-group">
                    <label data-i18n="textDetails">التفاصيل أو الملاحظات</label>
                    <textarea id="itemText" class="form-control" placeholder="..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeModal('itemModal')" data-i18n="cancel">إلغاء</button>
                    <button type="submit" id="saveItemBtn" class="btn btn-primary" style="padding: 1.2rem 3rem;" data-i18n="save">حفظ</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Media Viewer Modal -->
    <div id="mediaViewer" onclick="closeViewer(event)">
        <span class="close-viewer" onclick="document.getElementById('mediaViewer').style.display='none'">&times;</span>
        <div class="viewer-content" id="viewerContent"></div>
    </div>

    <!-- Confirm Modal -->
    <div class="modal" id="confirmModal">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <div style="color: var(--danger); font-size: 4rem; margin-bottom: 1rem;">
                <i class="fa-solid fa-circle-exclamation"></i>
            </div>
            <h2 id="confirmMessage" style="font-size: 1.5rem; margin-bottom: 2rem;"></h2>
            <div class="modal-actions" style="justify-content: center; gap: 1rem; margin-top: 0;">
                <button class="btn btn-cancel" onclick="closeModal('confirmModal')" data-i18n="cancel">إلغاء</button>
                <button class="btn" style="background: var(--danger); color: white; padding: 0.8rem 2rem;" id="confirmBtn" data-i18n="deleteBtn">مسح</button>
            </div>
        </div>
    </div>

    <!-- Logout Confirm Modal -->
    <div class="modal" id="logoutModal">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <div style="color: var(--primary); font-size: 4rem; margin-bottom: 1rem;">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </div>
            <h2 style="font-size: 1.5rem; margin-bottom: 2rem;">هل تريد تسجيل الخروج؟</h2>
            <div class="modal-actions" style="justify-content: center; gap: 1rem; margin-top: 0;">
                <button class="btn btn-cancel" onclick="closeModal('logoutModal')" data-i18n="cancel">لا</button>
                <button class="btn btn-primary" style="padding: 0.8rem 2rem;" onclick="confirmLogout()">نعم</button>
            </div>
        </div>
    </div>

    <!-- Script -->
    <script>
        // Translations
        const i18n = {
            ar: {
                newPatient: "مريض جديد", searchPlaceholder: "بحث بالاسم / التليفون...", name: "الاسم", phone: "التليفون", address: "العنوان", diagnosis: "التشخيص",
                editPatient: "تعديل", history: "التاريخ الطبي (Medical History)", preOp: "أشعة قبل العملية (Pre-op Radiology)", investigations: "تحاليل (Investigations)",
                postOp: "أشعة بعد العملية (Post-op Radiology)", opSheet: "تفاصيل العملية (Operation Sheet)", meds: "الروشتة والأدوية (Medications)",
                notes: "ملاحظات أخرى (Other Notes)", addEntry: "إضافة (Add)", typeText: "كتابة نص",
                typeFile: "إرفاق ملف", textDetails: "التفاصيل أو الملاحظات:", fileSelectHint: "اضغط لاختيار صورة، PDF، أو فيديو",
                save: "حفظ", cancel: "إلغاء", deleteBtn: "مسح", printBtn: "طباعة", logout: "خروج", langName: "English", prev: "السابق", next: "التالي",
                noPatients: "قم باختيار مريض من القائمة לעرض بياناته", saving: "جاري الحفظ...", addPatientTitle: "إضافة مريض", editPatientTitle: "تعديل مريض",
                confirmDelete: "هل أنت متأكد من مسح هذا العنصر؟", confirmDeletePatient: "هل أنت متأكد من مسح هذا المريض؟ لا يمكن التراجع عن هذا الإجراء.", deletePatient: "مسح المريض", loadMore: "إظهار المزيد", backToList: "العودة لقائمة المرضى", patientCode: "الكود"
            },
            en: {
                newPatient: "New Patient", searchPlaceholder: "Search name / phone / code...", name: "Name", phone: "Phone", address: "Address", diagnosis: "Diagnosis",
                editPatient: "Edit", history: "Medical History", preOp: "Pre-op Radiology", investigations: "Investigations",
                postOp: "Post-op Radiology", opSheet: "Operation Sheet", meds: "Medications",
                notes: "Other Notes", addEntry: "Add Entry", typeText: "Text Note",
                typeFile: "Attach File", textDetails: "Details or Notes:", fileSelectHint: "Click to select Image, PDF, or Video",
                save: "Save", cancel: "Cancel", deleteBtn: "Delete", printBtn: "Print", logout: "Logout", langName: "عربي", prev: "Prev", next: "Next",
                noPatients: "Select a patient to view details", saving: "Saving...", addPatientTitle: "Add Patient", editPatientTitle: "Edit Patient",
                confirmDelete: "Are you sure you want to delete this item?", confirmDeletePatient: "Are you sure you want to delete this patient? This action cannot be undone.", deletePatient: "Delete Patient", loadMore: "Load More", backToList: "Back to List", patientCode: "Code"
            }
        };

        let lang = localStorage.getItem('lang') || 'ar';
        
        function setLang(l) {
            lang = l;
            localStorage.setItem('lang', l);
            document.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr';
            document.body.dir = l === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.lang = l;
            
            document.querySelector('.mobile-back-btn i').className = l === 'ar' ? 'fa-solid fa-arrow-right' : 'fa-solid fa-arrow-left';
            
            // Flip chevron icons
            document.querySelector('#prevBtn i').className = l === 'ar' ? 'fa-solid fa-chevron-right' : 'fa-solid fa-chevron-left';
            document.querySelector('#nextBtn i').className = l === 'ar' ? 'fa-solid fa-chevron-left' : 'fa-solid fa-chevron-right';

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (i18n[lang][key]) el.textContent = i18n[lang][key];
            });
            document.querySelectorAll('[data-i18n-ph]').forEach(el => {
                const key = el.getAttribute('data-i18n-ph');
                if (i18n[lang][key]) el.placeholder = i18n[lang][key];
            });
            
            buildSections();
            if(currentPatient) renderFiles();
        }

        function toggleLang() { setLang(lang === 'ar' ? 'en' : 'ar'); }

        // Data State
        let patients = [];
        let filteredPatients = [];
        let currentPatient = null;
        let patientFiles = [];
        let currentPage = 1;
        const perPage = 15;
        const API_BASE = document.querySelector('meta[name="mobile-api-url"]')?.content || '/api/v1';
        function apiHeaders(extra = {}) {
            const token = localStorage.getItem('api_token');
            return token ? { ...extra, 'Authorization': `Bearer ${token}` } : extra;
        }
        function apiUrl(path) {
            return `${API_BASE}${path.startsWith('/') ? path : `/${path}`}`;
        }

        // Load limits per section to avoid lag
        const displayLimit = 4;
        let sectionLimits = {};

        const sectionsConfig = [
            { id: 'history', i18nKey: 'history', category: 'التاريخ الطبي (Medical history)' },
            { id: 'pre_rad', i18nKey: 'preOp', category: 'أشعة قبل العملية (Pre-op Radiology)' },
            { id: 'investigations', i18nKey: 'investigations', category: 'تحاليل (Investigations)' },
            { id: 'post_rad', i18nKey: 'postOp', category: 'أشعة بعد العملية (Post-op Radiology)' },
            { id: 'op_sheet', i18nKey: 'opSheet', category: 'تفاصيل العملية (Operation sheet)' },
            { id: 'meds', i18nKey: 'meds', category: 'أدوية المتابعة (Follow-up medications)' },
            { id: 'notes', i18nKey: 'notes', category: 'ملاحظات العملية (Operation Notes)' }
        ];

        document.addEventListener('DOMContentLoaded', () => {
            setLang(lang);
            fetchPatients();
        });

        async function fetchPatients() {
            try {
                const res = await fetch(apiUrl('/patients'), { headers: apiHeaders({ 'Accept': 'application/json' }) });
                const data = await res.json();
                patients = data.data || data.patients || [];
                handleSearch();
                setTimeout(restoreState, 100);
            } catch(e) { console.error(e); }
        }

        function handleSearch() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            filteredPatients = patients.filter(p => p.name.toLowerCase().includes(search) || (p.phone && p.phone.includes(search)) || (p.code && p.code.includes(search)));
            currentPage = 1;
            renderPatients();
        }

        function renderPatients() {
            const list = document.getElementById('patientList');
            list.innerHTML = '';
            
            const totalPages = Math.ceil(filteredPatients.length / perPage) || 1;
            if (currentPage > totalPages) currentPage = totalPages;
            
            const start = (currentPage - 1) * perPage;
            const paginated = filteredPatients.slice(start, start + perPage);
            
            paginated.forEach(p => {
                const btn = document.createElement('button');
                btn.className = `patient-btn ${currentPatient && currentPatient.id === p.id ? 'active' : ''}`;
                btn.innerHTML = `<span style="display:flex; justify-content:space-between; align-items:center;"><span>${p.name}</span><span style="font-size: 0.95rem; background: var(--bg); padding: 0.2rem 0.5rem; border-radius: 6px; color: var(--primary); border: 1px solid var(--border);">#${p.code || '---'}</span></span><span class="patient-phone" style="margin-top: 0.3rem;"><i class="fa-solid fa-phone"></i> ${p.phone}</span>`;
                btn.onclick = () => selectPatient(p);
                list.appendChild(btn);
            });

            document.getElementById('pageInfo').textContent = `${currentPage} / ${totalPages}`;
            document.getElementById('prevBtn').disabled = currentPage === 1;
            document.getElementById('nextBtn').disabled = currentPage === totalPages;
        }

        function changePage(dir) { currentPage += dir; renderPatients(); saveState(); }

        async function selectPatient(p) {
            currentPatient = p;
            renderPatients();
            
            document.getElementById('placeholderContent').style.display = 'none';
            document.getElementById('mainContent').style.display = 'block';
            document.querySelector('.sidebar').classList.add('mobile-hidden');
            window.scrollTo(0, 0);
            
            document.querySelector('#lbl_name .val').textContent = p.name;
            document.querySelector('#lbl_code .val').textContent = p.code || '---';
            document.querySelector('#lbl_address .val').textContent = p.address || '-';
            document.querySelector('#lbl_phone .val').textContent = p.phone || '-';
            document.querySelector('#lbl_diagnosis .val').textContent = p.diagnosis || '-';

            // Reset limits
            sectionsConfig.forEach(s => sectionLimits[s.category] = displayLimit);

            try {
                const res = await fetch(apiUrl(`/patients/${p.id}`), { headers: apiHeaders({ 'Accept': 'application/json' }) });
                const data = await res.json();
                const patient = data.data || data;
                patientFiles = patient.files || [];
                renderFiles();
            } catch(e) { console.error(e); }
            saveState();
        }

        function backToSidebar() {
            document.querySelector('.sidebar').classList.remove('mobile-hidden');
            document.getElementById('mainContent').style.display = 'none';
        }

        function openPatientModal(p = null) {
            document.getElementById('patientForm').reset();
            if (p) {
                document.getElementById('patientModalTitle').textContent = i18n[lang].editPatientTitle;
                document.getElementById('patientId').value = p.id;
                document.getElementById('p_name').value = p.name;
                document.getElementById('p_phone').value = p.phone;
                document.getElementById('p_address').value = p.address || '';
                document.getElementById('p_diagnosis').value = p.diagnosis || '';
            } else {
                document.getElementById('patientModalTitle').textContent = i18n[lang].addPatientTitle;
                document.getElementById('patientId').value = '';
            }
            document.getElementById('patientModal').classList.add('active');
        }

        async function savePatient(e) {
            e.preventDefault();
            const id = document.getElementById('patientId').value;
            const payload = {
                name: document.getElementById('p_name').value,
                phone: document.getElementById('p_phone').value,
                address: document.getElementById('p_address').value,
                diagnosis: document.getElementById('p_diagnosis').value,
            };

            const url = id ? apiUrl(`/patients/${id}`) : apiUrl('/patients');
            try {
                const res = await fetch(url, { method: id ? 'PUT' : 'POST', headers: apiHeaders({ 'Content-Type': 'application/json', 'Accept': 'application/json' }), body: JSON.stringify(payload) });
                if (res.ok) {
                    closeModal('patientModal');
                    showToast(id ? 'Patient updated successfully' : 'Patient added successfully', 'success');
                    await fetchPatients();
                    if (id && currentPatient && currentPatient.id == id) selectPatient(patients.find(x => x.id == id));
                    else if (!id) { const saved = await res.json(); const savedPatient = saved.data || saved; selectPatient(patients.find(x => x.id == savedPatient.id) || savedPatient); }
                }
            } catch(e) {
                console.error('[patients] save failed', e);
                showToast('Error saving patient', 'error');
            }
        }

        function buildSections() {
            const container = document.getElementById('sectionsContainer');
            container.innerHTML = '';

            sectionsConfig.forEach(sec => {
                const html = `
                    <div class="section-block">
                        <div class="section-header">
                            <h3 class="section-title">${i18n[lang][sec.i18nKey]}</h3>
                            <button class="btn btn-primary" onclick="openItemModal('${sec.category}')">
                                <i class="fa-solid fa-plus"></i> ${i18n[lang].addEntry}
                            </button>
                        </div>
                        <div class="items-grid" id="grid_${sec.id}"></div>
                        <button class="load-more-btn" id="more_${sec.id}" style="display:none;" onclick="loadMore('${sec.category}')">${i18n[lang].loadMore}</button>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', html);
            });
        }

        // ════════════════════════════════════════════════════════
        // DEDICATED VIEW LOGIC
        // ════════════════════════════════════════════════════════
        let currentDedicatedCategory = null;
        let dedicatedPage = 1;
        let dedicatedPerPage = 12;
        let dedicatedSearchQuery = '';

        function loadMore(category) {
            // Open dedicated view instead of expanding
            openDedicatedView(category);
        }

        function openDedicatedView(category) {
            currentDedicatedCategory = category;
            dedicatedPage = 1;
            dedicatedSearchQuery = '';
            
            const sec = sectionsConfig.find(s => s.category === category);
            const title = sec ? i18n[lang][sec.i18nKey] : category;
            
            document.getElementById('dedicatedTitle').textContent = title;
            document.getElementById('dedicatedSearch').value = '';
            
            // Hide sections, show dedicated view (both inside mainContent)
            document.getElementById('sectionsContainer').style.display = 'none';
            document.getElementById('dedicatedView').style.display = 'block';
            
            renderDedicatedView();
            saveState();
        }

        function closeDedicatedView() {
            document.getElementById('dedicatedView').style.display = 'none';
            document.getElementById('sectionsContainer').style.display = 'block';
            currentDedicatedCategory = null;
            dedicatedSearchQuery = '';
            saveState();
        }

        function handleDedicatedSearch() {
            dedicatedSearchQuery = document.getElementById('dedicatedSearch').value.trim().toLowerCase();
            dedicatedPage = 1;
            
            // Debounce
            if (window._dedicatedSearchTimeout) clearTimeout(window._dedicatedSearchTimeout);
            window._dedicatedSearchTimeout = setTimeout(() => renderDedicatedView(), 300);
        }

        function viewTextItem(id) {
            const file = patientFiles.find(f => f.id === id);
            if (!file) return;
            
            const viewer = document.getElementById('mediaViewer');
            const content = document.getElementById('viewerContent');
            content.innerHTML = `
                <div style="background:var(--surface); color:var(--text); padding:2rem; border-radius:8px; max-width:800px; max-height:90vh; overflow-y:auto; direction:rtl; border:1px solid var(--border);">
                    <div style="border-bottom:2px solid var(--border); padding-bottom:1rem; margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center;">
                        <h2 style="margin:0; color:var(--primary-light);">${file.category}</h2>
                        <span style="color:var(--text-muted);">${file.date}</span>
                    </div>
                    <div style="font-size:1.3rem; line-height:1.8; white-space:pre-wrap; color:var(--text);">${file.desc || ''}</div>
                </div>
            `;
            viewer.style.display = 'flex';
        }

        function renderDedicatedView() {
            if (!currentPatient || !currentDedicatedCategory) return;
            
            const grid = document.getElementById('dedicatedGrid');
            const pagEl = document.getElementById('dedicatedPagination');
            const countEl = document.getElementById('dedicatedCount');
            
            // Filter files by category and search
            let filtered = patientFiles.filter(f => f.category === currentDedicatedCategory);
            
            if (dedicatedSearchQuery) {
                filtered = filtered.filter(f => {
                    const text = (f.desc || '') + ' ' + (f.title || '') + ' ' + (f.date || '');
                    return text.toLowerCase().includes(dedicatedSearchQuery);
                });
            }
            
            const total = filtered.length;
            const totalPages = Math.ceil(total / dedicatedPerPage) || 1;
            
            // Ensure valid page
            if (dedicatedPage > totalPages) dedicatedPage = totalPages;
            if (dedicatedPage < 1) dedicatedPage = 1;
            
            // Slice for pagination
            const start = (dedicatedPage - 1) * dedicatedPerPage;
            const filesToShow = filtered.slice(start, start + dedicatedPerPage);
            
            // Update count
            countEl.textContent = `${total} عنصر`;
            
            // Render grid
            grid.innerHTML = '';
            
            if (filesToShow.length === 0) {
                grid.innerHTML = `
                    <div style="grid-column:1/-1; text-align:center; padding:4rem; color:var(--text-muted);">
                        <i class="fa-solid fa-folder-open" style="font-size:4rem; margin-bottom:1rem; display:block;"></i>
                        <p style="font-size:1.3rem;">${dedicatedSearchQuery ? 'لا توجد نتائج مطابقة للبحث' : 'لا توجد ملفات في هذا القسم'}</p>
                    </div>
                `;
                pagEl.innerHTML = '';
                return;
            }
            
            filesToShow.forEach(file => {
                const card = document.createElement('div');
                card.className = 'item-card';
                
                let mediaHTML = '';
                let isTextOnly = false;
                
                if (file.file_path) {
                    const ext = file.file_path.split('.').pop().toLowerCase();
                    if (['jpg','jpeg','png','gif','webp'].includes(ext) || file.type === 'image') {
                        mediaHTML = `<div class="item-preview-box" onclick="viewMedia('${file.file_path}', 'image')"><img src="${file.file_path}" loading="lazy"></div>`;
                    } else if (ext === 'pdf' || file.type === 'pdf') {
                        mediaHTML = `<div class="item-preview-box" onclick="viewMedia('${file.file_path}', 'pdf')"><i class="fa-solid fa-file-pdf file-icon" style="color: #EF4444;"></i></div>`;
                    } else if (['mp4','webm','ogg'].includes(ext) || file.type === 'video') {
                        mediaHTML = `<div class="item-preview-box" onclick="viewMedia('${file.file_path}', 'video')"><i class="fa-solid fa-circle-play file-icon" style="color: #3B82F6;"></i></div>`;
                    } else {
                        mediaHTML = `<div class="item-preview-box" onclick="window.open('${file.file_path}','_blank')"><i class="fa-solid fa-file file-icon"></i></div>`;
                    }
                } else {
                    // No file - text only
                    isTextOnly = true;
                    mediaHTML = `<div class="item-preview-box" style="background: linear-gradient(135deg, #EFF6FF, #DBEAFE); cursor:pointer;" onclick="viewTextItem(${file.id})"><i class="fa-solid fa-align-right file-icon" style="color: var(--primary);"></i></div>`;
                }
                
                // In dedicated view: show full text
                let textHTML = '';
                if (file.desc) {
                    textHTML = `<div class="text-content">${file.desc}</div>`;
                }
                
                card.innerHTML = `
                    <div class="item-date"><i class="fa-regular fa-calendar"></i> ${file.date}</div>
                    ${mediaHTML}
                    ${textHTML}
                    <div class="card-actions">
                        ${isTextOnly ? `<button class="print-btn" onclick="viewTextItem(${file.id})" style="background:#EFF6FF; color:var(--primary);"><i class="fa-solid fa-eye"></i> عرض النص</button>` : `<button class="print-btn" onclick="printItem(${file.id})"><i class="fa-solid fa-print"></i> ${i18n[lang].printBtn}</button>`}
                        <button class="delete-btn" onclick="confirmDeleteFile(${file.id})"><i class="fa-solid fa-trash"></i> ${i18n[lang].deleteBtn}</button>
                    </div>
                `;
                grid.appendChild(card);
            });
            
            // Render pagination
            if (totalPages > 1) {
                let html = '';
                html += `<button class="btn" style="background:var(--surface); border:1px solid var(--border);" onclick="changeDedicatedPage(${dedicatedPage - 1})" ${dedicatedPage === 1 ? 'disabled' : ''}><i class="fa-solid fa-chevron-right"></i></button>`;
                
                for (let i = 1; i <= totalPages; i++) {
                    if (i === 1 || i === totalPages || (i >= dedicatedPage - 1 && i <= dedicatedPage + 1)) {
                        html += `<button class="btn" style="background:${i === dedicatedPage ? 'var(--primary)' : 'var(--surface)'}; color:${i === dedicatedPage ? 'white' : 'var(--text)'}; border:1px solid var(--border); min-width:40px;" onclick="changeDedicatedPage(${i})">${i}</button>`;
                    } else if (i === dedicatedPage - 2 || i === dedicatedPage + 2) {
                        html += `<span style="padding:0 0.5rem; color:var(--text-muted);">...</span>`;
                    }
                }
                
                html += `<button class="btn" style="background:var(--surface); border:1px solid var(--border);" onclick="changeDedicatedPage(${dedicatedPage + 1})" ${dedicatedPage === totalPages ? 'disabled' : ''}><i class="fa-solid fa-chevron-left"></i></button>`;
                pagEl.innerHTML = html;
            } else {
                pagEl.innerHTML = '';
            }
        }

        function changeDedicatedPage(page) {
            dedicatedPage = page;
            renderDedicatedView();
            document.getElementById('dedicatedView').scrollTo({ top: 0, behavior: 'smooth' });
        }

        function renderFiles() {
            sectionsConfig.forEach(sec => {
                const grid = document.getElementById(`grid_${sec.id}`);
                const moreBtn = document.getElementById(`more_${sec.id}`);
                if(grid) grid.innerHTML = '';
                
                const catFiles = patientFiles.filter(f => f.category === sec.category);
                const limit = sectionLimits[sec.category] || displayLimit;
                
                const filesToShow = catFiles.slice(0, limit);
                
                if (catFiles.length > limit) moreBtn.style.display = 'block';
                else if(moreBtn) moreBtn.style.display = 'none';

                filesToShow.forEach(file => {
                    const card = document.createElement('div');
                    card.className = 'item-card';
                    
                    let mediaHTML = '';
                    let isTextOnly = false;
                    
                    if (file.file_path) {
                        const ext = file.file_path.split('.').pop().toLowerCase();
                        if (['jpg','jpeg','png','gif','webp'].includes(ext) || file.type === 'image') {
                            mediaHTML = `<div class="item-preview-box" onclick="viewMedia('${file.file_path}', 'image')"><img src="${file.file_path}"></div>`;
                        } else if (ext === 'pdf' || file.type === 'pdf') {
                            mediaHTML = `<div class="item-preview-box" onclick="viewMedia('${file.file_path}', 'pdf')"><i class="fa-solid fa-file-pdf file-icon" style="color: #EF4444;"></i></div>`;
                        } else if (['mp4','webm','ogg'].includes(ext) || file.type === 'video') {
                            mediaHTML = `<div class="item-preview-box" onclick="viewMedia('${file.file_path}', 'video')"><i class="fa-solid fa-circle-play file-icon" style="color: #3B82F6;"></i></div>`;
                        } else {
                            mediaHTML = `<div class="item-preview-box" onclick="window.open('${file.file_path}','_blank')"><i class="fa-solid fa-file file-icon"></i></div>`;
                        }
                    } else {
                        // No file - text only
                        isTextOnly = true;
                        mediaHTML = `<div class="item-preview-box" style="background: linear-gradient(135deg, #EFF6FF, #DBEAFE); cursor:pointer;" onclick="viewTextItem(${file.id})"><i class="fa-solid fa-align-right file-icon" style="color: var(--primary);"></i></div>`;
                    }
                    
                    // In overview: show short preview or "View text" button
                    let textHTML = '';
                    if (file.desc) {
                        if (isTextOnly) {
                            // Text only: show short preview
                            const shortText = file.desc.length > 80 ? file.desc.substring(0, 80) + '...' : file.desc;
                            textHTML = `<div class="text-content" style="font-size:1.1rem; color:#475569;">${shortText}</div>`;
                        } else {
                            // Has file: show full text
                            textHTML = `<div class="text-content">${file.desc}</div>`;
                        }
                    }

                    card.innerHTML = `
                        <div class="item-date"><i class="fa-regular fa-calendar"></i> ${file.date}</div>
                        ${mediaHTML}
                        ${textHTML}
                        <div class="card-actions">
                            ${isTextOnly ? `<button class="print-btn" onclick="viewTextItem(${file.id})" style="background:#EFF6FF; color:var(--primary);"><i class="fa-solid fa-eye"></i> عرض</button>` : `<button class="print-btn" onclick="printItem(${file.id})"><i class="fa-solid fa-print"></i> ${i18n[lang].printBtn}</button>`}
                            <button class="delete-btn" onclick="confirmDeleteFile(${file.id})"><i class="fa-solid fa-trash"></i> ${i18n[lang].deleteBtn}</button>
                        </div>
                    `;
                    grid.appendChild(card);
                });
            });
        }

        // Add Item Modal - Two Box UI
        function selectEntryType(type) {
            document.getElementById('selectedType').value = type;
            document.getElementById('card_text').classList.remove('active');
            document.getElementById('card_file').classList.remove('active');
            
            if(type === 'text') {
                document.getElementById('card_text').classList.add('active');
                document.getElementById('fileInputContainer').style.display = 'none';
            } else {
                document.getElementById('card_file').classList.add('active');
                document.getElementById('fileInputContainer').style.display = 'block';
            }
        }

        function openItemModal(category) {
            document.getElementById('itemForm').reset();
            document.getElementById('itemCategory').value = category;
            document.getElementById('fileNameDisplay').textContent = i18n[lang].fileSelectHint;
            selectEntryType('text'); // default
            document.getElementById('itemModal').classList.add('active');
        }

        function updateFileName(input) {
            const display = document.getElementById('fileNameDisplay');
            if (input.files.length > 0) {
                display.textContent = input.files[0].name;
                display.style.color = 'var(--primary)';
            } else {
                display.textContent = i18n[lang].fileSelectHint;
            }
        }

        async function saveItem(e) {
            e.preventDefault();
            if (!currentPatient) return;

            const category = document.getElementById('itemCategory').value;
            const type = document.getElementById('selectedType').value;
            const textDesc = document.getElementById('itemText').value;
            const fileInput = document.getElementById('itemFile');
            const btn = document.getElementById('saveItemBtn');

            const formData = new FormData();
            formData.append('title', category); 
            formData.append('desc', textDesc);
            formData.append('category', category);
            formData.append('date', new Date().toISOString().split('T')[0]);

            if (type === 'file') {
                if (fileInput.files.length === 0) {
                    alert('اختر ملف / Select a file'); return;
                }
                const f = fileInput.files[0];
                formData.append('file', f);
                
                let ft = 'file';
                if(f.type.includes('image')) ft = 'image';
                else if(f.type.includes('pdf')) ft = 'pdf';
                else if(f.type.includes('video')) ft = 'video';
                formData.append('type', ft);
            } else {
                formData.append('type', 'text');
            }

            btn.disabled = true;
            btn.textContent = i18n[lang].saving;

            try {
                const res = await fetch(apiUrl(`/patients/${currentPatient.id}/files`), { method: 'POST', headers: apiHeaders({ 'Accept': 'application/json' }), body: formData });
                if (res.ok) {
                    const response = await res.json();
                    const savedFile = response.data || response;
                    patientFiles.unshift(savedFile);
                    renderFiles();
                    showToast('Item saved successfully', 'success');
                    closeModal('itemModal');
                } else showToast('Error saving', 'error');
            } catch(err) {
                console.error('[patient-files] save failed', err);
                showToast('Error saving', 'error');
            } 
            finally { btn.disabled = false; btn.textContent = i18n[lang].save; }
        }

        let itemToDelete = null;
        let deleteType = null; // 'file' or 'patient'

        function confirmDeleteFile(id) {
            itemToDelete = id;
            deleteType = 'file';
            document.getElementById('confirmMessage').textContent = i18n[lang].confirmDelete;
            document.getElementById('confirmModal').classList.add('active');
        }
        
        function confirmDeletePatient() {
            if (!currentPatient) return;
            deleteType = 'patient';
            document.getElementById('confirmMessage').textContent = i18n[lang].confirmDeletePatient;
            document.getElementById('confirmModal').classList.add('active');
        }

        document.getElementById('confirmBtn').onclick = async function() {
            if (deleteType === 'patient' && currentPatient) {
                const id = currentPatient.id;
                closeModal('confirmModal');
                try {
                    const res = await fetch(apiUrl(`/patients/${id}`), { method: 'DELETE', headers: apiHeaders({ 'Accept': 'application/json' }) });
                    if (res.ok) { 
                        patients = patients.filter(p => p.id !== id);
                        currentPatient = null;
                        patientFiles = [];
                        document.getElementById('mainContent').style.display = 'none';
                        document.getElementById('placeholderContent').style.display = 'flex';
                        document.querySelector('.sidebar').classList.remove('mobile-hidden');
                        handleSearch();
                        showToast('Patient deleted successfully', 'success');
                    } else {
                        showToast('Failed to delete patient', 'error');
                    }
                } catch(e) { showToast('Error deleting patient', 'error'); }
                deleteType = null;
                return;
            }
            
            if (!itemToDelete) return;
            const id = itemToDelete;
            closeModal('confirmModal');
            try {
                const res = await fetch(apiUrl(`/patients/${currentPatient.id}/files/${id}`), { method: 'DELETE', headers: apiHeaders({ 'Accept': 'application/json' }) });
                if (res.ok) { patientFiles = patientFiles.filter(f => f.id !== id); renderFiles(); showToast('Item deleted', 'success'); }
            } catch(e) {
                console.error('[patient-files] delete failed', e);
                showToast('Error deleting item', 'error');
            }
            itemToDelete = null;
        };

        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        // Logout Modal
        function openLogoutModal() {
            document.getElementById('logoutModal').classList.add('active');
        }
        function confirmLogout() {
            document.getElementById('logoutForm').submit();
        }

        // Media Preview
        function viewMedia(src, type) {
            const viewer = document.getElementById('mediaViewer');
            const content = document.getElementById('viewerContent');
            content.innerHTML = '';
            
            if(type === 'image') {
                content.innerHTML = `<img src="${src}">`;
            } else if (type === 'pdf') {
                content.innerHTML = `<iframe src="${src}" style="width: 80vw; height: 90vh; border:none; background: white; border-radius: 8px;"></iframe>`;
            } else if (type === 'video') {
                content.innerHTML = `<video controls autoplay style="width: 80vw; max-height: 90vh;"><source src="${src}"></video>`;
            }
            viewer.style.display = 'flex';
        }

        function closeViewer(e) {
            if (e.target.id === 'mediaViewer' || e.target.classList.contains('close-viewer')) {
                document.getElementById('mediaViewer').style.display = 'none';
                document.getElementById('viewerContent').innerHTML = ''; // stop video
            }
        }

        // Print capability
        function printItem(id) {
            const file = patientFiles.find(f => f.id === id);
            if(!file) return;

            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>طباعة سجل مريض</title>
                    <style>
                        body { font-family: sans-serif; text-align: center; padding: 2rem; direction: rtl; }
                        .header { border-bottom: 2px solid #ccc; padding-bottom: 1rem; margin-bottom: 2rem; }
                        img { max-width: 100%; max-height: 80vh; border: 1px solid #ddd; margin-bottom: 1rem; }
                        h2 { white-space: pre-wrap; line-height: 1.5; }
                        .pdf-link { font-size: 1.5rem; color: blue; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>مريض: ${currentPatient.name}</h1>
                        <p>القسم: ${file.category} | التاريخ: ${file.date}</p>
                    </div>
            `);
            
            if (file.file_path) {
                const ext = file.file_path.split('.').pop().toLowerCase();
                if (['jpg','jpeg','png','gif','webp'].includes(ext) || file.type === 'image') {
                    printWindow.document.write(`<img src="${file.file_path}">`);
                } else {
                    printWindow.document.write(`<p class="pdf-link">مرفق ملف، يرجى فتح الملف لطباعته مباشرة.</p>`);
                }
            }
            if (file.desc) {
                printWindow.document.write(`<h2>${file.desc}</h2>`);
            }
            
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            
            // Wait for images to load before printing
            printWindow.onload = () => {
                setTimeout(() => {
                    printWindow.focus();
                    printWindow.print();
                    // printWindow.close(); // optional
                }, 500);
            };
        }
    
        // ════════════════════════════════════════════════════════
        // THEME SYSTEM
        // ════════════════════════════════════════════════════════
        let theme = localStorage.getItem('theme') || 'light';
        
        function setTheme(t) {
            theme = t;
            localStorage.setItem('theme', t);
            document.documentElement.setAttribute('data-theme', t);
            const icon = document.getElementById('themeIcon');
            if (icon) {
                icon.className = t === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
            }
        }
        
        function toggleTheme() {
            setTheme(theme === 'light' ? 'dark' : 'light');
        }
        
        setTheme(theme);

        // ════════════════════════════════════════════════════════
        // TOAST NOTIFICATIONS
        // ════════════════════════════════════════════════════════
        function showToast(message, type = 'info', duration = 3000) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            let icon = 'fa-circle-info';
            if (type === 'success') icon = 'fa-circle-check';
            if (type === 'error') icon = 'fa-circle-xmark';
            
            toast.innerHTML = `<i class="fa-solid ${icon}"></i> ${message}`;
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'toastOut 0.3s ease forwards';
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }

        // ════════════════════════════════════════════════════════
        // KEYBOARD SHORTCUTS
        // ════════════════════════════════════════════════════════
        let selectedPatientIndex = -1;
        
        document.addEventListener('keydown', (e) => {
            const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
            const ctrl = isMac ? e.metaKey : e.ctrlKey;
            
            // Ctrl/Cmd + K -> Focus search
            if (ctrl && e.key === 'k') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }
            
            // Escape -> Close modals
            if (e.key === 'Escape') {
                const activeModal = document.querySelector('.modal.active');
                if (activeModal) {
                    activeModal.classList.remove('active');
                }
                if (document.getElementById('mediaViewer').style.display === 'flex') {
                    document.getElementById('mediaViewer').style.display = 'none';
                    document.getElementById('viewerContent').innerHTML = '';
                }
            }
            
            // Ctrl/Cmd + N -> New patient
            if (ctrl && e.key === 'n') {
                e.preventDefault();
                openPatientModal();
            }
            
            // Ctrl/Cmd + S -> Save (if modal is open)
            if (ctrl && e.key === 's') {
                e.preventDefault();
                const patientModal = document.getElementById('patientModal');
                const itemModal = document.getElementById('itemModal');
                if (patientModal.classList.contains('active')) {
                    document.getElementById('patientForm').dispatchEvent(new Event('submit'));
                } else if (itemModal.classList.contains('active')) {
                    document.getElementById('itemForm').dispatchEvent(new Event('submit'));
                }
            }
            
            // Ctrl/Cmd + L -> Toggle language
            if (ctrl && e.key === 'l') {
                e.preventDefault();
                toggleLang();
            }
            
            // Arrow navigation in patient list
            if (document.activeElement.id === 'searchInput') {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    const buttons = document.querySelectorAll('.patient-btn');
                    if (buttons.length === 0) return;
                    
                    if (e.key === 'ArrowDown') {
                        selectedPatientIndex = Math.min(selectedPatientIndex + 1, buttons.length - 1);
                    } else {
                        selectedPatientIndex = Math.max(selectedPatientIndex - 1, 0);
                    }
                    
                    buttons.forEach((btn, i) => {
                        btn.style.background = i === selectedPatientIndex ? 'var(--surface-hover)' : '';
                    });
                    buttons[selectedPatientIndex].scrollIntoView({ block: 'nearest' });
                }
                if (e.key === 'Enter' && selectedPatientIndex >= 0) {
                    e.preventDefault();
                    const buttons = document.querySelectorAll('.patient-btn');
                    if (buttons[selectedPatientIndex]) {
                        buttons[selectedPatientIndex].click();
                        selectedPatientIndex = -1;
                    }
                }
            }
        });

        // ════════════════════════════════════════════════════════
        // STATE PERSISTENCE
        // ════════════════════════════════════════════════════════
        function saveState() {
            if (currentPatient) {
                localStorage.setItem('selectedPatientId', currentPatient.id);
            } else {
                localStorage.removeItem('selectedPatientId');
            }
            localStorage.setItem('sidebarPage', currentPage);
            localStorage.setItem('sidebarSearch', document.getElementById('searchInput').value);
            if (currentDedicatedCategory) {
                localStorage.setItem('dedicatedCategory', currentDedicatedCategory);
                localStorage.setItem('dedicatedPage', dedicatedPage);
                localStorage.setItem('dedicatedSearch', dedicatedSearchQuery);
            } else {
                localStorage.removeItem('dedicatedCategory');
                localStorage.removeItem('dedicatedPage');
                localStorage.removeItem('dedicatedSearch');
            }
        }
        
        function restoreState() {
            const savedSearch = localStorage.getItem('sidebarSearch');
            if (savedSearch) {
                document.getElementById('searchInput').value = savedSearch;
            }
            
            const savedPage = localStorage.getItem('sidebarPage');
            if (savedPage) {
                currentPage = parseInt(savedPage);
            }
            
            // Do not auto-restore selected patient - show placeholder by default
            // User must manually select a patient
            currentPatient = null;
            localStorage.removeItem('selectedPatientId');
            localStorage.removeItem('dedicatedCategory');
            localStorage.removeItem('dedicatedPage');
            localStorage.removeItem('dedicatedSearch');
        }
        
        // Save state before page unload
        window.addEventListener('beforeunload', saveState);
        
        </script>
    <!-- Toast Container -->
    <div id="toastContainer"></div>

</body>
</html>
