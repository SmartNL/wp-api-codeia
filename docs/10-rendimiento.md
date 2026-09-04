# 10 — Rendimiento y caché

Define la estrategia de caché, el coste de las consultas que genera el plugin y el presupuesto que cada petición no debe superar.

> La API expone datos que WordPress guarda en `wp_postmeta`, una tabla diseñada para flexibilidad, no para consulta. Casi todo lo de este documento gira alrededor de esa tensión.

---

## 1. El cuello de botella: `wp_postmeta`

### 1.1 Índices reales

```sql
CREATE TABLE wp_postmeta (
  meta_id    bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id    bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key   varchar(255)        DEFAULT NULL,
  meta_value longtext            DEFAULT NULL,
  PRIMARY KEY (meta_id),
  KEY post_id  (post_id),
  KEY meta_key (meta_key(191))
);
```

Tres hechos que condicionan todo el diseño:

1. **`meta_value` no tiene índice.** Ni lo puede tener de forma completa: es `LONGTEXT`. Filtrar por valor recorre todas las filas que compartan `meta_key`.
2. **`meta_key` está indexado a 191 caracteres.** Suficiente en la práctica, pero es un índice de prefijo.
3. **Cada campo es una fila.** Una propiedad con 28 campos son 28 filas. Cien mil propiedades, casi tres millones de filas.

Consecuencia directa: `filter[price][gte]=200000` sobre cien mil propiedades recorre cien mil filas para comparar valores. Y como `meta_value` es texto, la comparación numérica exige `CAST`, que impide cualquier uso de índice aunque lo hubiera.

### 1.2 Qué se hace al respecto

| Estrategia | Efecto | Coste |
| ---------- | ------ | ----- |
| Filtrado **opt-in** por campo | Solo se puede filtrar por lo habilitado | Menos flexibilidad |
| Tope de cláusulas `meta_query` | Acota el peor caso | — |
| Caché de respuesta agresiva | Repeticiones no llegan a la BD | Datos hasta 5 min viejos |
| Aviso de coste en el dashboard | El administrador decide con información | — |
| Índice adicional **(propuesto)** | `KEY (meta_key(32), meta_value(64))` | Escrituras más lentas, espacio |

El índice adicional **no se crea automáticamente**. Alterar una tabla del núcleo en la activación de un plugin es intrusivo, puede tardar minutos en tablas grandes y afecta a todo lo demás que escriba meta. Se ofrece como acción explícita en Herramientas, con su coste estimado y advertencia.

---

## 2. Jerarquía de caché

```
Petición
   │
   ├─▶ [L1] MemoryDriver          ── acierto ──▶ devolver  (~0 ms)
   │        array en memoria, vive lo que la petición
   │
   ├─▶ [L2] ObjectCache/Transient ── acierto ──▶ promover a L1 y devolver (~1 ms)
   │
   └─▶ [L3] Origen (BD + cómputo) ──▶ guardar en L2 y L1 ──▶ devolver (10-500 ms)
```

**L1 no es opcional.** Componer una respuesta de 20 propiedades pide el esquema del recurso una vez por elemento como mínimo. Sin caché en memoria son 20 lecturas de object cache que L1 reduce a una.

### 2.1 Elección de L2

| | Object cache persistente | Transients |
| --- | --- | --- |
| Almacén | Redis / Memcached | `wp_options` |
| Lectura | Red local, µs | Consulta SQL |
| Escritura | µs | `INSERT`/`UPDATE` + posible `autoload` |
| Compartida entre nodos | Sí | Sí (misma BD) |
| Expiración | Nativa | Perezosa, al leer |
| Riesgo | — | Hinchar `wp_options` |

Se detecta con `wp_using_ext_object_cache()`. Sin object cache, los transients funcionan pero cada escritura es una consulta y las entradas caducadas **no se borran solas**: se limpian al leerlas o en un evento de cron del núcleo. Un sitio con mucha rotación de claves acumula miles de filas huérfanas.

Por eso, sin object cache persistente, el TTL de las respuestas baja a **60 segundos** y el rate limiting reduce su granularidad: menos escrituras, menos residuo.

---

## 3. Grupos de caché

| Grupo | Contenido | TTL (con OC) | TTL (transients) | Coste de reconstruir |
| ----- | --------- | ------------ | ---------------- | -------------------- |
| `codeia_schema` | Esquemas normalizados | 12 h | 1 h | 50–5000 ms |
| `codeia_response` | Respuestas de lectura | 5 min | 60 s | 10–300 ms |
| `codeia_openapi` | Documento OpenAPI | 12 h | 1 h | 100–800 ms |
| `codeia_rl` | Contadores de límite | ventana | ventana | — |
| `codeia_status` | Panel de estado | 5 min | 5 min | 200–2000 ms |

### 3.1 Claves versionadas

```
codeia_schema:{env_hash}:{resource}
codeia_response:{schema_version}:{resource}:{fields_hash}:{args_hash}
codeia_openapi:{schema_version}:{fields_hash}
```

La invalidación se produce **por cambio de clave**, no por borrado. Cambiar `env_hash` deja las entradas anteriores huérfanas y expiran solas.

Se hace así porque `wp_cache_flush_group()` **no está soportado por todos los backends** de object cache. Depender de él produciría invalidaciones que funcionan en desarrollo con un backend y fallan en producción con otro — sirviendo datos obsoletos sin síntoma visible. El coste es memoria desperdiciada durante un TTL, aceptable a cambio de corrección garantizada.

### 3.2 Estampida

Cuando una clave cara expira con varias peticiones concurrentes, todas reconstruyen a la vez. Con el esquema (hasta 5 s) eso puede tumbar el sitio.

```php
if ( ! $this->cache->add( $lock_key, 1, 30 ) ) {   // add() es atómico
    return $stale ?? $this->wait_briefly_then_read( $key );
}
$value = $this->rebuild();                          // solo un proceso llega aquí
$this->cache->set( $key, $value, $ttl );
$this->cache->delete( $lock_key );
```

`add()` y no `set()`: solo tiene éxito si la clave no existe, que es lo que lo hace atómico. Quien no obtiene el cerrojo sirve la versión anterior si la hay (`stale-while-revalidate`) o espera brevemente.

Las respuestas se guardan con un TTL nominal (5 min) y uno duro mayor (15 min): pasado el nominal se considera obsoleta y se reconstruye, pero sigue disponible para servir mientras otro proceso la regenera.

---

## 4. Consultas

### 4.1 Precarga en lote

El patrón central, ya descrito en [02 §7.3](02-endpoints-dinamicos.md):

| Enfoque | Consultas (20 elementos con relación) |
| ------- | ------------------------------------- |
| Ingenuo | 41 |
| Con precarga en lote | 5 |

`WP_Query` ya precarga meta y términos por defecto. **No se deben desactivar:**

```php
// ❌ parece una optimización; multiplica las consultas por 20
'update_post_meta_cache' => false,
'update_post_term_cache' => false,
```

Solo tienen sentido cuando la respuesta no toca meta ni términos, que en esta API no ocurre nunca.

### 4.2 Argumentos que sí importan

| Argumento | Cuándo | Efecto |
| --------- | ------ | ------ |
| `'no_found_rows' => true` | Paginación por cursor | Elimina `SQL_CALC_FOUND_ROWS` |
| `'fields' => 'ids'` | Solo se necesitan IDs | Evita hidratar objetos |
| `'ignore_sticky_posts' => true` | Siempre en API | Evita una consulta extra |
| `'posts_per_page'` acotado | Siempre | Tope duro de 100 |
| `'suppress_filters' => false` | Siempre | Necesario para que los filtros de permisos apliquen |

`SQL_CALC_FOUND_ROWS` es la diferencia grande: obliga a MySQL a contar el conjunto completo ignorando el `LIMIT`. En tablas grandes puede costar más que la consulta misma. Por eso la paginación por cursor —que no lo necesita— es la recomendada para exportaciones y sincronización.

### 4.3 Ordenación por meta

```php
// Filtra implícitamente: INNER JOIN deja fuera lo que no tenga la clave
'meta_key' => '_property_price', 'orderby' => 'meta_value_num',

// Conserva los elementos sin valor
'meta_query' => [ 'precio' => [ 'key' => '_property_price', 'compare' => 'EXISTS' ] ],
'orderby'    => [ 'precio' => 'DESC', 'ID' => 'DESC' ],
```

Ordenar por meta implica `JOIN` + `filesort`: MySQL no puede usar índice para ordenar por `meta_value`. Es inevitable, y la razón de que la ordenación sea opt-in por campo y de que la caché de respuesta importe tanto en listados ordenados.

El desempate por `ID` es obligatorio: sin él, dos elementos con el mismo precio pueden alternar entre peticiones y provocar duplicados al paginar.

---

## 5. Carga diferida

| Elemento | Se carga cuando |
| -------- | --------------- |
| Módulos desactivados | Nunca — no se registran |
| Rutas REST | Solo en `rest_api_init` |
| Pantallas del admin | Solo en `admin_menu` y su `screen_id` |
| Adaptadores de campos | Solo si `is_available()` |
| Esquema de un recurso | Solo al pedirlo (perezoso) |
| Swagger UI | Solo en su pantalla |
| Muestreo de BD | Solo en rebuild explícito |

Una carga de página del front-end que no toca la API debe pagar **prácticamente cero**: el fichero principal, el contenedor y el registro de providers. Ninguna consulta, ningún esquema, ninguna ruta.

Los assets del admin se encolan comprobando `$screen->id`, no en todo `admin_enqueue_scripts`. Cargar Swagger UI en cada pantalla del escritorio es el tipo de detalle que hace que un plugin tenga fama de pesado.

---

## 6. Presupuesto por petición

Objetivos para una instalación de referencia (10 000 propiedades, object cache activo, PHP 8.1):

| Escenario | Consultas | Tiempo | Memoria |
| --------- | --------: | -----: | ------: |
| Colección de 20, caché caliente | ≤ 2 | ≤ 25 ms | ≤ 8 MB |
| Colección de 20, caché fría | ≤ 8 | ≤ 150 ms | ≤ 16 MB |
| Colección con 2 filtros meta, fría | ≤ 10 | ≤ 300 ms | ≤ 16 MB |
| Elemento individual, caliente | ≤ 1 | ≤ 15 ms | ≤ 6 MB |
| Elemento individual, frío | ≤ 5 | ≤ 60 ms | ≤ 10 MB |
| Con expansión de relación (20) | +2 | +40 ms | +4 MB |
| Rebuild de esquema completo | ≤ 15 | ≤ 5 s | ≤ 64 MB |
| `/docs`, caché fría | ≤ 3 | ≤ 800 ms | ≤ 32 MB |

Son **presupuestos, no mediciones**: sirven para detectar regresiones. Superar el doble de cualquiera de ellos es un defecto que debe investigarse, no una característica del entorno.

### 6.1 Cómo medir

| Herramienta | Uso |
| ----------- | --- |
| Query Monitor | Consultas, tiempos y hooks, también en peticiones REST |
| `SAVEQUERIES` + `$wpdb->queries` | Volcado bruto de consultas y su origen |
| `wp profile` (add-on de WP-CLI) | Coste por hook y por fase de arranque |
| Cabeceras propias `X-Codeia-Debug-*` | Consultas, tiempo y aciertos de caché, solo con `WP_DEBUG` |

Las cabeceras de diagnóstico **solo se emiten con `WP_DEBUG` activo**. En producción revelarían detalles internos de la instalación.

```
X-Codeia-Debug-Queries: 6
X-Codeia-Debug-Time: 87.4ms
X-Codeia-Debug-Cache: schema=hit response=miss
```

---

## 7. Antipatrones a evitar

| Antipatrón | Por qué falla | En su lugar |
| ---------- | ------------- | ----------- |
| `get_post_meta()` en bucle sin precarga | N consultas | `update_post_meta_cache()` en lote |
| `update_post_meta_cache => false` como «optimización» | Multiplica las consultas | Dejar el valor por defecto |
| Guardar configuración con `autoload = yes` | Se lee en cada petición del sitio | `autoload = no` |
| `flush_rewrite_rules()` en `init` | Regenera todo en cada petición | Bandera + flush diferido |
| `posts_per_page => -1` | Carga la tabla entera en memoria | Paginación por cursor |
| Filtrar el resultado después de consultar | Rompe paginación y totales | Filtrar en `WP_Query` |
| Depender de `wp_cache_flush_group()` | No soportado por todos los backends | Versionar la clave |
| Cachear respuestas dependientes del rol sin `fields_hash` | Filtra campos entre roles | Incluir el hash en la clave |
| Un `transient` por elemento | Hincha `wp_options` | Cachear la respuesta completa |

---

## 8. Lista de verificación

- [ ] L1 en memoria delante de L2 siempre
- [ ] Claves versionadas; sin dependencia de `flush_group`
- [ ] Cerrojo contra estampida en esquema y OpenAPI
- [ ] `fields_hash` en las claves de respuesta
- [ ] Precarga en lote de meta, términos y relaciones
- [ ] `no_found_rows` con paginación por cursor
- [ ] Desempate por `ID` en toda ordenación
- [ ] `codeia_settings` con `autoload = no`
- [ ] Módulos desactivados sin registrar hooks
- [ ] Assets del admin encolados por `screen_id`
- [ ] TTL reducido sin object cache persistente
- [ ] Cabeceras de diagnóstico solo con `WP_DEBUG`

---

## Documentos relacionados

- [arquitectura.md](arquitectura.md) — abstracción de caché y drivers
- [02-endpoints-dinamicos.md](02-endpoints-dinamicos.md) — precarga en lote y paginación por cursor
- [03-deteccion-cpt-campos.md](03-deteccion-cpt-campos.md) — coste del muestreo y versión de entorno
- [06-swagger-openapi.md](06-swagger-openapi.md) — caché del documento y estampida
- [09-seguridad.md](09-seguridad.md) — topes que también son defensa frente a DoS
