# Arquitectura — WP API Codeia

Documento principal de arquitectura. Define la visión de producto, la estructura técnica del plugin y el contrato entre sus módulos. Es la puerta de entrada al resto de la documentación.

> **Estado:** diseño. No existe código todavía. Lo marcado como **(propuesto)** es una decisión de diseño aún revisable; lo marcado como **(futuro)** queda fuera de la primera versión y solo condiciona qué debe quedar abstraído desde ya.

---

## 1. Visión de producto

WP API Codeia convierte una instalación de WordPress en una **API configurable desde interfaz**, sin escribir código. El administrador elige qué tipos de contenido se exponen, qué campos de cada uno, con qué autenticación y bajo qué permisos; el plugin deriva de esa configuración los endpoints REST, la documentación OpenAPI y las reglas de acceso.

La premisa que lo diferencia de escribir endpoints a mano: **el esquema no se declara, se descubre**. El plugin introspecciona la instalación —tipos de contenido, taxonomías, campos personalizados de ACF, JetEngine, Meta Box o `register_meta`— y presenta el resultado como catálogo configurable.

### 1.1 Alcance

| Dentro del alcance | Fuera del alcance |
| ------------------ | ----------------- |
| Exposición CRUD de post types y taxonomías | Sustituir la REST API nativa de WordPress |
| Descubrimiento automático de campos personalizados | Ser un ORM o una capa de consulta genérica sobre `$wpdb` |
| Autenticación conmutable y permisos por rol y campo | Gestión de usuarios o de contenido (eso sigue en el admin de WP) |
| OpenAPI generado de la configuración vigente | Generación de SDKs de cliente **(futuro)** |
| Dashboard de configuración y observabilidad | Panel SaaS externo **(futuro)** |

### 1.2 Principios de diseño

1. **Denegación por defecto.** Nada se expone hasta que alguien lo habilita explícitamente. Un post type recién detectado aparece en el catálogo como *disponible*, nunca como *activo*.
2. **El núcleo no conoce a los proveedores.** ACF, JetEngine y Meta Box se integran por adaptadores tras una interfaz. Quitar uno no debe tocar el núcleo.
3. **La configuración es un dato, no código.** Persistida, versionada, exportable e importable. Es la única fuente de verdad sobre qué expone la API.
4. **Apoyarse en el núcleo de WordPress.** `WP_REST_Controller`, `WP_Roles`, la Settings API y el sistema de capabilities existen y están auditados. Reimplementarlos es superficie de ataque gratuita.
5. **Degradar de forma visible.** Cuando una capacidad no está disponible (sin object cache persistente, sin ACF), el sistema sigue funcionando peor pero **lo dice** en el panel de estado, en lugar de fallar en silencio.

### 1.3 Glosario

| Término | Significado en este documento |
| ------- | ----------------------------- |
| **Recurso** | Post type o taxonomía expuesto por la API. |
| **Esquema** | Modelo normalizado de un recurso: sus campos, tipos, origen y nivel de confianza. |
| **Registro de esquema** (*schema registry*) | Servicio que construye, cachea y sirve los esquemas de todos los recursos. |
| **Proveedor de campos** (*field provider*) | Adaptador que sabe extraer definiciones de campo de una fuente concreta (ACF, Meta Box…). |
| **Proveedor de autenticación** | Estrategia que resuelve una identidad a partir de una petición (JWT, API Key…). |
| **Namespace** | Prefijo de las rutas REST. Configurable; `codeia/v1` por defecto. |
| **Alias raíz** | Ruta alternativa fuera de `/wp-json/`, servida vía rewrite rules. Opcional. |

---

## 2. Arquitectura global

### 2.1 Vista de capas

```
┌──────────────────────────────────────────────────────────────┐
│  CLIENTES                                                    │
│  App headless · Móvil · Integración externa · Swagger UI     │
└───────────────────────────┬──────────────────────────────────┘
                            │ HTTP
┌───────────────────────────▼──────────────────────────────────┐
│  CAPA DE ENTRADA                                             │
│  ┌────────────────┐   ┌──────────────────────────────────┐   │
│  │ Rewrite/Alias  │──▶│  WP REST Server (/wp-json/)      │   │
│  │  (opcional)    │   │  routing por namespace           │   │
│  └────────────────┘   └────────────┬─────────────────────┘   │
└────────────────────────────────────┼─────────────────────────┘
                                     │
┌────────────────────────────────────▼─────────────────────────┐
│  CAPA DE APLICACIÓN  (src/Api, src/Auth, src/Permissions)     │
│                                                              │
│   Autenticación ──▶ Permisos ──▶ Validación ──▶ Controlador  │
│   (cadena de       (rol × recurso  (args del      (CRUD       │
│    proveedores)     × operación     esquema)       derivado)  │
│                     × campo)                                 │
└────────────────────────────────────┬─────────────────────────┘
                                     │
┌────────────────────────────────────▼─────────────────────────┐
│  CAPA DE DOMINIO  (src/Schema)                               │
│                                                              │
│   Registro de esquema ◀── Proveedores de campos              │
│   (normaliza, cachea)     nativo · ACF · JetEngine ·         │
│                           Meta Box · BD · manual             │
└────────────────────────────────────┬─────────────────────────┘
                                     │
┌────────────────────────────────────▼─────────────────────────┐
│  CAPA DE INFRAESTRUCTURA  (src/Core, src/Utils)              │
│  Contenedor DI · Configuración · Caché · Logging · Eventos   │
└────────────────────────────────────┬─────────────────────────┘
                                     │
┌────────────────────────────────────▼─────────────────────────┐
│  WORDPRESS                                                   │
│  WP_Query · WP_Roles · wp_postmeta · Object Cache · Uploads  │
└──────────────────────────────────────────────────────────────┘
```

### 2.2 Regla de dependencia

Las dependencias apuntan **hacia abajo**, nunca hacia arriba ni en diagonal ascendente.

| Capa | Puede depender de | No puede depender de |
| ---- | ----------------- | -------------------- |
| Entrada | Aplicación, Infraestructura | — |
| Aplicación | Dominio, Infraestructura | Entrada, Admin |
| Dominio | Infraestructura | Aplicación, Entrada, Admin |
| Infraestructura | Solo WordPress y PHP | Cualquier capa superior |
| Admin | Aplicación, Dominio, Infraestructura | — |

La consecuencia práctica más importante: **el registro de esquema no sabe que existe una API REST**. Se puede ejercitar desde WP-CLI o desde un test sin levantar una petición HTTP. Y `src/Api` no accede nunca a `wp_postmeta` directamente: pide esquemas al dominio.

---

## 3. Flujo de una petición

Recorrido completo de `GET /wp-json/codeia/v1/property?per_page=10&_fields=id,title,precio`:

```
 1. Entrada HTTP
    └─▶ ¿alias raíz activo? → rewrite reescribe a la ruta canónica
 2. WP REST Server resuelve namespace + ruta
    └─▶ ¿existe la ruta? ─── no ──▶ 404  rest_no_route
 3. AUTENTICACIÓN            [hook: determine_current_user]
    └─▶ cadena de proveedores, primero que responda gana
        ├── sin credenciales  ──▶ usuario anónimo (válido si el recurso es público)
        └── credencial mala   ──▶ 401  codeia_auth_invalid
 4. PERMISO DE RUTA          [permission_callback]
    └─▶ ¿rol puede OPERACIÓN sobre RECURSO? ─── no ──▶ 403  codeia_forbidden
 5. VALIDACIÓN DE ARGUMENTOS [args: validate_callback + sanitize_callback]
    └─▶ derivados del esquema del recurso ── falla ──▶ 400  rest_invalid_param
 6. RATE LIMITING
    └─▶ ventana deslizante por identidad ── excede ──▶ 429  codeia_rate_limited
 7. CONTROLADOR
    ├─▶ ¿respuesta en caché? ── sí ──▶ salta a 10
    ├─▶ consulta el registro de esquema (campos expuestos y sus tipos)
    ├─▶ WP_Query + precarga de meta en lote
    └─▶ prepare_item_for_response() por elemento
 8. FILTRADO POR CAMPO
    └─▶ elimina los campos que el rol no puede leer
 9. CACHÉ
    └─▶ guarda la respuesta con clave versionada
10. RESPUESTA
    └─▶ 200 + cabeceras X-WP-Total, X-WP-TotalPages, X-RateLimit-*
```

Dos decisiones no obvias del flujo:

- **El permiso de ruta (4) y el filtrado por campo (8) están separados a propósito.** El primero decide si la petición procede; el segundo, qué parte del resultado es visible. Fundirlos obligaría a resolver permisos de campo antes de saber qué elementos devuelve la consulta.
- **El rate limiting va después de autenticar (6, no 2).** Así el contador se asocia a la identidad real y no solo a la IP, que es fácil de rotar. El coste es que una credencial inválida consume ciclo de autenticación; se compensa con un límite por IP más agresivo para peticiones anónimas fallidas. Detalle en [09-seguridad.md](09-seguridad.md).

---

## 4. Estructura de carpetas

```
wp-api-codeia/
├── wp-api-codeia.php          → Cabecera, constantes, autoloader, arranque
├── uninstall.php              → Borrado de opciones y tablas propias
├── composer.json              → PSR-4: WpApi\Codeia\ → src/
├── docs/                      → Esta documentación (Markdown)
├── admin-ui/                  → Fuente React del dashboard (NO se distribuye)
├── assets/
│   ├── admin/                 → Salida del build de Vite (SÍ se versiona)
│   └── vendor/swagger-ui/     → Swagger UI servida localmente
├── languages/                 → Traducciones
└── src/                       → Todo el código PHP, PSR-4
    ├── Core/
    ├── Modules/
    ├── Admin/
    ├── Api/
    ├── Auth/
    ├── Schema/
    ├── Permissions/
    ├── OpenApi/
    └── Utils/
```

> **Nota sobre el nombre `OpenApi/`.** El módulo de generación de Swagger se llamaría naturalmente `Docs/`. Se elige `OpenApi/` por dos razones, ambas de legibilidad: describe qué produce el módulo —un documento OpenAPI— en lugar de nombrar una categoría vaga, y evita que «docs» signifique dos cosas distintas en el mismo repositorio, donde `docs/` ya es la documentación Markdown del proyecto.
>
> No hay impedimento técnico: `src/Docs/` y `docs/` están en directorios distintos y **no colisionan**, tampoco en Windows o macOS, cuya insensibilidad a mayúsculas solo afecta a entradas de un mismo directorio. Todo el código PHP vive bajo `src/` por convención PSR-4, no para evitar ese choque.

### 4.1 Responsabilidad de cada directorio

| Directorio | Responsabilidad | Ejemplos de contenido |
| ---------- | --------------- | --------------------- |
| *(raíz de `src/`)* | Arranque: el contenedor y el orquestador. | `Container`, `Plugin` |
| `Core/` | Infraestructura transversal. No conoce el dominio. | `ServiceProvider`, `Config`, `Cache/` (interfaz y 3 drivers), `Logger`, `EventDispatcher`, `Activator`, `Exceptions/` |
| `Modules/` | Unidades funcionales activables. Cada una se registra en el contenedor y puede apagarse desde el dashboard. | `AuthModule`, `RestModule`, `OpenApiModule`, `MediaModule` |
| `Api/` | Todo lo que toca HTTP: rutas, controladores, formato de respuesta y error. | `RouteRegistrar`, `ControllerFactory`, `ResourceController`, `MediaController`, `ResponseFormatter` |
| `Auth/` | Resolución de identidad y ciclo de vida de credenciales. | `AuthenticatorChain`, `JwtAuthenticator`, `ApiKeyAuthenticator`, `TokenRepository` |
| `Schema/` | Descubrimiento, normalización y caché del modelo de datos. El corazón del plugin. | `SchemaRegistry`, `FieldProvider` y adaptadores, `FieldNormalizer`, `SchemaCache` |
| `Permissions/` | Evaluación de acceso por rol, recurso, operación y campo. | `PermissionResolver`, `PermissionMatrix`, `FieldVisibility`, `CapabilityMapper` |
| `OpenApi/` | Traducción del esquema a OpenAPI 3.1 y servicio de la UI. | `SpecGenerator`, `SchemaMapper`, `SecuritySchemeBuilder`, `SwaggerUiPage` |
| `Admin/` | Dashboard: pantallas, settings, endpoints internos y encolado del bundle React de `assets/admin/`. | `Menu`, páginas por sección, `SettingsRegistrar`, `InternalRestController`, `AssetLoader` |
| `Utils/` | Funciones puras sin estado ni dependencias. | `Str`, `Arr`, `MimeGuesser`, `Hash` |

Criterio para decidir dónde va algo nuevo: **si necesita saber qué es un "recurso expuesto", no es `Core/` ni `Utils/`.**

---

## 5. Patrón arquitectónico

### 5.1 Service Layer sobre contenedor DI

El plugin no usa singletons dispersos ni funciones globales para acceder a servicios. Un **contenedor de inyección de dependencias** compatible con PSR-11 construye y cachea las instancias; los módulos las declaran mediante *service providers*.

```php
interface ServiceProvider {
    /** Declara factorías en el contenedor. No debe ejecutar lógica ni tocar la BD. */
    public function register( Container $c ): void;

    /** Engancha en WordPress. Aquí sí se llama a add_action/add_filter. */
    public function boot( Container $c ): void;
}
```

La separación `register()` / `boot()` es deliberada: garantiza que **todas** las factorías estén declaradas antes de que cualquier módulo intente resolver una dependencia. Sin ella, el orden de carga de los módulos se vuelve significativo y frágil.

**Alternativa descartada:** el patrón habitual en plugins de WordPress —una clase `Plugin` singleton con `get_instance()` y propiedades públicas para cada subsistema— es más corto de escribir, pero convierte cada dependencia en implícita y global. Con detección de esquema, proveedores conmutables y adaptadores opcionales, hace falta poder sustituir una pieza (un proveedor de caché falso, un `SchemaRegistry` con datos fijos) sin levantar WordPress entero. Un contenedor lo permite; un singleton no.

El contenedor es **ligero y propio** (~150 líneas: factorías, resolución perezosa, detección de dependencias circulares), no una dependencia externa. Un plugin distribuible que arrastra un contenedor completo vía Composer corre el riesgo de colisionar de versión con otro plugin que cargue el mismo paquete, un problema real en el ecosistema de WordPress por la ausencia de aislamiento de dependencias.

### 5.2 Secuencia de arranque

El orden importa porque cada fase depende de que WordPress haya inicializado algo concreto.

| # | Hook | Prioridad | Qué ocurre | Por qué aquí |
| - | ---- | --------- | ---------- | ------------ |
| 1 | *(carga del fichero)* | — | Constantes, autoloader, comprobación de PHP/WP mínimos | Antes de nada; si el entorno no cumple, se aborta con aviso en el admin y no se registra nada |
| 2 | `plugins_loaded` | 5 | Se construye el contenedor y se carga la configuración | Todos los plugins están cargados: ya se puede detectar si ACF o JetEngine existen |
| 3 | `plugins_loaded` | 10 | `register()` de todos los providers activos | Después de que la configuración diga qué módulos están activos |
| 4 | `init` | 20 | `boot()` de los providers; alias de rewrite si procede | Prioridad 20: los CPTs de otros plugins se registran típicamente en `init` con prioridad 10, y el esquema los necesita ya registrados |
| 5 | `rest_api_init` | 10 | Registro de rutas a partir de la configuración | Único momento válido para `register_rest_route()` |
| 6 | `admin_menu` | 10 | Alta del dashboard | Solo en contexto de administración |

El punto 4 es el más delicado: **enganchar en `init` con prioridad 10 provocaría que los post types de terceros aún no estuvieran registrados** y el catálogo apareciera vacío de forma intermitente, según el orden de carga de los plugins. La prioridad 20 evita esa condición de carrera, y el descubrimiento en frío se hace de todas formas contra el estado ya construido, nunca durante el registro.

### 5.3 Módulos

Cada módulo es un provider con una bandera de activación en la configuración. Un módulo desactivado no se registra: no cuelga hooks, no consume memoria y no aparece en OpenAPI. Esto es lo que hace que el plugin escale hacia abajo — una instalación que solo quiere lectura pública no paga el coste de la maquinaria de subida de medios.

| Módulo | Activo por defecto | Depende de |
| ------ | ------------------ | ---------- |
| `SchemaModule` | Sí (obligatorio) | — |
| `RestModule` | Sí | Schema |
| `AuthModule` | Sí | — |
| `PermissionsModule` | Sí (obligatorio) | Schema |
| `MediaModule` | No | Auth, Permissions |
| `OpenApiModule` | No | Schema, Rest |
| `RewriteModule` | No | Rest |

---

## 6. Sistema de eventos y extensibilidad

El plugin se extiende con los mecanismos nativos de WordPress —`do_action` y `apply_filters`— bajo un espacio de nombres propio con separador `/`, la convención que siguen el core moderno y Gutenberg.

```
codeia/<subsistema>/<evento>
```

`EventDispatcher` es una fachada delgada sobre esos hooks: centraliza el prefijo, documenta la firma de cada evento y permite instrumentar (medir, registrar) sin tocar los puntos de emisión. No sustituye a los hooks de WordPress; los envuelve, de modo que cualquier desarrollador puede seguir usando `add_filter()` directamente.

### 6.1 Catálogo inicial de puntos de extensión **(propuesto)**

| Hook | Tipo | Firma | Uso previsto |
| ---- | ---- | ----- | ------------ |
| `codeia/schema/providers` | filtro | `array $providers` | Registrar un proveedor de campos propio |
| `codeia/schema/resource_fields` | filtro | `array $fields, string $resource` | Añadir, quitar o retipar campos detectados |
| `codeia/schema/before_rebuild` | acción | `string $reason` | Instrumentar reconstrucciones de esquema |
| `codeia/auth/providers` | filtro | `array $providers` | Añadir un método de autenticación |
| `codeia/auth/authenticated` | acción | `int $user_id, string $provider` | Auditoría de accesos |
| `codeia/permissions/can` | filtro | `bool $allowed, PermissionContext $ctx` | Última palabra sobre una decisión de acceso |
| `codeia/permissions/field_visible` | filtro | `bool $visible, string $field, string $resource` | Ocultar campos por lógica propia |
| `codeia/rest/routes` | filtro | `array $routes` | Alterar el conjunto de rutas antes de registrarlas |
| `codeia/rest/prepare_item` | filtro | `array $data, WP_Post $post, WP_REST_Request $req` | Transformar la representación de un elemento |
| `codeia/openapi/spec` | filtro | `array $spec` | Ajustar el documento OpenAPI generado |
| `codeia/cache/invalidated` | acción | `string $group, string $reason` | Encadenar invalidaciones externas |

Regla de compatibilidad: **la firma de un hook publicado no cambia dentro de una misma versión mayor.** Añadir parámetros al final es aceptable; reordenar o cambiar tipos, no.

---

## 7. Logging

### 7.1 Interfaz y niveles

Interfaz compatible con PSR-3 en niveles y firma, sin heredar de la interfaz de PSR-3 para no arrastrar el paquete.

| Nivel | Cuándo | Ejemplo |
| ----- | ------ | ------- |
| `error` | Fallo que impide completar la operación | El proveedor de caché lanza una excepción durante el rebuild |
| `warning` | Degradación con continuidad | Sin object cache persistente; se cae a transients |
| `info` | Hecho relevante para auditoría | Esquema reconstruido; token revocado; configuración exportada |
| `debug` | Detalle de diagnóstico | Consulta de muestreo de `wp_postmeta` y su duración |

### 7.2 Almacenamiento

Tabla propia `{$wpdb->prefix}codeia_logs` **(propuesto)**, con `id`, `created_at`, `level`, `channel`, `message`, `context` (JSON) e `user_id`, indexada por `(created_at, level)`.

**Alternativas descartadas:**

- *Guardar en una opción.* `wp_options` con `autoload=yes` cargaría los logs en cada petición; con `autoload=no` sigue siendo un blob serializado que hay que leer y reescribir entero para añadir una línea. Inviable con volumen.
- *Solo `error_log()`.* No permite mostrar los logs en el dashboard, que es un requisito del panel de estado.

Retención: purga por antigüedad y por número máximo de filas, en un evento de `wp_cron` diario. Sin purga, la tabla de logs es la forma más común de que un plugin así degrade una instalación a lo largo de meses. El nivel mínimo registrado es configurable, con `debug` desactivado por defecto.

**Los logs nunca contienen credenciales.** Tokens, claves y contraseñas se registran, como mucho, por su prefijo y su hash truncado — nunca en claro.

---

## 8. Caché

### 8.1 Abstracción

Una interfaz `CacheInterface` (`get`, `set`, `delete`, `flush_group`) con tres drivers, elegidos automáticamente:

| Driver | Cuándo se usa | Persistencia | Coste |
| ------ | ------------- | ------------ | ----- |
| `ObjectCacheDriver` | Hay object cache persistente (Redis, Memcached) | Entre peticiones | Óptimo |
| `TransientDriver` | No la hay | Entre peticiones, vía `wp_options` | Escrituras en BD |
| `MemoryDriver` | Siempre, como primer nivel | Solo dentro de la petición | Nulo |

La selección se hace con `wp_using_ext_object_cache()`. El `MemoryDriver` actúa **siempre** como caché de primer nivel por delante del driver persistente: evita resolver el mismo esquema varias veces dentro de una petición, que es el caso más frecuente cuando una respuesta contiene muchos elementos del mismo recurso.

### 8.2 Grupos y claves

Tres grupos con ciclos de vida distintos e invalidación encadenada:

| Grupo | Contenido | TTL | Se invalida cuando |
| ----- | --------- | --- | ------------------ |
| `codeia_schema` | Esquemas normalizados por recurso | 12 h | Cambia el hash de entorno, se activa/desactiva un plugin, rebuild manual |
| `codeia_response` | Respuestas de lectura | 5 min | Cambia el esquema, se guarda o borra un post del recurso |
| `codeia_openapi` | Documento OpenAPI | 12 h | Cambia el esquema o la configuración de exposición |

Las claves llevan **una versión de esquema incorporada**, un hash corto derivado del estado del entorno (post types registrados, plugins activos, versión de la configuración):

```
codeia_response:{schema_version}:{resource}:{hash_de_argumentos}
```

Así, invalidar el esquema invalida en cascada todas las respuestas derivadas de él **sin recorrer ni borrar claves una a una** — operación que el object cache de WordPress no expone de forma fiable, ya que `wp_cache_flush_group()` no está soportado por todos los backends. Las entradas viejas quedan huérfanas y expiran por TTL.

Detalle completo de estrategias y presupuestos en [10-rendimiento.md](10-rendimiento.md).

---

## 9. Configuración

Toda la configuración vive en una única opción `codeia_settings` (`autoload = no`), con un número de versión de esquema que habilita migraciones:

```php
[
  'version'   => 3,
  'namespace' => 'codeia',      // prefijo REST configurable
  'api_version' => 'v1',
  'auth'      => [ 'providers' => ['jwt' => [...], 'app_password' => [...]] ],
  'resources' => [
      'property' => [
          'enabled'    => true,
          'operations' => ['read', 'create', 'update'],
          'fields'     => [ 'precio' => ['expose' => true, 'type' => 'number'], ... ],
      ],
  ],
  'permissions' => [ /* matriz rol × recurso × operación */ ],
  'modules'     => [ 'media' => false, 'openapi' => true, 'rewrite' => false ],
]
```

Migraciones idempotentes ejecutadas al detectar una `version` inferior a la del código. La estructura completa y su ciclo de vida se documentan en [08-dashboard-admin.md](08-dashboard-admin.md); el formato de exportación, en [11-escalabilidad.md](11-escalabilidad.md).

---

## 10. Convenciones

| Elemento | Convención | Ejemplo |
| -------- | ---------- | ------- |
| Namespace PHP | `WpApi\Codeia\<Subsistema>` | `WpApi\Codeia\Schema\SchemaRegistry` |
| Clases | `PascalCase`, una por archivo (PSR-4) | `PermissionResolver.php` |
| Métodos y variables | `snake_case` (WPCS) | `get_item_permissions_check()` |
| Hooks | Prefijo `codeia/` con `/` | `codeia/schema/providers` |
| Códigos de error | Prefijo `codeia_` | `codeia_field_forbidden` |
| Opciones | Prefijo `codeia_` | `codeia_settings` |
| Post meta propio | Prefijo `_codeia_` (protegido) | `_codeia_api_token_version` |
| Constantes | Prefijo `CODEIA_` | `CODEIA_PLUGIN_DIR` |
| Grupos de caché | Prefijo `codeia_` | `codeia_schema` |
| Tablas | `{$wpdb->prefix}codeia_` | `wp_codeia_logs` |

Requisitos técnicos: **WordPress 7.1+**, **PHP 8.0+**, PSR-4, text domain `wp-api-codeia`. Todo archivo PHP abre con `defined( 'ABSPATH' ) || exit;`.

El dashboard se construye con **React 18 + Vite** sobre `@wordpress/components`, con React externalizado a `wp-element`. Detalle del reparto entre Settings API y React, y del proceso de build, en [08-dashboard-admin.md](08-dashboard-admin.md).

---

## 11. Rutas y namespace

Ruta **canónica**, siempre disponible:

```
/wp-json/codeia/v1/{post_type}
/wp-json/codeia/v1/{post_type}/{id}
```

El segmento `codeia` es configurable desde el dashboard. Cambiarlo invalida el esquema, el documento OpenAPI y, si está activo el alias, obliga a regenerar las rewrite rules.

Adicionalmente, un **alias en raíz** opcional (`/codeia/v1/{post_type}`) sirve la misma API fuera de `/wp-json/`. Está desactivado por defecto y se documenta en [07-rewrite-rules.md](07-rewrite-rules.md) junto con la detección de colisiones contra slugs existentes.

**Por qué la ruta canónica vive bajo `/wp-json/` y no en la raíz:** el descubrimiento estándar de la API de WordPress (cabecera `Link: <...>; rel="https://api.w.org/"`, el índice en `/wp-json/`, clientes como `@wordpress/api-fetch` y las herramientas del ecosistema) da por hecho ese prefijo. Servir únicamente en la raíz rompería esa interoperabilidad y añadiría riesgo de colisión con slugs de páginas. El alias cubre el caso de URLs limpias sin renunciar a lo anterior.

---

## 12. Índice de la documentación

| # | Documento | Contenido | Madurez |
| - | --------- | --------- | ------- |
| — | **arquitectura.md** *(este)* | Visión, capas, estructura, DI, eventos, logging, caché | Diseño |
| 01 | [01-autenticacion.md](01-autenticacion.md) | Proveedores conmutables, middleware, ciclo de vida de tokens | Diseño |
| 02 | [02-endpoints-dinamicos.md](02-endpoints-dinamicos.md) | CRUD derivado, proyección de campos, filtros, paginación, relaciones | Diseño |
| 03 | [03-deteccion-cpt-campos.md](03-deteccion-cpt-campos.md) | Introspección en 4 niveles, adaptadores, normalización, caché | Diseño |
| 04 | [04-permisos.md](04-permisos.md) | Matriz rol × recurso × operación × campo | Diseño |
| 05 | [05-subida-imagenes.md](05-subida-imagenes.md) | Endpoint de medios, validación MIME, cuotas | Diseño |
| 06 | [06-swagger-openapi.md](06-swagger-openapi.md) | Generación OpenAPI 3.1, caché, UI embebida | Diseño |
| 07 | [07-rewrite-rules.md](07-rewrite-rules.md) | Alias raíz, flush controlado, colisiones, versionado | Diseño |
| 08 | [08-dashboard-admin.md](08-dashboard-admin.md) | Pantallas, Settings API, REST interno, nonces | Diseño |
| 09 | [09-seguridad.md](09-seguridad.md) | Rate limiting, sanitización, matriz de amenazas | Diseño |
| 10 | [10-rendimiento.md](10-rendimiento.md) | Cachés, consultas meta, presupuesto por petición | Diseño |
| 11 | [11-escalabilidad.md](11-escalabilidad.md) | Multisite, exportación, versionado, headless, SaaS | Diseño |

### Orden de lectura recomendado

Para implementar, no para hojear:

```
arquitectura.md  →  03-deteccion-cpt-campos  →  02-endpoints-dinamicos
                                                        │
                          01-autenticacion  ←───────────┤
                          04-permisos       ←───────────┤
                                                        ▼
                    05-subida-imagenes · 06-swagger-openapi · 07-rewrite-rules
                                                        │
                                                        ▼
                              08-dashboard-admin  →  09/10/11 (transversales)
```

La detección de esquema va primero porque **todo lo demás deriva de ella**: los endpoints, los permisos por campo y el documento OpenAPI son proyecciones del mismo modelo normalizado.
