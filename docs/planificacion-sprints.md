# Planificación de sprints — wp-api-codeia

Roadmap de implementación en 8 sprints. Cada uno tiene su versión y su rama, y no se da por terminado hasta que su suite de tests está en verde.

> **Estado actual:** `v0.4.0` — sprint 3 (Authentication) implementado y verificado en `sprint/03-authentication`. Sin commitear, a la espera del OK.

---

## 1. Resumen

| Sprint | Nombre | Versión | PHP | JS | Tests | Casos |
| :----: | ------ | ------- | --: | -: | ----: | ----: |
| — | *Documentación* | `v0.1.0` | 0 | 0 | — | — |
| 1 | Foundation ✅ | `v0.2.0` | **16** | 0 | **7** | **72** |
| 2 | Schema Detection ✅ | `v0.3.0` | **16** | 0 | **5** | **54** |
| 3 | Authentication ✅ | `v0.4.0` | **17** | 0 | **6** | **71** |
| 4 | Permissions & Utils | `v0.5.0` | ~9 | 0 | ~7 | ~50 |
| 5 | Endpoints CRUD | `v0.6.0` | ~11 | 0 | ~9 | ~55 |
| 6 | Media & Security | `v0.7.0` | ~10 | 0 | ~8 | ~50 |
| 7 | Swagger & Performance | `v0.8.0` | ~9 | 0 | ~7 | ~40 |
| 8 | Dashboard Admin | `v0.9.0` | ~16 | ~30 | ~9 | ~45 |
| **Total** | | **`v0.9.0`** | **~104** | **~30** | **~58** | **~437** |

`v1.0.0` se alcanza al mergear `development` → `main` con todo verificado.

**Qué mide cada columna.** `PHP` y `JS` cuentan archivos de código de producción. `Tests` cuenta archivos de test; `Casos`, ejecuciones de PHPUnit — un método con `@dataProvider` produce varias. La distinción importa: el sprint 1 tiene 7 archivos, 64 métodos y 72 ejecuciones, tres cifras que se confunden con facilidad.

**Recalibración tras el sprint 1.** Las estimaciones iniciales daban ~15 archivos PHP y «~10 tests» para Foundation. El recuento de archivos acertó (16 reales), pero la columna de tests mezclaba archivos y casos: 10 estimados frente a 72 ejecuciones reales. Las cifras de arriba ya separan ambas magnitudes y proyectan los sprints restantes con la densidad observada — en torno a **4 casos por clase de producción**, más en los sprints con lógica de seguridad o muchas ramas condicionales.

Los sprints 2 y 3 llevan la densidad más alta del proyecto: la inferencia de tipos tiene numerosos casos límite documentados (valores `0/1` ambiguos, ceros a la izquierda, serializados) y la autenticación exige probar los ataques que debe rechazar, no solo el camino feliz.

El total de PHP es alto porque el diseño es estrictamente PSR-4 de una clase por archivo: seis proveedores de detección, cinco autenticadores, tres drivers de caché y siete pantallas de administración son, por sí solos, veintiún archivos. Una referencia que agrupe varias clases por archivo dará cifras muy inferiores para el mismo alcance.

---

## 2. Cobertura de la documentación

Los 8 sprints cubren los 12 documentos de arquitectura. Ninguno queda fuera:

| Documento | Sprint |
| --------- | ------ |
| [arquitectura.md](arquitectura.md) | 1 · Foundation |
| [03-deteccion-cpt-campos.md](03-deteccion-cpt-campos.md) | 2 · Schema Detection |
| [01-autenticacion.md](01-autenticacion.md) | 3 · Authentication |
| [04-permisos.md](04-permisos.md) | 4 · Permissions & Utils |
| [02-endpoints-dinamicos.md](02-endpoints-dinamicos.md) | 5 · Endpoints CRUD |
| [07-rewrite-rules.md](07-rewrite-rules.md) | 5 · Endpoints CRUD |
| [05-subida-imagenes.md](05-subida-imagenes.md) | 6 · Media & Security |
| [09-seguridad.md](09-seguridad.md) | 6 · Media & Security |
| [06-swagger-openapi.md](06-swagger-openapi.md) | 7 · Swagger & Performance |
| [10-rendimiento.md](10-rendimiento.md) | 7 · Swagger & Performance |
| [11-escalabilidad.md](11-escalabilidad.md) | 7 (CORS, multisitio, versionado) · 8 (export/import) |
| [08-dashboard-admin.md](08-dashboard-admin.md) | 8 · Dashboard Admin |

### Por qué este orden

**Autenticación y permisos van antes que los endpoints.** Cuando llega el sprint 5, el esquema, la identidad y la matriz de permisos ya existen y están probados: los endpoints solo cablean piezas terminadas y nacen con su `permission_callback` real desde la primera línea.

El orden alternativo —endpoints antes que permisos— obligaría a registrar rutas con un `permission_callback` provisional fijo a `manage_options` y a recordar eliminarlo tres sprints después. Ese tipo de provisional es exactamente el que se queda en producción.

**Contrapartida asumida:** los tests de integración del sprint 4 no pueden usar los endpoints reales, que aún no existen. Registran rutas mínimas de prueba desde el propio fixture para ejercitar `PermissionResolver` contra WordPress real. El sprint 5 vuelve a verificar el cableado sobre las rutas definitivas.

---

## 3. Flujo de ramas

```
main          ●─●─●─●                    intocable · solo releases etiquetados
                    └── development      integración de sprints
                            ├── sprint/01-foundation
                            ├── sprint/02-schema-detection
                            └── ...
```

| Rama | Función | Quién escribe en ella |
| ---- | ------- | --------------------- |
| `main` | Releases estables, etiquetados | Solo merges desde `development`, a petición explícita |
| `development` | Integración continua de sprints | Solo merges desde ramas de sprint |
| `sprint/NN-nombre` | Trabajo de un sprint | Commits de desarrollo |

Remoto: `origin` → [SmartNL/wp-api-codeia](https://github.com/SmartNL/wp-api-codeia) (privado). Las tres ramas tienen seguimiento configurado.

### Versionado

Cada sprint fija su versión en tres sitios que deben coincidir:

1. Cabecera `Version:` de `wp-api-codeia.php`
2. Constante `CODEIA_VERSION`
3. Entrada en `CHANGELOG.md`

**Los tags solo existen en `main`.** Al fusionar `development` → `main` se etiqueta con la versión del último sprint incluido. Un merge puede arrastrar varios sprints; el tag refleja el más reciente.

---

## 4. Ciclo de un sprint

```
1. Crear rama sprint/NN-nombre desde development
2. Implementar según los documentos de referencia
3. Ejecutar la suite completa (unit + integración + phpcs)
4. Presentar resumen de implementación + resumen de tests
5. ⏸  ESPERAR el OK del usuario
6. Solo entonces: sugerir commit y merge a development
7. ⏸  ESPERAR petición explícita para ejecutarlos
```

Entre los pasos 2 y 6 el trabajo permanece sin commitear. Es consecuencia de la regla de no ejecutar operaciones de git sin petición explícita.

### Definición de hecho

Aplica a todos los sprints sin excepción:

- [ ] Implementado según sus documentos; toda discrepancia se resuelve actualizando código **y** documento
- [ ] `phpcs --standard=WordPress src/` sin errores
- [ ] Suites unit e integración en verde
- [ ] Cubiertas las reglas no obvias de [CLAUDE.md](../CLAUDE.md) que apliquen al sprint
- [ ] Versión sincronizada en los tres sitios
- [ ] `defined( 'ABSPATH' ) || exit;` en todo fichero PHP nuevo
- [ ] Tabla de Resumen actualizada con los conteos reales
- [ ] Resumen de implementación y de tests presentado

---

## 5. Infraestructura de tests

**Sin Docker.** Todo corre sobre la instalación de Local (`session21.local`): su PHP 8.2.29, su MySQL 8.4.0 y su WordPress 7.1. El framework de tests del núcleo llega como dependencia de Composer (`wp-phpunit/wp-phpunit`, versión fijada a la de WordPress instalada).

| Suite | Herramienta | Qué cubre | Velocidad |
| ----- | ----------- | --------- | --------- |
| `tests/Unit/` | PHPUnit + Brain Monkey + Mockery | Lógica pura con WordPress mockeado | ms |
| `tests/Integration/` | PHPUnit + `wp-phpunit` sobre Local | `WP_Query`, REST server, capabilities, `wp_postmeta` reales | s |

El PHP de Local arranca sin `php.ini` y necesita uno propio que cargue `openssl`, `curl`, `mbstring`, `zip`, `fileinfo` y `mysqli` — esta última imprescindible para la validación MIME del sprint 6. Detalle en [CLAUDE.md](../CLAUDE.md).

### ⚠ Base de datos de tests

Los tests de integración usan **`local_tests`**, nunca `local`. La suite de WordPress ejecuta `DROP` sobre todas las tablas de la base que se le indique en cada arranque. La configuración vive en `wp-tests-config.php`, con prefijo `wptests_` como segunda barrera.

### El fixture es real

El plugin hermano `flavor-real-estate`, activo en esta instalación, se usa como **fixture de integración**, no como mock. Aporta 2 CPTs, 7 taxonomías y 28 campos meta guardados con `update_post_meta()` **sin `register_meta()`** — exactamente el caso que justifica la detección multinivel del sprint 2. Probar contra él vale más que cualquier escenario inventado.

---

## 6. Detalle por sprint

### Sprint 1 — `v0.2.0` — Foundation

Rama `sprint/01-foundation` · Documento: [arquitectura.md](arquitectura.md)

**Entregables**

- `wp-api-codeia.php` — cabecera, constantes, autoloader, guardas de PHP/WP mínimos
- `uninstall.php`, `composer.json`, `phpunit.xml.dist`, `phpcs.xml.dist`, `wp-tests-config.php`
- `src/Core/`: `Container` (PSR-11, resolución perezosa, detección de ciclos), `ServiceProvider` (`register`/`boot`), `Plugin` (secuencia de arranque), `Config`, `Activator`, `Deactivator`, `Logger` + tabla `codeia_logs`, `EventDispatcher`
- `src/Core/Cache/`: `CacheInterface`, `CacheManager`, `MemoryDriver`, `ObjectCacheDriver`, `TransientDriver`

**Tests unit** — resolución del contenedor y detección de ciclos; `Config` (defaults, fusión, descarte de claves desconocidas, migraciones idempotentes); los tres drivers y que `MemoryDriver` actúa como L1; `EventDispatcher`; `Logger` no registra credenciales.

**Tests integración** — la activación crea la tabla; la desactivación limpia; el orden de hooks se respeta (`init` a prioridad 20); una carga de front-end no registra rutas ni consulta esquema.

### Sprint 2 — `v0.3.0` — Schema Detection

Rama `sprint/02-schema-detection` · Documento: [03](03-deteccion-cpt-campos.md)

**Entregables** — `src/Schema/`: `SchemaRegistry`, `ResourceDefinition`, `FieldDefinition`, `FieldNormalizer`, `TypeInferrer`, `ConflictResolver`, `SchemaCache`, `ExclusionList`; `src/Schema/Providers/`: interfaz `FieldProvider` con `NativeProvider`, `AcfProvider`, `MetaBoxProvider`, `JetEngineProvider`, `DbSampleProvider`, `ManualProvider`.

**Tests unit** — normalización de nombres y colisiones; inferencia de tipos con los casos trampa del documento (`0/1` ambiguo, código postal con ceros a la izquierda, valores serializados); resolución de conflictos por confianza; **la lista de exclusión no filtra por prefijo `_`**.

**Tests integración (contra `flavor-real-estate`)**

- Detecta `property` y `flavor_agent` con sus 7 taxonomías
- `get_registered_meta_keys()` devuelve vacío, pero el muestreo encuentra los 28 campos `_property_*`
- `_property_price` → `number`; `_property_postal_code` → `string`; `_property_gallery` → `array`
- Propone la relación `_property_agent_id` → `flavor_agent` **sin activarla**
- `env_hash` cambia al activar o desactivar un plugin
- El muestreo **no** se ejecuta durante una petición de API

### Sprint 3 — `v0.4.0` — Authentication

Rama `sprint/03-authentication` · Documento: [01](01-autenticacion.md)

**Entregables** — `src/Auth/`: interfaz `Authenticator`, `AuthenticatorChain`, `JwtAuthenticator`, `ApiKeyAuthenticator`, `AppPasswordAuthenticator`, `UserTokenAuthenticator`, `TokenRepository`, `RefreshTokenService`, `RevocationList`, `TokenVersion`; `src/Auth/Jwt/`: `JwtEncoder`, `JwtDecoder`; `src/Api/AuthController`.

**Tests unit** — firma y verificación JWT; **rechazo de `alg: none` y de confusión HS/RS**; validación de `exp`, `iss` y `tv`; comparación en tiempo constante; generación de claves con `random_bytes`.

**Tests integración**

- `determine_current_user` a prioridad 15, sin recursión
- `rest_authentication_errors` devuelve `true` **solo** tras autenticar
- Una petición con token no exige nonce de cookie
- La rotación de refresh invalida el anterior
- **Reutilizar un refresh revoca la familia entera**
- Cambiar la contraseña incrementa `tv` e invalida los tokens
- Mensaje idéntico para usuario inexistente y contraseña errónea

### Sprint 4 — `v0.5.0` — Permissions & Utils

Rama `sprint/04-permissions-utils` · Documento: [04](04-permisos.md)

**Entregables** — `src/Permissions/`: `PermissionResolver`, `PermissionMatrix`, `PermissionContext`, `FieldVisibility`, `CapabilityMapper`, `CollectionRestrictor`; `src/Utils/`: `Str`, `Arr`, `Hash`.

**Tests unit** — cascada de especificidad (el nivel 4 gana al 3); denegación por defecto ante combinación no declarada; matriz por defecto; visibilidad de campo en lectura y escritura.

**Tests integración** — sobre rutas mínimas registradas por el fixture, ya que los endpoints reales llegan en el sprint 5:

- Las dos puertas se evalúan; la matriz **no puede ampliar** capabilities
- Un `author` no edita contenido ajeno (capability de objeto)
- Elemento no visible → **404, no 403**
- Campo invisible → **400 `unknown_field`, no 403**
- Lectura omite los campos vetados, sin devolverlos a `null`
- Escritura con campo vetado **rechaza la petición entera**
- La restricción de colección se aplica en `WP_Query`

### Sprint 5 — `v0.6.0` — Endpoints CRUD

Rama `sprint/05-endpoints-crud` · Documentos: [02](02-endpoints-dinamicos.md) y [07](07-rewrite-rules.md)

**Entregables** — `src/Api/`: `RouteRegistrar`, `ControllerFactory`, `ResourceController` (sobre `WP_REST_Controller`), `QueryBuilder`, `FieldProjector`, `CursorPaginator`, `ResponseFormatter`, `ErrorFormatter`; `src/Modules/RewriteModule`; `src/Rewrite/`: `CollisionDetector`, `RequestForwarder`.

Las rutas nacen con el `PermissionResolver` del sprint 4: **no hay `permission_callback` provisional en ningún momento.**

**Tests unit** — `QueryBuilder` traduce filtros con el `type` correcto en `meta_query`; listas blancas de `orderby` y de campos; tope de cláusulas; cursor codificado y verificado por HMAC con rechazo de cursor manipulado; detección de colisiones de rewrite.

**Tests integración**

- CRUD sobre `property` con permisos reales aplicados
- Paginación por offset y por cursor, estable ante inserciones
- Ordenación con desempate por `ID`
- **Conteo de consultas**: 20 elementos con relación en ≤ 5 consultas
- `_fields` no amplía la proyección
- **Paridad canónica/alias**: mismo status, cuerpo y cabeceras
- `REST_REQUEST` definida antes del dispatch; cuerpo de `POST` y `Authorization` reenviados
- `flush_rewrite_rules()` no se ejecuta en `init` sin bandera

### Sprint 6 — `v0.7.0` — Media & Security

Rama `sprint/06-media-security` · Documentos: [05](05-subida-imagenes.md) y [09](09-seguridad.md)

**Entregables** — `src/Api/MediaController`; `src/Media/`: `UploadHandler`, `MimeValidator`, `QuotaManager`, `Deduplicator`, `ExifCleaner`; `src/Security/`: `RateLimiter`, `SlidingWindow`, `IpResolver`, `CorsHandler`.

**Tests unit** — discrepancia entre extensión y contenido; lista blanca; ventana de cuotas; la ventana deslizante no admite 2× el límite en el borde; `IpResolver` ignora `X-Forwarded-For` sin proxy declarado.

**Tests integración**

- Subida real de JPEG
- **PHP renombrado a `.jpg` rechazado**
- SVG rechazado
- PNG de dimensiones excesivas rechazado **antes** de procesarlo
- `edit_post` comprobado sobre el post destino
- **El fichero se borra si la asociación falla**
- Deduplicación por hash devuelve 200; EXIF de GPS eliminado; subida anónima imposible
- 429 con `Retry-After` y cabeceras `X-RateLimit-*`
- `Vary: Origin` siempre que el origen no sea `*`

### Sprint 7 — `v0.8.0` — Swagger & Performance

Rama `sprint/07-swagger-performance` · Documentos: [06](06-swagger-openapi.md), [10](10-rendimiento.md) y [11](11-escalabilidad.md) *(parcial)*

**Entregables** — `src/OpenApi/`: `SpecGenerator`, `SchemaMapper` (draft-04 → 2020-12), `SecuritySchemeBuilder`, `SpecCache`, `SwaggerUiPage`; `src/Api/DocsController`; `src/Core/Cache/StampedeLock`; `src/Core/EtagManager`; `src/Modules/MultisiteSupport`. Swagger UI en `assets/vendor/`.

**Tests unit** — mapeo de tipos; conversión de `exclusiveMinimum`; `securitySchemes` solo de proveedores activos; anotación `x-codeia-confidence` en campos inferidos; cerrojo contra estampida.

**Tests integración** — el documento valida; todo `$ref` resuelve; sin `operationId` duplicados; rutas `admin/` **excluidas**; **el documento del ámbito anónimo no menciona campos restringidos**; `ETag` devuelve 304; presupuesto de rendimiento del [documento 10](10-rendimiento.md) verificado; en multisitio la configuración y los secretos son por sitio.

### Sprint 8 — `v0.9.0` — Dashboard Admin

Rama `sprint/08-dashboard-admin` · Documentos: [08](08-dashboard-admin.md) y [11](11-escalabilidad.md) *(export/import)*

**Entregables** — `src/Admin/`: `Menu`, `SettingsRegistrar`, `Sanitizer`, `InternalRestController`, `AssetLoader`, `StatusChecker`, `LogViewer`, `ConfigExporter`, `ConfigImporter`, y las 7 pantallas en `src/Admin/Pages/`. `admin-ui/` con React 18 + Vite, React externalizado a `wp-element`, salida versionada en `assets/admin/`.

**Tests unit** — el sanitizador fusiona, valida y **descarta claves desconocidas**; migraciones idempotentes con copia previa; la exportación no incluye secretos; la importación compara entorno.

**Tests integración** — toda ruta `admin/` exige `manage_options`; **ningún `permission_callback` con `'__return_true'` en toda la base**; nonce verificado; rebuild por lotes con progreso; assets encolados solo en su `screen_id`.

**Tests JS** — Vitest sobre la matriz de permisos: las celdas se desactivan cuando la capability no existe.

---

## 7. Comprobaciones transversales

Se repiten al cerrar cada sprint:

```bash
grep -rn "__return_true" src/           # debe estar vacío
grep -rLn "defined( 'ABSPATH' )" src/   # ningún fichero sin la guarda
composer lint                           # phpcs, estándar WordPress
composer test                           # unit + integración
```
