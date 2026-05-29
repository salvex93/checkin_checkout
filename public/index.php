<?php
// Cache-busting: calcula hash del bundle JS al vuelo.
// El query string ?v=HASH cambia con cada deploy -> el browser descarga siempre la version nueva.
$appJsPath = __DIR__ . '/assets/app.js';
$v = file_exists($appJsPath) ? substr(md5_file($appJsPath), 0, 8) : '1';
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="description" content="Portal de control de jornada laboral - Melius Services">
    <title>Clock System - Melius Services</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/color-thief/2.4.0/color-thief.umd.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { screens: { xs: '375px' } } }
        };
        (function applyInitialTheme() {
            try {
                const stored = localStorage.getItem('melius.theme');
                const preferDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (stored === 'dark' || (!stored && preferDark)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (_) {}
        })();
    </script>

    <script
        src="https://unpkg.com/react@18.3.1/umd/react.production.min.js"
        integrity="sha384-DGyLxAyjq0f9SPpVevD6IgztCFlnMF6oW/XQGmfe+IsZ8TqEiDrcHkMLKI6fiB/Z"
        crossorigin="anonymous"></script>
    <script
        src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js"
        integrity="sha384-gTGxhz21lVGYNMcdJOyq01Edg0jhn/c22nsx0kyqP0TxaV5WVdsSH1fSDUf5YJj1"
        crossorigin="anonymous"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&family=Poppins:wght@600;700;800;900&display=swap');
        :root {
            --melius-cyan: #07d6da;
            --melius-violet: #9909fe;
            --melius-violet-dark: #7a07cc;
            --melius-cyan-dark: #059aa0;
        }
        html, body { background-color: #f8fafc; }
        html.dark, html.dark body { background-color: #0b1220; }
        body { font-family: 'Inter', sans-serif; -webkit-tap-highlight-color: transparent; }
        h1, h2, h3, .font-display { font-family: 'Poppins', 'Inter', sans-serif; }
        .btn-melius { background-image: linear-gradient(135deg, var(--melius-cyan) 0%, var(--melius-violet) 100%); color: #fff; }
        .btn-melius:hover { background-image: linear-gradient(135deg, var(--melius-cyan-dark) 0%, var(--melius-violet-dark) 100%); }
        .text-melius-cyan { color: var(--melius-cyan); }
        .ring-melius { box-shadow: 0 10px 30px -10px rgba(7,214,218,0.45), 0 8px 24px -8px rgba(153,9,254,0.35); }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        html.dark .custom-scrollbar::-webkit-scrollbar-track { background: #1f2937; }
        html.dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #374151; }
        html.dark .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #4b5563; }
        .no-select { user-select: none; -webkit-user-select: none; }
        .safe-top { padding-top: max(env(safe-area-inset-top), 0px); }
        .safe-bottom { padding-bottom: max(env(safe-area-inset-bottom), 0px); }
        @keyframes meliusFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes meliusZoomIn { from { opacity: 0; transform: scale(0.96); } to { opacity: 1; transform: scale(1); } }
        .anim-fade-in { animation: meliusFadeIn 220ms ease-out both; }
        .anim-zoom-in { animation: meliusZoomIn 200ms ease-out both; }
        @media (prefers-reduced-motion: reduce) {
            .anim-fade-in, .anim-zoom-in, .animate-pulse, .animate-spin { animation: none !important; }
            * { transition-duration: 0ms !important; }
        }
        button, [role="button"], select,
        input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):not([type="hidden"]) {
            min-height: 44px;
        }
        @media (hover: none) and (pointer: coarse) {
            input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):not([type="hidden"]),
            select, textarea {
                font-size: 16px !important;
            }
        }
        html.dark input[type="date"]::-webkit-calendar-picker-indicator,
        html.dark input[type="time"]::-webkit-calendar-picker-indicator,
        html.dark input[type="datetime-local"]::-webkit-calendar-picker-indicator {
            filter: invert(1) opacity(0.7);
        }
        .melius-modal-header {
            border-bottom: 1px solid rgb(241 245 249 / 1);
        }
        html.dark .melius-modal-header { border-bottom-color: rgb(30 41 59 / 1); }
        .melius-modal-footer {
            position: sticky;
            bottom: 0;
            z-index: 2;
            background: inherit;
            border-top: 1px solid rgb(241 245 249 / 1);
        }
        html.dark .melius-modal-footer { border-top-color: rgb(30 41 59 / 1); }
        .toast-stack { position: fixed; top: 1rem; right: 1rem; left: 1rem; z-index: 60; display: flex; flex-direction: column; gap: 0.5rem; pointer-events: none; }
        @media (min-width: 640px) { .toast-stack { left: auto; max-width: 24rem; } }
        /* Variables CSS para el panel del tour — se invierten en dark mode */
        :root {
            --tour-bg: #ffffff;
            --tour-border: #e2e8f0;
            --tour-text: #0f172a;
            --tour-muted: #475569;
            --tour-btn-bg: #f8fafc;
        }
        html.dark {
            --tour-bg: #0f172a;
            --tour-border: #1e293b;
            --tour-text: #f1f5f9;
            --tour-muted: #94a3b8;
            --tour-btn-bg: #1e293b;
        }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 transition-colors safe-top safe-bottom">
    <noscript>
        <div style="padding:2rem;text-align:center;font-family:sans-serif">
            Esta aplicación requiere JavaScript habilitado. Por favor actívalo y recarga.
        </div>
    </noscript>
    <div id="root"></div>

    <script src="/assets/app.js?v=<?= $v ?>" defer></script>
</body>
</html>
