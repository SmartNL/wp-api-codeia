# Changelog

Todos los cambios notables de este proyecto se documentan en este archivo.

El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/)
y este proyecto sigue [Semantic Versioning](https://semver.org/lang/es/).

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

### Decisiones de diseño registradas

- **Namespace REST configurable**, con `codeia/v1` por defecto
- **Ruta canónica bajo `/wp-json/`**, con alias en raíz opcional vía rewrite rules — preserva el descubrimiento estándar de la API de WordPress
- **Detección de campos multi-nivel**: la introspección nativa no basta. Verificado contra el plugin `flavor-real-estate` de esta instalación, que guarda sus ~28 campos con `update_post_meta()` sin `register_meta()`, por lo que son invisibles a `get_registered_meta_keys()`
- **Exclusión de claves meta por lista explícita, no por prefijo `_`**: en ese mismo plugin todos los campos útiles usan guion bajo inicial, así que filtrar por prefijo los descartaría todos
- **Denegación por defecto** en permisos, campos, operaciones y módulos
- **Código bajo `src/`** y módulo de OpenAPI llamado `OpenApi/` en vez de `Docs/`, para no colisionar con `docs/` en sistemas de archivos insensibles a mayúsculas

_Sin código de plugin todavía. La primera versión publicada será la `0.1.0`._
