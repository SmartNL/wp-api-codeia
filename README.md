# WP API Codeia

Plugin de WordPress que convierte una instalación en una **API personalizada configurable desde un dashboard propio**. El administrador elige qué tipos de contenido se exponen, qué campos de cada uno, con qué autenticación y bajo qué permisos; el plugin deriva de esa configuración los endpoints REST, la documentación OpenAPI y las reglas de acceso.

La premisa que lo diferencia de escribir endpoints a mano: **el esquema no se declara, se descubre**. El plugin introspecciona la instalación —tipos de contenido, taxonomías y campos personalizados de ACF, JetEngine, Meta Box o `register_meta`— y presenta el resultado como catálogo configurable.

> **Estado:** fase de diseño. La arquitectura está documentada por completo; **todavía no hay código de plugin**. Lo descrito abajo es el diseño acordado, no funcionalidad disponible.

## Requisitos

- **WordPress**: 7.1 o superior
- **PHP**: 8.0 o superior

## Instalación

```bash
cd wp-content/plugins/
git clone <url-del-repositorio> wp-api-codeia
```

Después actívalo desde **Escritorio → Plugins** en el administrador de WordPress.

## Capacidades previstas

| Área | Qué hace |
| ---- | -------- |
| **Autenticación** | JWT, Application Passwords, API Key propia, token por usuario. OAuth2 preparado para el futuro |
| **Endpoints dinámicos** | CRUD derivado por post type, con selección granular de campos, filtros, ordenación, paginación y relaciones |
| **Detección automática** | Descubre CPTs, taxonomías y campos personalizados en cuatro niveles, incluido el muestreo de base de datos |
| **Permisos** | Matriz rol × recurso × operación × campo, sobre las capabilities reales de WordPress |
| **Subida de medios** | Endpoint propio con validación MIME por contenido, límites por rol y cuotas |
| **OpenAPI** | Documento 3.1 generado de la configuración vigente, con Swagger UI embebida |
| **Dashboard** | Configuración, panel de estado, registros y herramientas |

## API REST

Ruta **canónica**, siempre disponible:

```
/wp-json/codeia/v1/{post_type}
/wp-json/codeia/v1/{post_type}/{id}
```

El segmento `codeia` es **configurable** desde el dashboard; `codeia/v1` es el valor por defecto.

Opcionalmente, un **alias en raíz** (`/codeia/v1/{post_type}`) sirve la misma API fuera de `/wp-json/` mediante rewrite rules. Está desactivado por defecto y requiere enlaces permanentes activos.

### Endpoints por recurso expuesto

| Método | Ruta | Operación |
| ------ | ---- | --------- |
| `GET` | `/{post_type}` | Listar |
| `POST` | `/{post_type}` | Crear |
| `GET` | `/{post_type}/{id}` | Obtener |
| `PUT` · `PATCH` | `/{post_type}/{id}` | Actualizar |
| `DELETE` | `/{post_type}/{id}` | Eliminar |
| `GET` | `/{post_type}/schema` | Esquema del recurso |

Las operaciones no habilitadas no se registran: responden `404`, no `403`.

### Endpoints del sistema

| Método | Ruta | Función |
| ------ | ---- | ------- |
| `POST` | `/auth/token` | Emitir credencial |
| `POST` | `/auth/refresh` | Renovar con rotación |
| `POST` | `/media` | Subir imagen |
| `GET` | `/docs` | Documento OpenAPI (privado por defecto) |

## Documentación

Diseño técnico completo en [docs/](docs/). Empieza por el documento principal:

| # | Documento | Contenido |
| - | --------- | --------- |
| — | **[Arquitectura](docs/arquitectura.md)** | Visión, capas, estructura de carpetas, contenedor DI, eventos, logging y caché |
| 01 | [Autenticación](docs/01-autenticacion.md) | Proveedores conmutables, middleware, ciclo de vida de tokens |
| 02 | [Endpoints dinámicos](docs/02-endpoints-dinamicos.md) | CRUD derivado, proyección de campos, filtros, paginación, relaciones |
| 03 | [Detección de CPT y campos](docs/03-deteccion-cpt-campos.md) | Introspección en 4 niveles, adaptadores, normalización |
| 04 | [Permisos](docs/04-permisos.md) | Matriz rol × recurso × operación × campo |
| 05 | [Subida de imágenes](docs/05-subida-imagenes.md) | Validación MIME, límites por rol, cuotas |
| 06 | [Swagger / OpenAPI](docs/06-swagger-openapi.md) | Generación 3.1, caché, UI embebida |
| 07 | [Rewrite rules](docs/07-rewrite-rules.md) | Alias raíz, flush controlado, colisiones |
| 08 | [Dashboard](docs/08-dashboard-admin.md) | Pantallas, Settings API, REST interno, nonces |
| 09 | [Seguridad](docs/09-seguridad.md) | Rate limiting, sanitización, matriz de amenazas |
| 10 | [Rendimiento](docs/10-rendimiento.md) | Cachés, consultas meta, presupuesto por petición |
| 11 | [Escalabilidad](docs/11-escalabilidad.md) | Multisitio, exportación, versionado, headless |

## Desarrollo

- El código seguirá los [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/php/).
- [.editorconfig](.editorconfig) aplica tabuladores en PHP/JS/CSS y finales de línea `LF`. Activa el soporte de EditorConfig en tu editor.
- Namespace PHP `WpApi\Codeia` con autoloading PSR-4 sobre `src/`.
- Cada ruta REST declara un `permission_callback` explícito. Nunca `'__return_true'`.

## Changelog

Los cambios notables se documentan en [CHANGELOG.md](CHANGELOG.md), siguiendo [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/) y [Semantic Versioning](https://semver.org/lang/es/).

## Licencia

GPL-2.0-or-later
