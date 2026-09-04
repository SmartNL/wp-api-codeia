# Changelog

Todos los cambios notables de este proyecto se documentan en este archivo.

El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/)
y este proyecto sigue [Semantic Versioning](https://semver.org/lang/es/).

## [0.2.0] - 2026-09-04 — Sprint 1 · Foundation

### Añadido

- **Punto de entrada** `wp-api-codeia.php`: cabecera, constantes, autoloader y guardas de PHP 8.0+ / WordPress 7.1+ con aviso en el admin en lugar de fatal
- **`Container`**: contenedor DI con resolución perezosa, cacheo de instancias y detección de dependencias circulares. Compatible con la firma de PSR-11 sin heredar del paquete, para no arrastrar una dependencia de runtime que colisione con otros plugins
- **`Plugin`**: orquestador del arranque con las prioridades de hook documentadas (`plugins_loaded` 5 y 10, `init` 20). Instancia única sin `get_instance()` ni subsistemas globales
- **`ServiceProvider`**: contrato con `register()` y `boot()` separados, para que todas las factorías existan antes de que nadie resuelva
- **`Config`**: opción única con `autoload = no`, lectura por notación de punto, migraciones idempotentes y saneado que descarta claves desconocidas y aplica topes duros
- **Caché en dos niveles**: `CacheInterface` con `MemoryDriver` (L1), `ObjectCacheDriver` y `TransientDriver` (L2), coordinados por `CacheManager` con promoción a L1 y TTL reducidos cuando no hay object cache persistente
- **`Logger`**: cuatro niveles sobre tabla propia `codeia_logs`, con redacción automática de credenciales y purga por antigüedad vía cron
- **`EventDispatcher`**: fachada sobre los hooks de WordPress con el prefijo `codeia/`
- **`Activator`**: creación de tabla, configuración por defecto sin pisar la existente y gestión del cron
- **`uninstall.php`**: limpieza completa, con recorrido por sitios en multisitio
- **Infraestructura de tests**: `composer.json` (PSR-4), `phpunit.xml.dist` con suites separadas, `phpcs.xml.dist` y `wp-tests-config.php`
- **72 tests**: 55 unitarios con Brain Monkey y 17 de integración contra el WordPress de Local, sobre la base de datos `local_tests`

### Notas técnicas

- `phpcs` con el estándar WordPress: **0 errores, 0 avisos**
- Se excluye `WordPress.Files.FileName` porque PSR-4 y el formato del núcleo (`class-foo.php`) son incompatibles; manda el autoloader
- Las consultas directas a `codeia_logs` están documentadas en el ruleset: WordPress no ofrece API de alto nivel para tablas propias
- El entorno de tests corre **sobre Local**, sin Docker: PHP 8.2.29, MySQL 8.4.0 y WordPress 7.1 de la propia instalación, con `wp-phpunit/wp-phpunit` fijado a la versión 7.1.0
- `Activator::create_tables()` cumple los tres requisitos de formato de `dbDelta` (sin backticks, dos espacios tras `PRIMARY KEY`, una definición por línea); incumplirlos hace que la tabla no se cree y sin error

## [Unreleased]

### Añadido

- **Documentación de arquitectura**: 12 documentos en `docs/` que definen el diseño completo del plugin antes de escribir código
  - `arquitectura.md` — documento principal: visión de producto, vista de capas, regla de dependencia, flujo end-to-end de una petición, estructura de carpetas, Service Layer sobre contenedor DI, secuencia de arranque, sistema de eventos, logging y caché
  - `01-autenticacion.md` — cadena de proveedores (JWT, Application Passwords, API Key, token por usuario, OAuth2 futuro), middleware sobre `determine_current_user`, refresh con rotación y detección de reutilización, revocación por *token version*
  - `02-endpoints-dinamicos.md` — fábrica de controladores sobre `WP_REST_Controller`, proyección de campos en tres niveles, filtros con lista blanca, paginación por offset y por cursor, relaciones y precarga en lote
  - `03-deteccion-cpt-campos.md` — introspección en 4 niveles (registro nativo, adaptadores ACF/JetEngine/Meta Box, muestreo de base de datos, declaración manual), modelo de campo normalizado con origen y confianza, resolución de conflictos
  - `04-permisos.md` — modelo de cuatro ejes, doble puerta (matriz del plugin + capabilities de WordPress), resolución en cascada, matriz por defecto y visibilidad por campo
  - `05-subida-imagenes.md` — endpoint propio delegando en el núcleo, validación MIME por contenido, límites por rol, cuotas y deduplicación por hash
  - `06-swagger-openapi.md` — generación OpenAPI 3.1 desde la misma fuente que las rutas, conversión draft-04 → 2020-12, documento por ámbito de permisos, Swagger UI con assets locales
  - `07-rewrite-rules.md` — alias en raíz con reenvío al REST server, `flush_rewrite_rules()` diferido, detección de colisiones y convivencia de versiones
  - `08-dashboard-admin.md` — siete pantallas, criterio Settings API frente a REST interno, esquema de configuración y migraciones
  - `09-seguridad.md` — rate limiting por ventana deslizante, validación en el borde, matriz de 13 amenazas con su mitigación
  - `10-rendimiento.md` — jerarquía de caché en tres niveles, claves versionadas, protección contra estampida, presupuesto por petición y antipatrones
  - `11-escalabilidad.md` — multisitio, exportación e importación versionada, política de deprecación, CORS y camino hacia un panel externo

- **`CLAUDE.md`**: contexto para agentes de código — estado real del proyecto, comandos previstos, arquitectura condensada y las reglas no obvias del diseño que se romperían aplicando el enfoque por defecto

### Cambiado

- **Stack del dashboard**: `08-dashboard-admin.md` pasa de «REST interno + JS sin framework» a **React 18 + Vite** sobre `@wordpress/components`, con React externalizado a `wp-element`. Se añade la sección de build (`admin-ui/` → `assets/admin/`, assets construidos versionados)
- `.gitignore`: `/node_modules/` → `node_modules/` sin anclar, para que cubra `admin-ui/node_modules/`
- `.editorconfig`: la regla de tabuladores incluye `jsx`, `ts`, `tsx`, `mjs` y `cjs`

### Corregido

- **`arquitectura.md`**: la nota que justificaba el nombre `OpenApi/` afirmaba que `src/Docs/` colisionaría con `docs/` en sistemas de archivos insensibles a mayúsculas. Es falso — están en directorios distintos y la insensibilidad solo afecta a entradas de un mismo directorio. El nombre se mantiene por ser más descriptivo, con la justificación reescrita

### Decisiones de diseño registradas

- **Namespace REST configurable**, con `codeia/v1` por defecto
- **Ruta canónica bajo `/wp-json/`**, con alias en raíz opcional vía rewrite rules — preserva el descubrimiento estándar de la API de WordPress
- **Detección de campos multi-nivel**: la introspección nativa no basta. Verificado contra el plugin `flavor-real-estate` de esta instalación, que guarda sus ~28 campos con `update_post_meta()` sin `register_meta()`, por lo que son invisibles a `get_registered_meta_keys()`
- **Exclusión de claves meta por lista explícita, no por prefijo `_`**: en ese mismo plugin todos los campos útiles usan guion bajo inicial, así que filtrar por prefijo los descartaría todos
- **Denegación por defecto** en permisos, campos, operaciones y módulos
- **Dashboard con React 18 + Vite**, acotado a las cuatro pantallas con estado complejo (Recursos, Permisos, Estado, Registros); el resto usa Settings API y la API funciona sin JavaScript
- **Código bajo `src/`** y módulo de OpenAPI llamado `OpenApi/` en vez de `Docs/`, para no colisionar con `docs/` en sistemas de archivos insensibles a mayúsculas

_Sin código de plugin todavía. La primera versión publicada será la `0.1.0`._
