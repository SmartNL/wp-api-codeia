# Arquitectura — WP API Codeia

> **Nota:** el plugin todavía no tiene código. Todo lo marcado como **(propuesto)** es diseño previsto, no implementación existente. Este documento se actualizará a medida que se construya.

## Descripción General

Plugin de WordPress que expone endpoints REST propios para consumo desde el front-end, aplicaciones externas o integraciones de terceros. Se apoya exclusivamente en la API REST nativa de WordPress (`register_rest_route`, `WP_REST_Controller`, `WP_REST_Response`) sin dependencias externas en tiempo de ejecución.

## Stack Técnico

- **WordPress**: 7.1+
- **PHP**: 8.0+
- **Autoloading**: PSR-4 (Composer o fallback manual) _(propuesto)_
- **Namespace PHP**: `WpApi\Codeia` _(propuesto)_
- **Namespace REST**: `codeia/v1` _(propuesto)_
- **Text Domain**: `wp-api-codeia`

## Estructura de Archivos

Estructura prevista _(propuesta)_:

```
wp-api-codeia/
├── wp-api-codeia.php               → Punto de entrada, constantes, autoloader, hooks
├── uninstall.php                   → Limpieza al desinstalar
├── composer.json                   → PSR-4 autoloading
├── includes/
│   ├── Plugin.php                  → Singleton, orquesta hooks
│   ├── Activator.php               → Activación: flush rewrite rules, opciones por defecto
│   ├── Deactivator.php             → Desactivación: flush rewrite rules
│   ├── Rest/
│   │   ├── RestServiceProvider.php → Registra todos los controladores en rest_api_init
│   │   └── Controllers/            → Un controlador por recurso
│   ├── Auth/
│   │   └── Permissions.php         → Permission callbacks reutilizables
│   └── Helpers/
│       └── Helpers.php             → Utilidades compartidas
├── docs/
│   └── arquitectura.md
└── languages/                      → Traducciones
```

## Diseño de la API REST

### Convención de rutas

```
/wp-json/codeia/v1/<recurso>            → Colección
/wp-json/codeia/v1/<recurso>/<id>       → Elemento individual
```

Los recursos se nombran en **plural y minúsculas**, con guiones para separar palabras (`api-keys`, no `apiKeys`). Los verbos HTTP expresan la operación — no se codifican en la ruta:

| Método | Ruta | Operación |
| ------ | ---- | --------- |
| `GET` | `/<recurso>` | Listar |
| `POST` | `/<recurso>` | Crear |
| `GET` | `/<recurso>/<id>` | Obtener |
| `PUT` / `PATCH` | `/<recurso>/<id>` | Actualizar |
| `DELETE` | `/<recurso>/<id>` | Eliminar |

### Versionado

La versión vive en el namespace REST (`codeia/v1`). Los cambios incompatibles requieren un namespace nuevo (`codeia/v2`), manteniendo el anterior en funcionamiento durante un periodo de transición. Los cambios retrocompatibles (campos nuevos, endpoints nuevos) se añaden dentro de la versión existente.

### Formato de respuesta

Las respuestas correctas devuelven el recurso directamente mediante `WP_REST_Response`, con la paginación en cabeceras HTTP (`X-WP-Total`, `X-WP-TotalPages`), siguiendo la convención de la API REST de WordPress.

Los errores se devuelven como `WP_Error`, que WordPress serializa automáticamente:

```json
{
  "code": "codeia_recurso_no_encontrado",
  "message": "El recurso solicitado no existe.",
  "data": { "status": 404 }
}
```

### Códigos de error

| Código HTTP | Uso |
| ----------- | --- |
| `200` / `201` | Operación correcta / recurso creado |
| `400` | Parámetros inválidos o mal formados |
| `401` | Petición no autenticada |
| `403` | Autenticado pero sin permisos suficientes |
| `404` | Recurso inexistente |
| `500` | Error interno |

## Convenciones de Nombres

| Elemento | Convención | Ejemplo |
| -------- | ---------- | ------- |
| Clases | `PascalCase`, una por archivo | `SettingsController` |
| Archivos de clase | Igual que la clase (PSR-4) | `SettingsController.php` |
| Métodos y variables | `snake_case` (WPCS) | `get_item_permissions_check()` |
| Códigos de error | Prefijo `codeia_` | `codeia_parametro_invalido` |
| Opciones (`wp_options`) | Prefijo `codeia_` | `codeia_settings` |
| Post meta | Prefijo `_codeia_` (privada) | `_codeia_external_id` |
| Hooks propios | Prefijo `codeia/` | `codeia/rest/before_response` |
| Constantes | Prefijo `CODEIA_` | `CODEIA_PLUGIN_DIR` |

## Seguridad

- **`permission_callback` obligatorio.** Toda llamada a `register_rest_route` debe declararlo explícitamente. Nunca se usa `'__return_true'` salvo en endpoints deliberadamente públicos y de solo lectura, documentados como tales.
- **Capabilities, no roles.** Las comprobaciones usan `current_user_can()` con capabilities concretas (`manage_options`, `edit_posts`), nunca comparaciones directas de rol.
- **Sanitización y validación vía `args`.** Cada parámetro declara `sanitize_callback` y `validate_callback` en el array `args` de la ruta, para que WordPress rechace la petición antes de llegar al controlador.
- **Escapado en la salida.** Los datos se escapan al construir la respuesta según su destino.
- **Nonces en peticiones desde el admin.** Las llamadas autenticadas por cookie desde el administrador envían el nonce `wp_rest` en la cabecera `X-WP-Nonce`.
- **Sin secretos en el repositorio.** Claves y credenciales se guardan en opciones de WordPress o variables de entorno; `.env` está en `.gitignore`.
- **Acceso directo bloqueado.** Todo archivo PHP empieza con una guarda `defined( 'ABSPATH' ) || exit;`.

## Flujo de Datos

```
Petición HTTP
   → WordPress REST Server (routing por namespace codeia/v1)
   → permission_callback          ── falla → WP_Error 401 / 403
   → validate_callback / sanitize_callback de args
                                  ── falla → WP_Error 400
   → Controlador (lógica del recurso)
   → Acceso a datos (WP_Query, wp_options, meta)
   → WP_REST_Response
   → Respuesta JSON
```
