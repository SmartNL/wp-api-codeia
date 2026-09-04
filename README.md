# WP API Codeia

Plugin de WordPress que expone endpoints REST propios bajo un namespace dedicado, construido sobre la API REST nativa de WordPress y sin dependencias externas.

> **Estado:** repositorio recién inicializado. Todavía no hay código de plugin — solo documentación y convenciones. Lo descrito en «Estructura del proyecto» y «API REST» es el diseño previsto.

## Requisitos

- **WordPress**: 7.1 o superior
- **PHP**: 8.0 o superior

## Instalación

Clona el repositorio dentro del directorio de plugins de tu instalación:

```bash
cd wp-content/plugins/
git clone <url-del-repositorio> wp-api-codeia
```

Después actívalo desde **Escritorio → Plugins** en el administrador de WordPress.

## Estructura del proyecto

```
wp-api-codeia/
├── .editorconfig          → Convenciones de estilo (WPCS)
├── .gitignore
├── CHANGELOG.md           → Historial de cambios
├── README.md
└── docs/
    └── arquitectura.md    → Diseño técnico y decisiones
```

El código del plugin (`wp-api-codeia.php`, `includes/`) se añadirá en próximos commits. La estructura prevista está documentada en [docs/arquitectura.md](docs/arquitectura.md).

## API REST

Todos los endpoints se registrarán bajo el namespace `codeia/v1`:

```
https://<sitio>/wp-json/codeia/v1/<recurso>
```

| Método | Ruta | Descripción | Autenticación |
| ------ | ---- | ----------- | ------------- |
| —      | —    | _Sin endpoints definidos todavía_ | — |

La convención de rutas, el formato de respuesta y los códigos de error están descritos en [docs/arquitectura.md](docs/arquitectura.md#diseño-de-la-api-rest).

## Desarrollo

- El código sigue los [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/php/).
- El archivo [.editorconfig](.editorconfig) aplica indentación con tabuladores en PHP/JS/CSS y finales de línea `LF`. Asegúrate de que tu editor tenga soporte para EditorConfig activado.
- Cada endpoint REST debe declarar un `permission_callback` explícito. Consulta la sección de seguridad en [docs/arquitectura.md](docs/arquitectura.md#seguridad).

## Changelog

Los cambios notables se documentan en [CHANGELOG.md](CHANGELOG.md), siguiendo [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/) y [Semantic Versioning](https://semver.org/lang/es/).

## Licencia

GPL-2.0-or-later
