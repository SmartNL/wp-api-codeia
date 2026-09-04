# 02 — Sistema de endpoints dinámicos

Define cómo la configuración de un recurso se convierte en endpoints REST funcionales, sin escribir un controlador por tipo de contenido.

> Depende de [03-deteccion-cpt-campos.md](03-deteccion-cpt-campos.md): todo lo que aquí se registra deriva del esquema normalizado que produce el registro de esquema.

---

## 1. De la configuración a la ruta

```
Configuración persistida        Registro de esquema
(qué recursos, qué campos)  +   (qué campos existen y de qué tipo)
              │                           │
              └─────────────┬─────────────┘
                            ▼
                    ResourceDefinition
         (recurso, operaciones, campos expuestos,
          filtrables, ordenables, relaciones)
                            │
                            ▼
                    ControllerFactory
                            │
          ┌─────────────────┼─────────────────┐
          ▼                 ▼                 ▼
   register_rest_route  get_item_schema   args (validate
   (rutas + verbos)     (para OpenAPI)     + sanitize)
```

La `ResourceDefinition` es el objeto pivote: **una sola fuente** de la que salen las rutas, el esquema JSON para OpenAPI y las reglas de validación de argumentos. Que las tres cosas se deriven del mismo sitio es lo que garantiza que la documentación no se desincronice de la implementación.

---

## 2. Registro de rutas

### 2.1 Momento

`register_rest_route()` solo es válido dentro de `rest_api_init`. El registro es **diferido**: nada se registra hasta que llega una petición REST.

```php
add_action( 'rest_api_init', function () {
    foreach ( $this->config->enabled_resources() as $slug ) {
        $definition = $this->schema->definition_for( $slug );   // desde caché
        $this->factory->make( $definition )->register_routes();
    }
} );
```

Esto importa por rendimiento: una carga de página normal del front-end **no** paga el coste de construir definiciones ni registrar rutas. `rest_api_init` solo se dispara en peticiones REST.

### 2.2 Rutas generadas por recurso

Para `property`, con todas las operaciones habilitadas:

| Método | Ruta | Operación | Permiso |
| ------ | ---- | --------- | ------- |
| `GET` | `/codeia/v1/property` | Listar | `read` sobre el recurso |
| `POST` | `/codeia/v1/property` | Crear | `create` |
| `GET` | `/codeia/v1/property/{id}` | Obtener | `read` + `read_post` sobre el elemento |
| `PUT`/`PATCH` | `/codeia/v1/property/{id}` | Actualizar | `update` + `edit_post` |
| `DELETE` | `/codeia/v1/property/{id}` | Eliminar | `delete` + `delete_post` |
| `GET` | `/codeia/v1/property/schema` | Esquema del recurso | `read` |

Las operaciones no habilitadas **no se registran**. Un recurso de solo lectura no expone `POST` que devuelva 403: la ruta simplemente no existe y responde 404. Es preferible — no revela qué operaciones existen pero están vetadas.

`{id}` se registra como `(?P<id>[\d]+)`. Nunca `[\w-]+`: aceptar slugs abriría una segunda vía de resolución con reglas de permiso distintas.

### 2.3 Controlador

`ResourceController` extiende `WP_REST_Controller`, la clase base del núcleo. Se reutiliza en lugar de implementar rutas a mano porque ya resuelve el contrato de métodos (`get_items`, `create_item`…), la forma de `get_collection_params()` y la integración con `_fields` y `_embed`.

Un mismo controlador sirve a **todos** los recursos; se parametriza con su `ResourceDefinition` en el constructor. No se genera una clase por post type.

---

## 3. Proyección de campos

### 3.1 Tres niveles de filtrado

Un campo llega a la respuesta solo si supera los tres:

```
Campos detectados en el esquema
        │
        ├─▶ [1] ¿expuesto en la configuración?      ── no ──▶ descartado
        │       (decisión del administrador)
        ▼
        ├─▶ [2] ¿visible para el rol?               ── no ──▶ descartado
        │       (04-permisos.md)
        ▼
        └─▶ [3] ¿pedido por el cliente (_fields)?   ── no ──▶ descartado
                (optimización, no seguridad)
```

El orden es obligatorio y no es intercambiable: **`_fields` nunca puede ampliar** lo que los niveles 1 y 2 ya decidieron. Es una herramienta para pedir menos, jamás para pedir más. Implementarlo al revés —proyectar por `_fields` y luego comprobar permisos— es un patrón que en la práctica acaba filtrando campos vetados.

### 3.2 Nombres expuestos frente a claves de almacenamiento

Las claves meta reales rara vez sirven como nombres públicos. En `property` (plugin flavor-real-estate) son `_property_price`, `_property_built_area`, `_property_agent_id`. Exponerlas tal cual filtra detalles de implementación y produce una API fea.

El esquema mantiene un **mapa bidireccional**:

| Clave de almacenamiento | Nombre expuesto | Tipo |
| ----------------------- | --------------- | ---- |
| `_property_price` | `price` | `number` |
| `_property_price_before` | `price_before` | `number` |
| `_property_currency` | `currency` | `string` (enum) |
| `_property_built_area` | `built_area` | `number` |
| `_property_rooms` | `rooms` | `integer` |
| `_property_agent_id` | `agent_id` | `integer` (relación) |
| `_property_gallery` | `gallery` | `array<integer>` |
| `_property_latitude` | `latitude` | `number` |

Normalización por defecto: quitar el `_` inicial y el prefijo del post type, en `snake_case`. El administrador puede sobrescribir cualquier nombre desde el dashboard.

**Colisiones.** Dos claves distintas pueden normalizar al mismo nombre, y un nombre normalizado puede chocar con un campo nativo (`id`, `title`, `status`, `date`, `link`). Cuando ocurre, el esquema **conserva la clave completa** como nombre expuesto y marca el conflicto en el panel de estado, en lugar de resolverlo en silencio. Los campos nativos siempre ganan.

### 3.3 Campos calculados

Campos que no existen en almacenamiento y se derivan en la respuesta. Se declaran en el esquema con un *callback* y son de **solo lectura**.

```php
'price_formatted' => [
    'type'     => 'string',
    'computed' => true,
    'callback' => fn( array $item ) => Helpers::format_price( $item['price'], $item['currency'] ),
    'depends'  => [ 'price', 'currency' ],
],
```

`depends` es necesario: si el cliente pide `_fields=price_formatted`, el motor debe cargar `price` y `currency` aunque no vayan en la respuesta. Sin esa declaración, los campos calculados fallan de forma intermitente en cuanto se usa `_fields`.

---

## 4. Filtrado

### 4.1 Solo campos declarados filtrables

Filtrar por un campo requiere marcarlo como tal en el dashboard. **La lista blanca es de seguridad y de rendimiento a la vez**: impide construir `meta_query` arbitrarias (ver [09-seguridad.md](09-seguridad.md)) y evita que un cliente lance consultas no indexadas contra `wp_postmeta`.

### 4.2 Sintaxis

```
GET /codeia/v1/property?filter[price][gte]=200000
                       &filter[rooms]=3
                       &filter[property_type]=piso,atico
                       &search=centro
```

| Operador | Aplica a | Traducción |
| -------- | -------- | ---------- |
| *(ninguno)* | Todos | `=` (o `IN` si el valor lleva comas) |
| `gt` `gte` `lt` `lte` | Numéricos, fechas | Comparación con `type` correcto en `meta_query` |
| `between` | Numéricos, fechas | `BETWEEN` con dos valores |
| `like` | Texto | `LIKE %valor%` |
| `in` `not_in` | Todos | `IN` / `NOT IN` |
| `exists` `not_exists` | Todos | `EXISTS` / `NOT EXISTS` |

### 4.3 Traducción a consulta

Tres destinos según el origen del campo:

| Origen | Destino en `WP_Query` |
| ------ | --------------------- |
| Campo nativo (`status`, `author`, `date`) | Argumento directo (`post_status`, `author`, `date_query`) |
| Taxonomía | `tax_query` |
| Meta | `meta_query` |

**El `type` de la `meta_query` no es opcional.** Los valores de `wp_postmeta.meta_value` son `LONGTEXT`; sin `'type' => 'NUMERIC'`, MySQL compara como cadena y `"300000" < "89000"` resulta verdadero. El tipo se toma del esquema, que es precisamente por qué la detección de tipos ([03](03-deteccion-cpt-campos.md)) es un requisito y no un adorno.

```php
'meta_query' => [
    [ 'key' => '_property_price', 'value' => 200000,
      'compare' => '>=', 'type' => 'NUMERIC' ],
],
```

**Límite de complejidad.** Cada cláusula de `meta_query` es un `JOIN` adicional sobre `wp_postmeta`. Se impone un máximo configurable (**4 por defecto**) y se rechaza con `400 codeia_query_too_complex` al superarlo. Sin ese tope, un solo cliente puede tumbar la base de datos con una URL.

---

## 5. Ordenación

```
GET /codeia/v1/property?orderby=price&order=desc
```

Solo campos marcados como ordenables. El valor **nunca** se pasa a `WP_Query` sin validar contra la lista blanca: `orderby` acaba en la cláusula `ORDER BY` y es un vector clásico de inyección.

| `orderby` | Traducción |
| --------- | ---------- |
| `date`, `title`, `id`, `modified`, `menu_order` | Nativo |
| Campo meta numérico | `meta_value_num` + `meta_key` |
| Campo meta de texto | `meta_value` + `meta_key` |
| `relevance` | Solo junto a `search` |

**Ordenar por meta filtra implícitamente.** `WP_Query` con `meta_key` hace `INNER JOIN`: las entradas **sin** esa clave desaparecen del listado. Para `_property_price` no se nota (todas la tienen), pero para un campo opcional el listado se acorta sin explicación aparente. El motor lo evita usando la forma con `meta_query` nombrada y `'compare' => 'EXISTS'` en `LEFT JOIN` cuando el campo está marcado como opcional en el esquema, y lo documenta en la respuesta de `/schema`.

Ordenación secundaria por `ID` **siempre**, de forma implícita. Sin desempate estable, dos elementos con el mismo precio pueden alternar de posición entre peticiones y provocar duplicados o saltos al paginar.

---

## 6. Paginación

Dos estrategias con propósitos distintos.

### 6.1 Por offset (por defecto)

```
GET /codeia/v1/property?page=2&per_page=20
```

Respuesta con `X-WP-Total` y `X-WP-TotalPages`, igual que la REST API nativa.

Coste: obtener el total exige `SQL_CALC_FOUND_ROWS`, que en tablas grandes recorre el conjunto completo. Además, `OFFSET 10000` obliga a MySQL a descartar 10 000 filas antes de devolver nada.

Límite: `per_page` máximo **100**, configurable pero con tope duro. Sin él, `per_page=100000` es una denegación de servicio de una línea.

### 6.2 Por cursor

```
GET /codeia/v1/property?per_page=20&after=eyJkIjoiMjAyNi0wMy0xMi...
```

Cursor opaco (base64 de `{fecha, id}`) que traduce a una condición *keyset*:

```sql
WHERE (post_date, ID) < (:fecha, :id)
ORDER BY post_date DESC, ID DESC
LIMIT 20
```

Con `'no_found_rows' => true` se omite el conteo total. Coste constante independientemente de la profundidad, y **estable ante inserciones**: con offset, publicar un elemento nuevo mientras se pagina desplaza todo y provoca repeticiones.

| | Offset | Cursor |
| --- | --- | --- |
| Total conocido | Sí | No |
| Salto a página arbitraria | Sí | No |
| Coste a gran profundidad | Degrada | Constante |
| Estable ante inserciones | No | Sí |
| Uso recomendado | UI con paginador | Scroll infinito, sincronización, exportación |

El cursor se firma con HMAC para detectar manipulación. No es secreto —solo contiene fecha e ID— pero un cursor alterado produciría consultas con valores no validados.

---

## 7. Relaciones

### 7.1 Tipos soportados

| Tipo | Ejemplo real | Detección |
| ---- | ------------ | --------- |
| Taxonomía | `property` → `property_type`, `property_location` | `get_object_taxonomies()` |
| Post padre | Jerarquía nativa | `hierarchical` en el post type |
| Referencia por meta | `property._property_agent_id` → `flavor_agent` | Heurística + confirmación manual |
| Adjuntos | `property._property_gallery` → attachments | Campo de tipo galería |

La **referencia por meta** no es declarativa en WordPress: `_property_agent_id` es un entero indistinguible de cualquier otro. La heurística (sufijo `_id`/`_ids`, valores que resuelven a posts existentes de un mismo tipo) **propone** la relación, y el administrador la confirma en el dashboard. Nunca se activa automáticamente: una relación inferida mal produce consultas y expansiones erróneas.

### 7.2 Expansión

```
GET /codeia/v1/property?expand=agent,property_type
```

```json
{
  "id": 42,
  "title": "Ático en Malasaña",
  "price": 495000,
  "agent_id": 17,
  "_expanded": {
    "agent": { "id": 17, "title": "Laura Giménez", "phone": "+34 ..." },
    "property_type": [ { "id": 3, "slug": "atico", "name": "Ático" } ]
  }
}
```

Lo expandido va bajo `_expanded` y **respeta los permisos del recurso destino**: expandir `agent` no puede revelar campos de `flavor_agent` que el rol no podría leer pidiendo `/flavor_agent/17` directamente. Se resuelve delegando en el mismo `PermissionResolver`, no reimplementando la comprobación.

Profundidad máxima **1**. Expansión recursiva sin tope es una vía directa a agotar memoria con un grafo cíclico (`property → agent → property → …`).

### 7.3 Evitar el N+1

Sin precarga, listar 20 propiedades con su agente son 1 + 20 + 20 consultas. La solución es cargar **en lote**, no por elemento:

```
1. WP_Query devuelve los 20 posts
2. update_post_meta_cache( $ids )        → 1 consulta: toda la meta
3. update_object_term_cache( $ids, $pt ) → 1 consulta: todos los términos
4. recolectar los agent_id de los 20
5. _prime_post_caches( $agent_ids )      → 1 consulta: todos los agentes
6. componer la respuesta desde caché     → 0 consultas
```

De 41 consultas a 5, constante respecto a `per_page`. `WP_Query` hace los pasos 2 y 3 por su cuenta si no se le pide lo contrario; el motor **no debe** desactivarlos con `update_post_meta_cache => false` como «optimización», porque el efecto es exactamente el opuesto.

El paso 5 es la parte propia: recolectar todos los IDs relacionados de la página antes de resolver ninguno.

---

## 8. Caché de respuesta

### 8.1 Qué se cachea

Solo `GET`, y solo cuando la petición **no depende de la identidad**: sin campos restringidos por rol en juego y sin parámetros que varíen por usuario. Una respuesta que ya ha pasado por el filtrado por campo del rol no puede compartirse entre roles distintos.

Cuando el recurso tiene campos con visibilidad por rol, la clave incorpora un hash del **conjunto de campos visibles** para ese rol —no el ID de usuario—, de modo que todos los usuarios con la misma visibilidad comparten entrada.

```
codeia_response:{schema_version}:{resource}:{fields_hash}:{args_hash}
```

### 8.2 Invalidación

| Evento | Alcance |
| ------ | ------- |
| `save_post_{$post_type}` | Todas las respuestas del recurso |
| `deleted_post`, `trashed_post` | Todas las respuestas del recurso |
| Cambio de términos (`set_object_terms`) | Todas las respuestas del recurso |
| Cambio de configuración o de esquema | Todo, vía `schema_version` en la clave |

La invalidación es **por recurso, no por elemento**: saber qué respuestas de listado contienen un post concreto exigiría un índice inverso más caro de mantener que de reconstruir. Con TTL corto (5 min) el desperdicio es aceptable.

Detalle de drivers y presupuestos en [10-rendimiento.md](10-rendimiento.md).

---

## 9. Errores

| HTTP | `code` | Cuándo |
| ---- | ------ | ------ |
| 400 | `rest_invalid_param` | Argumento que no pasa `validate_callback` |
| 400 | `codeia_unknown_field` | `_fields` o `filter` nombra un campo inexistente |
| 400 | `codeia_field_not_filterable` | Campo real, no habilitado para filtrar |
| 400 | `codeia_query_too_complex` | Se supera el máximo de cláusulas meta |
| 400 | `codeia_invalid_cursor` | Cursor mal formado o con HMAC inválido |
| 403 | `codeia_forbidden` | Sin permiso para la operación |
| 403 | `codeia_field_forbidden` | Escritura sobre un campo vetado (ver [04](04-permisos.md)) |
| 404 | `rest_no_route` | Recurso u operación no habilitados |
| 404 | `codeia_not_found` | El elemento no existe o no es visible |
| 409 | `codeia_conflict` | Escritura concurrente detectada por `modified` |

`404` y no `403` cuando el elemento existe pero el rol no puede verlo: responder 403 confirmaría su existencia.

---

## Documentos relacionados

- [03-deteccion-cpt-campos.md](03-deteccion-cpt-campos.md) — de dónde sale el esquema que alimenta todo esto
- [04-permisos.md](04-permisos.md) — niveles 2 de la proyección y permisos de expansión
- [06-swagger-openapi.md](06-swagger-openapi.md) — cómo estas rutas se documentan solas
- [09-seguridad.md](09-seguridad.md) — listas blancas de `orderby` y `meta_key`
- [10-rendimiento.md](10-rendimiento.md) — precarga en lote y presupuesto de consultas
