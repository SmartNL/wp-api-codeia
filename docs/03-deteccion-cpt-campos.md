# 03 — Detección automática de CPT y campos

Define cómo el plugin descubre qué tipos de contenido y qué campos existen en una instalación, sin que nadie se los declare. Es el módulo que sostiene a todos los demás: endpoints, permisos por campo y OpenAPI son proyecciones del esquema que aquí se construye.

---

## 1. El problema

WordPress registra los post types y las taxonomías en una API central e introspeccionable. **Los campos personalizados, no.**

`update_post_meta()` acepta cualquier clave sin registro previo. `register_meta()` existe y permite declarar tipo y esquema, pero es **opcional** y buena parte del ecosistema no lo usa. Un plugin puede guardar cuarenta campos sin que ninguna API de WordPress sepa que existen.

### 1.1 Caso real de esta instalación

El plugin `flavor-real-estate` (activo en este sitio) registra `property` y `flavor_agent` correctamente, con `show_in_rest => true`. Sus campos, en cambio, se guardan así (`includes/MetaBoxes/PropertyMetaBox.php`):

```php
update_post_meta( $post_id, $key, $sanitized );   // sin register_meta() previo
```

Consecuencia medible:

| Consulta | Resultado |
| -------- | --------- |
| `get_post_types(['_builtin'=>false])` | `property`, `flavor_agent` ✅ |
| `get_object_taxonomies('property')` | 6 taxonomías ✅ |
| `get_registered_meta_keys('post','property')` | **vacío** ❌ |
| Campos que existen realmente | **~28** (`_property_price`, `_property_rooms`, `_property_agent_id`…) |

Un plugin que solo consultara la API de registro concluiría que `property` no tiene campos personalizados. Estaría equivocado en 28 de 28.

**De ahí el diseño en niveles:** ninguna fuente aislada es suficiente, y la que más cubre es también la menos fiable.

---

## 2. Estrategia en cuatro niveles

```
                    ┌──────────────────────────────┐
     prioridad ▲    │ 4. DECLARACIÓN MANUAL        │  confianza: total
     (gana el       │    dashboard                 │  cobertura: la que se teclee
      más alto)     ├──────────────────────────────┤
                    │ 3. MUESTREO DE BASE DE DATOS │  confianza: baja
                    │    SELECT DISTINCT meta_key  │  cobertura: total (verdad del dato)
                    ├──────────────────────────────┤
                    │ 2. ADAPTADORES DE PROVEEDOR  │  confianza: alta
                    │    ACF · JetEngine · MetaBox │  cobertura: la de cada plugin
                    ├──────────────────────────────┤
                    │ 1. REGISTRO NATIVO           │  confianza: total
                    │    register_meta, CPT, tax   │  cobertura: baja en la práctica
                    └──────────────────────────────┘
```

| Nivel | Qué detecta | Qué NO detecta | Coste |
| ----- | ----------- | -------------- | ----- |
| 1 Nativo | Post types, taxonomías, meta con `register_meta()` | Meta sin registrar (la mayoría) | Nulo — todo en memoria |
| 2 Adaptadores | Campos de ACF, JetEngine, Meta Box con tipo y etiqueta | Campos de plugins sin adaptador; los escritos a mano | Bajo — API en memoria |
| 3 Muestreo BD | **Todo lo que existe en `wp_postmeta`** | Campos definidos pero aún sin ningún valor guardado | Alto — consulta agregada |
| 4 Manual | Lo que el administrador declare | — | Nulo |

Los niveles 1 y 2 son **declarativos**: dicen qué campos *deberían* existir, con su tipo. El nivel 3 es **empírico**: dice qué hay realmente en la base de datos, sin tipo fiable. Se complementan, y por eso ninguno se ejecuta en exclusiva.

---

## 3. Nivel 1 — Registro nativo

### 3.1 Recursos

```php
get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' );
get_taxonomies( [ 'object_type' => [ $post_type ] ], 'objects' );
```

De cada `WP_Post_Type` se toma: `name`, `label`, `hierarchical`, `supports`, `capability_type`, `rest_base` y `show_in_rest`.

**`show_in_rest` no se usa como filtro.** Un CPT con `show_in_rest => false` no aparece en la REST API nativa, pero sí puede exponerse por este plugin: son namespaces distintos con configuración independiente. Sí se muestra como aviso en el dashboard — que el autor del CPT lo excluyera de REST puede ser una decisión deliberada que conviene no revertir a ciegas.

Los `supports` importan porque determinan qué campos nativos tienen sentido: sin `thumbnail`, `featured_media` no debe aparecer en el esquema.

### 3.2 Meta registrada

```php
get_registered_meta_keys( 'post', $post_type );
```

Devuelve `type`, `description`, `single`, `show_in_rest` y `sanitize_callback`. Cuando existe, es la fuente **autoritativa**: la declara quien escribió el código.

También se recogen los campos añadidos con `register_rest_field()`, leyendo `$wp_rest_additional_fields`. No son meta, pero forman parte de la representación REST del recurso y deben aparecer en el catálogo.

---

## 4. Nivel 2 — Adaptadores de proveedor

### 4.1 Interfaz

```php
interface FieldProvider {
    public function is_available(): bool;              // ¿está el plugin activo?
    public function id(): string;                      // 'acf', 'jetengine', 'metabox'
    public function confidence(): int;                 // 0-100
    /** @return FieldDefinition[] */
    public function fields_for( string $post_type ): array;
}
```

Cada adaptador se registra vía `codeia/schema/providers` y **solo se consulta si `is_available()` devuelve `true`**, comprobado con `class_exists()`/`function_exists()`, nunca con `is_plugin_active()` (que exige cargar `wp-admin/includes/plugin.php` y falla en contexto REST).

### 4.2 Adaptadores previstos

| Proveedor | Punto de entrada | Notas |
| --------- | ---------------- | ----- |
| **ACF** | `acf_get_field_groups(['post_type'=>$pt])` → `acf_get_fields($group)` | Da tipo, etiqueta, opciones de select y campos anidados |
| **Meta Box** | `rwmb_get_registry('field')` | Registro global por tipo de objeto |
| **JetEngine** | `jet_engine()->meta_boxes` | Definiciones en la propia opción del plugin |
| **Pods** **(futuro)** | `pods_api()->load_pod()` | No prioritario |

> Estas son APIs de terceros y **cambian entre versiones mayores**. Cada adaptador declara el rango de versiones probado y, ante una versión fuera de rango, se desactiva y lo notifica en el panel de estado en lugar de fallar. Un adaptador roto nunca debe impedir que el resto del esquema se construya.

### 4.3 La particularidad de ACF

ACF guarda cada valor en dos entradas de `wp_postmeta`:

```
precio        →  "495000"        (el valor)
_precio       →  "field_6a1f2c"  (puntero a la definición)
```

Esto tiene dos consecuencias:

1. El nivel 3 vería `_precio` como un campo más. Hay que reconocer el patrón —clave con `_` inicial cuyo valor casa con `field_[0-9a-f]+` y cuyo nombre sin guion bajo también existe— y **descartarla**, atribuyendo el campo real a ACF.
2. Los campos repetidores y flexibles generan claves dinámicas (`galeria_0_imagen`, `galeria_1_imagen`). El adaptador las expone como estructura, no como campos sueltos; el nivel 3 debe colapsar el patrón `nombre_<n>_sub` en lugar de listar una entrada por índice.

---

## 5. Nivel 3 — Muestreo de base de datos

Es el nivel que hace que el caso de `flavor-real-estate` funcione, y el más delicado.

### 5.1 Consulta

```sql
SELECT pm.meta_key, COUNT(*) AS usos
FROM {$wpdb->postmeta} pm
INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
WHERE p.post_type = %s
  AND p.post_status IN ('publish','draft','pending','private')
GROUP BY pm.meta_key
HAVING usos >= %d
ORDER BY usos DESC
LIMIT %d
```

`INNER JOIN` con `wp_posts` para acotar por post type — `wp_postmeta` no sabe de tipos. Es la parte cara: en instalaciones grandes esta consulta recorre muchas filas, así que **nunca se ejecuta en una petición de API**. Solo en:

- la activación del plugin,
- un *rebuild* manual desde el dashboard,
- una tarea programada de refresco (opcional, desactivada por defecto).

El resultado se cachea con el esquema. `HAVING usos >= 2` descarta claves de un solo uso, que suelen ser residuos.

### 5.2 Qué excluir — y el error que hay que evitar

La recomendación habitual es «ignorar las claves que empiezan por `_`, son privadas». **En esta instalación esa regla descartaría los 28 campos útiles de `property`**, porque `flavor-real-estate` los nombra todos con guion bajo inicial: `_property_price`, `_property_rooms`, `_property_agent_id`…

Es una convención extendida: el `_` marca la meta como protegida (`is_protected_meta()`), lo que la oculta de la caja «Campos personalizados» del editor. Los plugins serios lo usan precisamente para sus campos *reales*.

Por tanto se usa una **lista de exclusión explícita**, no un filtro por prefijo:

| Categoría | Patrón | Ejemplos |
| --------- | ------ | -------- |
| Internas del núcleo | Lista fija | `_edit_lock`, `_edit_last`, `_wp_page_template`, `_wp_old_slug`, `_wp_trash_meta_*`, `_thumbnail_id`, `_pingme`, `_encloseme` |
| Adjuntos | Prefijo `_wp_attach` | `_wp_attached_file`, `_wp_attachment_metadata` |
| oEmbed | Prefijo `_oembed_` | `_oembed_<hash>` |
| Punteros de ACF | `_<clave>` con valor `field_*` y `<clave>` existente | `_precio` |
| Ruido de terceros | Configurable | claves de caché de SEO, contadores |

`_thumbnail_id` se excluye del muestreo **pero se expone igualmente**, como campo nativo `featured_media`, cuando el post type declara `supports thumbnail`. Excluirlo aquí solo evita que aparezca duplicado.

La lista es editable desde el dashboard, y el panel de estado muestra qué claves se descartaron y por qué — para que una exclusión sorpresa sea diagnosticable en lugar de un campo que «no aparece».

### 5.3 Inferencia de tipo

El muestreo da nombres, no tipos. Se toman hasta **50 valores distintos** por clave y se analizan:

```
todos numéricos sin punto      → integer
todos numéricos con punto      → number
solo "0"/"1"/""                → boolean          (con aviso: ambiguo)
todos ISO-8601 o "Y-m-d H:i:s" → string/date-time
is_serialized()                → array u object   (según claves)
JSON válido                    → object
todos enteros que resuelven
   a un post existente         → integer + candidato a RELACIÓN
resto                          → string
```

La inferencia es **una propuesta con confianza baja**, siempre revisable en el dashboard. Dos trampas conocidas que se marcan explícitamente:

- **`0`/`1` no siempre es booleano.** `_property_floors` con valores `0` y `1` es un contador de plantas, no un sí/no. Cuando el rango de valores es exactamente `{0,1}` la propuesta es `boolean` pero se marca como **ambigua** y se pide confirmación.
- **Numérico no implica número.** Códigos postales (`_property_postal_code`) y referencias catastrales son cadenas: convertirlas a número pierde ceros a la izquierda. La heurística marca como `string` cualquier valor numérico con cero inicial y longitud fija.

### 5.4 Relaciones candidatas

Una clave se propone como relación si el nombre sugiere referencia (sufijo `_id`, `_ids`) **y** una muestra de sus valores resuelve a posts existentes de un mismo post type.

Con `_property_agent_id` se cumplen ambas: los valores resuelven a posts `flavor_agent`. Se propone `property → flavor_agent` y **queda pendiente de confirmación manual**. Una relación mal inferida genera expansiones y consultas erróneas, así que nunca se activa sola.

---

## 6. Nivel 4 — Declaración manual

El administrador puede declarar un campo que ningún nivel detectó (existe en código pero aún sin valores guardados), corregir un tipo mal inferido, renombrar el campo expuesto o forzar una relación.

Prioridad máxima: **lo manual gana siempre**, y se conserva aunque un rebuild deje de detectar el campo — con un aviso de «declarado manualmente, no detectado en la instalación», que es justo la señal que interesa cuando alguien renombra una clave.

---

## 7. Modelo de campo normalizado

Todos los niveles producen la misma estructura:

```php
final class FieldDefinition {
    public string  $storage_key;   // '_property_price'
    public string  $exposed_name;  // 'price'
    public string  $type;          // integer|number|string|boolean|array|object
    public ?string $format;        // date-time|uri|email|null
    public bool    $single;        // ¿un valor o varios?
    public string  $origin;        // native|acf|jetengine|metabox|db_sample|manual
    public int     $confidence;    // 0-100
    public ?string $label;
    public array   $enum;          // valores admitidos, si los hay
    public ?array  $relation;      // ['post_type'=>'flavor_agent','confirmed'=>bool]
    public bool    $protected;     // ¿is_protected_meta()?
}
```

`origin` y `confidence` son lo que permite resolver conflictos y explicar decisiones en la interfaz. Un campo sin trazabilidad de origen es imposible de depurar cuando el esquema se equivoca.

### 7.1 Confianza por origen

| Origen | Confianza | Razón |
| ------ | --------- | ----- |
| `manual` | 100 | Lo dijo una persona |
| `native` | 95 | Declarado en código con `register_meta()` |
| `acf` / `metabox` / `jetengine` | 85 | Declarado, pero traducido por un adaptador |
| `db_sample` | 40 | Existe seguro; el tipo es una conjetura |

### 7.2 Meta protegida

`protected` se calcula con `is_protected_meta( $key, 'post' )`. Su efecto: **exponer un campo protegido exige confirmación explícita** en el dashboard, con el aviso de que el autor del plugin lo marcó como interno.

No se bloquea —sería inservible con `flavor-real-estate`, donde todo lo útil es protegido— pero tampoco se expone en silencio. Es una decisión del administrador, tomada a la vista de lo que implica.

---

## 8. Resolución de conflictos

Cuando varios niveles describen la misma `storage_key`:

```
1. Ordenar candidatos por confianza (desc)
2. Tomar el de mayor confianza como base
3. Rellenar SOLO los huecos con los de menor confianza
   (db_sample aporta 'usos'; acf aporta 'label' si el manual no lo fijó)
4. Si dos con la MISMA confianza discrepan en 'type':
      → conservar ambos en el registro de conflictos
      → aplicar 'string' (el tipo más permisivo)
      → marcar el campo como NO EXPUESTO hasta que alguien decida
```

El paso 4 es deliberadamente conservador. Un campo con tipo en disputa que se expone como `string` funciona; expuesto como `integer` cuando en realidad guarda texto produce respuestas corruptas o errores de validación intermitentes. Ante la duda, **no exponer** y pedir una decisión.

---

## 9. Caché e invalidación

### 9.1 Versión de entorno

La clave de caché incorpora un hash corto del estado de la instalación:

```php
$env_hash = substr( md5( serialize( [
    array_keys( get_post_types( [], 'names' ) ),
    array_keys( get_taxonomies( [], 'names' ) ),
    get_option( 'active_plugins' ),
    CODEIA_VERSION,
    $config['version'],
] ) ), 0, 8 );
```

Cambiar cualquiera de esos elementos produce una clave distinta, de modo que el esquema viejo queda huérfano y expira solo. **No hace falta borrado explícito**, que es exactamente lo que el object cache de WordPress no garantiza: `wp_cache_flush_group()` no está soportado por todos los backends.

### 9.2 Disparadores

| Evento | Acción |
| ------ | ------ |
| `activated_plugin` / `deactivated_plugin` | Cambia `env_hash` → invalidación implícita |
| `registered_post_type` / `registered_taxonomy` | Ídem |
| Guardar configuración | Sube `config.version` → ídem |
| Rebuild manual | Borrado explícito + reconstrucción con muestreo |
| Cron de refresco (opcional) | Rebuild completo fuera de hora punta |

La invalidación en cascada hacia respuestas y OpenAPI está descrita en [arquitectura.md §8](arquitectura.md) — todas las claves derivadas incluyen la versión de esquema.

### 9.3 Coste de reconstrucción

| Fase | Coste |
| ---- | ----- |
| Niveles 1 y 2 | Milisegundos, todo en memoria |
| Nivel 3 (muestreo) | Cientos de ms a segundos, según volumen |
| Normalización y conflictos | Milisegundos |

Por eso el rebuild **completo** es una operación explícita y no automática. El rebuild **parcial** (niveles 1, 2 y 4, reutilizando el muestreo cacheado) es barato y sí se ejecuta al vuelo cuando cambia `env_hash`, con un aviso en el dashboard de que los datos empíricos pueden estar desfasados.

---

## 10. Flujo completo

```
             ┌─────────────────────────┐
             │ ¿esquema en caché       │── sí ──▶ devolver
             │   con env_hash actual?  │
             └───────────┬─────────────┘
                        no
                         ▼
   ┌─────────────────────────────────────────────┐
   │ N1  get_post_types / get_taxonomies         │
   │     get_registered_meta_keys                │
   └───────────────────┬─────────────────────────┘
                       ▼
   ┌─────────────────────────────────────────────┐
   │ N2  para cada provider disponible:          │
   │     ACF · Meta Box · JetEngine              │
   └───────────────────┬─────────────────────────┘
                       ▼
   ┌─────────────────────────────────────────────┐
   │ N3  ¿muestreo cacheado y vigente?           │
   │     sí → reutilizar   no → SQL + inferencia │
   └───────────────────┬─────────────────────────┘
                       ▼
   ┌─────────────────────────────────────────────┐
   │ N4  superponer declaraciones manuales       │
   └───────────────────┬─────────────────────────┘
                       ▼
   ┌─────────────────────────────────────────────┐
   │ Normalizar → resolver conflictos →          │
   │ filtro codeia/schema/resource_fields →      │
   │ guardar en caché                            │
   └───────────────────┬─────────────────────────┘
                       ▼
                 ResourceDefinition
```

---

## 11. Caso de validación: `flavor-real-estate`

Este plugin es la prueba de aceptación del módulo, porque combina lo bien hecho con lo que rompe la introspección ingenua.

| Aspecto | Detectado por | Resultado esperado |
| ------- | ------------- | ------------------ |
| CPT `property`, `flavor_agent` | N1 | ✅ con etiquetas y `supports` |
| 6 taxonomías de `property` | N1 | ✅ incluida la jerárquica `property_location` |
| `register_meta` | — | Ninguno: el plugin no lo usa |
| 28 campos `_property_*` | **N3** | ✅ solo gracias al muestreo |
| `_property_price` | N3 | `number` — todos los valores decimales |
| `_property_postal_code` | N3 | `string` — numérico pero con ceros iniciales |
| `_property_rooms` | N3 | `integer` |
| `_property_gallery` | N3 | `array<integer>` — valor serializado |
| `_property_agent_id` | N3 | `integer` + relación propuesta a `flavor_agent` |
| `_property_floors` | N3 | ⚠️ `boolean` ambiguo si solo hay valores 0/1 → pedir confirmación |
| `_flavor_re_property_nonce` | — | No aparece: es un campo de formulario, nunca se guarda como meta |

La última fila ilustra por qué el nivel 3 consulta la base de datos y no analiza el código: **la base de datos dice lo que hay, no lo que parece haber.** Un análisis estático del plugin encontraría esa constante de nonce y la propondría como campo inexistente.

---

## Documentos relacionados

- [arquitectura.md](arquitectura.md) — el registro de esquema dentro de la capa de dominio
- [02-endpoints-dinamicos.md](02-endpoints-dinamicos.md) — cómo el esquema se convierte en rutas y filtros
- [04-permisos.md](04-permisos.md) — permisos por campo sobre el esquema detectado
- [06-swagger-openapi.md](06-swagger-openapi.md) — traducción del esquema a JSON Schema
- [10-rendimiento.md](10-rendimiento.md) — coste del muestreo y presupuesto de caché
