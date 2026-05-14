# QA Checklist — clockin-clockout

Checklist sistematico para validar funcionalidad y responsive. Recorre cada item en navegador real (desktop + tablet + movil) y marca con `[x]` lo que funciona. Reporta bugs al final.

## Viewports a probar

- **Desktop:** 1440x900 (default)
- **Tablet:** 768x1024 (iPad portrait)
- **Movil:** 375x667 (iPhone SE) y 414x896 (iPhone 11)

Usa DevTools → Toggle Device Toolbar (Ctrl+Shift+M).

---

## A. Autenticacion y onboarding

| # | Escenario | Desktop | Movil |
|---|---|---|---|
| A1 | Pagina /login carga sin errores en consola | [ ] | [ ] |
| A2 | Login con credenciales validas redirige a dashboard | [ ] | [ ] |
| A3 | Login con password mal muestra error claro | [ ] | [ ] |
| A4 | Forgot-password muestra confirmacion (envio o "si existe") | [ ] | [ ] |
| A5 | Cambio de password tras invitacion funciona | [ ] | [ ] |
| A6 | Logout limpia sesion y vuelve a login | [ ] | [ ] |

## B. Panel admin: navegacion

| # | Escenario | Desktop | Movil |
|---|---|---|---|
| B1 | Header: logo + nombre producto se ven correctos | [ ] | [ ] |
| B2 | Tabs primarios (Dashboard, Registros, Agentes, Solicitudes) responden al click | [ ] | [ ] |
| B3 | Dropdown "Mas" abre/cierra al click | [ ] | n/a |
| B4 | Drawer "Mas" abre desde bottom nav movil | n/a | [ ] |
| B5 | Avatar usuario: click abre dropdown | [ ] | [ ] |
| B6 | Mi jornada: click vuelve a dashboard empleado | [ ] | [ ] |
| B7 | Cambiar tema: click alterna oscuro/claro | [ ] | [ ] |
| B8 | Cerrar sesion: click cierra sesion | [ ] | [ ] |
| B9 | Bottom nav movil queda fijo abajo, no tapa contenido | n/a | [ ] |
| B10 | Badge de Solicitudes muestra conteo correcto | [ ] | [ ] |
| B11 | Sub-toggle Cambios/Extras dentro de Solicitudes funciona | [ ] | [ ] |

## C. Dashboard (KPIs)

| # | Escenario | Desktop | Movil |
|---|---|---|---|
| C1 | Cards KPI cargan numeros (no 0 si hay datos) | [ ] | [ ] |
| C2 | "Agentes activos" incluye super_admin/admin si fichan | [ ] | [ ] |
| C3 | "Por empresa" muestra desglose (solo super_admin) | [ ] | [ ] |
| C4 | Cards no se desbordan en movil (texto largo) | [ ] | [ ] |
| C5 | Loading state se ve mientras carga | [ ] | [ ] |

## D. Registros / Records

| # | Escenario | Desktop | Movil |
|---|---|---|---|
| D1 | Tabla de registros se ve completa (no scroll horizontal incomodo) | [ ] | [ ] |
| D2 | Filtros (periodo, empresa) funcionan | [ ] | [ ] |
| D3 | Export CSV descarga archivo correcto | [ ] | [ ] |
| D4 | Vista movil de registros usa cards (no tabla) | n/a | [ ] |

## E. Agentes

| # | Escenario | Desktop | Movil |
|---|---|---|---|
| E1 | Listado de agentes carga con paginacion | [ ] | [ ] |
| E2 | Filtros: q, empresa, status funcionan | [ ] | [ ] |
| E3 | Invitar consultor: form abre, valida, abre confirm modal | [ ] | [ ] |
| E4 | Confirm modal muestra preview email con marca correcta | [ ] | [ ] |
| E5 | Click "Confirmar y enviar" crea cuenta + envia mail | [ ] | [ ] |
| E6 | Click "Volver" deja form igual sin enviar | [ ] | [ ] |
| E7 | Carga masiva CSV abre, descarga plantilla, sube CSV | [ ] | [ ] |
| E8 | Bulk confirm modal muestra 4 tarjetas resumen | [ ] | [ ] |
| E9 | Bulk: avisos por fila (email invalido, duplicado) aparecen | [ ] | [ ] |
| E10 | Bulk: tabs por marca con preview email | [ ] | [ ] |
| E11 | Bulk: confirm dispara procesamiento, reporte aparece | [ ] | [ ] |

## F. Empresas

| # | Escenario | Desktop | Movil |
|---|---|---|---|
| F1 | Listado carga | [ ] | [ ] |
| F2 | Crear nueva empresa funciona | [ ] | [ ] |
| F3 | Editar empresa funciona | [ ] | [ ] |
| F4 | Boton "Branding" abre modal de override | [ ] | [ ] |
| F5 | Modal branding: preview en vivo con colores override | [ ] | [ ] |
| F6 | Guardar override aplica cambios | [ ] | [ ] |
| F7 | "Limpiar todo y volver al heredado" deja campos vacios | [ ] | [ ] |
| F8 | Eliminar empresa con 0 agentes funciona (super_admin) | [ ] | [ ] |

## G. Marcas paraguas (solo super_admin)

| # | Escenario | Desktop | Movil |
|---|---|---|---|
| G1 | Listado de marcas carga con cards | [ ] | [ ] |
| G2 | Card lectura: solo nombre + circulos de color (sin slug/hex) | [ ] | [ ] |
| G3 | Editar marca abre modal 2 columnas (form + preview email) | [ ] | n/a |
| G4 | Editar en movil colapsa a stack vertical | n/a | [ ] |
| G5 | Subir logo: preview carga | [ ] | [ ] |
| G6 | "Sugerir colores desde el logo" extrae paleta | [ ] | [ ] |
| G7 | Detalles tecnicos (acordeon) muestra slug + hex editables | [ ] | [ ] |
| G8 | Preview email se actualiza en vivo al cambiar colores | [ ] | [ ] |
| G9 | Guardar persiste y refresca lista | [ ] | [ ] |
| G10 | Desactivar marca (super_admin) funciona | [ ] | [ ] |

## H. Configuracion > Branding (TenantSettings, solo super_admin)

| # | Escenario | Desktop | Movil |
|---|---|---|---|
| H1 | Tab "Configuracion" aparece en dropdown Mas | [ ] | [ ] |
| H2 | Sub-tab Branding carga datos del tenant | [ ] | [ ] |
| H3 | Logo: si no hay custom, muestra Melius con badge "default" | [ ] | [ ] |
| H4 | Subir logo personalizado actualiza preview | [ ] | [ ] |
| H5 | Cambiar nombre del producto refleja en preview | [ ] | [ ] |
| H6 | Color pickers actualizan gradiente del preview | [ ] | [ ] |
| H7 | Guardar refresca header de la app en vivo (sin reload) | [ ] | [ ] |
| H8 | Recargar pagina conserva el branding guardado | [ ] | [ ] |

## I. Configuracion > Licencia (Billing)

| # | Escenario | Desktop | Movil |
|---|---|---|---|
| I1 | Sub-tab Licencia carga estado actual | [ ] | [ ] |
| I2 | Card de estado muestra plan, precio, status | [ ] | [ ] |
| I3 | Catalogo de 4 planes se ve (Trial, Starter, Pro, Enterprise) | [ ] | [ ] |
| I4 | Cambiar plan manual funciona (super_admin) | [ ] | [ ] |
| I5 | Conectar Stripe: muestra modal con instrucciones | [ ] | [ ] |
| I6 | Conectar PayPal: muestra modal con instrucciones | [ ] | [ ] |

## J. Solicitudes (Cambios + Extras)

| # | Escenario | Desktop | Movil |
|---|---|---|---|
| J1 | Cambios pendientes se listan | [ ] | [ ] |
| J2 | Aprobar cambio funciona | [ ] | [ ] |
| J3 | Rechazar cambio con motivo funciona | [ ] | [ ] |
| J4 | Extras pendientes se listan | [ ] | [ ] |
| J5 | Aprobar extras funciona | [ ] | [ ] |

## K. Vista empleado (jornada)

| # | Escenario | Desktop | Movil |
|---|---|---|---|
| K1 | Pagina carga con boton clockin grande | [ ] | [ ] |
| K2 | Clockin funciona (timestamp + entry_time) | [ ] | [ ] |
| K3 | Clockout funciona (cierra el registro) | [ ] | [ ] |
| K4 | Reportar overtime funciona | [ ] | [ ] |
| K5 | Solicitar cambio de empresa: solo aparecen empresas misma marca | [ ] | [ ] |
| K6 | Si must_change_password=1, redirige a cambio antes de fichar | [ ] | [ ] |

## L. Responsive global

| # | Escenario | Desktop | Tablet | Movil |
|---|---|---|---|---|
| L1 | Ningun modulo tiene scroll horizontal | [ ] | [ ] | [ ] |
| L2 | Modales caben en pantalla (no se cortan abajo) | [ ] | [ ] | [ ] |
| L3 | Botones de touch tienen al menos 40px de alto | n/a | [ ] | [ ] |
| L4 | Texto no se corta sin elipsis | [ ] | [ ] | [ ] |
| L5 | Tema oscuro: todos los textos legibles | [ ] | [ ] | [ ] |
| L6 | Cambio orientacion portrait/landscape no rompe layout | n/a | [ ] | [ ] |

## M. Seguridad visual

| # | Escenario | Resultado esperado |
|---|---|---|
| M1 | Logout limpia datos del navegador (no quedan en sessionStorage) | [ ] |
| M2 | Click derecho > Inspect: ningun token/secret visible en HTML | [ ] |
| M3 | DevTools > Network: ningun endpoint devuelve datos sin login | [ ] |
| M4 | Tema oscuro: no aparece texto blanco sobre fondo blanco | [ ] |

---

## Reporte de bugs (rellenar)

```
[BUG-001] Modulo / Item del checklist
  Viewport: desktop|tablet|movil
  Pasos: 1. ... 2. ... 3. ...
  Esperado: ...
  Actual: ...
  Severidad: critico|alto|medio|bajo
```
