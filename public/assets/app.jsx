        const { useState, useEffect, useMemo, useRef, useCallback } = React;

        // =====================================================================
        // API client: wrapper unico con CSRF, credenciales de sesion y manejo
        // de 401 -> logout cliente. NUNCA leemos datos de negocio de localStorage.
        // =====================================================================

        const API_BASE = '/api';
        let CSRF_TOKEN = null;

        async function apiFetch(path, { method = 'GET', body = null } = {}) {
            const headers = { 'Accept': 'application/json' };
            if (body !== null) headers['Content-Type'] = 'application/json';
            if (CSRF_TOKEN && method !== 'GET') headers['X-CSRF-Token'] = CSRF_TOKEN;

            let res;
            try {
                res = await fetch(`${API_BASE}/${path.replace(/^\//, '')}`, {
                    method,
                    credentials: 'same-origin',
                    headers,
                    body: body !== null ? JSON.stringify(body) : null
                });
            } catch (e) {
                throw { code: 'NETWORK_ERROR', message: 'Sin conexión con el servidor.' };
            }

            let payload;
            try { payload = await res.json(); }
            catch (_) { payload = { ok: false, error: { code: 'BAD_RESPONSE', message: 'Respuesta no JSON.' } }; }

            if (!res.ok || !payload.ok) {
                const err = payload.error || { code: 'UNKNOWN', message: `HTTP ${res.status}` };
                // Si la sesion expiro, limpiar estado local. El App detecta via /auth/me.
                if (res.status === 401) err._auth = true;
                throw err;
            }
            return payload.data;
        }

        async function fetchCsrf() {
            const d = await apiFetch('csrf');
            CSRF_TOKEN = d.csrf_token;
        }

        // Wrapper para requests mutantes: si el server responde CSRF_INVALID
        // (token desincronizado por sesion nueva, cache intermediario o cookie
        // que llego despues del primer fetch), refrescamos el token y reintentamos
        // UNA sola vez. Evita el "Token CSRF invalido o ausente" en el primer
        // login de invitados que abren el link desde un correo.
        async function apiPost(path, body) {
            try {
                return await apiFetch(path, { method: 'POST', body });
            } catch (err) {
                if (err && err.code === 'CSRF_INVALID') {
                    await fetchCsrf();
                    return await apiFetch(path, { method: 'POST', body });
                }
                throw err;
            }
        }

        // =====================================================================
        // Sistema de toasts (reemplaza alert() — alert es modal blocking y feo)
        // =====================================================================

        const ToastContext = React.createContext({ push: () => {} });

        const ToastProvider = ({ children }) => {
            const [toasts, setToasts] = useState([]);
            const push = useCallback((type, message) => {
                const id = Date.now() + Math.random();
                setToasts(t => [...t, { id, type, message }]);
                setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 4500);
            }, []);
            return (
                <ToastContext.Provider value={{ push }}>
                    {children}
                    <div className="toast-stack" role="status" aria-live="polite">
                        {toasts.map(t => (
                            <div key={t.id}
                                className={`pointer-events-auto rounded-2xl px-4 py-3 shadow-lg border text-sm font-medium anim-fade-in ${
                                    t.type === 'error' ? 'bg-red-50 dark:bg-red-900/40 border-red-200 dark:border-red-800 text-red-700 dark:text-red-200' :
                                    t.type === 'success' ? 'bg-emerald-50 dark:bg-emerald-900/40 border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-200' :
                                    t.type === 'warning' ? 'bg-amber-50 dark:bg-amber-900/40 border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-200' :
                                    'bg-blue-50 dark:bg-blue-900/40 border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-200'
                                }`}>
                                {t.message}
                            </div>
                        ))}
                    </div>
                </ToastContext.Provider>
            );
        };
        const useToast = () => React.useContext(ToastContext);

        // =====================================================================
        // Iconos (SVG inline para evitar dependencias)
        // =====================================================================

        const Icon = ({ name, size = 24, className = "" }) => {
            const icons = {
                Clock: <path d="M12 6v6l4 2"/>,
                LogIn: <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/>,
                LogOut: <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>,
                History: (<><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/></>),
                ShieldCheck: (<><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></>),
                FileText: (<><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></>),
                ArrowLeftRight: <path d="M8 3 4 7l4 4M4 7h16M16 21l4-4-4-4M20 17H4"/>,
                Check: <path d="M20 6 9 17l-5-5"/>,
                X: <path d="M18 6 6 18M6 6l12 12"/>,
                Mail: (<><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></>),
                ArrowDown: <path d="M12 5v14M19 12l-7 7-7-7"/>,
                Lock: (<><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></>),
                Sun: (<><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></>),
                Moon: <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>,
                Hourglass: <path d="M5 22h14M5 2h14M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/>,
                AlertTriangle: (<><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></>),
                Spinner: <circle cx="12" cy="12" r="10" strokeDasharray="40" strokeDashoffset="10"/>,
                Home: <path d="M3 12 12 3l9 9M5 10v10h14V10"/>,
                Users: (<><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></>),
                MoreHorizontal: (<><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></>),
                Building: (<><rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4M8 6h.01M16 6h.01M12 6h.01M8 10h.01M16 10h.01M12 10h.01M8 14h.01M16 14h.01M12 14h.01"/></>),
                Tag: (<><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/></>),
                ChevronDown: <path d="m6 9 6 6 6-6"/>,
                Bell: (<><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></>),
                User: (<><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></>),
                CalendarDays: (<><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/></>),
                Plane: <path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>
            };
            return (
                <svg xmlns="http://www.w3.org/2000/svg" width={size} height={size} viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"
                    className={className + (name === 'Spinner' ? ' animate-spin' : '')} aria-hidden="true" focusable="false">
                    {icons[name] || null}
                </svg>
            );
        };

        const ThemeToggle = ({ theme, onToggle }) => (
            <button type="button" onClick={onToggle}
                aria-label={theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'}
                className="p-3 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500">
                <Icon name={theme === 'dark' ? 'Sun' : 'Moon'} size={18} />
            </button>
        );

        // =====================================================================
        // Modal accesible (ESC, click fuera, role=dialog, foco inicial)
        // =====================================================================

        // Modal con layout flex-col responsivo. Por defecto el contenedor scrollea internamente
        // (compatible con el comportamiento previo) y el aria-label viene de `title`.
        // Si se pasa `showHeader`, se agrega un header sticky con el titulo visible y boton de cierre.
        const Modal = ({ open, onClose, title, children, maxWidth = 'max-w-md', dismissible = true, showHeader = false }) => {
            const dialogRef = useRef(null);
            useEffect(() => {
                if (!open) return;
                const onKey = (e) => { if (e.key === 'Escape' && dismissible) onClose(); };
                document.addEventListener('keydown', onKey);
                const prevOverflow = document.body.style.overflow;
                document.body.style.overflow = 'hidden';
                const t = setTimeout(() => {
                    const focusable = dialogRef.current?.querySelector('input, button, select, textarea, [tabindex]:not([tabindex="-1"])');
                    focusable?.focus();
                }, 30);
                return () => {
                    document.removeEventListener('keydown', onKey);
                    clearTimeout(t);
                    document.body.style.overflow = prevOverflow;
                };
            }, [open, onClose, dismissible]);
            if (!open) return null;
            const isDark = document.documentElement.classList.contains('dark');
            const bg = isDark ? '#0f172a' : '#ffffff';
            const borderColor = isDark ? '#1e293b' : '#e2e8f0';
            // El overlay hace overflow-y:auto para que el modal sea siempre accesible
            // sin importar su altura o el tamaño de la pantalla. El modal usa margin:auto
            // para centrarse cuando hay espacio, y queda en el top con padding cuando no.
            const overlayStyle = {
                position: 'fixed', top: 0, left: 0, right: 0, bottom: 0,
                zIndex: 50, overflowY: 'auto', overflowX: 'hidden',
                backgroundColor: 'rgba(15,23,42,0.75)', backdropFilter: 'blur(6px)',
                WebkitBackdropFilter: 'blur(6px)',
                display: 'flex', alignItems: 'flex-start', justifyContent: 'center',
                padding: '24px 16px', boxSizing: 'border-box',
            };
            // maxWidth resuelto desde la prop Tailwind a valor CSS real
            const widthMap = {
                'max-w-sm': '384px', 'max-w-md': '448px', 'max-w-lg': '512px',
                'max-w-xl': '576px', 'max-w-2xl': '672px', 'max-w-3xl': '768px',
                'max-w-4xl': '896px',
            };
            const resolvedMaxWidth = widthMap[maxWidth] || '448px';
            if (showHeader) {
                return (
                    <div style={overlayStyle}
                        onMouseDown={(e) => { if (dismissible && e.target === e.currentTarget) onClose(); }}
                        role="presentation">
                        <div ref={dialogRef} role="dialog" aria-modal="true" aria-label={title}
                            className="anim-zoom-in"
                            style={{
                                background: bg, border: `1px solid ${borderColor}`,
                                borderRadius: '16px', boxShadow: '0 20px 60px rgba(0,0,0,0.45)',
                                width: '100%', maxWidth: resolvedMaxWidth,
                                margin: 'auto 0',
                                display: 'flex', flexDirection: 'column',
                                flexShrink: 0,
                            }}>
                            <div style={{
                                flexShrink: 0, padding: '18px 24px',
                                borderBottom: `1px solid ${borderColor}`,
                                display: 'flex', alignItems: 'center',
                                justifyContent: 'space-between', gap: '12px',
                                borderRadius: '16px 16px 0 0',
                                background: bg,
                            }}>
                                <h2 style={{
                                    margin: 0, fontSize: '18px', fontWeight: 900,
                                    fontFamily: 'Poppins,Inter,sans-serif',
                                    color: isDark ? '#f1f5f9' : '#0f172a',
                                    overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
                                }}>{title}</h2>
                                {dismissible && (
                                    <button type="button" onClick={onClose} aria-label="Cerrar"
                                        style={{
                                            flexShrink: 0, width: '36px', height: '36px', minHeight: '36px',
                                            borderRadius: '50%', border: 'none', cursor: 'pointer',
                                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                                            background: isDark ? '#1e293b' : '#f1f5f9',
                                            color: isDark ? '#94a3b8' : '#64748b',
                                        }}>
                                        <Icon name="X" size={16} />
                                    </button>
                                )}
                            </div>
                            <div className="custom-scrollbar"
                                style={{ padding: '24px', overscrollBehavior: 'contain', WebkitOverflowScrolling: 'touch' }}>
                                {children}
                            </div>
                        </div>
                    </div>
                );
            }
            return (
                <div style={overlayStyle}
                    onMouseDown={(e) => { if (dismissible && e.target === e.currentTarget) onClose(); }}
                    role="presentation">
                    <div ref={dialogRef} role="dialog" aria-modal="true" aria-label={title}
                        className="custom-scrollbar anim-zoom-in"
                        style={{
                            background: bg, border: `1px solid ${borderColor}`,
                            borderRadius: '16px', boxShadow: '0 20px 60px rgba(0,0,0,0.45)',
                            width: '100%', maxWidth: resolvedMaxWidth,
                            margin: 'auto 0',
                            padding: '24px', boxSizing: 'border-box',
                            flexShrink: 0,
                            overscrollBehavior: 'contain', WebkitOverflowScrolling: 'touch',
                        }}>
                        {children}
                    </div>
                </div>
            );
        };

        // =====================================================================
        // Select estandar reutilizable: mismo borde, radius, focus state, chevron
        // SVG custom (elimina chevron nativo del SO via appearance:none). Cumple
        // touch target minimo 44px en movil.
        // =====================================================================
        const Select = React.forwardRef(({ value, onChange, children, className = '', size = 'md', disabled, ...rest }, ref) => {
            const sizes = {
                sm: 'px-3 py-2 text-sm pr-9',
                md: 'px-4 py-3 text-sm pr-10 min-h-[44px]',
                lg: 'px-4 py-3.5 text-base pr-10 min-h-[48px]',
            };
            return (
                <div className={`relative ${className}`}>
                    <select
                        ref={ref}
                        value={value}
                        onChange={onChange}
                        disabled={disabled}
                        className={`appearance-none w-full ${sizes[size] || sizes.md} rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-700 dark:text-slate-200 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 disabled:opacity-50 disabled:cursor-not-allowed transition-all`}
                        {...rest}
                    >
                        {children}
                    </select>
                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </span>
                </div>
            );
        });

        // =====================================================================
        // Componentes de loading / error / vacio (estados explicitos — Regla
        // tech-lead-frontend: ningun estado puede ser silencioso)
        // =====================================================================

        const LoadingScreen = () => (
            <div className="min-h-screen flex flex-col items-center justify-center gap-4 text-slate-400 dark:text-slate-500">
                <Icon name="Spinner" size={32} />
                <p className="text-xs uppercase tracking-widest font-bold">Cargando</p>
            </div>
        );

        const ErrorState = ({ message, onRetry }) => (
            <div className="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-3xl p-6 text-center">
                <p className="text-red-700 dark:text-red-300 font-bold mb-3">{message}</p>
                {onRetry && (
                    <button onClick={onRetry} className="text-xs font-black uppercase tracking-widest text-red-600 dark:text-red-300 underline">
                        Reintentar
                    </button>
                )}
            </div>
        );

        const EmptyState = ({ message }) => (
            <div className="text-center py-12 sm:py-20 text-slate-500 dark:text-slate-400 italic font-medium">
                {message}
            </div>
        );

        // =====================================================================
        // Tutorial interactivo (tour guiado)
        // Selector via data-tour="key". Posiciona un tooltip junto al elemento
        // con scroll automatico. Persiste finalizacion en localStorage por rol,
        // pero permite reiniciarlo desde el menu de usuario.
        // =====================================================================

        const TOUR_STORAGE_KEY = 'melius.tour.v1.completed';

        const TOUR_STEPS_USER = [
            { sel: 'header-user', title: 'Tu identidad', body: 'Aquí ves tu nombre, la marca y la empresa a la que estás asignado. Si la empresa no corresponde, solicita el cambio desde el enlace inferior. Solo puedes pedir cambios entre empresas de la misma marca.' },
            { sel: 'btn-clockin', title: 'Marcar entrada', body: 'Inicia tu jornada con un solo toque. El sistema registra la hora exacta y tu zona horaria. Solo puedes marcar entrada una vez al día.' },
            { sel: 'btn-clockout', title: 'Marcar salida', body: 'Cierra tu jornada al terminar. Si olvidaste marcar el día anterior y entras antes de las 06:00, el sistema cerrará automáticamente la jornada previa a las 18:00.' },
            { sel: 'btn-vacation', title: 'Vacaciones', body: 'Solicita un rango de días de vacaciones con motivo opcional. La solicitud queda pendiente hasta que el administrador la apruebe o la rechace. No puedes solapar fechas con solicitudes activas.' },
            { sel: 'btn-history', title: 'Tu historial', body: 'Consulta todos tus registros previos con horas trabajadas y motivos de cierre. Puedes exportar tu historial a CSV cuando lo necesites.' },
            { sel: 'user-menu', title: 'Tu menú de usuario', body: 'Desde aquí cambias entre tema claro y oscuro, repites este tutorial cuando quieras o cierras sesión. También verás tu correo y nombre de la sesión activa.' },
        ];

        const TOUR_STEPS_ADMIN = [
            { sel: 'admin-header', title: 'Panel administrativo', body: 'Aquí gestionas a tu equipo: consultores, empresas, solicitudes pendientes y reportes. Tu vista depende de tu rol (admin o super admin).' },
            { sel: 'admin-tab-dashboard', title: 'Dashboard', body: 'Resumen ejecutivo del día: horas trabajadas, días de vacaciones aprobados, solicitudes pendientes, consultores activos y registros que requieren tu atención.' },
            { sel: 'admin-tab-records', title: 'Registros', body: 'Todos los marcajes del equipo. Filtra por consultor, fecha, empresa o estatus. Exporta a CSV para reportes externos.' },
            { sel: 'admin-tab-agents', title: 'Consultores', body: 'Invita consultores por correo (individual o por CSV masivo), gestiona sus perfiles, reenvía invitaciones pendientes y desactiva accesos cuando salgan del equipo.' },
            { sel: 'admin-tab-requests', title: 'Solicitudes', body: 'Aprueba o rechaza cambios de empresa y solicitudes de vacaciones de los consultores. Las solicitudes aprobadas se contabilizan automáticamente en los reportes.' },
            { sel: 'admin-user-menu', title: 'Tu menú de usuario', body: 'Desde aquí vuelves a tu checador personal, alternas el tema, repites este tutorial o cierras sesión.' },
        ];

        function getTourSeen(viewKey) {
            try {
                const raw = localStorage.getItem(TOUR_STORAGE_KEY);
                if (!raw) return false;
                const seen = JSON.parse(raw);
                return seen[viewKey] === true;
            } catch (_) { return false; }
        }

        function markTourSeen(viewKey) {
            try {
                const raw = localStorage.getItem(TOUR_STORAGE_KEY);
                const seen = raw ? JSON.parse(raw) : {};
                seen[viewKey] = true;
                localStorage.setItem(TOUR_STORAGE_KEY, JSON.stringify(seen));
            } catch (_) {}
        }

        const TourTooltip = ({ steps, onClose }) => {
            const [idx, setIdx] = useState(0);
            const [highlightBox, setHighlightBox] = useState(null);

            useEffect(() => {
                let raf, timer;
                const step = steps[idx];
                if (!step) { setHighlightBox(null); return; }
                const el = document.querySelector(`[data-tour="${step.sel}"]`);
                if (!el) { setHighlightBox(null); return; }
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                timer = setTimeout(() => {
                    raf = requestAnimationFrame(() => {
                        const r = el.getBoundingClientRect();
                        const vw = window.innerWidth;
                        const vh = window.innerHeight;
                        const visible = r.top < vh && r.bottom > 0 && r.left < vw && r.right > 0;
                        setHighlightBox(visible ? { top: r.top, left: r.left, width: r.width, height: r.height } : null);
                    });
                }, 350);
                const onResize = () => {
                    if (!el) return;
                    const r = el.getBoundingClientRect();
                    setHighlightBox({ top: r.top, left: r.left, width: r.width, height: r.height });
                };
                window.addEventListener('resize', onResize);
                return () => {
                    if (raf) cancelAnimationFrame(raf);
                    if (timer) clearTimeout(timer);
                    window.removeEventListener('resize', onResize);
                };
            }, [idx, steps]);

            const step = steps[idx];
            if (!step) return null;
            const total = steps.length;
            const isLast = idx === total - 1;

            return (
                <div style={{ position: 'fixed', inset: 0, zIndex: 60, pointerEvents: 'none' }}>
                    {/* Fondo oscuro clickeable para cerrar */}
                    <div style={{ position: 'absolute', inset: 0, background: 'rgba(15,23,42,0.72)', pointerEvents: 'auto' }}
                        onClick={onClose} />
                    {/* Recuadro highlight — position:fixed para coincidir con getBoundingClientRect en mobile */}
                    {highlightBox && (
                        <div style={{
                            position: 'fixed', pointerEvents: 'none',
                            top: highlightBox.top - 6, left: highlightBox.left - 6,
                            width: highlightBox.width + 12, height: highlightBox.height + 12,
                            borderRadius: '14px',
                            boxShadow: '0 0 0 4px #07d6da, 0 0 0 8px rgba(7,214,218,0.25)',
                            zIndex: 61,
                        }} />
                    )}
                    {/* Panel fijo en la parte inferior — siempre dentro del viewport */}
                    <div style={{
                        position: 'absolute', bottom: 0, left: 0, right: 0,
                        pointerEvents: 'auto',
                        padding: '12px 16px 20px',
                        background: 'transparent',
                        display: 'flex', justifyContent: 'center',
                    }}>
                        <div role="dialog" aria-label={step.title}
                            className="anim-zoom-in"
                            style={{
                                width: '100%', maxWidth: '480px',
                                background: 'var(--tour-bg, #fff)',
                                border: '1px solid var(--tour-border, #e2e8f0)',
                                borderRadius: '16px',
                                boxShadow: '0 -4px 32px rgba(0,0,0,0.18), 0 2px 8px rgba(0,0,0,0.08)',
                                padding: '20px',
                                boxSizing: 'border-box',
                            }}>
                            {/* Barra de progreso */}
                            <div style={{ display: 'flex', gap: '4px', marginBottom: '16px' }}>
                                {steps.map((_, i) => (
                                    <div key={i} style={{
                                        flex: 1, height: '3px', borderRadius: '99px',
                                        background: i <= idx ? '#07d6da' : 'rgba(148,163,184,0.3)',
                                        transition: 'background 0.3s',
                                    }} />
                                ))}
                            </div>
                            <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '12px', marginBottom: '8px' }}>
                                <span style={{ fontSize: '11px', fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#07d6da' }}>
                                    Paso {idx + 1} de {total}
                                </span>
                                <button onClick={onClose} aria-label="Cerrar tutorial"
                                    style={{ flexShrink: 0, background: 'none', border: 'none', cursor: 'pointer', padding: '2px', color: '#94a3b8', minHeight: 'auto', height: 'auto' }}>
                                    <Icon name="X" size={18} />
                                </button>
                            </div>
                            <h3 style={{ margin: '0 0 8px', fontSize: '18px', fontWeight: 900, fontFamily: 'Poppins,Inter,sans-serif', color: 'var(--tour-text, #0f172a)', lineHeight: 1.3 }}>
                                {step.title}
                            </h3>
                            <p style={{ margin: '0 0 20px', fontSize: '14px', lineHeight: 1.6, color: 'var(--tour-muted, #475569)' }}>
                                {step.body}
                            </p>
                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px' }}>
                                <button onClick={onClose}
                                    style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: '12px', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', color: '#94a3b8', padding: '8px 0', minHeight: 'auto' }}>
                                    Saltar
                                </button>
                                <div style={{ display: 'flex', gap: '8px' }}>
                                    {idx > 0 && (
                                        <button onClick={() => setIdx(i => i - 1)}
                                            style={{ padding: '10px 20px', borderRadius: '10px', border: '1px solid #e2e8f0', background: 'var(--tour-btn-bg, #f8fafc)', color: 'var(--tour-text, #0f172a)', fontWeight: 700, fontSize: '13px', cursor: 'pointer', minHeight: '44px' }}>
                                            Atrás
                                        </button>
                                    )}
                                    <button onClick={() => isLast ? onClose() : setIdx(i => i + 1)}
                                        className="btn-melius"
                                        style={{ padding: '10px 24px', borderRadius: '10px', border: 'none', fontWeight: 800, fontSize: '13px', cursor: 'pointer', minHeight: '44px' }}>
                                        {isLast ? 'Terminar' : 'Siguiente'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            );
        };

        // =====================================================================
        // App principal
        // =====================================================================

        // =====================================================================
        // Branding context: carga /api/branding al iniciar la app y permite
        // refresh tras guardar cambios. Se resuelve por cascada con la marca y
        // overrides cuando el usuario esta logueado (en useEffect de App).
        // =====================================================================

        const DEFAULT_BRANDING = {
            product_name: 'Melius Clockin',
            logo_url: null,
            primary_color: '#07d6da',
            secondary_color: '#9909fe',
        };

        const BrandingContext = React.createContext({
            branding: DEFAULT_BRANDING,
            tenantBranding: DEFAULT_BRANDING,
            setTenantBranding: () => {},
            refreshBranding: async () => {},
        });

        const useBranding = () => React.useContext(BrandingContext);

        const BrandingProvider = ({ children }) => {
            const [tenantBranding, setTenantBranding] = useState(DEFAULT_BRANDING);
            const refreshBranding = useCallback(async () => {
                try {
                    const d = await apiFetch('branding');
                    if (d?.branding) setTenantBranding({ ...DEFAULT_BRANDING, ...d.branding });
                } catch (_) { /* publico: si falla, defaults */ }
            }, []);
            useEffect(() => { refreshBranding(); }, [refreshBranding]);

            return (
                <BrandingContext.Provider value={{ branding: tenantBranding, tenantBranding, setTenantBranding, refreshBranding }}>
                    {children}
                </BrandingContext.Provider>
            );
        };

        // Resuelve el branding efectivo para un usuario: empresa override > marca > tenant.
        // Acepta currentUser (con campos brand_*) y un companyBranding opcional.
        const resolveEffectiveBranding = (tenant, currentUser, companyOverride) => {
            const co = companyOverride || {};
            const u = currentUser || {};
            return {
                product_name: tenant.product_name || DEFAULT_BRANDING.product_name,
                logo_url: co.branding_logo_url || u.brand_logo_url || tenant.logo_url || null,
                primary_color: co.branding_primary || u.brand_primary || tenant.primary_color || DEFAULT_BRANDING.primary_color,
                secondary_color: co.branding_secondary || u.brand_secondary || tenant.secondary_color || DEFAULT_BRANDING.secondary_color,
            };
        };

        // Persistencia ligera de navegacion entre recargas.
        // Solo guardamos la vista raiz (dashboard|admin-panel) y la tab activa del admin.
        // No persistimos login/loading/change-password — esos vienen del estado de sesion.
        const NAV_STORAGE_KEY = 'melius.nav.v1';
        const RESUMABLE_VIEWS = new Set(['dashboard', 'admin-panel']);
        function readNavState() {
            try {
                const raw = localStorage.getItem(NAV_STORAGE_KEY);
                if (!raw) return {};
                const obj = JSON.parse(raw);
                return obj && typeof obj === 'object' ? obj : {};
            } catch (_) { return {}; }
        }
        function writeNavState(patch) {
            try {
                const next = { ...readNavState(), ...patch };
                localStorage.setItem(NAV_STORAGE_KEY, JSON.stringify(next));
            } catch (_) {}
        }

        const App = () => {
            const { push: toast } = useToast();
            // loading | login | forgot-password | reset-password | change-password | dashboard | admin-panel
            const [view, setView] = useState('loading');
            const [resetToken, setResetToken] = useState(null);
            const [currentUser, setCurrentUser] = useState(null);
            const [theme, setTheme] = useState(() => (document.documentElement.classList.contains('dark') ? 'dark' : 'light'));
            const [companies, setCompanies] = useState([]);
            const [submitting, setSubmitting] = useState(false);
            // tourKey: null | 'user' | 'admin'. Forzar = ignorar localStorage (replay desde menu).
            const [tourKey, setTourKey] = useState(null);
            // Modal de consulta T&C (lectura libre, no bloqueante).
            const [termsViewerOpen, setTermsViewerOpen] = useState(false);

            const startTour = useCallback((key) => setTourKey(key), []);
            const closeTour = useCallback(() => {
                if (tourKey) markTourSeen(tourKey);
                setTourKey(null);
            }, [tourKey]);

            // Persistir vista raiz para que al recargar caigas en el mismo sitio.
            useEffect(() => {
                if (RESUMABLE_VIEWS.has(view)) writeNavState({ view });
                else if (view === 'login') writeNavState({ view: null });
            }, [view]);

            // Auto-arrancar tour al entrar a dashboard/admin la primera vez.
            useEffect(() => {
                if (!currentUser) return;
                if (view === 'dashboard' && !getTourSeen('user')) {
                    const t = setTimeout(() => setTourKey('user'), 600);
                    return () => clearTimeout(t);
                }
                if (view === 'admin-panel' && !getTourSeen('admin')) {
                    const t = setTimeout(() => setTourKey('admin'), 600);
                    return () => clearTimeout(t);
                }
            }, [view, currentUser]);

            const toggleTheme = useCallback(() => {
                setTheme(prev => {
                    const next = prev === 'dark' ? 'light' : 'dark';
                    document.documentElement.classList.toggle('dark', next === 'dark');
                    try { localStorage.setItem('melius.theme', next); } catch (_) {}
                    return next;
                });
            }, []);

            // Hidratacion: si la URL trae reset_token vamos directo a esa vista.
            useEffect(() => {
                const url = new URL(window.location.href);
                const tok = url.searchParams.get('reset_token');
                (async () => {
                    try {
                        await fetchCsrf();
                        if (tok && /^[a-f0-9]{64}$/.test(tok)) {
                            setResetToken(tok);
                            setView('reset-password');
                            return;
                        }
                        try {
                            const me = await apiFetch('auth/me');
                            CSRF_TOKEN = me.csrf_token || CSRF_TOKEN;
                            setCurrentUser(me.user);
                            if (me.user.must_change_password) {
                                setView('change-password');
                            } else if (me.user.terms_pending) {
                                setView('accept-terms');
                            } else {
                                const saved = readNavState().view;
                                const canResumeAdmin = me.user.role === 'admin' || me.user.role === 'super_admin';
                                if (saved === 'admin-panel' && canResumeAdmin) setView('admin-panel');
                                else if (saved === 'dashboard') setView('dashboard');
                                else setView('dashboard');
                            }
                        } catch (e) {
                            if (e._auth) setView('login'); else { toast('error', e.message); setView('login'); }
                        }
                    } catch (e) {
                        toast('error', e.message || 'Error al iniciar.');
                        setView('login');
                    }
                })();
            }, []);

            // Cargar empresas cuando se necesite (register / change-company)
            const loadCompanies = useCallback(async () => {
                try {
                    const d = await apiFetch('companies');
                    setCompanies(d.companies || []);
                } catch (e) { toast('error', e.message); }
            }, [toast]);

            const handleLogin = async (e) => {
                e.preventDefault();
                if (submitting) return;
                const data = new FormData(e.target);
                setSubmitting(true);
                try {
                    const d = await apiPost('auth/login', { email: data.get('email'), password: data.get('password') });
                    CSRF_TOKEN = d.csrf_token || CSRF_TOKEN;
                    setCurrentUser(d.user);
                    if (d.user.must_change_password) {
                        setView('change-password');
                    } else if (d.user.terms_pending) {
                        setView('accept-terms');
                    } else {
                        setView('dashboard');
                    }
                    toast('success', `Bienvenido, ${d.user.name}`);
                } catch (err) {
                    toast('error', err.message || 'No se pudo iniciar sesión.');
                } finally { setSubmitting(false); }
            };

            const handleLogout = async () => {
                try { await apiFetch('auth/logout', { method: 'POST' }); }
                catch (_) {}
                setCurrentUser(null);
                CSRF_TOKEN = null;
                await fetchCsrf();
                setView('login');
            };

            // === Routing ===
            if (view === 'loading') return <LoadingScreen />;

            return (
                <div className="min-h-screen flex flex-col items-center justify-center py-6 sm:py-10 px-4">
                    {view === 'login' && (
                        <LoginCard
                            onSubmit={handleLogin}
                            submitting={submitting}
                            onGoForgot={() => setView('forgot-password')}
                            theme={theme}
                            onToggleTheme={toggleTheme}
                        />
                    )}

                    {view === 'forgot-password' && (
                        <ForgotPasswordCard
                            onBack={() => setView('login')}
                            theme={theme}
                            onToggleTheme={toggleTheme}
                        />
                    )}

                    {view === 'reset-password' && (
                        <ResetPasswordCard
                            token={resetToken}
                            onDone={() => {
                                // Limpia el query param y vuelve a login.
                                window.history.replaceState({}, '', window.location.pathname);
                                setResetToken(null);
                                setView('login');
                            }}
                            theme={theme}
                            onToggleTheme={toggleTheme}
                        />
                    )}

                    {view === 'accept-terms' && currentUser && (
                        <AcceptTermsCard
                            currentUser={currentUser}
                            onAccepted={async () => {
                                try {
                                    const me = await apiFetch('auth/me');
                                    CSRF_TOKEN = me.csrf_token || CSRF_TOKEN;
                                    setCurrentUser(me.user);
                                    if (me.user.must_change_password) setView('change-password');
                                    else if (me.user.terms_pending) setView('accept-terms');
                                    else setView('dashboard');
                                } catch (e) {
                                    if (e && e._auth) setView('login');
                                    else { toast('error', e.message || 'No se pudo continuar.'); setView('login'); }
                                }
                            }}
                            onLogout={handleLogout}
                            theme={theme}
                            onToggleTheme={toggleTheme}
                        />
                    )}

                    {view === 'change-password' && currentUser && (
                        <ChangePasswordCard
                            currentUser={currentUser}
                            onDone={async () => {
                                try {
                                    const me = await apiFetch('auth/me');
                                    CSRF_TOKEN = me.csrf_token || CSRF_TOKEN;
                                    setCurrentUser(me.user);
                                    setView(me.user.terms_pending ? 'accept-terms' : 'dashboard');
                                } catch (_) { setView('login'); }
                            }}
                            onLogout={handleLogout}
                            theme={theme}
                            onToggleTheme={toggleTheme}
                        />
                    )}

                    {view === 'dashboard' && currentUser && (
                        <UserDashboard
                            currentUser={currentUser}
                            onLogout={handleLogout}
                            theme={theme}
                            onToggleTheme={toggleTheme}
                            companies={companies}
                            loadCompanies={loadCompanies}
                            onGoAdmin={() => setView('admin-panel')}
                            onStartTour={() => startTour('user')}
                        />
                    )}

                    {view === 'admin-panel' && currentUser && (
                        <AdminPanel
                            currentUser={currentUser}
                            onLogout={handleLogout}
                            theme={theme}
                            onToggleTheme={toggleTheme}
                            onGoDashboard={() => setView('dashboard')}
                            onStartTour={() => startTour('admin')}
                        />
                    )}

                    {tourKey === 'user' && <TourTooltip steps={TOUR_STEPS_USER} onClose={closeTour} />}
                    {tourKey === 'admin' && <TourTooltip steps={TOUR_STEPS_ADMIN} onClose={closeTour} />}

                    <TermsViewerModal open={termsViewerOpen} onClose={() => setTermsViewerOpen(false)} />

                    <footer className="mt-8 sm:mt-12 flex flex-col items-center gap-2 text-slate-300 dark:text-slate-600 font-bold text-[10px] uppercase tracking-[0.5em] sm:tracking-[0.8em]">
                        <span>Melius Services · Infrastructure</span>
                        <button onClick={() => setTermsViewerOpen(true)}
                            className="text-slate-400 dark:text-slate-500 hover:text-blue-500 dark:hover:text-blue-300 transition-colors tracking-widest text-[9px] sm:text-[10px]">
                            Terminos y Privacidad
                        </button>
                    </footer>
                </div>
            );
        };

        // =====================================================================
        // Vistas
        // =====================================================================

        // Modal de consulta solo-lectura de los Terminos y el Aviso de Privacidad.
        // Carga la version activa via GET /terms/current (endpoint publico, no requiere login).
        // No tiene checkbox ni accion de aceptar — es para consulta libre desde el footer.
        const TermsViewerModal = ({ open, onClose }) => {
            const { push: toast } = useToast();
            const [terms, setTerms] = useState(null);
            const [loading, setLoading] = useState(false);

            useEffect(() => {
                if (!open) return;
                let alive = true;
                setLoading(true);
                (async () => {
                    try {
                        const d = await apiFetch('terms/current');
                        if (alive) setTerms(d.terms);
                    } catch (e) {
                        if (alive) toast('error', e.message || 'No se pudieron cargar los terminos.');
                    } finally { if (alive) setLoading(false); }
                })();
                return () => { alive = false; };
            }, [open]);

            useEffect(() => {
                if (!open) return;
                const onKey = (e) => { if (e.key === 'Escape') onClose(); };
                document.addEventListener('keydown', onKey);
                return () => document.removeEventListener('keydown', onKey);
            }, [open, onClose]);

            if (!open) return null;
            return (
                <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
                     onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}>
                    <div className="max-w-3xl w-full max-h-[90vh] bg-white dark:bg-slate-900 rounded-3xl shadow-2xl border border-slate-100 dark:border-slate-800 flex flex-col">
                        <div className="flex items-center justify-between p-5 border-b border-slate-200 dark:border-slate-700">
                            <h2 className="text-xl sm:text-2xl font-black text-slate-800 dark:text-slate-100 font-display">
                                {terms?.title || 'Terminos y Privacidad'}
                                {terms?.version && (
                                    <span className="ml-2 text-xs font-bold text-slate-400 tracking-widest uppercase">v{terms.version}</span>
                                )}
                            </h2>
                            <button onClick={onClose}
                                aria-label="Cerrar"
                                className="w-9 h-9 rounded-full bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 flex items-center justify-center">
                                <Icon name="X" size={18} />
                            </button>
                        </div>
                        <div className="flex-1 overflow-y-auto p-6">
                            {loading ? (
                                <p className="text-slate-500">Cargando...</p>
                            ) : !terms ? (
                                <p className="text-amber-600 dark:text-amber-400">No hay terminos publicados.</p>
                            ) : (
                                <>
                                    <div className="prose prose-sm dark:prose-invert max-w-none" dangerouslySetInnerHTML={{ __html: terms.body_html }} />
                                    <hr className="my-6 border-slate-200 dark:border-slate-700" />
                                    <div className="prose prose-sm dark:prose-invert max-w-none" dangerouslySetInnerHTML={{ __html: terms.privacy_html }} />
                                </>
                            )}
                        </div>
                        <div className="p-4 border-t border-slate-200 dark:border-slate-700 flex justify-end">
                            <button onClick={onClose}
                                className="btn-melius px-5 py-2.5 rounded-xl text-white font-bold">
                                Cerrar
                            </button>
                        </div>
                    </div>
                </div>
            );
        };

        const AcceptTermsCard = ({ currentUser, onAccepted, onLogout, theme, onToggleTheme }) => {
            const { push: toast } = useToast();
            const [terms, setTerms] = useState(null);
            const [loading, setLoading] = useState(true);
            const [accepting, setAccepting] = useState(false);
            const [agreed, setAgreed] = useState(false);

            useEffect(() => {
                (async () => {
                    try {
                        const d = await apiFetch('terms/current');
                        setTerms(d.terms);
                    } catch (e) {
                        toast('error', e.message || 'No se pudieron cargar los terminos.');
                    } finally { setLoading(false); }
                })();
            }, []);

            const handleAccept = async () => {
                if (!agreed || accepting || !terms) return;
                setAccepting(true);
                try {
                    await apiPost('terms/accept', { version: terms.version });
                    toast('success', 'Terminos aceptados.');
                    // onAccepted es async (refresca auth/me + setView).
                    // Esperamos a que termine antes de soltar el spinner para
                    // garantizar que la navegacion ocurre dentro del stack
                    // de este handler. Si onAccepted lanza, lo capturamos
                    // para mostrar toast y permitir reintento sin re-loguear.
                    try {
                        await onAccepted();
                    } catch (navErr) {
                        toast('error', navErr?.message || 'No se pudo continuar. Recarga la pagina.');
                        setAccepting(false);
                    }
                } catch (e) {
                    toast('error', e.message || 'No se pudo registrar la aceptacion.');
                    setAccepting(false);
                }
            };

            return (
                <div className="fixed inset-0 z-40 bg-slate-50 dark:bg-slate-950 flex items-center justify-center p-2 sm:p-6 overflow-y-auto">
                    <div className="max-w-3xl w-full bg-white dark:bg-slate-900 rounded-3xl shadow-2xl border border-slate-100 dark:border-slate-800 relative flex flex-col" style={{ maxHeight: 'calc(100vh - 1rem)' }}>
                    <div className="absolute top-4 right-4 z-10"><ThemeToggle theme={theme} onToggle={onToggleTheme} /></div>
                    <div className="p-6 sm:p-10 pb-2 sm:pb-4 flex-shrink-0">
                        <h2 className="text-2xl sm:text-3xl font-black text-slate-800 dark:text-slate-100 mb-2 font-display pr-12">
                            {terms?.title || 'Terminos y Condiciones'}
                        </h2>
                        <p className="text-slate-500 dark:text-slate-400 text-sm">
                            Hola {currentUser?.name?.split(' ')[0] || ''}. Antes de continuar debes leer y aceptar los siguientes terminos y el aviso de privacidad. Tu aceptacion queda registrada con fecha, IP y pais.
                        </p>
                    </div>

                    {loading ? (
                        <p className="text-slate-500 px-6 sm:px-10 py-6">Cargando...</p>
                    ) : !terms ? (
                        <p className="text-amber-600 dark:text-amber-400 px-6 sm:px-10 py-6">No hay terminos publicados. Contacta al administrador.</p>
                    ) : (
                        <>
                            <div className="flex-1 overflow-y-auto px-6 sm:px-10 py-4 min-h-0">
                                <div className="border border-slate-200 dark:border-slate-700 rounded-xl p-4 bg-slate-50 dark:bg-slate-800/50">
                                    <div className="prose prose-sm dark:prose-invert max-w-none" dangerouslySetInnerHTML={{ __html: terms.body_html }} />
                                    <hr className="my-6 border-slate-200 dark:border-slate-700" />
                                    <div className="prose prose-sm dark:prose-invert max-w-none" dangerouslySetInnerHTML={{ __html: terms.privacy_html }} />
                                </div>
                            </div>

                            <div className="flex-shrink-0 px-6 sm:px-10 py-4 sm:py-6 border-t border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 rounded-b-3xl">
                                <label className="flex items-start gap-3 cursor-pointer mb-4">
                                    <input
                                        type="checkbox"
                                        checked={agreed}
                                        onChange={e => setAgreed(e.target.checked)}
                                        className="mt-1 w-6 h-6 rounded border-2 border-slate-400 dark:border-slate-500 accent-blue-600 flex-shrink-0"
                                    />
                                    <span className="text-slate-700 dark:text-slate-200 text-sm font-semibold">
                                        He leido y acepto los Terminos de Uso y el Aviso de Privacidad (version {terms.version}).
                                    </span>
                                </label>

                                <div className="flex flex-col sm:flex-row gap-3">
                                    <button
                                        onClick={handleAccept}
                                        disabled={!agreed || accepting}
                                        className="btn-melius px-6 py-3 rounded-xl text-white font-bold disabled:opacity-50 disabled:cursor-not-allowed flex-1 sm:flex-initial min-h-[48px]"
                                    >
                                        {accepting ? 'Registrando...' : 'Aceptar y continuar'}
                                    </button>
                                    <button
                                        onClick={onLogout}
                                        className="px-6 py-3 rounded-xl border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 font-semibold hover:bg-slate-50 dark:hover:bg-slate-800 min-h-[48px]"
                                    >
                                        Cerrar sesion
                                    </button>
                                </div>
                            </div>
                        </>
                    )}
                    </div>
                </div>
            );
        };

        const LoginCard = ({ onSubmit, submitting, onGoForgot, theme, onToggleTheme }) => {
            const [captcha, setCaptcha] = useState(null);         // { question, expires_in }
            const [captchaAnswer, setCaptchaAnswer] = useState('');
            const [captchaVerified, setCaptchaVerified] = useState(false);
            const [captchaError, setCaptchaError] = useState('');
            const [verifying, setVerifying] = useState(false);

            useEffect(() => {
                apiFetch('auth/captcha').then(d => setCaptcha(d)).catch(() => {});
            }, []);

            const handleVerifyCaptcha = async () => {
                if (!captchaAnswer.trim()) return;
                setVerifying(true); setCaptchaError('');
                try {
                    await apiPost('auth/captcha/verify', { answer: parseInt(captchaAnswer, 10) });
                    setCaptchaVerified(true);
                } catch (e) {
                    setCaptchaError(e.message || 'Respuesta incorrecta.');
                    // Recargar challenge tras fallo
                    apiFetch('auth/captcha').then(d => { setCaptcha(d); setCaptchaAnswer(''); }).catch(() => {});
                } finally { setVerifying(false); }
            };

            return (
            <div className="max-w-md w-full bg-white dark:bg-slate-900 p-6 sm:p-10 md:p-12 rounded-[2rem] sm:rounded-[3rem] md:rounded-[4rem] shadow-2xl dark:shadow-black/50 border border-slate-100 dark:border-slate-800 flex flex-col items-center anim-zoom-in relative">
                <div className="absolute top-4 right-4 sm:top-6 sm:right-6"><ThemeToggle theme={theme} onToggle={onToggleTheme} /></div>
                <div className="w-20 h-20 sm:w-28 sm:h-28 rounded-2xl sm:rounded-[2rem] flex items-center justify-center mb-6 sm:mb-8 ring-melius bg-white dark:bg-slate-800 p-2">
                    <img src="/assets/brands/melius.webp" alt="Melius Services" className="w-full h-full object-contain" />
                </div>
                <h1 className="text-2xl sm:text-3xl md:text-4xl font-black text-slate-800 dark:text-slate-100 mb-2 tracking-tighter text-center font-display">Clock System</h1>
                <p className="text-slate-400 dark:text-slate-500 font-black uppercase tracking-[0.3em] sm:tracking-[0.4em] text-[9px] sm:text-[10px] mb-8 sm:mb-12 text-center">Melius Services Portal</p>

                {!captchaVerified ? (
                    <div className="w-full space-y-4">
                        <div className="bg-slate-50 dark:bg-slate-800 rounded-2xl p-5 text-center border border-slate-100 dark:border-slate-700">
                            <p className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-widest mb-3">Verificación de seguridad</p>
                            {captcha ? (
                                <p className="text-2xl font-black text-slate-800 dark:text-slate-100 font-display mb-4">{captcha.question}</p>
                            ) : (
                                <div className="h-8 flex items-center justify-center"><Icon name="Spinner" size={20} /></div>
                            )}
                            <input type="number" value={captchaAnswer} onChange={e => setCaptchaAnswer(e.target.value)}
                                onKeyDown={e => e.key === 'Enter' && handleVerifyCaptcha()}
                                placeholder="Tu respuesta"
                                className="w-full px-6 py-4 rounded-2xl border-2 border-slate-100 dark:border-slate-700 bg-white dark:bg-slate-900 dark:text-slate-100 outline-none focus:border-blue-500 transition-all font-bold text-center text-lg mb-3" />
                            {captchaError && <p className="text-red-500 text-xs font-bold mb-3">{captchaError}</p>}
                            <button onClick={handleVerifyCaptcha} disabled={verifying || !captchaAnswer.trim()}
                                className="w-full btn-melius py-4 rounded-2xl font-black text-base ring-melius disabled:opacity-60 disabled:cursor-wait flex items-center justify-center gap-2">
                                {verifying && <Icon name="Spinner" size={18} />}
                                {verifying ? 'Verificando…' : 'Continuar'}
                            </button>
                        </div>
                    </div>
                ) : (
                    <form onSubmit={onSubmit} className="w-full space-y-4 sm:space-y-5">
                        <div className="space-y-1">
                            <label htmlFor="login-email" className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 sm:ml-5 tracking-widest block">Email Corporativo</label>
                            <input id="login-email" name="email" type="email" placeholder="usuario@melius.com" required autoComplete="email"
                                className="w-full px-6 sm:px-8 py-4 sm:py-5 rounded-2xl sm:rounded-3xl border-2 border-slate-50 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 focus:bg-white dark:focus:bg-slate-900 transition-all font-medium" />
                        </div>
                        <div className="space-y-1">
                            <label htmlFor="login-password" className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 sm:ml-5 tracking-widest block">Contraseña</label>
                            <input id="login-password" name="password" type="password" required autoComplete="current-password" minLength="1"
                                className="w-full px-6 sm:px-8 py-4 sm:py-5 rounded-2xl sm:rounded-3xl border-2 border-slate-50 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 focus:bg-white dark:focus:bg-slate-900 transition-all font-medium" />
                        </div>
                        <button type="submit" disabled={submitting}
                            className="w-full btn-melius py-4 sm:py-5 rounded-2xl sm:rounded-3xl font-black text-lg sm:text-xl ring-melius transition-all active:scale-95 no-select disabled:opacity-60 disabled:cursor-wait flex items-center justify-center gap-3">
                            {submitting && <Icon name="Spinner" size={20} />}
                            {submitting ? 'Validando…' : 'Entrar'}
                        </button>
                    </form>
                )}

                <div className="mt-8 sm:mt-12 flex flex-col items-center gap-4 sm:gap-5">
                    <button onClick={onGoForgot} className="text-blue-600 dark:text-blue-300 font-black text-xs uppercase tracking-widest hover:underline transition-all">
                        Olvidé mi contraseña
                    </button>
                    <p className="text-[10px] text-slate-400 dark:text-slate-500 font-bold text-center max-w-xs">
                        El alta de cuentas es exclusiva del administrador. Solicita una invitación para acceder.
                    </p>
                </div>
            </div>
            );
        };

        // Carcasa visual reutilizable para las vistas de password.
        const PasswordCard = ({ title, subtitle, children, theme, onToggleTheme }) => (
            <div className="max-w-md w-full bg-white dark:bg-slate-900 p-6 sm:p-10 md:p-12 rounded-[2rem] sm:rounded-[3rem] shadow-2xl dark:shadow-black/50 border border-slate-100 dark:border-slate-800 anim-fade-in relative">
                {onToggleTheme && (
                    <div className="absolute top-4 right-4 sm:top-6 sm:right-6"><ThemeToggle theme={theme} onToggle={onToggleTheme} /></div>
                )}
                <div className="bg-blue-600 w-14 h-14 rounded-2xl flex items-center justify-center mb-6 text-white">
                    <Icon name="Lock" />
                </div>
                <h2 className="text-2xl sm:text-3xl font-black text-slate-800 dark:text-slate-100 mb-2 tracking-tight">{title}</h2>
                {subtitle && <p className="text-sm text-slate-500 dark:text-slate-400 mb-6">{subtitle}</p>}
                {children}
            </div>
        );

        // Medidor de fuerza basico (min 10, mayusc, numero, simbolo).
        const PasswordStrength = ({ value }) => {
            const checks = [
                { ok: (value || '').length >= 10, label: 'Mín. 10 caracteres' },
                { ok: /[A-Z]/.test(value || ''), label: 'Mayúscula' },
                { ok: /[0-9]/.test(value || ''), label: 'Número' },
                { ok: /[^A-Za-z0-9]/.test(value || ''), label: 'Símbolo' }
            ];
            return (
                <ul className="text-[11px] text-slate-500 dark:text-slate-400 grid grid-cols-2 gap-1">
                    {checks.map((c, i) => (
                        <li key={i} className={c.ok ? 'text-emerald-600 dark:text-emerald-300 font-bold' : ''}>
                            {c.ok ? '✓' : '·'} {c.label}
                        </li>
                    ))}
                </ul>
            );
        };

        const PasswordInputs = ({ newPwd, setNewPwd, confirmPwd, setConfirmPwd, autoComplete = 'new-password' }) => (
            <>
                <div className="space-y-1">
                    <label className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 tracking-widest block">Nueva contraseña</label>
                    <input type="password" required minLength="10" maxLength="200" autoComplete={autoComplete}
                        value={newPwd} onChange={(e) => setNewPwd(e.target.value)}
                        className="w-full px-6 py-4 rounded-2xl border-2 border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 transition-all font-medium" />
                </div>
                <PasswordStrength value={newPwd} />
                <div className="space-y-1">
                    <label className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 tracking-widest block">Confirmar contraseña</label>
                    <input type="password" required minLength="10" maxLength="200" autoComplete={autoComplete}
                        value={confirmPwd} onChange={(e) => setConfirmPwd(e.target.value)}
                        className={`w-full px-6 py-4 rounded-2xl border-2 ${confirmPwd && confirmPwd !== newPwd ? 'border-red-400' : 'border-slate-100 dark:border-slate-700'} bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 transition-all font-medium`} />
                </div>
            </>
        );

        const ForgotPasswordCard = ({ onBack, theme, onToggleTheme }) => {
            const { push: toast } = useToast();
            const [email, setEmail] = useState('');
            const [submitting, setSubmitting] = useState(false);
            const [sent, setSent] = useState(false);
            const handle = async (e) => {
                e.preventDefault();
                if (submitting) return;
                setSubmitting(true);
                try {
                    const d = await apiPost('auth/forgot-password', { email });
                    toast('success', d.message);
                    setSent(true);
                } catch (err) { toast('error', err.message); }
                finally { setSubmitting(false); }
            };
            return (
                <PasswordCard title="Restablecer contraseña" subtitle="Te enviaremos un enlace de recuperación si el correo está registrado." theme={theme} onToggleTheme={onToggleTheme}>
                    {sent ? (
                        <div className="space-y-5">
                            <p className="text-sm text-emerald-600 dark:text-emerald-300 font-bold">Revisa tu correo. El enlace expira en 72 horas.</p>
                            <button onClick={onBack} className="w-full py-3 rounded-2xl font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-200">Volver al inicio de sesión</button>
                        </div>
                    ) : (
                        <form onSubmit={handle} className="space-y-5">
                            <div className="space-y-1">
                                <label className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 tracking-widest block">Correo</label>
                                <input type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)}
                                    className="w-full px-6 py-4 rounded-2xl border-2 border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 transition-all font-medium" />
                            </div>
                            <button type="submit" disabled={submitting}
                                className="w-full bg-blue-600 text-white py-4 rounded-2xl font-black hover:bg-blue-700 transition-all shadow-xl shadow-blue-200 dark:shadow-blue-900/40 disabled:opacity-60 flex items-center justify-center gap-2">
                                {submitting && <Icon name="Spinner" size={18} />}
                                Enviar enlace
                            </button>
                            <button type="button" onClick={onBack} className="w-full py-2 text-slate-400 font-bold text-xs uppercase tracking-widest">Cancelar</button>
                        </form>
                    )}
                </PasswordCard>
            );
        };

        const ResetPasswordCard = ({ token, onDone, theme, onToggleTheme }) => {
            const { push: toast } = useToast();
            const [newPwd, setNewPwd] = useState('');
            const [confirmPwd, setConfirmPwd] = useState('');
            const [submitting, setSubmitting] = useState(false);
            const handle = async (e) => {
                e.preventDefault();
                if (submitting) return;
                if (newPwd !== confirmPwd) { toast('error', 'Las contraseñas no coinciden.'); return; }
                setSubmitting(true);
                try {
                    await apiPost('auth/reset-password', { token, new_password: newPwd, confirm_password: confirmPwd });
                    toast('success', 'Contraseña restablecida. Inicia sesión.');
                    onDone();
                } catch (err) { toast('error', err.message); }
                finally { setSubmitting(false); }
            };
            return (
                <PasswordCard title="Nueva contraseña" subtitle="Define una contraseña segura para tu cuenta." theme={theme} onToggleTheme={onToggleTheme}>
                    <form onSubmit={handle} className="space-y-5">
                        <PasswordInputs newPwd={newPwd} setNewPwd={setNewPwd} confirmPwd={confirmPwd} setConfirmPwd={setConfirmPwd} />
                        <button type="submit" disabled={submitting || newPwd !== confirmPwd}
                            className="w-full bg-blue-600 text-white py-4 rounded-2xl font-black hover:bg-blue-700 transition-all shadow-xl shadow-blue-200 dark:shadow-blue-900/40 disabled:opacity-60 flex items-center justify-center gap-2">
                            {submitting && <Icon name="Spinner" size={18} />}
                            Restablecer
                        </button>
                    </form>
                </PasswordCard>
            );
        };

        const ChangePasswordCard = ({ currentUser, onDone, onLogout, theme, onToggleTheme }) => {
            const { push: toast } = useToast();
            const [currentPwd, setCurrentPwd] = useState('');
            const [newPwd, setNewPwd] = useState('');
            const [confirmPwd, setConfirmPwd] = useState('');
            const [submitting, setSubmitting] = useState(false);
            // Admin sin empresa asignada: debe elegir empresa en este mismo paso.
            const needsCompany = currentUser.role === 'admin' && !currentUser.company_id;
            const [companies, setCompanies] = useState([]);
            const [companyId, setCompanyId] = useState('');
            const [loadingCompanies, setLoadingCompanies] = useState(false);

            useEffect(() => {
                if (!needsCompany) return;
                setLoadingCompanies(true);
                apiFetch('companies')
                    .then(d => setCompanies(d.companies || []))
                    .catch(e => toast('error', e.message))
                    .finally(() => setLoadingCompanies(false));
            }, [needsCompany]);

            const handle = async (e) => {
                e.preventDefault();
                if (submitting) return;
                if (newPwd !== confirmPwd) { toast('error', 'Las contraseñas no coinciden.'); return; }
                if (needsCompany && !companyId) { toast('error', 'Selecciona la empresa a la que perteneces.'); return; }
                setSubmitting(true);
                try {
                    const payload = {
                        current_password: currentPwd, new_password: newPwd, confirm_password: confirmPwd
                    };
                    if (needsCompany) payload.company_id = parseInt(companyId, 10);
                    await apiPost('auth/change-password', payload);
                    toast('success', 'Contraseña actualizada.');
                    onDone();
                } catch (err) { toast('error', err.message); }
                finally { setSubmitting(false); }
            };
            return (
                <PasswordCard
                    title="Debes cambiar tu contraseña"
                    subtitle={`Hola ${currentUser.name}, por seguridad define una contraseña nueva antes de continuar.`}
                    theme={theme}
                    onToggleTheme={onToggleTheme}
                >
                    <form onSubmit={handle} className="space-y-5">
                        <div className="space-y-1">
                            <label className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 tracking-widest block">Contraseña actual (temporal)</label>
                            <input type="password" required minLength="1" maxLength="200" autoComplete="current-password"
                                value={currentPwd} onChange={(e) => setCurrentPwd(e.target.value)}
                                className="w-full px-6 py-4 rounded-2xl border-2 border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 transition-all font-medium" />
                        </div>
                        <PasswordInputs newPwd={newPwd} setNewPwd={setNewPwd} confirmPwd={confirmPwd} setConfirmPwd={setConfirmPwd} />
                        {needsCompany && (
                            <div className="space-y-1">
                                <label className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 tracking-widest block">Empresa a la que perteneces</label>
                                <Select
                                    required
                                    value={companyId}
                                    onChange={(e) => setCompanyId(e.target.value)}
                                    disabled={loadingCompanies}
                                    size="lg"
                                >
                                    <option value="" disabled>{loadingCompanies ? 'Cargando empresas...' : 'Selecciona una empresa'}</option>
                                    {companies.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </Select>
                                <p className="text-[11px] text-slate-500 ml-4 mt-1">Como administrador necesitas tener una empresa asignada para gestionar a tu equipo.</p>
                            </div>
                        )}
                        <button type="submit" disabled={submitting || newPwd !== confirmPwd || (needsCompany && !companyId)}
                            className="w-full bg-blue-600 text-white py-4 rounded-2xl font-black hover:bg-blue-700 transition-all shadow-xl shadow-blue-200 dark:shadow-blue-900/40 disabled:opacity-60 flex items-center justify-center gap-2">
                            {submitting && <Icon name="Spinner" size={18} />}
                            Guardar nueva contraseña
                        </button>
                        <button type="button" onClick={onLogout} className="w-full py-2 text-slate-400 dark:text-slate-500 font-bold text-xs uppercase tracking-widest">Cerrar sesión</button>
                    </form>
                </PasswordCard>
            );
        };

        // =====================================================================
        // Dashboard de usuario
        // =====================================================================

        const UserDashboard = ({ currentUser, onLogout, theme, onToggleTheme, companies, loadCompanies, onGoAdmin, onStartTour }) => {
            const canSwitchToAdmin = currentUser.role === 'admin' || currentUser.role === 'super_admin';
            const { push: toast } = useToast();
            const [todayRecord, setTodayRecord] = useState(null);
            const [logs, setLogs] = useState([]);
            const [loading, setLoading] = useState(true);
            const [error, setError] = useState(null);
            const [showChangeModal, setShowChangeModal] = useState(false);
            const [showOvertimeModal, setShowOvertimeModal] = useState(false);
            const [showVacationModal, setShowVacationModal] = useState(false);
            const [vacationList, setVacationList] = useState([]);
            const [vacationLoading, setVacationLoading] = useState(false);
            const [pendingDecision, setPendingDecision] = useState(null);
            const [submitting, setSubmitting] = useState(false);
            const [userMenuOpen, setUserMenuOpen] = useState(false);
            const userMenuRef = useRef(null);

            useEffect(() => {
                if (!userMenuOpen) return;
                const onClickOutside = (e) => {
                    if (userMenuRef.current && !userMenuRef.current.contains(e.target)) setUserMenuOpen(false);
                };
                const onKey = (e) => { if (e.key === 'Escape') setUserMenuOpen(false); };
                document.addEventListener('mousedown', onClickOutside);
                document.addEventListener('keydown', onKey);
                return () => {
                    document.removeEventListener('mousedown', onClickOutside);
                    document.removeEventListener('keydown', onKey);
                };
            }, [userMenuOpen]);

            const refresh = useCallback(async () => {
                setLoading(true); setError(null);
                try {
                    const [today, mine] = await Promise.all([
                        apiFetch('records/today'),
                        apiFetch('records/mine?limit=5')
                    ]);
                    setTodayRecord(today.record);
                    setLogs(mine.records);
                } catch (e) {
                    setError(e.message || 'Error al cargar datos.');
                } finally { setLoading(false); }
            }, []);

            useEffect(() => { refresh(); }, [refresh]);

            const clientTimezone = () => {
                try { return Intl.DateTimeFormat().resolvedOptions().timeZone || null; }
                catch (_) { return null; }
            };

            // Anti-bot: registra si hubo interaccion humana real (mouse/touch/keyboard) en la sesion.
            const humanInteractionRef = useRef(false);
            useEffect(() => {
                const mark = () => { humanInteractionRef.current = true; };
                window.addEventListener('mousemove', mark, { once: true, passive: true });
                window.addEventListener('touchstart', mark, { once: true, passive: true });
                window.addEventListener('keydown', mark, { once: true });
                return () => {
                    window.removeEventListener('mousemove', mark);
                    window.removeEventListener('touchstart', mark);
                    window.removeEventListener('keydown', mark);
                };
            }, []);

            const handleClockIn = async (extraBody = {}) => {
                if (submitting) return;
                setSubmitting(true);
                try {
                    const body = {
                        client_timezone: clientTimezone(),
                        human_interaction: humanInteractionRef.current,
                        hp_field: '',
                        ...extraBody,
                    };
                    const d = await apiFetch('records/clockin', { method: 'POST', body });
                    if (d.decision_required) {
                        setPendingDecision({ priorRecord: d.prior_record, rule: d.rule });
                        return;
                    }
                    setPendingDecision(null);
                    setTodayRecord(d.record);
                    if (d.warnings?.tz_mismatch) {
                        toast('warning', 'Marcaste desde una zona horaria distinta a la de tu perfil. Quedó registrado.');
                    } else {
                        toast('success', 'Entrada registrada.');
                    }
                    refresh();
                } catch (e) {
                    toast('error', e.message);
                } finally { setSubmitting(false); }
            };

            const handleClockOut = async () => {
                if (submitting) return;
                if (new Date().getHours() < 18) {
                    toast('warning', 'La salida solo puede registrarse a partir de las 18:00.');
                    return;
                }
                setSubmitting(true);
                try {
                    const d = await apiFetch('records/clockout', { method: 'POST', body: {
                        client_timezone: clientTimezone(),
                        human_interaction: humanInteractionRef.current,
                        hp_field: '',
                    } });
                    setTodayRecord(d.record);
                    toast('success', 'Salida registrada.');
                    refresh();
                } catch (e) {
                    toast('error', e.message);
                } finally { setSubmitting(false); }
            };

            const userCompany = useMemo(() => {
                if (currentUser.company_name) return { id: currentUser.company_id, name: currentUser.company_name };
                return companies.find(c => c.id === currentUser.company_id);
            }, [companies, currentUser]);

            return (
                <div className="max-w-4xl w-full flex flex-col gap-6 sm:gap-8 anim-fade-in">
                    {/* Header */}
                    <div data-tour="header-user" className="flex flex-col md:flex-row justify-between items-center bg-white dark:bg-slate-900 p-6 sm:p-8 rounded-[2rem] sm:rounded-[2.5rem] shadow-xl dark:shadow-black/40 border border-slate-100 dark:border-slate-800 gap-4 md:gap-6">
                        <div className="flex items-center gap-4 sm:gap-5 w-full md:w-auto">
                            {currentUser.brand_logo_url ? (
                                <div className="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-white dark:bg-slate-800 ring-melius flex items-center justify-center p-2 shrink-0" title={currentUser.brand_name || ''}>
                                    <img src={currentUser.brand_logo_url} alt={currentUser.brand_name || 'Marca'} className="w-full h-full object-contain" />
                                </div>
                            ) : (
                                <div className="w-14 h-14 sm:w-16 sm:h-16 btn-melius rounded-2xl flex items-center justify-center text-white text-2xl sm:text-3xl font-black ring-melius shrink-0 font-display">
                                    {currentUser.name[0]?.toUpperCase()}
                                </div>
                            )}
                            <div className="min-w-0">
                                <h2 className="text-xl sm:text-2xl font-black text-slate-800 dark:text-slate-100 tracking-tight truncate">{currentUser.name}</h2>
                                <div className="flex items-center gap-2 sm:gap-3 mt-1 flex-wrap">
                                    <span className="text-[11px] sm:text-xs font-bold px-2.5 sm:px-3 py-1 bg-cyan-50 dark:bg-cyan-900/30 text-melius-cyan rounded-full border border-cyan-100 dark:border-cyan-900/40">
                                        {userCompany?.name || 'Sin empresa'}
                                    </span>
                                    <button onClick={() => { if (!companies.length) loadCompanies(); setShowChangeModal(true); }}
                                        className="text-[9px] sm:text-[10px] font-black text-slate-400 dark:text-slate-500 hover:text-blue-500 dark:hover:text-blue-300 uppercase tracking-widest underline transition-all">
                                        ¿Empresa incorrecta?
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div className="flex items-center gap-2 sm:gap-3 w-full md:w-auto justify-end">
                            {canSwitchToAdmin && onGoAdmin && (
                                <button onClick={onGoAdmin}
                                    title="Cambiar a vista de administración"
                                    className="px-4 sm:px-5 py-2.5 sm:py-3 btn-melius rounded-2xl font-bold text-sm transition-all flex items-center gap-2">
                                    <Icon name="ShieldCheck" size={16} />
                                    <span className="hidden sm:inline">Admin Console</span>
                                    <span className="sm:hidden">Admin</span>
                                </button>
                            )}
                            <div data-tour="user-menu" className="relative" ref={userMenuRef}>
                                <button onClick={() => setUserMenuOpen(o => !o)}
                                    aria-haspopup="menu"
                                    aria-expanded={userMenuOpen}
                                    aria-label="Abrir menú de usuario"
                                    className="flex items-center gap-2 pl-1.5 pr-2.5 sm:pr-3 py-1.5 rounded-full bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 active:scale-95 transition-all border border-slate-200 dark:border-slate-700 shadow-sm">
                                    <div className="w-8 h-8 rounded-full bg-gradient-to-br from-melius-cyan to-melius-violet flex items-center justify-center text-white font-black text-sm">
                                        {(currentUser.name || '?').charAt(0).toUpperCase()}
                                    </div>
                                    <span className="hidden sm:inline text-sm font-bold text-slate-600 dark:text-slate-200 max-w-[100px] truncate">{currentUser.name?.split(' ')[0] || 'Usuario'}</span>
                                    <Icon name="ChevronDown" size={16} className={`text-slate-500 dark:text-slate-300 transition-transform ${userMenuOpen ? 'rotate-180' : ''}`} />
                                </button>
                                {userMenuOpen && (
                                    <div role="menu" className="absolute right-0 mt-2 w-64 max-w-[calc(100vw-1rem)] bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 py-2 z-30 anim-fade-in">
                                        <div className="px-4 py-3 border-b border-slate-100 dark:border-slate-800">
                                            <div className="text-[10px] uppercase tracking-widest text-slate-400 font-black">Sesión activa</div>
                                            <div className="text-sm font-bold text-slate-700 dark:text-slate-200 truncate mt-0.5">{currentUser.name}</div>
                                            <div className="text-[11px] text-slate-400 truncate" title={currentUser.email || ''}>{currentUser.email || ''}</div>
                                        </div>
                                        <button onClick={() => { setUserMenuOpen(false); onToggleTheme(); }}
                                            className="w-full text-left px-4 py-2.5 text-sm font-bold flex items-center gap-3 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                                            <Icon name={theme === 'dark' ? 'Sun' : 'Moon'} size={18} />
                                            {theme === 'dark' ? 'Tema claro' : 'Tema oscuro'}
                                        </button>
                                        {onStartTour && (
                                            <button onClick={() => { setUserMenuOpen(false); onStartTour(); }}
                                                className="w-full text-left px-4 py-2.5 text-sm font-bold flex items-center gap-3 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                                                <Icon name="ShieldCheck" size={18} />
                                                Ver tutorial
                                            </button>
                                        )}
                                        <div className="border-t border-slate-100 dark:border-slate-800 mt-1 pt-1">
                                            <button onClick={() => { setUserMenuOpen(false); onLogout(); }}
                                                className="w-full text-left px-4 py-2.5 text-sm font-bold flex items-center gap-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">
                                                <Icon name="LogOut" size={18} />
                                                Cerrar sesión
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {loading && <LoadingScreen />}
                    {error && !loading && <ErrorState message={error} onRetry={refresh} />}

                    {!loading && !error && (
                        <>
                            {/* Acciones principales */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 md:gap-8">
                                <button data-tour="btn-clockin" onClick={() => handleClockIn()} disabled={!!todayRecord || submitting} aria-label="Marcar entrada"
                                    className={`p-6 sm:p-8 md:p-10 rounded-[2rem] sm:rounded-[3rem] border-4 transition-all flex flex-col items-center gap-4 sm:gap-5 no-select ${
                                        todayRecord
                                            ? 'bg-slate-50 dark:bg-slate-900/50 border-slate-100 dark:border-slate-800 opacity-60 grayscale cursor-not-allowed'
                                            : 'bg-white dark:bg-slate-900 border-blue-50 dark:border-blue-900/40 hover:border-blue-400 dark:hover:border-blue-500 shadow-2xl shadow-blue-900/5 dark:shadow-blue-950/40 active:scale-95'
                                    }`}>
                                    <div className={`p-4 sm:p-6 rounded-2xl sm:rounded-3xl ${todayRecord ? 'bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-600' : 'bg-blue-600 text-white shadow-xl shadow-blue-200 dark:shadow-blue-900/40'}`}>
                                        <Icon name="LogIn" size={28} className="sm:hidden" />
                                        <Icon name="LogIn" size={36} className="hidden sm:block" />
                                    </div>
                                    <div className="text-center">
                                        <span className="block font-black text-xl sm:text-2xl uppercase tracking-tighter text-slate-800 dark:text-slate-100">Entrada</span>
                                        {todayRecord && <span className="text-blue-600 dark:text-blue-300 font-black font-mono text-xs sm:text-sm uppercase bg-blue-50 dark:bg-blue-900/40 px-3 py-1 rounded-lg mt-2 inline-block">Registrada: {todayRecord.entry_time}</span>}
                                    </div>
                                </button>

                                {(() => {
                                    const beforeEighteen = new Date().getHours() < 18;
                                    const clockoutDisabled = !todayRecord || !!todayRecord?.exit_time || submitting || beforeEighteen;
                                    const clockoutInactive = !todayRecord || !!todayRecord?.exit_time || beforeEighteen;
                                    return (
                                        <button data-tour="btn-clockout" onClick={handleClockOut} disabled={clockoutDisabled} aria-label="Marcar salida"
                                            className={`p-6 sm:p-8 md:p-10 rounded-[2rem] sm:rounded-[3rem] border-4 transition-all flex flex-col items-center gap-4 sm:gap-5 no-select ${
                                                clockoutInactive
                                                    ? 'bg-slate-50 dark:bg-slate-900/50 border-slate-100 dark:border-slate-800 opacity-60 grayscale cursor-not-allowed'
                                                    : 'bg-white dark:bg-slate-900 border-orange-50 dark:border-orange-900/40 hover:border-orange-400 dark:hover:border-orange-500 shadow-2xl shadow-orange-900/5 dark:shadow-orange-950/40 active:scale-95'
                                            }`}>
                                            <div className={`p-4 sm:p-6 rounded-2xl sm:rounded-3xl ${clockoutInactive ? 'bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-600' : 'bg-orange-600 text-white shadow-xl shadow-orange-200 dark:shadow-orange-900/40'}`}>
                                                <Icon name="LogOut" size={28} className="sm:hidden" />
                                                <Icon name="LogOut" size={36} className="hidden sm:block" />
                                            </div>
                                            <div className="text-center">
                                                <span className="block font-black text-xl sm:text-2xl uppercase tracking-tighter text-slate-800 dark:text-slate-100">Salida</span>
                                                {todayRecord?.exit_time && <span className="text-orange-600 dark:text-orange-300 font-black font-mono text-xs sm:text-sm uppercase bg-orange-50 dark:bg-orange-900/40 px-3 py-1 rounded-lg mt-2 inline-block">Registrada: {todayRecord.exit_time}</span>}
                                                {!todayRecord && <span className="text-slate-300 dark:text-slate-600 font-bold text-[10px] uppercase block mt-2">Pendiente de entrada</span>}
                                                {todayRecord && !todayRecord.exit_time && beforeEighteen && <span className="text-slate-400 dark:text-slate-500 font-bold text-[10px] uppercase block mt-2">Disponible a las 18:00</span>}
                                            </div>
                                        </button>
                                    );
                                })()}
                            </div>

                            {/* Card vacaciones */}
                            <div data-tour="btn-vacation" className="bg-white dark:bg-slate-900 p-5 sm:p-7 md:p-8 rounded-[2rem] sm:rounded-[2.5rem] shadow-sm border border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                                <div className="flex items-center gap-3 sm:gap-4">
                                    <div className="bg-emerald-100 dark:bg-emerald-900/40 p-3 rounded-2xl text-emerald-600 dark:text-emerald-300 shrink-0"><Icon name="CalendarDays" /></div>
                                    <div>
                                        <h3 className="font-black text-base sm:text-lg text-slate-800 dark:text-slate-100">Vacaciones</h3>
                                        <p className="text-xs text-slate-500 dark:text-slate-400">Solicita un rango de días · sujeto a aprobación</p>
                                    </div>
                                </div>
                                <button onClick={() => setShowVacationModal(true)}
                                    className="px-5 sm:px-6 py-3 rounded-2xl bg-emerald-500 text-white font-bold hover:bg-emerald-600 transition-all shadow-lg shadow-emerald-200 dark:shadow-emerald-900/30 w-full sm:w-auto min-h-[44px]">
                                    Solicitar vacaciones
                                </button>
                            </div>

                            {/* Historial */}
                            <div data-tour="btn-history" className="bg-white dark:bg-slate-900 p-5 sm:p-7 md:p-8 rounded-[2rem] sm:rounded-[2.5rem] shadow-sm border border-slate-100 dark:border-slate-800">
                                <h3 className="font-black text-slate-800 dark:text-slate-100 mb-6 sm:mb-8 flex items-center gap-3 uppercase tracking-widest text-xs">
                                    <Icon name="History" className="text-blue-500 dark:text-blue-300 w-4 h-4" /> Mis Jornadas Laborales
                                </h3>
                                <div className="space-y-3 sm:space-y-4">
                                    {logs.map(log => (
                                        <div key={log.id} className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 p-4 sm:p-6 bg-slate-50/50 dark:bg-slate-800/40 rounded-2xl sm:rounded-3xl border border-slate-100 dark:border-slate-800 hover:bg-white dark:hover:bg-slate-800 hover:shadow-md transition-all">
                                            <div className="w-full">
                                                <p className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-2">
                                                    {log.work_date}
                                                    {log.closed_reason === 'forgotten' && ' · cerrado por olvido'}
                                                </p>
                                                <div className="flex items-center gap-3 sm:gap-4 flex-wrap">
                                                    <div className="bg-white dark:bg-slate-900 px-3 sm:px-4 py-2 rounded-xl shadow-sm border border-slate-100 dark:border-slate-800">
                                                        <p className="text-[8px] font-black text-slate-400 dark:text-slate-500 uppercase mb-1">Entrada</p>
                                                        <p className="font-mono font-black text-lg sm:text-xl text-blue-600 dark:text-blue-400 tracking-tight">{log.entry_time}</p>
                                                    </div>
                                                    <div className="bg-white dark:bg-slate-900 px-3 sm:px-4 py-2 rounded-xl shadow-sm border border-slate-100 dark:border-slate-800">
                                                        <p className="text-[8px] font-black text-slate-400 dark:text-slate-500 uppercase mb-1">Salida</p>
                                                        <p className={`font-mono font-black text-lg sm:text-xl tracking-tight ${log.exit_time ? 'text-orange-600 dark:text-orange-400' : 'text-slate-400 dark:text-slate-500'}`}>{log.exit_time || '--:--'}</p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div className={`w-9 h-9 sm:w-10 sm:h-10 rounded-full flex items-center justify-center shrink-0 ${log.exit_time ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-300 dark:text-slate-600'}`}>
                                                <Icon name="Check" size={18} />
                                            </div>
                                        </div>
                                    ))}
                                    {logs.length === 0 && <EmptyState message="Aún no tienes registros de asistencia" />}
                                </div>
                            </div>
                        </>
                    )}

                    {/* Modal cambio empresa */}
                    <Modal open={showChangeModal} onClose={() => setShowChangeModal(false)} title="Cambio de empresa">
                        <h3 className="text-xl sm:text-2xl font-black mb-3 text-slate-800 dark:text-slate-100 tracking-tight">Cambio de empresa</h3>
                        <p className="text-slate-500 dark:text-slate-400 text-sm mb-6 sm:mb-8 leading-relaxed">
                            Selecciona la empresa correcta. La solicitud será revisada por el administrador.
                        </p>
                        <div className="space-y-3 mb-6 sm:mb-8">
                            {companies.filter(c => c.id !== currentUser.company_id).map(c => (
                                <button key={c.id}
                                    onClick={async () => {
                                        try {
                                            await apiFetch('records/change-company', { method: 'POST', body: { new_company_id: c.id } });
                                            toast('success', 'Solicitud enviada.');
                                            setShowChangeModal(false);
                                        } catch (e) { toast('error', e.message); }
                                    }}
                                    className="w-full text-left p-4 sm:p-5 rounded-2xl border-2 border-transparent bg-slate-50 dark:bg-slate-800 hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-all font-bold flex justify-between items-center group">
                                    <span className="text-slate-700 dark:text-slate-200">{c.name}</span>
                                    <Icon name="ArrowLeftRight" className="w-4 h-4 text-slate-300 dark:text-slate-600 group-hover:text-blue-500" />
                                </button>
                            ))}
                            {companies.filter(c => c.id !== currentUser.company_id).length === 0 && (
                                <EmptyState message="No hay otras empresas disponibles." />
                            )}
                        </div>
                        <button onClick={() => setShowChangeModal(false)} className="w-full py-3 text-slate-400 dark:text-slate-500 font-bold hover:text-slate-600 dark:hover:text-slate-300 uppercase tracking-widest text-[10px]">Cancelar</button>
                    </Modal>

                    {/* Modal solicitud de vacaciones */}
                    <Modal open={showVacationModal} onClose={() => setShowVacationModal(false)} title="Solicitar vacaciones" maxWidth="max-w-lg" showHeader>
                        <VacationForm
                            onSubmit={async (start_date, end_date, reason) => {
                                try {
                                    const r = await apiFetch('vacations/request', { method: 'POST', body: { start_date, end_date, reason } });
                                    toast('success', `Solicitud enviada (${r.days} días).`);
                                    setShowVacationModal(false);
                                } catch (e) { toast('error', e.message); }
                            }}
                            onCancel={() => setShowVacationModal(false)}
                        />
                    </Modal>

                    {/* Modal confirmacion de olvido de cierre. Si el usuario marca entrada
                        antes del grace_hour_am sin haber cerrado la jornada anterior, le
                        ofrecemos cerrar a las 18:00 y comenzar la nueva. */}
                    <Modal open={!!pendingDecision} onClose={() => {}} title="Jornada anterior sin cerrar" dismissible={false}>
                        <div className="flex items-center gap-3 mb-5">
                            <div className="bg-amber-100 dark:bg-amber-900/40 p-3 rounded-2xl text-amber-600 dark:text-amber-300"><Icon name="AlertTriangle" /></div>
                            <h3 className="text-lg sm:text-xl font-black text-slate-800 dark:text-slate-100">Jornada anterior sin cerrar</h3>
                        </div>
                        <p className="text-slate-500 dark:text-slate-400 text-sm mb-6 leading-relaxed">
                            Tu última jornada ({pendingDecision?.priorRecord?.work_date}) no tiene salida. Estás marcando entrada antes de las {String(pendingDecision?.rule?.grace_hour_am || 6).padStart(2,'0')}:00.
                        </p>
                        <div className="grid grid-cols-1 gap-3">
                            <button onClick={() => handleClockIn({ declare_overtime: false })}
                                className="text-left p-5 rounded-2xl border-2 border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:border-slate-400 transition-all">
                                <p className="font-black text-slate-700 dark:text-slate-200">Cerrar jornada anterior y continuar</p>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">Cerramos la jornada anterior a las 18:00 e iniciamos la de hoy.</p>
                            </button>
                        </div>
                    </Modal>
                </div>
            );
        };

        // VacationForm: el Modal renderiza el titulo en su propio header sticky.
        // Aqui solo el contenido del body. Los botones quedan al final del form.
        const VacationForm = ({ onSubmit, onCancel }) => {
            const today = new Date().toISOString().slice(0, 10);
            const [start, setStart] = useState(today);
            const [end, setEnd] = useState(today);
            const [reason, setReason] = useState('');
            const [submitting, setSubmitting] = useState(false);
            const days = (() => {
                try {
                    const a = new Date(start);
                    const b = new Date(end);
                    if (isNaN(a) || isNaN(b) || a > b) return 0;
                    return Math.round((b - a) / 86400000) + 1;
                } catch { return 0; }
            })();
            const handle = async (e) => {
                e.preventDefault();
                if (days <= 0) return;
                setSubmitting(true);
                try { await onSubmit(start, end, reason.trim()); }
                finally { setSubmitting(false); }
            };
            return (
                <form onSubmit={handle} className="space-y-5">
                    <p className="text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
                        Selecciona el rango de fechas. La solicitud queda pendiente hasta que el administrador la apruebe o la rechace.
                    </p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label htmlFor="vac-start" className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-2 tracking-widest block mb-1">Inicio</label>
                            <input id="vac-start" type="date" value={start} min={today} onChange={(e) => setStart(e.target.value)} required
                                className="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 font-semibold" />
                        </div>
                        <div>
                            <label htmlFor="vac-end" className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-2 tracking-widest block mb-1">Fin</label>
                            <input id="vac-end" type="date" value={end} min={start || today} onChange={(e) => setEnd(e.target.value)} required
                                className="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 font-semibold" />
                        </div>
                    </div>
                    <div className={`rounded-xl px-4 py-3 text-sm font-bold flex items-center gap-2 ${days > 0 ? 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-900/40' : 'bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-300 border border-red-100 dark:border-red-900/40'}`}>
                        <Icon name={days > 0 ? 'Check' : 'AlertTriangle'} size={16} />
                        {days > 0 ? `Total: ${days} día${days === 1 ? '' : 's'} calendario` : 'Rango inválido — la fecha fin debe ser igual o posterior a la de inicio'}
                    </div>
                    <div>
                        <label htmlFor="vac-reason" className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-2 tracking-widest block mb-1">Motivo (opcional)</label>
                        <textarea id="vac-reason" value={reason} onChange={(e) => setReason(e.target.value)} rows="3" maxLength="500"
                            placeholder="Ej. Viaje familiar, descanso médico, etc."
                            className="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 font-medium resize-none" />
                        <p className="text-[10px] text-slate-400 dark:text-slate-500 ml-2 mt-1">{reason.length}/500</p>
                    </div>
                    <div className="flex flex-col sm:flex-row gap-3 pt-2">
                        <button type="button" onClick={onCancel} disabled={submitting} className="flex-1 py-3 rounded-xl font-bold text-slate-600 dark:text-slate-200 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors order-2 sm:order-1 disabled:opacity-60">Cancelar</button>
                        <button type="submit" disabled={submitting || days <= 0} className="flex-1 py-3 rounded-xl font-black text-white bg-emerald-500 hover:bg-emerald-600 transition-colors shadow-lg shadow-emerald-200 dark:shadow-emerald-900/30 disabled:opacity-60 order-1 sm:order-2 flex items-center justify-center gap-2">
                            {submitting && <Icon name="Spinner" size={18} />}
                            Enviar solicitud
                        </button>
                    </div>
                </form>
            );
        };

        // =====================================================================
        // Panel admin
        // =====================================================================

        const AdminPanel = ({ currentUser, onLogout, theme, onToggleTheme, onGoDashboard, onStartTour }) => {
            const { push: toast } = useToast();
            const { tenantBranding } = useBranding();
            const isSuper = currentUser.role === 'super_admin';
            // Branding efectivo: empresa-override (si la hay) > marca paraguas > tenant.
            const effective = resolveEffectiveBranding(tenantBranding, currentUser, null);

            // Tabs primarios (visibles siempre en desktop, en bottom-nav movil) y
            // secundarios (dropdown "Mas" en desktop, dentro del drawer "Mas" en movil).
            const primaryTabs = [
                { id: 'dashboard', label: 'Dashboard', icon: 'Home' },
                { id: 'records', label: 'Registros', icon: 'FileText' },
                { id: 'agents', label: 'Consultores', icon: 'Users' },
                { id: 'requests', label: 'Solicitudes', icon: 'Bell' },
            ];
            const secondaryTabs = [
                ...(isSuper ? [{ id: 'brands', label: 'Marcas', icon: 'Tag' }] : []),
                { id: 'companies', label: 'Empresas', icon: 'Building' },
                { id: 'admins', label: 'Admins', icon: 'ShieldCheck' },
                { id: 'alerts', label: 'Alertas geo', icon: 'AlertTriangle' },
                { id: 'security', label: 'Seguridad', icon: 'Shield' },
                ...(isSuper ? [{ id: 'emails', label: 'Plantillas', icon: 'FileText' }] : []),
                ...(isSuper ? [{ id: 'tenant', label: 'Configuración', icon: 'Lock' }] : []),
            ];

            const [activeTab, setActiveTab] = useState(() => {
                const allowed = new Set(['dashboard','records','agents','requests', ...(isSuper ? ['brands','tenant','emails'] : []), 'companies','admins','alerts','security']);
                const saved = readNavState().adminTab;
                return saved && allowed.has(saved) ? saved : 'dashboard';
            });
            // Subtab del bucket "requests" agrupado: cambios o vacaciones.
            const [requestsSub, setRequestsSub] = useState(() => {
                const saved = readNavState().requestsSub;
                return saved === 'vacations' ? 'vacations' : 'changes';
            });

            useEffect(() => { writeNavState({ adminTab: activeTab }); }, [activeTab]);
            useEffect(() => { writeNavState({ requestsSub }); }, [requestsSub]);
            const [counts, setCounts] = useState({ changes: 0, vacations: 0, alerts: 0 });
            const [moreOpen, setMoreOpen] = useState(false);
            const [userMenuOpen, setUserMenuOpen] = useState(false);
            const [mobileMoreOpen, setMobileMoreOpen] = useState(false);
            const moreRef = useRef(null);
            const userDesktopRef = useRef(null);
            const userMobileRef = useRef(null);

            const totalRequests = counts.changes + counts.vacations;

            // Cerrar dropdowns al hacer click fuera. Se valida contra los DOS refs
            // (desktop y movil) porque solo uno esta en el DOM segun breakpoint, pero
            // el handler debe respetar ambos para no cerrar el dropdown al hacer click
            // dentro del mismo. Si NINGUNO contiene el target, cerramos.
            useEffect(() => {
                const onDocClick = (e) => {
                    if (moreRef.current && !moreRef.current.contains(e.target)) setMoreOpen(false);
                    const insideDesktop = userDesktopRef.current?.contains(e.target);
                    const insideMobile = userMobileRef.current?.contains(e.target);
                    if (!insideDesktop && !insideMobile) setUserMenuOpen(false);
                };
                document.addEventListener('mousedown', onDocClick);
                return () => document.removeEventListener('mousedown', onDocClick);
            }, []);

            const refreshCounts = useCallback(async () => {
                try {
                    const [cr, vac, al] = await Promise.all([
                        apiFetch('admin/change-requests'),
                        apiFetch('admin/vacations?status=pending').catch(() => ({ requests: [] })),
                        apiFetch('admin/location-alerts/pending-count').catch(() => ({ pending: 0 }))
                    ]);
                    setCounts({
                        changes: (cr.requests || []).length,
                        vacations: (vac.requests || []).length,
                        alerts: al.pending || 0
                    });
                } catch (_) {}
            }, []);
            useEffect(() => { refreshCounts(); }, [refreshCounts]);

            const goTab = (id) => {
                setActiveTab(id);
                setMoreOpen(false);
                setMobileMoreOpen(false);
            };

            // Util: button primario para desktop top bar.
            const PrimaryTabButton = ({ tab }) => {
                const active = activeTab === tab.id;
                const badge = tab.id === 'requests' && totalRequests > 0;
                return (
                    <button data-tour={`admin-tab-${tab.id}`} onClick={() => goTab(tab.id)}
                        className={`shrink-0 px-3 lg:px-4 py-2 rounded-xl text-xs lg:text-sm font-bold flex items-center gap-2 transition-all relative ${active ? 'btn-melius shadow-md' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 hover:text-slate-700 dark:hover:text-slate-100'}`}>
                        <Icon name={tab.icon} size={16} />
                        <span>{tab.label}</span>
                        {badge && <span className="ml-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-black flex items-center justify-center">{totalRequests}</span>}
                    </button>
                );
            };

            return (
                <div className="max-w-6xl w-full flex flex-col gap-4 sm:gap-6 pb-24 md:pb-0">
                    {/* HEADER */}
                    <div data-tour="admin-header" className="flex items-center justify-between bg-white dark:bg-slate-900 p-4 sm:p-6 rounded-2xl sm:rounded-3xl shadow-sm border border-slate-100 dark:border-slate-800 gap-4">
                        {/* Logo + titulo */}
                        <div className="flex items-center gap-3 sm:gap-4 min-w-0">
                            <div className="w-12 h-12 rounded-2xl bg-white dark:bg-slate-800 ring-melius flex items-center justify-center p-1.5 shrink-0">
                                <img src={effective.logo_url || '/assets/brands/melius.webp'} alt={effective.product_name} className="w-full h-full object-contain" />
                            </div>
                            <div className="min-w-0">
                                <h2 className="font-black text-lg sm:text-xl text-slate-800 dark:text-slate-100 font-display truncate">{effective.product_name}</h2>
                                <p className="text-[10px] sm:text-xs text-slate-400 uppercase font-bold tracking-widest truncate">{isSuper ? 'Super administrador' : 'Administrador'} &middot; {currentUser.name}</p>
                            </div>
                        </div>

                        {/* Tabs desktop (md+) */}
                        <div className="hidden md:flex gap-2 items-center flex-wrap justify-end">
                            {primaryTabs.map(t => <PrimaryTabButton key={t.id} tab={t} />)}
                            {/* Dropdown Mas */}
                            <div className="relative" ref={moreRef}>
                                <button onClick={() => setMoreOpen(o => !o)}
                                    className={`shrink-0 px-3 lg:px-4 py-2 rounded-xl text-xs lg:text-sm font-bold flex items-center gap-2 transition-all ${secondaryTabs.some(s => s.id === activeTab) ? 'btn-melius shadow-md' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 hover:text-slate-700 dark:hover:text-slate-100'}`}>
                                    <Icon name="MoreHorizontal" size={16} />
                                    <span>Más</span>
                                    <Icon name="ChevronDown" size={14} />
                                </button>
                                {moreOpen && (
                                    <div className="absolute right-0 mt-2 w-52 bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 py-2 z-30">
                                        {secondaryTabs.map(t => (
                                            <button key={t.id} onClick={() => goTab(t.id)}
                                                className={`w-full text-left px-4 py-2 text-sm font-bold flex items-center gap-3 transition-all ${activeTab === t.id ? 'bg-cyan-50 dark:bg-cyan-900/20 text-melius-cyan' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800'}`}>
                                                <Icon name={t.icon} size={16} />
                                                {t.label}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Menu usuario */}
                            <div data-tour="admin-user-menu" className="relative ml-1" ref={userDesktopRef}>
                                <button onClick={() => setUserMenuOpen(o => !o)}
                                    className="shrink-0 pl-2 pr-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs lg:text-sm font-bold flex items-center gap-2 transition-all">
                                    <div className="w-7 h-7 rounded-full bg-gradient-to-br from-melius-cyan to-melius-violet flex items-center justify-center text-white font-black text-xs">
                                        {(currentUser.name || '?').charAt(0).toUpperCase()}
                                    </div>
                                    <span className="max-w-[110px] truncate text-slate-600 dark:text-slate-200">{currentUser.name?.split(' ')[0] || 'Usuario'}</span>
                                    <Icon name="ChevronDown" size={14} className="text-slate-400" />
                                </button>
                                {userMenuOpen && (
                                    <div className="absolute right-0 mt-2 w-56 bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 py-2 z-30">
                                        <div className="px-4 py-2 border-b border-slate-100 dark:border-slate-800">
                                            <div className="text-xs text-slate-400">Sesión activa</div>
                                            <div className="text-sm font-bold text-slate-700 dark:text-slate-200 truncate">{currentUser.email || currentUser.name}</div>
                                        </div>
                                        {onGoDashboard && (
                                            <button onClick={() => { setUserMenuOpen(false); onGoDashboard(); }}
                                                className="w-full text-left px-4 py-2 text-sm font-bold flex items-center gap-3 text-melius-cyan hover:bg-cyan-50 dark:hover:bg-cyan-900/20">
                                                <Icon name="Clock" size={16} />
                                                Mi jornada
                                            </button>
                                        )}
                                        <button onClick={() => { setUserMenuOpen(false); onToggleTheme(); }}
                                            className="w-full text-left px-4 py-2 text-sm font-bold flex items-center gap-3 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                                            <Icon name={theme === 'dark' ? 'Sun' : 'Moon'} size={16} />
                                            {theme === 'dark' ? 'Tema claro' : 'Tema oscuro'}
                                        </button>
                                        {onStartTour && (
                                            <button onClick={() => { setUserMenuOpen(false); onStartTour(); }}
                                                className="w-full text-left px-4 py-2 text-sm font-bold flex items-center gap-3 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                                                <Icon name="ShieldCheck" size={16} />
                                                Ver tutorial
                                            </button>
                                        )}
                                        <div className="border-t border-slate-100 dark:border-slate-800 mt-1 pt-1">
                                            <button onClick={() => { setUserMenuOpen(false); onLogout(); }}
                                                className="w-full text-left px-4 py-2 text-sm font-bold flex items-center gap-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">
                                                <Icon name="LogOut" size={16} />
                                                Cerrar sesión
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Movil: pill con avatar + nombre + chevron (los tabs van en bottom nav).
                            Antes era solo un circulo, los usuarios no descubrian que era tappable. */}
                        <div className="md:hidden relative shrink-0" ref={userMobileRef}>
                            <button onClick={() => setUserMenuOpen(o => !o)}
                                aria-haspopup="menu"
                                aria-expanded={userMenuOpen}
                                aria-label="Abrir menú de usuario"
                                className="flex items-center gap-2 pl-1.5 pr-2.5 py-1.5 rounded-full bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 active:scale-95 transition-all border border-slate-200 dark:border-slate-700 shadow-sm">
                                <div className="w-8 h-8 rounded-full bg-gradient-to-br from-melius-cyan to-melius-violet flex items-center justify-center text-white font-black text-sm">
                                    {(currentUser.name || '?').charAt(0).toUpperCase()}
                                </div>
                                <Icon name="ChevronDown" size={16} className={`text-slate-500 dark:text-slate-300 transition-transform ${userMenuOpen ? 'rotate-180' : ''}`} />
                            </button>
                            {userMenuOpen && (
                                <div role="menu" className="absolute right-0 mt-2 w-64 max-w-[calc(100vw-1rem)] bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 py-2 z-30 anim-fade-in">
                                    <div className="px-4 py-3 border-b border-slate-100 dark:border-slate-800">
                                        <div className="text-[10px] uppercase tracking-widest text-slate-400 font-black">Sesión activa</div>
                                        <div className="text-sm font-bold text-slate-700 dark:text-slate-200 truncate mt-0.5">{currentUser.name}</div>
                                        <div className="text-[11px] text-slate-400 truncate" title={currentUser.email || ''}>{currentUser.email || ''}</div>
                                    </div>
                                    {onGoDashboard && (
                                        <button onClick={() => { setUserMenuOpen(false); onGoDashboard(); }}
                                            className="w-full text-left px-4 py-3 text-sm font-bold flex items-center gap-3 text-melius-cyan hover:bg-cyan-50 dark:hover:bg-cyan-900/20">
                                            <Icon name="Clock" size={18} />
                                            Ir al checador
                                        </button>
                                    )}
                                    <button onClick={() => { setUserMenuOpen(false); onToggleTheme(); }}
                                        className="w-full text-left px-4 py-3 text-sm font-bold flex items-center gap-3 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                                        <Icon name={theme === 'dark' ? 'Sun' : 'Moon'} size={18} />
                                        {theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'}
                                    </button>
                                    {onStartTour && (
                                        <button onClick={() => { setUserMenuOpen(false); onStartTour(); }}
                                            className="w-full text-left px-4 py-3 text-sm font-bold flex items-center gap-3 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                                            <Icon name="ShieldCheck" size={18} />
                                            Ver tutorial
                                        </button>
                                    )}
                                    <div className="border-t border-slate-100 dark:border-slate-800 mt-1 pt-1">
                                        <button onClick={() => { setUserMenuOpen(false); onLogout(); }}
                                            className="w-full text-left px-4 py-3 text-sm font-bold flex items-center gap-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">
                                            <Icon name="LogOut" size={18} />
                                            Cerrar sesión
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Sub-toggle dentro de Solicitudes: cambios | extras */}
                    {activeTab === 'requests' && (
                        <div className="flex gap-2 bg-white dark:bg-slate-900 p-2 rounded-2xl border border-slate-100 dark:border-slate-800 w-full sm:w-auto self-start">
                            <button onClick={() => setRequestsSub('changes')}
                                className={`px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-2 transition-all ${requestsSub === 'changes' ? 'btn-melius' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}`}>
                                <Icon name="ArrowLeftRight" size={14} />
                                Cambios
                                {counts.changes > 0 && <span className="min-w-[16px] h-[16px] px-1 rounded-full bg-red-500 text-white text-[9px] font-black flex items-center justify-center">{counts.changes}</span>}
                            </button>
                            <button onClick={() => setRequestsSub('vacations')}
                                className={`px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-2 transition-all ${requestsSub === 'vacations' ? 'btn-melius' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}`}>
                                <Icon name="CalendarDays" size={14} />
                                Vacaciones
                                {counts.vacations > 0 && <span className="min-w-[16px] h-[16px] px-1 rounded-full bg-emerald-500 text-white text-[9px] font-black flex items-center justify-center">{counts.vacations}</span>}
                            </button>
                        </div>
                    )}

                    {/* CONTENIDO */}
                    {activeTab === 'dashboard' && <DashboardTab />}
                    {activeTab === 'records' && <RecordsTab isSuper={isSuper} />}
                    {activeTab === 'agents' && <AgentsTab isSuper={isSuper} />}
                    {activeTab === 'requests' && requestsSub === 'changes' && <ChangesTab onChange={refreshCounts} />}
                    {activeTab === 'requests' && requestsSub === 'vacations' && <VacationsTab onChange={refreshCounts} />}
                    {activeTab === 'brands' && isSuper && <BrandsTab />}
                    {activeTab === 'companies' && <CompaniesTab isSuper={isSuper} />}
                    {activeTab === 'admins' && <AdminsTab currentUser={currentUser} isSuper={isSuper} />}
                    {activeTab === 'alerts' && <LocationAlertsTab onChange={refreshCounts} />}
                    {activeTab === 'security' && <SecurityEventsTab />}
                    {activeTab === 'tenant' && isSuper && <ConfigurationTab />}
                    {activeTab === 'emails' && isSuper && <EmailTemplatesTab />}

                    {/* BOTTOM NAV MOVIL */}
                    <nav className="md:hidden fixed bottom-0 left-0 right-0 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700 px-2 py-1 z-40 flex justify-around" style={{ paddingBottom: 'max(4px, env(safe-area-inset-bottom))' }}>
                        {primaryTabs.map(t => {
                            const active = activeTab === t.id;
                            const badge = t.id === 'requests' && totalRequests > 0;
                            return (
                                <button key={t.id} onClick={() => goTab(t.id)}
                                    className={`flex-1 flex flex-col items-center gap-0.5 py-1.5 rounded-lg transition-all relative ${active ? 'text-melius-cyan' : 'text-slate-400 dark:text-slate-500'}`}>
                                    <Icon name={t.icon} size={20} />
                                    <span className="text-[10px] font-bold">{t.label}</span>
                                    {badge && <span className="absolute top-0 right-2 min-w-[16px] h-[16px] px-1 rounded-full bg-red-500 text-white text-[9px] font-black flex items-center justify-center">{totalRequests}</span>}
                                    {active && <span className="absolute -top-1 left-1/2 -translate-x-1/2 w-8 h-1 rounded-full bg-melius-cyan"></span>}
                                </button>
                            );
                        })}
                        <button onClick={() => setMobileMoreOpen(true)}
                            className={`flex-1 flex flex-col items-center gap-0.5 py-1.5 rounded-lg transition-all relative ${secondaryTabs.some(s => s.id === activeTab) ? 'text-melius-cyan' : 'text-slate-400 dark:text-slate-500'}`}>
                            <Icon name="MoreHorizontal" size={20} />
                            <span className="text-[10px] font-bold">Más</span>
                        </button>
                    </nav>

                    {/* DRAWER MOVIL "MAS" — incluye accesos rapidos (checador, tema, logout)
                        ademas de las tabs secundarias. Asi el usuario los encuentra aunque
                        no descubra el avatar del header. */}
                    {mobileMoreOpen && (
                        <div className="md:hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-end" onClick={() => setMobileMoreOpen(false)}>
                            <div className="bg-white dark:bg-slate-900 w-full rounded-t-3xl p-4 space-y-1 anim-zoom-in max-h-[85vh] overflow-y-auto" onClick={e => e.stopPropagation()}>
                                <div className="w-12 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full mx-auto mb-3"></div>

                                <p className="text-[10px] font-black uppercase tracking-widest text-slate-400 px-3 pb-1">Acciones rápidas</p>
                                {onGoDashboard && (
                                    <button onClick={() => { setMobileMoreOpen(false); onGoDashboard(); }}
                                        className="w-full text-left px-3 py-3 text-sm font-bold flex items-center gap-3 rounded-xl text-melius-cyan bg-cyan-50/60 dark:bg-cyan-900/20 hover:bg-cyan-50 dark:hover:bg-cyan-900/30">
                                        <Icon name="Clock" size={18} />
                                        Ir al checador
                                    </button>
                                )}
                                <button onClick={() => { setMobileMoreOpen(false); onToggleTheme(); }}
                                    className="w-full text-left px-3 py-3 text-sm font-bold flex items-center gap-3 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                                    <Icon name={theme === 'dark' ? 'Sun' : 'Moon'} size={18} />
                                    {theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'}
                                </button>
                                {onStartTour && (
                                    <button onClick={() => { setMobileMoreOpen(false); onStartTour(); }}
                                        className="w-full text-left px-3 py-3 text-sm font-bold flex items-center gap-3 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                                        <Icon name="ShieldCheck" size={18} />
                                        Ver tutorial
                                    </button>
                                )}

                                {secondaryTabs.length > 0 && (
                                    <>
                                        <p className="text-[10px] font-black uppercase tracking-widest text-slate-400 px-3 pt-3 pb-1">Configuración</p>
                                        {secondaryTabs.map(t => (
                                            <button key={t.id} onClick={() => goTab(t.id)}
                                                className={`w-full text-left px-3 py-3 text-sm font-bold flex items-center gap-3 rounded-xl ${activeTab === t.id ? 'bg-cyan-50 dark:bg-cyan-900/20 text-melius-cyan' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800'}`}>
                                                <Icon name={t.icon} size={18} />
                                                {t.label}
                                            </button>
                                        ))}
                                    </>
                                )}

                                <div className="border-t border-slate-100 dark:border-slate-800 mt-2 pt-2">
                                    <button onClick={() => { setMobileMoreOpen(false); onLogout(); }}
                                        className="w-full text-left px-3 py-3 text-sm font-bold flex items-center gap-3 rounded-xl text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">
                                        <Icon name="LogOut" size={18} />
                                        Cerrar sesión
                                    </button>
                                </div>
                                <button onClick={() => setMobileMoreOpen(false)}
                                    className="w-full mt-2 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200">
                                    Cerrar
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            );
        };

        // === Tabs =============================================================

        const Kpi = ({ label, value }) => (
            <div className="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm">
                <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">{label}</p>
                <p className="font-black text-2xl sm:text-3xl text-slate-800 dark:text-slate-100 mt-1">{value}</p>
            </div>
        );

        const DashboardTab = () => {
            const { push: toast } = useToast();
            const [data, setData] = useState(null);
            const [companyId, setCompanyId] = useState('');
            const [companies, setCompanies] = useState([]);
            const [loading, setLoading] = useState(true);
            const [error, setError] = useState(null);
            const [alertsPending, setAlertsPending] = useState(0);
            const load = useCallback(async () => {
                setLoading(true); setError(null);
                try {
                    const cs = await apiFetch('admin/companies');
                    setCompanies(cs.companies || []);
                    const d = companyId
                        ? await apiFetch(`admin/dashboard/company/${companyId}`)
                        : await apiFetch('admin/dashboard/global');
                    setData(d.dashboard);
                    apiFetch('admin/location-alerts/pending-count')
                        .then(r => setAlertsPending(r.pending || 0))
                        .catch(() => {});
                } catch (e) { setError(e.message); }
                finally { setLoading(false); }
            }, [companyId]);
            useEffect(() => { load(); }, [load]);

            if (loading) return <LoadingScreen />;
            if (error) return <ErrorState message={error} onRetry={load} />;
            const t = data?.totals || {};
            return (
                <div className="space-y-4 sm:space-y-6">
                    {alertsPending > 0 && (
                        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-900/40 rounded-2xl p-4 flex items-center gap-3">
                            <Icon name="AlertTriangle" className="text-red-500" size={24} />
                            <div className="flex-1 min-w-0">
                                <p className="font-black text-sm text-red-700 dark:text-red-300">
                                    {alertsPending} alerta{alertsPending === 1 ? '' : 's'} de ubicacion pendiente{alertsPending === 1 ? '' : 's'}
                                </p>
                                <p className="text-xs text-red-600/80 dark:text-red-200/70">
                                    Cambios radicales detectados en marcajes recientes. Revisa el tab "Alertas geo".
                                </p>
                            </div>
                        </div>
                    )}
                    <div className="flex flex-wrap gap-3 items-end justify-between">
                        <div className="flex flex-col">
                            <label className="text-[10px] font-black uppercase tracking-widest text-slate-400 ml-2 mb-1">Empresa</label>
                            <Select value={companyId} onChange={(e) => setCompanyId(e.target.value)} size="sm">
                                <option value="">Todas</option>
                                {companies.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                        </div>
                    </div>
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                        <Kpi label="Registros hoy" value={t.records_today ?? 0} />
                        <Kpi label="Registros semana" value={t.records_week ?? 0} />
                        <Kpi label="Registros mes" value={t.records_month ?? 0} />
                        <Kpi label="Consultores activos" value={t.active_users ?? 0} />
                        <Kpi label="Retrasos del mes" value={t.late_month ?? 0} />
                        <Kpi label="Ausencias del mes" value={t.absences_month ?? 0} />
                        <Kpi label="Vacaciones pendientes" value={t.vacation_pending_count ?? 0} />
                        <Kpi label="Días aprobados (mes)" value={(t.vacation_approved_days ?? 0).toFixed(0)} />
                    </div>
                    {data?.by_company?.length > 0 && (
                        <div className="bg-white dark:bg-slate-900 p-4 sm:p-6 rounded-2xl border border-slate-100 dark:border-slate-800">
                            <h3 className="font-black uppercase tracking-widest text-xs text-slate-500 mb-3">Consultores activos por empresa</h3>
                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                {data.by_company.map(b => (
                                    <div key={b.company_id} className="flex items-center justify-between bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl">
                                        <span className="font-bold text-sm text-slate-700 dark:text-slate-200 truncate">{b.company_name}</span>
                                        <span className="font-mono font-black text-blue-600 dark:text-blue-300">{b.active_users}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            );
        };

        const RecordsTab = ({ isSuper }) => {
            const [records, setRecords] = useState([]);
            const [loading, setLoading] = useState(true);
            const [error, setError] = useState(null);
            const [period, setPeriod] = useState('month');
            const [companyId, setCompanyId] = useState('');
            const [companies, setCompanies] = useState([]);
            const load = useCallback(async () => {
                setLoading(true); setError(null);
                try {
                    const r = await apiFetch('admin/records');
                    setRecords(r.records || []);
                    if (companies.length === 0) {
                        const cs = await apiFetch('admin/companies');
                        setCompanies(cs.companies || []);
                    }
                } catch (e) { setError(e.message); }
                finally { setLoading(false); }
            }, [companies.length]);
            useEffect(() => { load(); }, [load]);

            const exportCsv = () => {
                const qs = new URLSearchParams({ period });
                if (companyId) qs.set('company_id', companyId);
                window.location.href = `/api/admin/records/export?${qs.toString()}`;
            };

            if (loading) return <LoadingScreen />;
            if (error) return <ErrorState message={error} onRetry={load} />;
            return (
                <div className="space-y-4">
                    <div className="flex flex-wrap items-end gap-3 bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-100 dark:border-slate-800">
                        <div className="flex flex-col">
                            <label className="text-[10px] font-black uppercase tracking-widest text-slate-400 ml-2 mb-1">Periodo</label>
                            <Select value={period} onChange={(e) => setPeriod(e.target.value)} size="sm">
                                <option value="week">Esta semana</option>
                                <option value="month">Este mes</option>
                                <option value="year">Este año</option>
                            </Select>
                        </div>
                        {isSuper && (
                            <div className="flex flex-col">
                                <label className="text-[10px] font-black uppercase tracking-widest text-slate-400 ml-2 mb-1">Empresa</label>
                                <Select value={companyId} onChange={(e) => setCompanyId(e.target.value)} size="sm">
                                    <option value="">Todas</option>
                                    {companies.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </Select>
                            </div>
                        )}
                        <button onClick={exportCsv} className="ml-auto px-4 py-2 rounded-xl bg-emerald-500 text-white font-bold text-sm hover:bg-emerald-600 transition-colors">
                            Exportar CSV
                        </button>
                    </div>
                    <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-800 overflow-hidden">
                        <div className="hidden md:block overflow-x-auto custom-scrollbar">
                            <table className="w-full text-left">
                                <thead className="bg-slate-50 dark:bg-slate-800/60 text-[10px] uppercase font-black text-slate-400 tracking-wider border-b dark:border-slate-800">
                                    <tr>
                                        <th className="px-6 py-4">Consultor</th>
                                        <th className="px-6 py-4">Empresa</th>
                                        <th className="px-6 py-4">Fecha</th>
                                        <th className="px-6 py-4">Entrada</th>
                                        <th className="px-6 py-4">Salida</th>
                                        <th className="px-6 py-4">Estado</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y dark:divide-slate-800 text-sm">
                                    {records.map(rec => <AdminRecordRow key={rec.id} rec={rec} />)}
                                    {records.length === 0 && <tr><td colSpan="6" className="px-6 py-20 text-center text-slate-300 italic">Sin registros aún</td></tr>}
                                </tbody>
                            </table>
                        </div>
                        <div className="md:hidden divide-y dark:divide-slate-800">
                            {records.map(rec => <AdminRecordCard key={rec.id} rec={rec} />)}
                            {records.length === 0 && <EmptyState message="Sin registros aún" />}
                        </div>
                    </div>
                </div>
            );
        };

        const CompanyForm = ({ initial, onSave, onCancel }) => {
            const [form, setForm] = useState(() => ({
                name: initial?.name || '',
                brand_id: initial?.brand_id || '',
                timezone: initial?.timezone || 'America/Mexico_City',
                work_start_time: initial?.work_start_time || '09:00',
                work_end_time: initial?.work_end_time || '18:00',
                work_days_mask: initial?.work_days_mask || 31,
                grace_minutes_late: initial?.grace_minutes_late ?? 15,
            }));
            const [brands, setBrands] = useState([]);
            const [submitting, setSubmitting] = useState(false);
            useEffect(() => { apiFetch('admin/brands').then(d => setBrands(d.brands || [])).catch(() => {}); }, []);
            const dayBits = [
                { bit: 1, label: 'L' }, { bit: 2, label: 'M' }, { bit: 4, label: 'X' },
                { bit: 8, label: 'J' }, { bit: 16, label: 'V' }, { bit: 32, label: 'S' }, { bit: 64, label: 'D' },
            ];
            const toggleDay = (bit) => setForm(f => ({ ...f, work_days_mask: (f.work_days_mask & bit) ? (f.work_days_mask & ~bit) : (f.work_days_mask | bit) }));
            const submit = async (e) => {
                e.preventDefault();
                setSubmitting(true);
                try {
                    const payload = {
                        ...form,
                        work_days_mask: parseInt(form.work_days_mask, 10),
                        grace_minutes_late: parseInt(form.grace_minutes_late, 10),
                        brand_id: form.brand_id ? parseInt(form.brand_id, 10) : null,
                    };
                    await onSave(payload);
                } finally { setSubmitting(false); }
            };
            return (
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label className="block">
                            <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Nombre</span>
                            <input required maxLength="100" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
                                className="w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold" />
                        </label>
                        <label className="block">
                            <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Marca paraguas</span>
                            <div className="mt-1">
                                <Select value={form.brand_id} onChange={(e) => setForm({ ...form, brand_id: e.target.value })}>
                                    <option value="">— Sin marca —</option>
                                    {brands.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                </Select>
                            </div>
                        </label>
                        <label className="block">
                            <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Zona horaria (IANA)</span>
                            <input required value={form.timezone} onChange={(e) => setForm({ ...form, timezone: e.target.value })}
                                className="w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-sm" />
                        </label>
                        <label className="block">
                            <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Entrada (HH:MM)</span>
                            <input required pattern="^[0-2][0-9]:[0-5][0-9]$" value={form.work_start_time} onChange={(e) => setForm({ ...form, work_start_time: e.target.value })}
                                className="w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono" />
                        </label>
                        <label className="block">
                            <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Salida (HH:MM)</span>
                            <input required pattern="^[0-2][0-9]:[0-5][0-9]$" value={form.work_end_time} onChange={(e) => setForm({ ...form, work_end_time: e.target.value })}
                                className="w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono" />
                        </label>
                        <label className="block">
                            <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Tolerancia tarde (min)</span>
                            <input type="number" min="0" max="60" value={form.grace_minutes_late} onChange={(e) => setForm({ ...form, grace_minutes_late: e.target.value })}
                                className="w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono" />
                        </label>
                    </div>
                    <div>
                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400 block mb-1">Días laborales</span>
                        <div className="flex gap-2">
                            {dayBits.map(d => (
                                <button type="button" key={d.bit} onClick={() => toggleDay(d.bit)}
                                    className={`w-10 h-10 rounded-xl font-black text-sm ${form.work_days_mask & d.bit ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-400'}`}>
                                    {d.label}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="flex gap-2 pt-2">
                        <button type="button" onClick={onCancel} className="flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200">Cancelar</button>
                        <button type="submit" disabled={submitting} className="flex-1 py-3 rounded-xl bg-blue-600 text-white font-black hover:bg-blue-700 disabled:opacity-60 flex items-center justify-center gap-2">
                            {submitting && <Icon name="Spinner" size={18} />}
                            Guardar
                        </button>
                    </div>
                </form>
            );
        };

        // Helper para multipart/form-data. apiFetch solo maneja JSON.
        // Reusa la cookie de sesion y el CSRF_TOKEN del front.
        async function apiUpload(path, formData) {
            const headers = { 'Accept': 'application/json' };
            if (CSRF_TOKEN) headers['X-CSRF-Token'] = CSRF_TOKEN;
            const res = await fetch(`${API_BASE}/${path.replace(/^\//, '')}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers,
                body: formData,
            });
            let payload;
            try { payload = await res.json(); }
            catch (_) { payload = { ok: false, error: { code: 'BAD_RESPONSE', message: 'Respuesta no JSON.' } }; }
            if (!res.ok || !payload.ok) {
                throw payload.error || { code: 'UNKNOWN', message: `HTTP ${res.status}` };
            }
            return payload.data;
        }

        // Wrapper de configuracion con sub-tabs: Branding | Licencia.
        // Solo super_admin lo ve (montaje gated en AdminPanel).
        const ConfigurationTab = () => {
            const [section, setSection] = useState('branding');
            return (
                <div className="space-y-4">
                    <div className="flex gap-2 bg-white dark:bg-slate-900 p-2 rounded-2xl border border-slate-100 dark:border-slate-800 w-full sm:w-auto self-start">
                        <button onClick={() => setSection('branding')}
                            className={`px-4 py-2 rounded-xl text-xs font-bold transition-all ${section === 'branding' ? 'btn-melius' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}`}>
                            Branding
                        </button>
                        <button onClick={() => setSection('billing')}
                            className={`px-4 py-2 rounded-xl text-xs font-bold transition-all ${section === 'billing' ? 'btn-melius' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}`}>
                            Licencia
                        </button>
                    </div>
                    {section === 'branding' && <TenantSettingsTab />}
                    {section === 'billing' && <BillingTab />}
                </div>
            );
        };

        // Formatea monto en centavos como precio legible: 2900 -> "$29.00 USD/mes".
        const formatMonthly = (cents, currency) => {
            if (!cents) return 'Bajo cotización';
            const v = (cents / 100).toFixed(cents % 100 === 0 ? 0 : 2);
            return `$${v} ${currency}/mes`;
        };

        const BillingTab = () => {
            const { push: toast } = useToast();
            const [plans, setPlans] = useState([]);
            const [sub, setSub] = useState(null);
            const [loading, setLoading] = useState(true);
            const [submitting, setSubmitting] = useState(false);
            const [connectModal, setConnectModal] = useState(null);

            const load = useCallback(async () => {
                setLoading(true);
                try {
                    const [pr, sr] = await Promise.all([
                        apiFetch('admin/billing/plans'),
                        apiFetch('admin/billing/subscription'),
                    ]);
                    setPlans(pr.plans || []);
                    setSub(sr.subscription || null);
                } catch (e) { toast('error', e.message); }
                finally { setLoading(false); }
            }, []);
            useEffect(() => { load(); }, [load]);

            const changePlan = async (planCode) => {
                if (!confirm(`¿Cambiar plan a "${planCode}"? Se aplicará de forma manual (sin cobro).`)) return;
                setSubmitting(true);
                try {
                    const r = await apiFetch('admin/billing/subscription', { method: 'PUT', body: { plan_code: planCode } });
                    setSub(r.subscription);
                    toast('success', 'Plan actualizado.');
                } catch (e) { toast('error', e.message); }
                finally { setSubmitting(false); }
            };

            const connectProvider = async (provider) => {
                setSubmitting(true);
                try {
                    await apiFetch('admin/billing/connect', { method: 'POST', body: { provider } });
                    toast('success', `${provider} conectado.`);
                    setConnectModal(null);
                    load();
                } catch (e) {
                    // Esperamos NOT_IMPLEMENTED 501 con next_steps.
                    setConnectModal({ provider, steps: e.next_steps || [e.message] });
                }
                finally { setSubmitting(false); }
            };

            if (loading) return <LoadingScreen />;

            const statusColor = {
                trial: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
                past_due: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300',
                canceled: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
                suspended: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
            }[sub?.status || 'trial'] || 'bg-slate-100 text-slate-700';

            return (
                <div className="space-y-4">
                    <div>
                        <h3 className="font-black text-lg text-slate-800 dark:text-slate-100">Licencia mensual</h3>
                        <p className="text-xs text-slate-500">Plan, estado de la suscripción y conexión con pasarela de pago.</p>
                    </div>

                    {/* Estado actual */}
                    <div className="rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
                        <div className="flex items-start justify-between gap-3 flex-wrap">
                            <div>
                                <div className="text-[10px] font-black uppercase tracking-widest text-slate-400">Plan actual</div>
                                <div className="text-2xl font-black text-slate-800 dark:text-slate-100">{sub?.plan_name || 'Trial'}</div>
                                <div className="text-xs text-slate-500 mt-1">
                                    {sub?.price_monthly_cents ? formatMonthly(sub.price_monthly_cents, sub.currency) : 'Sin costo (trial)'}
                                </div>
                            </div>
                            <div className="flex flex-col items-end gap-1.5">
                                <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest ${statusColor}`}>
                                    {sub?.status || 'trial'}
                                </span>
                                <span className="text-[10px] text-slate-400">
                                    {sub?.provider === 'none' ? 'Sin pasarela conectada' : `Pasarela: ${sub?.provider}`}
                                </span>
                            </div>
                        </div>
                        {sub?.features && <p className="text-xs text-slate-500 mt-3">{sub.features}</p>}
                    </div>

                    {/* Catalogo de planes */}
                    <div>
                        <h4 className="text-[11px] font-black uppercase tracking-widest text-slate-500 mb-2">Planes disponibles</h4>
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                            {plans.map(p => {
                                const current = sub?.plan_code === p.code;
                                return (
                                    <div key={p.code} className={`rounded-2xl border p-4 ${current ? 'border-melius-cyan bg-cyan-50 dark:bg-cyan-900/10' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900'}`}>
                                        <div className="flex items-center justify-between">
                                            <div className="font-black text-slate-800 dark:text-slate-100">{p.name}</div>
                                            {current && <span className="text-[10px] font-black uppercase tracking-widest text-melius-cyan">Actual</span>}
                                        </div>
                                        <div className="text-xl font-black text-slate-800 dark:text-slate-100 mt-2">{formatMonthly(p.price_monthly_cents, p.currency)}</div>
                                        {p.features && <p className="text-[11px] text-slate-500 mt-2 leading-relaxed">{p.features}</p>}
                                        <ul className="text-[11px] text-slate-500 mt-2 space-y-0.5">
                                            <li>Usuarios: <strong>{p.max_users ?? 'sin límite'}</strong></li>
                                            <li>Empresas: <strong>{p.max_companies ?? 'sin límite'}</strong></li>
                                        </ul>
                                        {!current && (
                                            <button onClick={() => changePlan(p.code)} disabled={submitting}
                                                className="mt-3 w-full py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 font-bold text-xs disabled:opacity-60">
                                                Asignar manualmente
                                            </button>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    {/* Conexion con pasarela */}
                    <div>
                        <h4 className="text-[11px] font-black uppercase tracking-widest text-slate-500 mb-2">Pasarela de pago</h4>
                        <div className="rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-4 space-y-3">
                            <p className="text-xs text-slate-500">
                                Conecta una pasarela para cobrar mensualmente de forma automática. Por ahora la suscripción se gestiona de forma manual.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <button onClick={() => connectProvider('stripe')} disabled={submitting}
                                    className="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold text-sm hover:bg-indigo-700 disabled:opacity-60 flex items-center gap-2">
                                    Conectar Stripe
                                </button>
                                <button onClick={() => connectProvider('paypal')} disabled={submitting}
                                    className="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold text-sm hover:bg-blue-700 disabled:opacity-60 flex items-center gap-2">
                                    Conectar PayPal
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Modal de instrucciones para conectar pasarela */}
                    <Modal open={!!connectModal} onClose={() => setConnectModal(null)} title="Conectar pasarela" maxWidth="max-w-lg">
                        {connectModal && (
                            <div className="space-y-3">
                                <h3 className="text-xl font-black text-slate-800 dark:text-slate-100">Conectar {connectModal.provider}</h3>
                                <div className="rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-900/40 p-4 text-sm text-amber-800 dark:text-amber-200">
                                    <p className="font-bold mb-2">Integración pendiente</p>
                                    <p className="text-xs">La conexión real con {connectModal.provider} está lista a nivel de modelo de datos pero requiere credenciales API. Pasos:</p>
                                </div>
                                <ol className="list-decimal list-inside text-xs text-slate-600 dark:text-slate-300 space-y-2 pl-2">
                                    {connectModal.steps.map((s, i) => <li key={i}>{s}</li>)}
                                </ol>
                                <button onClick={() => setConnectModal(null)} className="w-full py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200">Entendido</button>
                            </div>
                        )}
                    </Modal>
                </div>
            );
        };

        // Plantillas de correo editables por marca. Solo super_admin.
        // Permite modificar subject + intro + label del boton de las 4 plantillas
        // (invitation, password_reset, admin_disabled, admin_delete_receipt).
        // El layout HTML (hero, colores, footer) permanece blindado en mailer.php.
        const EMAIL_TEMPLATE_KINDS = [
            { id: 'invitation', label: 'Invitación', desc: 'Correo de bienvenida con credenciales temporales.' },
            { id: 'password_reset', label: 'Restablecer contraseña', desc: 'Enlace de reset cuando un usuario olvida su contraseña.' },
            { id: 'admin_disabled', label: 'Cuenta desactivada', desc: 'Aviso al administrador cuya cuenta fue desactivada.' },
            { id: 'admin_delete_receipt', label: 'Recibo de desactivación', desc: 'Confirmación al admin que ejecutó la desactivación.' },
        ];
        const EMAIL_PLACEHOLDERS_BY_KIND = {
            invitation: ['{{name}}', '{{company}}', '{{brand_name}}'],
            password_reset: ['{{name}}', '{{brand_name}}', '{{hours}}'],
            admin_disabled: ['{{name}}', '{{company}}', '{{actor_name}}', '{{brand_name}}'],
            admin_delete_receipt: ['{{actor_name}}', '{{target_name}}', '{{target_email}}', '{{company}}', '{{brand_name}}'],
        };

        const EmailTemplatesTab = () => {
            const { push: toast } = useToast();
            const [brands, setBrands] = useState([]);
            const [activeBrand, setActiveBrand] = useState(null);
            const [activeKind, setActiveKind] = useState('invitation');
            const [tpl, setTpl] = useState({ subject: '', intro_html: '', cta_label: '' });
            const [previewHtml, setPreviewHtml] = useState('');
            const [loading, setLoading] = useState(true);
            const [saving, setSaving] = useState(false);

            useEffect(() => {
                apiFetch('admin/brands').then(d => {
                    const list = d.brands || [];
                    setBrands(list);
                    if (list.length && activeBrand === null) setActiveBrand(list[0].id);
                }).catch(e => toast('error', e.message)).finally(() => setLoading(false));
            }, []);

            const loadTpl = useCallback(async () => {
                if (!activeBrand) return;
                try {
                    const d = await apiFetch(`admin/email-templates/${activeBrand}/${activeKind}`);
                    setTpl({
                        subject: d.template.subject || '',
                        intro_html: d.template.intro_html || '',
                        cta_label: d.template.cta_label || '',
                    });
                } catch (e) {
                    setTpl({ subject: '', intro_html: '', cta_label: '' });
                    if (e.status !== 404) toast('error', e.message);
                }
            }, [activeBrand, activeKind]);

            useEffect(() => { loadTpl(); }, [loadTpl]);

            const refreshPreview = useCallback(async () => {
                if (!activeBrand) return;
                try {
                    const d = await apiFetch('admin/email-templates/preview', {
                        method: 'POST',
                        body: {
                            brand_id: activeBrand,
                            kind: activeKind,
                            subject: tpl.subject,
                            intro_html: tpl.intro_html,
                            cta_label: tpl.cta_label,
                        },
                    });
                    setPreviewHtml(d.html || '');
                } catch (e) {
                    setPreviewHtml('<p style="color:#b91c1c;padding:16px;">Error generando vista previa: ' + e.message + '</p>');
                }
            }, [activeBrand, activeKind, tpl]);

            useEffect(() => {
                const t = setTimeout(refreshPreview, 400);
                return () => clearTimeout(t);
            }, [refreshPreview]);

            const save = async () => {
                if (!activeBrand) return;
                setSaving(true);
                try {
                    await apiFetch(`admin/email-templates/${activeBrand}/${activeKind}`, {
                        method: 'PUT',
                        body: tpl,
                    });
                    toast('success', 'Plantilla guardada.');
                    loadTpl();
                } catch (e) { toast('error', e.message); }
                finally { setSaving(false); }
            };

            const reset = async () => {
                if (!activeBrand) return;
                if (!confirm('¿Restablecer la plantilla al texto por defecto? Se perderán los cambios guardados.')) return;
                try {
                    await apiFetch(`admin/email-templates/${activeBrand}/${activeKind}`, { method: 'DELETE' });
                    toast('success', 'Plantilla restablecida.');
                    loadTpl();
                } catch (e) { toast('error', e.message); }
            };

            const insertPlaceholder = (ph) => {
                setTpl(prev => ({ ...prev, intro_html: (prev.intro_html || '') + ph }));
            };

            if (loading) return <LoadingScreen />;
            if (!brands.length) return <div className="p-6 text-slate-500">No hay marcas registradas. Crea una marca primero.</div>;

            const activeKindMeta = EMAIL_TEMPLATE_KINDS.find(k => k.id === activeKind);
            const placeholders = EMAIL_PLACEHOLDERS_BY_KIND[activeKind] || [];

            return (
                <div className="space-y-4">
                    <div>
                        <h2 className="text-lg font-black text-slate-800 dark:text-slate-100">Plantillas de correo</h2>
                        <p className="text-xs text-slate-500">Edita el contenido escrito de los correos del sistema. El diseño visual (colores, logos, layout) se controla desde la pestaña Marcas.</p>
                    </div>

                    <div className="flex flex-wrap gap-2 items-center">
                        <span className="text-[11px] font-black uppercase tracking-widest text-slate-500">Marca</span>
                        <Select value={activeBrand || ''} onChange={(e) => setActiveBrand(parseInt(e.target.value, 10))} size="sm">
                            {brands.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </Select>
                    </div>

                    <div className="flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-700">
                        {EMAIL_TEMPLATE_KINDS.map(k => (
                            <button key={k.id} type="button" onClick={() => setActiveKind(k.id)}
                                className={`px-3 py-2 text-xs font-bold border-b-2 transition-all ${activeKind === k.id ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}`}>
                                {k.label}
                            </button>
                        ))}
                    </div>

                    {activeKindMeta && <p className="text-xs text-slate-500">{activeKindMeta.desc}</p>}

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div className="space-y-3">
                            <div>
                                <label className="text-[11px] font-black uppercase tracking-widest text-slate-500">Asunto del correo</label>
                                <input type="text" value={tpl.subject} maxLength={200}
                                    onChange={(e) => setTpl({ ...tpl, subject: e.target.value })}
                                    className="mt-1 w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm" />
                            </div>
                            <div>
                                <label className="text-[11px] font-black uppercase tracking-widest text-slate-500">Texto del correo</label>
                                <textarea value={tpl.intro_html} rows="8" maxLength="4000"
                                    onChange={(e) => setTpl({ ...tpl, intro_html: e.target.value })}
                                    className="mt-1 w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm font-mono" />
                                <p className="text-[10px] text-slate-400 mt-1">{tpl.intro_html.length}/4000 caracteres. Solo texto plano; los saltos de línea se respetan.</p>
                            </div>
                            <div>
                                <label className="text-[11px] font-black uppercase tracking-widest text-slate-500">Placeholders disponibles</label>
                                <div className="flex flex-wrap gap-1 mt-1">
                                    {placeholders.map(ph => (
                                        <button key={ph} type="button" onClick={() => insertPlaceholder(ph)}
                                            className="px-2 py-1 text-[11px] font-mono rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200">
                                            {ph}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            {(activeKind === 'invitation' || activeKind === 'password_reset') && (
                                <div>
                                    <label className="text-[11px] font-black uppercase tracking-widest text-slate-500">Texto del botón</label>
                                    <input type="text" value={tpl.cta_label || ''} maxLength={80}
                                        onChange={(e) => setTpl({ ...tpl, cta_label: e.target.value })}
                                        placeholder="Dejar vacío para usar el texto por defecto"
                                        className="mt-1 w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm" />
                                </div>
                            )}
                            <div className="flex gap-2 pt-2">
                                <button type="button" onClick={save} disabled={saving}
                                    className="flex-1 py-2 rounded-xl btn-melius font-black disabled:opacity-60 flex items-center justify-center gap-2">
                                    {saving && <Icon name="Spinner" size={16} />}
                                    Guardar
                                </button>
                                <button type="button" onClick={reset}
                                    className="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200 text-sm">
                                    Restablecer
                                </button>
                            </div>
                        </div>

                        <div className="space-y-2">
                            <span className="text-[11px] font-black uppercase tracking-widest text-slate-500">Vista previa en vivo</span>
                            <div className="rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden bg-slate-100 dark:bg-slate-900 h-[60vh] sm:h-[560px] max-h-[560px] min-h-[320px]">
                                <iframe srcDoc={previewHtml} title="Vista previa correo"
                                    className="w-full h-full bg-white" sandbox="allow-same-origin" />
                            </div>
                            <p className="text-[10px] text-slate-400">El correo real se enviará con el logo y colores de la marca seleccionada.</p>
                        </div>
                    </div>
                </div>
            );
        };

        // Configuracion white-label del tenant (solo super_admin).
        // Permite editar nombre del producto, logo y colores que aplican al UI base.
        // Si el tenant no tiene logo aun, mostramos el logo Melius default como
        // referencia visual para que el super_admin sepa cual reemplazar.
        const DEFAULT_TENANT_LOGO = '/assets/brands/melius.webp';

        const TenantSettingsTab = () => {
            const { push: toast } = useToast();
            const { tenantBranding, setTenantBranding, refreshBranding } = useBranding();
            const [form, setForm] = useState({
                product_name: tenantBranding.product_name,
                primary_color: tenantBranding.primary_color,
                secondary_color: tenantBranding.secondary_color || '',
            });
            const [logoFile, setLogoFile] = useState(null);
            const [logoPreview, setLogoPreview] = useState(tenantBranding.logo_url || DEFAULT_TENANT_LOGO);
            const [hasCustomLogo, setHasCustomLogo] = useState(!!tenantBranding.logo_url);
            const [submitting, setSubmitting] = useState(false);
            const fileRef = useRef(null);

            useEffect(() => {
                (async () => {
                    try {
                        const d = await apiFetch('admin/tenant-settings');
                        if (d?.tenant) {
                            setForm({
                                product_name: d.tenant.product_name,
                                primary_color: d.tenant.primary_color,
                                secondary_color: d.tenant.secondary_color || '',
                            });
                            setHasCustomLogo(!!d.tenant.logo_url);
                            setLogoPreview(d.tenant.logo_url || DEFAULT_TENANT_LOGO);
                        }
                    } catch (e) { toast('error', e.message); }
                })();
            }, []);

            const pickFile = (f) => {
                if (!f) return;
                if (f.size > 512 * 1024) { toast('error', 'Logo supera 512 KB.'); return; }
                if (!['image/png','image/jpeg','image/webp','image/svg+xml'].includes(f.type)) {
                    toast('error', 'Formato no soportado. PNG, JPG, WebP o SVG.');
                    return;
                }
                setLogoFile(f);
                setLogoPreview(URL.createObjectURL(f));
                setHasCustomLogo(true);
            };

            const save = async () => {
                setSubmitting(true);
                try {
                    const body = {
                        product_name: form.product_name.trim(),
                        primary_color: form.primary_color,
                        secondary_color: form.secondary_color || null,
                    };
                    const r = await apiFetch('admin/tenant-settings', { method: 'PUT', body });
                    if (logoFile) {
                        const fd = new FormData();
                        fd.append('logo', logoFile);
                        const lr = await apiUpload('admin/tenant-settings/logo', fd);
                        setLogoFile(null);
                        if (lr?.logo_url) { setLogoPreview(lr.logo_url); setHasCustomLogo(true); }
                    }
                    // Refresca el context para que UI cambie en vivo.
                    if (r?.tenant) setTenantBranding(prev => ({ ...prev, ...r.tenant }));
                    await refreshBranding();
                    toast('success', 'Configuración del tenant guardada.');
                } catch (e) { toast('error', e.message || 'Error al guardar.'); }
                finally { setSubmitting(false); }
            };

            const gradient = form.secondary_color
                ? `linear-gradient(135deg, ${form.primary_color} 0%, ${form.secondary_color} 100%)`
                : form.primary_color;

            return (
                <div className="space-y-4">
                    <div>
                        <h3 className="font-black text-lg text-slate-800 dark:text-slate-100">Branding del producto</h3>
                        <p className="text-xs text-slate-500">Personaliza nombre, logo y colores que se ven antes del login y en el header del admin console. Aplica a toda la instalación. Las marcas paraguas (NetFy, Fullman) y los emails de invitación no cambian.</p>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                        {/* Columna izquierda: form */}
                        <div className="space-y-4">
                            <fieldset className="border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-3">
                                <legend className="px-2 text-[11px] font-black uppercase tracking-widest text-slate-500">Identidad del producto</legend>
                                <label className="block">
                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Nombre del producto</span>
                                    <input maxLength="120" value={form.product_name} onChange={(e) => setForm({ ...form, product_name: e.target.value })}
                                        className="w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold" />
                                    <p className="text-[10px] text-slate-400 mt-1">Aparece en el header (ej. "Melius Clockin", "NetFy Clockin", "Acme Time").</p>
                                </label>
                                <div>
                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Logo</span>
                                    <div className="mt-1 flex items-center gap-3">
                                        <div className="w-16 h-16 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 flex items-center justify-center p-1.5 shrink-0 relative">
                                            <img src={logoPreview} alt="logo" className="w-full h-full object-contain" />
                                            {!hasCustomLogo && !logoFile && (
                                                <span className="absolute -top-2 -right-2 text-[8px] font-black uppercase tracking-widest bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-1.5 py-0.5 rounded">default</span>
                                            )}
                                        </div>
                                        <button type="button" onClick={() => fileRef.current?.click()}
                                            className="px-3 py-2 rounded-xl bg-cyan-50 dark:bg-cyan-900/30 text-melius-cyan border border-cyan-100 dark:border-cyan-900/40 font-bold text-xs">
                                            {logoFile ? 'Cambiar' : (hasCustomLogo ? 'Reemplazar' : 'Subir logo')}
                                        </button>
                                        <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" className="hidden"
                                            onChange={(e) => pickFile(e.target.files?.[0])} />
                                    </div>
                                    <p className="text-[10px] text-slate-400 mt-1">PNG, JPG, WebP o SVG. Máx. 512 KB. {!hasCustomLogo && 'Estás viendo el logo default Melius — sube uno para personalizar.'}</p>
                                </div>
                            </fieldset>

                            <fieldset className="border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-3">
                                <legend className="px-2 text-[11px] font-black uppercase tracking-widest text-slate-500">Colores base del UI</legend>
                                <div className="grid grid-cols-2 gap-3">
                                    <label className="block">
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Primario</span>
                                        <div className="mt-1 flex items-center gap-2">
                                            <input type="color" value={form.primary_color} onChange={(e) => setForm({ ...form, primary_color: e.target.value })}
                                                className="w-14 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer" />
                                            <div className="flex-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold text-sm uppercase">
                                                {form.primary_color}
                                            </div>
                                        </div>
                                    </label>
                                    <label className="block">
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Secundario</span>
                                        <div className="mt-1 flex items-center gap-2">
                                            <input type="color" value={form.secondary_color || '#9909fe'} onChange={(e) => setForm({ ...form, secondary_color: e.target.value })}
                                                className="w-14 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer" />
                                            <div className="flex-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold text-sm uppercase">
                                                {form.secondary_color || '—'}
                                            </div>
                                        </div>
                                    </label>
                                </div>
                                <p className="text-[10px] text-slate-400">Estos colores aplican al gradiente del login y al botón primario. Cada empresa puede sobrescribirlos en su propia configuración.</p>
                            </fieldset>

                            <button type="button" onClick={save} disabled={submitting}
                                className="w-full py-3 rounded-xl btn-melius font-black disabled:opacity-60 flex items-center justify-center gap-2">
                                {submitting && <Icon name="Spinner" size={18} />}
                                Guardar configuración
                            </button>
                        </div>

                        {/* Columna derecha: preview UI */}
                        <div className="space-y-2">
                            <span className="text-[11px] font-black uppercase tracking-widest text-slate-500">Vista previa</span>
                            <div className="rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden bg-slate-100 dark:bg-slate-900 p-3">
                                {/* Preview header app */}
                                <div className="rounded-xl bg-white border border-slate-200 p-3 flex items-center gap-3 mb-2">
                                    <div className="w-10 h-10 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center p-1 shrink-0">
                                        {logoPreview ? <img src={logoPreview} alt="logo" className="w-full h-full object-contain" /> : <Icon name="ShieldCheck" size={20} className="text-slate-400" />}
                                    </div>
                                    <div className="min-w-0">
                                        <div className="font-black text-slate-800 truncate text-sm">{form.product_name || 'Producto'}</div>
                                        <div className="text-[9px] uppercase tracking-widest text-slate-400 font-bold">Admin Console</div>
                                    </div>
                                </div>
                                {/* Preview login hero */}
                                <div className="rounded-xl overflow-hidden">
                                    <div style={{ background: gradient, color: '#fff', padding: '20px 18px', textAlign: 'center' }}>
                                        {logoPreview && <div style={{ marginBottom: 8 }}>
                                            <img src={logoPreview} alt="logo" style={{ width: 44, height: 44, borderRadius: 10, background: '#fff', padding: 4, objectFit: 'contain', display: 'inline-block' }} />
                                        </div>}
                                        <div style={{ fontSize: 10, letterSpacing: '0.3em', textTransform: 'uppercase', opacity: 0.92, fontWeight: 700 }}>{(form.product_name || 'PRODUCTO').toUpperCase()}</div>
                                        <div style={{ fontSize: 18, fontWeight: 800, marginTop: 6 }}>Iniciar sesión</div>
                                    </div>
                                    <div style={{ padding: '14px 16px', background: '#fff', textAlign: 'center' }}>
                                        <span style={{ display: 'inline-block', padding: '9px 22px', background: form.primary_color, color: '#fff', borderRadius: 8, fontSize: 12, fontWeight: 800 }}>
                                            Entrar
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <p className="text-[10px] text-slate-400">El cambio se aplicará en vivo después de guardar.</p>
                        </div>
                    </div>
                </div>
            );
        };

        const BrandsTab = () => {
            const { push: toast } = useToast();
            const [rows, setRows] = useState([]);
            const [loading, setLoading] = useState(true);
            const [error, setError] = useState(null);
            const [editing, setEditing] = useState(null);
            const [creating, setCreating] = useState(false);

            const load = useCallback(async () => {
                setLoading(true); setError(null);
                try { const d = await apiFetch('admin/brands'); setRows(d.brands || []); }
                catch (e) { setError(e.message); }
                finally { setLoading(false); }
            }, []);
            useEffect(() => { load(); }, [load]);

            const save = async (body, brandId, logoFile) => {
                try {
                    let id = brandId;
                    if (brandId) {
                        await apiFetch(`admin/brands/${brandId}`, { method: 'PUT', body });
                    } else {
                        const r = await apiFetch('admin/brands', { method: 'POST', body });
                        id = r.id;
                    }
                    if (logoFile && id) {
                        const fd = new FormData();
                        fd.append('logo', logoFile);
                        await apiUpload(`admin/brands/${id}/logo`, fd);
                    }
                    toast('success', brandId ? 'Marca actualizada.' : 'Marca creada.');
                    setEditing(null); setCreating(false); load();
                } catch (e) { toast('error', e.message || 'Error guardando marca.'); }
            };

            const remove = async (b) => {
                if (!confirm(`¿Desactivar la marca "${b.name}"? Las empresas vinculadas quedarán sin marca.`)) return;
                try { await apiFetch(`admin/brands/${b.id}`, { method: 'DELETE' }); toast('success', 'Marca desactivada.'); load(); }
                catch (e) { toast('error', e.message); }
            };

            if (loading) return <LoadingScreen />;
            if (error) return <ErrorState message={error} onRetry={load} />;

            return (
                <div className="space-y-4">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <h3 className="font-black text-lg text-slate-800 dark:text-slate-100">Marcas paraguas</h3>
                            <p className="text-xs text-slate-500">Logo, colores y mensaje de bienvenida que cada consultor verá al recibir su correo.</p>
                        </div>
                        <button onClick={() => setCreating(true)} className="px-4 py-2 rounded-xl btn-melius font-bold text-sm">+ Nueva marca</button>
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                        {rows.map(b => (
                            <div key={b.id} className={`bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm ${!b.is_active ? 'opacity-60' : ''}`}>
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex items-start gap-3 min-w-0">
                                        <div className="w-14 h-14 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 flex items-center justify-center p-1.5 shrink-0">
                                            <img src={b.logo_url} alt={b.name} className="w-full h-full object-contain" />
                                        </div>
                                        <div className="min-w-0">
                                            <h4 className="font-black text-slate-800 dark:text-slate-100 truncate">{b.name}</h4>
                                            <div className="flex items-center gap-1.5 mt-2">
                                                <span title="Color primario" className="w-6 h-6 rounded-full border-2 border-white shadow ring-1 ring-slate-200 dark:ring-slate-700" style={{ backgroundColor: b.primary_color }}></span>
                                                {b.secondary_color && <span title="Color secundario" className="w-6 h-6 rounded-full border-2 border-white shadow ring-1 ring-slate-200 dark:ring-slate-700" style={{ backgroundColor: b.secondary_color }}></span>}
                                            </div>
                                            <p className="text-[11px] text-slate-500 mt-2">{b.companies_count} empresa{b.companies_count === 1 ? '' : 's'} vinculadas</p>
                                            {!b.is_active && <span className="inline-block mt-1 text-[10px] font-black uppercase tracking-widest text-red-500">Inactiva</span>}
                                        </div>
                                    </div>
                                    <div className="flex flex-col gap-2 shrink-0">
                                        <button onClick={() => setEditing(b)} className="text-xs font-bold text-blue-600 hover:underline">Editar</button>
                                        {b.is_active && <button onClick={() => remove(b)} className="text-xs font-bold text-red-500 hover:underline">Desactivar</button>}
                                    </div>
                                </div>
                            </div>
                        ))}
                        {rows.length === 0 && <EmptyState message="Sin marcas aún" />}
                    </div>
                    <Modal open={creating} onClose={() => setCreating(false)} title="Nueva marca" maxWidth="max-w-4xl" showHeader>
                        <BrandForm onSave={(b, file) => save(b, null, file)} onCancel={() => setCreating(false)} />
                    </Modal>
                    <Modal open={!!editing} onClose={() => setEditing(null)} title="Editar marca" maxWidth="max-w-4xl" showHeader>
                        {editing && <BrandForm initial={editing} onSave={(b, file) => save(b, editing.id, file)} onCancel={() => setEditing(null)} />}
                    </Modal>
                </div>
            );
        };

        // Convierte un componente RGB [0..255] a string hex "#rrggbb".
        const rgbToHex = (r, g, b) => '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');

        const BrandForm = ({ initial, onSave, onCancel }) => {
            const [form, setForm] = useState(() => ({
                name: initial?.name || '',
                primary_color: initial?.primary_color || '#07d6da',
                secondary_color: initial?.secondary_color || '#9909fe',
                welcome_intro: initial?.welcome_intro || '',
            }));
            const [logoFile, setLogoFile] = useState(null);
            const [logoPreview, setLogoPreview] = useState(initial?.logo_url || '');
            const [submitting, setSubmitting] = useState(false);
            const [showAdvanced, setShowAdvanced] = useState(false);
            const [extracting, setExtracting] = useState(false);
            const fileRef = useRef(null);
            const logoImgRef = useRef(null);

            const pickFile = (f) => {
                if (!f) return;
                if (f.size > 512 * 1024) { alert('El logo supera 512 KB.'); return; }
                if (!['image/png','image/jpeg','image/webp','image/svg+xml'].includes(f.type)) {
                    alert('Formato no soportado. Usa PNG, JPG, WebP o SVG.');
                    return;
                }
                setLogoFile(f);
                setLogoPreview(URL.createObjectURL(f));
            };

            // Extrae paleta dominante del logo usando ColorThief. SVG no es soportado
            // por canvas en algunos navegadores; en ese caso avisamos sin tirar error.
            const extractColorsFromLogo = () => {
                if (!logoPreview) { alert('Sube un logo primero.'); return; }
                if (typeof ColorThief === 'undefined') { alert('Extractor de colores no disponible.'); return; }
                setExtracting(true);
                const img = new Image();
                img.crossOrigin = 'anonymous';
                img.onload = () => {
                    try {
                        const ct = new ColorThief();
                        const palette = ct.getPalette(img, 5) || [];
                        if (palette.length === 0) { alert('No se pudo extraer la paleta.'); return; }
                        const primary = rgbToHex(palette[0][0], palette[0][1], palette[0][2]);
                        const secondary = palette[1] ? rgbToHex(palette[1][0], palette[1][1], palette[1][2]) : form.secondary_color;
                        setForm(prev => ({ ...prev, primary_color: primary, secondary_color: secondary }));
                    } catch (e) {
                        alert('No se pudo extraer la paleta. Si el logo es SVG, prueba con PNG o JPG.');
                    } finally {
                        setExtracting(false);
                    }
                };
                img.onerror = () => { setExtracting(false); alert('No se pudo cargar el logo para analizar.'); };
                img.src = logoPreview;
            };

            const submit = async (e) => {
                e.preventDefault();
                setSubmitting(true);
                try {
                    const body = {
                        name: form.name.trim(),
                        primary_color: form.primary_color,
                        secondary_color: form.secondary_color || null,
                        welcome_intro: form.welcome_intro.trim() || null,
                    };
                    await onSave(body, logoFile);
                } finally { setSubmitting(false); }
            };

            // Intro preview: replica la prioridad del backend (welcome_intro > fallback).
            const previewIntro = form.welcome_intro.trim()
                ? form.welcome_intro.trim()
                : `Tu equipo está usando ${form.name || 'la plataforma'} Clockin para marcar jornada de forma sencilla. Acabas de ser invitado a sumarte.`;
            const gradient = form.secondary_color
                ? `linear-gradient(135deg, ${form.primary_color} 0%, ${form.secondary_color} 100%)`
                : form.primary_color;

            return (
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                        {/* COLUMNA IZQUIERDA: edicion */}
                        <div className="space-y-4">
                            {/* Bloque 1: Identidad */}
                            <fieldset className="border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-3">
                                <legend className="px-2 text-[11px] font-black uppercase tracking-widest text-slate-500">Identidad</legend>
                                <label className="block">
                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Nombre de la marca</span>
                                    <input required maxLength="120" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
                                        className="w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold" />
                                </label>
                                <div>
                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Logo</span>
                                    <div className="mt-1 flex items-center gap-3">
                                        {logoPreview && (
                                            <div className="w-16 h-16 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 flex items-center justify-center p-1.5 shrink-0">
                                                <img ref={logoImgRef} src={logoPreview} alt="preview" className="w-full h-full object-contain" />
                                            </div>
                                        )}
                                        <button type="button" onClick={() => fileRef.current?.click()}
                                            className="px-3 py-2 rounded-xl bg-cyan-50 dark:bg-cyan-900/30 text-melius-cyan border border-cyan-100 dark:border-cyan-900/40 font-bold text-xs">
                                            {logoFile ? 'Cambiar' : (logoPreview ? 'Reemplazar' : 'Seleccionar')}
                                        </button>
                                        <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" className="hidden"
                                            onChange={(e) => pickFile(e.target.files?.[0])} />
                                    </div>
                                    <p className="text-[10px] text-slate-400 mt-1">PNG, JPG, WebP o SVG. Máx. 512 KB.</p>
                                </div>
                            </fieldset>

                            {/* Bloque 2: Branding visual */}
                            <fieldset className="border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-3">
                                <legend className="px-2 text-[11px] font-black uppercase tracking-widest text-slate-500">Colores de marca</legend>
                                <div className="grid grid-cols-2 gap-3">
                                    <label className="block">
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Primario</span>
                                        <div className="mt-1 flex items-center gap-2">
                                            <input type="color" value={form.primary_color} onChange={(e) => setForm({ ...form, primary_color: e.target.value })}
                                                className="w-14 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer" />
                                            <div className="flex-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold text-sm uppercase">
                                                {form.primary_color}
                                            </div>
                                        </div>
                                    </label>
                                    <label className="block">
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Secundario</span>
                                        <div className="mt-1 flex items-center gap-2">
                                            <input type="color" value={form.secondary_color || '#9909fe'} onChange={(e) => setForm({ ...form, secondary_color: e.target.value })}
                                                className="w-14 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer" />
                                            <div className="flex-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold text-sm uppercase">
                                                {form.secondary_color || '—'}
                                            </div>
                                        </div>
                                    </label>
                                </div>
                                <button type="button" onClick={extractColorsFromLogo} disabled={!logoPreview || extracting}
                                    className="w-full px-3 py-2 rounded-xl bg-gradient-to-r from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-700 border border-slate-200 dark:border-slate-600 font-bold text-xs text-slate-700 dark:text-slate-200 disabled:opacity-50 flex items-center justify-center gap-2">
                                    {extracting ? 'Analizando logo...' : 'Sugerir colores desde el logo'}
                                </button>
                                <p className="text-[10px] text-slate-400">Detectamos los dos colores dominantes del logo. Puedes ajustarlos después.</p>
                            </fieldset>

                            {/* Bloque 3: Mensaje */}
                            <fieldset className="border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-2">
                                <legend className="px-2 text-[11px] font-black uppercase tracking-widest text-slate-500">Mensaje de bienvenida</legend>
                                <textarea value={form.welcome_intro} rows="4" maxLength="2000"
                                    onChange={(e) => setForm({ ...form, welcome_intro: e.target.value })}
                                    placeholder="Si lo dejas vacío, usamos un saludo genérico con el nombre de la empresa."
                                    className="w-full mt-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm" />
                                <p className="text-[10px] text-slate-400">{form.welcome_intro.length}/2000 caracteres. Aparece en el correo de invitación.</p>
                            </fieldset>

                            {/* Acordeon: detalles tecnicos */}
                            <button type="button" onClick={() => setShowAdvanced(s => !s)}
                                className="text-[11px] font-bold text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 underline">
                                {showAdvanced ? 'Ocultar detalles técnicos' : 'Mostrar detalles técnicos'}
                            </button>
                            {showAdvanced && (
                                <div className="bg-slate-50 dark:bg-slate-800/50 rounded-xl p-3 space-y-2 text-xs">
                                    {initial?.slug && (
                                        <div className="flex justify-between">
                                            <span className="font-black uppercase tracking-widest text-slate-400 text-[10px]">Slug</span>
                                            <span className="font-mono text-slate-600 dark:text-slate-300">{initial.slug}</span>
                                        </div>
                                    )}
                                    <div className="flex justify-between">
                                        <span className="font-black uppercase tracking-widest text-slate-400 text-[10px]">Hex primario</span>
                                        <input type="text" value={form.primary_color} onChange={(e) => setForm({ ...form, primary_color: e.target.value })}
                                            pattern="^#[0-9a-fA-F]{3,6}$" required
                                            className="font-mono text-xs px-2 py-0.5 rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900" />
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="font-black uppercase tracking-widest text-slate-400 text-[10px]">Hex secundario</span>
                                        <input type="text" value={form.secondary_color || ''} onChange={(e) => setForm({ ...form, secondary_color: e.target.value })}
                                            pattern="^#[0-9a-fA-F]{3,6}$|^$"
                                            className="font-mono text-xs px-2 py-0.5 rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900" />
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* COLUMNA DERECHA: preview email */}
                        <div className="space-y-2">
                            <span className="text-[11px] font-black uppercase tracking-widest text-slate-500">Vista previa del correo de invitación</span>
                            <div className="rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden bg-slate-100 dark:bg-slate-900 p-3">
                                <div className="rounded-xl overflow-hidden bg-white border border-slate-200" style={{ fontFamily: 'Segoe UI, Arial, sans-serif' }}>
                                    {/* Hero gradient */}
                                    <div style={{ background: gradient, color: '#fff', padding: '24px 20px', textAlign: 'center' }}>
                                        {logoPreview && (
                                            <div style={{ marginBottom: 10 }}>
                                                <img src={logoPreview} alt="logo" style={{ width: 56, height: 56, borderRadius: 12, background: '#fff', padding: 5, objectFit: 'contain', display: 'inline-block' }} />
                                            </div>
                                        )}
                                        <div style={{ fontSize: 10, letterSpacing: '0.3em', textTransform: 'uppercase', opacity: 0.92, fontWeight: 700 }}>{(form.name || 'Marca').toUpperCase()} CLOCKIN</div>
                                        <div style={{ fontSize: 20, fontWeight: 800, marginTop: 8 }}>Bienvenido a bordo</div>
                                        <div style={{ fontSize: 12, marginTop: 6, opacity: 0.95 }}>Marca jornada en segundos. Sin Excel. Sin fricción.</div>
                                    </div>
                                    {/* Body */}
                                    <div style={{ padding: '18px 20px', fontSize: 13, color: '#1f2937', lineHeight: 1.55 }}>
                                        <p style={{ margin: '0 0 6px 0' }}>Hola <strong>[Nombre del consultor]</strong>,</p>
                                        <p style={{ margin: '0 0 6px 0', whiteSpace: 'pre-line' }}>{previewIntro}</p>
                                    </div>
                                    {/* CTA */}
                                    <div style={{ padding: '4px 20px 18px 20px', textAlign: 'center' }}>
                                        <span style={{ display: 'inline-block', padding: '10px 22px', background: form.primary_color, color: '#fff', borderRadius: 8, fontSize: 13, fontWeight: 800 }}>
                                            Entrar a {form.name || 'la plataforma'} Clockin
                                        </span>
                                    </div>
                                    <div style={{ padding: '10px 20px', background: '#f8fafc', borderTop: '1px solid #e2e8f0', fontSize: 10, color: '#64748b', textAlign: 'center' }}>
                                        Enviado por noreply@fullman.tech vía {form.name || 'la plataforma'} Clockin.
                                    </div>
                                </div>
                            </div>
                            <p className="text-[10px] text-slate-400">Aproximación visual. El correo real usa tablas HTML compatibles con Gmail/Outlook.</p>
                        </div>
                    </div>

                    <div className="flex gap-2 pt-2">
                        <button type="button" onClick={onCancel} className="flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200">Cancelar</button>
                        <button type="submit" disabled={submitting} className="flex-1 py-3 rounded-xl btn-melius font-black disabled:opacity-60 flex items-center justify-center gap-2">
                            {submitting && <Icon name="Spinner" size={18} />}
                            Guardar
                        </button>
                    </div>
                </form>
            );
        };

        const CompaniesTab = ({ isSuper }) => {
            const { push: toast } = useToast();
            const [rows, setRows] = useState([]);
            const [loading, setLoading] = useState(true);
            const [error, setError] = useState(null);
            const [editing, setEditing] = useState(null);
            const [creating, setCreating] = useState(false);
            const [brandingTarget, setBrandingTarget] = useState(null);
            const load = useCallback(async () => {
                setLoading(true); setError(null);
                try { const d = await apiFetch('admin/companies'); setRows(d.companies || []); }
                catch (e) { setError(e.message); }
                finally { setLoading(false); }
            }, []);
            useEffect(() => { load(); }, [load]);

            const save = async (body) => {
                try {
                    if (editing) {
                        await apiFetch(`admin/companies/${editing.id}`, { method: 'PUT', body });
                        toast('success', 'Empresa actualizada.');
                    } else {
                        await apiFetch('admin/companies', { method: 'POST', body });
                        toast('success', 'Empresa creada.');
                    }
                    setEditing(null); setCreating(false); load();
                } catch (e) { toast('error', e.message); }
            };
            const remove = async (c) => {
                if (!confirm(`¿Eliminar empresa "${c.name}"?`)) return;
                try { await apiFetch(`admin/companies/${c.id}`, { method: 'DELETE' }); toast('success', 'Empresa eliminada.'); load(); }
                catch (e) { toast('error', e.message); }
            };
            if (loading) return <LoadingScreen />;
            if (error) return <ErrorState message={error} onRetry={load} />;
            return (
                <div className="space-y-4">
                    <div className="flex justify-end">
                        {isSuper && (
                            <button onClick={() => setCreating(true)} className="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold text-sm hover:bg-blue-700">+ Nueva empresa</button>
                        )}
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                        {rows.map(c => (
                            <div key={c.id} className="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex items-start gap-3 min-w-0">
                                        {c.brand_logo_url && (
                                            <div className="w-10 h-10 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 flex items-center justify-center p-1 shrink-0" title={c.brand_name}>
                                                <img src={c.brand_logo_url} alt={c.brand_name} className="w-full h-full object-contain" />
                                            </div>
                                        )}
                                        <div className="min-w-0">
                                            <h4 className="font-black text-slate-800 dark:text-slate-100 truncate">{c.name}</h4>
                                            {c.brand_name && <p className="text-[10px] font-black uppercase tracking-widest text-melius-cyan">{c.brand_name}</p>}
                                            <p className="text-[11px] text-slate-400 font-mono mt-1">{c.timezone} · {c.work_start_time}–{c.work_end_time} · gracia {c.grace_minutes_late}m</p>
                                            <p className="text-[11px] text-slate-500 mt-1">Consultores activos: {c.active_users}</p>
                                        </div>
                                    </div>
                                    <div className="flex flex-col gap-1 shrink-0 items-end">
                                        <button onClick={() => setEditing(c)} className="text-xs font-bold text-blue-600 hover:underline">Editar</button>
                                        <button onClick={() => setBrandingTarget(c)} className="text-xs font-bold text-purple-600 hover:underline" title="Sobrescribir colores y logo solo para esta empresa">Branding</button>
                                        {isSuper && c.active_users === 0 && <button onClick={() => remove(c)} className="text-xs font-bold text-red-500 hover:underline">Eliminar</button>}
                                    </div>
                                </div>
                            </div>
                        ))}
                        {rows.length === 0 && <EmptyState message="Sin empresas aún" />}
                    </div>
                    <Modal open={creating} onClose={() => setCreating(false)} title="Nueva empresa" maxWidth="max-w-2xl" showHeader>
                        <CompanyForm onSave={save} onCancel={() => setCreating(false)} />
                    </Modal>
                    <Modal open={!!editing} onClose={() => setEditing(null)} title="Editar empresa" maxWidth="max-w-2xl" showHeader>
                        {editing && <CompanyForm initial={editing} onSave={save} onCancel={() => setEditing(null)} />}
                    </Modal>
                    <Modal open={!!brandingTarget} onClose={() => setBrandingTarget(null)} title="Branding de empresa" maxWidth="max-w-3xl" showHeader>
                        {brandingTarget && <CompanyBrandingForm company={brandingTarget} onClose={() => setBrandingTarget(null)} onSaved={() => { setBrandingTarget(null); load(); }} />}
                    </Modal>
                </div>
            );
        };

        // Modal de override de branding por empresa. Permite sobrescribir colores
        // sobre la marca paraguas o el tenant. Si todos los campos se dejan vacios,
        // la empresa vuelve a heredar del nivel superior.
        const CompanyBrandingForm = ({ company, onClose, onSaved }) => {
            const { push: toast } = useToast();
            const { tenantBranding } = useBranding();
            const [primary, setPrimary] = useState(company.branding_primary || '');
            const [secondary, setSecondary] = useState(company.branding_secondary || '');
            const [logoUrl, setLogoUrl] = useState(company.branding_logo_url || '');
            const [submitting, setSubmitting] = useState(false);

            // Cascada: que esta heredando esta empresa antes de aplicar override?
            const inheritedPrimary = company.brand_primary || tenantBranding.primary_color;
            const inheritedSecondary = company.brand_secondary || tenantBranding.secondary_color;
            const inheritedLogo = company.brand_logo_url || tenantBranding.logo_url || '/assets/brands/melius.webp';
            const inheritedSource = company.brand_name ? `marca "${company.brand_name}"` : 'tenant';

            // Branding efectivo en este momento (lo que se ve si guardamos).
            const effPrimary = primary || inheritedPrimary;
            const effSecondary = secondary || inheritedSecondary;
            const effLogo = logoUrl || inheritedLogo;
            const gradient = effSecondary ? `linear-gradient(135deg, ${effPrimary} 0%, ${effSecondary} 100%)` : effPrimary;

            const save = async () => {
                setSubmitting(true);
                try {
                    await apiFetch(`admin/companies/${company.id}/branding`, {
                        method: 'PUT',
                        body: {
                            branding_primary: primary || null,
                            branding_secondary: secondary || null,
                            branding_logo_url: logoUrl || null,
                        }
                    });
                    toast('success', 'Branding guardado.');
                    onSaved();
                } catch (e) { toast('error', e.message || 'Error al guardar.'); }
                finally { setSubmitting(false); }
            };

            const reset = () => {
                setPrimary(''); setSecondary(''); setLogoUrl('');
            };

            return (
                <div className="space-y-4">
                    <div>
                        <p className="text-sm text-slate-700 dark:text-slate-200 font-bold">{company.name}</p>
                        <p className="text-xs text-slate-500 mt-1">Sobrescribe el branding heredado (de {inheritedSource}). Deja todos los campos vacíos para volver al heredado.</p>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                        {/* Form */}
                        <div className="space-y-4">
                            <fieldset className="border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-3">
                                <legend className="px-2 text-[11px] font-black uppercase tracking-widest text-slate-500">Colores override</legend>
                                <label className="block">
                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Primario (deja vacío = heredar {inheritedPrimary})</span>
                                    <div className="mt-1 flex items-center gap-2">
                                        <input type="color" value={primary || inheritedPrimary} onChange={(e) => setPrimary(e.target.value)}
                                            className="w-14 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer" />
                                        <input type="text" value={primary} onChange={(e) => setPrimary(e.target.value)}
                                            placeholder={inheritedPrimary}
                                            pattern="^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$|^$"
                                            className="flex-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-sm uppercase" />
                                    </div>
                                </label>
                                <label className="block">
                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Secundario (deja vacío = heredar {inheritedSecondary || 'ninguno'})</span>
                                    <div className="mt-1 flex items-center gap-2">
                                        <input type="color" value={secondary || inheritedSecondary || '#9909fe'} onChange={(e) => setSecondary(e.target.value)}
                                            className="w-14 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer" />
                                        <input type="text" value={secondary} onChange={(e) => setSecondary(e.target.value)}
                                            placeholder={inheritedSecondary || '—'}
                                            pattern="^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$|^$"
                                            className="flex-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-sm uppercase" />
                                    </div>
                                </label>
                            </fieldset>

                            <fieldset className="border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-2">
                                <legend className="px-2 text-[11px] font-black uppercase tracking-widest text-slate-500">Logo override (URL)</legend>
                                <input type="text" value={logoUrl} onChange={(e) => setLogoUrl(e.target.value)}
                                    placeholder={`Heredado: ${inheritedLogo}`}
                                    className="w-full px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-xs" />
                                <p className="text-[10px] text-slate-400">URL absoluta o relativa al sitio. Upload de archivo por empresa se agregará después. Deja vacío para heredar.</p>
                            </fieldset>

                            <button type="button" onClick={reset}
                                className="text-[11px] font-bold text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 underline">
                                Limpiar todo y volver al branding heredado
                            </button>
                        </div>

                        {/* Preview */}
                        <div className="space-y-2">
                            <span className="text-[11px] font-black uppercase tracking-widest text-slate-500">Preview</span>
                            <div className="rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden bg-slate-100 dark:bg-slate-900 p-3 space-y-2">
                                <div className="rounded-xl bg-white border border-slate-200 p-3 flex items-center gap-3">
                                    <div className="w-10 h-10 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center p-1 shrink-0">
                                        <img src={effLogo} alt="logo" className="w-full h-full object-contain" onError={(e) => { e.target.src = '/assets/brands/melius.webp'; }} />
                                    </div>
                                    <div className="min-w-0">
                                        <div className="font-black text-slate-800 truncate text-sm">{tenantBranding.product_name}</div>
                                        <div className="text-[9px] uppercase tracking-widest text-slate-400 font-bold">{company.name}</div>
                                    </div>
                                </div>
                                <div className="rounded-xl overflow-hidden">
                                    <div style={{ background: gradient, color: '#fff', padding: '14px 16px', textAlign: 'center' }}>
                                        <div style={{ fontSize: 10, letterSpacing: '0.3em', textTransform: 'uppercase', opacity: 0.92, fontWeight: 700 }}>{company.name.toUpperCase()}</div>
                                        <div style={{ fontSize: 14, fontWeight: 800, marginTop: 4 }}>Vista del UI con tu branding</div>
                                    </div>
                                    <div style={{ padding: '10px 14px', background: '#fff', textAlign: 'center' }}>
                                        <span style={{ display: 'inline-block', padding: '7px 16px', background: effPrimary, color: '#fff', borderRadius: 7, fontSize: 11, fontWeight: 800 }}>
                                            Entrar
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="flex gap-2 pt-2">
                        <button type="button" onClick={onClose} disabled={submitting}
                            className="flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200 disabled:opacity-60">
                            Cancelar
                        </button>
                        <button type="button" onClick={save} disabled={submitting}
                            className="flex-1 py-3 rounded-xl btn-melius font-black disabled:opacity-60 flex items-center justify-center gap-2">
                            {submitting && <Icon name="Spinner" size={18} />}
                            Guardar branding
                        </button>
                    </div>
                </div>
            );
        };

        // Modal de confirmacion previo al envio de invitacion. Muestra destinatario,
        // empresa+marca y un mini-preview del email con los colores reales de la marca.
        const InviteConfirmModal = ({ open, payload, company, onConfirm, onCancel, submitting }) => {
            if (!open || !payload) return null;
            const primary = company?.brand_primary || '#07d6da';
            const secondary = company?.brand_secondary || '#9909fe';
            const gradient = `linear-gradient(135deg, ${primary} 0%, ${secondary} 100%)`;
            const brandName = company?.brand_name || 'Melius';
            const logoUrl = company?.brand_logo_url || null;
            const intro = (company?.brand_welcome && company.brand_welcome.trim())
                ? company.brand_welcome.trim()
                : `Tu equipo en ${company?.name || 'tu empresa'} está usando ${brandName} Clockin para marcar jornada de forma sencilla.`;
            const roleLabel = payload.role === 'admin' ? 'Administrador' : 'Consultor';

            return (
                <Modal open={open} onClose={onCancel} title="Confirmar invitación" maxWidth="max-w-3xl" showHeader>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* Resumen destinatario */}
                        <div className="space-y-3">
                            <div className="rounded-2xl border border-slate-200 dark:border-slate-700 p-4 space-y-2">
                                <div className="text-[11px] font-black uppercase tracking-widest text-slate-500">Destinatario</div>
                                <div>
                                    <div className="text-xs text-slate-400">Correo</div>
                                    <div className="font-bold text-slate-800 dark:text-slate-100 truncate">{payload.email}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-slate-400">Nombre</div>
                                    <div className="font-bold text-slate-800 dark:text-slate-100">{payload.name}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-slate-400">Rol</div>
                                    <div className="font-bold text-slate-800 dark:text-slate-100">{roleLabel}</div>
                                </div>
                                {company && (
                                    <div>
                                        <div className="text-xs text-slate-400">Empresa / Marca</div>
                                        <div className="font-bold text-slate-800 dark:text-slate-100">{company.name}{company.brand_name ? ` — ${company.brand_name}` : ''}</div>
                                    </div>
                                )}
                            </div>
                            <p className="text-[11px] text-slate-500">
                                Al confirmar se creará la cuenta con una contraseña temporal y se enviará el correo de invitación al destinatario. El usuario deberá cambiar su contraseña al ingresar.
                            </p>
                        </div>

                        {/* Mini-preview del email */}
                        <div className="space-y-2">
                            <span className="text-[11px] font-black uppercase tracking-widest text-slate-500">Vista previa</span>
                            <div className="rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden bg-slate-100 dark:bg-slate-900 p-3">
                                <div className="rounded-xl overflow-hidden bg-white border border-slate-200" style={{ fontFamily: 'Segoe UI, Arial, sans-serif' }}>
                                    <div style={{ background: gradient, color: '#fff', padding: '20px 18px', textAlign: 'center' }}>
                                        {logoUrl && (
                                            <div style={{ marginBottom: 8 }}>
                                                <img src={logoUrl} alt={brandName} style={{ width: 48, height: 48, borderRadius: 10, background: '#fff', padding: 4, objectFit: 'contain', display: 'inline-block' }} />
                                            </div>
                                        )}
                                        <div style={{ fontSize: 10, letterSpacing: '0.3em', textTransform: 'uppercase', opacity: 0.92, fontWeight: 700 }}>{brandName.toUpperCase()} CLOCKIN</div>
                                        <div style={{ fontSize: 18, fontWeight: 800, marginTop: 6 }}>Bienvenido a bordo, {payload.name.split(' ')[0]}</div>
                                    </div>
                                    <div style={{ padding: '14px 18px', fontSize: 12, color: '#1f2937', lineHeight: 1.55 }}>
                                        <p style={{ margin: '0 0 6px 0' }}>Hola <strong>{payload.name}</strong>,</p>
                                        <p style={{ margin: '0 0 6px 0', whiteSpace: 'pre-line' }}>{intro}</p>
                                    </div>
                                    <div style={{ padding: '4px 18px 16px 18px', textAlign: 'center' }}>
                                        <span style={{ display: 'inline-block', padding: '9px 20px', background: primary, color: '#fff', borderRadius: 8, fontSize: 12, fontWeight: 800 }}>
                                            Entrar a {brandName} Clockin
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="flex gap-2 pt-4">
                        <button type="button" onClick={onCancel} disabled={submitting}
                            className="flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200 disabled:opacity-60">
                            Volver
                        </button>
                        <button type="button" onClick={onConfirm} disabled={submitting}
                            className="flex-1 py-3 rounded-xl bg-blue-600 text-white font-black hover:bg-blue-700 disabled:opacity-60 flex items-center justify-center gap-2">
                            {submitting && <Icon name="Spinner" size={18} />}
                            Confirmar y enviar
                        </button>
                    </div>
                </Modal>
            );
        };

        const InviteForm = ({ defaultRole, isSuper, onSave, onCancel }) => {
            const [companies, setCompanies] = useState([]);
            const [form, setForm] = useState({ email: '', name: '', role: defaultRole, company_id: '' });
            const [confirmOpen, setConfirmOpen] = useState(false);
            const [pendingPayload, setPendingPayload] = useState(null);
            const [submitting, setSubmitting] = useState(false);
            useEffect(() => { apiFetch('admin/companies').then(d => setCompanies(d.companies || [])).catch(() => {}); }, []);

            // Submit del form: NO envia al backend todavia. Solo prepara payload y abre confirm.
            const submit = (e) => {
                e.preventDefault();
                const body = { email: form.email.trim(), name: form.name.trim(), role: form.role };
                if (form.company_id) body.company_id = parseInt(form.company_id, 10);
                setPendingPayload(body);
                setConfirmOpen(true);
            };

            const confirmInvite = async () => {
                if (!pendingPayload) return;
                setSubmitting(true);
                try {
                    await onSave(pendingPayload);
                    setConfirmOpen(false);
                    setPendingPayload(null);
                } finally { setSubmitting(false); }
            };

            const selectedCompany = pendingPayload?.company_id
                ? companies.find(c => c.id === pendingPayload.company_id) || null
                : null;

            return (
                <>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label className="block">
                                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Correo</span>
                                <input type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })}
                                    className="w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-medium" />
                            </label>
                            <label className="block">
                                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Nombre</span>
                                <input required minLength="2" maxLength="120" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
                                    className="w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-medium" />
                            </label>
                            {isSuper && (
                                <label className="block">
                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Rol</span>
                                    <div className="mt-1">
                                        <Select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
                                            <option value="consultant">Consultor</option>
                                            <option value="admin">Administrador</option>
                                        </Select>
                                    </div>
                                </label>
                            )}
                            <label className="block">
                                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Empresa</span>
                                <div className="mt-1">
                                    <Select value={form.company_id} onChange={(e) => setForm({ ...form, company_id: e.target.value })}>
                                        <option value="">— Sin empresa —</option>
                                        {companies.map(c => <option key={c.id} value={c.id}>{c.name}{c.brand_name ? ` — ${c.brand_name}` : ''}</option>)}
                                    </Select>
                                </div>
                            </label>
                        </div>
                        <p className="text-[11px] text-slate-500">Se enviará un correo con una contraseña temporal. Revisarás el resumen antes de confirmar.</p>
                        <div className="flex gap-2">
                            <button type="button" onClick={onCancel} className="flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200">Cancelar</button>
                            <button type="submit" className="flex-1 py-3 rounded-xl bg-blue-600 text-white font-black hover:bg-blue-700 flex items-center justify-center gap-2">
                                Revisar invitación
                            </button>
                        </div>
                    </form>
                    <InviteConfirmModal
                        open={confirmOpen}
                        payload={pendingPayload}
                        company={selectedCompany}
                        submitting={submitting}
                        onConfirm={confirmInvite}
                        onCancel={() => { if (!submitting) { setConfirmOpen(false); setPendingPayload(null); } }}
                    />
                </>
            );
        };

        const ResendInviteModal = ({ open, target, busy, onConfirm, onCancel }) => (
            <Modal open={open} onClose={onCancel} title="Reenviar invitación" maxWidth="max-w-lg" showHeader>
                <div className="flex items-start gap-3 mb-4">
                    <div className="shrink-0 w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-purple-500 flex items-center justify-center text-white">
                        <Icon name="Mail" size={22} />
                    </div>
                    <div className="flex-1">
                        <p className="text-sm text-slate-600 dark:text-slate-300">
                            Se generará una nueva password temporal y se enviará un correo de bienvenida actualizado.
                        </p>
                    </div>
                </div>
                {target && (
                    <div className="bg-slate-50 dark:bg-slate-800/50 p-3 rounded-xl mb-4">
                        <p className="font-bold text-slate-800 dark:text-slate-100">{target.name}</p>
                        <p className="text-xs text-slate-500 truncate">{target.email}</p>
                        <p className="text-xs text-slate-500 mt-1">{target.company_name || 'Sin empresa'}</p>
                    </div>
                )}
                <div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-900/40 rounded-xl p-3 mb-4">
                    <p className="text-xs text-amber-800 dark:text-amber-200 leading-relaxed">
                        <strong>Importante:</strong> la password temporal anterior dejará de funcionar. El usuario deberá usar la nueva que llegue por correo.
                    </p>
                </div>
                <div className="flex gap-2 justify-end">
                    <button onClick={onCancel} disabled={busy} className="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold text-sm disabled:opacity-60">Cancelar</button>
                    <button onClick={onConfirm} disabled={busy} className="px-4 py-2 rounded-xl btn-melius font-bold text-sm disabled:opacity-60 inline-flex items-center gap-2">
                        {busy && <Icon name="Spinner" size={16} />}
                        {busy ? 'Enviando...' : 'Reenviar invitación'}
                    </button>
                </div>
            </Modal>
        );

        const AgentsTab = ({ isSuper }) => {
            const { push: toast } = useToast();
            const [q, setQ] = useState('');
            const [companyId, setCompanyId] = useState('');
            const [status, setStatus] = useState('');
            const [data, setData] = useState({ agents: [], total: 0 });
            const [companies, setCompanies] = useState([]);
            const [loading, setLoading] = useState(true);
            const [error, setError] = useState(null);
            const [inviting, setInviting] = useState(false);
            const [bulkOpen, setBulkOpen] = useState(false);
            const [resending, setResending] = useState(null);
            const [resendBusy, setResendBusy] = useState(false);
            const [unblocking, setUnblocking] = useState(null);
            const [acta, setActa] = useState(null);
            const [actaForm, setActaForm] = useState({ subject: '', message: '' });
            const [actaBusy, setActaBusy] = useState(false);
            const load = useCallback(async () => {
                setLoading(true); setError(null);
                try {
                    const qs = new URLSearchParams();
                    if (q) qs.set('q', q);
                    if (companyId) qs.set('company_id', companyId);
                    if (status) qs.set('status', status);
                    const d = await apiFetch(`admin/agents/search?${qs.toString()}`);
                    setData(d);
                    if (companies.length === 0) {
                        const cs = await apiFetch('admin/companies');
                        setCompanies(cs.companies || []);
                    }
                } catch (e) { setError(e.message); }
                finally { setLoading(false); }
            }, [q, companyId, status, companies.length]);
            useEffect(() => { const t = setTimeout(load, 250); return () => clearTimeout(t); }, [load]);

            const invite = async (body) => {
                try { await apiFetch('admin/users/invite', { method: 'POST', body }); toast('success', 'Invitación enviada.'); setInviting(false); load(); }
                catch (e) { toast('error', e.message); }
            };
            const toggleStatus = async (a) => {
                const next = a.status === 'active' ? 'disabled' : 'active';
                try {
                    await apiFetch(`admin/users/${a.id}`, { method: 'PUT', body: {
                        company_id: a.company_id, status: next,
                    }});
                    toast('success', 'Consultor actualizado.'); load();
                } catch (e) { toast('error', e.message); }
            };
            const openResend = (a) => { setResending(a); };
            const closeResend = () => { if (!resendBusy) { setResending(null); } };
            const doResend = async () => {
                if (!resending) return;
                setResendBusy(true);
                try {
                    await apiFetch(`admin/users/${resending.id}/resend-invite`, { method: 'POST' });
                    toast('success', 'Invitación reenviada con nueva password temporal.');
                    setResending(null);
                } catch (e) { toast('error', e.message); }
                finally { setResendBusy(false); }
            };

            const promoteToAdmin = async (a) => {
                if (!a.company_id) {
                    toast('error', 'El consultor debe tener empresa asignada antes de promoverlo a admin.');
                    return;
                }
                try {
                    await apiFetch(`admin/users/${a.id}`, { method: 'PUT', body: {
                        company_id: a.company_id, status: a.status, role: 'admin',
                    }});
                    toast('success', 'Promovido a administrador.'); load();
                } catch (e) { toast('error', e.message); }
            };

            const doUnblock = async (a) => {
                setUnblocking(a.id);
                try {
                    await apiFetch(`admin/users/${a.id}/unblock`, { method: 'POST', body: {} });
                    toast('success', `Cuenta de ${a.name} desbloqueada.`);
                    load();
                } catch (e) { toast('error', e.message); }
                finally { setUnblocking(null); }
            };

            const openActa = (a) => { setActa(a); setActaForm({ subject: '', message: '' }); };
            const closeActa = () => { if (!actaBusy) { setActa(null); } };
            const doSendActa = async () => {
                if (!acta) return;
                setActaBusy(true);
                try {
                    await apiFetch(`admin/users/${acta.id}/send-acta`, { method: 'POST', body: actaForm });
                    toast('success', 'Acta administrativa enviada.');
                    setActa(null);
                } catch (e) { toast('error', e.message); }
                finally { setActaBusy(false); }
            };

            return (
                <div className="space-y-4">
                    <div className="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-end gap-3 bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-100 dark:border-slate-800">
                        <input placeholder="Buscar por nombre o correo" value={q} onChange={(e) => setQ(e.target.value)}
                            className="w-full sm:flex-1 sm:min-w-[200px] px-4 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 font-medium" />
                        {isSuper && (
                            <Select value={companyId} onChange={(e) => setCompanyId(e.target.value)} size="sm" className="w-full sm:w-auto">
                                <option value="">Todas las empresas</option>
                                {companies.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                        )}
                        <Select value={status} onChange={(e) => setStatus(e.target.value)} size="sm" className="w-full sm:w-auto">
                            <option value="">Cualquier estado</option>
                            <option value="active">Activos</option>
                            <option value="disabled">Deshabilitados</option>
                            <option value="pending_confirmation">Pendientes</option>
                        </Select>
                        <div className="flex gap-2 w-full sm:w-auto sm:ml-auto">
                            <button onClick={() => setBulkOpen(true)} className="flex-1 sm:flex-none px-4 py-2 rounded-xl bg-cyan-50 dark:bg-cyan-900/30 text-melius-cyan border border-cyan-100 dark:border-cyan-900/40 font-bold text-sm hover:bg-cyan-100 dark:hover:bg-cyan-900/50">Carga CSV</button>
                            <button onClick={() => setInviting(true)} className="flex-1 sm:flex-none px-4 py-2 rounded-xl btn-melius font-bold text-sm">+ Invitar</button>
                        </div>
                    </div>
                    {loading ? <LoadingScreen /> : error ? <ErrorState message={error} onRetry={load} /> : (
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 divide-y dark:divide-slate-800">
                            {data.agents.map(a => (
                                <div key={a.id} className="p-4 flex flex-wrap items-center gap-2 sm:gap-3 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                    <div className="flex-1 min-w-0 basis-full sm:basis-auto sm:min-w-[180px]">
                                        <p className="font-bold text-slate-800 dark:text-slate-100 truncate">{a.name} <span className="text-[10px] font-black uppercase ml-2 px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-500">{a.role}</span></p>
                                        <p className="text-xs text-slate-500 truncate">{a.email}</p>
                                    </div>
                                    <span className="text-xs text-slate-500 truncate max-w-[120px]">{a.company_name || 'Sin empresa'}</span>
                                    {a.must_change_password ? (
                                        <span className="px-2 py-1 rounded-full text-[10px] font-black uppercase bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">pendiente</span>
                                    ) : (
                                        <span className={`px-2 py-1 rounded-full text-[10px] font-black uppercase ${a.status === 'active' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'}`}>{a.status}</span>
                                    )}
                                    <div className="flex items-center gap-2 ml-auto">
                                        {a.must_change_password && a.status === 'active' && (
                                            <button onClick={() => openResend(a)} className="px-3 py-1.5 rounded-lg text-xs font-bold bg-cyan-50 text-cyan-600 hover:bg-cyan-100 dark:bg-cyan-900/30 dark:text-cyan-300 inline-flex items-center gap-1.5">
                                                <Icon name="Mail" size={14} />
                                                Reenviar invitación
                                            </button>
                                        )}
                                        {isSuper && a.role === 'consultant' && a.company_id && a.status === 'active' && (
                                            <button onClick={() => promoteToAdmin(a)} className="px-3 py-1.5 rounded-lg text-xs font-bold bg-purple-50 text-purple-600 hover:bg-purple-100 dark:bg-purple-900/30 dark:text-purple-300 inline-flex items-center gap-1.5">
                                                <Icon name="ShieldCheck" size={14} />
                                                Promover a admin
                                            </button>
                                        )}
                                        <button onClick={() => toggleStatus(a)} className="text-xs font-bold text-blue-600 hover:underline">{a.status === 'active' ? 'Desactivar' : 'Activar'}</button>
                                        {(a.failed_attempts > 0 || a.locked_until) && (
                                            <button onClick={() => doUnblock(a)} disabled={unblocking === a.id}
                                                className="px-3 py-1.5 rounded-lg text-xs font-bold bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-900/30 dark:text-amber-300 inline-flex items-center gap-1.5 disabled:opacity-50">
                                                <Icon name="Unlock" size={13} />
                                                {unblocking === a.id ? '...' : 'Desbloquear'}
                                            </button>
                                        )}
                                        <button onClick={() => openActa(a)}
                                            className="px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 inline-flex items-center gap-1.5">
                                            <Icon name="FileText" size={13} />
                                            Acta
                                        </button>
                                    </div>
                                </div>
                            ))}
                            {data.agents.length === 0 && <EmptyState message="Sin resultados" />}
                        </div>
                    )}
                    <Modal open={inviting} onClose={() => setInviting(false)} title="Invitar consultor" maxWidth="max-w-2xl" showHeader>
                        <InviteForm defaultRole="consultant" isSuper={isSuper} onSave={invite} onCancel={() => setInviting(false)} />
                    </Modal>
                    <ResendInviteModal open={!!resending} target={resending} busy={resendBusy} onConfirm={doResend} onCancel={closeResend} />
                    {/* Modal acta administrativa */}
                    <Modal open={!!acta} onClose={closeActa} title={`Acta administrativa — ${acta?.name || ''}`} maxWidth="max-w-xl" showHeader>
                        <div className="p-5 flex flex-col gap-4">
                            <p className="text-xs text-slate-500 dark:text-slate-400">El correo llegará a <strong>{acta?.email}</strong> con firma de administrador y fecha. Guarda una copia en tus registros.</p>
                            <div>
                                <label className="text-[10px] font-black uppercase tracking-widest text-slate-400 block mb-1">Asunto del acta</label>
                                <input value={actaForm.subject} onChange={e => setActaForm(f => ({ ...f, subject: e.target.value }))}
                                    placeholder="Ej. Incumplimiento de horario — 01/06/2026"
                                    className="w-full px-4 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-medium" />
                            </div>
                            <div>
                                <label className="text-[10px] font-black uppercase tracking-widest text-slate-400 block mb-1">Contenido del acta</label>
                                <textarea value={actaForm.message} onChange={e => setActaForm(f => ({ ...f, message: e.target.value }))}
                                    rows={6} placeholder="Redacta el acta. Puedes describir el incidente, las medidas tomadas y las consecuencias en caso de reincidencia..."
                                    className="w-full px-4 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-medium resize-none" />
                            </div>
                            <div className="flex gap-3 justify-end pt-1">
                                <button onClick={closeActa} disabled={actaBusy} className="px-4 py-2 rounded-xl text-sm font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">Cancelar</button>
                                <button onClick={doSendActa} disabled={actaBusy || !actaForm.subject.trim() || !actaForm.message.trim()}
                                    className="px-5 py-2 rounded-xl btn-melius font-bold text-sm disabled:opacity-50 flex items-center gap-2">
                                    <Icon name="Send" size={14} />
                                    {actaBusy ? 'Enviando...' : 'Enviar acta'}
                                </button>
                            </div>
                        </div>
                    </Modal>
                    <Modal open={bulkOpen} onClose={() => setBulkOpen(false)} title="Carga masiva CSV" maxWidth="max-w-3xl" showHeader>
                        <BulkInviteForm
                            isSuper={isSuper}
                            companies={companies}
                            onClose={() => setBulkOpen(false)}
                            onDone={() => { setBulkOpen(false); load(); }}
                        />
                    </Modal>
                </div>
            );
        };

        // Parser CSV minimo y tolerante. Solo soporta delimitadores , y ;
        // y comillas dobles para escapar. Devuelve array de objetos por header.
        const parseCsvLight = (raw) => {
            const text = String(raw || '').replace(/\r\n?/g, '\n').trim();
            if (!text) return { headers: [], rows: [] };
            const lines = text.split('\n').filter(l => l.trim() !== '');
            const delim = (lines[0].includes(';') && !lines[0].includes(',')) ? ';' : ',';
            const splitLine = (line) => {
                const out = []; let cur = ''; let inQ = false;
                for (let i = 0; i < line.length; i++) {
                    const ch = line[i];
                    if (ch === '"') { inQ = !inQ; continue; }
                    if (ch === delim && !inQ) { out.push(cur); cur = ''; continue; }
                    cur += ch;
                }
                out.push(cur);
                return out.map(s => s.trim());
            };
            const headers = splitLine(lines[0]).map(h => h.toLowerCase());
            const rows = lines.slice(1).map((line, idx) => {
                const cells = splitLine(line);
                const obj = { _row: idx + 2 };
                headers.forEach((h, i) => { obj[h] = cells[i] !== undefined ? cells[i] : ''; });
                return obj;
            });
            return { headers, rows };
        };

        // Modal de confirmacion para carga masiva. Muestra resumen, avisos y un
        // mini-preview por cada marca detectada (con tabs si hay mas de una).
        const BulkConfirmModal = ({ open, parsed, companies, defaultCompanyId, isSuper, onConfirm, onCancel, submitting }) => {
            const [activeTab, setActiveTab] = useState(0);
            useEffect(() => { setActiveTab(0); }, [open]);
            if (!open || !parsed) return null;

            const totalRows = parsed.rows.length;
            const defaultCompany = defaultCompanyId
                ? companies.find(c => c.id === parseInt(defaultCompanyId, 10))
                : null;

            // Resolver empresa por fila: prioridad company (col) > default. Empresa no
            // resuelta = aviso. Email/name vacios = aviso. Email mal = aviso.
            const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const seen = new Set();
            const enriched = parsed.rows.map(r => {
                const issues = [];
                const email = (r.email || '').trim().toLowerCase();
                const name = (r.name || '').trim();
                if (!email) issues.push('email vacío');
                else if (!emailRe.test(email)) issues.push('email inválido');
                else if (seen.has(email)) issues.push('email duplicado en CSV');
                seen.add(email);
                if (!name || name.length < 2) issues.push('nombre vacío o muy corto');

                let company = null;
                const companyName = (r.company || '').trim().toLowerCase();
                if (companyName) {
                    company = companies.find(c => c.name.toLowerCase() === companyName) || null;
                    if (!company) issues.push(`empresa "${r.company}" no existe`);
                } else if (defaultCompany) {
                    company = defaultCompany;
                } else if (isSuper) {
                    issues.push('sin empresa y sin default');
                }
                return { ...r, _email: email, _name: name, _company: company, _issues: issues };
            });

            // Agrupar por marca (empresa.brand_id). Filas sin company resuelta caen en bucket "_unassigned".
            const groups = new Map();
            enriched.forEach(r => {
                if (!r._company) return;
                const key = r._company.brand_id ? `brand:${r._company.brand_id}` : `nobrand:${r._company.id}`;
                if (!groups.has(key)) {
                    groups.set(key, {
                        company: r._company,
                        brand_name: r._company.brand_name || '(sin marca)',
                        brand_primary: r._company.brand_primary,
                        brand_secondary: r._company.brand_secondary,
                        brand_logo_url: r._company.brand_logo_url,
                        brand_welcome: r._company.brand_welcome,
                        rows: [],
                    });
                }
                groups.get(key).rows.push(r);
            });
            const groupList = Array.from(groups.values());
            const issueRows = enriched.filter(r => r._issues.length > 0);
            const okCount = enriched.length - issueRows.length;

            const activeGroup = groupList[activeTab] || null;
            const primary = activeGroup?.brand_primary || '#07d6da';
            const secondary = activeGroup?.brand_secondary || '#9909fe';
            const gradient = `linear-gradient(135deg, ${primary} 0%, ${secondary} 100%)`;
            const brandName = activeGroup?.brand_name || 'Melius';
            const sampleRow = activeGroup?.rows[0];
            const intro = (activeGroup?.brand_welcome && activeGroup.brand_welcome.trim())
                ? activeGroup.brand_welcome.trim()
                : `Tu equipo en ${activeGroup?.company.name || 'tu empresa'} está usando ${brandName} Clockin para marcar jornada de forma sencilla.`;

            return (
                <Modal open={open} onClose={onCancel} title="Revisar carga masiva" maxWidth="max-w-4xl" showHeader>
                    <div className="space-y-4">
                        {/* Resumen */}
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
                            <div className="rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-900/40 p-3 text-center">
                                <div className="text-2xl font-black text-emerald-700 dark:text-emerald-300">{okCount}</div>
                                <div className="text-[10px] font-bold uppercase tracking-widest text-emerald-700 dark:text-emerald-300">Listos</div>
                            </div>
                            <div className="rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-900/40 p-3 text-center">
                                <div className="text-2xl font-black text-amber-700 dark:text-amber-300">{issueRows.length}</div>
                                <div className="text-[10px] font-bold uppercase tracking-widest text-amber-700 dark:text-amber-300">Con avisos</div>
                            </div>
                            <div className="rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 p-3 text-center">
                                <div className="text-2xl font-black text-blue-700 dark:text-blue-300">{groupList.length}</div>
                                <div className="text-[10px] font-bold uppercase tracking-widest text-blue-700 dark:text-blue-300">Marcas</div>
                            </div>
                            <div className="rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 p-3 text-center">
                                <div className="text-2xl font-black text-slate-700 dark:text-slate-200">{totalRows}</div>
                                <div className="text-[10px] font-bold uppercase tracking-widest text-slate-500">Filas totales</div>
                            </div>
                        </div>

                        {/* Avisos */}
                        {issueRows.length > 0 && (
                            <div className="rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50/60 dark:bg-amber-900/10 p-3 max-h-40 overflow-auto">
                                <p className="text-[11px] font-black uppercase tracking-widest text-amber-700 dark:text-amber-300 mb-2">Avisos por fila</p>
                                <div className="text-xs space-y-1 font-mono">
                                    {issueRows.slice(0, 20).map((r, i) => (
                                        <div key={i} className="text-amber-800 dark:text-amber-200">
                                            Fila {r._row}: {r._email || '(sin email)'} — {r._issues.join(', ')}
                                        </div>
                                    ))}
                                    {issueRows.length > 20 && <div className="text-amber-700 dark:text-amber-400 italic">... y {issueRows.length - 20} más. Estas filas se enviarán de todas formas; el reporte final mostrará el detalle.</div>}
                                </div>
                            </div>
                        )}

                        {/* Tabs por marca + preview */}
                        {groupList.length > 0 && (
                            <div>
                                <div className="flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-700">
                                    {groupList.map((g, i) => (
                                        <button key={i} type="button" onClick={() => setActiveTab(i)}
                                            className={`px-3 py-2 text-xs font-bold border-b-2 transition-all ${activeTab === i ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'}`}>
                                            {g.brand_name} <span className="opacity-60">({g.rows.length})</span>
                                        </button>
                                    ))}
                                </div>
                                {activeGroup && sampleRow && (
                                    <div className="mt-3 rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-900 p-3">
                                        <div className="rounded-xl overflow-hidden bg-white border border-slate-200" style={{ fontFamily: 'Segoe UI, Arial, sans-serif' }}>
                                            <div style={{ background: gradient, color: '#fff', padding: '18px 16px', textAlign: 'center' }}>
                                                {activeGroup.brand_logo_url && (
                                                    <div style={{ marginBottom: 8 }}>
                                                        <img src={activeGroup.brand_logo_url} alt={brandName} style={{ width: 44, height: 44, borderRadius: 10, background: '#fff', padding: 4, objectFit: 'contain', display: 'inline-block' }} />
                                                    </div>
                                                )}
                                                <div style={{ fontSize: 9, letterSpacing: '0.3em', textTransform: 'uppercase', opacity: 0.92, fontWeight: 700 }}>{brandName.toUpperCase()} CLOCKIN</div>
                                                <div style={{ fontSize: 16, fontWeight: 800, marginTop: 6 }}>Bienvenido a bordo, {sampleRow._name.split(' ')[0] || 'Nombre'}</div>
                                            </div>
                                            <div style={{ padding: '12px 16px', fontSize: 11, color: '#1f2937', lineHeight: 1.5 }}>
                                                <p style={{ margin: '0 0 4px 0' }}>Hola <strong>{sampleRow._name || '[Nombre]'}</strong>,</p>
                                                <p style={{ margin: '0 0 6px 0', whiteSpace: 'pre-line' }}>{intro}</p>
                                            </div>
                                            <div style={{ padding: '2px 16px 12px 16px', textAlign: 'center' }}>
                                                <span style={{ display: 'inline-block', padding: '7px 16px', background: primary, color: '#fff', borderRadius: 8, fontSize: 11, fontWeight: 800 }}>
                                                    Entrar a {brandName} Clockin
                                                </span>
                                            </div>
                                        </div>
                                        <p className="text-[10px] text-slate-400 mt-2">Preview con datos de la primera fila de esta marca. Cada consultor recibirá su propio correo personalizado.</p>
                                    </div>
                                )}
                            </div>
                        )}

                        <p className="text-[11px] text-slate-500">
                            Al confirmar se crearán {okCount} cuentas con contraseña temporal. Las {issueRows.length > 0 ? `${issueRows.length} filas con avisos se enviarán también al servidor y` : 'filas'} aparecerán en el reporte final si fallan.
                        </p>

                        <div className="flex gap-2 pt-2">
                            <button type="button" onClick={onCancel} disabled={submitting}
                                className="flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200 disabled:opacity-60">
                                Volver
                            </button>
                            <button type="button" onClick={onConfirm} disabled={submitting || totalRows === 0}
                                className="flex-1 py-3 rounded-xl btn-melius font-black disabled:opacity-60 flex items-center justify-center gap-2">
                                {submitting && <Icon name="Spinner" size={18} />}
                                Confirmar y procesar
                            </button>
                        </div>
                    </div>
                </Modal>
            );
        };

        const BulkInviteForm = ({ isSuper, companies, onClose, onDone }) => {
            const { push: toast } = useToast();
            const [csv, setCsv] = useState('');
            const [defaultCompanyId, setDefaultCompanyId] = useState('');
            const [submitting, setSubmitting] = useState(false);
            const [report, setReport] = useState(null);
            const [dragOver, setDragOver] = useState(false);
            const [confirmOpen, setConfirmOpen] = useState(false);
            const [parsed, setParsed] = useState(null);
            const fileInputRef = useRef(null);

            const downloadTemplate = async () => {
                try {
                    const res = await fetch(`${API_BASE}/admin/users/template.csv`, { credentials: 'same-origin' });
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    const blob = await res.blob();
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'plantilla_consultores.csv';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    URL.revokeObjectURL(url);
                } catch (e) { toast('error', 'No se pudo descargar la plantilla.'); }
            };

            const readFile = (file) => {
                if (!file) return;
                if (file.size > 2 * 1024 * 1024) { toast('error', 'CSV supera 2 MB.'); return; }
                const reader = new FileReader();
                reader.onload = () => setCsv(String(reader.result || ''));
                reader.readAsText(file, 'utf-8');
            };

            const onDrop = (e) => {
                e.preventDefault(); setDragOver(false);
                const f = e.dataTransfer?.files?.[0];
                if (f) readFile(f);
            };

            // Paso 1: abrir confirmacion con resumen y mini-preview por marca.
            const openConfirm = () => {
                if (!csv.trim()) { toast('error', 'Pega o carga un CSV primero.'); return; }
                const p = parseCsvLight(csv);
                if (p.rows.length === 0) { toast('error', 'El CSV no contiene filas válidas.'); return; }
                setParsed(p);
                setConfirmOpen(true);
            };

            // Paso 2: confirmacion -> POST real al backend.
            const submit = async () => {
                setSubmitting(true);
                setReport(null);
                try {
                    const body = { csv };
                    if (defaultCompanyId) body.default_company_id = parseInt(defaultCompanyId, 10);
                    const r = await apiFetch('admin/users/bulk-invite', { method: 'POST', body });
                    setReport(r);
                    setConfirmOpen(false);
                    if ((r.summary?.created || 0) > 0) {
                        toast('success', `${r.summary.created} consultores creados.`);
                    } else {
                        toast('error', 'Ningún consultor fue creado. Revisa el reporte.');
                    }
                } catch (e) { toast('error', e.message || 'Error en carga masiva.'); }
                finally { setSubmitting(false); }
            };

            return (
                <div className="space-y-4">
                    <div className="rounded-2xl bg-cyan-50 dark:bg-cyan-900/20 border border-cyan-100 dark:border-cyan-900/40 p-4 text-sm text-slate-700 dark:text-slate-200 space-y-3">
                        <div>
                            <p className="font-black text-melius-cyan mb-1">Como funciona en 3 pasos</p>
                            <ol className="list-decimal list-inside space-y-1 text-xs leading-relaxed">
                                <li><strong>Descarga la plantilla.</strong> Trae las columnas correctas y dos filas de ejemplo (puedes borrarlas).</li>
                                <li><strong>Llena una fila por consultor</strong> en Excel o Google Sheets. Guarda como CSV.</li>
                                <li><strong>Sube o pega el archivo</strong> aquí y dale Procesar. Cada consultor recibirá un correo con su contraseña temporal.</li>
                            </ol>
                        </div>
                        <button onClick={downloadTemplate}
                            className="inline-flex items-center gap-2 px-4 py-2 rounded-xl btn-melius font-bold text-sm">
                            Descargar plantilla
                        </button>
                    </div>

                    <div className="rounded-xl border border-slate-100 dark:border-slate-700 p-4 text-xs text-slate-600 dark:text-slate-300 space-y-1.5 bg-white dark:bg-slate-900">
                        <p className="font-black text-slate-700 dark:text-slate-200 mb-1">Columnas del CSV</p>
                        <p><code className="font-mono text-melius-cyan font-bold">email</code> — correo del consultor.</p>
                        <p><code className="font-mono text-melius-cyan font-bold">name</code> — nombre completo (mínimo 2 caracteres).</p>
                        <p><code className="font-mono text-melius-cyan font-bold">role</code> — escribe <code className="font-mono">consultant</code>{isSuper ? <span> (o <code className="font-mono">admin</code> si quieres dar de alta administradores).</span> : <span>.</span>}</p>
                        {isSuper && (
                            <p><code className="font-mono text-melius-cyan font-bold">company</code> — nombre de la empresa exactamente como aparece en el sistema (ej. <code className="font-mono">Coppel</code>, <code className="font-mono">Hyatt</code>). Puede ir vacío si seleccionas una empresa default abajo.</p>
                        )}
                        {!isSuper && (
                            <p className="text-slate-500">Todos los consultores quedarán asignados a tu empresa automáticamente — no necesitas la columna <code className="font-mono">company</code>.</p>
                        )}
                        <p className="text-slate-500 pt-1">Máximo 500 filas por carga. El archivo se valida fila por fila; si alguna falla, las demás se procesan igual.</p>
                    </div>

                    {isSuper && (
                        <label className="block">
                            <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Empresa default (se aplica a filas con la columna company vacía)</span>
                            <div className="mt-1">
                                <Select value={defaultCompanyId} onChange={(e) => setDefaultCompanyId(e.target.value)}>
                                    <option value="">— Sin default (cada fila debe traer company) —</option>
                                    {companies.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </Select>
                            </div>
                        </label>
                    )}

                    <div
                        onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                        onDragLeave={() => setDragOver(false)}
                        onDrop={onDrop}
                        className={`rounded-2xl border-2 border-dashed p-6 text-center transition-all ${dragOver ? 'border-melius-cyan bg-cyan-50 dark:bg-cyan-900/20' : 'border-slate-200 dark:border-slate-700'}`}>
                        <p className="text-sm font-bold text-slate-600 dark:text-slate-300">Arrastra tu CSV aquí o</p>
                        <button onClick={() => fileInputRef.current?.click()} className="mt-2 px-4 py-2 rounded-xl btn-melius font-bold text-sm">Selecciona archivo</button>
                        <input ref={fileInputRef} type="file" accept=".csv,text/csv" className="hidden"
                            onChange={(e) => readFile(e.target.files?.[0])} />
                    </div>

                    <label className="block">
                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">O pega el CSV directo</span>
                        <textarea value={csv} onChange={(e) => setCsv(e.target.value)} rows="6" spellCheck="false"
                            placeholder={isSuper
                                ? "email,name,role,company\nana@empresa.com,Ana Gomez,consultant,Coppel"
                                : "email,name,role\nana@empresa.com,Ana Gomez,consultant"}
                            className="w-full mt-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-xs" />
                    </label>

                    {report && (
                        <div className="space-y-2 rounded-xl border border-slate-100 dark:border-slate-700 p-4 bg-white dark:bg-slate-900">
                            <div className="flex gap-3 text-xs font-bold flex-wrap">
                                <span className="px-3 py-1 rounded-full bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">Creados: {report.summary.created}</span>
                                <span className="px-3 py-1 rounded-full bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300">Errores: {report.summary.failed}</span>
                                <span className="px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">Omitidos: {report.summary.skipped}</span>
                            </div>
                            {(report.failed?.length > 0 || report.skipped?.length > 0) && (
                                <div className="max-h-40 overflow-auto text-xs space-y-1 font-mono">
                                    {report.failed?.map((f, i) => (
                                        <div key={`f${i}`} className="text-red-600 dark:text-red-300">Fila {f.row}: {f.email || '(sin email)'} — {f.reason}</div>
                                    ))}
                                    {report.skipped?.map((s, i) => (
                                        <div key={`s${i}`} className="text-amber-600 dark:text-amber-300">Fila {s.row}: {s.email} — {s.reason}</div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    <div className="flex gap-2 pt-2">
                        <button onClick={onClose} className="flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200">Cerrar</button>
                        {report?.summary?.created > 0 && (
                            <button onClick={onDone} className="flex-1 py-3 rounded-xl bg-emerald-600 text-white font-black hover:bg-emerald-700">Listo, recargar lista</button>
                        )}
                        <button onClick={openConfirm} disabled={submitting || !csv.trim()} className="flex-1 py-3 rounded-xl btn-melius font-black disabled:opacity-60 flex items-center justify-center gap-2">
                            {submitting && <Icon name="Spinner" size={18} />}
                            Revisar y procesar
                        </button>
                    </div>
                    <BulkConfirmModal
                        open={confirmOpen}
                        parsed={parsed}
                        companies={companies}
                        defaultCompanyId={defaultCompanyId}
                        isSuper={isSuper}
                        submitting={submitting}
                        onConfirm={submit}
                        onCancel={() => { if (!submitting) { setConfirmOpen(false); } }}
                    />
                </div>
            );
        };

        const AdminsTab = ({ currentUser, isSuper }) => {
            const { push: toast } = useToast();
            const [data, setData] = useState({ agents: [] });
            const [loading, setLoading] = useState(true);
            const [error, setError] = useState(null);
            const [inviting, setInviting] = useState(false);
            const [deleting, setDeleting] = useState(null);
            const [confirmEmail, setConfirmEmail] = useState('');
            const [busy, setBusy] = useState(false);
            const [resending, setResending] = useState(null);
            const [resendBusy, setResendBusy] = useState(false);
            const load = useCallback(async () => {
                setLoading(true); setError(null);
                try {
                    const d = await apiFetch('admin/users');
                    setData({ agents: (d.users || []).filter(u => u.role === 'admin' || u.role === 'super_admin') });
                } catch (e) { setError(e.message); }
                finally { setLoading(false); }
            }, []);
            useEffect(() => { load(); }, [load]);

            const invite = async (body) => {
                try { await apiFetch('admin/users/invite', { method: 'POST', body: { ...body, role: 'admin' } }); toast('success', 'Admin invitado.'); setInviting(false); load(); }
                catch (e) { toast('error', e.message); }
            };

            const openResend = (a) => { setResending(a); };
            const closeResend = () => { if (!resendBusy) { setResending(null); } };
            const doResend = async () => {
                if (!resending) return;
                setResendBusy(true);
                try {
                    await apiFetch(`admin/users/${resending.id}/resend-invite`, { method: 'POST' });
                    toast('success', 'Invitación reenviada con nueva password temporal.');
                    setResending(null);
                } catch (e) { toast('error', e.message); }
                finally { setResendBusy(false); }
            };

            const openDelete = (admin) => { setDeleting(admin); setConfirmEmail(''); };
            const closeDelete = () => { setDeleting(null); setConfirmEmail(''); setBusy(false); };
            const canConfirmDelete = deleting && confirmEmail.trim().toLowerCase() === deleting.email.toLowerCase();

            const doDelete = async () => {
                if (!deleting || !canConfirmDelete) return;
                setBusy(true);
                try {
                    const res = await apiFetch(`admin/users/${deleting.id}`, {
                        method: 'DELETE',
                        body: { email_confirmation: confirmEmail.trim() }
                    });
                    toast('success', res?.message || 'Administrador procesado.');
                    closeDelete();
                    load();
                } catch (e) {
                    toast('error', e.message);
                    setBusy(false);
                }
            };

            const demoteToConsultant = async (a) => {
                try {
                    await apiFetch(`admin/users/${a.id}`, { method: 'PUT', body: {
                        company_id: a.company_id, status: a.status, role: 'consultant',
                    }});
                    toast('success', 'Bajado a consultor.'); load();
                } catch (e) { toast('error', e.message); }
            };

            if (loading) return <LoadingScreen />;
            if (error) return <ErrorState message={error} onRetry={load} />;
            return (
                <div className="space-y-4">
                    <div className="flex justify-end">
                        <button onClick={() => setInviting(true)} className="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold text-sm hover:bg-blue-700">+ Invitar admin</button>
                    </div>
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 divide-y dark:divide-slate-800">
                        {data.agents.map(a => {
                            const isSelf = a.id === currentUser.id;
                            const isTargetSuper = a.role === 'super_admin';
                            const canDelete = !isSelf && !isTargetSuper && a.status === 'active';
                            return (
                                <div key={a.id} className="p-4 flex flex-wrap items-center gap-2 sm:gap-3">
                                    <div className="flex-1 min-w-0 basis-full sm:basis-auto sm:min-w-[180px]">
                                        <p className="font-bold text-slate-800 dark:text-slate-100 truncate">{a.name} <span className="text-[10px] font-black uppercase ml-2 px-2 py-0.5 rounded-full bg-purple-50 text-purple-600 dark:bg-purple-900/30 dark:text-purple-300">{a.role}</span>{isSelf && <span className="text-[10px] font-black uppercase ml-2 px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300">tu</span>}</p>
                                        <p className="text-xs text-slate-500 truncate">{a.email}</p>
                                    </div>
                                    <span className="text-xs text-slate-500 truncate max-w-[120px]">{a.company_name || 'Sin empresa'}</span>
                                    {a.must_change_password ? (
                                        <span className="px-2 py-1 rounded-full text-[10px] font-black uppercase bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">pendiente</span>
                                    ) : (
                                        <span className={`px-2 py-1 rounded-full text-[10px] font-black uppercase ${a.status === 'active' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'}`}>{a.status}</span>
                                    )}
                                    {a.must_change_password && a.status === 'active' && !isTargetSuper && (
                                        <button onClick={() => openResend(a)} className="px-3 py-1.5 rounded-lg text-xs font-bold bg-cyan-50 text-cyan-600 hover:bg-cyan-100 dark:bg-cyan-900/30 dark:text-cyan-300 inline-flex items-center gap-1.5">
                                            <Icon name="Mail" size={14} />
                                            Reenviar invitación
                                        </button>
                                    )}
                                    {isSuper && !isSelf && !isTargetSuper && a.status === 'active' && a.company_id && (
                                        <button onClick={() => demoteToConsultant(a)} className="px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 inline-flex items-center gap-1.5">
                                            <Icon name="ArrowDown" size={14} />
                                            Bajar a consultor
                                        </button>
                                    )}
                                    {canDelete && (
                                        <button onClick={() => openDelete(a)} className="px-3 py-1.5 rounded-lg text-xs font-bold bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-900/30 dark:text-red-300">
                                            {a.company_id ? 'Eliminar' : 'Desactivar'}
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                        {data.agents.length === 0 && <EmptyState message="Sin administradores aún" />}
                    </div>
                    <Modal open={inviting} onClose={() => setInviting(false)} title="Invitar administrador" maxWidth="max-w-2xl" showHeader>
                        <InviteForm defaultRole="admin" isSuper={isSuper} onSave={invite} onCancel={() => setInviting(false)} />
                    </Modal>
                    <Modal open={!!deleting} onClose={closeDelete} title={deleting?.company_id ? 'Eliminar administrador' : 'Desactivar administrador'} maxWidth="max-w-lg" showHeader>
                        {deleting?.company_id ? (
                            <p className="text-sm text-slate-600 dark:text-slate-300 mb-4">
                                Como el administrador tiene <strong>{deleting.company_name}</strong> asignada, lo convertimos en <strong>consultor</strong> de esa empresa. Conserva acceso para marcar jornada pero pierde sus permisos administrativos. Su histórico queda intacto.
                            </p>
                        ) : (
                            <p className="text-sm text-slate-600 dark:text-slate-300 mb-4">
                                Esta acción desactiva la cuenta del administrador (no tiene empresa asignada). Se envía un aviso al afectado y un recibo a tu correo. La cuenta se puede reactivar después.
                            </p>
                        )}
                        {deleting && (
                            <div className="bg-slate-50 dark:bg-slate-800/50 p-3 rounded-xl mb-4 text-sm">
                                <p className="font-bold text-slate-800 dark:text-slate-100">{deleting.name}</p>
                                <p className="text-xs text-slate-500">{deleting.email}</p>
                                <p className="text-xs text-slate-500 mt-1">{deleting.company_name || 'Sin empresa'}</p>
                            </div>
                        )}
                        <label className="block text-xs font-black uppercase tracking-widest text-slate-500 mb-2">Para confirmar, escribe el email del administrador</label>
                        <input
                            type="email"
                            value={confirmEmail}
                            onChange={e => setConfirmEmail(e.target.value)}
                            placeholder={deleting?.email || ''}
                            autoComplete="off"
                            className="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm font-mono"
                        />
                        <div className="flex gap-2 mt-4 justify-end">
                            <button onClick={closeDelete} disabled={busy} className="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold text-sm">Cancelar</button>
                            <button onClick={doDelete} disabled={!canConfirmDelete || busy} className={`px-4 py-2 rounded-xl font-bold text-sm text-white ${canConfirmDelete && !busy ? (deleting?.company_id ? 'bg-amber-600 hover:bg-amber-700' : 'bg-red-600 hover:bg-red-700') : 'bg-slate-300 dark:bg-slate-700 cursor-not-allowed'}`}>{busy ? 'Procesando...' : (deleting?.company_id ? 'Convertir en consultor' : 'Desactivar')}</button>
                        </div>
                    </Modal>
                    <ResendInviteModal open={!!resending} target={resending} busy={resendBusy} onConfirm={doResend} onCancel={closeResend} />
                </div>
            );
        };

        const ChangesTab = ({ onChange }) => {
            const { push: toast } = useToast();
            const [items, setItems] = useState([]);
            const [loading, setLoading] = useState(true);
            const [error, setError] = useState(null);
            const load = useCallback(async () => {
                setLoading(true); setError(null);
                try { const d = await apiFetch('admin/change-requests'); setItems(d.requests || []); }
                catch (e) { setError(e.message); }
                finally { setLoading(false); }
            }, []);
            useEffect(() => { load(); }, [load]);
            const decide = async (id, decision) => {
                try { await apiFetch('admin/decide', { method: 'POST', body: { type: 'change', id, decision } }); toast('success', `Solicitud ${decision === 'approve' ? 'aprobada' : 'rechazada'}.`); load(); onChange?.(); }
                catch (e) { toast('error', e.message); }
            };
            if (loading) return <LoadingScreen />;
            if (error) return <ErrorState message={error} onRetry={load} />;
            return (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
                    {items.map(req => (
                        <div key={req.id} className="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm flex flex-col gap-4">
                            <div>
                                <h4 className="font-bold text-base text-slate-800 dark:text-slate-100">{req.user_name}</h4>
                                <p className="text-[10px] text-slate-400 font-black uppercase tracking-widest">Cambio de empresa</p>
                            </div>
                            <div className="flex items-center gap-3 bg-slate-50 dark:bg-slate-800/60 p-3 rounded-2xl">
                                <div className="text-center flex-1">
                                    <p className="text-[9px] text-slate-400 font-black uppercase">Actual</p>
                                    <p className="font-bold text-red-400 text-xs">{req.old_company_name || '—'}</p>
                                </div>
                                <Icon name="ArrowLeftRight" className="text-slate-300" size={16} />
                                <div className="text-center flex-1">
                                    <p className="text-[9px] text-slate-400 font-black uppercase">Nueva</p>
                                    <p className="font-bold text-emerald-500 text-xs">{req.new_company_name}</p>
                                </div>
                            </div>
                            <div className="flex gap-2">
                                <button onClick={() => decide(req.id, 'approve')} className="flex-1 bg-emerald-500 text-white py-2.5 rounded-xl font-bold text-sm hover:bg-emerald-600 flex items-center justify-center gap-2"><Icon name="Check" size={16} /> Aprobar</button>
                                <button onClick={() => decide(req.id, 'reject')} className="flex-1 bg-red-50 dark:bg-red-900/30 text-red-500 dark:text-red-300 py-2.5 rounded-xl font-bold text-sm hover:bg-red-100 flex items-center justify-center gap-2"><Icon name="X" size={16} /> Rechazar</button>
                            </div>
                        </div>
                    ))}
                    {items.length === 0 && <EmptyState message="No hay solicitudes de cambio pendientes" />}
                </div>
            );
        };

        const VacationsTab = ({ onChange }) => {
            const { push: toast } = useToast();
            const [items, setItems] = useState([]);
            const [status, setStatus] = useState('pending');
            const [loading, setLoading] = useState(true);
            const [error, setError] = useState(null);
            const [busyId, setBusyId] = useState(null);
            const [notes, setNotes] = useState({});

            const load = useCallback(async () => {
                setLoading(true); setError(null);
                try {
                    const d = await apiFetch(`admin/vacations?status=${status}`);
                    setItems(d.requests || []);
                } catch (e) { setError(e.message); }
                finally { setLoading(false); }
            }, [status]);
            useEffect(() => { load(); }, [load]);

            const decide = async (id, decision) => {
                if (busyId) return;
                setBusyId(id);
                try {
                    await apiFetch(`admin/vacations/${id}/decide`, {
                        method: 'POST',
                        body: { decision, note: notes[id] || '' }
                    });
                    toast('success', decision === 'approved' ? 'Solicitud aprobada.' : 'Solicitud rechazada.');
                    load(); onChange?.();
                } catch (e) { toast('error', e.message); }
                finally { setBusyId(null); }
            };

            return (
                <div className="space-y-4">
                    <div className="flex flex-wrap gap-2 items-center">
                        {['pending', 'approved', 'rejected', 'cancelled'].map(s => (
                            <button key={s} onClick={() => setStatus(s)}
                                className={`px-3 py-2 rounded-xl text-xs font-black uppercase tracking-widest transition-colors min-h-[40px] ${
                                    status === s
                                        ? 'bg-emerald-500 text-white'
                                        : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'
                                }`}>
                                {s === 'pending' ? 'Pendientes' : s === 'approved' ? 'Aprobadas' : s === 'rejected' ? 'Rechazadas' : 'Canceladas'}
                            </button>
                        ))}
                    </div>

                    {loading && <LoadingScreen />}
                    {error && <ErrorState message={error} onRetry={load} />}
                    {!loading && !error && items.length === 0 && <EmptyState message={`Sin solicitudes ${status === 'pending' ? 'pendientes' : status}.`} />}

                    {!loading && !error && items.length > 0 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                            {items.map(req => (
                                <div key={req.id} className="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm flex flex-col gap-3">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h4 className="font-bold text-base text-slate-800 dark:text-slate-100 truncate">{req.user_name}</h4>
                                            <p className="text-xs text-slate-400 truncate">{req.user_email}</p>
                                            {req.company_name && <p className="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-1">{req.company_name}</p>}
                                        </div>
                                        <div className="bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 px-2 py-1 rounded-lg text-xs font-black whitespace-nowrap">{req.days} día{req.days === 1 ? '' : 's'}</div>
                                    </div>
                                    <div className="bg-slate-50 dark:bg-slate-800/60 p-3 rounded-xl text-sm">
                                        <p className="font-bold text-slate-700 dark:text-slate-200">Del <span className="font-mono">{req.start_date}</span></p>
                                        <p className="font-bold text-slate-700 dark:text-slate-200">Al <span className="font-mono">{req.end_date}</span></p>
                                        {req.reason && <p className="text-xs text-slate-500 mt-2 italic break-words">"{req.reason}"</p>}
                                    </div>
                                    {status === 'pending' ? (
                                        <>
                                            <input type="text" placeholder="Nota (opcional)" maxLength="500"
                                                value={notes[req.id] || ''}
                                                onChange={e => setNotes({ ...notes, [req.id]: e.target.value })}
                                                className="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm" />
                                            <div className="flex gap-2">
                                                <button onClick={() => decide(req.id, 'approved')} disabled={busyId === req.id}
                                                    className="flex-1 bg-emerald-500 text-white py-2.5 rounded-xl font-bold text-sm hover:bg-emerald-600 flex items-center justify-center gap-2 disabled:opacity-60 min-h-[44px]">
                                                    <Icon name="Check" size={16} /> Aprobar
                                                </button>
                                                <button onClick={() => decide(req.id, 'rejected')} disabled={busyId === req.id}
                                                    className="flex-1 bg-red-50 dark:bg-red-900/30 text-red-500 dark:text-red-300 py-2.5 rounded-xl font-bold text-sm hover:bg-red-100 flex items-center justify-center gap-2 disabled:opacity-60 min-h-[44px]">
                                                    <Icon name="X" size={16} /> Rechazar
                                                </button>
                                            </div>
                                        </>
                                    ) : (
                                        <div className="text-xs text-slate-400">
                                            {req.decided_at && <p>Decidido: {req.decided_at}</p>}
                                            {req.decision_note && <p className="italic mt-1">"{req.decision_note}"</p>}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            );
        };

        // Alias retro-compatible: OvertimeTab ahora muestra Vacaciones (modulo reemplazado)
        const OvertimeTab = VacationsTab;

        const LocationAlertsTab = ({ onChange }) => {
            const { push: toast } = useToast();
            const [items, setItems] = useState([]);
            const [statusFilter, setStatusFilter] = useState('pending');
            const [loading, setLoading] = useState(true);
            const [error, setError] = useState(null);
            const [busyId, setBusyId] = useState(null);
            const [notes, setNotes] = useState({});

            const load = useCallback(async () => {
                setLoading(true); setError(null);
                try {
                    const d = await apiFetch(`admin/location-alerts?status=${statusFilter}`);
                    setItems(d.alerts || []);
                } catch (e) { setError(e.message); }
                finally { setLoading(false); }
            }, [statusFilter]);
            useEffect(() => { load(); }, [load]);

            const review = async (id, decision) => {
                if (busyId) return;
                setBusyId(id);
                try {
                    await apiFetch(`admin/location-alerts/${id}/review`, {
                        method: 'POST',
                        body: { decision, notes: notes[id] || '' }
                    });
                    toast('success', decision === 'reviewed' ? 'Alerta marcada como revisada.' : 'Alerta descartada.');
                    load();
                    onChange?.();
                } catch (e) {
                    toast('error', e.message);
                } finally { setBusyId(null); }
            };

            const REASON_LABEL = {
                NEW_COUNTRY: 'Pais nuevo',
                IMPOSSIBLE_SPEED: 'Velocidad imposible',
                FAR_FROM_HISTORY: 'Lejos del historial',
            };

            if (loading) return <LoadingScreen />;
            if (error) return <ErrorState message={error} onRetry={load} />;

            return (
                <div className="space-y-4">
                    <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="font-black uppercase tracking-widest text-xs text-slate-500 mr-auto">
                            Alertas de cambio radical de ubicacion ({items.length})
                        </h3>
                        {['pending','reviewed','dismissed'].map(s => (
                            <button key={s} onClick={() => setStatusFilter(s)}
                                className={`px-3 py-1.5 rounded-xl text-xs font-bold uppercase tracking-wider transition-all ${statusFilter === s ? 'btn-melius shadow-sm' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-700 dark:hover:text-slate-100'}`}>
                                {s === 'pending' ? 'Pendientes' : s === 'reviewed' ? 'Revisadas' : 'Descartadas'}
                            </button>
                        ))}
                    </div>
                    {items.length === 0 && <EmptyState message={`Sin alertas ${statusFilter === 'pending' ? 'pendientes' : statusFilter === 'reviewed' ? 'revisadas' : 'descartadas'}.`} />}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        {items.map(a => {
                            const reasons = (a.reason_codes || '').split(',').filter(Boolean);
                            return (
                                <div key={a.id} className="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-red-200 dark:border-red-900/40 shadow-sm flex flex-col gap-3">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h4 className="font-bold text-base text-slate-800 dark:text-slate-100 truncate">{a.user_name}</h4>
                                            <p className="text-[10px] text-slate-400 font-black uppercase tracking-widest truncate">{a.user_email}</p>
                                            <p className="text-[10px] text-slate-400 font-black uppercase tracking-widest">{a.company_name || '— sin empresa'}</p>
                                        </div>
                                        <span className="shrink-0 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">
                                            {a.status === 'pending' ? 'PENDIENTE' : a.status === 'reviewed' ? 'REVISADA' : 'DESCARTADA'}
                                        </span>
                                    </div>
                                    <div className="flex gap-2 flex-wrap">
                                        {reasons.map(r => (
                                            <span key={r} className="px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-wider bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                                                {REASON_LABEL[r] || r}
                                            </span>
                                        ))}
                                    </div>
                                    <div className="grid grid-cols-2 gap-2 text-xs">
                                        <div className="bg-slate-50 dark:bg-slate-800/60 p-2 rounded-lg">
                                            <p className="text-[9px] text-slate-400 font-black uppercase">Previa</p>
                                            <p className="font-bold text-slate-700 dark:text-slate-200">
                                                {a.prev_city ? `${a.prev_city}, ${a.prev_country_code || '—'}` : (a.prev_country_code || '—')}
                                            </p>
                                            {a.prev_marked_at && <p className="text-[10px] text-slate-400">{a.prev_marked_at}</p>}
                                        </div>
                                        <div className="bg-red-50 dark:bg-red-900/20 p-2 rounded-lg">
                                            <p className="text-[9px] text-red-500 font-black uppercase">Actual</p>
                                            <p className="font-bold text-red-700 dark:text-red-300">
                                                {a.curr_city ? `${a.curr_city}, ${a.curr_country_code || '—'}` : (a.curr_country_code || '—')}
                                            </p>
                                            <p className="text-[10px] text-slate-400">{a.work_date} {a.entry_time}</p>
                                        </div>
                                    </div>
                                    {(a.distance_km !== null || a.implied_speed_kmh !== null) && (
                                        <div className="flex gap-3 text-[11px] text-slate-500">
                                            {a.distance_km !== null && <span><strong>{a.distance_km}</strong> km</span>}
                                            {a.implied_speed_kmh !== null && <span><strong>{a.implied_speed_kmh}</strong> km/h implicitos</span>}
                                            {a.elapsed_minutes !== null && <span><strong>{a.elapsed_minutes}</strong> min</span>}
                                        </div>
                                    )}
                                    {a.notes && a.status !== 'pending' && (
                                        <p className="text-xs text-slate-500 italic border-l-2 border-slate-300 pl-2">"{a.notes}"</p>
                                    )}
                                    {a.status === 'pending' && (
                                        <>
                                            <input type="text" placeholder="Notas (opcional)"
                                                value={notes[a.id] || ''}
                                                onChange={(e) => setNotes(n => ({ ...n, [a.id]: e.target.value }))}
                                                className="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60" />
                                            <div className="flex gap-2">
                                                <button onClick={() => review(a.id, 'reviewed')} disabled={busyId === a.id}
                                                    className="flex-1 bg-emerald-500 text-white py-2 rounded-xl font-bold text-sm hover:bg-emerald-600 disabled:opacity-50 flex items-center justify-center gap-2">
                                                    <Icon name="Check" size={16} /> Revisada
                                                </button>
                                                <button onClick={() => review(a.id, 'dismissed')} disabled={busyId === a.id}
                                                    className="flex-1 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 py-2 rounded-xl font-bold text-sm hover:bg-slate-200 disabled:opacity-50 flex items-center justify-center gap-2">
                                                    <Icon name="X" size={16} /> Descartar
                                                </button>
                                            </div>
                                        </>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            );
        };

        // =====================================================================
        // SecurityEventsTab — panel de eventos de seguridad con evidencia forense
        // =====================================================================
        const SecurityEventsTab = () => {
            const { push: toast } = useToast();
            const [events, setEvents] = useState([]);
            const [loading, setLoading] = useState(true);
            const [error, setError] = useState(null);
            const [typeFilter, setTypeFilter] = useState('all');
            const [showReviewed, setShowReviewed] = useState(false);
            const [busyId, setBusyId] = useState(null);
            const [expandedId, setExpandedId] = useState(null);
            const [unreviewedCount, setUnreviewedCount] = useState(0);

            const load = useCallback(async () => {
                setLoading(true); setError(null);
                try {
                    const params = new URLSearchParams({ type: typeFilter, reviewed: showReviewed ? 'true' : 'false' });
                    const d = await apiFetch('admin/security-events?' + params.toString());
                    setEvents(d.events || []);
                    setUnreviewedCount(d.unreviewed_count || 0);
                } catch (e) { setError(e.message); }
                finally { setLoading(false); }
            }, [typeFilter, showReviewed]);

            useEffect(() => { load(); }, [load]);

            const markReviewed = async (id) => {
                setBusyId(id);
                try {
                    await apiFetch('admin/security-events/' + id + '/review', { method: 'POST', body: {} });
                    toast('success', 'Evento marcado como revisado.');
                    load();
                } catch (e) { toast('error', e.message); }
                finally { setBusyId(null); }
            };

            const typeLabels = {
                dom_manipulation: { label: 'Manipulación DOM', color: 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300' },
                scraping:         { label: 'Scraping',         color: 'bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300' },
                brute_force:      { label: 'Fuerza bruta',     color: 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300' },
                bot_blocked:      { label: 'Bot bloqueado',    color: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300' },
                ip_blocked:       { label: 'IP bloqueada',     color: 'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300' },
            };

            const parseEvidence = (detail) => {
                try {
                    const idx = detail.indexOf('| evidence=');
                    if (idx === -1) return null;
                    return JSON.parse(detail.slice(idx + 11));
                } catch (_) { return null; }
            };

            const cleanDetail = (detail) => {
                const idx = detail.indexOf(' | evidence=');
                return idx === -1 ? detail : detail.slice(0, idx);
            };

            return (
                <div className="flex flex-col gap-4">
                    {/* Header */}
                    <div className="bg-white dark:bg-slate-900 rounded-2xl sm:rounded-3xl border border-slate-100 dark:border-slate-800 p-5 sm:p-7">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
                            <div className="flex items-center gap-3">
                                <div className="bg-red-100 dark:bg-red-900/30 p-3 rounded-2xl text-red-600 dark:text-red-400 shrink-0"><Icon name="Shield" size={20} /></div>
                                <div>
                                    <h3 className="font-black text-lg text-slate-800 dark:text-slate-100">Eventos de seguridad</h3>
                                    <p className="text-xs text-slate-500 dark:text-slate-400">Manipulación DOM, scraping, fuerza bruta, IPs bloqueadas</p>
                                </div>
                                {unreviewedCount > 0 && <span className="ml-2 px-2 py-0.5 rounded-full bg-red-500 text-white text-xs font-black">{unreviewedCount} sin revisar</span>}
                            </div>
                            <div className="flex flex-wrap gap-2 items-center">
                                <select value={typeFilter} onChange={e => setTypeFilter(e.target.value)}
                                    className="text-xs font-bold border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                                    <option value="all">Todos los tipos</option>
                                    <option value="dom_manipulation">Manipulación DOM</option>
                                    <option value="scraping">Scraping</option>
                                    <option value="brute_force">Fuerza bruta</option>
                                    <option value="bot_blocked">Bot bloqueado</option>
                                    <option value="ip_blocked">IP bloqueada</option>
                                </select>
                                <button onClick={() => setShowReviewed(v => !v)}
                                    className={`text-xs font-bold px-3 py-2 rounded-xl border transition-all ${showReviewed ? 'btn-melius' : 'border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400 bg-white dark:bg-slate-800'}`}>
                                    {showReviewed ? 'Mostrando todos' : 'Solo sin revisar'}
                                </button>
                                <button onClick={load} className="text-xs font-bold px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-500 bg-white dark:bg-slate-800 hover:bg-slate-50 transition-all"><Icon name="RefreshCw" size={13} /></button>
                            </div>
                        </div>

                        {loading && <div className="text-center py-10 text-slate-400 text-sm">Cargando...</div>}
                        {error && <div className="text-center py-10 text-red-500 text-sm">{error}</div>}
                        {!loading && !error && events.length === 0 && (
                            <div className="text-center py-12">
                                <div className="text-4xl mb-3">&#128274;</div>
                                <p className="font-black text-slate-600 dark:text-slate-300">Sin eventos de seguridad</p>
                                <p className="text-xs text-slate-400 mt-1">No se han detectado actividades sospechosas con los filtros actuales.</p>
                            </div>
                        )}
                        {!loading && !error && events.length > 0 && (
                            <div className="flex flex-col gap-3">
                                {events.map(ev => {
                                    const meta = typeLabels[ev.event_type] || { label: ev.event_type, color: 'bg-slate-100 text-slate-600' };
                                    const evidence = parseEvidence(ev.detail || '');
                                    const detail = cleanDetail(ev.detail || '');
                                    const isExpanded = expandedId === ev.id;
                                    return (
                                        <div key={ev.id} className={`rounded-2xl border transition-all ${ev.reviewed ? 'border-slate-100 dark:border-slate-800 opacity-60' : 'border-red-100 dark:border-red-900/40 bg-red-50/30 dark:bg-red-950/10'}`}>
                                            <div className="p-4 flex flex-col sm:flex-row sm:items-start gap-3">
                                                {/* Tipo + badge */}
                                                <div className="shrink-0">
                                                    <span className={`text-[10px] font-black uppercase px-2 py-1 rounded-lg ${meta.color}`}>{meta.label}</span>
                                                </div>
                                                {/* Info principal */}
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400 mb-1">
                                                        <span className="font-mono font-bold text-slate-700 dark:text-slate-200">{ev.ip}</span>
                                                        {ev.user_name && <span className="font-bold text-slate-600 dark:text-slate-300">{ev.user_name}</span>}
                                                        {ev.user_email && <span className="text-slate-400">{ev.user_email}</span>}
                                                        <span>{new Date(ev.created_at).toLocaleString('es-MX')}</span>
                                                    </div>
                                                    <p className="text-xs text-slate-600 dark:text-slate-300 break-all">{detail}</p>
                                                    {/* Evidencia forense si existe */}
                                                    {evidence && (
                                                        <div className="mt-2 flex flex-wrap gap-2">
                                                            {evidence.action_attempted && (
                                                                <span className="text-[10px] font-bold bg-orange-50 dark:bg-orange-900/20 text-orange-700 dark:text-orange-300 px-2 py-0.5 rounded-lg">
                                                                    Intento: {evidence.action_attempted}
                                                                </span>
                                                            )}
                                                            <span className={`text-[10px] font-bold px-2 py-0.5 rounded-lg ${evidence.succeeded ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' : 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300'}`}>
                                                                {evidence.succeeded ? 'Logrado' : 'Bloqueado'}
                                                            </span>
                                                            {ev.user_agent && (
                                                                <button onClick={() => setExpandedId(isExpanded ? null : ev.id)}
                                                                    className="text-[10px] font-bold text-melius-cyan underline">
                                                                    {isExpanded ? 'Ocultar detalle' : 'Ver evidencia completa'}
                                                                </button>
                                                            )}
                                                        </div>
                                                    )}
                                                    {isExpanded && (
                                                        <div className="mt-3 p-3 bg-slate-900 dark:bg-black rounded-xl text-[10px] font-mono text-green-400 break-all space-y-1">
                                                            {ev.user_agent && <p><span className="text-slate-400">UA:</span> {ev.user_agent}</p>}
                                                            {ev.uri && <p><span className="text-slate-400">URI:</span> {ev.uri}</p>}
                                                            {evidence?.fingerprint && <p><span className="text-slate-400">Fingerprint:</span> {evidence.fingerprint}</p>}
                                                            {evidence?.timestamp_ms && <p><span className="text-slate-400">Timestamp:</span> {new Date(evidence.timestamp_ms).toISOString()}</p>}
                                                        </div>
                                                    )}
                                                </div>
                                                {/* Acción */}
                                                {!ev.reviewed && (
                                                    <button onClick={() => markReviewed(ev.id)} disabled={busyId === ev.id}
                                                        className="shrink-0 text-xs font-bold px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 hover:text-emerald-700 dark:hover:text-emerald-300 transition-all disabled:opacity-50">
                                                        {busyId === ev.id ? '...' : 'Revisado'}
                                                    </button>
                                                )}
                                                {ev.reviewed && <span className="shrink-0 text-[10px] text-emerald-500 font-black uppercase">Revisado</span>}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>
            );
        };

        const TzMismatchBadge = ({ rec }) => {
            if (!rec.tz_mismatch) return null;
            const profile = rec.timezone || '—';
            const client = rec.client_timezone || '—';
            return (
                <span
                    title={`Marco desde ${client} (perfil: ${profile})`}
                    className="ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 cursor-help">
                    TZ
                </span>
            );
        };

        const GeoBadge = ({ rec }) => {
            const code = rec.geo_country_code;
            if (!code) return null;
            const name = rec.geo_country_name || code;
            const city = rec.geo_city;
            const label = city ? `${city}, ${name}` : name;
            return (
                <span
                    title={`Marco desde ${label} (IP)`}
                    className="ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300 cursor-help">
                    {code}
                </span>
            );
        };

        const GeoExitBadge = ({ rec }) => {
            const code = rec.geo_exit_country_code;
            if (!code) return null;
            if (code === rec.geo_country_code) return null; // misma ubicacion = no aporta
            const city = rec.geo_exit_city;
            const label = city ? `${city} (salida)` : `${code} (salida)`;
            return (
                <span
                    title={`Salida desde ${label}`}
                    className="ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300 cursor-help">
                    {code}<Icon name="LogOut" size={10} />
                </span>
            );
        };

        const GeoAlertBadge = ({ rec }) => {
            if (!rec.geo_alert_flag) return null;
            const reasonMap = {
                NEW_COUNTRY: 'Pais nuevo en historial',
                IMPOSSIBLE_SPEED: 'Velocidad imposible vs ultimo marcaje',
                FAR_FROM_HISTORY: 'Distancia inusual del historial',
            };
            const reasons = (rec.geo_alert_reasons || '').split(',').filter(Boolean);
            const tooltip = reasons.length > 0
                ? 'Alerta de ubicacion: ' + reasons.map(r => reasonMap[r] || r).join('; ')
                : 'Alerta de ubicacion';
            return (
                <span
                    title={tooltip}
                    className="ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 cursor-help animate-pulse">
                    <Icon name="AlertTriangle" size={10} /> ALERTA
                </span>
            );
        };

        const AdminRecordRow = ({ rec }) => {
            const stateLabel = rec.exit_time
                ? (rec.closed_reason === 'forgotten' ? 'OLVIDO 18:00' : 'COMPLETO')
                : 'EN TURNO';
            const stateClass = rec.exit_time
                ? (rec.closed_reason === 'forgotten' ? 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' :
                   'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300')
                : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300';
            return (
                <tr className="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors">
                    <td className="px-6 py-4 font-bold text-slate-800 dark:text-slate-100">{rec.user_name}</td>
                    <td className="px-6 py-4 text-slate-600 dark:text-slate-300">{rec.company_name || '—'}</td>
                    <td className="px-6 py-4 text-slate-500 dark:text-slate-400 font-medium">
                        {rec.work_date}<TzMismatchBadge rec={rec} /><GeoBadge rec={rec} /><GeoExitBadge rec={rec} /><GeoAlertBadge rec={rec} />
                        {rec.late_close && (
                            <span title={`Cierre tardio: +${rec.late_minutes} min`}
                                className="ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 cursor-help">
                                <Icon name="AlertTriangle" size={10} /> TARDIO +{rec.late_minutes}m
                            </span>
                        )}
                    </td>
                    <td className="px-6 py-4 font-mono font-bold text-blue-600 dark:text-blue-400">
                        <div>{rec.entry_time}<span className="text-[9px] font-bold text-slate-400 ml-1">local</span></div>
                        {rec.entry_time_cdmx && rec.entry_time_cdmx !== rec.entry_time && (
                            <div className="text-[11px] text-slate-500 dark:text-slate-400 font-mono">{rec.entry_time_cdmx} <span className="text-[9px] font-bold text-slate-400">CDMX</span></div>
                        )}
                    </td>
                    <td className="px-6 py-4 font-mono font-bold text-orange-600 dark:text-orange-400">
                        <div>{rec.exit_time || '--:--'}<span className="text-[9px] font-bold text-slate-400 ml-1">local</span></div>
                        {rec.exit_time_cdmx && rec.exit_time_cdmx !== rec.exit_time && (
                            <div className="text-[11px] text-slate-500 dark:text-slate-400 font-mono">{rec.exit_time_cdmx} <span className="text-[9px] font-bold text-slate-400">CDMX</span></div>
                        )}
                    </td>
                    <td className="px-6 py-4"><span className={`px-3 py-1 rounded-full text-[10px] font-black tracking-tighter ${stateClass}`}>{stateLabel}</span></td>
                </tr>
            );
        };

        const AdminRecordCard = ({ rec }) => (
            <div className="p-5 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors">
                <div className="flex justify-between items-start mb-3">
                    <div>
                        <p className="font-bold text-slate-800 dark:text-slate-100">{rec.user_name}</p>
                        <p className="text-xs text-slate-500 dark:text-slate-400">{rec.company_name || 'Sin empresa'}</p>
                    </div>
                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">
                        {rec.work_date}<TzMismatchBadge rec={rec} /><GeoBadge rec={rec} /><GeoExitBadge rec={rec} /><GeoAlertBadge rec={rec} />
                    </span>
                </div>
                <div className="flex gap-3 text-sm flex-wrap">
                    <div>
                        <p className="text-[9px] font-black uppercase text-slate-400">Entrada</p>
                        <p className="font-mono font-bold text-blue-600 dark:text-blue-400">{rec.entry_time} <span className="text-[8px] font-bold text-slate-400">local</span></p>
                        {rec.entry_time_cdmx && rec.entry_time_cdmx !== rec.entry_time && (
                            <p className="font-mono text-[11px] text-slate-500 dark:text-slate-400">{rec.entry_time_cdmx} <span className="text-[8px] font-bold">CDMX</span></p>
                        )}
                    </div>
                    <div>
                        <p className="text-[9px] font-black uppercase text-slate-400">Salida</p>
                        <p className="font-mono font-bold text-orange-600 dark:text-orange-400">{rec.exit_time || '--:--'} <span className="text-[8px] font-bold text-slate-400">local</span></p>
                        {rec.exit_time_cdmx && rec.exit_time_cdmx !== rec.exit_time && (
                            <p className="font-mono text-[11px] text-slate-500 dark:text-slate-400">{rec.exit_time_cdmx} <span className="text-[8px] font-bold">CDMX</span></p>
                        )}
                    </div>
                </div>
            </div>
        );

        // =====================================================================
        // DOM Hardening v2 — deteccion de manipulacion con evidencia forense.
        // Captura: tipo de intento, fingerprint del navegador, si tuvo exito.
        // Muestra alerta visible al atacante y reporta al backend.
        // =====================================================================
        (function domHardening() {
            const rootEl = document.getElementById('root');
            if (!rootEl || typeof MutationObserver === 'undefined') return;

            // Fingerprint ligero del navegador para identificar al actor
            const buildFingerprint = () => {
                try {
                    const nav = window.navigator;
                    return [
                        nav.userAgent.slice(0, 120),
                        nav.language,
                        String(screen.width) + 'x' + String(screen.height),
                        String(nav.hardwareConcurrency || ''),
                        Intl.DateTimeFormat().resolvedOptions().timeZone,
                        String(nav.platform || ''),
                    ].join('|');
                } catch (_) { return 'unknown'; }
            };

            // Alerta visual intimidante al atacante — se muestra en pantalla completa
            const showWarning = (action) => {
                if (document.getElementById('_sec_warn')) return;
                const overlay = document.createElement('div');
                overlay.id = '_sec_warn';
                overlay.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,0.97);display:flex;flex-direction:column;align-items:center;justify-content:center;font-family:monospace;padding:32px;text-align:center;';

                const bypassId   = '_sec_bypass_pw';
                const bypassBtn  = '_sec_bypass_btn';
                const bypassMsg  = '_sec_bypass_msg';

                overlay.innerHTML = '<div style="max-width:560px;">'
                    + '<div style="font-size:48px;margin-bottom:24px;">&#9888;</div>'
                    + '<p style="color:#ef4444;font-size:20px;font-weight:900;letter-spacing:0.05em;margin-bottom:16px;">ACTIVIDAD SOSPECHOSA DETECTADA</p>'
                    + '<p style="color:#f97316;font-size:14px;font-weight:700;margin-bottom:12px;">Intento registrado: <span style="color:#fbbf24;">' + action.replace(/</g,'&lt;') + '</span></p>'
                    + '<p style="color:#94a3b8;font-size:13px;line-height:1.6;margin-bottom:24px;">Este sistema monitorea y registra manipulaciones del DOM en tiempo real.<br>Tu IP, huella digital del navegador y la accion realizada han sido enviados<br>al equipo de seguridad para su revision.</p>'
                    + '<p style="color:#64748b;font-size:11px;margin-bottom:20px;">Si eres super administrador, confirma tu contrasena para continuar.</p>'
                    + '<div style="display:flex;gap:8px;justify-content:center;align-items:center;flex-wrap:wrap;">'
                    + '<input id="' + bypassId + '" type="password" placeholder="Contrasena de super admin" autocomplete="current-password" style="padding:10px 14px;background:#1e293b;color:#e2e8f0;border:1px solid #334155;border-radius:8px;font-size:13px;font-family:monospace;min-width:220px;" />'
                    + '<button id="' + bypassBtn + '" style="padding:10px 20px;background:#0f172a;color:#94a3b8;border:1px solid #334155;border-radius:8px;cursor:pointer;font-size:12px;font-family:monospace;">Verificar</button>'
                    + '</div>'
                    + '<p id="' + bypassMsg + '" style="color:#ef4444;font-size:11px;margin-top:8px;min-height:16px;"></p>'
                    + '</div>';

                document.body.appendChild(overlay);

                const btn = document.getElementById(bypassBtn);
                const inp = document.getElementById(bypassId);
                const msg = document.getElementById(bypassMsg);
                if (btn && inp) {
                    btn.addEventListener('click', () => {
                        const pw = inp.value;
                        if (!pw) { msg.textContent = 'Ingresa tu contrasena.'; return; }
                        btn.textContent = '...';
                        btn.disabled = true;
                        fetch('/api/auth/verify-password', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ password: pw }),
                        }).then(r => r.json()).then(d => {
                            if (d.ok && d.is_super_admin) {
                                overlay.remove();
                            } else if (d.ok) {
                                msg.textContent = 'Solo super administradores pueden cerrar esta alerta.';
                                btn.textContent = 'Verificar'; btn.disabled = false;
                            } else {
                                msg.textContent = 'Contrasena incorrecta.';
                                btn.textContent = 'Verificar'; btn.disabled = false;
                            }
                        }).catch(() => {
                            msg.textContent = 'Error de red. Intenta de nuevo.';
                            btn.textContent = 'Verificar'; btn.disabled = false;
                        });
                    });
                    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') btn.click(); });
                }
            };

            let reportThrottle = null;
            const report = (detail, actionAttempted, succeeded) => {
                showWarning(actionAttempted || detail);
                if (reportThrottle) return;
                reportThrottle = setTimeout(() => { reportThrottle = null; }, 8000);
                try {
                    fetch('/api/anti-bot/dom-report', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            detail: detail.slice(0, 500),
                            action_attempted: (actionAttempted || '').slice(0, 100),
                            succeeded: !!succeeded,
                            fingerprint: buildFingerprint(),
                            timestamp_ms: Date.now(),
                        }),
                    }).catch(() => {});
                } catch (_) {}
            };

            const observer = new MutationObserver((mutations) => {
                for (const m of mutations) {
                    for (const node of m.addedNodes) {
                        if (node.nodeType !== 1) continue;
                        // Inyeccion de script
                        if (node.tagName === 'SCRIPT') {
                            const src = node.src || node.textContent.slice(0, 120);
                            node.remove();
                            report('script_injection: ' + src, 'inyectar_script', false);
                        }
                        // Iframe oculto (posible clickjacking o exfiltración)
                        if (node.tagName === 'IFRAME' && (node.style.display === 'none' || node.style.visibility === 'hidden' || node.width === '0')) {
                            node.remove();
                            report('hidden_iframe: ' + (node.src || '').slice(0, 120), 'inyectar_iframe_oculto', false);
                        }
                    }
                    // Eliminacion del contenedor raiz
                    if (m.type === 'childList') {
                        for (const node of m.removedNodes) {
                            if (node.id === 'root') {
                                report('root_removed', 'eliminar_contenedor_react', true);
                            }
                        }
                    }
                    // Modificacion de atributos criticos del root
                    if (m.type === 'attributes' && m.target === rootEl) {
                        report('root_attr_modified: ' + m.attributeName, 'modificar_atributo_root', true);
                    }
                }
            });
            observer.observe(document.body, {
                childList: true, subtree: true,
                attributes: true,
                attributeFilter: ['data-reactroot', 'id', 'class'],
            });

            // Detectar override de fetch/XMLHttpRequest (posible interceptor de credenciales)
            const _origFetch = window.fetch;
            const _origXHR   = window.XMLHttpRequest;
            setTimeout(() => {
                if (window.fetch !== _origFetch) {
                    report('fetch_override_detected', 'interceptar_fetch', true);
                }
                if (window.XMLHttpRequest !== _origXHR) {
                    report('xhr_override_detected', 'interceptar_xhr', true);
                }
            }, 3000);
        })();

        const root = ReactDOM.createRoot(document.getElementById('root'));
        root.render(<ToastProvider><BrandingProvider><App /></BrandingProvider></ToastProvider>);