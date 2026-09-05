# Changelog

Todos los cambios notables de este proyecto se documentan en este archivo.

El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/)
y este proyecto sigue [Semantic Versioning](https://semver.org/lang/es/).

## [1.0.0] - 2026-09-05 — Primera versión estable

Primera publicación en `main`. Reúne los ocho sprints del roadmap sin cambios
funcionales respecto a `0.10.0`: es la misma base, etiquetada como estable.

El plugin convierte WordPress en una API configurable desde su propio panel.
La premisa que lo ordena todo es que **el esquema no se declara, se descubre**:
el plugin introspecciona la instalación y presenta lo encontrado como catálogo
configurable, del que derivan los endpoints, el documento OpenAPI y las reglas
de acceso.

### Contenido

| Sprint | Versión | Aportación |
| ------ | ------- | ---------- |
| 1 | `0.2.0` | Contenedor DI, configuración, caché, registro, activación |
| 2 | `0.3.0` | Detección de esquema en cuatro niveles |
| 3 | `0.4.0` | Autenticación: JWT, API Key, Application Passwords, tokens |
| 4 | `0.5.0` | Permisos: matriz de cuatro ejes sobre las capabilities |
| 5 | `0.6.0` | Endpoints CRUD derivados, filtros, paginación por cursor |
| 6 | `0.7.0` | Medios con validación por contenido, y límite de peticiones |
| 7 | `0.8.0` | OpenAPI 3.1, ETag, alias de rutas en raíz |
| 8 | `0.9.0` · `0.10.0` | Dashboard: siete pantallas sobre la API interna |

### Estado

```
245 tests unitarios · 370 aserciones
192 tests de integración · 418 aserciones
 48 tests de JavaScript · 7 ficheros
phpcs: 0 errores, 21 avisos justificados
```

### Deuda conocida

- **`media.max_bytes` es configuración muerta.** `StatusChecker` la lee, pero
  `media` no existe en `Config::defaults()` y `Config::sanitize()` solo
  conserva las claves de primer nivel que sí existen: el valor nunca puede
  guardarse, así que la comprobación siempre compara contra el valor por
  defecto
- **Sin integración continua.** Los scripts de Composer y de npm encajan
  directamente en un workflow de GitHub Actions


## [0.10.0] - 2026-09-05 — Dashboard completo y correcciones de coherencia

Cierra el sprint 8, que se entregó con el backend terminado pero la interfaz
reducida a un volcado de JSON. Al construir las pantallas contra la API real
aparecieron cuatro defectos de coherencia entre módulos que la interfaz dejó
al descubierto; van corregidos aquí.

### Corregido

- **`auth.providers` no hacía nada.** El generador de OpenAPI respetaba la
  configuración pero `AuthServiceProvider` montaba los cuatro autenticadores
  siempre. Desactivar API Key lo ocultaba de la documentación y **seguía
  aceptando la credencial**, justo lo contrario de lo que espera quien lo
  desactiva. Ahora un proveedor desactivado no se monta
- **`permissions.defaults` se descartaba al guardar.** El nivel 1 de la matriz
  es un mapa `rol => booleano`, de forma distinta a los demás nodos, y
  `sanitize_permissions()` lo tiraba entero por no ser un array de reglas. El
  nivel 1 era inalcanzable desde el panel
- **El bundle dependía de un global no declarado.** El JSX se compilaba con el
  runtime clásico, que emite `React.createElement` sin declarar `React`;
  funcionaba solo porque `wp-element` arrastra el script `react` del núcleo.
  Ahora el JSX se compila contra `createElement` importado de
  `@wordpress/element`
- **`StatusChecker` devolvía identificadores, no etiquetas.** Un semáforo sin
  texto es ilegible para quien no distingue rojo y verde
- **Los tres módulos eran inalcanzables desde el panel.** `media`, `openapi` y
  `rewrite` están apagados de origen —cada uno amplía la superficie expuesta—
  pero ninguna pantalla los activaba: solo se podían encender editando la
  opción a mano. La pantalla de Documentación mostraba el «Failed to load API
  definition» crudo de Swagger UI, que no explica la causa ni ofrece salida
- **`RewriteModule::request_flush()` no lo llamaba nadie.** Cambiar el
  namespace o activar el alias guardaba la opción y dejaba las reglas viejas
  en su sitio: el alias respondía 404. Ahora `update_settings` marca la
  regeneración cuando cambia algo que afecta al enrutado, y solo entonces

### Añadido

- **Las siete pantallas, con contenido real**: Estado con semáforo etiquetado y
  cifras del esquema; Recursos con catálogo por campo —origen, confianza,
  candado de meta protegida, aviso de tipo ambiguo y relación propuesta—;
  Permisos con la cascada de cuatro niveles y el nivel 1 por separado;
  Autenticación con el criterio de cada proveedor; Documentación con Swagger
  UI; Registros con filtro por nivel y contexto desplegable; Herramientas con
  reconstrucción, exportación, importación y purga
- **Swagger UI servido en local** desde `assets/vendor/swagger-ui/`, copiado por
  el script `vendor:swagger`. Nunca desde un CDN: la pantalla vive detrás del
  login del administrador y un script de terceros ahí tendría ejecución en el
  contexto del panel. Se encola **solo** en la pantalla de documentación, porque
  son 1,7 MB
- **`POST admin/import`**: importación con simulación por defecto y **copia
  previa** antes de escribir. Aplicar exige pedirlo explícitamente: la
  importación sustituye la configuración entera y no hay deshacer
- **`DELETE admin/logs`**, que deja constancia de sí misma; `GET admin/settings`
  y `GET admin/roles`; filtros `level` y `channel` en `GET admin/logs`
- **Valores por defecto de `auth.providers`** según `docs/01-autenticacion.md`:
  Application Passwords y JWT activos, API Key y token de usuario no. Las
  credenciales de larga vida no se activan solas
- **Saneado de `auth` y `logging`**, con la retención de registros acotada
  entre 1 y 365 días
- **`.gitattributes`** con `* text=auto eol=lf`, pendiente desde el andamiaje
- **`composer.lock` versionado**: sin él, cada `composer install` trae versiones
  distintas y `wp-phpunit` deja de coincidir con el WordPress instalado
- **Tarjeta de módulos** en Herramientas, con el motivo de que cada uno esté
  apagado de origen, y activación en contexto desde la propia pantalla de
  Documentación
- **51 tests nuevos**: 9 de integración PHP y 42 de JavaScript, incluidos los
  primeros de renderizado con Testing Library


## [0.9.0] - 2026-09-04 — Sprint 8 · Dashboard Admin

### Añadido

- **`Menu`**: siete pantallas —Estado, Recursos, Permisos, Autenticación, Documentación, Registros y Herramientas—, todas con `manage_options`. No se contempla un rol intermedio: quien configura qué datos salen del sitio toma decisiones de administración plenas
- **`InternalRestController`**: rutas `admin/` que consume el cliente React. Es la **única** vía de datos — sin `admin-ajax` ni estado preinyectado más allá de la configuración de arranque —, así que la interfaz se puede ejercitar con `curl` durante el desarrollo. No aparecen en el documento OpenAPI
- **`Sanitizer`**: única puerta de escritura. Fusiona el fragmento con lo existente, **descarta claves desconocidas**, y filtra post types y roles que no existen en la instalación
- **`StatusChecker`**: diagnóstico con semáforo de object cache, enlaces permanentes, HTTPS, versión de PHP, registro, proveedores y coherencia de los límites de subida
- **`ConfigExporter`**: formato portable con `format_version` **independiente de la versión del plugin**, y comparación con el entorno de destino que detecta post types y roles ausentes. **Nunca incluye secretos**
- **`AssetLoader`**: encola por `screen_id`, no en todo el admin. Inyecta `window.codeiaAdmin` con posición `before` — con React montándose al cargar, hacerlo después dejaría la aplicación sin `root` ni `nonce` en su primer render
- **`admin-ui/`**: React 18 + Vite con **React externalizado a `wp-element`**. Empaquetarlo añadiría ~130 KB y arriesgaría dos instancias en la misma página, con los errores de contexto y hooks que eso provoca
- **La matriz desactiva las celdas que las capabilities ya impiden**, en vez de permitir marcar algo que la segunda puerta rechazaría siempre
- **25 tests nuevos**: 19 de integración PHP y 6 de JavaScript con Vitest

### Corregido

- `AssetLoader` encolaba `index.css` incondicionalmente, pero el bundle solo lo genera si hay estilos: producía un 404 en cada carga de la pantalla. Ahora se comprueba su existencia

### Notas técnicas

- Los assets construidos en `assets/admin/` **se versionan**, para que el plugin funcione desde un clon o un zip sin Node. La salida usa nombre estable, sin hash por build, para que los diffs sigan siendo legibles
- `phpcs` con el estándar WordPress: **0 errores**

## [0.8.0] - 2026-09-04 — Sprint 7 · Swagger & Performance

### Añadido

- **`SpecGenerator`**: documento OpenAPI 3.1 derivado de los **mismos objetos que producen las rutas**. Que ambos salgan del mismo sitio es lo que impide la deriva entre documentación e implementación; la alternativa habitual —anotaciones o YAML aparte— envejece en cuanto alguien cambia la configuración
- **El documento varía por ámbito de permisos.** Uno único con todos los campos filtraría la existencia de `cadastral_ref` a un consumidor anónimo: el **nombre** de un campo ya es información
- **Tres esquemas por recurso**, no uno: lectura, creación y actualización. Uno compartido produce clientes generados que envían `id` en el `POST` o consideran obligatorio en escritura lo que solo aparece en lectura
- **`SchemaMapper`**: conversión draft-04 → 2020-12. No es cosmética — `exclusiveMinimum` es un booleano junto a `minimum` en draft-04 y el propio valor en 2020-12; traducirlo mal cambia la semántica del límite
- **`SecuritySchemeBuilder`**: declara solo los proveedores **activos**. Documentar JWT desactivado invita a integrar contra algo que devolverá 401
- **`StampedeLock`**: reserva con `add()`, que solo tiene éxito si la clave no existe y por eso es atómico. Quien no obtiene el cerrojo sirve la versión obsoleta en lugar de reconstruir en paralelo
- **`EtagManager`**: `ETag` e `If-None-Match` con soporte de forma débil. Convierte una respuesta de 200 KB en unas cabeceras cuando nada ha cambiado
- **`DocsController`**: endpoint `/docs` **privado por defecto**. Un documento OpenAPI es un mapa completo de la superficie de ataque; publicarlo debe ser deliberado. Con modo no público emite `Cache-Control: private, no-store`
- **41 tests nuevos**: 26 unitarios y 15 de integración, incluida la verificación de que toda referencia `$ref` resuelve y no hay `operationId` duplicados

### Notas técnicas

- `phpcs` con el estándar WordPress: **0 errores**

## [0.7.0] - 2026-09-04 — Sprint 6 · Media & Security

### Añadido

- **`SlidingWindow`**: ventana deslizante de dos contadores. La ventana fija admitiría 120 peticiones al final de un minuto y 120 al principio del siguiente —240 en dos segundos—; esta variante lo impide con coste constante
- **`RateLimiter`**: límites por identidad y por IP, con cabeceras `X-RateLimit-*` y `Retry-After`. Se aplica **después** de autenticar para que el contador se asocie a la identidad real y no solo a la IP, que es fácil de rotar. El endpoint de emisión limita **por IP y por usuario a la vez**: solo por IP, un ataque distribuido contra una cuenta pasa
- **`IpResolver`**: `REMOTE_ADDR` es lo único fiable. `X-Forwarded-For` solo se lee si el administrador declara rangos de proxy de confianza; sin esa condición, cualquiera esquivaría el límite rotando la cabecera. La IP se almacena **hasheada** por ser dato personal
- **`MimeValidator`**: valida por **contenido**, con `finfo` más `wp_check_filetype_and_ext`. Ni el `type` de `$_FILES`, ni la extensión, ni el `Content-Type` son fiables. **SVG queda fuera y sin opción en la interfaz**: es XML que admite `<script>`
- **Límite de dimensiones** comprobado antes de procesar: un PNG enorme puede pesar poco comprimido y agotar la memoria al generar los tamaños derivados
- **`MediaController`**: endpoint propio que delega en `wp_handle_upload()` y `wp_insert_attachment()`. Lo propio es la capa de política. Comprueba `edit_post` sobre el **post destino** — el punto que el controlador nativo no cubre — y lo hace **antes** de escribir nada
- **`QuotaManager`**: cuotas de ficheros y bytes por usuario y ventana. Subida anónima imposible y no configurable
- **`ExifCleaner`**: elimina la geolocalización por defecto. Publicar la foto con su GPS anularía la ocultación de `latitude` y `longitude` que hace la matriz de permisos
- **`Deduplicator`**: reutiliza el adjunto existente ante reintentos de cliente por timeout
- **`CorsHandler`**: CORS configurable con `Vary: Origin` siempre que el origen no sea `*` —sin él, una caché intermedia puede servir a un origen la cabecera de otro— y sin comodines de subdominio
- **33 tests nuevos**: 17 unitarios y 16 de integración, centrados en lo que debe rechazarse

### Notas técnicas

- El entorno de tests necesita ahora las extensiones **GD** y **exif** además de las anteriores, para generar y analizar imágenes reales
- El módulo de medios está **desactivado por defecto**: la subida es la superficie de ataque más peligrosa de una API
- `phpcs` con el estándar WordPress: **0 errores**

## [0.6.0] - 2026-09-04 — Sprint 5 · Endpoints CRUD

### Añadido

- **La API ya expone contenido.** Este sprint junta las tres capas anteriores: el esquema define las rutas, la autenticación resuelve la identidad y los permisos deciden el acceso
- **`ResourceController`** sobre `WP_REST_Controller`: un solo controlador sirve a **todos** los recursos, parametrizado por su `ResourceDefinition`. No se genera una clase por post type
- **Las rutas nacen con el `PermissionResolver` real.** No hubo `permission_callback` provisional en ningún momento — que era el riesgo que evitamos al reordenar los sprints
- **Las operaciones no habilitadas no se registran**: responden `404`, no `403`. No revela qué operaciones existen pero están vetadas
- **`QueryBuilder`**: traduce filtros con el `type` correcto en `meta_query`. Sin `NUMERIC`, MySQL compara `LONGTEXT` como cadena y `"300000" < "89000"` resulta verdadero. Todo identificador del cliente se traduce por lista blanca del esquema; si no traduce, se rechaza
- **`FieldProjector`**: tres filtros en orden estricto — expuesto en configuración, visible para el rol, pedido con `_fields`. **`_fields` solo puede reducir, jamás ampliar**
- **`CursorPaginator`**: paginación keyset sobre `(post_date_gmt, ID)`, firmada con HMAC. Coste constante a cualquier profundidad y estable ante inserciones
- **`RouteRegistrar`**: registro diferido en `rest_api_init`. Una carga del front-end no paga el coste de construir definiciones
- **`RewriteModule`**: alias en raíz opcional que reenvía al mismo `WP_REST_Server`, con `REST_REQUEST` definida antes del dispatch y `flush_rewrite_rules()` diferido
- **`CollisionDetector`**: bloquea la activación del alias si el prefijo choca con una página, un post type, una taxonomía o un prefijo reservado
- **37 tests nuevos**: 11 unitarios y 26 de integración sobre CRUD real con permisos aplicados

### Corregido

- **`CursorPaginator` no traducía el cursor a SQL.** Codificaba, decodificaba y dejaba el argumento en la consulta, pero nada lo consumía: la segunda página devolvía la primera otra vez. Añadido el filtro `posts_where` que genera la condición keyset. Lo detectó el test que comprueba que dos páginas consecutivas no comparten ningún ID
- `per_page` no validaba su máximo: faltaba declarar `validate_callback` explícitamente, porque WordPress no lo añade solo a los args declarados a mano

## [0.5.0] - 2026-09-04 — Sprint 4 · Permissions & Utils

### Añadido

- **Modelo de permisos de cuatro ejes** (`src/Permissions/`): rol × recurso × operación × campo
- **Dos puertas independientes y ambas obligatorias.** La matriz del plugin expresa qué se expone hacia fuera; las capabilities de WordPress son el modelo de permisos real del sitio. **La matriz solo puede restringir, nunca ampliar** — si pudiera, un usuario sin `edit_posts` editaría contenido por REST porque alguien marcó mal una casilla, y la API sería una vía para eludir los permisos del sitio
- **`PermissionMatrix`**: cascada de cuatro niveles donde gana la regla **más específica**, no la más permisiva. Un editor con `property.read` permitido y `property.read.price` denegado lee propiedades sin ver el precio. Denegación por defecto en toda combinación no declarada, de modo que actualizar el plugin nunca amplía el acceso de nadie
- **`CapabilityMapper`**: deriva las capabilities del `capability_type` del post type, sin codificarlas a mano. Comprueba la capability de **objeto** además de la de colección — omitirla es el fallo de autorización más común en endpoints REST personalizados, porque concede a cualquier autor la edición del contenido ajeno
- **`PermissionResolver`**: une las dos puertas y cachea por rol dentro de la petición. Las decisiones sobre un objeto concreto **no** se cachean, para que la resolución de un post no arrastre la de otro
- **`FieldVisibility`**: asimetría deliberada entre lectura y escritura. En lectura los campos vetados **se omiten** —devolverlos como `null` confirmaría que existen—; en escritura se **rechaza la petición entera**, porque un `200` a un cliente que cree haber guardado el dato es una inconsistencia que aparece mucho después
- **`CollectionRestrictor`**: acota los elementos visibles **en la consulta**, nunca filtrando el resultado después. Filtrar a posteriori rompe la paginación: pedir 20 y descartar 7 devuelve 13 y `X-WP-Total` deja de cuadrar
- **`src/Utils/`**: `Arr`, `Str` y `Hash`, funciones puras sin estado
- **Cinco puntos de extensión**: `codeia/permissions/can`, `field_visible`, `field_writable` y `collection_args`. El filtro `can` recibe el contexto con la decisión previa y el nivel de la cascada que la produjo — sin ese dato, el código de terceros solo podría decidir a ciegas
- **57 tests nuevos**: 29 unitarios y 28 de integración contra roles y capabilities reales de WordPress

### Notas técnicas

- Cero comparaciones por nombre de rol en el camino de decisión: los roles son agrupaciones mutables de capabilities y cualquier plugin de membresía las altera. Se usan como eje de la matriz —es lo que un administrador entiende— pero la comprobación efectiva siempre acaba en `user_can()`
- Una configuración con valores no interpretables **no concede acceso**: se ignora en vez de asumir permiso
- `phpcs` con el estándar WordPress: **0 errores**

## [0.4.0] - 2026-09-04 — Sprint 3 · Authentication

### Añadido

- **Cadena de autenticación conmutable** (`src/Auth/`) con cuatro proveedores activos y OAuth2 preparado como extensión futura
- **`JwtCodec`**: emisión y verificación HS256. El algoritmo se **compara contra el esperado, nunca se lee del token** para decidir cómo verificar, lo que cierra `alg: none` y la confusión HS/RS. Valida en orden formato → algoritmo → firma → claims, para que un error de claim no sirva de oráculo sobre tokens sin firmar
- **`AuthenticatorChain`**: separa `handles()` de `authenticate()`, distinguiendo «no traes credenciales mías» de «traes las mías y son inválidas». Sin esa distinción, un token caducado caería al siguiente proveedor y acabaría en un 403 confuso en vez de un 401
- **`RefreshTokenService`**: rotación con familias y **detección de reutilización**. Un refresh ya consumido que reaparece solo se explica por carrera del cliente o por robo; como no se distinguen, cae la familia entera
- **`TokenVersion`**: revocación masiva con un entero en user meta. Cambiar contraseña o rol la incrementa e invalida todos los tokens previos con una sola escritura
- **`RevocationList`**: revocación individual por `jti`, acotada por la vigencia del token y no por el histórico
- **`TokenRepository`** y **`OpaqueToken`**: API Keys y tokens de usuario almacenados solo como hash SHA-256, comparados con `hash_equals`. El alfabeto excluye el punto para poder distinguirlos de un JWT en el esquema Bearer
- **`AuthMiddleware`**: engancha en `determine_current_user` a prioridad 15 —por encima de la cookie, por debajo de Application Passwords— con guarda de reentrada. Devuelve `true` en `rest_authentication_errors` **solo tras autenticar**; hacerlo siempre desactivaría la protección CSRF de toda la instalación
- **`AuthController`**: `/auth/token` y `/auth/refresh`. Mensaje idéntico para usuario inexistente y contraseña incorrecta, para no convertir el endpoint en un oráculo de enumeración
- **71 tests nuevos**: 39 unitarios y 32 de integración

### Corregido

- **`Logger` degrada en silencio si su tabla no existe.** Antes emitía el error de base de datos de WordPress directamente en la salida, corrompiendo cualquier respuesta JSON. Ahora suprime el error, marca el registro como no disponible para el resto de la petición y deja de reintentar

### Notas técnicas

- Los endpoints de auth son públicos por necesidad, pero **no usan `__return_true`**: su `permission_callback` exige transporte cifrado y rechaza la emisión sobre HTTP
- El secreto de firma nunca reutiliza `AUTH_KEY` ni las sales del núcleo: comparten propósito con las cookies de sesión, y compartirlo convertiría la fuga de uno en el compromiso de ambos
- Las credenciales opacas usan SHA-256 y no `wp_hash_password()`: 256 bits de entropía aleatoria no necesitan el coste de bcrypt, cuyo propósito es frenar ataques de diccionario contra contraseñas humanas
- `phpcs` con el estándar WordPress: **0 errores**

## [0.3.0] - 2026-09-04 — Sprint 2 · Schema Detection

### Añadido

- **Detección de esquema en cuatro niveles** (`src/Schema/`), el módulo del que derivan endpoints, permisos por campo y OpenAPI
- **`FieldDefinition`**: modelo normalizado con `origin` y `confidence`. Sin trazabilidad de origen, un esquema equivocado es imposible de depurar
- **`ExclusionList`**: descarta ruido por **lista explícita de claves del núcleo, nunca por el prefijo `_`**. En `flavor-real-estate` los 28 campos útiles llevan guion bajo inicial; filtrar por prefijo los eliminaría todos. Reconoce además los punteros `field_*` de ACF y colapsa claves indexadas de repetidor
- **`TypeInferrer`**: infiere tipos de una muestra de hasta 50 valores, con los casos trampa documentados — `{0,1}` se propone booleano pero **marcado ambiguo** (puede ser un contador), y los numéricos con ceros a la izquierda y longitud fija se quedan en `string` para no destruir códigos postales
- **`FieldNormalizer`**: traduce `_property_price` a `price` y resuelve colisiones. Los campos nativos (`id`, `title`, `status`…) siempre ganan; una clave que choque conserva su nombre completo y el conflicto queda registrado
- **`ConflictResolver`**: fusiona por confianza y rellena huecos con los candidatos menores. Ante empate con tipos distintos aplica `string`, marca ambiguo y **deja el campo sin exponer** hasta que alguien decida
- **Seis proveedores**: `NativeProvider` (nivel 1), `AcfProvider`, `MetaBoxProvider`, `JetEngineProvider` (nivel 2), `DbSampleProvider` (nivel 3) y `ManualProvider` (nivel 4). Cada adaptador de terceros se desactiva solo si su API no responde, sin impedir que el resto del esquema se construya
- **`DbSampleProvider`**: consulta agregada sobre `wp_postmeta` acotada por post type. Propone relaciones exigiendo dos señales —sufijo `_id`/`_ids` y valores que resuelven a posts de un mismo tipo— y **nunca las activa sola**
- **`SchemaCache`** y **`SchemaRegistry`**: caché con clave versionada por hash de entorno; la invalidación es implícita, sin depender de `wp_cache_flush_group()`
- **33 tests nuevos**: 26 unitarios y 16 de integración contra un post type sembrado con `update_post_meta()` sin `register_meta()`, reproduciendo el caso real

### Corregido

- `SchemaRegistry::build()` declaraba `$object` pero el cuerpo usaba `$type_object`. El operador `??` enmascaraba el fallo y `label` caía en silencio al slug del post type en vez de a su etiqueta. Detectado por `phpcs` antes de volver a ejecutar los tests

### Notas técnicas

- `phpcs` con el estándar WordPress: **0 errores**
- Las consultas directas de `DbSampleProvider` están justificadas en el ruleset: descubrir qué claves existen es su razón de ser y WordPress no ofrece API para ello
- Los tests unitarios replican `is_serialized`, `maybe_unserialize` e `is_protected_meta` con su comportamiento real. Un stub que devolviera siempre `false` ocultaría justo los casos que `TypeInferrer` debe distinguir

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
