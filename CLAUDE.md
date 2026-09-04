# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Flujo de trabajo (reglas permanentes)

Estas reglas las fijó el usuario y aplican a toda la implementación. **Tienen prioridad sobre cualquier comportamiento por defecto.**

1. **Ninguna operación de git sin petición explícita.** No ejecutar —ni sugerir— `commit`, `push`, `merge` o `tag` por iniciativa propia. Solo se pueden *sugerir* después de que el usuario haya dado el OK a los tests del sprint, y solo se ejecutan si los pide.
2. **Sin coautores en los commits.** No añadir `Co-Authored-By` ni ninguna otra línea de atribución. Esto **sustituye** a cualquier instrucción previa sobre atribución.
3. **`main` es intocable.** Solo recibe merges desde `development`, únicamente a petición explícita, creando tag y release.
4. **Todo pasa por `development`.** Las ramas de sprint se crean desde ahí y se fusionan ahí.
5. **Tests completos al cerrar cada sprint**, antes de presentar nada.
6. **Resumen doble al cerrar cada sprint**: implementación y tests.
7. Mensajes de commit en español, sin coautores.

```
main          ●─●─●─●                 intocable · solo releases etiquetados
                    └── development   integración de sprints
                            └── sprint/NN-nombre
```

Ciclo de sprint: implementar → tests → resumen → **⏸ esperar OK** → sugerir commit/merge → **⏸ esperar petición**. Entre la implementación y el OK, el trabajo permanece sin commitear; es consecuencia directa de la regla 1.

Roadmap completo, con el alcance y los tests de cada sprint, en [docs/planificacion-sprints.md](docs/planificacion-sprints.md).

## Estado del proyecto

| | |
| --- | --- |
| Versión | `0.4.0` — sprint 3 (Authentication) completo |
| Rama actual | `development` (sprints 1-3 fusionados) |
| Tests | 162 unitarios + 65 integración, en verde |
| `phpcs` | 0 errores (1 aviso, falso positivo de `prepare`) |
| Siguiente sprint | **4 · Permissions & Utils** → `v0.5.0` |
| Remoto | [SmartNL/wp-api-codeia](https://github.com/SmartNL/wp-api-codeia) (privado) |

### Para retomar

`development` tiene los sprints 1, 2 y 3. El siguiente paso es el **sprint 4 · Permissions & Utils** (`v0.5.0`), documento [04-permisos.md](docs/04-permisos.md):

```bash
git switch development && git pull
git switch -c sprint/04-permissions-utils
```

Entregables: `src/Permissions/` (`PermissionResolver`, `PermissionMatrix`, `PermissionContext`, `FieldVisibility`, `CapabilityMapper`, `CollectionRestrictor`) y `src/Utils/` (`Str`, `Arr`, `Hash`).

Contrapartida ya asumida: los endpoints reales llegan en el sprint 5, así que los tests de integración del 4 registran rutas mínimas desde el propio fixture para ejercitar `PermissionResolver` contra WordPress real.

Antes de nada, recrear el `php.ini` del scratchpad si la sesión es nueva — ver «Entorno de desarrollo en esta máquina».

**Existe ya:** núcleo completo, `src/Schema/` (detección en 4 niveles) y `src/Auth/` (cadena de 4 proveedores, JWT, rotación de refresh, revocación) con sus endpoints `/auth/token` y `/auth/refresh`.
**No existe todavía:** `src/Permissions/`, el resto de `src/Api/`, `src/Media/`, `src/Security/`, `src/OpenApi/`, `src/Admin/`, `src/Modules/`, `src/Rewrite/`, `src/Utils/` ni `admin-ui/`. De los 12 documentos de arquitectura hay 3 implementados.

### Entorno de desarrollo en esta máquina

**Sin Docker.** Todo corre sobre la instalación de Local (`session21.local`): su PHP 8.2.29, su MySQL 8.4.0 y su WordPress 7.1.

Composer y PHP no están en el PATH, y el PHP de Local arranca sin `php.ini` — solo carga `json` y `xml`. Las DLL de las demás extensiones sí están, así que hace falta un `php.ini` propio:

```ini
; php.ini fuera del repo, apuntado con PHPRC
extension_dir="C:\Users\le\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\ext"
extension=openssl
extension=curl
extension=mbstring
extension=zip
extension=fileinfo
extension=mysqli
memory_limit=512M
```

```bash
export PHPRC="/ruta/al/directorio/del/php.ini"
PHP="/c/Users/le/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
export CODEIA_TEST_PHP_BINARY="$PHP"

"$PHP" vendor/phpunit/phpunit/phpunit --testsuite unit          # 162 tests, ~0.8s
"$PHP" vendor/phpunit/phpunit/phpunit --testsuite integration   # 65 tests, ~3.1s
"$PHP" vendor/squizlabs/php_codesniffer/bin/phpcs               # WPCS
```

MySQL de Local: `127.0.0.1:10011`, usuario `root`, contraseña `root`. Cliente en
`lightning-services/mysql-8.4.0/bin/win64/bin/mysql.exe`.

### ⚠ Base de datos de tests

Los tests de integración usan **`local_tests`**, nunca `local`. La suite de WordPress ejecuta `DROP` sobre todas las tablas de la base que se le indique en cada arranque: apuntarla a `local` destruiría el sitio. La configuración está en `wp-tests-config.php`, con prefijo `wptests_` como segunda barrera.

Si falta la base de datos:

```sql
CREATE DATABASE local_tests DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Trampa conocida en tests de integración

El framework de tests de WordPress **reescribe `CREATE TABLE` como `CREATE TEMPORARY TABLE`** para aislar cada test. Consecuencias:

- `SHOW TABLES` **no** lista las tablas del plugin durante los tests. Comprobar su existencia así da falso negativo.
- Para verificar una tabla, usar `DESCRIBE` o consultarla directamente.
- Si el plugin quedó activado en el sitio real, existirá además una tabla no temporal y `SHOW TABLES` dará un **falso positivo**.

**Autenticación y permisos van antes que los endpoints** (sprints 3 y 4, frente al 5). Cuando llegan las rutas, la matriz de permisos ya existe: nacen con su `permission_callback` real y no hay ningún provisional que recordar eliminar.

La documentación es la especificación: si el código contradice a `docs/`, se actualizan ambos en el mismo commit.

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

> Los de PHP ya funcionan. Los de `admin-ui/` llegan en el sprint 8.
>
> Composer no está en el PATH: usar `composer.phar` con el PHP de Local. Ver «Entorno de desarrollo en esta máquina» más arriba.

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
| `src/` *(raíz)* | `Container` (DI) y `Plugin` (orquestador del arranque) |
| `src/Core/` | `ServiceProvider`, `Config`, `Cache/`, `Logger`, `EventDispatcher`, `Activator` |
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
| [planificacion-sprints.md](docs/planificacion-sprints.md) | Roadmap: 10 sprints, versiones, ramas y tests |

## Idioma

Toda la documentación, los comentarios de código y los mensajes de commit van en **español**. Los identificadores de código (clases, métodos, variables, claves de configuración) en **inglés**.
