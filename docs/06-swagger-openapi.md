# 06 — Generación automática de Swagger / OpenAPI

Define cómo el plugin produce un documento OpenAPI 3.1 a partir de la configuración vigente, lo mantiene sincronizado y lo sirve con una interfaz navegable.

> La documentación **no se escribe, se deriva**. Si hace falta actualizarla a mano cuando cambia la configuración, el diseño está mal.

---

## 1. Fuente única

El generador no inspecciona la instalación por su cuenta. Consume exactamente los mismos objetos que producen las rutas:

```
ResourceDefinition  ──┬──▶ register_rest_route()      (lo que la API hace)
   (de 03)            │
                      ├──▶ get_item_schema()          (contrato del recurso)
                      │
                      └──▶ SpecGenerator ──▶ OpenAPI  (lo que la API dice que hace)
```

Que las rutas y el documento salgan del mismo objeto es lo que impide la deriva entre ambos. La alternativa habitual —anotaciones en docblocks, ficheros YAML mantenidos aparte— produce documentación que envejece en cuanto alguien cambia la configuración desde el dashboard, que es precisamente el caso de uso central de este plugin.

`get_item_schema()` de `WP_REST_Controller` ya es el contrato que el núcleo usa para validar argumentos y responder a `OPTIONS`. Reutilizarlo significa que **validación y documentación no pueden discrepar**: si un campo acepta un valor, el documento lo refleja.

---

## 2. Por qué OpenAPI 3.1

| | 3.0 | **3.1** |
| --- | --- | --- |
| Base de esquemas | Subconjunto propio de JSON Schema | JSON Schema 2020-12 completo |
| Nulos | `nullable: true` | `type: ["string","null"]` |
| `examples` | Uno por medio | Múltiples, estándar |
| Webhooks | No | Sí |
| Soporte en Swagger UI | Total | Desde 4.x |

Se elige **3.1** porque su modelo de esquemas es JSON Schema estándar, y los esquemas de WordPress ya son JSON Schema. En 3.0 habría que traducir cada `type` nulo a `nullable`, un paso de conversión con pérdida y una fuente de errores sutiles.

### 2.1 La incompatibilidad que hay que resolver

Los esquemas de WordPress siguen **JSON Schema draft-04**; OpenAPI 3.1 usa **2020-12**. No son idénticos:

| draft-04 (WP) | 2020-12 (OpenAPI 3.1) |
| ------------- | --------------------- |
| `"exclusiveMinimum": true` + `"minimum": 0` | `"exclusiveMinimum": 0` |
| `"id"` | `"$id"` |
| `"definitions"` | `"$defs"` |
| Ausencia = opcional | Igual, pero `required` es array en ambos |
| `"type": "string"` con `null` implícito | `"type": ["string","null"]` explícito |

`SchemaMapper` hace esa conversión. No es cosmética: `exclusiveMinimum` mal traducido cambia la semántica de la validación de un límite.

---

## 3. Mapeo de tipos

Del modelo de campo normalizado ([03 §7](03-deteccion-cpt-campos.md)) al esquema OpenAPI:

| Campo (`type`/`format`) | OpenAPI | Notas |
| ----------------------- | ------- | ----- |
| `integer` | `{"type":"integer"}` | `format: int64` si procede |
| `number` | `{"type":"number"}` | |
| `string` | `{"type":"string"}` | |
| `string` + `date-time` | `{"type":"string","format":"date-time"}` | |
| `string` + `uri` | `{"type":"string","format":"uri"}` | |
| `string` + `email` | `{"type":"string","format":"email"}` | |
| `boolean` | `{"type":"boolean"}` | |
| `array` | `{"type":"array","items":{...}}` | `items` del tipo del elemento |
| `object` | `{"type":"object"}` | `additionalProperties: true` si la forma es desconocida |
| con `enum` | `{"enum":[...]}` | De las opciones detectadas |
| relación | `{"type":"integer"}` + `x-codeia-relation` | Extensión propia |
| confianza < 50 | Se añade `"x-codeia-confidence"` | Marca el tipo como inferido |

### 3.1 Campos inferidos

Un campo detectado por muestreo de base de datos tiene confianza 40: su tipo es una conjetura. El documento lo refleja con una extensión propia y una nota en la descripción:

```json
"price": {
  "type": "number",
  "description": "Precio. Tipo inferido del contenido de la base de datos.",
  "x-codeia-confidence": 40,
  "x-codeia-origin": "db_sample"
}
```

Publicar un tipo inferido como si fuera declarado es peor que no documentarlo: un consumidor generaría un cliente tipado sobre una conjetura. Las extensiones `x-` son válidas en OpenAPI y las herramientas las ignoran sin fallar.

---

## 4. Estructura del documento

```yaml
openapi: 3.1.0
info:
  title: "{nombre del sitio} — API"
  version: "1.0.0"          # versión de la configuración, no del plugin
servers:
  - url: https://ejemplo.com/wp-json/codeia/v1
  - url: https://ejemplo.com/codeia/v1     # solo si el alias está activo
components:
  securitySchemes: { ... }   # §5
  parameters:                # reutilizables
    Page: { ... }
    PerPage: { ... }
    Fields: { ... }
  schemas:
    Property: { ... }
    PropertyCreate: { ... }  # sin campos de solo lectura
    Error: { ... }
  responses:
    Unauthorized: { ... }
    Forbidden: { ... }
    NotFound: { ... }
    RateLimited: { ... }
paths:
  /property: { get: ..., post: ... }
  /property/{id}: { get: ..., patch: ..., delete: ... }
```

**Tres esquemas por recurso, no uno.** `Property` (lectura, incluye campos calculados y de solo lectura), `PropertyCreate` (escritura, sin `id` ni calculados, con los obligatorios marcados) y `PropertyUpdate` (escritura, todo opcional). Un único esquema compartido produce clientes generados que envían `id` en el `POST` o consideran obligatorio en escritura lo que solo aparece en lectura.

Las respuestas de error se declaran en `components/responses` y se referencian, en lugar de repetirse en cada operación. Con 6 recursos × 5 operaciones × 5 errores, la diferencia entre referenciar y repetir son unos cientos de líneas.

---

## 5. Esquemas de seguridad

Se generan **solo para los proveedores activos** ([01](01-autenticacion.md)). Documentar JWT cuando está desactivado invita a integrar contra algo que devolverá 401.

```yaml
components:
  securitySchemes:
    bearerAuth:                       # si JWT activo
      type: http
      scheme: bearer
      bearerFormat: JWT
    appPassword:                      # si Application Passwords activo
      type: http
      scheme: basic
    apiKey:                           # si API Key activa
      type: apiKey
      in: header
      name: X-Codeia-Key
```

Cada operación declara qué admite. Las de lectura sobre recursos con acceso anónimo llevan `security: [{}, {bearerAuth: []}]` — el objeto vacío significa «también sin autenticar», que es información relevante para quien integra.

OAuth2 aparecería como `type: oauth2` con sus flujos cuando el proveedor exista **(futuro)**; el generador ya contempla la rama.

---

## 6. El documento depende de quién lo pide

Un detalle que se pasa por alto con facilidad: **el documento OpenAPI describe campos, y los campos tienen visibilidad por rol** ([04 §4.1](04-permisos.md)).

Generar un único documento con todos los campos filtraría la existencia de `cadastral_ref` o `price_before` a un consumidor anónimo, aunque nunca pudiera leer sus valores. El nombre de un campo ya es información.

Por eso el documento se genera **por ámbito de permisos**, con la misma clave que las respuestas:

```
codeia_openapi:{schema_version}:{fields_hash}
```

Un anónimo obtiene el documento de los campos públicos; un editor, el suyo. Los administradores ven además una vista completa en el dashboard, con los campos restringidos marcados y anotado qué rol los alcanza — es la vista útil para configurar, y solo se sirve a quien ya tiene `manage_options`.

---

## 7. Exposición

| Modo | Ruta | Quién accede | Uso |
| ---- | ---- | ------------ | --- |
| **Privado** (por defecto) | — | Solo el dashboard | Desarrollo interno |
| Autenticado | `/codeia/v1/docs` | Identidades válidas | Socios de integración |
| Público | `/codeia/v1/docs` | Cualquiera | API abierta |

Formatos: `/docs` devuelve JSON; `/docs?format=yaml`, YAML.

Por defecto **privado**. Un documento OpenAPI es un mapa completo de la superficie de ataque: rutas, parámetros, tipos y métodos de autenticación. Publicarlo es una decisión legítima para una API abierta, pero debe ser deliberada.

`/docs` **nunca** se cachea en CDN ni en caché de página cuando el modo es autenticado: su contenido varía por ámbito de permisos. Se envía `Cache-Control: private, no-store` en ese caso.

---

## 8. Interfaz Swagger embebida

Swagger UI en una pantalla del dashboard, con los assets **servidos desde el plugin**, no desde CDN.

Razones, en orden de importancia:

1. **Cadena de suministro.** Un `<script src="https://cdn.../swagger-ui.js">` en el admin de WordPress ejecuta código de terceros con la sesión del administrador. Un CDN comprometido es una toma de control del sitio.
2. **CSP.** Muchas instalaciones corporativas aplican Content-Security-Policy restrictiva; un asset externo simplemente no carga.
3. **Funcionamiento sin red.** Entornos locales y redes internas sin salida.
4. **Estabilidad.** La versión queda fijada; no cambia bajo los pies por un despliegue ajeno.

Los ficheros se distribuyen en `assets/vendor/swagger-ui/` con su versión fijada, y se encolan con `wp_enqueue_script()` usando `CODEIA_VERSION` como cache-buster.

La UI se inicializa contra el documento del propio sitio, con el nonce `wp_rest` inyectado para que **«Try it out» funcione con la sesión del administrador** sin pedir un token aparte.

---

## 9. Caché e invalidación

### 9.1 Coste

Generar el documento recorre todos los recursos expuestos, todos sus campos y todas sus operaciones. Con 6 recursos y ~30 campos cada uno son decenas de miles de operaciones de composición de arrays: decenas o cientos de milisegundos. Demasiado para hacerlo en cada petición a `/docs`.

### 9.2 Cadena de invalidación

```
Cambia el entorno (plugins, CPTs)  ─┐
Cambia la configuración            ─┼─▶ nuevo env_hash / config.version
Rebuild manual del esquema         ─┘             │
                                                  ▼
                                          schema_version cambia
                                                  │
                    ┌─────────────────────────────┼─────────────────────────────┐
                    ▼                             ▼                             ▼
          codeia_schema:{v}:...         codeia_response:{v}:...      codeia_openapi:{v}:...
                    └──────────── todas quedan huérfanas y expiran por TTL ─────┘
```

La invalidación es **implícita por versión en la clave**, no un borrado explícito. Mismo mecanismo que el resto de cachés ([arquitectura.md §8](arquitectura.md)): evita depender de `wp_cache_flush_group()`, que no todos los backends de object cache implementan.

Además de los disparadores de esquema, invalidan el documento: activar o desactivar un proveedor de autenticación, cambiar el modo de exposición de `/docs`, modificar el alias de rutas y cambiar el `namespace`.

| Grupo | TTL | Regenera |
| ----- | --- | -------- |
| `codeia_openapi` | 12 h | Bajo demanda, con bloqueo para evitar estampida |

El **bloqueo contra estampida** importa: sin él, la expiración simultánea con varias peticiones concurrentes lanza varias generaciones completas a la vez. Se usa un cerrojo corto en caché; quien no lo obtiene sirve la versión anterior o espera.

---

## 10. Validación

El documento generado debe validar contra la especificación. Comprobaciones automáticas en el panel de estado:

- [ ] Todo `$ref` resuelve a un componente existente
- [ ] Ningún `operationId` duplicado
- [ ] Todo parámetro de ruta declarado en `parameters` y presente en la plantilla
- [ ] Todo esquema referenciado desde `paths` existe en `components/schemas`
- [ ] `securitySchemes` referenciados están definidos
- [ ] Sin campos restringidos filtrados en el documento del ámbito público

Un botón «Validar documento» ejecuta las comprobaciones y muestra el resultado. Para validación estricta contra la especificación completa, el dashboard ofrece la descarga del JSON para pasarlo por un validador externo — embarcar un validador de OpenAPI completo en el plugin no compensa.

---

## Documentos relacionados

- [03-deteccion-cpt-campos.md](03-deteccion-cpt-campos.md) — modelo de campo, tipos y confianza que aquí se traducen
- [02-endpoints-dinamicos.md](02-endpoints-dinamicos.md) — rutas y parámetros documentados
- [01-autenticacion.md](01-autenticacion.md) — proveedores activos → `securitySchemes`
- [04-permisos.md](04-permisos.md) — por qué el documento varía según el ámbito
- [10-rendimiento.md](10-rendimiento.md) — caché, TTL y protección contra estampida
