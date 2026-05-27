function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const {
  useState,
  useEffect,
  useMemo,
  useRef,
  useCallback
} = React;
const API_BASE = '/api';
let CSRF_TOKEN = null;
async function apiFetch(path, {
  method = 'GET',
  body = null
} = {}) {
  const headers = {
    'Accept': 'application/json'
  };
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
    throw {
      code: 'NETWORK_ERROR',
      message: 'Sin conexión con el servidor.'
    };
  }
  let payload;
  try {
    payload = await res.json();
  } catch (_) {
    payload = {
      ok: false,
      error: {
        code: 'BAD_RESPONSE',
        message: 'Respuesta no JSON.'
      }
    };
  }
  if (!res.ok || !payload.ok) {
    const err = payload.error || {
      code: 'UNKNOWN',
      message: `HTTP ${res.status}`
    };
    if (res.status === 401) err._auth = true;
    throw err;
  }
  return payload.data;
}
async function fetchCsrf() {
  const d = await apiFetch('csrf');
  CSRF_TOKEN = d.csrf_token;
}
async function apiPost(path, body) {
  try {
    return await apiFetch(path, {
      method: 'POST',
      body
    });
  } catch (err) {
    if (err && err.code === 'CSRF_INVALID') {
      await fetchCsrf();
      return await apiFetch(path, {
        method: 'POST',
        body
      });
    }
    throw err;
  }
}
const ToastContext = React.createContext({
  push: () => {}
});
const ToastProvider = ({
  children
}) => {
  const [toasts, setToasts] = useState([]);
  const push = useCallback((type, message) => {
    const id = Date.now() + Math.random();
    setToasts(t => [...t, {
      id,
      type,
      message
    }]);
    setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 4500);
  }, []);
  return React.createElement(ToastContext.Provider, {
    value: {
      push
    }
  }, children, React.createElement("div", {
    className: "toast-stack",
    role: "status",
    "aria-live": "polite"
  }, toasts.map(t => React.createElement("div", {
    key: t.id,
    className: `pointer-events-auto rounded-2xl px-4 py-3 shadow-lg border text-sm font-medium anim-fade-in ${t.type === 'error' ? 'bg-red-50 dark:bg-red-900/40 border-red-200 dark:border-red-800 text-red-700 dark:text-red-200' : t.type === 'success' ? 'bg-emerald-50 dark:bg-emerald-900/40 border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-200' : t.type === 'warning' ? 'bg-amber-50 dark:bg-amber-900/40 border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-200' : 'bg-blue-50 dark:bg-blue-900/40 border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-200'}`
  }, t.message))));
};
const useToast = () => React.useContext(ToastContext);
const Icon = ({
  name,
  size = 24,
  className = ""
}) => {
  const icons = {
    Clock: React.createElement("path", {
      d: "M12 6v6l4 2"
    }),
    LogIn: React.createElement("path", {
      d: "M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"
    }),
    LogOut: React.createElement("path", {
      d: "M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"
    }),
    History: React.createElement(React.Fragment, null, React.createElement("path", {
      d: "M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"
    }), React.createElement("path", {
      d: "M3 3v5h5M12 7v5l4 2"
    })),
    ShieldCheck: React.createElement(React.Fragment, null, React.createElement("path", {
      d: "M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"
    }), React.createElement("path", {
      d: "m9 12 2 2 4-4"
    })),
    FileText: React.createElement(React.Fragment, null, React.createElement("path", {
      d: "M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"
    }), React.createElement("polyline", {
      points: "14 2 14 8 20 8"
    }), React.createElement("line", {
      x1: "16",
      y1: "13",
      x2: "8",
      y2: "13"
    }), React.createElement("line", {
      x1: "16",
      y1: "17",
      x2: "8",
      y2: "17"
    })),
    ArrowLeftRight: React.createElement("path", {
      d: "M8 3 4 7l4 4M4 7h16M16 21l4-4-4-4M20 17H4"
    }),
    Check: React.createElement("path", {
      d: "M20 6 9 17l-5-5"
    }),
    X: React.createElement("path", {
      d: "M18 6 6 18M6 6l12 12"
    }),
    Mail: React.createElement(React.Fragment, null, React.createElement("rect", {
      width: "20",
      height: "16",
      x: "2",
      y: "4",
      rx: "2"
    }), React.createElement("path", {
      d: "m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"
    })),
    ArrowDown: React.createElement("path", {
      d: "M12 5v14M19 12l-7 7-7-7"
    }),
    Lock: React.createElement(React.Fragment, null, React.createElement("rect", {
      width: "18",
      height: "11",
      x: "3",
      y: "11",
      rx: "2",
      ry: "2"
    }), React.createElement("path", {
      d: "M7 11V7a5 5 0 0 1 10 0v4"
    })),
    Sun: React.createElement(React.Fragment, null, React.createElement("circle", {
      cx: "12",
      cy: "12",
      r: "4"
    }), React.createElement("path", {
      d: "M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"
    })),
    Moon: React.createElement("path", {
      d: "M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"
    }),
    Hourglass: React.createElement("path", {
      d: "M5 22h14M5 2h14M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"
    }),
    AlertTriangle: React.createElement(React.Fragment, null, React.createElement("path", {
      d: "M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"
    }), React.createElement("line", {
      x1: "12",
      y1: "9",
      x2: "12",
      y2: "13"
    }), React.createElement("line", {
      x1: "12",
      y1: "17",
      x2: "12.01",
      y2: "17"
    })),
    Spinner: React.createElement("circle", {
      cx: "12",
      cy: "12",
      r: "10",
      strokeDasharray: "40",
      strokeDashoffset: "10"
    }),
    Home: React.createElement("path", {
      d: "M3 12 12 3l9 9M5 10v10h14V10"
    }),
    Users: React.createElement(React.Fragment, null, React.createElement("path", {
      d: "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"
    }), React.createElement("circle", {
      cx: "9",
      cy: "7",
      r: "4"
    }), React.createElement("path", {
      d: "M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"
    })),
    MoreHorizontal: React.createElement(React.Fragment, null, React.createElement("circle", {
      cx: "12",
      cy: "12",
      r: "1"
    }), React.createElement("circle", {
      cx: "19",
      cy: "12",
      r: "1"
    }), React.createElement("circle", {
      cx: "5",
      cy: "12",
      r: "1"
    })),
    Building: React.createElement(React.Fragment, null, React.createElement("rect", {
      width: "16",
      height: "20",
      x: "4",
      y: "2",
      rx: "2"
    }), React.createElement("path", {
      d: "M9 22v-4h6v4M8 6h.01M16 6h.01M12 6h.01M8 10h.01M16 10h.01M12 10h.01M8 14h.01M16 14h.01M12 14h.01"
    })),
    Tag: React.createElement(React.Fragment, null, React.createElement("path", {
      d: "M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"
    }), React.createElement("circle", {
      cx: "7",
      cy: "7",
      r: "1"
    })),
    ChevronDown: React.createElement("path", {
      d: "m6 9 6 6 6-6"
    }),
    Bell: React.createElement(React.Fragment, null, React.createElement("path", {
      d: "M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"
    }), React.createElement("path", {
      d: "M10.3 21a1.94 1.94 0 0 0 3.4 0"
    })),
    User: React.createElement(React.Fragment, null, React.createElement("path", {
      d: "M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"
    }), React.createElement("circle", {
      cx: "12",
      cy: "7",
      r: "4"
    })),
    CalendarDays: React.createElement(React.Fragment, null, React.createElement("rect", {
      width: "18",
      height: "18",
      x: "3",
      y: "4",
      rx: "2"
    }), React.createElement("path", {
      d: "M16 2v4M8 2v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"
    })),
    Plane: React.createElement("path", {
      d: "M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"
    })
  };
  return React.createElement("svg", {
    xmlns: "http://www.w3.org/2000/svg",
    width: size,
    height: size,
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "2",
    strokeLinecap: "round",
    strokeLinejoin: "round",
    className: className + (name === 'Spinner' ? ' animate-spin' : ''),
    "aria-hidden": "true",
    focusable: "false"
  }, icons[name] || null);
};
const ThemeToggle = ({
  theme,
  onToggle
}) => React.createElement("button", {
  type: "button",
  onClick: onToggle,
  "aria-label": theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro',
  className: "p-3 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500"
}, React.createElement(Icon, {
  name: theme === 'dark' ? 'Sun' : 'Moon',
  size: 18
}));
const Modal = ({
  open,
  onClose,
  title,
  children,
  maxWidth = 'max-w-md',
  dismissible = true
}) => {
  const dialogRef = useRef(null);
  useEffect(() => {
    if (!open) return;
    const onKey = e => {
      if (e.key === 'Escape' && dismissible) onClose();
    };
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
  return React.createElement("div", {
    className: "fixed inset-0 bg-slate-900/60 dark:bg-black/70 backdrop-blur-md z-50 flex items-center justify-center p-2 sm:p-6",
    onMouseDown: e => {
      if (dismissible && e.target === e.currentTarget) onClose();
    },
    role: "presentation"
  }, React.createElement("div", {
    ref: dialogRef,
    role: "dialog",
    "aria-modal": "true",
    "aria-label": title,
    className: `bg-white dark:bg-slate-900 w-full ${maxWidth} max-h-[92vh] overflow-y-auto custom-scrollbar rounded-2xl sm:rounded-[2.5rem] p-4 sm:p-10 shadow-2xl border border-slate-100 dark:border-slate-800 anim-zoom-in`
  }, children));
};
const Select = React.forwardRef(({
  value,
  onChange,
  children,
  className = '',
  size = 'md',
  disabled,
  ...rest
}, ref) => {
  const sizes = {
    sm: 'px-3 py-2 text-sm pr-9',
    md: 'px-4 py-3 text-sm pr-10 min-h-[44px]',
    lg: 'px-4 py-3.5 text-base pr-10 min-h-[48px]'
  };
  return React.createElement("div", {
    className: `relative ${className}`
  }, React.createElement("select", _extends({
    ref: ref,
    value: value,
    onChange: onChange,
    disabled: disabled,
    className: `appearance-none w-full ${sizes[size] || sizes.md} rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-700 dark:text-slate-200 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 disabled:opacity-50 disabled:cursor-not-allowed transition-all`
  }, rest), children), React.createElement("span", {
    className: "pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500"
  }, React.createElement("svg", {
    width: "16",
    height: "16",
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "2.5",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, React.createElement("polyline", {
    points: "6 9 12 15 18 9"
  }))));
});
const LoadingScreen = () => React.createElement("div", {
  className: "min-h-screen flex flex-col items-center justify-center gap-4 text-slate-400 dark:text-slate-500"
}, React.createElement(Icon, {
  name: "Spinner",
  size: 32
}), React.createElement("p", {
  className: "text-xs uppercase tracking-widest font-bold"
}, "Cargando"));
const ErrorState = ({
  message,
  onRetry
}) => React.createElement("div", {
  className: "bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-3xl p-6 text-center"
}, React.createElement("p", {
  className: "text-red-700 dark:text-red-300 font-bold mb-3"
}, message), onRetry && React.createElement("button", {
  onClick: onRetry,
  className: "text-xs font-black uppercase tracking-widest text-red-600 dark:text-red-300 underline"
}, "Reintentar"));
const EmptyState = ({
  message
}) => React.createElement("div", {
  className: "text-center py-12 sm:py-20 text-slate-500 dark:text-slate-400 italic font-medium"
}, message);
const TOUR_STORAGE_KEY = 'melius.tour.v1.completed';
const TOUR_STEPS_USER = [{
  sel: 'header-user',
  title: 'Tu identidad',
  body: 'Aquí ves tu nombre, la marca y la empresa a la que estás asignado. Si la empresa no corresponde, solicita el cambio desde el enlace inferior. Solo puedes pedir cambios entre empresas de la misma marca.'
}, {
  sel: 'btn-clockin',
  title: 'Marcar entrada',
  body: 'Inicia tu jornada con un solo toque. El sistema registra la hora exacta y tu zona horaria. Solo puedes marcar entrada una vez al día.'
}, {
  sel: 'btn-clockout',
  title: 'Marcar salida',
  body: 'Cierra tu jornada al terminar. Si olvidaste marcar el día anterior y entras antes de las 06:00, el sistema cerrará automáticamente la jornada previa a las 18:00.'
}, {
  sel: 'btn-vacation',
  title: 'Vacaciones',
  body: 'Solicita un rango de días de vacaciones con motivo opcional. La solicitud queda pendiente hasta que el administrador la apruebe o la rechace. No puedes solapar fechas con solicitudes activas.'
}, {
  sel: 'btn-history',
  title: 'Tu historial',
  body: 'Consulta todos tus registros previos con horas trabajadas y motivos de cierre. Puedes exportar tu historial a CSV cuando lo necesites.'
}, {
  sel: 'user-menu',
  title: 'Tu menú de usuario',
  body: 'Desde aquí cambias entre tema claro y oscuro, repites este tutorial cuando quieras o cierras sesión. También verás tu correo y nombre de la sesión activa.'
}];
const TOUR_STEPS_ADMIN = [{
  sel: 'admin-header',
  title: 'Panel administrativo',
  body: 'Aquí gestionas a tu equipo: consultores, empresas, solicitudes pendientes y reportes. Tu vista depende de tu rol (admin o super admin).'
}, {
  sel: 'admin-tab-dashboard',
  title: 'Dashboard',
  body: 'Resumen ejecutivo del día: horas trabajadas, días de vacaciones aprobados, solicitudes pendientes, consultores activos y registros que requieren tu atención.'
}, {
  sel: 'admin-tab-records',
  title: 'Registros',
  body: 'Todos los marcajes del equipo. Filtra por consultor, fecha, empresa o estatus. Exporta a CSV para reportes externos.'
}, {
  sel: 'admin-tab-agents',
  title: 'Consultores',
  body: 'Invita consultores por correo (individual o por CSV masivo), gestiona sus perfiles, reenvía invitaciones pendientes y desactiva accesos cuando salgan del equipo.'
}, {
  sel: 'admin-tab-requests',
  title: 'Solicitudes',
  body: 'Aprueba o rechaza cambios de empresa y solicitudes de vacaciones de los consultores. Las solicitudes aprobadas se contabilizan automáticamente en los reportes.'
}, {
  sel: 'admin-user-menu',
  title: 'Tu menú de usuario',
  body: 'Desde aquí vuelves a tu checador personal, alternas el tema, repites este tutorial o cierras sesión.'
}];
function getTourSeen(viewKey) {
  try {
    const raw = localStorage.getItem(TOUR_STORAGE_KEY);
    if (!raw) return false;
    const seen = JSON.parse(raw);
    return seen[viewKey] === true;
  } catch (_) {
    return false;
  }
}
function markTourSeen(viewKey) {
  try {
    const raw = localStorage.getItem(TOUR_STORAGE_KEY);
    const seen = raw ? JSON.parse(raw) : {};
    seen[viewKey] = true;
    localStorage.setItem(TOUR_STORAGE_KEY, JSON.stringify(seen));
  } catch (_) {}
}
const TourTooltip = ({
  steps,
  onClose
}) => {
  const [idx, setIdx] = useState(0);
  const [box, setBox] = useState(null);
  useEffect(() => {
    let raf, timer;
    const measure = () => {
      const step = steps[idx];
      if (!step) {
        setBox(null);
        return;
      }
      const el = document.querySelector(`[data-tour="${step.sel}"]`);
      if (!el) {
        setBox({
          missing: true
        });
        return;
      }
      el.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
      });
      timer = setTimeout(() => {
        raf = requestAnimationFrame(() => {
          const r = el.getBoundingClientRect();
          const vw = window.innerWidth;
          const vh = window.innerHeight;
          const outOfView = r.bottom < 0 || r.top > vh || r.right < 0 || r.left > vw;
          if (outOfView) {
            setBox({
              missing: true
            });
            return;
          }
          setBox({
            top: r.top,
            left: r.left,
            width: r.width,
            height: r.height
          });
        });
      }, 320);
    };
    measure();
    const onResize = () => measure();
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
  const TOOLTIP_H = 260;
  const MARGIN = 16;
  let tooltipStyle = {
    position: 'fixed',
    top: '50%',
    left: '50%',
    transform: 'translate(-50%, -50%)',
    maxWidth: 'calc(100vw - 32px)',
    width: 'calc(100vw - 32px)',
    maxHeight: 'calc(100vh - 32px)',
    overflowY: 'auto'
  };
  if (box && !box.missing) {
    const vh = window.innerHeight;
    const spaceBelow = vh - (box.top + box.height);
    const spaceAbove = box.top;
    const canFitBelow = spaceBelow >= TOOLTIP_H + MARGIN;
    const canFitAbove = spaceAbove >= TOOLTIP_H + MARGIN;
    if (canFitBelow) {
      const top = Math.max(MARGIN, Math.min(vh - TOOLTIP_H - MARGIN, box.top + box.height + 12));
      tooltipStyle = {
        position: 'fixed',
        top: `${top}px`,
        left: `${MARGIN}px`,
        right: `${MARGIN}px`,
        maxWidth: '420px',
        marginLeft: 'auto',
        marginRight: 'auto',
        maxHeight: `calc(100vh - ${top + MARGIN}px)`,
        overflowY: 'auto'
      };
    } else if (canFitAbove) {
      const bottom = Math.max(MARGIN, vh - box.top + 12);
      tooltipStyle = {
        position: 'fixed',
        bottom: `${bottom}px`,
        left: `${MARGIN}px`,
        right: `${MARGIN}px`,
        maxWidth: '420px',
        marginLeft: 'auto',
        marginRight: 'auto',
        maxHeight: `calc(100vh - ${bottom + MARGIN}px)`,
        overflowY: 'auto'
      };
    }
  }
  return React.createElement("div", {
    className: "fixed inset-0 z-[60] pointer-events-none"
  }, React.createElement("div", {
    className: "absolute inset-0 bg-slate-900/70 pointer-events-auto",
    onClick: onClose
  }), box && !box.missing && React.createElement("div", {
    className: "absolute rounded-2xl ring-4 ring-melius-cyan ring-offset-2 ring-offset-slate-900/0 pointer-events-none animate-pulse",
    style: {
      top: box.top - 4,
      left: box.left - 4,
      width: box.width + 8,
      height: box.height + 8
    }
  }), React.createElement("div", {
    className: "bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 p-5 pointer-events-auto anim-zoom-in",
    style: tooltipStyle,
    role: "dialog",
    "aria-label": step.title
  }, React.createElement("div", {
    className: "flex items-center justify-between mb-2"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-melius-cyan"
  }, "Tutorial \xB7 ", idx + 1, "/", total), React.createElement("button", {
    onClick: onClose,
    "aria-label": "Cerrar tutorial",
    className: "text-slate-400 hover:text-slate-700 dark:hover:text-slate-200"
  }, React.createElement(Icon, {
    name: "X",
    size: 18
  }))), React.createElement("h3", {
    className: "font-black text-lg text-slate-800 dark:text-slate-100 mb-1"
  }, step.title), React.createElement("p", {
    className: "text-sm text-slate-600 dark:text-slate-300 mb-4"
  }, step.body), box?.missing && React.createElement("p", {
    className: "text-[11px] text-amber-600 dark:text-amber-400 mb-3 font-bold"
  }, "Este apartado no est\xE1 visible ahora. Salta al siguiente paso o cierra el tutorial."), React.createElement("div", {
    className: "flex items-center justify-between gap-2"
  }, React.createElement("button", {
    onClick: onClose,
    className: "text-xs font-bold uppercase tracking-widest text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
  }, "Saltar"), React.createElement("div", {
    className: "flex gap-2"
  }, idx > 0 && React.createElement("button", {
    onClick: () => setIdx(i => i - 1),
    className: "px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700"
  }, "Atr\xE1s"), React.createElement("button", {
    onClick: () => isLast ? onClose() : setIdx(i => i + 1),
    className: "px-4 py-2 rounded-xl text-xs font-bold btn-melius"
  }, isLast ? 'Terminar' : 'Siguiente')))));
};
const DEFAULT_BRANDING = {
  product_name: 'Melius Clockin',
  logo_url: null,
  primary_color: '#07d6da',
  secondary_color: '#9909fe'
};
const BrandingContext = React.createContext({
  branding: DEFAULT_BRANDING,
  tenantBranding: DEFAULT_BRANDING,
  setTenantBranding: () => {},
  refreshBranding: async () => {}
});
const useBranding = () => React.useContext(BrandingContext);
const BrandingProvider = ({
  children
}) => {
  const [tenantBranding, setTenantBranding] = useState(DEFAULT_BRANDING);
  const refreshBranding = useCallback(async () => {
    try {
      const d = await apiFetch('branding');
      if (d?.branding) setTenantBranding({
        ...DEFAULT_BRANDING,
        ...d.branding
      });
    } catch (_) {}
  }, []);
  useEffect(() => {
    refreshBranding();
  }, [refreshBranding]);
  return React.createElement(BrandingContext.Provider, {
    value: {
      branding: tenantBranding,
      tenantBranding,
      setTenantBranding,
      refreshBranding
    }
  }, children);
};
const resolveEffectiveBranding = (tenant, currentUser, companyOverride) => {
  const co = companyOverride || {};
  const u = currentUser || {};
  return {
    product_name: tenant.product_name || DEFAULT_BRANDING.product_name,
    logo_url: co.branding_logo_url || u.brand_logo_url || tenant.logo_url || null,
    primary_color: co.branding_primary || u.brand_primary || tenant.primary_color || DEFAULT_BRANDING.primary_color,
    secondary_color: co.branding_secondary || u.brand_secondary || tenant.secondary_color || DEFAULT_BRANDING.secondary_color
  };
};
const NAV_STORAGE_KEY = 'melius.nav.v1';
const RESUMABLE_VIEWS = new Set(['dashboard', 'admin-panel']);
function readNavState() {
  try {
    const raw = localStorage.getItem(NAV_STORAGE_KEY);
    if (!raw) return {};
    const obj = JSON.parse(raw);
    return obj && typeof obj === 'object' ? obj : {};
  } catch (_) {
    return {};
  }
}
function writeNavState(patch) {
  try {
    const next = {
      ...readNavState(),
      ...patch
    };
    localStorage.setItem(NAV_STORAGE_KEY, JSON.stringify(next));
  } catch (_) {}
}
const App = () => {
  const {
    push: toast
  } = useToast();
  const [view, setView] = useState('loading');
  const [resetToken, setResetToken] = useState(null);
  const [currentUser, setCurrentUser] = useState(null);
  const [theme, setTheme] = useState(() => document.documentElement.classList.contains('dark') ? 'dark' : 'light');
  const [companies, setCompanies] = useState([]);
  const [submitting, setSubmitting] = useState(false);
  const [tourKey, setTourKey] = useState(null);
  const [termsViewerOpen, setTermsViewerOpen] = useState(false);
  const startTour = useCallback(key => setTourKey(key), []);
  const closeTour = useCallback(() => {
    if (tourKey) markTourSeen(tourKey);
    setTourKey(null);
  }, [tourKey]);
  useEffect(() => {
    if (RESUMABLE_VIEWS.has(view)) writeNavState({
      view
    });else if (view === 'login') writeNavState({
      view: null
    });
  }, [view]);
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
      try {
        localStorage.setItem('melius.theme', next);
      } catch (_) {}
      return next;
    });
  }, []);
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
            if (saved === 'admin-panel' && canResumeAdmin) setView('admin-panel');else if (saved === 'dashboard') setView('dashboard');else setView('dashboard');
          }
        } catch (e) {
          if (e._auth) setView('login');else {
            toast('error', e.message);
            setView('login');
          }
        }
      } catch (e) {
        toast('error', e.message || 'Error al iniciar.');
        setView('login');
      }
    })();
  }, []);
  const loadCompanies = useCallback(async () => {
    try {
      const d = await apiFetch('companies');
      setCompanies(d.companies || []);
    } catch (e) {
      toast('error', e.message);
    }
  }, [toast]);
  const handleLogin = async e => {
    e.preventDefault();
    if (submitting) return;
    const data = new FormData(e.target);
    setSubmitting(true);
    try {
      const d = await apiPost('auth/login', {
        email: data.get('email'),
        password: data.get('password')
      });
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
    } finally {
      setSubmitting(false);
    }
  };
  const handleLogout = async () => {
    try {
      await apiFetch('auth/logout', {
        method: 'POST'
      });
    } catch (_) {}
    setCurrentUser(null);
    CSRF_TOKEN = null;
    await fetchCsrf();
    setView('login');
  };
  if (view === 'loading') return React.createElement(LoadingScreen, null);
  return React.createElement("div", {
    className: "min-h-screen flex flex-col items-center justify-center py-6 sm:py-10 px-4"
  }, view === 'login' && React.createElement(LoginCard, {
    onSubmit: handleLogin,
    submitting: submitting,
    onGoForgot: () => setView('forgot-password'),
    theme: theme,
    onToggleTheme: toggleTheme
  }), view === 'forgot-password' && React.createElement(ForgotPasswordCard, {
    onBack: () => setView('login'),
    theme: theme,
    onToggleTheme: toggleTheme
  }), view === 'reset-password' && React.createElement(ResetPasswordCard, {
    token: resetToken,
    onDone: () => {
      window.history.replaceState({}, '', window.location.pathname);
      setResetToken(null);
      setView('login');
    },
    theme: theme,
    onToggleTheme: toggleTheme
  }), view === 'accept-terms' && currentUser && React.createElement(AcceptTermsCard, {
    currentUser: currentUser,
    onAccepted: async () => {
      try {
        const me = await apiFetch('auth/me');
        CSRF_TOKEN = me.csrf_token || CSRF_TOKEN;
        setCurrentUser(me.user);
        if (me.user.must_change_password) setView('change-password');else if (me.user.terms_pending) setView('accept-terms');else setView('dashboard');
      } catch (e) {
        if (e && e._auth) setView('login');else {
          toast('error', e.message || 'No se pudo continuar.');
          setView('login');
        }
      }
    },
    onLogout: handleLogout,
    theme: theme,
    onToggleTheme: toggleTheme
  }), view === 'change-password' && currentUser && React.createElement(ChangePasswordCard, {
    currentUser: currentUser,
    onDone: async () => {
      try {
        const me = await apiFetch('auth/me');
        CSRF_TOKEN = me.csrf_token || CSRF_TOKEN;
        setCurrentUser(me.user);
        setView(me.user.terms_pending ? 'accept-terms' : 'dashboard');
      } catch (_) {
        setView('login');
      }
    },
    onLogout: handleLogout,
    theme: theme,
    onToggleTheme: toggleTheme
  }), view === 'dashboard' && currentUser && React.createElement(UserDashboard, {
    currentUser: currentUser,
    onLogout: handleLogout,
    theme: theme,
    onToggleTheme: toggleTheme,
    companies: companies,
    loadCompanies: loadCompanies,
    onGoAdmin: () => setView('admin-panel'),
    onStartTour: () => startTour('user')
  }), view === 'admin-panel' && currentUser && React.createElement(AdminPanel, {
    currentUser: currentUser,
    onLogout: handleLogout,
    theme: theme,
    onToggleTheme: toggleTheme,
    onGoDashboard: () => setView('dashboard'),
    onStartTour: () => startTour('admin')
  }), tourKey === 'user' && React.createElement(TourTooltip, {
    steps: TOUR_STEPS_USER,
    onClose: closeTour
  }), tourKey === 'admin' && React.createElement(TourTooltip, {
    steps: TOUR_STEPS_ADMIN,
    onClose: closeTour
  }), React.createElement(TermsViewerModal, {
    open: termsViewerOpen,
    onClose: () => setTermsViewerOpen(false)
  }), React.createElement("footer", {
    className: "mt-8 sm:mt-12 flex flex-col items-center gap-2 text-slate-300 dark:text-slate-600 font-bold text-[10px] uppercase tracking-[0.5em] sm:tracking-[0.8em]"
  }, React.createElement("span", null, "Melius Services \xB7 Infrastructure"), React.createElement("button", {
    onClick: () => setTermsViewerOpen(true),
    className: "text-slate-400 dark:text-slate-500 hover:text-blue-500 dark:hover:text-blue-300 transition-colors tracking-widest text-[9px] sm:text-[10px]"
  }, "Terminos y Privacidad")));
};
const TermsViewerModal = ({
  open,
  onClose
}) => {
  const {
    push: toast
  } = useToast();
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
      } finally {
        if (alive) setLoading(false);
      }
    })();
    return () => {
      alive = false;
    };
  }, [open]);
  useEffect(() => {
    if (!open) return;
    const onKey = e => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);
  if (!open) return null;
  return React.createElement("div", {
    className: "fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm",
    onClick: e => {
      if (e.target === e.currentTarget) onClose();
    }
  }, React.createElement("div", {
    className: "max-w-3xl w-full max-h-[90vh] bg-white dark:bg-slate-900 rounded-3xl shadow-2xl border border-slate-100 dark:border-slate-800 flex flex-col"
  }, React.createElement("div", {
    className: "flex items-center justify-between p-5 border-b border-slate-200 dark:border-slate-700"
  }, React.createElement("h2", {
    className: "text-xl sm:text-2xl font-black text-slate-800 dark:text-slate-100 font-display"
  }, terms?.title || 'Terminos y Privacidad', terms?.version && React.createElement("span", {
    className: "ml-2 text-xs font-bold text-slate-400 tracking-widest uppercase"
  }, "v", terms.version)), React.createElement("button", {
    onClick: onClose,
    "aria-label": "Cerrar",
    className: "w-9 h-9 rounded-full bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 flex items-center justify-center"
  }, React.createElement(Icon, {
    name: "X",
    size: 18
  }))), React.createElement("div", {
    className: "flex-1 overflow-y-auto p-6"
  }, loading ? React.createElement("p", {
    className: "text-slate-500"
  }, "Cargando...") : !terms ? React.createElement("p", {
    className: "text-amber-600 dark:text-amber-400"
  }, "No hay terminos publicados.") : React.createElement(React.Fragment, null, React.createElement("div", {
    className: "prose prose-sm dark:prose-invert max-w-none",
    dangerouslySetInnerHTML: {
      __html: terms.body_html
    }
  }), React.createElement("hr", {
    className: "my-6 border-slate-200 dark:border-slate-700"
  }), React.createElement("div", {
    className: "prose prose-sm dark:prose-invert max-w-none",
    dangerouslySetInnerHTML: {
      __html: terms.privacy_html
    }
  }))), React.createElement("div", {
    className: "p-4 border-t border-slate-200 dark:border-slate-700 flex justify-end"
  }, React.createElement("button", {
    onClick: onClose,
    className: "btn-melius px-5 py-2.5 rounded-xl text-white font-bold"
  }, "Cerrar"))));
};
const AcceptTermsCard = ({
  currentUser,
  onAccepted,
  onLogout,
  theme,
  onToggleTheme
}) => {
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
      } finally {
        setLoading(false);
      }
    })();
  }, []);
  const handleAccept = async () => {
    if (!agreed || accepting || !terms) return;
    setAccepting(true);
    try {
      await apiPost('terms/accept', {
        version: terms.version
      });
      toast('success', 'Terminos aceptados.');
      setTimeout(() => {
        try {
          onAccepted();
        } catch (_) {}
      }, 0);
    } catch (e) {
      toast('error', e.message || 'No se pudo registrar la aceptacion.');
      setAccepting(false);
    }
  };
  return React.createElement("div", {
    className: "fixed inset-0 z-40 bg-slate-50 dark:bg-slate-950 flex items-center justify-center p-2 sm:p-6 overflow-y-auto"
  }, React.createElement("div", {
    className: "max-w-3xl w-full bg-white dark:bg-slate-900 rounded-3xl shadow-2xl border border-slate-100 dark:border-slate-800 relative flex flex-col",
    style: {
      maxHeight: 'calc(100vh - 1rem)'
    }
  }, React.createElement("div", {
    className: "absolute top-4 right-4 z-10"
  }, React.createElement(ThemeToggle, {
    theme: theme,
    onToggle: onToggleTheme
  })), React.createElement("div", {
    className: "p-6 sm:p-10 pb-2 sm:pb-4 flex-shrink-0"
  }, React.createElement("h2", {
    className: "text-2xl sm:text-3xl font-black text-slate-800 dark:text-slate-100 mb-2 font-display pr-12"
  }, terms?.title || 'Terminos y Condiciones'), React.createElement("p", {
    className: "text-slate-500 dark:text-slate-400 text-sm"
  }, "Hola ", currentUser?.name?.split(' ')[0] || '', ". Antes de continuar debes leer y aceptar los siguientes terminos y el aviso de privacidad. Tu aceptacion queda registrada con fecha, IP y pais.")), loading ? React.createElement("p", {
    className: "text-slate-500 px-6 sm:px-10 py-6"
  }, "Cargando...") : !terms ? React.createElement("p", {
    className: "text-amber-600 dark:text-amber-400 px-6 sm:px-10 py-6"
  }, "No hay terminos publicados. Contacta al administrador.") : React.createElement(React.Fragment, null, React.createElement("div", {
    className: "flex-1 overflow-y-auto px-6 sm:px-10 py-4 min-h-0"
  }, React.createElement("div", {
    className: "border border-slate-200 dark:border-slate-700 rounded-xl p-4 bg-slate-50 dark:bg-slate-800/50"
  }, React.createElement("div", {
    className: "prose prose-sm dark:prose-invert max-w-none",
    dangerouslySetInnerHTML: {
      __html: terms.body_html
    }
  }), React.createElement("hr", {
    className: "my-6 border-slate-200 dark:border-slate-700"
  }), React.createElement("div", {
    className: "prose prose-sm dark:prose-invert max-w-none",
    dangerouslySetInnerHTML: {
      __html: terms.privacy_html
    }
  }))), React.createElement("div", {
    className: "flex-shrink-0 px-6 sm:px-10 py-4 sm:py-6 border-t border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 rounded-b-3xl"
  }, React.createElement("label", {
    className: "flex items-start gap-3 cursor-pointer mb-4"
  }, React.createElement("input", {
    type: "checkbox",
    checked: agreed,
    onChange: e => setAgreed(e.target.checked),
    className: "mt-1 w-6 h-6 rounded border-2 border-slate-400 dark:border-slate-500 accent-blue-600 flex-shrink-0"
  }), React.createElement("span", {
    className: "text-slate-700 dark:text-slate-200 text-sm font-semibold"
  }, "He leido y acepto los Terminos de Uso y el Aviso de Privacidad (version ", terms.version, ").")), React.createElement("div", {
    className: "flex flex-col sm:flex-row gap-3"
  }, React.createElement("button", {
    onClick: handleAccept,
    disabled: !agreed || accepting,
    className: "btn-melius px-6 py-3 rounded-xl text-white font-bold disabled:opacity-50 disabled:cursor-not-allowed flex-1 sm:flex-initial min-h-[48px]"
  }, accepting ? 'Registrando...' : 'Aceptar y continuar'), React.createElement("button", {
    onClick: onLogout,
    className: "px-6 py-3 rounded-xl border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 font-semibold hover:bg-slate-50 dark:hover:bg-slate-800 min-h-[48px]"
  }, "Cerrar sesion"))))));
};
const LoginCard = ({
  onSubmit,
  submitting,
  onGoForgot,
  theme,
  onToggleTheme
}) => React.createElement("div", {
  className: "max-w-md w-full bg-white dark:bg-slate-900 p-6 sm:p-10 md:p-12 rounded-[2rem] sm:rounded-[3rem] md:rounded-[4rem] shadow-2xl dark:shadow-black/50 border border-slate-100 dark:border-slate-800 flex flex-col items-center anim-zoom-in relative"
}, React.createElement("div", {
  className: "absolute top-4 right-4 sm:top-6 sm:right-6"
}, React.createElement(ThemeToggle, {
  theme: theme,
  onToggle: onToggleTheme
})), React.createElement("div", {
  className: "w-20 h-20 sm:w-28 sm:h-28 rounded-2xl sm:rounded-[2rem] flex items-center justify-center mb-6 sm:mb-8 ring-melius bg-white dark:bg-slate-800 p-2"
}, React.createElement("img", {
  src: "/assets/brands/melius.webp",
  alt: "Melius Services",
  className: "w-full h-full object-contain"
})), React.createElement("h1", {
  className: "text-2xl sm:text-3xl md:text-4xl font-black text-slate-800 dark:text-slate-100 mb-2 tracking-tighter text-center font-display"
}, "Clock System"), React.createElement("p", {
  className: "text-slate-400 dark:text-slate-500 font-black uppercase tracking-[0.3em] sm:tracking-[0.4em] text-[9px] sm:text-[10px] mb-8 sm:mb-12 text-center"
}, "Melius Services Portal"), React.createElement("form", {
  onSubmit: onSubmit,
  className: "w-full space-y-4 sm:space-y-5"
}, React.createElement("div", {
  className: "space-y-1"
}, React.createElement("label", {
  htmlFor: "login-email",
  className: "text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 sm:ml-5 tracking-widest block"
}, "Email Corporativo"), React.createElement("input", {
  id: "login-email",
  name: "email",
  type: "email",
  placeholder: "usuario@melius.com",
  required: true,
  autoComplete: "email",
  className: "w-full px-6 sm:px-8 py-4 sm:py-5 rounded-2xl sm:rounded-3xl border-2 border-slate-50 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 focus:bg-white dark:focus:bg-slate-900 transition-all font-medium"
})), React.createElement("div", {
  className: "space-y-1"
}, React.createElement("label", {
  htmlFor: "login-password",
  className: "text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 sm:ml-5 tracking-widest block"
}, "Contrase\xF1a"), React.createElement("input", {
  id: "login-password",
  name: "password",
  type: "password",
  required: true,
  autoComplete: "current-password",
  minLength: "1",
  className: "w-full px-6 sm:px-8 py-4 sm:py-5 rounded-2xl sm:rounded-3xl border-2 border-slate-50 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 focus:bg-white dark:focus:bg-slate-900 transition-all font-medium"
})), React.createElement("button", {
  type: "submit",
  disabled: submitting,
  className: "w-full btn-melius py-4 sm:py-5 rounded-2xl sm:rounded-3xl font-black text-lg sm:text-xl ring-melius transition-all active:scale-95 no-select disabled:opacity-60 disabled:cursor-wait flex items-center justify-center gap-3"
}, submitting && React.createElement(Icon, {
  name: "Spinner",
  size: 20
}), submitting ? 'Validando' : 'Entrar')), React.createElement("div", {
  className: "mt-8 sm:mt-12 flex flex-col items-center gap-4 sm:gap-5"
}, React.createElement("button", {
  onClick: onGoForgot,
  className: "text-blue-600 dark:text-blue-300 font-black text-xs uppercase tracking-widest hover:underline transition-all"
}, "Olvid\xE9 mi contrase\xF1a"), React.createElement("p", {
  className: "text-[10px] text-slate-400 dark:text-slate-500 font-bold text-center max-w-xs"
}, "El alta de cuentas es exclusiva del administrador. Solicita una invitaci\xF3n para acceder.")));
const PasswordCard = ({
  title,
  subtitle,
  children,
  theme,
  onToggleTheme
}) => React.createElement("div", {
  className: "max-w-md w-full bg-white dark:bg-slate-900 p-6 sm:p-10 md:p-12 rounded-[2rem] sm:rounded-[3rem] shadow-2xl dark:shadow-black/50 border border-slate-100 dark:border-slate-800 anim-fade-in relative"
}, onToggleTheme && React.createElement("div", {
  className: "absolute top-4 right-4 sm:top-6 sm:right-6"
}, React.createElement(ThemeToggle, {
  theme: theme,
  onToggle: onToggleTheme
})), React.createElement("div", {
  className: "bg-blue-600 w-14 h-14 rounded-2xl flex items-center justify-center mb-6 text-white"
}, React.createElement(Icon, {
  name: "Lock"
})), React.createElement("h2", {
  className: "text-2xl sm:text-3xl font-black text-slate-800 dark:text-slate-100 mb-2 tracking-tight"
}, title), subtitle && React.createElement("p", {
  className: "text-sm text-slate-500 dark:text-slate-400 mb-6"
}, subtitle), children);
const PasswordStrength = ({
  value
}) => {
  const checks = [{
    ok: (value || '').length >= 10,
    label: 'Mín. 10 caracteres'
  }, {
    ok: /[A-Z]/.test(value || ''),
    label: 'Mayúscula'
  }, {
    ok: /[0-9]/.test(value || ''),
    label: 'Número'
  }, {
    ok: /[^A-Za-z0-9]/.test(value || ''),
    label: 'Símbolo'
  }];
  return React.createElement("ul", {
    className: "text-[11px] text-slate-500 dark:text-slate-400 grid grid-cols-2 gap-1"
  }, checks.map((c, i) => React.createElement("li", {
    key: i,
    className: c.ok ? 'text-emerald-600 dark:text-emerald-300 font-bold' : ''
  }, c.ok ? '✓' : '·', " ", c.label)));
};
const PasswordInputs = ({
  newPwd,
  setNewPwd,
  confirmPwd,
  setConfirmPwd,
  autoComplete = 'new-password'
}) => React.createElement(React.Fragment, null, React.createElement("div", {
  className: "space-y-1"
}, React.createElement("label", {
  className: "text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 tracking-widest block"
}, "Nueva contrase\xF1a"), React.createElement("input", {
  type: "password",
  required: true,
  minLength: "10",
  maxLength: "200",
  autoComplete: autoComplete,
  value: newPwd,
  onChange: e => setNewPwd(e.target.value),
  className: "w-full px-6 py-4 rounded-2xl border-2 border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 transition-all font-medium"
})), React.createElement(PasswordStrength, {
  value: newPwd
}), React.createElement("div", {
  className: "space-y-1"
}, React.createElement("label", {
  className: "text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 tracking-widest block"
}, "Confirmar contrase\xF1a"), React.createElement("input", {
  type: "password",
  required: true,
  minLength: "10",
  maxLength: "200",
  autoComplete: autoComplete,
  value: confirmPwd,
  onChange: e => setConfirmPwd(e.target.value),
  className: `w-full px-6 py-4 rounded-2xl border-2 ${confirmPwd && confirmPwd !== newPwd ? 'border-red-400' : 'border-slate-100 dark:border-slate-700'} bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 transition-all font-medium`
})));
const ForgotPasswordCard = ({
  onBack,
  theme,
  onToggleTheme
}) => {
  const {
    push: toast
  } = useToast();
  const [email, setEmail] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState(false);
  const handle = async e => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    try {
      const d = await apiPost('auth/forgot-password', {
        email
      });
      toast('success', d.message);
      setSent(true);
    } catch (err) {
      toast('error', err.message);
    } finally {
      setSubmitting(false);
    }
  };
  return React.createElement(PasswordCard, {
    title: "Restablecer contrase\xF1a",
    subtitle: "Te enviaremos un enlace de recuperaci\xF3n si el correo est\xE1 registrado.",
    theme: theme,
    onToggleTheme: onToggleTheme
  }, sent ? React.createElement("div", {
    className: "space-y-5"
  }, React.createElement("p", {
    className: "text-sm text-emerald-600 dark:text-emerald-300 font-bold"
  }, "Revisa tu correo. El enlace expira en 72 horas."), React.createElement("button", {
    onClick: onBack,
    className: "w-full py-3 rounded-2xl font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-200"
  }, "Volver al inicio de sesi\xF3n")) : React.createElement("form", {
    onSubmit: handle,
    className: "space-y-5"
  }, React.createElement("div", {
    className: "space-y-1"
  }, React.createElement("label", {
    className: "text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 tracking-widest block"
  }, "Correo"), React.createElement("input", {
    type: "email",
    required: true,
    autoComplete: "email",
    value: email,
    onChange: e => setEmail(e.target.value),
    className: "w-full px-6 py-4 rounded-2xl border-2 border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 transition-all font-medium"
  })), React.createElement("button", {
    type: "submit",
    disabled: submitting,
    className: "w-full bg-blue-600 text-white py-4 rounded-2xl font-black hover:bg-blue-700 transition-all shadow-xl shadow-blue-200 dark:shadow-blue-900/40 disabled:opacity-60 flex items-center justify-center gap-2"
  }, submitting && React.createElement(Icon, {
    name: "Spinner",
    size: 18
  }), "Enviar enlace"), React.createElement("button", {
    type: "button",
    onClick: onBack,
    className: "w-full py-2 text-slate-400 font-bold text-xs uppercase tracking-widest"
  }, "Cancelar")));
};
const ResetPasswordCard = ({
  token,
  onDone,
  theme,
  onToggleTheme
}) => {
  const {
    push: toast
  } = useToast();
  const [newPwd, setNewPwd] = useState('');
  const [confirmPwd, setConfirmPwd] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const handle = async e => {
    e.preventDefault();
    if (submitting) return;
    if (newPwd !== confirmPwd) {
      toast('error', 'Las contraseñas no coinciden.');
      return;
    }
    setSubmitting(true);
    try {
      await apiPost('auth/reset-password', {
        token,
        new_password: newPwd,
        confirm_password: confirmPwd
      });
      toast('success', 'Contraseña restablecida. Inicia sesión.');
      onDone();
    } catch (err) {
      toast('error', err.message);
    } finally {
      setSubmitting(false);
    }
  };
  return React.createElement(PasswordCard, {
    title: "Nueva contrase\xF1a",
    subtitle: "Define una contrase\xF1a segura para tu cuenta.",
    theme: theme,
    onToggleTheme: onToggleTheme
  }, React.createElement("form", {
    onSubmit: handle,
    className: "space-y-5"
  }, React.createElement(PasswordInputs, {
    newPwd: newPwd,
    setNewPwd: setNewPwd,
    confirmPwd: confirmPwd,
    setConfirmPwd: setConfirmPwd
  }), React.createElement("button", {
    type: "submit",
    disabled: submitting || newPwd !== confirmPwd,
    className: "w-full bg-blue-600 text-white py-4 rounded-2xl font-black hover:bg-blue-700 transition-all shadow-xl shadow-blue-200 dark:shadow-blue-900/40 disabled:opacity-60 flex items-center justify-center gap-2"
  }, submitting && React.createElement(Icon, {
    name: "Spinner",
    size: 18
  }), "Restablecer")));
};
const ChangePasswordCard = ({
  currentUser,
  onDone,
  onLogout,
  theme,
  onToggleTheme
}) => {
  const {
    push: toast
  } = useToast();
  const [currentPwd, setCurrentPwd] = useState('');
  const [newPwd, setNewPwd] = useState('');
  const [confirmPwd, setConfirmPwd] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const needsCompany = currentUser.role === 'admin' && !currentUser.company_id;
  const [companies, setCompanies] = useState([]);
  const [companyId, setCompanyId] = useState('');
  const [loadingCompanies, setLoadingCompanies] = useState(false);
  useEffect(() => {
    if (!needsCompany) return;
    setLoadingCompanies(true);
    apiFetch('companies').then(d => setCompanies(d.companies || [])).catch(e => toast('error', e.message)).finally(() => setLoadingCompanies(false));
  }, [needsCompany]);
  const handle = async e => {
    e.preventDefault();
    if (submitting) return;
    if (newPwd !== confirmPwd) {
      toast('error', 'Las contraseñas no coinciden.');
      return;
    }
    if (needsCompany && !companyId) {
      toast('error', 'Selecciona la empresa a la que perteneces.');
      return;
    }
    setSubmitting(true);
    try {
      const payload = {
        current_password: currentPwd,
        new_password: newPwd,
        confirm_password: confirmPwd
      };
      if (needsCompany) payload.company_id = parseInt(companyId, 10);
      await apiPost('auth/change-password', payload);
      toast('success', 'Contraseña actualizada.');
      onDone();
    } catch (err) {
      toast('error', err.message);
    } finally {
      setSubmitting(false);
    }
  };
  return React.createElement(PasswordCard, {
    title: "Debes cambiar tu contrase\xF1a",
    subtitle: `Hola ${currentUser.name}, por seguridad define una contraseña nueva antes de continuar.`,
    theme: theme,
    onToggleTheme: onToggleTheme
  }, React.createElement("form", {
    onSubmit: handle,
    className: "space-y-5"
  }, React.createElement("div", {
    className: "space-y-1"
  }, React.createElement("label", {
    className: "text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 tracking-widest block"
  }, "Contrase\xF1a actual (temporal)"), React.createElement("input", {
    type: "password",
    required: true,
    minLength: "1",
    maxLength: "200",
    autoComplete: "current-password",
    value: currentPwd,
    onChange: e => setCurrentPwd(e.target.value),
    className: "w-full px-6 py-4 rounded-2xl border-2 border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 transition-all font-medium"
  })), React.createElement(PasswordInputs, {
    newPwd: newPwd,
    setNewPwd: setNewPwd,
    confirmPwd: confirmPwd,
    setConfirmPwd: setConfirmPwd
  }), needsCompany && React.createElement("div", {
    className: "space-y-1"
  }, React.createElement("label", {
    className: "text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-4 tracking-widest block"
  }, "Empresa a la que perteneces"), React.createElement(Select, {
    required: true,
    value: companyId,
    onChange: e => setCompanyId(e.target.value),
    disabled: loadingCompanies,
    size: "lg"
  }, React.createElement("option", {
    value: "",
    disabled: true
  }, loadingCompanies ? 'Cargando empresas...' : 'Selecciona una empresa'), companies.map(c => React.createElement("option", {
    key: c.id,
    value: c.id
  }, c.name))), React.createElement("p", {
    className: "text-[11px] text-slate-500 ml-4 mt-1"
  }, "Como administrador necesitas tener una empresa asignada para gestionar a tu equipo.")), React.createElement("button", {
    type: "submit",
    disabled: submitting || newPwd !== confirmPwd || needsCompany && !companyId,
    className: "w-full bg-blue-600 text-white py-4 rounded-2xl font-black hover:bg-blue-700 transition-all shadow-xl shadow-blue-200 dark:shadow-blue-900/40 disabled:opacity-60 flex items-center justify-center gap-2"
  }, submitting && React.createElement(Icon, {
    name: "Spinner",
    size: 18
  }), "Guardar nueva contrase\xF1a"), React.createElement("button", {
    type: "button",
    onClick: onLogout,
    className: "w-full py-2 text-slate-400 dark:text-slate-500 font-bold text-xs uppercase tracking-widest"
  }, "Cerrar sesi\xF3n")));
};
const UserDashboard = ({
  currentUser,
  onLogout,
  theme,
  onToggleTheme,
  companies,
  loadCompanies,
  onGoAdmin,
  onStartTour
}) => {
  const canSwitchToAdmin = currentUser.role === 'admin' || currentUser.role === 'super_admin';
  const {
    push: toast
  } = useToast();
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
    const onClickOutside = e => {
      if (userMenuRef.current && !userMenuRef.current.contains(e.target)) setUserMenuOpen(false);
    };
    const onKey = e => {
      if (e.key === 'Escape') setUserMenuOpen(false);
    };
    document.addEventListener('mousedown', onClickOutside);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClickOutside);
      document.removeEventListener('keydown', onKey);
    };
  }, [userMenuOpen]);
  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [today, mine] = await Promise.all([apiFetch('records/today'), apiFetch('records/mine?limit=5')]);
      setTodayRecord(today.record);
      setLogs(mine.records);
    } catch (e) {
      setError(e.message || 'Error al cargar datos.');
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    refresh();
  }, [refresh]);
  const clientTimezone = () => {
    try {
      return Intl.DateTimeFormat().resolvedOptions().timeZone || null;
    } catch (_) {
      return null;
    }
  };
  const humanInteractionRef = useRef(false);
  useEffect(() => {
    const mark = () => {
      humanInteractionRef.current = true;
    };
    window.addEventListener('mousemove', mark, {
      once: true,
      passive: true
    });
    window.addEventListener('touchstart', mark, {
      once: true,
      passive: true
    });
    window.addEventListener('keydown', mark, {
      once: true
    });
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
        ...extraBody
      };
      const d = await apiFetch('records/clockin', {
        method: 'POST',
        body
      });
      if (d.decision_required) {
        setPendingDecision({
          priorRecord: d.prior_record,
          rule: d.rule
        });
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
    } finally {
      setSubmitting(false);
    }
  };
  const handleClockOut = async () => {
    if (submitting) return;
    setSubmitting(true);
    try {
      const d = await apiFetch('records/clockout', {
        method: 'POST',
        body: {
          client_timezone: clientTimezone(),
          human_interaction: humanInteractionRef.current,
          hp_field: ''
        }
      });
      setTodayRecord(d.record);
      toast('success', 'Salida registrada.');
      refresh();
    } catch (e) {
      toast('error', e.message);
    } finally {
      setSubmitting(false);
    }
  };
  const userCompany = useMemo(() => {
    if (currentUser.company_name) return {
      id: currentUser.company_id,
      name: currentUser.company_name
    };
    return companies.find(c => c.id === currentUser.company_id);
  }, [companies, currentUser]);
  return React.createElement("div", {
    className: "max-w-4xl w-full flex flex-col gap-6 sm:gap-8 anim-fade-in"
  }, React.createElement("div", {
    "data-tour": "header-user",
    className: "flex flex-col md:flex-row justify-between items-center bg-white dark:bg-slate-900 p-6 sm:p-8 rounded-[2rem] sm:rounded-[2.5rem] shadow-xl dark:shadow-black/40 border border-slate-100 dark:border-slate-800 gap-4 md:gap-6"
  }, React.createElement("div", {
    className: "flex items-center gap-4 sm:gap-5 w-full md:w-auto"
  }, currentUser.brand_logo_url ? React.createElement("div", {
    className: "w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-white dark:bg-slate-800 ring-melius flex items-center justify-center p-2 shrink-0",
    title: currentUser.brand_name || ''
  }, React.createElement("img", {
    src: currentUser.brand_logo_url,
    alt: currentUser.brand_name || 'Marca',
    className: "w-full h-full object-contain"
  })) : React.createElement("div", {
    className: "w-14 h-14 sm:w-16 sm:h-16 btn-melius rounded-2xl flex items-center justify-center text-white text-2xl sm:text-3xl font-black ring-melius shrink-0 font-display"
  }, currentUser.name[0]?.toUpperCase()), React.createElement("div", {
    className: "min-w-0"
  }, React.createElement("h2", {
    className: "text-xl sm:text-2xl font-black text-slate-800 dark:text-slate-100 tracking-tight truncate"
  }, currentUser.name), React.createElement("div", {
    className: "flex items-center gap-2 sm:gap-3 mt-1 flex-wrap"
  }, React.createElement("span", {
    className: "text-[11px] sm:text-xs font-bold px-2.5 sm:px-3 py-1 bg-cyan-50 dark:bg-cyan-900/30 text-melius-cyan rounded-full border border-cyan-100 dark:border-cyan-900/40"
  }, userCompany?.name || 'Sin empresa'), React.createElement("button", {
    onClick: () => {
      if (!companies.length) loadCompanies();
      setShowChangeModal(true);
    },
    className: "text-[9px] sm:text-[10px] font-black text-slate-400 dark:text-slate-500 hover:text-blue-500 dark:hover:text-blue-300 uppercase tracking-widest underline transition-all"
  }, "\xBFEmpresa incorrecta?")))), React.createElement("div", {
    className: "flex items-center gap-2 sm:gap-3 w-full md:w-auto justify-end"
  }, canSwitchToAdmin && onGoAdmin && React.createElement("button", {
    onClick: onGoAdmin,
    title: "Cambiar a vista de administraci\xF3n",
    className: "px-4 sm:px-5 py-2.5 sm:py-3 btn-melius rounded-2xl font-bold text-sm transition-all flex items-center gap-2"
  }, React.createElement(Icon, {
    name: "ShieldCheck",
    size: 16
  }), React.createElement("span", {
    className: "hidden sm:inline"
  }, "Admin Console"), React.createElement("span", {
    className: "sm:hidden"
  }, "Admin")), React.createElement("div", {
    "data-tour": "user-menu",
    className: "relative",
    ref: userMenuRef
  }, React.createElement("button", {
    onClick: () => setUserMenuOpen(o => !o),
    "aria-haspopup": "menu",
    "aria-expanded": userMenuOpen,
    "aria-label": "Abrir men\xFA de usuario",
    className: "flex items-center gap-2 pl-1.5 pr-2.5 sm:pr-3 py-1.5 rounded-full bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 active:scale-95 transition-all border border-slate-200 dark:border-slate-700 shadow-sm"
  }, React.createElement("div", {
    className: "w-8 h-8 rounded-full bg-gradient-to-br from-melius-cyan to-melius-violet flex items-center justify-center text-white font-black text-sm"
  }, (currentUser.name || '?').charAt(0).toUpperCase()), React.createElement("span", {
    className: "hidden sm:inline text-sm font-bold text-slate-600 dark:text-slate-200 max-w-[100px] truncate"
  }, currentUser.name?.split(' ')[0] || 'Usuario'), React.createElement(Icon, {
    name: "ChevronDown",
    size: 16,
    className: `text-slate-500 dark:text-slate-300 transition-transform ${userMenuOpen ? 'rotate-180' : ''}`
  })), userMenuOpen && React.createElement("div", {
    role: "menu",
    className: "absolute right-0 mt-2 w-64 max-w-[calc(100vw-1rem)] bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 py-2 z-30 anim-fade-in"
  }, React.createElement("div", {
    className: "px-4 py-3 border-b border-slate-100 dark:border-slate-800"
  }, React.createElement("div", {
    className: "text-[10px] uppercase tracking-widest text-slate-400 font-black"
  }, "Sesi\xF3n activa"), React.createElement("div", {
    className: "text-sm font-bold text-slate-700 dark:text-slate-200 truncate mt-0.5"
  }, currentUser.name), React.createElement("div", {
    className: "text-[11px] text-slate-400 truncate",
    title: currentUser.email || ''
  }, currentUser.email || '')), React.createElement("button", {
    onClick: () => {
      setUserMenuOpen(false);
      onToggleTheme();
    },
    className: "w-full text-left px-4 py-2.5 text-sm font-bold flex items-center gap-3 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800"
  }, React.createElement(Icon, {
    name: theme === 'dark' ? 'Sun' : 'Moon',
    size: 18
  }), theme === 'dark' ? 'Tema claro' : 'Tema oscuro'), onStartTour && React.createElement("button", {
    onClick: () => {
      setUserMenuOpen(false);
      onStartTour();
    },
    className: "w-full text-left px-4 py-2.5 text-sm font-bold flex items-center gap-3 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800"
  }, React.createElement(Icon, {
    name: "ShieldCheck",
    size: 18
  }), "Ver tutorial"), React.createElement("div", {
    className: "border-t border-slate-100 dark:border-slate-800 mt-1 pt-1"
  }, React.createElement("button", {
    onClick: () => {
      setUserMenuOpen(false);
      onLogout();
    },
    className: "w-full text-left px-4 py-2.5 text-sm font-bold flex items-center gap-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
  }, React.createElement(Icon, {
    name: "LogOut",
    size: 18
  }), "Cerrar sesi\xF3n")))))), loading && React.createElement(LoadingScreen, null), error && !loading && React.createElement(ErrorState, {
    message: error,
    onRetry: refresh
  }), !loading && !error && React.createElement(React.Fragment, null, React.createElement("div", {
    className: "grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 md:gap-8"
  }, React.createElement("button", {
    "data-tour": "btn-clockin",
    onClick: () => handleClockIn(),
    disabled: !!todayRecord || submitting,
    "aria-label": "Marcar entrada",
    className: `p-6 sm:p-8 md:p-10 rounded-[2rem] sm:rounded-[3rem] border-4 transition-all flex flex-col items-center gap-4 sm:gap-5 no-select ${todayRecord ? 'bg-slate-50 dark:bg-slate-900/50 border-slate-100 dark:border-slate-800 opacity-60 grayscale cursor-not-allowed' : 'bg-white dark:bg-slate-900 border-blue-50 dark:border-blue-900/40 hover:border-blue-400 dark:hover:border-blue-500 shadow-2xl shadow-blue-900/5 dark:shadow-blue-950/40 active:scale-95'}`
  }, React.createElement("div", {
    className: `p-4 sm:p-6 rounded-2xl sm:rounded-3xl ${todayRecord ? 'bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-600' : 'bg-blue-600 text-white shadow-xl shadow-blue-200 dark:shadow-blue-900/40'}`
  }, React.createElement(Icon, {
    name: "LogIn",
    size: 28,
    className: "sm:hidden"
  }), React.createElement(Icon, {
    name: "LogIn",
    size: 36,
    className: "hidden sm:block"
  })), React.createElement("div", {
    className: "text-center"
  }, React.createElement("span", {
    className: "block font-black text-xl sm:text-2xl uppercase tracking-tighter text-slate-800 dark:text-slate-100"
  }, "Entrada"), todayRecord && React.createElement("span", {
    className: "text-blue-600 dark:text-blue-300 font-black font-mono text-xs sm:text-sm uppercase bg-blue-50 dark:bg-blue-900/40 px-3 py-1 rounded-lg mt-2 inline-block"
  }, "Registrada: ", todayRecord.entry_time))), React.createElement("button", {
    "data-tour": "btn-clockout",
    onClick: handleClockOut,
    disabled: !todayRecord || !!todayRecord?.exit_time || submitting,
    "aria-label": "Marcar salida",
    className: `p-6 sm:p-8 md:p-10 rounded-[2rem] sm:rounded-[3rem] border-4 transition-all flex flex-col items-center gap-4 sm:gap-5 no-select ${!todayRecord || todayRecord?.exit_time ? 'bg-slate-50 dark:bg-slate-900/50 border-slate-100 dark:border-slate-800 opacity-60 grayscale cursor-not-allowed' : 'bg-white dark:bg-slate-900 border-orange-50 dark:border-orange-900/40 hover:border-orange-400 dark:hover:border-orange-500 shadow-2xl shadow-orange-900/5 dark:shadow-orange-950/40 active:scale-95'}`
  }, React.createElement("div", {
    className: `p-4 sm:p-6 rounded-2xl sm:rounded-3xl ${!todayRecord || todayRecord?.exit_time ? 'bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-600' : 'bg-orange-600 text-white shadow-xl shadow-orange-200 dark:shadow-orange-900/40'}`
  }, React.createElement(Icon, {
    name: "LogOut",
    size: 28,
    className: "sm:hidden"
  }), React.createElement(Icon, {
    name: "LogOut",
    size: 36,
    className: "hidden sm:block"
  })), React.createElement("div", {
    className: "text-center"
  }, React.createElement("span", {
    className: "block font-black text-xl sm:text-2xl uppercase tracking-tighter text-slate-800 dark:text-slate-100"
  }, "Salida"), todayRecord?.exit_time && React.createElement("span", {
    className: "text-orange-600 dark:text-orange-300 font-black font-mono text-xs sm:text-sm uppercase bg-orange-50 dark:bg-orange-900/40 px-3 py-1 rounded-lg mt-2 inline-block"
  }, "Registrada: ", todayRecord.exit_time), !todayRecord && React.createElement("span", {
    className: "text-slate-300 dark:text-slate-600 font-bold text-[10px] uppercase block mt-2"
  }, "Pendiente de entrada")))), React.createElement("div", {
    "data-tour": "btn-vacation",
    className: "bg-white dark:bg-slate-900 p-5 sm:p-7 md:p-8 rounded-[2rem] sm:rounded-[2.5rem] shadow-sm border border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4"
  }, React.createElement("div", {
    className: "flex items-center gap-3 sm:gap-4"
  }, React.createElement("div", {
    className: "bg-emerald-100 dark:bg-emerald-900/40 p-3 rounded-2xl text-emerald-600 dark:text-emerald-300 shrink-0"
  }, React.createElement(Icon, {
    name: "CalendarDays"
  })), React.createElement("div", null, React.createElement("h3", {
    className: "font-black text-base sm:text-lg text-slate-800 dark:text-slate-100"
  }, "Vacaciones"), React.createElement("p", {
    className: "text-xs text-slate-500 dark:text-slate-400"
  }, "Solicita un rango de d\xEDas \xB7 sujeto a aprobaci\xF3n"))), React.createElement("button", {
    onClick: () => setShowVacationModal(true),
    className: "px-5 sm:px-6 py-3 rounded-2xl bg-emerald-500 text-white font-bold hover:bg-emerald-600 transition-all shadow-lg shadow-emerald-200 dark:shadow-emerald-900/30 w-full sm:w-auto min-h-[44px]"
  }, "Solicitar vacaciones")), React.createElement("div", {
    "data-tour": "btn-history",
    className: "bg-white dark:bg-slate-900 p-5 sm:p-7 md:p-8 rounded-[2rem] sm:rounded-[2.5rem] shadow-sm border border-slate-100 dark:border-slate-800"
  }, React.createElement("h3", {
    className: "font-black text-slate-800 dark:text-slate-100 mb-6 sm:mb-8 flex items-center gap-3 uppercase tracking-widest text-xs"
  }, React.createElement(Icon, {
    name: "History",
    className: "text-blue-500 dark:text-blue-300 w-4 h-4"
  }), " Mis Jornadas Laborales"), React.createElement("div", {
    className: "space-y-3 sm:space-y-4"
  }, logs.map(log => React.createElement("div", {
    key: log.id,
    className: "flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 p-4 sm:p-6 bg-slate-50/50 dark:bg-slate-800/40 rounded-2xl sm:rounded-3xl border border-slate-100 dark:border-slate-800 hover:bg-white dark:hover:bg-slate-800 hover:shadow-md transition-all"
  }, React.createElement("div", {
    className: "w-full"
  }, React.createElement("p", {
    className: "text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-2"
  }, log.work_date, log.closed_reason === 'forgotten' && ' · cerrado por olvido'), React.createElement("div", {
    className: "flex items-center gap-3 sm:gap-4 flex-wrap"
  }, React.createElement("div", {
    className: "bg-white dark:bg-slate-900 px-3 sm:px-4 py-2 rounded-xl shadow-sm border border-slate-100 dark:border-slate-800"
  }, React.createElement("p", {
    className: "text-[8px] font-black text-slate-400 dark:text-slate-500 uppercase mb-1"
  }, "Entrada"), React.createElement("p", {
    className: "font-mono font-black text-lg sm:text-xl text-blue-600 dark:text-blue-400 tracking-tight"
  }, log.entry_time)), React.createElement("div", {
    className: "bg-white dark:bg-slate-900 px-3 sm:px-4 py-2 rounded-xl shadow-sm border border-slate-100 dark:border-slate-800"
  }, React.createElement("p", {
    className: "text-[8px] font-black text-slate-400 dark:text-slate-500 uppercase mb-1"
  }, "Salida"), React.createElement("p", {
    className: `font-mono font-black text-lg sm:text-xl tracking-tight ${log.exit_time ? 'text-orange-600 dark:text-orange-400' : 'text-slate-400 dark:text-slate-500'}`
  }, log.exit_time || '--:--')))), React.createElement("div", {
    className: `w-9 h-9 sm:w-10 sm:h-10 rounded-full flex items-center justify-center shrink-0 ${log.exit_time ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-300 dark:text-slate-600'}`
  }, React.createElement(Icon, {
    name: "Check",
    size: 18
  })))), logs.length === 0 && React.createElement(EmptyState, {
    message: "A\xFAn no tienes registros de asistencia"
  })))), React.createElement(Modal, {
    open: showChangeModal,
    onClose: () => setShowChangeModal(false),
    title: "Cambio de empresa"
  }, React.createElement("h3", {
    className: "text-xl sm:text-2xl font-black mb-3 text-slate-800 dark:text-slate-100 tracking-tight"
  }, "Cambio de empresa"), React.createElement("p", {
    className: "text-slate-500 dark:text-slate-400 text-sm mb-6 sm:mb-8 leading-relaxed"
  }, "Selecciona la empresa correcta. La solicitud ser\xE1 revisada por el administrador."), React.createElement("div", {
    className: "space-y-3 mb-6 sm:mb-8"
  }, companies.filter(c => c.id !== currentUser.company_id).map(c => React.createElement("button", {
    key: c.id,
    onClick: async () => {
      try {
        await apiFetch('records/change-company', {
          method: 'POST',
          body: {
            new_company_id: c.id
          }
        });
        toast('success', 'Solicitud enviada.');
        setShowChangeModal(false);
      } catch (e) {
        toast('error', e.message);
      }
    },
    className: "w-full text-left p-4 sm:p-5 rounded-2xl border-2 border-transparent bg-slate-50 dark:bg-slate-800 hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-all font-bold flex justify-between items-center group"
  }, React.createElement("span", {
    className: "text-slate-700 dark:text-slate-200"
  }, c.name), React.createElement(Icon, {
    name: "ArrowLeftRight",
    className: "w-4 h-4 text-slate-300 dark:text-slate-600 group-hover:text-blue-500"
  }))), companies.filter(c => c.id !== currentUser.company_id).length === 0 && React.createElement(EmptyState, {
    message: "No hay otras empresas disponibles."
  })), React.createElement("button", {
    onClick: () => setShowChangeModal(false),
    className: "w-full py-3 text-slate-400 dark:text-slate-500 font-bold hover:text-slate-600 dark:hover:text-slate-300 uppercase tracking-widest text-[10px]"
  }, "Cancelar")), React.createElement(Modal, {
    open: showVacationModal,
    onClose: () => setShowVacationModal(false),
    title: "Solicitar vacaciones",
    maxWidth: "max-w-lg"
  }, React.createElement(VacationForm, {
    onSubmit: async (start_date, end_date, reason) => {
      try {
        const r = await apiFetch('vacations/request', {
          method: 'POST',
          body: {
            start_date,
            end_date,
            reason
          }
        });
        toast('success', `Solicitud enviada (${r.days} días).`);
        setShowVacationModal(false);
      } catch (e) {
        toast('error', e.message);
      }
    },
    onCancel: () => setShowVacationModal(false)
  })), React.createElement(Modal, {
    open: !!pendingDecision,
    onClose: () => {},
    title: "Jornada anterior sin cerrar",
    dismissible: false
  }, React.createElement("div", {
    className: "flex items-center gap-3 mb-5"
  }, React.createElement("div", {
    className: "bg-amber-100 dark:bg-amber-900/40 p-3 rounded-2xl text-amber-600 dark:text-amber-300"
  }, React.createElement(Icon, {
    name: "AlertTriangle"
  })), React.createElement("h3", {
    className: "text-lg sm:text-xl font-black text-slate-800 dark:text-slate-100"
  }, "Jornada anterior sin cerrar")), React.createElement("p", {
    className: "text-slate-500 dark:text-slate-400 text-sm mb-6 leading-relaxed"
  }, "Tu \xFAltima jornada (", pendingDecision?.priorRecord?.work_date, ") no tiene salida. Est\xE1s marcando entrada antes de las ", String(pendingDecision?.rule?.grace_hour_am || 6).padStart(2, '0'), ":00."), React.createElement("div", {
    className: "grid grid-cols-1 gap-3"
  }, React.createElement("button", {
    onClick: () => handleClockIn({
      declare_overtime: false
    }),
    className: "text-left p-5 rounded-2xl border-2 border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:border-slate-400 transition-all"
  }, React.createElement("p", {
    className: "font-black text-slate-700 dark:text-slate-200"
  }, "Cerrar jornada anterior y continuar"), React.createElement("p", {
    className: "text-xs text-slate-500 dark:text-slate-400 mt-1"
  }, "Cerramos la jornada anterior a las 18:00 e iniciamos la de hoy.")))));
};
const VacationForm = ({
  onSubmit,
  onCancel
}) => {
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
    } catch {
      return 0;
    }
  })();
  const handle = async e => {
    e.preventDefault();
    if (days <= 0) return;
    setSubmitting(true);
    try {
      await onSubmit(start, end, reason.trim());
    } finally {
      setSubmitting(false);
    }
  };
  return React.createElement("form", {
    onSubmit: handle,
    className: "space-y-5"
  }, React.createElement("div", null, React.createElement("h3", {
    className: "text-xl sm:text-2xl font-black text-slate-800 dark:text-slate-100 tracking-tight"
  }, "Solicitud de vacaciones"), React.createElement("p", {
    className: "text-sm text-slate-500 dark:text-slate-400 mt-2"
  }, "Selecciona el rango de fechas. La solicitud queda pendiente hasta que el administrador la apruebe o la rechace.")), React.createElement("div", {
    className: "grid grid-cols-1 sm:grid-cols-2 gap-3"
  }, React.createElement("div", null, React.createElement("label", {
    htmlFor: "vac-start",
    className: "text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-2 tracking-widest block"
  }, "Inicio"), React.createElement("input", {
    id: "vac-start",
    type: "date",
    value: start,
    min: today,
    onChange: e => setStart(e.target.value),
    required: true,
    className: "w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 font-bold min-h-[44px]"
  })), React.createElement("div", null, React.createElement("label", {
    htmlFor: "vac-end",
    className: "text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-2 tracking-widest block"
  }, "Fin"), React.createElement("input", {
    id: "vac-end",
    type: "date",
    value: end,
    min: start || today,
    onChange: e => setEnd(e.target.value),
    required: true,
    className: "w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 font-bold min-h-[44px]"
  }))), React.createElement("div", {
    className: `text-sm font-bold ${days > 0 ? 'text-emerald-600 dark:text-emerald-300' : 'text-red-500'}`
  }, days > 0 ? `Total: ${days} día${days === 1 ? '' : 's'} calendario` : 'Rango inválido'), React.createElement("div", null, React.createElement("label", {
    htmlFor: "vac-reason",
    className: "text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 ml-2 tracking-widest block"
  }, "Motivo (opcional)"), React.createElement("textarea", {
    id: "vac-reason",
    value: reason,
    onChange: e => setReason(e.target.value),
    rows: "3",
    maxLength: "500",
    placeholder: "Ej. Viaje familiar, descanso m\xE9dico, etc.",
    className: "w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 font-medium resize-none"
  })), React.createElement("div", {
    className: "flex flex-col sm:flex-row gap-3"
  }, React.createElement("button", {
    type: "button",
    onClick: onCancel,
    className: "flex-1 py-3 rounded-xl font-bold text-slate-500 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors order-2 sm:order-1 min-h-[48px]"
  }, "Cancelar"), React.createElement("button", {
    type: "submit",
    disabled: submitting || days <= 0,
    className: "flex-1 py-3 rounded-xl font-black text-white bg-emerald-500 hover:bg-emerald-600 transition-colors shadow-lg shadow-emerald-200 dark:shadow-emerald-900/30 disabled:opacity-60 order-1 sm:order-2 flex items-center justify-center gap-2 min-h-[48px]"
  }, submitting && React.createElement(Icon, {
    name: "Spinner",
    size: 18
  }), "Enviar solicitud")));
};
const AdminPanel = ({
  currentUser,
  onLogout,
  theme,
  onToggleTheme,
  onGoDashboard,
  onStartTour
}) => {
  const {
    push: toast
  } = useToast();
  const {
    tenantBranding
  } = useBranding();
  const isSuper = currentUser.role === 'super_admin';
  const effective = resolveEffectiveBranding(tenantBranding, currentUser, null);
  const primaryTabs = [{
    id: 'dashboard',
    label: 'Dashboard',
    icon: 'Home'
  }, {
    id: 'records',
    label: 'Registros',
    icon: 'FileText'
  }, {
    id: 'agents',
    label: 'Consultores',
    icon: 'Users'
  }, {
    id: 'requests',
    label: 'Solicitudes',
    icon: 'Bell'
  }];
  const secondaryTabs = [...(isSuper ? [{
    id: 'brands',
    label: 'Marcas',
    icon: 'Tag'
  }] : []), {
    id: 'companies',
    label: 'Empresas',
    icon: 'Building'
  }, {
    id: 'admins',
    label: 'Admins',
    icon: 'ShieldCheck'
  }, {
    id: 'alerts',
    label: 'Alertas geo',
    icon: 'AlertTriangle'
  }, ...(isSuper ? [{
    id: 'emails',
    label: 'Plantillas',
    icon: 'FileText'
  }] : []), ...(isSuper ? [{
    id: 'tenant',
    label: 'Configuración',
    icon: 'Lock'
  }] : [])];
  const [activeTab, setActiveTab] = useState(() => {
    const allowed = new Set(['dashboard', 'records', 'agents', 'requests', ...(isSuper ? ['brands', 'tenant', 'emails'] : []), 'companies', 'admins', 'alerts']);
    const saved = readNavState().adminTab;
    return saved && allowed.has(saved) ? saved : 'dashboard';
  });
  const [requestsSub, setRequestsSub] = useState(() => {
    const saved = readNavState().requestsSub;
    return saved === 'vacations' ? 'vacations' : 'changes';
  });
  useEffect(() => {
    writeNavState({
      adminTab: activeTab
    });
  }, [activeTab]);
  useEffect(() => {
    writeNavState({
      requestsSub
    });
  }, [requestsSub]);
  const [counts, setCounts] = useState({
    changes: 0,
    vacations: 0,
    alerts: 0
  });
  const [moreOpen, setMoreOpen] = useState(false);
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const [mobileMoreOpen, setMobileMoreOpen] = useState(false);
  const moreRef = useRef(null);
  const userDesktopRef = useRef(null);
  const userMobileRef = useRef(null);
  const totalRequests = counts.changes + counts.vacations;
  useEffect(() => {
    const onDocClick = e => {
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
      const [cr, vac, al] = await Promise.all([apiFetch('admin/change-requests'), apiFetch('admin/vacations?status=pending').catch(() => ({
        requests: []
      })), apiFetch('admin/location-alerts/pending-count').catch(() => ({
        pending: 0
      }))]);
      setCounts({
        changes: (cr.requests || []).length,
        vacations: (vac.requests || []).length,
        alerts: al.pending || 0
      });
    } catch (_) {}
  }, []);
  useEffect(() => {
    refreshCounts();
  }, [refreshCounts]);
  const goTab = id => {
    setActiveTab(id);
    setMoreOpen(false);
    setMobileMoreOpen(false);
  };
  const PrimaryTabButton = ({
    tab
  }) => {
    const active = activeTab === tab.id;
    const badge = tab.id === 'requests' && totalRequests > 0;
    return React.createElement("button", {
      "data-tour": `admin-tab-${tab.id}`,
      onClick: () => goTab(tab.id),
      className: `shrink-0 px-3 lg:px-4 py-2 rounded-xl text-xs lg:text-sm font-bold flex items-center gap-2 transition-all relative ${active ? 'btn-melius shadow-md' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 hover:text-slate-700 dark:hover:text-slate-100'}`
    }, React.createElement(Icon, {
      name: tab.icon,
      size: 16
    }), React.createElement("span", null, tab.label), badge && React.createElement("span", {
      className: "ml-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-black flex items-center justify-center"
    }, totalRequests));
  };
  return React.createElement("div", {
    className: "max-w-6xl w-full flex flex-col gap-4 sm:gap-6 pb-24 md:pb-0"
  }, React.createElement("div", {
    "data-tour": "admin-header",
    className: "flex items-center justify-between bg-white dark:bg-slate-900 p-4 sm:p-6 rounded-2xl sm:rounded-3xl shadow-sm border border-slate-100 dark:border-slate-800 gap-4"
  }, React.createElement("div", {
    className: "flex items-center gap-3 sm:gap-4 min-w-0"
  }, React.createElement("div", {
    className: "w-12 h-12 rounded-2xl bg-white dark:bg-slate-800 ring-melius flex items-center justify-center p-1.5 shrink-0"
  }, React.createElement("img", {
    src: effective.logo_url || '/assets/brands/melius.webp',
    alt: effective.product_name,
    className: "w-full h-full object-contain"
  })), React.createElement("div", {
    className: "min-w-0"
  }, React.createElement("h2", {
    className: "font-black text-lg sm:text-xl text-slate-800 dark:text-slate-100 font-display truncate"
  }, effective.product_name), React.createElement("p", {
    className: "text-[10px] sm:text-xs text-slate-400 uppercase font-bold tracking-widest truncate"
  }, isSuper ? 'Super administrador' : 'Administrador', " \xB7 ", currentUser.name))), React.createElement("div", {
    className: "hidden md:flex gap-2 items-center flex-wrap justify-end"
  }, primaryTabs.map(t => React.createElement(PrimaryTabButton, {
    key: t.id,
    tab: t
  })), React.createElement("div", {
    className: "relative",
    ref: moreRef
  }, React.createElement("button", {
    onClick: () => setMoreOpen(o => !o),
    className: `shrink-0 px-3 lg:px-4 py-2 rounded-xl text-xs lg:text-sm font-bold flex items-center gap-2 transition-all ${secondaryTabs.some(s => s.id === activeTab) ? 'btn-melius shadow-md' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 hover:text-slate-700 dark:hover:text-slate-100'}`
  }, React.createElement(Icon, {
    name: "MoreHorizontal",
    size: 16
  }), React.createElement("span", null, "M\xE1s"), React.createElement(Icon, {
    name: "ChevronDown",
    size: 14
  })), moreOpen && React.createElement("div", {
    className: "absolute right-0 mt-2 w-52 bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 py-2 z-30"
  }, secondaryTabs.map(t => React.createElement("button", {
    key: t.id,
    onClick: () => goTab(t.id),
    className: `w-full text-left px-4 py-2 text-sm font-bold flex items-center gap-3 transition-all ${activeTab === t.id ? 'bg-cyan-50 dark:bg-cyan-900/20 text-melius-cyan' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800'}`
  }, React.createElement(Icon, {
    name: t.icon,
    size: 16
  }), t.label)))), React.createElement("div", {
    "data-tour": "admin-user-menu",
    className: "relative ml-1",
    ref: userDesktopRef
  }, React.createElement("button", {
    onClick: () => setUserMenuOpen(o => !o),
    className: "shrink-0 pl-2 pr-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs lg:text-sm font-bold flex items-center gap-2 transition-all"
  }, React.createElement("div", {
    className: "w-7 h-7 rounded-full bg-gradient-to-br from-melius-cyan to-melius-violet flex items-center justify-center text-white font-black text-xs"
  }, (currentUser.name || '?').charAt(0).toUpperCase()), React.createElement("span", {
    className: "max-w-[110px] truncate text-slate-600 dark:text-slate-200"
  }, currentUser.name?.split(' ')[0] || 'Usuario'), React.createElement(Icon, {
    name: "ChevronDown",
    size: 14,
    className: "text-slate-400"
  })), userMenuOpen && React.createElement("div", {
    className: "absolute right-0 mt-2 w-56 bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 py-2 z-30"
  }, React.createElement("div", {
    className: "px-4 py-2 border-b border-slate-100 dark:border-slate-800"
  }, React.createElement("div", {
    className: "text-xs text-slate-400"
  }, "Sesi\xF3n activa"), React.createElement("div", {
    className: "text-sm font-bold text-slate-700 dark:text-slate-200 truncate"
  }, currentUser.email || currentUser.name)), onGoDashboard && React.createElement("button", {
    onClick: () => {
      setUserMenuOpen(false);
      onGoDashboard();
    },
    className: "w-full text-left px-4 py-2 text-sm font-bold flex items-center gap-3 text-melius-cyan hover:bg-cyan-50 dark:hover:bg-cyan-900/20"
  }, React.createElement(Icon, {
    name: "Clock",
    size: 16
  }), "Mi jornada"), React.createElement("button", {
    onClick: () => {
      setUserMenuOpen(false);
      onToggleTheme();
    },
    className: "w-full text-left px-4 py-2 text-sm font-bold flex items-center gap-3 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800"
  }, React.createElement(Icon, {
    name: theme === 'dark' ? 'Sun' : 'Moon',
    size: 16
  }), theme === 'dark' ? 'Tema claro' : 'Tema oscuro'), onStartTour && React.createElement("button", {
    onClick: () => {
      setUserMenuOpen(false);
      onStartTour();
    },
    className: "w-full text-left px-4 py-2 text-sm font-bold flex items-center gap-3 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800"
  }, React.createElement(Icon, {
    name: "ShieldCheck",
    size: 16
  }), "Ver tutorial"), React.createElement("div", {
    className: "border-t border-slate-100 dark:border-slate-800 mt-1 pt-1"
  }, React.createElement("button", {
    onClick: () => {
      setUserMenuOpen(false);
      onLogout();
    },
    className: "w-full text-left px-4 py-2 text-sm font-bold flex items-center gap-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
  }, React.createElement(Icon, {
    name: "LogOut",
    size: 16
  }), "Cerrar sesi\xF3n"))))), React.createElement("div", {
    className: "md:hidden relative shrink-0",
    ref: userMobileRef
  }, React.createElement("button", {
    onClick: () => setUserMenuOpen(o => !o),
    "aria-haspopup": "menu",
    "aria-expanded": userMenuOpen,
    "aria-label": "Abrir men\xFA de usuario",
    className: "flex items-center gap-2 pl-1.5 pr-2.5 py-1.5 rounded-full bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 active:scale-95 transition-all border border-slate-200 dark:border-slate-700 shadow-sm"
  }, React.createElement("div", {
    className: "w-8 h-8 rounded-full bg-gradient-to-br from-melius-cyan to-melius-violet flex items-center justify-center text-white font-black text-sm"
  }, (currentUser.name || '?').charAt(0).toUpperCase()), React.createElement(Icon, {
    name: "ChevronDown",
    size: 16,
    className: `text-slate-500 dark:text-slate-300 transition-transform ${userMenuOpen ? 'rotate-180' : ''}`
  })), userMenuOpen && React.createElement("div", {
    role: "menu",
    className: "absolute right-0 mt-2 w-64 max-w-[calc(100vw-1rem)] bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 py-2 z-30 anim-fade-in"
  }, React.createElement("div", {
    className: "px-4 py-3 border-b border-slate-100 dark:border-slate-800"
  }, React.createElement("div", {
    className: "text-[10px] uppercase tracking-widest text-slate-400 font-black"
  }, "Sesi\xF3n activa"), React.createElement("div", {
    className: "text-sm font-bold text-slate-700 dark:text-slate-200 truncate mt-0.5"
  }, currentUser.name), React.createElement("div", {
    className: "text-[11px] text-slate-400 truncate",
    title: currentUser.email || ''
  }, currentUser.email || '')), onGoDashboard && React.createElement("button", {
    onClick: () => {
      setUserMenuOpen(false);
      onGoDashboard();
    },
    className: "w-full text-left px-4 py-3 text-sm font-bold flex items-center gap-3 text-melius-cyan hover:bg-cyan-50 dark:hover:bg-cyan-900/20"
  }, React.createElement(Icon, {
    name: "Clock",
    size: 18
  }), "Ir al checador"), React.createElement("button", {
    onClick: () => {
      setUserMenuOpen(false);
      onToggleTheme();
    },
    className: "w-full text-left px-4 py-3 text-sm font-bold flex items-center gap-3 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800"
  }, React.createElement(Icon, {
    name: theme === 'dark' ? 'Sun' : 'Moon',
    size: 18
  }), theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'), onStartTour && React.createElement("button", {
    onClick: () => {
      setUserMenuOpen(false);
      onStartTour();
    },
    className: "w-full text-left px-4 py-3 text-sm font-bold flex items-center gap-3 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800"
  }, React.createElement(Icon, {
    name: "ShieldCheck",
    size: 18
  }), "Ver tutorial"), React.createElement("div", {
    className: "border-t border-slate-100 dark:border-slate-800 mt-1 pt-1"
  }, React.createElement("button", {
    onClick: () => {
      setUserMenuOpen(false);
      onLogout();
    },
    className: "w-full text-left px-4 py-3 text-sm font-bold flex items-center gap-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
  }, React.createElement(Icon, {
    name: "LogOut",
    size: 18
  }), "Cerrar sesi\xF3n"))))), activeTab === 'requests' && React.createElement("div", {
    className: "flex gap-2 bg-white dark:bg-slate-900 p-2 rounded-2xl border border-slate-100 dark:border-slate-800 w-full sm:w-auto self-start"
  }, React.createElement("button", {
    onClick: () => setRequestsSub('changes'),
    className: `px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-2 transition-all ${requestsSub === 'changes' ? 'btn-melius' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}`
  }, React.createElement(Icon, {
    name: "ArrowLeftRight",
    size: 14
  }), "Cambios", counts.changes > 0 && React.createElement("span", {
    className: "min-w-[16px] h-[16px] px-1 rounded-full bg-red-500 text-white text-[9px] font-black flex items-center justify-center"
  }, counts.changes)), React.createElement("button", {
    onClick: () => setRequestsSub('vacations'),
    className: `px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-2 transition-all ${requestsSub === 'vacations' ? 'btn-melius' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}`
  }, React.createElement(Icon, {
    name: "CalendarDays",
    size: 14
  }), "Vacaciones", counts.vacations > 0 && React.createElement("span", {
    className: "min-w-[16px] h-[16px] px-1 rounded-full bg-emerald-500 text-white text-[9px] font-black flex items-center justify-center"
  }, counts.vacations))), activeTab === 'dashboard' && React.createElement(DashboardTab, null), activeTab === 'records' && React.createElement(RecordsTab, {
    isSuper: isSuper
  }), activeTab === 'agents' && React.createElement(AgentsTab, {
    isSuper: isSuper
  }), activeTab === 'requests' && requestsSub === 'changes' && React.createElement(ChangesTab, {
    onChange: refreshCounts
  }), activeTab === 'requests' && requestsSub === 'vacations' && React.createElement(VacationsTab, {
    onChange: refreshCounts
  }), activeTab === 'brands' && isSuper && React.createElement(BrandsTab, null), activeTab === 'companies' && React.createElement(CompaniesTab, {
    isSuper: isSuper
  }), activeTab === 'admins' && React.createElement(AdminsTab, {
    currentUser: currentUser,
    isSuper: isSuper
  }), activeTab === 'alerts' && React.createElement(LocationAlertsTab, {
    onChange: refreshCounts
  }), activeTab === 'tenant' && isSuper && React.createElement(ConfigurationTab, null), activeTab === 'emails' && isSuper && React.createElement(EmailTemplatesTab, null), React.createElement("nav", {
    className: "md:hidden fixed bottom-0 left-0 right-0 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700 px-2 py-1 z-40 flex justify-around",
    style: {
      paddingBottom: 'max(4px, env(safe-area-inset-bottom))'
    }
  }, primaryTabs.map(t => {
    const active = activeTab === t.id;
    const badge = t.id === 'requests' && totalRequests > 0;
    return React.createElement("button", {
      key: t.id,
      onClick: () => goTab(t.id),
      className: `flex-1 flex flex-col items-center gap-0.5 py-1.5 rounded-lg transition-all relative ${active ? 'text-melius-cyan' : 'text-slate-400 dark:text-slate-500'}`
    }, React.createElement(Icon, {
      name: t.icon,
      size: 20
    }), React.createElement("span", {
      className: "text-[10px] font-bold"
    }, t.label), badge && React.createElement("span", {
      className: "absolute top-0 right-2 min-w-[16px] h-[16px] px-1 rounded-full bg-red-500 text-white text-[9px] font-black flex items-center justify-center"
    }, totalRequests), active && React.createElement("span", {
      className: "absolute -top-1 left-1/2 -translate-x-1/2 w-8 h-1 rounded-full bg-melius-cyan"
    }));
  }), React.createElement("button", {
    onClick: () => setMobileMoreOpen(true),
    className: `flex-1 flex flex-col items-center gap-0.5 py-1.5 rounded-lg transition-all relative ${secondaryTabs.some(s => s.id === activeTab) ? 'text-melius-cyan' : 'text-slate-400 dark:text-slate-500'}`
  }, React.createElement(Icon, {
    name: "MoreHorizontal",
    size: 20
  }), React.createElement("span", {
    className: "text-[10px] font-bold"
  }, "M\xE1s"))), mobileMoreOpen && React.createElement("div", {
    className: "md:hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-end",
    onClick: () => setMobileMoreOpen(false)
  }, React.createElement("div", {
    className: "bg-white dark:bg-slate-900 w-full rounded-t-3xl p-4 space-y-1 anim-zoom-in max-h-[85vh] overflow-y-auto",
    onClick: e => e.stopPropagation()
  }, React.createElement("div", {
    className: "w-12 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full mx-auto mb-3"
  }), React.createElement("p", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400 px-3 pb-1"
  }, "Acciones r\xE1pidas"), onGoDashboard && React.createElement("button", {
    onClick: () => {
      setMobileMoreOpen(false);
      onGoDashboard();
    },
    className: "w-full text-left px-3 py-3 text-sm font-bold flex items-center gap-3 rounded-xl text-melius-cyan bg-cyan-50/60 dark:bg-cyan-900/20 hover:bg-cyan-50 dark:hover:bg-cyan-900/30"
  }, React.createElement(Icon, {
    name: "Clock",
    size: 18
  }), "Ir al checador"), React.createElement("button", {
    onClick: () => {
      setMobileMoreOpen(false);
      onToggleTheme();
    },
    className: "w-full text-left px-3 py-3 text-sm font-bold flex items-center gap-3 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800"
  }, React.createElement(Icon, {
    name: theme === 'dark' ? 'Sun' : 'Moon',
    size: 18
  }), theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'), onStartTour && React.createElement("button", {
    onClick: () => {
      setMobileMoreOpen(false);
      onStartTour();
    },
    className: "w-full text-left px-3 py-3 text-sm font-bold flex items-center gap-3 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800"
  }, React.createElement(Icon, {
    name: "ShieldCheck",
    size: 18
  }), "Ver tutorial"), secondaryTabs.length > 0 && React.createElement(React.Fragment, null, React.createElement("p", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400 px-3 pt-3 pb-1"
  }, "Configuraci\xF3n"), secondaryTabs.map(t => React.createElement("button", {
    key: t.id,
    onClick: () => goTab(t.id),
    className: `w-full text-left px-3 py-3 text-sm font-bold flex items-center gap-3 rounded-xl ${activeTab === t.id ? 'bg-cyan-50 dark:bg-cyan-900/20 text-melius-cyan' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800'}`
  }, React.createElement(Icon, {
    name: t.icon,
    size: 18
  }), t.label))), React.createElement("div", {
    className: "border-t border-slate-100 dark:border-slate-800 mt-2 pt-2"
  }, React.createElement("button", {
    onClick: () => {
      setMobileMoreOpen(false);
      onLogout();
    },
    className: "w-full text-left px-3 py-3 text-sm font-bold flex items-center gap-3 rounded-xl text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
  }, React.createElement(Icon, {
    name: "LogOut",
    size: 18
  }), "Cerrar sesi\xF3n")), React.createElement("button", {
    onClick: () => setMobileMoreOpen(false),
    className: "w-full mt-2 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200"
  }, "Cerrar"))));
};
const Kpi = ({
  label,
  value
}) => React.createElement("div", {
  className: "bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm"
}, React.createElement("p", {
  className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
}, label), React.createElement("p", {
  className: "font-black text-2xl sm:text-3xl text-slate-800 dark:text-slate-100 mt-1"
}, value));
const DashboardTab = () => {
  const {
    push: toast
  } = useToast();
  const [data, setData] = useState(null);
  const [companyId, setCompanyId] = useState('');
  const [companies, setCompanies] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [alertsPending, setAlertsPending] = useState(0);
  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const cs = await apiFetch('admin/companies');
      setCompanies(cs.companies || []);
      const d = companyId ? await apiFetch(`admin/dashboard/company/${companyId}`) : await apiFetch('admin/dashboard/global');
      setData(d.dashboard);
      apiFetch('admin/location-alerts/pending-count').then(r => setAlertsPending(r.pending || 0)).catch(() => {});
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [companyId]);
  useEffect(() => {
    load();
  }, [load]);
  if (loading) return React.createElement(LoadingScreen, null);
  if (error) return React.createElement(ErrorState, {
    message: error,
    onRetry: load
  });
  const t = data?.totals || {};
  return React.createElement("div", {
    className: "space-y-4 sm:space-y-6"
  }, alertsPending > 0 && React.createElement("div", {
    className: "bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-900/40 rounded-2xl p-4 flex items-center gap-3"
  }, React.createElement(Icon, {
    name: "AlertTriangle",
    className: "text-red-500",
    size: 24
  }), React.createElement("div", {
    className: "flex-1 min-w-0"
  }, React.createElement("p", {
    className: "font-black text-sm text-red-700 dark:text-red-300"
  }, alertsPending, " alerta", alertsPending === 1 ? '' : 's', " de ubicacion pendiente", alertsPending === 1 ? '' : 's'), React.createElement("p", {
    className: "text-xs text-red-600/80 dark:text-red-200/70"
  }, "Cambios radicales detectados en marcajes recientes. Revisa el tab \"Alertas geo\"."))), React.createElement("div", {
    className: "flex flex-wrap gap-3 items-end justify-between"
  }, React.createElement("div", {
    className: "flex flex-col"
  }, React.createElement("label", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400 ml-2 mb-1"
  }, "Empresa"), React.createElement(Select, {
    value: companyId,
    onChange: e => setCompanyId(e.target.value),
    size: "sm"
  }, React.createElement("option", {
    value: ""
  }, "Todas"), companies.map(c => React.createElement("option", {
    key: c.id,
    value: c.id
  }, c.name))))), React.createElement("div", {
    className: "grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4"
  }, React.createElement(Kpi, {
    label: "Registros hoy",
    value: t.records_today ?? 0
  }), React.createElement(Kpi, {
    label: "Registros semana",
    value: t.records_week ?? 0
  }), React.createElement(Kpi, {
    label: "Registros mes",
    value: t.records_month ?? 0
  }), React.createElement(Kpi, {
    label: "Consultores activos",
    value: t.active_users ?? 0
  }), React.createElement(Kpi, {
    label: "Retrasos del mes",
    value: t.late_month ?? 0
  }), React.createElement(Kpi, {
    label: "Ausencias del mes",
    value: t.absences_month ?? 0
  }), React.createElement(Kpi, {
    label: "Vacaciones pendientes",
    value: t.vacation_pending_count ?? 0
  }), React.createElement(Kpi, {
    label: "D\xEDas aprobados (mes)",
    value: (t.vacation_approved_days ?? 0).toFixed(0)
  })), data?.by_company?.length > 0 && React.createElement("div", {
    className: "bg-white dark:bg-slate-900 p-4 sm:p-6 rounded-2xl border border-slate-100 dark:border-slate-800"
  }, React.createElement("h3", {
    className: "font-black uppercase tracking-widest text-xs text-slate-500 mb-3"
  }, "Consultores activos por empresa"), React.createElement("div", {
    className: "grid grid-cols-2 sm:grid-cols-3 gap-3"
  }, data.by_company.map(b => React.createElement("div", {
    key: b.company_id,
    className: "flex items-center justify-between bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl"
  }, React.createElement("span", {
    className: "font-bold text-sm text-slate-700 dark:text-slate-200 truncate"
  }, b.company_name), React.createElement("span", {
    className: "font-mono font-black text-blue-600 dark:text-blue-300"
  }, b.active_users))))));
};
const RecordsTab = ({
  isSuper
}) => {
  const [records, setRecords] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [period, setPeriod] = useState('month');
  const [companyId, setCompanyId] = useState('');
  const [companies, setCompanies] = useState([]);
  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const r = await apiFetch('admin/records');
      setRecords(r.records || []);
      if (companies.length === 0) {
        const cs = await apiFetch('admin/companies');
        setCompanies(cs.companies || []);
      }
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [companies.length]);
  useEffect(() => {
    load();
  }, [load]);
  const exportCsv = () => {
    const qs = new URLSearchParams({
      period
    });
    if (companyId) qs.set('company_id', companyId);
    window.location.href = `/api/admin/records/export?${qs.toString()}`;
  };
  if (loading) return React.createElement(LoadingScreen, null);
  if (error) return React.createElement(ErrorState, {
    message: error,
    onRetry: load
  });
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", {
    className: "flex flex-wrap items-end gap-3 bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-100 dark:border-slate-800"
  }, React.createElement("div", {
    className: "flex flex-col"
  }, React.createElement("label", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400 ml-2 mb-1"
  }, "Periodo"), React.createElement(Select, {
    value: period,
    onChange: e => setPeriod(e.target.value),
    size: "sm"
  }, React.createElement("option", {
    value: "week"
  }, "Esta semana"), React.createElement("option", {
    value: "month"
  }, "Este mes"), React.createElement("option", {
    value: "year"
  }, "Este a\xF1o"))), isSuper && React.createElement("div", {
    className: "flex flex-col"
  }, React.createElement("label", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400 ml-2 mb-1"
  }, "Empresa"), React.createElement(Select, {
    value: companyId,
    onChange: e => setCompanyId(e.target.value),
    size: "sm"
  }, React.createElement("option", {
    value: ""
  }, "Todas"), companies.map(c => React.createElement("option", {
    key: c.id,
    value: c.id
  }, c.name)))), React.createElement("button", {
    onClick: exportCsv,
    className: "ml-auto px-4 py-2 rounded-xl bg-emerald-500 text-white font-bold text-sm hover:bg-emerald-600 transition-colors"
  }, "Exportar CSV")), React.createElement("div", {
    className: "bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-800 overflow-hidden"
  }, React.createElement("div", {
    className: "hidden md:block overflow-x-auto custom-scrollbar"
  }, React.createElement("table", {
    className: "w-full text-left"
  }, React.createElement("thead", {
    className: "bg-slate-50 dark:bg-slate-800/60 text-[10px] uppercase font-black text-slate-400 tracking-wider border-b dark:border-slate-800"
  }, React.createElement("tr", null, React.createElement("th", {
    className: "px-6 py-4"
  }, "Consultor"), React.createElement("th", {
    className: "px-6 py-4"
  }, "Empresa"), React.createElement("th", {
    className: "px-6 py-4"
  }, "Fecha"), React.createElement("th", {
    className: "px-6 py-4"
  }, "Entrada"), React.createElement("th", {
    className: "px-6 py-4"
  }, "Salida"), React.createElement("th", {
    className: "px-6 py-4"
  }, "Estado"))), React.createElement("tbody", {
    className: "divide-y dark:divide-slate-800 text-sm"
  }, records.map(rec => React.createElement(AdminRecordRow, {
    key: rec.id,
    rec: rec
  })), records.length === 0 && React.createElement("tr", null, React.createElement("td", {
    colSpan: "6",
    className: "px-6 py-20 text-center text-slate-300 italic"
  }, "Sin registros a\xFAn"))))), React.createElement("div", {
    className: "md:hidden divide-y dark:divide-slate-800"
  }, records.map(rec => React.createElement(AdminRecordCard, {
    key: rec.id,
    rec: rec
  })), records.length === 0 && React.createElement(EmptyState, {
    message: "Sin registros a\xFAn"
  }))));
};
const CompanyForm = ({
  initial,
  onSave,
  onCancel
}) => {
  const [form, setForm] = useState(() => ({
    name: initial?.name || '',
    brand_id: initial?.brand_id || '',
    timezone: initial?.timezone || 'America/Mexico_City',
    work_start_time: initial?.work_start_time || '09:00',
    work_end_time: initial?.work_end_time || '18:00',
    work_days_mask: initial?.work_days_mask || 31,
    grace_minutes_late: initial?.grace_minutes_late ?? 15
  }));
  const [brands, setBrands] = useState([]);
  const [submitting, setSubmitting] = useState(false);
  useEffect(() => {
    apiFetch('admin/brands').then(d => setBrands(d.brands || [])).catch(() => {});
  }, []);
  const dayBits = [{
    bit: 1,
    label: 'L'
  }, {
    bit: 2,
    label: 'M'
  }, {
    bit: 4,
    label: 'X'
  }, {
    bit: 8,
    label: 'J'
  }, {
    bit: 16,
    label: 'V'
  }, {
    bit: 32,
    label: 'S'
  }, {
    bit: 64,
    label: 'D'
  }];
  const toggleDay = bit => setForm(f => ({
    ...f,
    work_days_mask: f.work_days_mask & bit ? f.work_days_mask & ~bit : f.work_days_mask | bit
  }));
  const submit = async e => {
    e.preventDefault();
    setSubmitting(true);
    try {
      const payload = {
        ...form,
        work_days_mask: parseInt(form.work_days_mask, 10),
        grace_minutes_late: parseInt(form.grace_minutes_late, 10),
        brand_id: form.brand_id ? parseInt(form.brand_id, 10) : null
      };
      await onSave(payload);
    } finally {
      setSubmitting(false);
    }
  };
  return React.createElement("form", {
    onSubmit: submit,
    className: "space-y-4"
  }, React.createElement("div", {
    className: "grid grid-cols-1 sm:grid-cols-2 gap-3"
  }, React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Nombre"), React.createElement("input", {
    required: true,
    maxLength: "100",
    value: form.name,
    onChange: e => setForm({
      ...form,
      name: e.target.value
    }),
    className: "w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold"
  })), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Marca paraguas"), React.createElement("div", {
    className: "mt-1"
  }, React.createElement(Select, {
    value: form.brand_id,
    onChange: e => setForm({
      ...form,
      brand_id: e.target.value
    })
  }, React.createElement("option", {
    value: ""
  }, "\u2014 Sin marca \u2014"), brands.map(b => React.createElement("option", {
    key: b.id,
    value: b.id
  }, b.name))))), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Zona horaria (IANA)"), React.createElement("input", {
    required: true,
    value: form.timezone,
    onChange: e => setForm({
      ...form,
      timezone: e.target.value
    }),
    className: "w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-sm"
  })), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Entrada (HH:MM)"), React.createElement("input", {
    required: true,
    pattern: "^[0-2][0-9]:[0-5][0-9]$",
    value: form.work_start_time,
    onChange: e => setForm({
      ...form,
      work_start_time: e.target.value
    }),
    className: "w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono"
  })), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Salida (HH:MM)"), React.createElement("input", {
    required: true,
    pattern: "^[0-2][0-9]:[0-5][0-9]$",
    value: form.work_end_time,
    onChange: e => setForm({
      ...form,
      work_end_time: e.target.value
    }),
    className: "w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono"
  })), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Tolerancia tarde (min)"), React.createElement("input", {
    type: "number",
    min: "0",
    max: "60",
    value: form.grace_minutes_late,
    onChange: e => setForm({
      ...form,
      grace_minutes_late: e.target.value
    }),
    className: "w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono"
  }))), React.createElement("div", null, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400 block mb-1"
  }, "D\xEDas laborales"), React.createElement("div", {
    className: "flex gap-2"
  }, dayBits.map(d => React.createElement("button", {
    type: "button",
    key: d.bit,
    onClick: () => toggleDay(d.bit),
    className: `w-10 h-10 rounded-xl font-black text-sm ${form.work_days_mask & d.bit ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-400'}`
  }, d.label)))), React.createElement("div", {
    className: "flex gap-2 pt-2"
  }, React.createElement("button", {
    type: "button",
    onClick: onCancel,
    className: "flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200"
  }, "Cancelar"), React.createElement("button", {
    type: "submit",
    disabled: submitting,
    className: "flex-1 py-3 rounded-xl bg-blue-600 text-white font-black hover:bg-blue-700 disabled:opacity-60 flex items-center justify-center gap-2"
  }, submitting && React.createElement(Icon, {
    name: "Spinner",
    size: 18
  }), "Guardar")));
};
async function apiUpload(path, formData) {
  const headers = {
    'Accept': 'application/json'
  };
  if (CSRF_TOKEN) headers['X-CSRF-Token'] = CSRF_TOKEN;
  const res = await fetch(`${API_BASE}/${path.replace(/^\//, '')}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers,
    body: formData
  });
  let payload;
  try {
    payload = await res.json();
  } catch (_) {
    payload = {
      ok: false,
      error: {
        code: 'BAD_RESPONSE',
        message: 'Respuesta no JSON.'
      }
    };
  }
  if (!res.ok || !payload.ok) {
    throw payload.error || {
      code: 'UNKNOWN',
      message: `HTTP ${res.status}`
    };
  }
  return payload.data;
}
const ConfigurationTab = () => {
  const [section, setSection] = useState('branding');
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", {
    className: "flex gap-2 bg-white dark:bg-slate-900 p-2 rounded-2xl border border-slate-100 dark:border-slate-800 w-full sm:w-auto self-start"
  }, React.createElement("button", {
    onClick: () => setSection('branding'),
    className: `px-4 py-2 rounded-xl text-xs font-bold transition-all ${section === 'branding' ? 'btn-melius' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}`
  }, "Branding"), React.createElement("button", {
    onClick: () => setSection('billing'),
    className: `px-4 py-2 rounded-xl text-xs font-bold transition-all ${section === 'billing' ? 'btn-melius' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}`
  }, "Licencia")), section === 'branding' && React.createElement(TenantSettingsTab, null), section === 'billing' && React.createElement(BillingTab, null));
};
const formatMonthly = (cents, currency) => {
  if (!cents) return 'Bajo cotización';
  const v = (cents / 100).toFixed(cents % 100 === 0 ? 0 : 2);
  return `$${v} ${currency}/mes`;
};
const BillingTab = () => {
  const {
    push: toast
  } = useToast();
  const [plans, setPlans] = useState([]);
  const [sub, setSub] = useState(null);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [connectModal, setConnectModal] = useState(null);
  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [pr, sr] = await Promise.all([apiFetch('admin/billing/plans'), apiFetch('admin/billing/subscription')]);
      setPlans(pr.plans || []);
      setSub(sr.subscription || null);
    } catch (e) {
      toast('error', e.message);
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    load();
  }, [load]);
  const changePlan = async planCode => {
    if (!confirm(`¿Cambiar plan a "${planCode}"? Se aplicará de forma manual (sin cobro).`)) return;
    setSubmitting(true);
    try {
      const r = await apiFetch('admin/billing/subscription', {
        method: 'PUT',
        body: {
          plan_code: planCode
        }
      });
      setSub(r.subscription);
      toast('success', 'Plan actualizado.');
    } catch (e) {
      toast('error', e.message);
    } finally {
      setSubmitting(false);
    }
  };
  const connectProvider = async provider => {
    setSubmitting(true);
    try {
      await apiFetch('admin/billing/connect', {
        method: 'POST',
        body: {
          provider
        }
      });
      toast('success', `${provider} conectado.`);
      setConnectModal(null);
      load();
    } catch (e) {
      setConnectModal({
        provider,
        steps: e.next_steps || [e.message]
      });
    } finally {
      setSubmitting(false);
    }
  };
  if (loading) return React.createElement(LoadingScreen, null);
  const statusColor = {
    trial: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    past_due: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300',
    canceled: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    suspended: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'
  }[sub?.status || 'trial'] || 'bg-slate-100 text-slate-700';
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", null, React.createElement("h3", {
    className: "font-black text-lg text-slate-800 dark:text-slate-100"
  }, "Licencia mensual"), React.createElement("p", {
    className: "text-xs text-slate-500"
  }, "Plan, estado de la suscripci\xF3n y conexi\xF3n con pasarela de pago.")), React.createElement("div", {
    className: "rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5"
  }, React.createElement("div", {
    className: "flex items-start justify-between gap-3 flex-wrap"
  }, React.createElement("div", null, React.createElement("div", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Plan actual"), React.createElement("div", {
    className: "text-2xl font-black text-slate-800 dark:text-slate-100"
  }, sub?.plan_name || 'Trial'), React.createElement("div", {
    className: "text-xs text-slate-500 mt-1"
  }, sub?.price_monthly_cents ? formatMonthly(sub.price_monthly_cents, sub.currency) : 'Sin costo (trial)')), React.createElement("div", {
    className: "flex flex-col items-end gap-1.5"
  }, React.createElement("span", {
    className: `px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest ${statusColor}`
  }, sub?.status || 'trial'), React.createElement("span", {
    className: "text-[10px] text-slate-400"
  }, sub?.provider === 'none' ? 'Sin pasarela conectada' : `Pasarela: ${sub?.provider}`))), sub?.features && React.createElement("p", {
    className: "text-xs text-slate-500 mt-3"
  }, sub.features)), React.createElement("div", null, React.createElement("h4", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500 mb-2"
  }, "Planes disponibles"), React.createElement("div", {
    className: "grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3"
  }, plans.map(p => {
    const current = sub?.plan_code === p.code;
    return React.createElement("div", {
      key: p.code,
      className: `rounded-2xl border p-4 ${current ? 'border-melius-cyan bg-cyan-50 dark:bg-cyan-900/10' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900'}`
    }, React.createElement("div", {
      className: "flex items-center justify-between"
    }, React.createElement("div", {
      className: "font-black text-slate-800 dark:text-slate-100"
    }, p.name), current && React.createElement("span", {
      className: "text-[10px] font-black uppercase tracking-widest text-melius-cyan"
    }, "Actual")), React.createElement("div", {
      className: "text-xl font-black text-slate-800 dark:text-slate-100 mt-2"
    }, formatMonthly(p.price_monthly_cents, p.currency)), p.features && React.createElement("p", {
      className: "text-[11px] text-slate-500 mt-2 leading-relaxed"
    }, p.features), React.createElement("ul", {
      className: "text-[11px] text-slate-500 mt-2 space-y-0.5"
    }, React.createElement("li", null, "Usuarios: ", React.createElement("strong", null, p.max_users ?? 'sin límite')), React.createElement("li", null, "Empresas: ", React.createElement("strong", null, p.max_companies ?? 'sin límite'))), !current && React.createElement("button", {
      onClick: () => changePlan(p.code),
      disabled: submitting,
      className: "mt-3 w-full py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 font-bold text-xs disabled:opacity-60"
    }, "Asignar manualmente"));
  }))), React.createElement("div", null, React.createElement("h4", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500 mb-2"
  }, "Pasarela de pago"), React.createElement("div", {
    className: "rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-4 space-y-3"
  }, React.createElement("p", {
    className: "text-xs text-slate-500"
  }, "Conecta una pasarela para cobrar mensualmente de forma autom\xE1tica. Por ahora la suscripci\xF3n se gestiona de forma manual."), React.createElement("div", {
    className: "flex flex-wrap gap-2"
  }, React.createElement("button", {
    onClick: () => connectProvider('stripe'),
    disabled: submitting,
    className: "px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold text-sm hover:bg-indigo-700 disabled:opacity-60 flex items-center gap-2"
  }, "Conectar Stripe"), React.createElement("button", {
    onClick: () => connectProvider('paypal'),
    disabled: submitting,
    className: "px-4 py-2 rounded-xl bg-blue-600 text-white font-bold text-sm hover:bg-blue-700 disabled:opacity-60 flex items-center gap-2"
  }, "Conectar PayPal")))), React.createElement(Modal, {
    open: !!connectModal,
    onClose: () => setConnectModal(null),
    title: "Conectar pasarela",
    maxWidth: "max-w-lg"
  }, connectModal && React.createElement("div", {
    className: "space-y-3"
  }, React.createElement("h3", {
    className: "text-xl font-black text-slate-800 dark:text-slate-100"
  }, "Conectar ", connectModal.provider), React.createElement("div", {
    className: "rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-900/40 p-4 text-sm text-amber-800 dark:text-amber-200"
  }, React.createElement("p", {
    className: "font-bold mb-2"
  }, "Integraci\xF3n pendiente"), React.createElement("p", {
    className: "text-xs"
  }, "La conexi\xF3n real con ", connectModal.provider, " est\xE1 lista a nivel de modelo de datos pero requiere credenciales API. Pasos:")), React.createElement("ol", {
    className: "list-decimal list-inside text-xs text-slate-600 dark:text-slate-300 space-y-2 pl-2"
  }, connectModal.steps.map((s, i) => React.createElement("li", {
    key: i
  }, s))), React.createElement("button", {
    onClick: () => setConnectModal(null),
    className: "w-full py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200"
  }, "Entendido"))));
};
const EMAIL_TEMPLATE_KINDS = [{
  id: 'invitation',
  label: 'Invitación',
  desc: 'Correo de bienvenida con credenciales temporales.'
}, {
  id: 'password_reset',
  label: 'Restablecer contraseña',
  desc: 'Enlace de reset cuando un usuario olvida su contraseña.'
}, {
  id: 'admin_disabled',
  label: 'Cuenta desactivada',
  desc: 'Aviso al administrador cuya cuenta fue desactivada.'
}, {
  id: 'admin_delete_receipt',
  label: 'Recibo de desactivación',
  desc: 'Confirmación al admin que ejecutó la desactivación.'
}];
const EMAIL_PLACEHOLDERS_BY_KIND = {
  invitation: ['{{name}}', '{{company}}', '{{brand_name}}'],
  password_reset: ['{{name}}', '{{brand_name}}', '{{hours}}'],
  admin_disabled: ['{{name}}', '{{company}}', '{{actor_name}}', '{{brand_name}}'],
  admin_delete_receipt: ['{{actor_name}}', '{{target_name}}', '{{target_email}}', '{{company}}', '{{brand_name}}']
};
const EmailTemplatesTab = () => {
  const {
    push: toast
  } = useToast();
  const [brands, setBrands] = useState([]);
  const [activeBrand, setActiveBrand] = useState(null);
  const [activeKind, setActiveKind] = useState('invitation');
  const [tpl, setTpl] = useState({
    subject: '',
    intro_html: '',
    cta_label: ''
  });
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
        cta_label: d.template.cta_label || ''
      });
    } catch (e) {
      setTpl({
        subject: '',
        intro_html: '',
        cta_label: ''
      });
      if (e.status !== 404) toast('error', e.message);
    }
  }, [activeBrand, activeKind]);
  useEffect(() => {
    loadTpl();
  }, [loadTpl]);
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
          cta_label: tpl.cta_label
        }
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
        body: tpl
      });
      toast('success', 'Plantilla guardada.');
      loadTpl();
    } catch (e) {
      toast('error', e.message);
    } finally {
      setSaving(false);
    }
  };
  const reset = async () => {
    if (!activeBrand) return;
    if (!confirm('¿Restablecer la plantilla al texto por defecto? Se perderán los cambios guardados.')) return;
    try {
      await apiFetch(`admin/email-templates/${activeBrand}/${activeKind}`, {
        method: 'DELETE'
      });
      toast('success', 'Plantilla restablecida.');
      loadTpl();
    } catch (e) {
      toast('error', e.message);
    }
  };
  const insertPlaceholder = ph => {
    setTpl(prev => ({
      ...prev,
      intro_html: (prev.intro_html || '') + ph
    }));
  };
  if (loading) return React.createElement(LoadingScreen, null);
  if (!brands.length) return React.createElement("div", {
    className: "p-6 text-slate-500"
  }, "No hay marcas registradas. Crea una marca primero.");
  const activeKindMeta = EMAIL_TEMPLATE_KINDS.find(k => k.id === activeKind);
  const placeholders = EMAIL_PLACEHOLDERS_BY_KIND[activeKind] || [];
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", null, React.createElement("h2", {
    className: "text-lg font-black text-slate-800 dark:text-slate-100"
  }, "Plantillas de correo"), React.createElement("p", {
    className: "text-xs text-slate-500"
  }, "Edita el contenido escrito de los correos del sistema. El dise\xF1o visual (colores, logos, layout) se controla desde la pesta\xF1a Marcas.")), React.createElement("div", {
    className: "flex flex-wrap gap-2 items-center"
  }, React.createElement("span", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Marca"), React.createElement(Select, {
    value: activeBrand || '',
    onChange: e => setActiveBrand(parseInt(e.target.value, 10)),
    size: "sm"
  }, brands.map(b => React.createElement("option", {
    key: b.id,
    value: b.id
  }, b.name)))), React.createElement("div", {
    className: "flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-700"
  }, EMAIL_TEMPLATE_KINDS.map(k => React.createElement("button", {
    key: k.id,
    type: "button",
    onClick: () => setActiveKind(k.id),
    className: `px-3 py-2 text-xs font-bold border-b-2 transition-all ${activeKind === k.id ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}`
  }, k.label))), activeKindMeta && React.createElement("p", {
    className: "text-xs text-slate-500"
  }, activeKindMeta.desc), React.createElement("div", {
    className: "grid grid-cols-1 lg:grid-cols-2 gap-4"
  }, React.createElement("div", {
    className: "space-y-3"
  }, React.createElement("div", null, React.createElement("label", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Asunto del correo"), React.createElement("input", {
    type: "text",
    value: tpl.subject,
    maxLength: 200,
    onChange: e => setTpl({
      ...tpl,
      subject: e.target.value
    }),
    className: "mt-1 w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm"
  })), React.createElement("div", null, React.createElement("label", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Texto del correo"), React.createElement("textarea", {
    value: tpl.intro_html,
    rows: "8",
    maxLength: "4000",
    onChange: e => setTpl({
      ...tpl,
      intro_html: e.target.value
    }),
    className: "mt-1 w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm font-mono"
  }), React.createElement("p", {
    className: "text-[10px] text-slate-400 mt-1"
  }, tpl.intro_html.length, "/4000 caracteres. Solo texto plano; los saltos de l\xEDnea se respetan.")), React.createElement("div", null, React.createElement("label", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Placeholders disponibles"), React.createElement("div", {
    className: "flex flex-wrap gap-1 mt-1"
  }, placeholders.map(ph => React.createElement("button", {
    key: ph,
    type: "button",
    onClick: () => insertPlaceholder(ph),
    className: "px-2 py-1 text-[11px] font-mono rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200"
  }, ph)))), (activeKind === 'invitation' || activeKind === 'password_reset') && React.createElement("div", null, React.createElement("label", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Texto del bot\xF3n"), React.createElement("input", {
    type: "text",
    value: tpl.cta_label || '',
    maxLength: 80,
    onChange: e => setTpl({
      ...tpl,
      cta_label: e.target.value
    }),
    placeholder: "Dejar vac\xEDo para usar el texto por defecto",
    className: "mt-1 w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm"
  })), React.createElement("div", {
    className: "flex gap-2 pt-2"
  }, React.createElement("button", {
    type: "button",
    onClick: save,
    disabled: saving,
    className: "flex-1 py-2 rounded-xl btn-melius font-black disabled:opacity-60 flex items-center justify-center gap-2"
  }, saving && React.createElement(Icon, {
    name: "Spinner",
    size: 16
  }), "Guardar"), React.createElement("button", {
    type: "button",
    onClick: reset,
    className: "px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200 text-sm"
  }, "Restablecer"))), React.createElement("div", {
    className: "space-y-2"
  }, React.createElement("span", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Vista previa en vivo"), React.createElement("div", {
    className: "rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden bg-slate-100 dark:bg-slate-900 h-[60vh] sm:h-[560px] max-h-[560px] min-h-[320px]"
  }, React.createElement("iframe", {
    srcDoc: previewHtml,
    title: "Vista previa correo",
    className: "w-full h-full bg-white",
    sandbox: "allow-same-origin"
  })), React.createElement("p", {
    className: "text-[10px] text-slate-400"
  }, "El correo real se enviar\xE1 con el logo y colores de la marca seleccionada."))));
};
const DEFAULT_TENANT_LOGO = '/assets/brands/melius.webp';
const TenantSettingsTab = () => {
  const {
    push: toast
  } = useToast();
  const {
    tenantBranding,
    setTenantBranding,
    refreshBranding
  } = useBranding();
  const [form, setForm] = useState({
    product_name: tenantBranding.product_name,
    primary_color: tenantBranding.primary_color,
    secondary_color: tenantBranding.secondary_color || ''
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
            secondary_color: d.tenant.secondary_color || ''
          });
          setHasCustomLogo(!!d.tenant.logo_url);
          setLogoPreview(d.tenant.logo_url || DEFAULT_TENANT_LOGO);
        }
      } catch (e) {
        toast('error', e.message);
      }
    })();
  }, []);
  const pickFile = f => {
    if (!f) return;
    if (f.size > 512 * 1024) {
      toast('error', 'Logo supera 512 KB.');
      return;
    }
    if (!['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'].includes(f.type)) {
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
        secondary_color: form.secondary_color || null
      };
      const r = await apiFetch('admin/tenant-settings', {
        method: 'PUT',
        body
      });
      if (logoFile) {
        const fd = new FormData();
        fd.append('logo', logoFile);
        const lr = await apiUpload('admin/tenant-settings/logo', fd);
        setLogoFile(null);
        if (lr?.logo_url) {
          setLogoPreview(lr.logo_url);
          setHasCustomLogo(true);
        }
      }
      if (r?.tenant) setTenantBranding(prev => ({
        ...prev,
        ...r.tenant
      }));
      await refreshBranding();
      toast('success', 'Configuración del tenant guardada.');
    } catch (e) {
      toast('error', e.message || 'Error al guardar.');
    } finally {
      setSubmitting(false);
    }
  };
  const gradient = form.secondary_color ? `linear-gradient(135deg, ${form.primary_color} 0%, ${form.secondary_color} 100%)` : form.primary_color;
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", null, React.createElement("h3", {
    className: "font-black text-lg text-slate-800 dark:text-slate-100"
  }, "Branding del producto"), React.createElement("p", {
    className: "text-xs text-slate-500"
  }, "Personaliza nombre, logo y colores que se ven antes del login y en el header del admin console. Aplica a toda la instalaci\xF3n. Las marcas paraguas (NetFy, Fullman) y los emails de invitaci\xF3n no cambian.")), React.createElement("div", {
    className: "grid grid-cols-1 lg:grid-cols-2 gap-5"
  }, React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("fieldset", {
    className: "border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-3"
  }, React.createElement("legend", {
    className: "px-2 text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Identidad del producto"), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Nombre del producto"), React.createElement("input", {
    maxLength: "120",
    value: form.product_name,
    onChange: e => setForm({
      ...form,
      product_name: e.target.value
    }),
    className: "w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold"
  }), React.createElement("p", {
    className: "text-[10px] text-slate-400 mt-1"
  }, "Aparece en el header (ej. \"Melius Clockin\", \"NetFy Clockin\", \"Acme Time\").")), React.createElement("div", null, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Logo"), React.createElement("div", {
    className: "mt-1 flex items-center gap-3"
  }, React.createElement("div", {
    className: "w-16 h-16 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 flex items-center justify-center p-1.5 shrink-0 relative"
  }, React.createElement("img", {
    src: logoPreview,
    alt: "logo",
    className: "w-full h-full object-contain"
  }), !hasCustomLogo && !logoFile && React.createElement("span", {
    className: "absolute -top-2 -right-2 text-[8px] font-black uppercase tracking-widest bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-1.5 py-0.5 rounded"
  }, "default")), React.createElement("button", {
    type: "button",
    onClick: () => fileRef.current?.click(),
    className: "px-3 py-2 rounded-xl bg-cyan-50 dark:bg-cyan-900/30 text-melius-cyan border border-cyan-100 dark:border-cyan-900/40 font-bold text-xs"
  }, logoFile ? 'Cambiar' : hasCustomLogo ? 'Reemplazar' : 'Subir logo'), React.createElement("input", {
    ref: fileRef,
    type: "file",
    accept: "image/png,image/jpeg,image/webp,image/svg+xml",
    className: "hidden",
    onChange: e => pickFile(e.target.files?.[0])
  })), React.createElement("p", {
    className: "text-[10px] text-slate-400 mt-1"
  }, "PNG, JPG, WebP o SVG. M\xE1x. 512 KB. ", !hasCustomLogo && 'Estás viendo el logo default Melius — sube uno para personalizar.'))), React.createElement("fieldset", {
    className: "border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-3"
  }, React.createElement("legend", {
    className: "px-2 text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Colores base del UI"), React.createElement("div", {
    className: "grid grid-cols-2 gap-3"
  }, React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Primario"), React.createElement("div", {
    className: "mt-1 flex items-center gap-2"
  }, React.createElement("input", {
    type: "color",
    value: form.primary_color,
    onChange: e => setForm({
      ...form,
      primary_color: e.target.value
    }),
    className: "w-14 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer"
  }), React.createElement("div", {
    className: "flex-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold text-sm uppercase"
  }, form.primary_color))), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Secundario"), React.createElement("div", {
    className: "mt-1 flex items-center gap-2"
  }, React.createElement("input", {
    type: "color",
    value: form.secondary_color || '#9909fe',
    onChange: e => setForm({
      ...form,
      secondary_color: e.target.value
    }),
    className: "w-14 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer"
  }), React.createElement("div", {
    className: "flex-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold text-sm uppercase"
  }, form.secondary_color || '—')))), React.createElement("p", {
    className: "text-[10px] text-slate-400"
  }, "Estos colores aplican al gradiente del login y al bot\xF3n primario. Cada empresa puede sobrescribirlos en su propia configuraci\xF3n.")), React.createElement("button", {
    type: "button",
    onClick: save,
    disabled: submitting,
    className: "w-full py-3 rounded-xl btn-melius font-black disabled:opacity-60 flex items-center justify-center gap-2"
  }, submitting && React.createElement(Icon, {
    name: "Spinner",
    size: 18
  }), "Guardar configuraci\xF3n")), React.createElement("div", {
    className: "space-y-2"
  }, React.createElement("span", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Vista previa"), React.createElement("div", {
    className: "rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden bg-slate-100 dark:bg-slate-900 p-3"
  }, React.createElement("div", {
    className: "rounded-xl bg-white border border-slate-200 p-3 flex items-center gap-3 mb-2"
  }, React.createElement("div", {
    className: "w-10 h-10 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center p-1 shrink-0"
  }, logoPreview ? React.createElement("img", {
    src: logoPreview,
    alt: "logo",
    className: "w-full h-full object-contain"
  }) : React.createElement(Icon, {
    name: "ShieldCheck",
    size: 20,
    className: "text-slate-400"
  })), React.createElement("div", {
    className: "min-w-0"
  }, React.createElement("div", {
    className: "font-black text-slate-800 truncate text-sm"
  }, form.product_name || 'Producto'), React.createElement("div", {
    className: "text-[9px] uppercase tracking-widest text-slate-400 font-bold"
  }, "Admin Console"))), React.createElement("div", {
    className: "rounded-xl overflow-hidden"
  }, React.createElement("div", {
    style: {
      background: gradient,
      color: '#fff',
      padding: '20px 18px',
      textAlign: 'center'
    }
  }, logoPreview && React.createElement("div", {
    style: {
      marginBottom: 8
    }
  }, React.createElement("img", {
    src: logoPreview,
    alt: "logo",
    style: {
      width: 44,
      height: 44,
      borderRadius: 10,
      background: '#fff',
      padding: 4,
      objectFit: 'contain',
      display: 'inline-block'
    }
  })), React.createElement("div", {
    style: {
      fontSize: 10,
      letterSpacing: '0.3em',
      textTransform: 'uppercase',
      opacity: 0.92,
      fontWeight: 700
    }
  }, (form.product_name || 'PRODUCTO').toUpperCase()), React.createElement("div", {
    style: {
      fontSize: 18,
      fontWeight: 800,
      marginTop: 6
    }
  }, "Iniciar sesi\xF3n")), React.createElement("div", {
    style: {
      padding: '14px 16px',
      background: '#fff',
      textAlign: 'center'
    }
  }, React.createElement("span", {
    style: {
      display: 'inline-block',
      padding: '9px 22px',
      background: form.primary_color,
      color: '#fff',
      borderRadius: 8,
      fontSize: 12,
      fontWeight: 800
    }
  }, "Entrar")))), React.createElement("p", {
    className: "text-[10px] text-slate-400"
  }, "El cambio se aplicar\xE1 en vivo despu\xE9s de guardar."))));
};
const BrandsTab = () => {
  const {
    push: toast
  } = useToast();
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [editing, setEditing] = useState(null);
  const [creating, setCreating] = useState(false);
  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const d = await apiFetch('admin/brands');
      setRows(d.brands || []);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    load();
  }, [load]);
  const save = async (body, brandId, logoFile) => {
    try {
      let id = brandId;
      if (brandId) {
        await apiFetch(`admin/brands/${brandId}`, {
          method: 'PUT',
          body
        });
      } else {
        const r = await apiFetch('admin/brands', {
          method: 'POST',
          body
        });
        id = r.id;
      }
      if (logoFile && id) {
        const fd = new FormData();
        fd.append('logo', logoFile);
        await apiUpload(`admin/brands/${id}/logo`, fd);
      }
      toast('success', brandId ? 'Marca actualizada.' : 'Marca creada.');
      setEditing(null);
      setCreating(false);
      load();
    } catch (e) {
      toast('error', e.message || 'Error guardando marca.');
    }
  };
  const remove = async b => {
    if (!confirm(`¿Desactivar la marca "${b.name}"? Las empresas vinculadas quedarán sin marca.`)) return;
    try {
      await apiFetch(`admin/brands/${b.id}`, {
        method: 'DELETE'
      });
      toast('success', 'Marca desactivada.');
      load();
    } catch (e) {
      toast('error', e.message);
    }
  };
  if (loading) return React.createElement(LoadingScreen, null);
  if (error) return React.createElement(ErrorState, {
    message: error,
    onRetry: load
  });
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", {
    className: "flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
  }, React.createElement("div", null, React.createElement("h3", {
    className: "font-black text-lg text-slate-800 dark:text-slate-100"
  }, "Marcas paraguas"), React.createElement("p", {
    className: "text-xs text-slate-500"
  }, "Logo, colores y mensaje de bienvenida que cada consultor ver\xE1 al recibir su correo.")), React.createElement("button", {
    onClick: () => setCreating(true),
    className: "px-4 py-2 rounded-xl btn-melius font-bold text-sm"
  }, "+ Nueva marca")), React.createElement("div", {
    className: "grid grid-cols-1 md:grid-cols-2 gap-3"
  }, rows.map(b => React.createElement("div", {
    key: b.id,
    className: `bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm ${!b.is_active ? 'opacity-60' : ''}`
  }, React.createElement("div", {
    className: "flex items-start justify-between gap-3"
  }, React.createElement("div", {
    className: "flex items-start gap-3 min-w-0"
  }, React.createElement("div", {
    className: "w-14 h-14 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 flex items-center justify-center p-1.5 shrink-0"
  }, React.createElement("img", {
    src: b.logo_url,
    alt: b.name,
    className: "w-full h-full object-contain"
  })), React.createElement("div", {
    className: "min-w-0"
  }, React.createElement("h4", {
    className: "font-black text-slate-800 dark:text-slate-100 truncate"
  }, b.name), React.createElement("div", {
    className: "flex items-center gap-1.5 mt-2"
  }, React.createElement("span", {
    title: "Color primario",
    className: "w-6 h-6 rounded-full border-2 border-white shadow ring-1 ring-slate-200 dark:ring-slate-700",
    style: {
      backgroundColor: b.primary_color
    }
  }), b.secondary_color && React.createElement("span", {
    title: "Color secundario",
    className: "w-6 h-6 rounded-full border-2 border-white shadow ring-1 ring-slate-200 dark:ring-slate-700",
    style: {
      backgroundColor: b.secondary_color
    }
  })), React.createElement("p", {
    className: "text-[11px] text-slate-500 mt-2"
  }, b.companies_count, " empresa", b.companies_count === 1 ? '' : 's', " vinculadas"), !b.is_active && React.createElement("span", {
    className: "inline-block mt-1 text-[10px] font-black uppercase tracking-widest text-red-500"
  }, "Inactiva"))), React.createElement("div", {
    className: "flex flex-col gap-2 shrink-0"
  }, React.createElement("button", {
    onClick: () => setEditing(b),
    className: "text-xs font-bold text-blue-600 hover:underline"
  }, "Editar"), b.is_active && React.createElement("button", {
    onClick: () => remove(b),
    className: "text-xs font-bold text-red-500 hover:underline"
  }, "Desactivar"))))), rows.length === 0 && React.createElement(EmptyState, {
    message: "Sin marcas a\xFAn"
  })), React.createElement(Modal, {
    open: creating,
    onClose: () => setCreating(false),
    title: "Nueva marca",
    maxWidth: "max-w-4xl"
  }, React.createElement(BrandForm, {
    onSave: (b, file) => save(b, null, file),
    onCancel: () => setCreating(false)
  })), React.createElement(Modal, {
    open: !!editing,
    onClose: () => setEditing(null),
    title: "Editar marca",
    maxWidth: "max-w-4xl"
  }, editing && React.createElement(BrandForm, {
    initial: editing,
    onSave: (b, file) => save(b, editing.id, file),
    onCancel: () => setEditing(null)
  })));
};
const rgbToHex = (r, g, b) => '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');
const BrandForm = ({
  initial,
  onSave,
  onCancel
}) => {
  const [form, setForm] = useState(() => ({
    name: initial?.name || '',
    primary_color: initial?.primary_color || '#07d6da',
    secondary_color: initial?.secondary_color || '#9909fe',
    welcome_intro: initial?.welcome_intro || ''
  }));
  const [logoFile, setLogoFile] = useState(null);
  const [logoPreview, setLogoPreview] = useState(initial?.logo_url || '');
  const [submitting, setSubmitting] = useState(false);
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [extracting, setExtracting] = useState(false);
  const fileRef = useRef(null);
  const logoImgRef = useRef(null);
  const pickFile = f => {
    if (!f) return;
    if (f.size > 512 * 1024) {
      alert('El logo supera 512 KB.');
      return;
    }
    if (!['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'].includes(f.type)) {
      alert('Formato no soportado. Usa PNG, JPG, WebP o SVG.');
      return;
    }
    setLogoFile(f);
    setLogoPreview(URL.createObjectURL(f));
  };
  const extractColorsFromLogo = () => {
    if (!logoPreview) {
      alert('Sube un logo primero.');
      return;
    }
    if (typeof ColorThief === 'undefined') {
      alert('Extractor de colores no disponible.');
      return;
    }
    setExtracting(true);
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      try {
        const ct = new ColorThief();
        const palette = ct.getPalette(img, 5) || [];
        if (palette.length === 0) {
          alert('No se pudo extraer la paleta.');
          return;
        }
        const primary = rgbToHex(palette[0][0], palette[0][1], palette[0][2]);
        const secondary = palette[1] ? rgbToHex(palette[1][0], palette[1][1], palette[1][2]) : form.secondary_color;
        setForm(prev => ({
          ...prev,
          primary_color: primary,
          secondary_color: secondary
        }));
      } catch (e) {
        alert('No se pudo extraer la paleta. Si el logo es SVG, prueba con PNG o JPG.');
      } finally {
        setExtracting(false);
      }
    };
    img.onerror = () => {
      setExtracting(false);
      alert('No se pudo cargar el logo para analizar.');
    };
    img.src = logoPreview;
  };
  const submit = async e => {
    e.preventDefault();
    setSubmitting(true);
    try {
      const body = {
        name: form.name.trim(),
        primary_color: form.primary_color,
        secondary_color: form.secondary_color || null,
        welcome_intro: form.welcome_intro.trim() || null
      };
      await onSave(body, logoFile);
    } finally {
      setSubmitting(false);
    }
  };
  const previewIntro = form.welcome_intro.trim() ? form.welcome_intro.trim() : `Tu equipo está usando ${form.name || 'la plataforma'} Clockin para marcar jornada de forma sencilla. Acabas de ser invitado a sumarte.`;
  const gradient = form.secondary_color ? `linear-gradient(135deg, ${form.primary_color} 0%, ${form.secondary_color} 100%)` : form.primary_color;
  return React.createElement("form", {
    onSubmit: submit,
    className: "space-y-4"
  }, React.createElement("h3", {
    className: "text-xl font-black text-slate-800 dark:text-slate-100"
  }, initial ? 'Editar marca' : 'Nueva marca'), React.createElement("div", {
    className: "grid grid-cols-1 lg:grid-cols-2 gap-5"
  }, React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("fieldset", {
    className: "border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-3"
  }, React.createElement("legend", {
    className: "px-2 text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Identidad"), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Nombre de la marca"), React.createElement("input", {
    required: true,
    maxLength: "120",
    value: form.name,
    onChange: e => setForm({
      ...form,
      name: e.target.value
    }),
    className: "w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold"
  })), React.createElement("div", null, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Logo"), React.createElement("div", {
    className: "mt-1 flex items-center gap-3"
  }, logoPreview && React.createElement("div", {
    className: "w-16 h-16 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 flex items-center justify-center p-1.5 shrink-0"
  }, React.createElement("img", {
    ref: logoImgRef,
    src: logoPreview,
    alt: "preview",
    className: "w-full h-full object-contain"
  })), React.createElement("button", {
    type: "button",
    onClick: () => fileRef.current?.click(),
    className: "px-3 py-2 rounded-xl bg-cyan-50 dark:bg-cyan-900/30 text-melius-cyan border border-cyan-100 dark:border-cyan-900/40 font-bold text-xs"
  }, logoFile ? 'Cambiar' : logoPreview ? 'Reemplazar' : 'Seleccionar'), React.createElement("input", {
    ref: fileRef,
    type: "file",
    accept: "image/png,image/jpeg,image/webp,image/svg+xml",
    className: "hidden",
    onChange: e => pickFile(e.target.files?.[0])
  })), React.createElement("p", {
    className: "text-[10px] text-slate-400 mt-1"
  }, "PNG, JPG, WebP o SVG. M\xE1x. 512 KB."))), React.createElement("fieldset", {
    className: "border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-3"
  }, React.createElement("legend", {
    className: "px-2 text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Colores de marca"), React.createElement("div", {
    className: "grid grid-cols-2 gap-3"
  }, React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Primario"), React.createElement("div", {
    className: "mt-1 flex items-center gap-2"
  }, React.createElement("input", {
    type: "color",
    value: form.primary_color,
    onChange: e => setForm({
      ...form,
      primary_color: e.target.value
    }),
    className: "w-14 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer"
  }), React.createElement("div", {
    className: "flex-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold text-sm uppercase"
  }, form.primary_color))), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Secundario"), React.createElement("div", {
    className: "mt-1 flex items-center gap-2"
  }, React.createElement("input", {
    type: "color",
    value: form.secondary_color || '#9909fe',
    onChange: e => setForm({
      ...form,
      secondary_color: e.target.value
    }),
    className: "w-14 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer"
  }), React.createElement("div", {
    className: "flex-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-bold text-sm uppercase"
  }, form.secondary_color || '—')))), React.createElement("button", {
    type: "button",
    onClick: extractColorsFromLogo,
    disabled: !logoPreview || extracting,
    className: "w-full px-3 py-2 rounded-xl bg-gradient-to-r from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-700 border border-slate-200 dark:border-slate-600 font-bold text-xs text-slate-700 dark:text-slate-200 disabled:opacity-50 flex items-center justify-center gap-2"
  }, extracting ? 'Analizando logo...' : 'Sugerir colores desde el logo'), React.createElement("p", {
    className: "text-[10px] text-slate-400"
  }, "Detectamos los dos colores dominantes del logo. Puedes ajustarlos despu\xE9s.")), React.createElement("fieldset", {
    className: "border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-2"
  }, React.createElement("legend", {
    className: "px-2 text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Mensaje de bienvenida"), React.createElement("textarea", {
    value: form.welcome_intro,
    rows: "4",
    maxLength: "2000",
    onChange: e => setForm({
      ...form,
      welcome_intro: e.target.value
    }),
    placeholder: "Si lo dejas vac\xEDo, usamos un saludo gen\xE9rico con el nombre de la empresa.",
    className: "w-full mt-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm"
  }), React.createElement("p", {
    className: "text-[10px] text-slate-400"
  }, form.welcome_intro.length, "/2000 caracteres. Aparece en el correo de invitaci\xF3n.")), React.createElement("button", {
    type: "button",
    onClick: () => setShowAdvanced(s => !s),
    className: "text-[11px] font-bold text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 underline"
  }, showAdvanced ? 'Ocultar detalles técnicos' : 'Mostrar detalles técnicos'), showAdvanced && React.createElement("div", {
    className: "bg-slate-50 dark:bg-slate-800/50 rounded-xl p-3 space-y-2 text-xs"
  }, initial?.slug && React.createElement("div", {
    className: "flex justify-between"
  }, React.createElement("span", {
    className: "font-black uppercase tracking-widest text-slate-400 text-[10px]"
  }, "Slug"), React.createElement("span", {
    className: "font-mono text-slate-600 dark:text-slate-300"
  }, initial.slug)), React.createElement("div", {
    className: "flex justify-between"
  }, React.createElement("span", {
    className: "font-black uppercase tracking-widest text-slate-400 text-[10px]"
  }, "Hex primario"), React.createElement("input", {
    type: "text",
    value: form.primary_color,
    onChange: e => setForm({
      ...form,
      primary_color: e.target.value
    }),
    pattern: "^#[0-9a-fA-F]{3,6}$",
    required: true,
    className: "font-mono text-xs px-2 py-0.5 rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900"
  })), React.createElement("div", {
    className: "flex justify-between"
  }, React.createElement("span", {
    className: "font-black uppercase tracking-widest text-slate-400 text-[10px]"
  }, "Hex secundario"), React.createElement("input", {
    type: "text",
    value: form.secondary_color || '',
    onChange: e => setForm({
      ...form,
      secondary_color: e.target.value
    }),
    pattern: "^#[0-9a-fA-F]{3,6}$|^$",
    className: "font-mono text-xs px-2 py-0.5 rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900"
  })))), React.createElement("div", {
    className: "space-y-2"
  }, React.createElement("span", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Vista previa del correo de invitaci\xF3n"), React.createElement("div", {
    className: "rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden bg-slate-100 dark:bg-slate-900 p-3"
  }, React.createElement("div", {
    className: "rounded-xl overflow-hidden bg-white border border-slate-200",
    style: {
      fontFamily: 'Segoe UI, Arial, sans-serif'
    }
  }, React.createElement("div", {
    style: {
      background: gradient,
      color: '#fff',
      padding: '24px 20px',
      textAlign: 'center'
    }
  }, logoPreview && React.createElement("div", {
    style: {
      marginBottom: 10
    }
  }, React.createElement("img", {
    src: logoPreview,
    alt: "logo",
    style: {
      width: 56,
      height: 56,
      borderRadius: 12,
      background: '#fff',
      padding: 5,
      objectFit: 'contain',
      display: 'inline-block'
    }
  })), React.createElement("div", {
    style: {
      fontSize: 10,
      letterSpacing: '0.3em',
      textTransform: 'uppercase',
      opacity: 0.92,
      fontWeight: 700
    }
  }, (form.name || 'Marca').toUpperCase(), " CLOCKIN"), React.createElement("div", {
    style: {
      fontSize: 20,
      fontWeight: 800,
      marginTop: 8
    }
  }, "Bienvenido a bordo"), React.createElement("div", {
    style: {
      fontSize: 12,
      marginTop: 6,
      opacity: 0.95
    }
  }, "Marca jornada en segundos. Sin Excel. Sin fricci\xF3n.")), React.createElement("div", {
    style: {
      padding: '18px 20px',
      fontSize: 13,
      color: '#1f2937',
      lineHeight: 1.55
    }
  }, React.createElement("p", {
    style: {
      margin: '0 0 6px 0'
    }
  }, "Hola ", React.createElement("strong", null, "[Nombre del consultor]"), ","), React.createElement("p", {
    style: {
      margin: '0 0 6px 0',
      whiteSpace: 'pre-line'
    }
  }, previewIntro)), React.createElement("div", {
    style: {
      padding: '4px 20px 18px 20px',
      textAlign: 'center'
    }
  }, React.createElement("span", {
    style: {
      display: 'inline-block',
      padding: '10px 22px',
      background: form.primary_color,
      color: '#fff',
      borderRadius: 8,
      fontSize: 13,
      fontWeight: 800
    }
  }, "Entrar a ", form.name || 'la plataforma', " Clockin")), React.createElement("div", {
    style: {
      padding: '10px 20px',
      background: '#f8fafc',
      borderTop: '1px solid #e2e8f0',
      fontSize: 10,
      color: '#64748b',
      textAlign: 'center'
    }
  }, "Enviado por noreply@fullman.tech v\xEDa ", form.name || 'la plataforma', " Clockin."))), React.createElement("p", {
    className: "text-[10px] text-slate-400"
  }, "Aproximaci\xF3n visual. El correo real usa tablas HTML compatibles con Gmail/Outlook."))), React.createElement("div", {
    className: "flex gap-2 pt-2"
  }, React.createElement("button", {
    type: "button",
    onClick: onCancel,
    className: "flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200"
  }, "Cancelar"), React.createElement("button", {
    type: "submit",
    disabled: submitting,
    className: "flex-1 py-3 rounded-xl btn-melius font-black disabled:opacity-60 flex items-center justify-center gap-2"
  }, submitting && React.createElement(Icon, {
    name: "Spinner",
    size: 18
  }), "Guardar")));
};
const CompaniesTab = ({
  isSuper
}) => {
  const {
    push: toast
  } = useToast();
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [editing, setEditing] = useState(null);
  const [creating, setCreating] = useState(false);
  const [brandingTarget, setBrandingTarget] = useState(null);
  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const d = await apiFetch('admin/companies');
      setRows(d.companies || []);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    load();
  }, [load]);
  const save = async body => {
    try {
      if (editing) {
        await apiFetch(`admin/companies/${editing.id}`, {
          method: 'PUT',
          body
        });
        toast('success', 'Empresa actualizada.');
      } else {
        await apiFetch('admin/companies', {
          method: 'POST',
          body
        });
        toast('success', 'Empresa creada.');
      }
      setEditing(null);
      setCreating(false);
      load();
    } catch (e) {
      toast('error', e.message);
    }
  };
  const remove = async c => {
    if (!confirm(`¿Eliminar empresa "${c.name}"?`)) return;
    try {
      await apiFetch(`admin/companies/${c.id}`, {
        method: 'DELETE'
      });
      toast('success', 'Empresa eliminada.');
      load();
    } catch (e) {
      toast('error', e.message);
    }
  };
  if (loading) return React.createElement(LoadingScreen, null);
  if (error) return React.createElement(ErrorState, {
    message: error,
    onRetry: load
  });
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", {
    className: "flex justify-end"
  }, isSuper && React.createElement("button", {
    onClick: () => setCreating(true),
    className: "px-4 py-2 rounded-xl bg-blue-600 text-white font-bold text-sm hover:bg-blue-700"
  }, "+ Nueva empresa")), React.createElement("div", {
    className: "grid grid-cols-1 md:grid-cols-2 gap-3"
  }, rows.map(c => React.createElement("div", {
    key: c.id,
    className: "bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm"
  }, React.createElement("div", {
    className: "flex items-start justify-between gap-3"
  }, React.createElement("div", {
    className: "flex items-start gap-3 min-w-0"
  }, c.brand_logo_url && React.createElement("div", {
    className: "w-10 h-10 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 flex items-center justify-center p-1 shrink-0",
    title: c.brand_name
  }, React.createElement("img", {
    src: c.brand_logo_url,
    alt: c.brand_name,
    className: "w-full h-full object-contain"
  })), React.createElement("div", {
    className: "min-w-0"
  }, React.createElement("h4", {
    className: "font-black text-slate-800 dark:text-slate-100 truncate"
  }, c.name), c.brand_name && React.createElement("p", {
    className: "text-[10px] font-black uppercase tracking-widest text-melius-cyan"
  }, c.brand_name), React.createElement("p", {
    className: "text-[11px] text-slate-400 font-mono mt-1"
  }, c.timezone, " \xB7 ", c.work_start_time, "\u2013", c.work_end_time, " \xB7 gracia ", c.grace_minutes_late, "m"), React.createElement("p", {
    className: "text-[11px] text-slate-500 mt-1"
  }, "Consultores activos: ", c.active_users))), React.createElement("div", {
    className: "flex flex-col gap-1 shrink-0 items-end"
  }, React.createElement("button", {
    onClick: () => setEditing(c),
    className: "text-xs font-bold text-blue-600 hover:underline"
  }, "Editar"), React.createElement("button", {
    onClick: () => setBrandingTarget(c),
    className: "text-xs font-bold text-purple-600 hover:underline",
    title: "Sobrescribir colores y logo solo para esta empresa"
  }, "Branding"), isSuper && c.active_users === 0 && React.createElement("button", {
    onClick: () => remove(c),
    className: "text-xs font-bold text-red-500 hover:underline"
  }, "Eliminar"))))), rows.length === 0 && React.createElement(EmptyState, {
    message: "Sin empresas a\xFAn"
  })), React.createElement(Modal, {
    open: creating,
    onClose: () => setCreating(false),
    title: "Nueva empresa",
    maxWidth: "max-w-2xl"
  }, React.createElement("h3", {
    className: "text-xl font-black mb-4 text-slate-800 dark:text-slate-100"
  }, "Nueva empresa"), React.createElement(CompanyForm, {
    onSave: save,
    onCancel: () => setCreating(false)
  })), React.createElement(Modal, {
    open: !!editing,
    onClose: () => setEditing(null),
    title: "Editar empresa",
    maxWidth: "max-w-2xl"
  }, React.createElement("h3", {
    className: "text-xl font-black mb-4 text-slate-800 dark:text-slate-100"
  }, "Editar empresa"), editing && React.createElement(CompanyForm, {
    initial: editing,
    onSave: save,
    onCancel: () => setEditing(null)
  })), React.createElement(Modal, {
    open: !!brandingTarget,
    onClose: () => setBrandingTarget(null),
    title: "Branding de empresa",
    maxWidth: "max-w-3xl"
  }, brandingTarget && React.createElement(CompanyBrandingForm, {
    company: brandingTarget,
    onClose: () => setBrandingTarget(null),
    onSaved: () => {
      setBrandingTarget(null);
      load();
    }
  })));
};
const CompanyBrandingForm = ({
  company,
  onClose,
  onSaved
}) => {
  const {
    push: toast
  } = useToast();
  const {
    tenantBranding
  } = useBranding();
  const [primary, setPrimary] = useState(company.branding_primary || '');
  const [secondary, setSecondary] = useState(company.branding_secondary || '');
  const [logoUrl, setLogoUrl] = useState(company.branding_logo_url || '');
  const [submitting, setSubmitting] = useState(false);
  const inheritedPrimary = company.brand_primary || tenantBranding.primary_color;
  const inheritedSecondary = company.brand_secondary || tenantBranding.secondary_color;
  const inheritedLogo = company.brand_logo_url || tenantBranding.logo_url || '/assets/brands/melius.webp';
  const inheritedSource = company.brand_name ? `marca "${company.brand_name}"` : 'tenant';
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
          branding_logo_url: logoUrl || null
        }
      });
      toast('success', 'Branding guardado.');
      onSaved();
    } catch (e) {
      toast('error', e.message || 'Error al guardar.');
    } finally {
      setSubmitting(false);
    }
  };
  const reset = () => {
    setPrimary('');
    setSecondary('');
    setLogoUrl('');
  };
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", null, React.createElement("h3", {
    className: "text-xl font-black text-slate-800 dark:text-slate-100"
  }, "Branding de ", company.name), React.createElement("p", {
    className: "text-xs text-slate-500"
  }, "Sobrescribe el branding heredado (de ", inheritedSource, "). Deja todos los campos vac\xEDos para volver al heredado.")), React.createElement("div", {
    className: "grid grid-cols-1 lg:grid-cols-2 gap-5"
  }, React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("fieldset", {
    className: "border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-3"
  }, React.createElement("legend", {
    className: "px-2 text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Colores override"), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Primario (deja vac\xEDo = heredar ", inheritedPrimary, ")"), React.createElement("div", {
    className: "mt-1 flex items-center gap-2"
  }, React.createElement("input", {
    type: "color",
    value: primary || inheritedPrimary,
    onChange: e => setPrimary(e.target.value),
    className: "w-14 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer"
  }), React.createElement("input", {
    type: "text",
    value: primary,
    onChange: e => setPrimary(e.target.value),
    placeholder: inheritedPrimary,
    pattern: "^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$|^$",
    className: "flex-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-sm uppercase"
  }))), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Secundario (deja vac\xEDo = heredar ", inheritedSecondary || 'ninguno', ")"), React.createElement("div", {
    className: "mt-1 flex items-center gap-2"
  }, React.createElement("input", {
    type: "color",
    value: secondary || inheritedSecondary || '#9909fe',
    onChange: e => setSecondary(e.target.value),
    className: "w-14 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer"
  }), React.createElement("input", {
    type: "text",
    value: secondary,
    onChange: e => setSecondary(e.target.value),
    placeholder: inheritedSecondary || '—',
    pattern: "^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$|^$",
    className: "flex-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-sm uppercase"
  })))), React.createElement("fieldset", {
    className: "border border-slate-200 dark:border-slate-700 rounded-2xl p-4 space-y-2"
  }, React.createElement("legend", {
    className: "px-2 text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Logo override (URL)"), React.createElement("input", {
    type: "text",
    value: logoUrl,
    onChange: e => setLogoUrl(e.target.value),
    placeholder: `Heredado: ${inheritedLogo}`,
    className: "w-full px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-xs"
  }), React.createElement("p", {
    className: "text-[10px] text-slate-400"
  }, "URL absoluta o relativa al sitio. Upload de archivo por empresa se agregar\xE1 despu\xE9s. Deja vac\xEDo para heredar.")), React.createElement("button", {
    type: "button",
    onClick: reset,
    className: "text-[11px] font-bold text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 underline"
  }, "Limpiar todo y volver al branding heredado")), React.createElement("div", {
    className: "space-y-2"
  }, React.createElement("span", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Preview"), React.createElement("div", {
    className: "rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden bg-slate-100 dark:bg-slate-900 p-3 space-y-2"
  }, React.createElement("div", {
    className: "rounded-xl bg-white border border-slate-200 p-3 flex items-center gap-3"
  }, React.createElement("div", {
    className: "w-10 h-10 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center p-1 shrink-0"
  }, React.createElement("img", {
    src: effLogo,
    alt: "logo",
    className: "w-full h-full object-contain",
    onError: e => {
      e.target.src = '/assets/brands/melius.webp';
    }
  })), React.createElement("div", {
    className: "min-w-0"
  }, React.createElement("div", {
    className: "font-black text-slate-800 truncate text-sm"
  }, tenantBranding.product_name), React.createElement("div", {
    className: "text-[9px] uppercase tracking-widest text-slate-400 font-bold"
  }, company.name))), React.createElement("div", {
    className: "rounded-xl overflow-hidden"
  }, React.createElement("div", {
    style: {
      background: gradient,
      color: '#fff',
      padding: '14px 16px',
      textAlign: 'center'
    }
  }, React.createElement("div", {
    style: {
      fontSize: 10,
      letterSpacing: '0.3em',
      textTransform: 'uppercase',
      opacity: 0.92,
      fontWeight: 700
    }
  }, company.name.toUpperCase()), React.createElement("div", {
    style: {
      fontSize: 14,
      fontWeight: 800,
      marginTop: 4
    }
  }, "Vista del UI con tu branding")), React.createElement("div", {
    style: {
      padding: '10px 14px',
      background: '#fff',
      textAlign: 'center'
    }
  }, React.createElement("span", {
    style: {
      display: 'inline-block',
      padding: '7px 16px',
      background: effPrimary,
      color: '#fff',
      borderRadius: 7,
      fontSize: 11,
      fontWeight: 800
    }
  }, "Entrar")))))), React.createElement("div", {
    className: "flex gap-2 pt-2"
  }, React.createElement("button", {
    type: "button",
    onClick: onClose,
    disabled: submitting,
    className: "flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200 disabled:opacity-60"
  }, "Cancelar"), React.createElement("button", {
    type: "button",
    onClick: save,
    disabled: submitting,
    className: "flex-1 py-3 rounded-xl btn-melius font-black disabled:opacity-60 flex items-center justify-center gap-2"
  }, submitting && React.createElement(Icon, {
    name: "Spinner",
    size: 18
  }), "Guardar branding")));
};
const InviteConfirmModal = ({
  open,
  payload,
  company,
  onConfirm,
  onCancel,
  submitting
}) => {
  if (!open || !payload) return null;
  const primary = company?.brand_primary || '#07d6da';
  const secondary = company?.brand_secondary || '#9909fe';
  const gradient = `linear-gradient(135deg, ${primary} 0%, ${secondary} 100%)`;
  const brandName = company?.brand_name || 'Melius';
  const logoUrl = company?.brand_logo_url || null;
  const intro = company?.brand_welcome && company.brand_welcome.trim() ? company.brand_welcome.trim() : `Tu equipo en ${company?.name || 'tu empresa'} está usando ${brandName} Clockin para marcar jornada de forma sencilla.`;
  const roleLabel = payload.role === 'admin' ? 'Administrador' : 'Consultor';
  return React.createElement(Modal, {
    open: open,
    onClose: onCancel,
    title: "Confirmar invitaci\xF3n",
    maxWidth: "max-w-3xl"
  }, React.createElement("div", {
    className: "grid grid-cols-1 md:grid-cols-2 gap-4"
  }, React.createElement("div", {
    className: "space-y-3"
  }, React.createElement("div", {
    className: "rounded-2xl border border-slate-200 dark:border-slate-700 p-4 space-y-2"
  }, React.createElement("div", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Destinatario"), React.createElement("div", null, React.createElement("div", {
    className: "text-xs text-slate-400"
  }, "Correo"), React.createElement("div", {
    className: "font-bold text-slate-800 dark:text-slate-100 truncate"
  }, payload.email)), React.createElement("div", null, React.createElement("div", {
    className: "text-xs text-slate-400"
  }, "Nombre"), React.createElement("div", {
    className: "font-bold text-slate-800 dark:text-slate-100"
  }, payload.name)), React.createElement("div", null, React.createElement("div", {
    className: "text-xs text-slate-400"
  }, "Rol"), React.createElement("div", {
    className: "font-bold text-slate-800 dark:text-slate-100"
  }, roleLabel)), company && React.createElement("div", null, React.createElement("div", {
    className: "text-xs text-slate-400"
  }, "Empresa / Marca"), React.createElement("div", {
    className: "font-bold text-slate-800 dark:text-slate-100"
  }, company.name, company.brand_name ? ` — ${company.brand_name}` : ''))), React.createElement("p", {
    className: "text-[11px] text-slate-500"
  }, "Al confirmar se crear\xE1 la cuenta con una contrase\xF1a temporal y se enviar\xE1 el correo de invitaci\xF3n al destinatario. El usuario deber\xE1 cambiar su contrase\xF1a al ingresar.")), React.createElement("div", {
    className: "space-y-2"
  }, React.createElement("span", {
    className: "text-[11px] font-black uppercase tracking-widest text-slate-500"
  }, "Vista previa"), React.createElement("div", {
    className: "rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden bg-slate-100 dark:bg-slate-900 p-3"
  }, React.createElement("div", {
    className: "rounded-xl overflow-hidden bg-white border border-slate-200",
    style: {
      fontFamily: 'Segoe UI, Arial, sans-serif'
    }
  }, React.createElement("div", {
    style: {
      background: gradient,
      color: '#fff',
      padding: '20px 18px',
      textAlign: 'center'
    }
  }, logoUrl && React.createElement("div", {
    style: {
      marginBottom: 8
    }
  }, React.createElement("img", {
    src: logoUrl,
    alt: brandName,
    style: {
      width: 48,
      height: 48,
      borderRadius: 10,
      background: '#fff',
      padding: 4,
      objectFit: 'contain',
      display: 'inline-block'
    }
  })), React.createElement("div", {
    style: {
      fontSize: 10,
      letterSpacing: '0.3em',
      textTransform: 'uppercase',
      opacity: 0.92,
      fontWeight: 700
    }
  }, brandName.toUpperCase(), " CLOCKIN"), React.createElement("div", {
    style: {
      fontSize: 18,
      fontWeight: 800,
      marginTop: 6
    }
  }, "Bienvenido a bordo, ", payload.name.split(' ')[0])), React.createElement("div", {
    style: {
      padding: '14px 18px',
      fontSize: 12,
      color: '#1f2937',
      lineHeight: 1.55
    }
  }, React.createElement("p", {
    style: {
      margin: '0 0 6px 0'
    }
  }, "Hola ", React.createElement("strong", null, payload.name), ","), React.createElement("p", {
    style: {
      margin: '0 0 6px 0',
      whiteSpace: 'pre-line'
    }
  }, intro)), React.createElement("div", {
    style: {
      padding: '4px 18px 16px 18px',
      textAlign: 'center'
    }
  }, React.createElement("span", {
    style: {
      display: 'inline-block',
      padding: '9px 20px',
      background: primary,
      color: '#fff',
      borderRadius: 8,
      fontSize: 12,
      fontWeight: 800
    }
  }, "Entrar a ", brandName, " Clockin")))))), React.createElement("div", {
    className: "flex gap-2 pt-4"
  }, React.createElement("button", {
    type: "button",
    onClick: onCancel,
    disabled: submitting,
    className: "flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200 disabled:opacity-60"
  }, "Volver"), React.createElement("button", {
    type: "button",
    onClick: onConfirm,
    disabled: submitting,
    className: "flex-1 py-3 rounded-xl bg-blue-600 text-white font-black hover:bg-blue-700 disabled:opacity-60 flex items-center justify-center gap-2"
  }, submitting && React.createElement(Icon, {
    name: "Spinner",
    size: 18
  }), "Confirmar y enviar")));
};
const InviteForm = ({
  defaultRole,
  isSuper,
  onSave,
  onCancel
}) => {
  const [companies, setCompanies] = useState([]);
  const [form, setForm] = useState({
    email: '',
    name: '',
    role: defaultRole,
    company_id: ''
  });
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [pendingPayload, setPendingPayload] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  useEffect(() => {
    apiFetch('admin/companies').then(d => setCompanies(d.companies || [])).catch(() => {});
  }, []);
  const submit = e => {
    e.preventDefault();
    const body = {
      email: form.email.trim(),
      name: form.name.trim(),
      role: form.role
    };
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
    } finally {
      setSubmitting(false);
    }
  };
  const selectedCompany = pendingPayload?.company_id ? companies.find(c => c.id === pendingPayload.company_id) || null : null;
  return React.createElement(React.Fragment, null, React.createElement("form", {
    onSubmit: submit,
    className: "space-y-4"
  }, React.createElement("div", {
    className: "grid grid-cols-1 sm:grid-cols-2 gap-3"
  }, React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Correo"), React.createElement("input", {
    type: "email",
    required: true,
    value: form.email,
    onChange: e => setForm({
      ...form,
      email: e.target.value
    }),
    className: "w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-medium"
  })), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Nombre"), React.createElement("input", {
    required: true,
    minLength: "2",
    maxLength: "120",
    value: form.name,
    onChange: e => setForm({
      ...form,
      name: e.target.value
    }),
    className: "w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-medium"
  })), isSuper && React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Rol"), React.createElement("div", {
    className: "mt-1"
  }, React.createElement(Select, {
    value: form.role,
    onChange: e => setForm({
      ...form,
      role: e.target.value
    })
  }, React.createElement("option", {
    value: "consultant"
  }, "Consultor"), React.createElement("option", {
    value: "admin"
  }, "Administrador")))), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Empresa"), React.createElement("div", {
    className: "mt-1"
  }, React.createElement(Select, {
    value: form.company_id,
    onChange: e => setForm({
      ...form,
      company_id: e.target.value
    })
  }, React.createElement("option", {
    value: ""
  }, "\u2014 Sin empresa \u2014"), companies.map(c => React.createElement("option", {
    key: c.id,
    value: c.id
  }, c.name, c.brand_name ? ` — ${c.brand_name}` : '')))))), React.createElement("p", {
    className: "text-[11px] text-slate-500"
  }, "Se enviar\xE1 un correo con una contrase\xF1a temporal. Revisar\xE1s el resumen antes de confirmar."), React.createElement("div", {
    className: "flex gap-2"
  }, React.createElement("button", {
    type: "button",
    onClick: onCancel,
    className: "flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200"
  }, "Cancelar"), React.createElement("button", {
    type: "submit",
    className: "flex-1 py-3 rounded-xl bg-blue-600 text-white font-black hover:bg-blue-700 flex items-center justify-center gap-2"
  }, "Revisar invitaci\xF3n"))), React.createElement(InviteConfirmModal, {
    open: confirmOpen,
    payload: pendingPayload,
    company: selectedCompany,
    submitting: submitting,
    onConfirm: confirmInvite,
    onCancel: () => {
      if (!submitting) {
        setConfirmOpen(false);
        setPendingPayload(null);
      }
    }
  }));
};
const ResendInviteModal = ({
  open,
  target,
  busy,
  onConfirm,
  onCancel
}) => React.createElement(Modal, {
  open: open,
  onClose: onCancel,
  title: "Reenviar invitaci\xF3n",
  maxWidth: "max-w-lg"
}, React.createElement("div", {
  className: "flex items-start gap-3 mb-4"
}, React.createElement("div", {
  className: "shrink-0 w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-purple-500 flex items-center justify-center text-white"
}, React.createElement(Icon, {
  name: "Mail",
  size: 22
})), React.createElement("div", {
  className: "flex-1"
}, React.createElement("h3", {
  className: "text-xl font-black text-slate-800 dark:text-slate-100"
}, "Reenviar invitaci\xF3n"), React.createElement("p", {
  className: "text-sm text-slate-600 dark:text-slate-300 mt-1"
}, "Se generar\xE1 una nueva password temporal y se enviar\xE1 un correo de bienvenida actualizado."))), target && React.createElement("div", {
  className: "bg-slate-50 dark:bg-slate-800/50 p-3 rounded-xl mb-4"
}, React.createElement("p", {
  className: "font-bold text-slate-800 dark:text-slate-100"
}, target.name), React.createElement("p", {
  className: "text-xs text-slate-500 truncate"
}, target.email), React.createElement("p", {
  className: "text-xs text-slate-500 mt-1"
}, target.company_name || 'Sin empresa')), React.createElement("div", {
  className: "bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-900/40 rounded-xl p-3 mb-4"
}, React.createElement("p", {
  className: "text-xs text-amber-800 dark:text-amber-200 leading-relaxed"
}, React.createElement("strong", null, "Importante:"), " la password temporal anterior dejar\xE1 de funcionar. El usuario deber\xE1 usar la nueva que llegue por correo.")), React.createElement("div", {
  className: "flex gap-2 justify-end"
}, React.createElement("button", {
  onClick: onCancel,
  disabled: busy,
  className: "px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold text-sm disabled:opacity-60"
}, "Cancelar"), React.createElement("button", {
  onClick: onConfirm,
  disabled: busy,
  className: "px-4 py-2 rounded-xl btn-melius font-bold text-sm disabled:opacity-60 inline-flex items-center gap-2"
}, busy && React.createElement(Icon, {
  name: "Spinner",
  size: 16
}), busy ? 'Enviando...' : 'Reenviar invitación')));
const AgentsTab = ({
  isSuper
}) => {
  const {
    push: toast
  } = useToast();
  const [q, setQ] = useState('');
  const [companyId, setCompanyId] = useState('');
  const [status, setStatus] = useState('');
  const [data, setData] = useState({
    agents: [],
    total: 0
  });
  const [companies, setCompanies] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [inviting, setInviting] = useState(false);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [resending, setResending] = useState(null);
  const [resendBusy, setResendBusy] = useState(false);
  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
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
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [q, companyId, status, companies.length]);
  useEffect(() => {
    const t = setTimeout(load, 250);
    return () => clearTimeout(t);
  }, [load]);
  const invite = async body => {
    try {
      await apiFetch('admin/users/invite', {
        method: 'POST',
        body
      });
      toast('success', 'Invitación enviada.');
      setInviting(false);
      load();
    } catch (e) {
      toast('error', e.message);
    }
  };
  const toggleStatus = async a => {
    const next = a.status === 'active' ? 'disabled' : 'active';
    try {
      await apiFetch(`admin/users/${a.id}`, {
        method: 'PUT',
        body: {
          company_id: a.company_id,
          status: next
        }
      });
      toast('success', 'Consultor actualizado.');
      load();
    } catch (e) {
      toast('error', e.message);
    }
  };
  const openResend = a => {
    setResending(a);
  };
  const closeResend = () => {
    if (!resendBusy) {
      setResending(null);
    }
  };
  const doResend = async () => {
    if (!resending) return;
    setResendBusy(true);
    try {
      await apiFetch(`admin/users/${resending.id}/resend-invite`, {
        method: 'POST'
      });
      toast('success', 'Invitación reenviada con nueva password temporal.');
      setResending(null);
    } catch (e) {
      toast('error', e.message);
    } finally {
      setResendBusy(false);
    }
  };
  const promoteToAdmin = async a => {
    if (!a.company_id) {
      toast('error', 'El consultor debe tener empresa asignada antes de promoverlo a admin.');
      return;
    }
    try {
      await apiFetch(`admin/users/${a.id}`, {
        method: 'PUT',
        body: {
          company_id: a.company_id,
          status: a.status,
          role: 'admin'
        }
      });
      toast('success', 'Promovido a administrador.');
      load();
    } catch (e) {
      toast('error', e.message);
    }
  };
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", {
    className: "flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-end gap-3 bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-100 dark:border-slate-800"
  }, React.createElement("input", {
    placeholder: "Buscar por nombre o correo",
    value: q,
    onChange: e => setQ(e.target.value),
    className: "w-full sm:flex-1 sm:min-w-[200px] px-4 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 font-medium"
  }), isSuper && React.createElement(Select, {
    value: companyId,
    onChange: e => setCompanyId(e.target.value),
    size: "sm",
    className: "w-full sm:w-auto"
  }, React.createElement("option", {
    value: ""
  }, "Todas las empresas"), companies.map(c => React.createElement("option", {
    key: c.id,
    value: c.id
  }, c.name))), React.createElement(Select, {
    value: status,
    onChange: e => setStatus(e.target.value),
    size: "sm",
    className: "w-full sm:w-auto"
  }, React.createElement("option", {
    value: ""
  }, "Cualquier estado"), React.createElement("option", {
    value: "active"
  }, "Activos"), React.createElement("option", {
    value: "disabled"
  }, "Deshabilitados"), React.createElement("option", {
    value: "pending_confirmation"
  }, "Pendientes")), React.createElement("div", {
    className: "flex gap-2 w-full sm:w-auto sm:ml-auto"
  }, React.createElement("button", {
    onClick: () => setBulkOpen(true),
    className: "flex-1 sm:flex-none px-4 py-2 rounded-xl bg-cyan-50 dark:bg-cyan-900/30 text-melius-cyan border border-cyan-100 dark:border-cyan-900/40 font-bold text-sm hover:bg-cyan-100 dark:hover:bg-cyan-900/50"
  }, "Carga CSV"), React.createElement("button", {
    onClick: () => setInviting(true),
    className: "flex-1 sm:flex-none px-4 py-2 rounded-xl btn-melius font-bold text-sm"
  }, "+ Invitar"))), loading ? React.createElement(LoadingScreen, null) : error ? React.createElement(ErrorState, {
    message: error,
    onRetry: load
  }) : React.createElement("div", {
    className: "bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 divide-y dark:divide-slate-800"
  }, data.agents.map(a => React.createElement("div", {
    key: a.id,
    className: "p-4 flex flex-wrap items-center gap-2 sm:gap-3 hover:bg-slate-50 dark:hover:bg-slate-800/40"
  }, React.createElement("div", {
    className: "flex-1 min-w-0 basis-full sm:basis-auto sm:min-w-[180px]"
  }, React.createElement("p", {
    className: "font-bold text-slate-800 dark:text-slate-100 truncate"
  }, a.name, " ", React.createElement("span", {
    className: "text-[10px] font-black uppercase ml-2 px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-500"
  }, a.role)), React.createElement("p", {
    className: "text-xs text-slate-500 truncate"
  }, a.email)), React.createElement("span", {
    className: "text-xs text-slate-500 truncate max-w-[120px]"
  }, a.company_name || 'Sin empresa'), a.must_change_password ? React.createElement("span", {
    className: "px-2 py-1 rounded-full text-[10px] font-black uppercase bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300"
  }, "pendiente") : React.createElement("span", {
    className: `px-2 py-1 rounded-full text-[10px] font-black uppercase ${a.status === 'active' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'}`
  }, a.status), React.createElement("div", {
    className: "flex items-center gap-2 ml-auto"
  }, a.must_change_password && a.status === 'active' && React.createElement("button", {
    onClick: () => openResend(a),
    className: "px-3 py-1.5 rounded-lg text-xs font-bold bg-cyan-50 text-cyan-600 hover:bg-cyan-100 dark:bg-cyan-900/30 dark:text-cyan-300 inline-flex items-center gap-1.5"
  }, React.createElement(Icon, {
    name: "Mail",
    size: 14
  }), "Reenviar invitaci\xF3n"), isSuper && a.role === 'consultant' && a.company_id && a.status === 'active' && React.createElement("button", {
    onClick: () => promoteToAdmin(a),
    className: "px-3 py-1.5 rounded-lg text-xs font-bold bg-purple-50 text-purple-600 hover:bg-purple-100 dark:bg-purple-900/30 dark:text-purple-300 inline-flex items-center gap-1.5"
  }, React.createElement(Icon, {
    name: "ShieldCheck",
    size: 14
  }), "Promover a admin"), React.createElement("button", {
    onClick: () => toggleStatus(a),
    className: "text-xs font-bold text-blue-600 hover:underline"
  }, a.status === 'active' ? 'Desactivar' : 'Activar')))), data.agents.length === 0 && React.createElement(EmptyState, {
    message: "Sin resultados"
  })), React.createElement(Modal, {
    open: inviting,
    onClose: () => setInviting(false),
    title: "Invitar consultor",
    maxWidth: "max-w-2xl"
  }, React.createElement("h3", {
    className: "text-xl font-black mb-4 text-slate-800 dark:text-slate-100"
  }, "Invitar consultor"), React.createElement(InviteForm, {
    defaultRole: "consultant",
    isSuper: isSuper,
    onSave: invite,
    onCancel: () => setInviting(false)
  })), React.createElement(ResendInviteModal, {
    open: !!resending,
    target: resending,
    busy: resendBusy,
    onConfirm: doResend,
    onCancel: closeResend
  }), React.createElement(Modal, {
    open: bulkOpen,
    onClose: () => setBulkOpen(false),
    title: "Carga masiva CSV",
    maxWidth: "max-w-3xl"
  }, React.createElement(BulkInviteForm, {
    isSuper: isSuper,
    companies: companies,
    onClose: () => setBulkOpen(false),
    onDone: () => {
      setBulkOpen(false);
      load();
    }
  })));
};
const parseCsvLight = raw => {
  const text = String(raw || '').replace(/\r\n?/g, '\n').trim();
  if (!text) return {
    headers: [],
    rows: []
  };
  const lines = text.split('\n').filter(l => l.trim() !== '');
  const delim = lines[0].includes(';') && !lines[0].includes(',') ? ';' : ',';
  const splitLine = line => {
    const out = [];
    let cur = '';
    let inQ = false;
    for (let i = 0; i < line.length; i++) {
      const ch = line[i];
      if (ch === '"') {
        inQ = !inQ;
        continue;
      }
      if (ch === delim && !inQ) {
        out.push(cur);
        cur = '';
        continue;
      }
      cur += ch;
    }
    out.push(cur);
    return out.map(s => s.trim());
  };
  const headers = splitLine(lines[0]).map(h => h.toLowerCase());
  const rows = lines.slice(1).map((line, idx) => {
    const cells = splitLine(line);
    const obj = {
      _row: idx + 2
    };
    headers.forEach((h, i) => {
      obj[h] = cells[i] !== undefined ? cells[i] : '';
    });
    return obj;
  });
  return {
    headers,
    rows
  };
};
const BulkConfirmModal = ({
  open,
  parsed,
  companies,
  defaultCompanyId,
  isSuper,
  onConfirm,
  onCancel,
  submitting
}) => {
  const [activeTab, setActiveTab] = useState(0);
  useEffect(() => {
    setActiveTab(0);
  }, [open]);
  if (!open || !parsed) return null;
  const totalRows = parsed.rows.length;
  const defaultCompany = defaultCompanyId ? companies.find(c => c.id === parseInt(defaultCompanyId, 10)) : null;
  const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const seen = new Set();
  const enriched = parsed.rows.map(r => {
    const issues = [];
    const email = (r.email || '').trim().toLowerCase();
    const name = (r.name || '').trim();
    if (!email) issues.push('email vacío');else if (!emailRe.test(email)) issues.push('email inválido');else if (seen.has(email)) issues.push('email duplicado en CSV');
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
    return {
      ...r,
      _email: email,
      _name: name,
      _company: company,
      _issues: issues
    };
  });
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
        rows: []
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
  const intro = activeGroup?.brand_welcome && activeGroup.brand_welcome.trim() ? activeGroup.brand_welcome.trim() : `Tu equipo en ${activeGroup?.company.name || 'tu empresa'} está usando ${brandName} Clockin para marcar jornada de forma sencilla.`;
  return React.createElement(Modal, {
    open: open,
    onClose: onCancel,
    title: "Revisar carga masiva",
    maxWidth: "max-w-4xl"
  }, React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", {
    className: "grid grid-cols-2 md:grid-cols-4 gap-2"
  }, React.createElement("div", {
    className: "rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-900/40 p-3 text-center"
  }, React.createElement("div", {
    className: "text-2xl font-black text-emerald-700 dark:text-emerald-300"
  }, okCount), React.createElement("div", {
    className: "text-[10px] font-bold uppercase tracking-widest text-emerald-700 dark:text-emerald-300"
  }, "Listos")), React.createElement("div", {
    className: "rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-900/40 p-3 text-center"
  }, React.createElement("div", {
    className: "text-2xl font-black text-amber-700 dark:text-amber-300"
  }, issueRows.length), React.createElement("div", {
    className: "text-[10px] font-bold uppercase tracking-widest text-amber-700 dark:text-amber-300"
  }, "Con avisos")), React.createElement("div", {
    className: "rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 p-3 text-center"
  }, React.createElement("div", {
    className: "text-2xl font-black text-blue-700 dark:text-blue-300"
  }, groupList.length), React.createElement("div", {
    className: "text-[10px] font-bold uppercase tracking-widest text-blue-700 dark:text-blue-300"
  }, "Marcas")), React.createElement("div", {
    className: "rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 p-3 text-center"
  }, React.createElement("div", {
    className: "text-2xl font-black text-slate-700 dark:text-slate-200"
  }, totalRows), React.createElement("div", {
    className: "text-[10px] font-bold uppercase tracking-widest text-slate-500"
  }, "Filas totales"))), issueRows.length > 0 && React.createElement("div", {
    className: "rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50/60 dark:bg-amber-900/10 p-3 max-h-40 overflow-auto"
  }, React.createElement("p", {
    className: "text-[11px] font-black uppercase tracking-widest text-amber-700 dark:text-amber-300 mb-2"
  }, "Avisos por fila"), React.createElement("div", {
    className: "text-xs space-y-1 font-mono"
  }, issueRows.slice(0, 20).map((r, i) => React.createElement("div", {
    key: i,
    className: "text-amber-800 dark:text-amber-200"
  }, "Fila ", r._row, ": ", r._email || '(sin email)', " \u2014 ", r._issues.join(', '))), issueRows.length > 20 && React.createElement("div", {
    className: "text-amber-700 dark:text-amber-400 italic"
  }, "... y ", issueRows.length - 20, " m\xE1s. Estas filas se enviar\xE1n de todas formas; el reporte final mostrar\xE1 el detalle."))), groupList.length > 0 && React.createElement("div", null, React.createElement("div", {
    className: "flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-700"
  }, groupList.map((g, i) => React.createElement("button", {
    key: i,
    type: "button",
    onClick: () => setActiveTab(i),
    className: `px-3 py-2 text-xs font-bold border-b-2 transition-all ${activeTab === i ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'}`
  }, g.brand_name, " ", React.createElement("span", {
    className: "opacity-60"
  }, "(", g.rows.length, ")")))), activeGroup && sampleRow && React.createElement("div", {
    className: "mt-3 rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-900 p-3"
  }, React.createElement("div", {
    className: "rounded-xl overflow-hidden bg-white border border-slate-200",
    style: {
      fontFamily: 'Segoe UI, Arial, sans-serif'
    }
  }, React.createElement("div", {
    style: {
      background: gradient,
      color: '#fff',
      padding: '18px 16px',
      textAlign: 'center'
    }
  }, activeGroup.brand_logo_url && React.createElement("div", {
    style: {
      marginBottom: 8
    }
  }, React.createElement("img", {
    src: activeGroup.brand_logo_url,
    alt: brandName,
    style: {
      width: 44,
      height: 44,
      borderRadius: 10,
      background: '#fff',
      padding: 4,
      objectFit: 'contain',
      display: 'inline-block'
    }
  })), React.createElement("div", {
    style: {
      fontSize: 9,
      letterSpacing: '0.3em',
      textTransform: 'uppercase',
      opacity: 0.92,
      fontWeight: 700
    }
  }, brandName.toUpperCase(), " CLOCKIN"), React.createElement("div", {
    style: {
      fontSize: 16,
      fontWeight: 800,
      marginTop: 6
    }
  }, "Bienvenido a bordo, ", sampleRow._name.split(' ')[0] || 'Nombre')), React.createElement("div", {
    style: {
      padding: '12px 16px',
      fontSize: 11,
      color: '#1f2937',
      lineHeight: 1.5
    }
  }, React.createElement("p", {
    style: {
      margin: '0 0 4px 0'
    }
  }, "Hola ", React.createElement("strong", null, sampleRow._name || '[Nombre]'), ","), React.createElement("p", {
    style: {
      margin: '0 0 6px 0',
      whiteSpace: 'pre-line'
    }
  }, intro)), React.createElement("div", {
    style: {
      padding: '2px 16px 12px 16px',
      textAlign: 'center'
    }
  }, React.createElement("span", {
    style: {
      display: 'inline-block',
      padding: '7px 16px',
      background: primary,
      color: '#fff',
      borderRadius: 8,
      fontSize: 11,
      fontWeight: 800
    }
  }, "Entrar a ", brandName, " Clockin"))), React.createElement("p", {
    className: "text-[10px] text-slate-400 mt-2"
  }, "Preview con datos de la primera fila de esta marca. Cada consultor recibir\xE1 su propio correo personalizado."))), React.createElement("p", {
    className: "text-[11px] text-slate-500"
  }, "Al confirmar se crear\xE1n ", okCount, " cuentas con contrase\xF1a temporal. Las ", issueRows.length > 0 ? `${issueRows.length} filas con avisos se enviarán también al servidor y` : 'filas', " aparecer\xE1n en el reporte final si fallan."), React.createElement("div", {
    className: "flex gap-2 pt-2"
  }, React.createElement("button", {
    type: "button",
    onClick: onCancel,
    disabled: submitting,
    className: "flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200 disabled:opacity-60"
  }, "Volver"), React.createElement("button", {
    type: "button",
    onClick: onConfirm,
    disabled: submitting || totalRows === 0,
    className: "flex-1 py-3 rounded-xl btn-melius font-black disabled:opacity-60 flex items-center justify-center gap-2"
  }, submitting && React.createElement(Icon, {
    name: "Spinner",
    size: 18
  }), "Confirmar y procesar"))));
};
const BulkInviteForm = ({
  isSuper,
  companies,
  onClose,
  onDone
}) => {
  const {
    push: toast
  } = useToast();
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
      const res = await fetch(`${API_BASE}/admin/users/template.csv`, {
        credentials: 'same-origin'
      });
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
    } catch (e) {
      toast('error', 'No se pudo descargar la plantilla.');
    }
  };
  const readFile = file => {
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
      toast('error', 'CSV supera 2 MB.');
      return;
    }
    const reader = new FileReader();
    reader.onload = () => setCsv(String(reader.result || ''));
    reader.readAsText(file, 'utf-8');
  };
  const onDrop = e => {
    e.preventDefault();
    setDragOver(false);
    const f = e.dataTransfer?.files?.[0];
    if (f) readFile(f);
  };
  const openConfirm = () => {
    if (!csv.trim()) {
      toast('error', 'Pega o carga un CSV primero.');
      return;
    }
    const p = parseCsvLight(csv);
    if (p.rows.length === 0) {
      toast('error', 'El CSV no contiene filas válidas.');
      return;
    }
    setParsed(p);
    setConfirmOpen(true);
  };
  const submit = async () => {
    setSubmitting(true);
    setReport(null);
    try {
      const body = {
        csv
      };
      if (defaultCompanyId) body.default_company_id = parseInt(defaultCompanyId, 10);
      const r = await apiFetch('admin/users/bulk-invite', {
        method: 'POST',
        body
      });
      setReport(r);
      setConfirmOpen(false);
      if ((r.summary?.created || 0) > 0) {
        toast('success', `${r.summary.created} consultores creados.`);
      } else {
        toast('error', 'Ningún consultor fue creado. Revisa el reporte.');
      }
    } catch (e) {
      toast('error', e.message || 'Error en carga masiva.');
    } finally {
      setSubmitting(false);
    }
  };
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("h3", {
    className: "text-xl font-black text-slate-800 dark:text-slate-100"
  }, "Carga masiva de consultores"), React.createElement("div", {
    className: "rounded-2xl bg-cyan-50 dark:bg-cyan-900/20 border border-cyan-100 dark:border-cyan-900/40 p-4 text-sm text-slate-700 dark:text-slate-200 space-y-3"
  }, React.createElement("div", null, React.createElement("p", {
    className: "font-black text-melius-cyan mb-1"
  }, "Como funciona en 3 pasos"), React.createElement("ol", {
    className: "list-decimal list-inside space-y-1 text-xs leading-relaxed"
  }, React.createElement("li", null, React.createElement("strong", null, "Descarga la plantilla."), " Trae las columnas correctas y dos filas de ejemplo (puedes borrarlas)."), React.createElement("li", null, React.createElement("strong", null, "Llena una fila por consultor"), " en Excel o Google Sheets. Guarda como CSV."), React.createElement("li", null, React.createElement("strong", null, "Sube o pega el archivo"), " aqu\xED y dale Procesar. Cada consultor recibir\xE1 un correo con su contrase\xF1a temporal."))), React.createElement("button", {
    onClick: downloadTemplate,
    className: "inline-flex items-center gap-2 px-4 py-2 rounded-xl btn-melius font-bold text-sm"
  }, "Descargar plantilla")), React.createElement("div", {
    className: "rounded-xl border border-slate-100 dark:border-slate-700 p-4 text-xs text-slate-600 dark:text-slate-300 space-y-1.5 bg-white dark:bg-slate-900"
  }, React.createElement("p", {
    className: "font-black text-slate-700 dark:text-slate-200 mb-1"
  }, "Columnas del CSV"), React.createElement("p", null, React.createElement("code", {
    className: "font-mono text-melius-cyan font-bold"
  }, "email"), " \u2014 correo del consultor."), React.createElement("p", null, React.createElement("code", {
    className: "font-mono text-melius-cyan font-bold"
  }, "name"), " \u2014 nombre completo (m\xEDnimo 2 caracteres)."), React.createElement("p", null, React.createElement("code", {
    className: "font-mono text-melius-cyan font-bold"
  }, "role"), " \u2014 escribe ", React.createElement("code", {
    className: "font-mono"
  }, "consultant"), isSuper ? React.createElement("span", null, " (o ", React.createElement("code", {
    className: "font-mono"
  }, "admin"), " si quieres dar de alta administradores).") : React.createElement("span", null, ".")), isSuper && React.createElement("p", null, React.createElement("code", {
    className: "font-mono text-melius-cyan font-bold"
  }, "company"), " \u2014 nombre de la empresa exactamente como aparece en el sistema (ej. ", React.createElement("code", {
    className: "font-mono"
  }, "Coppel"), ", ", React.createElement("code", {
    className: "font-mono"
  }, "Hyatt"), "). Puede ir vac\xEDo si seleccionas una empresa default abajo."), !isSuper && React.createElement("p", {
    className: "text-slate-500"
  }, "Todos los consultores quedar\xE1n asignados a tu empresa autom\xE1ticamente \u2014 no necesitas la columna ", React.createElement("code", {
    className: "font-mono"
  }, "company"), "."), React.createElement("p", {
    className: "text-slate-500 pt-1"
  }, "M\xE1ximo 500 filas por carga. El archivo se valida fila por fila; si alguna falla, las dem\xE1s se procesan igual.")), isSuper && React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "Empresa default (se aplica a filas con la columna company vac\xEDa)"), React.createElement("div", {
    className: "mt-1"
  }, React.createElement(Select, {
    value: defaultCompanyId,
    onChange: e => setDefaultCompanyId(e.target.value)
  }, React.createElement("option", {
    value: ""
  }, "\u2014 Sin default (cada fila debe traer company) \u2014"), companies.map(c => React.createElement("option", {
    key: c.id,
    value: c.id
  }, c.name))))), React.createElement("div", {
    onDragOver: e => {
      e.preventDefault();
      setDragOver(true);
    },
    onDragLeave: () => setDragOver(false),
    onDrop: onDrop,
    className: `rounded-2xl border-2 border-dashed p-6 text-center transition-all ${dragOver ? 'border-melius-cyan bg-cyan-50 dark:bg-cyan-900/20' : 'border-slate-200 dark:border-slate-700'}`
  }, React.createElement("p", {
    className: "text-sm font-bold text-slate-600 dark:text-slate-300"
  }, "Arrastra tu CSV aqu\xED o"), React.createElement("button", {
    onClick: () => fileInputRef.current?.click(),
    className: "mt-2 px-4 py-2 rounded-xl btn-melius font-bold text-sm"
  }, "Selecciona archivo"), React.createElement("input", {
    ref: fileInputRef,
    type: "file",
    accept: ".csv,text/csv",
    className: "hidden",
    onChange: e => readFile(e.target.files?.[0])
  })), React.createElement("label", {
    className: "block"
  }, React.createElement("span", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
  }, "O pega el CSV directo"), React.createElement("textarea", {
    value: csv,
    onChange: e => setCsv(e.target.value),
    rows: "6",
    spellCheck: "false",
    placeholder: isSuper ? "email,name,role,company\nana@empresa.com,Ana Gomez,consultant,Coppel" : "email,name,role\nana@empresa.com,Ana Gomez,consultant",
    className: "w-full mt-1 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-xs"
  })), report && React.createElement("div", {
    className: "space-y-2 rounded-xl border border-slate-100 dark:border-slate-700 p-4 bg-white dark:bg-slate-900"
  }, React.createElement("div", {
    className: "flex gap-3 text-xs font-bold flex-wrap"
  }, React.createElement("span", {
    className: "px-3 py-1 rounded-full bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300"
  }, "Creados: ", report.summary.created), React.createElement("span", {
    className: "px-3 py-1 rounded-full bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300"
  }, "Errores: ", report.summary.failed), React.createElement("span", {
    className: "px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300"
  }, "Omitidos: ", report.summary.skipped)), (report.failed?.length > 0 || report.skipped?.length > 0) && React.createElement("div", {
    className: "max-h-40 overflow-auto text-xs space-y-1 font-mono"
  }, report.failed?.map((f, i) => React.createElement("div", {
    key: `f${i}`,
    className: "text-red-600 dark:text-red-300"
  }, "Fila ", f.row, ": ", f.email || '(sin email)', " \u2014 ", f.reason)), report.skipped?.map((s, i) => React.createElement("div", {
    key: `s${i}`,
    className: "text-amber-600 dark:text-amber-300"
  }, "Fila ", s.row, ": ", s.email, " \u2014 ", s.reason)))), React.createElement("div", {
    className: "flex gap-2 pt-2"
  }, React.createElement("button", {
    onClick: onClose,
    className: "flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-slate-600 dark:text-slate-200"
  }, "Cerrar"), report?.summary?.created > 0 && React.createElement("button", {
    onClick: onDone,
    className: "flex-1 py-3 rounded-xl bg-emerald-600 text-white font-black hover:bg-emerald-700"
  }, "Listo, recargar lista"), React.createElement("button", {
    onClick: openConfirm,
    disabled: submitting || !csv.trim(),
    className: "flex-1 py-3 rounded-xl btn-melius font-black disabled:opacity-60 flex items-center justify-center gap-2"
  }, submitting && React.createElement(Icon, {
    name: "Spinner",
    size: 18
  }), "Revisar y procesar")), React.createElement(BulkConfirmModal, {
    open: confirmOpen,
    parsed: parsed,
    companies: companies,
    defaultCompanyId: defaultCompanyId,
    isSuper: isSuper,
    submitting: submitting,
    onConfirm: submit,
    onCancel: () => {
      if (!submitting) {
        setConfirmOpen(false);
      }
    }
  }));
};
const AdminsTab = ({
  currentUser,
  isSuper
}) => {
  const {
    push: toast
  } = useToast();
  const [data, setData] = useState({
    agents: []
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [inviting, setInviting] = useState(false);
  const [deleting, setDeleting] = useState(null);
  const [confirmEmail, setConfirmEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [resending, setResending] = useState(null);
  const [resendBusy, setResendBusy] = useState(false);
  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const d = await apiFetch('admin/users');
      setData({
        agents: (d.users || []).filter(u => u.role === 'admin' || u.role === 'super_admin')
      });
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    load();
  }, [load]);
  const invite = async body => {
    try {
      await apiFetch('admin/users/invite', {
        method: 'POST',
        body: {
          ...body,
          role: 'admin'
        }
      });
      toast('success', 'Admin invitado.');
      setInviting(false);
      load();
    } catch (e) {
      toast('error', e.message);
    }
  };
  const openResend = a => {
    setResending(a);
  };
  const closeResend = () => {
    if (!resendBusy) {
      setResending(null);
    }
  };
  const doResend = async () => {
    if (!resending) return;
    setResendBusy(true);
    try {
      await apiFetch(`admin/users/${resending.id}/resend-invite`, {
        method: 'POST'
      });
      toast('success', 'Invitación reenviada con nueva password temporal.');
      setResending(null);
    } catch (e) {
      toast('error', e.message);
    } finally {
      setResendBusy(false);
    }
  };
  const openDelete = admin => {
    setDeleting(admin);
    setConfirmEmail('');
  };
  const closeDelete = () => {
    setDeleting(null);
    setConfirmEmail('');
    setBusy(false);
  };
  const canConfirmDelete = deleting && confirmEmail.trim().toLowerCase() === deleting.email.toLowerCase();
  const doDelete = async () => {
    if (!deleting || !canConfirmDelete) return;
    setBusy(true);
    try {
      const res = await apiFetch(`admin/users/${deleting.id}`, {
        method: 'DELETE',
        body: {
          email_confirmation: confirmEmail.trim()
        }
      });
      toast('success', res?.message || 'Administrador procesado.');
      closeDelete();
      load();
    } catch (e) {
      toast('error', e.message);
      setBusy(false);
    }
  };
  const demoteToConsultant = async a => {
    try {
      await apiFetch(`admin/users/${a.id}`, {
        method: 'PUT',
        body: {
          company_id: a.company_id,
          status: a.status,
          role: 'consultant'
        }
      });
      toast('success', 'Bajado a consultor.');
      load();
    } catch (e) {
      toast('error', e.message);
    }
  };
  if (loading) return React.createElement(LoadingScreen, null);
  if (error) return React.createElement(ErrorState, {
    message: error,
    onRetry: load
  });
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", {
    className: "flex justify-end"
  }, React.createElement("button", {
    onClick: () => setInviting(true),
    className: "px-4 py-2 rounded-xl bg-blue-600 text-white font-bold text-sm hover:bg-blue-700"
  }, "+ Invitar admin")), React.createElement("div", {
    className: "bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 divide-y dark:divide-slate-800"
  }, data.agents.map(a => {
    const isSelf = a.id === currentUser.id;
    const isTargetSuper = a.role === 'super_admin';
    const canDelete = !isSelf && !isTargetSuper && a.status === 'active';
    return React.createElement("div", {
      key: a.id,
      className: "p-4 flex flex-wrap items-center gap-2 sm:gap-3"
    }, React.createElement("div", {
      className: "flex-1 min-w-0 basis-full sm:basis-auto sm:min-w-[180px]"
    }, React.createElement("p", {
      className: "font-bold text-slate-800 dark:text-slate-100 truncate"
    }, a.name, " ", React.createElement("span", {
      className: "text-[10px] font-black uppercase ml-2 px-2 py-0.5 rounded-full bg-purple-50 text-purple-600 dark:bg-purple-900/30 dark:text-purple-300"
    }, a.role), isSelf && React.createElement("span", {
      className: "text-[10px] font-black uppercase ml-2 px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300"
    }, "tu")), React.createElement("p", {
      className: "text-xs text-slate-500 truncate"
    }, a.email)), React.createElement("span", {
      className: "text-xs text-slate-500 truncate max-w-[120px]"
    }, a.company_name || 'Sin empresa'), a.must_change_password ? React.createElement("span", {
      className: "px-2 py-1 rounded-full text-[10px] font-black uppercase bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300"
    }, "pendiente") : React.createElement("span", {
      className: `px-2 py-1 rounded-full text-[10px] font-black uppercase ${a.status === 'active' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'}`
    }, a.status), a.must_change_password && a.status === 'active' && !isTargetSuper && React.createElement("button", {
      onClick: () => openResend(a),
      className: "px-3 py-1.5 rounded-lg text-xs font-bold bg-cyan-50 text-cyan-600 hover:bg-cyan-100 dark:bg-cyan-900/30 dark:text-cyan-300 inline-flex items-center gap-1.5"
    }, React.createElement(Icon, {
      name: "Mail",
      size: 14
    }), "Reenviar invitaci\xF3n"), isSuper && !isSelf && !isTargetSuper && a.status === 'active' && a.company_id && React.createElement("button", {
      onClick: () => demoteToConsultant(a),
      className: "px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 inline-flex items-center gap-1.5"
    }, React.createElement(Icon, {
      name: "ArrowDown",
      size: 14
    }), "Bajar a consultor"), canDelete && React.createElement("button", {
      onClick: () => openDelete(a),
      className: "px-3 py-1.5 rounded-lg text-xs font-bold bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-900/30 dark:text-red-300"
    }, a.company_id ? 'Eliminar' : 'Desactivar'));
  }), data.agents.length === 0 && React.createElement(EmptyState, {
    message: "Sin administradores a\xFAn"
  })), React.createElement(Modal, {
    open: inviting,
    onClose: () => setInviting(false),
    title: "Invitar administrador",
    maxWidth: "max-w-2xl"
  }, React.createElement("h3", {
    className: "text-xl font-black mb-4 text-slate-800 dark:text-slate-100"
  }, "Invitar administrador"), React.createElement(InviteForm, {
    defaultRole: "admin",
    isSuper: isSuper,
    onSave: invite,
    onCancel: () => setInviting(false)
  })), React.createElement(Modal, {
    open: !!deleting,
    onClose: closeDelete,
    title: deleting?.company_id ? 'Eliminar administrador' : 'Desactivar administrador',
    maxWidth: "max-w-lg"
  }, React.createElement("h3", {
    className: "text-xl font-black mb-2 text-slate-800 dark:text-slate-100"
  }, deleting?.company_id ? 'Eliminar administrador' : 'Desactivar administrador'), deleting?.company_id ? React.createElement("p", {
    className: "text-sm text-slate-600 dark:text-slate-300 mb-4"
  }, "Como el administrador tiene ", React.createElement("strong", null, deleting.company_name), " asignada, lo convertimos en ", React.createElement("strong", null, "consultor"), " de esa empresa. Conserva acceso para marcar jornada pero pierde sus permisos administrativos. Su hist\xF3rico queda intacto.") : React.createElement("p", {
    className: "text-sm text-slate-600 dark:text-slate-300 mb-4"
  }, "Esta acci\xF3n desactiva la cuenta del administrador (no tiene empresa asignada). Se env\xEDa un aviso al afectado y un recibo a tu correo. La cuenta se puede reactivar despu\xE9s."), deleting && React.createElement("div", {
    className: "bg-slate-50 dark:bg-slate-800/50 p-3 rounded-xl mb-4 text-sm"
  }, React.createElement("p", {
    className: "font-bold text-slate-800 dark:text-slate-100"
  }, deleting.name), React.createElement("p", {
    className: "text-xs text-slate-500"
  }, deleting.email), React.createElement("p", {
    className: "text-xs text-slate-500 mt-1"
  }, deleting.company_name || 'Sin empresa')), React.createElement("label", {
    className: "block text-xs font-black uppercase tracking-widest text-slate-500 mb-2"
  }, "Para confirmar, escribe el email del administrador"), React.createElement("input", {
    type: "email",
    value: confirmEmail,
    onChange: e => setConfirmEmail(e.target.value),
    placeholder: deleting?.email || '',
    autoComplete: "off",
    className: "w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm font-mono"
  }), React.createElement("div", {
    className: "flex gap-2 mt-4 justify-end"
  }, React.createElement("button", {
    onClick: closeDelete,
    disabled: busy,
    className: "px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold text-sm"
  }, "Cancelar"), React.createElement("button", {
    onClick: doDelete,
    disabled: !canConfirmDelete || busy,
    className: `px-4 py-2 rounded-xl font-bold text-sm text-white ${canConfirmDelete && !busy ? deleting?.company_id ? 'bg-amber-600 hover:bg-amber-700' : 'bg-red-600 hover:bg-red-700' : 'bg-slate-300 dark:bg-slate-700 cursor-not-allowed'}`
  }, busy ? 'Procesando...' : deleting?.company_id ? 'Convertir en consultor' : 'Desactivar'))), React.createElement(ResendInviteModal, {
    open: !!resending,
    target: resending,
    busy: resendBusy,
    onConfirm: doResend,
    onCancel: closeResend
  }));
};
const ChangesTab = ({
  onChange
}) => {
  const {
    push: toast
  } = useToast();
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const d = await apiFetch('admin/change-requests');
      setItems(d.requests || []);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    load();
  }, [load]);
  const decide = async (id, decision) => {
    try {
      await apiFetch('admin/decide', {
        method: 'POST',
        body: {
          type: 'change',
          id,
          decision
        }
      });
      toast('success', `Solicitud ${decision === 'approve' ? 'aprobada' : 'rechazada'}.`);
      load();
      onChange?.();
    } catch (e) {
      toast('error', e.message);
    }
  };
  if (loading) return React.createElement(LoadingScreen, null);
  if (error) return React.createElement(ErrorState, {
    message: error,
    onRetry: load
  });
  return React.createElement("div", {
    className: "grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6"
  }, items.map(req => React.createElement("div", {
    key: req.id,
    className: "bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm flex flex-col gap-4"
  }, React.createElement("div", null, React.createElement("h4", {
    className: "font-bold text-base text-slate-800 dark:text-slate-100"
  }, req.user_name), React.createElement("p", {
    className: "text-[10px] text-slate-400 font-black uppercase tracking-widest"
  }, "Cambio de empresa")), React.createElement("div", {
    className: "flex items-center gap-3 bg-slate-50 dark:bg-slate-800/60 p-3 rounded-2xl"
  }, React.createElement("div", {
    className: "text-center flex-1"
  }, React.createElement("p", {
    className: "text-[9px] text-slate-400 font-black uppercase"
  }, "Actual"), React.createElement("p", {
    className: "font-bold text-red-400 text-xs"
  }, req.old_company_name || '—')), React.createElement(Icon, {
    name: "ArrowLeftRight",
    className: "text-slate-300",
    size: 16
  }), React.createElement("div", {
    className: "text-center flex-1"
  }, React.createElement("p", {
    className: "text-[9px] text-slate-400 font-black uppercase"
  }, "Nueva"), React.createElement("p", {
    className: "font-bold text-emerald-500 text-xs"
  }, req.new_company_name))), React.createElement("div", {
    className: "flex gap-2"
  }, React.createElement("button", {
    onClick: () => decide(req.id, 'approve'),
    className: "flex-1 bg-emerald-500 text-white py-2.5 rounded-xl font-bold text-sm hover:bg-emerald-600 flex items-center justify-center gap-2"
  }, React.createElement(Icon, {
    name: "Check",
    size: 16
  }), " Aprobar"), React.createElement("button", {
    onClick: () => decide(req.id, 'reject'),
    className: "flex-1 bg-red-50 dark:bg-red-900/30 text-red-500 dark:text-red-300 py-2.5 rounded-xl font-bold text-sm hover:bg-red-100 flex items-center justify-center gap-2"
  }, React.createElement(Icon, {
    name: "X",
    size: 16
  }), " Rechazar")))), items.length === 0 && React.createElement(EmptyState, {
    message: "No hay solicitudes de cambio pendientes"
  }));
};
const VacationsTab = ({
  onChange
}) => {
  const {
    push: toast
  } = useToast();
  const [items, setItems] = useState([]);
  const [status, setStatus] = useState('pending');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busyId, setBusyId] = useState(null);
  const [notes, setNotes] = useState({});
  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const d = await apiFetch(`admin/vacations?status=${status}`);
      setItems(d.requests || []);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [status]);
  useEffect(() => {
    load();
  }, [load]);
  const decide = async (id, decision) => {
    if (busyId) return;
    setBusyId(id);
    try {
      await apiFetch(`admin/vacations/${id}/decide`, {
        method: 'POST',
        body: {
          decision,
          note: notes[id] || ''
        }
      });
      toast('success', decision === 'approved' ? 'Solicitud aprobada.' : 'Solicitud rechazada.');
      load();
      onChange?.();
    } catch (e) {
      toast('error', e.message);
    } finally {
      setBusyId(null);
    }
  };
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", {
    className: "flex flex-wrap gap-2 items-center"
  }, ['pending', 'approved', 'rejected', 'cancelled'].map(s => React.createElement("button", {
    key: s,
    onClick: () => setStatus(s),
    className: `px-3 py-2 rounded-xl text-xs font-black uppercase tracking-widest transition-colors min-h-[40px] ${status === s ? 'bg-emerald-500 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'}`
  }, s === 'pending' ? 'Pendientes' : s === 'approved' ? 'Aprobadas' : s === 'rejected' ? 'Rechazadas' : 'Canceladas'))), loading && React.createElement(LoadingScreen, null), error && React.createElement(ErrorState, {
    message: error,
    onRetry: load
  }), !loading && !error && items.length === 0 && React.createElement(EmptyState, {
    message: `Sin solicitudes ${status === 'pending' ? 'pendientes' : status}.`
  }), !loading && !error && items.length > 0 && React.createElement("div", {
    className: "grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4"
  }, items.map(req => React.createElement("div", {
    key: req.id,
    className: "bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm flex flex-col gap-3"
  }, React.createElement("div", {
    className: "flex items-start justify-between gap-3"
  }, React.createElement("div", {
    className: "min-w-0"
  }, React.createElement("h4", {
    className: "font-bold text-base text-slate-800 dark:text-slate-100 truncate"
  }, req.user_name), React.createElement("p", {
    className: "text-xs text-slate-400 truncate"
  }, req.user_email), req.company_name && React.createElement("p", {
    className: "text-[10px] font-black uppercase tracking-widest text-slate-400 mt-1"
  }, req.company_name)), React.createElement("div", {
    className: "bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 px-2 py-1 rounded-lg text-xs font-black whitespace-nowrap"
  }, req.days, " d\xEDa", req.days === 1 ? '' : 's')), React.createElement("div", {
    className: "bg-slate-50 dark:bg-slate-800/60 p-3 rounded-xl text-sm"
  }, React.createElement("p", {
    className: "font-bold text-slate-700 dark:text-slate-200"
  }, "Del ", React.createElement("span", {
    className: "font-mono"
  }, req.start_date)), React.createElement("p", {
    className: "font-bold text-slate-700 dark:text-slate-200"
  }, "Al ", React.createElement("span", {
    className: "font-mono"
  }, req.end_date)), req.reason && React.createElement("p", {
    className: "text-xs text-slate-500 mt-2 italic break-words"
  }, "\"", req.reason, "\"")), status === 'pending' ? React.createElement(React.Fragment, null, React.createElement("input", {
    type: "text",
    placeholder: "Nota (opcional)",
    maxLength: "500",
    value: notes[req.id] || '',
    onChange: e => setNotes({
      ...notes,
      [req.id]: e.target.value
    }),
    className: "w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm"
  }), React.createElement("div", {
    className: "flex gap-2"
  }, React.createElement("button", {
    onClick: () => decide(req.id, 'approved'),
    disabled: busyId === req.id,
    className: "flex-1 bg-emerald-500 text-white py-2.5 rounded-xl font-bold text-sm hover:bg-emerald-600 flex items-center justify-center gap-2 disabled:opacity-60 min-h-[44px]"
  }, React.createElement(Icon, {
    name: "Check",
    size: 16
  }), " Aprobar"), React.createElement("button", {
    onClick: () => decide(req.id, 'rejected'),
    disabled: busyId === req.id,
    className: "flex-1 bg-red-50 dark:bg-red-900/30 text-red-500 dark:text-red-300 py-2.5 rounded-xl font-bold text-sm hover:bg-red-100 flex items-center justify-center gap-2 disabled:opacity-60 min-h-[44px]"
  }, React.createElement(Icon, {
    name: "X",
    size: 16
  }), " Rechazar"))) : React.createElement("div", {
    className: "text-xs text-slate-400"
  }, req.decided_at && React.createElement("p", null, "Decidido: ", req.decided_at), req.decision_note && React.createElement("p", {
    className: "italic mt-1"
  }, "\"", req.decision_note, "\""))))));
};
const OvertimeTab = VacationsTab;
const LocationAlertsTab = ({
  onChange
}) => {
  const {
    push: toast
  } = useToast();
  const [items, setItems] = useState([]);
  const [statusFilter, setStatusFilter] = useState('pending');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busyId, setBusyId] = useState(null);
  const [notes, setNotes] = useState({});
  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const d = await apiFetch(`admin/location-alerts?status=${statusFilter}`);
      setItems(d.alerts || []);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [statusFilter]);
  useEffect(() => {
    load();
  }, [load]);
  const review = async (id, decision) => {
    if (busyId) return;
    setBusyId(id);
    try {
      await apiFetch(`admin/location-alerts/${id}/review`, {
        method: 'POST',
        body: {
          decision,
          notes: notes[id] || ''
        }
      });
      toast('success', decision === 'reviewed' ? 'Alerta marcada como revisada.' : 'Alerta descartada.');
      load();
      onChange?.();
    } catch (e) {
      toast('error', e.message);
    } finally {
      setBusyId(null);
    }
  };
  const REASON_LABEL = {
    NEW_COUNTRY: 'Pais nuevo',
    IMPOSSIBLE_SPEED: 'Velocidad imposible',
    FAR_FROM_HISTORY: 'Lejos del historial'
  };
  if (loading) return React.createElement(LoadingScreen, null);
  if (error) return React.createElement(ErrorState, {
    message: error,
    onRetry: load
  });
  return React.createElement("div", {
    className: "space-y-4"
  }, React.createElement("div", {
    className: "flex items-center gap-2 flex-wrap"
  }, React.createElement("h3", {
    className: "font-black uppercase tracking-widest text-xs text-slate-500 mr-auto"
  }, "Alertas de cambio radical de ubicacion (", items.length, ")"), ['pending', 'reviewed', 'dismissed'].map(s => React.createElement("button", {
    key: s,
    onClick: () => setStatusFilter(s),
    className: `px-3 py-1.5 rounded-xl text-xs font-bold uppercase tracking-wider transition-all ${statusFilter === s ? 'btn-melius shadow-sm' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-700 dark:hover:text-slate-100'}`
  }, s === 'pending' ? 'Pendientes' : s === 'reviewed' ? 'Revisadas' : 'Descartadas'))), items.length === 0 && React.createElement(EmptyState, {
    message: `Sin alertas ${statusFilter === 'pending' ? 'pendientes' : statusFilter === 'reviewed' ? 'revisadas' : 'descartadas'}.`
  }), React.createElement("div", {
    className: "grid grid-cols-1 lg:grid-cols-2 gap-4"
  }, items.map(a => {
    const reasons = (a.reason_codes || '').split(',').filter(Boolean);
    return React.createElement("div", {
      key: a.id,
      className: "bg-white dark:bg-slate-900 p-5 rounded-2xl border border-red-200 dark:border-red-900/40 shadow-sm flex flex-col gap-3"
    }, React.createElement("div", {
      className: "flex items-start justify-between gap-3"
    }, React.createElement("div", {
      className: "min-w-0"
    }, React.createElement("h4", {
      className: "font-bold text-base text-slate-800 dark:text-slate-100 truncate"
    }, a.user_name), React.createElement("p", {
      className: "text-[10px] text-slate-400 font-black uppercase tracking-widest truncate"
    }, a.user_email), React.createElement("p", {
      className: "text-[10px] text-slate-400 font-black uppercase tracking-widest"
    }, a.company_name || '— sin empresa')), React.createElement("span", {
      className: "shrink-0 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300"
    }, a.status === 'pending' ? 'PENDIENTE' : a.status === 'reviewed' ? 'REVISADA' : 'DESCARTADA')), React.createElement("div", {
      className: "flex gap-2 flex-wrap"
    }, reasons.map(r => React.createElement("span", {
      key: r,
      className: "px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-wider bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
    }, REASON_LABEL[r] || r))), React.createElement("div", {
      className: "grid grid-cols-2 gap-2 text-xs"
    }, React.createElement("div", {
      className: "bg-slate-50 dark:bg-slate-800/60 p-2 rounded-lg"
    }, React.createElement("p", {
      className: "text-[9px] text-slate-400 font-black uppercase"
    }, "Previa"), React.createElement("p", {
      className: "font-bold text-slate-700 dark:text-slate-200"
    }, a.prev_city ? `${a.prev_city}, ${a.prev_country_code || '—'}` : a.prev_country_code || '—'), a.prev_marked_at && React.createElement("p", {
      className: "text-[10px] text-slate-400"
    }, a.prev_marked_at)), React.createElement("div", {
      className: "bg-red-50 dark:bg-red-900/20 p-2 rounded-lg"
    }, React.createElement("p", {
      className: "text-[9px] text-red-500 font-black uppercase"
    }, "Actual"), React.createElement("p", {
      className: "font-bold text-red-700 dark:text-red-300"
    }, a.curr_city ? `${a.curr_city}, ${a.curr_country_code || '—'}` : a.curr_country_code || '—'), React.createElement("p", {
      className: "text-[10px] text-slate-400"
    }, a.work_date, " ", a.entry_time))), (a.distance_km !== null || a.implied_speed_kmh !== null) && React.createElement("div", {
      className: "flex gap-3 text-[11px] text-slate-500"
    }, a.distance_km !== null && React.createElement("span", null, React.createElement("strong", null, a.distance_km), " km"), a.implied_speed_kmh !== null && React.createElement("span", null, React.createElement("strong", null, a.implied_speed_kmh), " km/h implicitos"), a.elapsed_minutes !== null && React.createElement("span", null, React.createElement("strong", null, a.elapsed_minutes), " min")), a.notes && a.status !== 'pending' && React.createElement("p", {
      className: "text-xs text-slate-500 italic border-l-2 border-slate-300 pl-2"
    }, "\"", a.notes, "\""), a.status === 'pending' && React.createElement(React.Fragment, null, React.createElement("input", {
      type: "text",
      placeholder: "Notas (opcional)",
      value: notes[a.id] || '',
      onChange: e => setNotes(n => ({
        ...n,
        [a.id]: e.target.value
      })),
      className: "w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60"
    }), React.createElement("div", {
      className: "flex gap-2"
    }, React.createElement("button", {
      onClick: () => review(a.id, 'reviewed'),
      disabled: busyId === a.id,
      className: "flex-1 bg-emerald-500 text-white py-2 rounded-xl font-bold text-sm hover:bg-emerald-600 disabled:opacity-50 flex items-center justify-center gap-2"
    }, React.createElement(Icon, {
      name: "Check",
      size: 16
    }), " Revisada"), React.createElement("button", {
      onClick: () => review(a.id, 'dismissed'),
      disabled: busyId === a.id,
      className: "flex-1 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 py-2 rounded-xl font-bold text-sm hover:bg-slate-200 disabled:opacity-50 flex items-center justify-center gap-2"
    }, React.createElement(Icon, {
      name: "X",
      size: 16
    }), " Descartar"))));
  })));
};
const TzMismatchBadge = ({
  rec
}) => {
  if (!rec.tz_mismatch) return null;
  const profile = rec.timezone || '—';
  const client = rec.client_timezone || '—';
  return React.createElement("span", {
    title: `Marco desde ${client} (perfil: ${profile})`,
    className: "ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 cursor-help"
  }, "TZ");
};
const GeoBadge = ({
  rec
}) => {
  const code = rec.geo_country_code;
  if (!code) return null;
  const name = rec.geo_country_name || code;
  const city = rec.geo_city;
  const label = city ? `${city}, ${name}` : name;
  return React.createElement("span", {
    title: `Marco desde ${label} (IP)`,
    className: "ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300 cursor-help"
  }, code);
};
const GeoExitBadge = ({
  rec
}) => {
  const code = rec.geo_exit_country_code;
  if (!code) return null;
  if (code === rec.geo_country_code) return null;
  const city = rec.geo_exit_city;
  const label = city ? `${city} (salida)` : `${code} (salida)`;
  return React.createElement("span", {
    title: `Salida desde ${label}`,
    className: "ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300 cursor-help"
  }, code, React.createElement(Icon, {
    name: "LogOut",
    size: 10
  }));
};
const GeoAlertBadge = ({
  rec
}) => {
  if (!rec.geo_alert_flag) return null;
  const reasonMap = {
    NEW_COUNTRY: 'Pais nuevo en historial',
    IMPOSSIBLE_SPEED: 'Velocidad imposible vs ultimo marcaje',
    FAR_FROM_HISTORY: 'Distancia inusual del historial'
  };
  const reasons = (rec.geo_alert_reasons || '').split(',').filter(Boolean);
  const tooltip = reasons.length > 0 ? 'Alerta de ubicacion: ' + reasons.map(r => reasonMap[r] || r).join('; ') : 'Alerta de ubicacion';
  return React.createElement("span", {
    title: tooltip,
    className: "ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 cursor-help animate-pulse"
  }, React.createElement(Icon, {
    name: "AlertTriangle",
    size: 10
  }), " ALERTA");
};
const AdminRecordRow = ({
  rec
}) => {
  const stateLabel = rec.exit_time ? rec.closed_reason === 'forgotten' ? 'OLVIDO 18:00' : 'COMPLETO' : 'EN TURNO';
  const stateClass = rec.exit_time ? rec.closed_reason === 'forgotten' ? 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300';
  return React.createElement("tr", {
    className: "hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors"
  }, React.createElement("td", {
    className: "px-6 py-4 font-bold text-slate-800 dark:text-slate-100"
  }, rec.user_name), React.createElement("td", {
    className: "px-6 py-4 text-slate-600 dark:text-slate-300"
  }, rec.company_name || '—'), React.createElement("td", {
    className: "px-6 py-4 text-slate-500 dark:text-slate-400 font-medium"
  }, rec.work_date, React.createElement(TzMismatchBadge, {
    rec: rec
  }), React.createElement(GeoBadge, {
    rec: rec
  }), React.createElement(GeoExitBadge, {
    rec: rec
  }), React.createElement(GeoAlertBadge, {
    rec: rec
  }), rec.late_close && React.createElement("span", {
    title: `Cierre tardio: +${rec.late_minutes} min`,
    className: "ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 cursor-help"
  }, React.createElement(Icon, {
    name: "AlertTriangle",
    size: 10
  }), " TARDIO +", rec.late_minutes, "m")), React.createElement("td", {
    className: "px-6 py-4 font-mono font-bold text-blue-600 dark:text-blue-400"
  }, React.createElement("div", null, rec.entry_time, React.createElement("span", {
    className: "text-[9px] font-bold text-slate-400 ml-1"
  }, "local")), rec.entry_time_cdmx && rec.entry_time_cdmx !== rec.entry_time && React.createElement("div", {
    className: "text-[11px] text-slate-500 dark:text-slate-400 font-mono"
  }, rec.entry_time_cdmx, " ", React.createElement("span", {
    className: "text-[9px] font-bold text-slate-400"
  }, "CDMX"))), React.createElement("td", {
    className: "px-6 py-4 font-mono font-bold text-orange-600 dark:text-orange-400"
  }, React.createElement("div", null, rec.exit_time || '--:--', React.createElement("span", {
    className: "text-[9px] font-bold text-slate-400 ml-1"
  }, "local")), rec.exit_time_cdmx && rec.exit_time_cdmx !== rec.exit_time && React.createElement("div", {
    className: "text-[11px] text-slate-500 dark:text-slate-400 font-mono"
  }, rec.exit_time_cdmx, " ", React.createElement("span", {
    className: "text-[9px] font-bold text-slate-400"
  }, "CDMX"))), React.createElement("td", {
    className: "px-6 py-4"
  }, React.createElement("span", {
    className: `px-3 py-1 rounded-full text-[10px] font-black tracking-tighter ${stateClass}`
  }, stateLabel)));
};
const AdminRecordCard = ({
  rec
}) => React.createElement("div", {
  className: "p-5 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors"
}, React.createElement("div", {
  className: "flex justify-between items-start mb-3"
}, React.createElement("div", null, React.createElement("p", {
  className: "font-bold text-slate-800 dark:text-slate-100"
}, rec.user_name), React.createElement("p", {
  className: "text-xs text-slate-500 dark:text-slate-400"
}, rec.company_name || 'Sin empresa')), React.createElement("span", {
  className: "text-[10px] font-black uppercase tracking-widest text-slate-400"
}, rec.work_date, React.createElement(TzMismatchBadge, {
  rec: rec
}), React.createElement(GeoBadge, {
  rec: rec
}), React.createElement(GeoExitBadge, {
  rec: rec
}), React.createElement(GeoAlertBadge, {
  rec: rec
}))), React.createElement("div", {
  className: "flex gap-3 text-sm flex-wrap"
}, React.createElement("div", null, React.createElement("p", {
  className: "text-[9px] font-black uppercase text-slate-400"
}, "Entrada"), React.createElement("p", {
  className: "font-mono font-bold text-blue-600 dark:text-blue-400"
}, rec.entry_time, " ", React.createElement("span", {
  className: "text-[8px] font-bold text-slate-400"
}, "local")), rec.entry_time_cdmx && rec.entry_time_cdmx !== rec.entry_time && React.createElement("p", {
  className: "font-mono text-[11px] text-slate-500 dark:text-slate-400"
}, rec.entry_time_cdmx, " ", React.createElement("span", {
  className: "text-[8px] font-bold"
}, "CDMX"))), React.createElement("div", null, React.createElement("p", {
  className: "text-[9px] font-black uppercase text-slate-400"
}, "Salida"), React.createElement("p", {
  className: "font-mono font-bold text-orange-600 dark:text-orange-400"
}, rec.exit_time || '--:--', " ", React.createElement("span", {
  className: "text-[8px] font-bold text-slate-400"
}, "local")), rec.exit_time_cdmx && rec.exit_time_cdmx !== rec.exit_time && React.createElement("p", {
  className: "font-mono text-[11px] text-slate-500 dark:text-slate-400"
}, rec.exit_time_cdmx, " ", React.createElement("span", {
  className: "text-[8px] font-bold"
}, "CDMX")))));
const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(React.createElement(ToastProvider, null, React.createElement(BrandingProvider, null, React.createElement(App, null))));
