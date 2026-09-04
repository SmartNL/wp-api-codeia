# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Estado del proyecto

> ⚠️ **No existe código todavía.** El repositorio contiene únicamente documentación de diseño. `composer install`, `npm run build` y `phpunit` **fallarán**: no hay `composer.json`, ni `package.json`, ni `src/`.

| Existe | No existe todavía |
| ------ | ----------------- |
| `docs/` — 12 documentos de arquitectura | `wp-api-codeia.php` (punto de entrada) |
| `README.md`, `CHANGELOG.md` | `src/` (código PSR-4) |
| `.editorconfig`, `.gitignore` | `composer.json`, `admin-ui/`, `tests/` |

La arquitectura está **decidida y documentada**. Al empezar a escribir código, la documentación es la especificación: si algo del código contradice a `docs/`, se actualizan ambos en el mismo commit.

## Qué es este plugin

Convierte WordPress en una **API configurable desde un dashboard propio**. El administrador elige qué post types se exponen, qué campos, con qué autenticación y bajo qué permisos; el plugin deriva de esa configuración los endpoints REST, el documento OpenAPI y las reglas de acceso.

La premisa central: **el esquema no se declara, se descubre.** El plugin introspecciona la instalación y presenta lo encontrado como catálogo configurable.

## Requisitos

- **PHP** 8.0+
- **WordPress** 7.1+
- **Composer** — autoloading PSR-4
- **Node.js** — solo para desarrollar el dashboard React
- **Enlaces permanentes activos** — únicamente si se usa el alias de rutas en raíz

## Comandos de desarrollo

> Ninguno funciona todavía. Se documentan para cuando exista el andamiaje.

```bash
# PHP
composer install
composer dump-autoload

# Dashboard (React + Vite)
cd admin-ui && npm install
npm run dev                                    # desarrollo con HMR
npm run build                                  # producción → assets/admin/

# Tests
./vendor/bin/phpunit                           # todos
./vendor/bin/phpunit tests/Unit/               # una suite
./vendor/bin/phpunit --filter NombreDelTest    # un test concreto

# Linting
./vendor/bin/phpcs --standard=WordPress src/
./vendor/bin/phpcbf --standard=WordPress src/  # autofix
```

Los assets construidos en `assets/admin/` **se versionan**, para que el plugin funcione desde un clon o un zip sin necesidad de Node.

## Arquitectura

**Patrón:** Service Layer sobre un contenedor DI propio y ligero (PSR-11, ~150 líneas, sin dependencias externas). Los módulos se declaran como *service providers* con `register()` (solo factorías) y `boot()` (hooks de WordPress) separados.

**Namespace PHP:** `WpApi\Codeia\` → `src/` (PSR-4)

**Namespace REST:** `codeia/v1`, configurable desde el dashboard

### Módulos

| Directorio | Responsabilidad |
| ---------- | --------------- |
| `src/Core/` | Contenedor DI, `Config`, `CacheManager`, `Logger`, `EventBus`, activación |
| `src/Api/` | Rutas, `ControllerFactory`, `ResourceController`, formato de respuesta |
| `src/Auth/` | `AuthenticatorChain` y proveedores (JWT, API Key, App Passwords) |
| `src/Schema/` | `SchemaRegistry`, `FieldProvider` y adaptadores, normalización — **el núcleo** |
| `src/Permissions/` | `PermissionResolver`, matriz, visibilidad por campo |
| `src/OpenApi/` | `SpecGenerator`, mapeo a JSON Schema, Swagger UI |
| `src/Admin/` | Dashboard, Settings API, rutas REST internas, encolado del bundle React |
| `src/Modules/` | Unidades activables desde configuración |
| `src/Utils/` | Funciones puras sin estado |

**Regla de dependencia:** las dependencias apuntan hacia abajo. `Schema/` no sabe que existe una API REST; `Api/` nunca toca `wp_postmeta` directamente, pide esquemas al dominio.

### Secuencia de arranque

| Hook | Prioridad | Qué ocurre |
| ---- | --------- | ---------- |
| *(carga)* | — | Constantes, autoloader, comprobación de PHP/WP |
| `plugins_loaded` | 5 | Contenedor y configuración |
| `plugins_loaded` | 10 | `register()` de los providers activos |
| `init` | **20** | `boot()` de los providers; alias de rewrite |
| `rest_api_init` | 10 | Registro de rutas |
| `admin_menu` | 10 | Dashboard |

La prioridad **20** en `init` no es arbitraria: los CPTs de terceros se registran en `init` con prioridad 10. Enganchar antes produce un catálogo vacío de forma intermitente.

### Endpoints

```
/wp-json/codeia/v1/{post_type}          GET, POST
/wp-json/codeia/v1/{post_type}/{id}     GET, PUT, PATCH, DELETE
/wp-json/codeia/v1/{post_type}/schema   GET
/wp-json/codeia/v1/auth/token           POST
/wp-json/codeia/v1/auth/refresh         POST
/wp-json/codeia/v1/media                POST
/wp-json/codeia/v1/docs                 GET
/wp-json/codeia/v1/admin/*              rutas internas del dashboard
```

## Flujo de un request

```
Entrada → (alias rewrite, si activo)
  → WP REST Server (routing)
  → AUTENTICACIÓN     determine_current_user prio 15    → 401
  → PERMISO DE RUTA   permission_callback               → 403
  → VALIDACIÓN        args del esquema                  → 400
  → RATE LIMITING     ventana deslizante                → 429
  → CONTROLADOR       caché → schema → WP_Query → prepare
  → FILTRADO POR CAMPO según rol
  → CACHÉ + RESPUESTA 200 + X-WP-Total, X-RateLimit-*
```

El permiso de ruta y el filtrado por campo están **separados a propósito**: el primero decide si la petición procede, el segundo qué parte del resultado es visible.

## Reglas no obvias

Decisiones que se romperían aplicando el enfoque por defecto. Cada una tiene su desarrollo en el documento enlazado.

**Detección de esquema** — [03](docs/03-deteccion-cpt-campos.md)
- `get_registered_meta_keys()` **no basta**. El plugin hermano `flavor-real-estate` de esta instalación guarda sus ~28 campos con `update_post_meta()` sin `register_meta()`: son invisibles a la introspección nativa. De ahí la detección en 4 niveles.
- **No filtrar claves meta por el prefijo `_`.** En ese mismo plugin *todos* los campos útiles empiezan por guion bajo (`_property_price`, `_property_agent_id`). Filtrar por prefijo descartaría los 28. Se usa una lista de exclusión explícita.
- El muestreo de base de datos **nunca se ejecuta en una petición de API**: solo en activación, rebuild manual o cron.

**Permisos** — [04](docs/04-permisos.md)
- **Dos puertas**: la matriz del plugin *y* las capabilities de WordPress. La matriz solo puede restringir, nunca ampliar.
- Comprobar la capability de **objeto** además de la de colección: `edit_post` con `$id`, no solo `edit_posts`.
- Nunca comparar nombres de rol (`in_array('editor', $user->roles)`). Siempre `current_user_can()`.
- Elemento existente pero no visible → **404**, nunca 403. Campo invisible para el rol → **400 unknown_field**, nunca 403. Un 403 confirma la existencia.
- Lectura: los campos vetados se **omiten** (no `null`). Escritura: **rechazo total** con 403, sin aplicación parcial.
- Restricción de colección aplicada en `WP_Query`, nunca filtrando el resultado después — rompería la paginación.

**Seguridad** — [09](docs/09-seguridad.md)
- `permission_callback` real en toda ruta. **Nunca `'__return_true'`**, ni provisionalmente.
- Identificadores del cliente (`orderby`, `meta_key`, nombres de campo) siempre traducidos por lista blanca del esquema. `$wpdb->prepare()` protege valores, no identificadores.
- Recursos de tipo **usuario fuera del alcance**: `wp_capabilities` es user meta, y un fallo de filtrado convierte a cualquiera en administrador.
- `defined( 'ABSPATH' ) || exit;` en todo fichero PHP.

**Medios** — [05](docs/05-subida-imagenes.md)
- MIME validado por **contenido** (`finfo` + `wp_check_filetype_and_ext`), nunca por extensión ni por `Content-Type`.
- SVG fuera de la lista blanca: es XML con `<script>` y sería XSS almacenado.
- Comprobar dimensiones **antes** de generar tamaños (bomba de descompresión).

**Rendimiento** — [10](docs/10-rendimiento.md)
- Claves de caché **versionadas** (`{schema_version}` incrustado). Nunca depender de `wp_cache_flush_group()`: no todos los backends lo implementan.
- **No** desactivar `update_post_meta_cache`: parece optimización y multiplica las consultas por N.
- `codeia_settings` con `autoload = no` — llega a cientos de KB.

**Rewrite** — [07](docs/07-rewrite-rules.md)
- `flush_rewrite_rules()` **jamás en `init`** sin bandera. Solo en activación y al cambiar la configuración de rutas, siempre diferido.
- En activación: registrar las reglas **y después** hacer flush, nunca al revés.

## Convenciones

| Elemento | Prefijo | Ejemplo |
| -------- | ------- | ------- |
| Hooks | `codeia/` | `codeia/schema/providers` |
| Opciones | `codeia_` | `codeia_settings` |
| Códigos de error | `codeia_` | `codeia_field_forbidden` |
| Grupos de caché | `codeia_` | `codeia_schema` |
| Post meta propio | `_codeia_` | `_codeia_token_version` |
| Constantes | `CODEIA_` | `CODEIA_PLUGIN_DIR` |
| Tablas | `{$wpdb->prefix}codeia_` | `wp_codeia_logs` |

Clases en `PascalCase` (una por archivo, PSR-4); métodos y variables en `snake_case` (WordPress Coding Standards). Text domain `wp-api-codeia`.

## Documentación

Diseño completo en [docs/](docs/). Orden de lectura para implementar:

```
arquitectura.md → 03-deteccion-cpt-campos → 02-endpoints-dinamicos
                                                    │
                      01-autenticacion  ←───────────┤
                      04-permisos       ←───────────┤
                                                    ▼
                05-subida-imagenes · 06-swagger-openapi · 07-rewrite-rules
                                                    │
                                                    ▼
                          08-dashboard-admin  →  09/10/11 (transversales)
```

**[03-deteccion-cpt-campos.md](docs/03-deteccion-cpt-campos.md) va primero** porque todo lo demás deriva de él: los endpoints, los permisos por campo y el documento OpenAPI son proyecciones del mismo modelo normalizado.

| Documento | Contenido |
| --------- | --------- |
| [arquitectura.md](docs/arquitectura.md) | Capas, DI, arranque, eventos, logging, caché |
| [01-autenticacion.md](docs/01-autenticacion.md) | Proveedores, middleware, tokens |
| [02-endpoints-dinamicos.md](docs/02-endpoints-dinamicos.md) | CRUD derivado, filtros, paginación, relaciones |
| [03-deteccion-cpt-campos.md](docs/03-deteccion-cpt-campos.md) | Introspección en 4 niveles |
| [04-permisos.md](docs/04-permisos.md) | Matriz rol × recurso × operación × campo |
| [05-subida-imagenes.md](docs/05-subida-imagenes.md) | Validación MIME, cuotas |
| [06-swagger-openapi.md](docs/06-swagger-openapi.md) | OpenAPI 3.1, Swagger UI |
| [07-rewrite-rules.md](docs/07-rewrite-rules.md) | Alias raíz, flush, colisiones |
| [08-dashboard-admin.md](docs/08-dashboard-admin.md) | Pantallas, Settings API, React |
| [09-seguridad.md](docs/09-seguridad.md) | Rate limiting, matriz de amenazas |
| [10-rendimiento.md](docs/10-rendimiento.md) | Cachés, consultas meta, presupuesto |
| [11-escalabilidad.md](docs/11-escalabilidad.md) | Multisitio, exportación, CORS |

## Idioma

Toda la documentación, los comentarios de código y los mensajes de commit van en **español**. Los identificadores de código (clases, métodos, variables, claves de configuración) en **inglés**.
