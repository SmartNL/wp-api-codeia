# 04 — Sistema de permisos por rol

Define qué puede hacer una identidad ya autenticada: sobre qué recursos, con qué operaciones y sobre qué campos concretos.

> Presupone la identidad resuelta en [01-autenticacion.md](01-autenticacion.md). Aquí no se pregunta *quién eres* sino *qué se te permite*.

---

## 1. Modelo de cuatro ejes

Una decisión de acceso se evalúa sobre cuatro dimensiones:

```
       ROL            ×      RECURSO      ×    OPERACIÓN   ×     CAMPO
   ───────────           ───────────         ────────────      ────────
   editor                property            read              price
   author                flavor_agent        create            agent_id
   subscriber            (taxonomías)        update            latitude
   anónimo                                   delete
                                             upload
```

Los tres primeros ejes deciden **si la petición procede**. El cuarto decide **qué parte del resultado es visible o escribible**, y se aplica después, ya sobre los datos.

Separarlos es lo que permite responder «puedes leer propiedades, pero no su precio de coste» sin duplicar rutas ni recursos.

### 1.1 Denegación por defecto

Toda combinación no concedida explícitamente está denegada. Un recurso recién detectado, un rol recién creado o una operación recién añadida al plugin arrancan **sin acceso**.

La consecuencia práctica: actualizar el plugin no puede ampliar el acceso de nadie. Si una versión futura añade la operación `bulk_delete`, ningún rol la tendrá hasta que un administrador la conceda.

---

## 2. Dos puertas, no una

Una petición atraviesa **dos** comprobaciones independientes, y ambas deben pasar:

```
┌─────────────────────────────────────────────────────────┐
│ PUERTA 1 — Matriz del plugin                            │
│ ¿La configuración concede ROL × RECURSO × OPERACIÓN?    │
│ Fuente: codeia_settings['permissions']                  │
└──────────────────────────┬──────────────────────────────┘
                           │ pasa
                           ▼
┌─────────────────────────────────────────────────────────┐
│ PUERTA 2 — Capabilities de WordPress                    │
│ ¿current_user_can( cap del post type [, $post_id] )?    │
│ Fuente: WP_Roles + map_meta_cap                         │
└──────────────────────────┬──────────────────────────────┘
                           │ pasa
                           ▼
                       Operación
```

**Por qué dos.** La matriz del plugin es configuración de API: expresa qué se expone hacia fuera. Las capabilities son el modelo de permisos real de WordPress, respetado por el editor, por otros plugins y por `map_meta_cap`. Sustituir el segundo por el primero convertiría la API en una vía para eludir el modelo de permisos del sitio — un usuario sin `edit_posts` podría editar contenido por REST si alguien marcase mal una casilla.

La matriz solo puede **restringir**, nunca ampliar. Es una decisión estructural, no una casilla de configuración.

### 2.1 Capabilities por operación

Se derivan del `capability_type` del post type, no se codifican a mano. Para `property` con `capability_type => 'post'`:

| Operación | Capability de colección | Capability de objeto |
| --------- | ----------------------- | -------------------- |
| `read` (listar) | `read` | — |
| `read` (elemento) | `read` | `read_post` |
| `create` | `edit_posts` | — |
| `update` | `edit_posts` | `edit_post` |
| `delete` | `delete_posts` | `delete_post` |
| `upload` | `upload_files` | `edit_post` (del destino) |

Las de objeto pasan por `map_meta_cap`, que es lo que hace que un `author` pueda editar **sus** entradas y no las ajenas. Comprobar solo la capability de colección (`edit_posts`) y no la de objeto (`edit_post`, `$id`) es el fallo de autorización más común en endpoints REST personalizados: concede a cualquier autor la edición del contenido de todos los demás.

### 2.2 Nunca comparar roles

```php
// ❌ frágil: ignora roles personalizados y capabilities modificadas
if ( in_array( 'editor', $user->roles, true ) ) { ... }

// ✅ correcto
if ( current_user_can( 'edit_post', $post_id ) ) { ... }
```

Los roles son agrupaciones mutables de capabilities. Cualquier plugin de membresía, tienda o LMS crea roles propios y altera los existentes. La comparación por nombre de rol ignora todo eso y se rompe en cuanto el sitio sale de la instalación por defecto.

Los roles **sí** se usan como eje de la matriz de configuración —es lo que un administrador entiende— pero la comprobación efectiva siempre se resuelve en capabilities.

---

## 3. Resolución en cascada

Las reglas se declaran a distintos niveles de especificidad. Gana **la más específica**, no la más permisiva ni la más restrictiva.

```
Especificidad ▲
              │  5. Filtro codeia/permissions/can      (código de terceros)
              │  4. Rol × Recurso × Operación × Campo
              │  3. Rol × Recurso × Operación
              │  2. Rol × Recurso
              │  1. Rol (por defecto global)
              │  0. Denegado
              ▼
```

```
resolve( rol, recurso, operación, campo? ):
    decision = DENY
    para nivel en 1..4:
        si existe regla en ese nivel:
            decision = regla.efecto        # sobrescribe
    decision = apply_filters( 'codeia/permissions/can', decision, contexto )
    return decision
```

Cada nivel **sobrescribe** al anterior en lugar de combinarse. Un `editor` con `property.read = allow` (nivel 3) y `property.read.price = deny` (nivel 4) puede leer propiedades sin ver el precio: la regla de campo es más específica y gana.

El filtro del nivel 5 tiene la última palabra por diseño — es el punto de extensión para lógica que la matriz no puede expresar (permisos por horario, por propiedad de un campo, por integración externa). Se documenta como tal: **puede conceder lo que la matriz deniega**, y por eso `PermissionContext` incluye la decisión previa y el motivo, para que el código de terceros decida con conocimiento.

---

## 4. Matriz de permisos por defecto

Valores iniciales al activar el plugin, para `property`. **Todos los recursos arrancan sin exponer**; esta matriz solo se aplica una vez el administrador expone el recurso.

| Rol | read | create | update | delete | upload |
| --- | :--: | :----: | :----: | :----: | :----: |
| Anónimo | ✅ solo `publish` | ❌ | ❌ | ❌ | ❌ |
| `subscriber` | ✅ solo `publish` | ❌ | ❌ | ❌ | ❌ |
| `contributor` | ✅ + propios borradores | ✅ | ⚠️ propios, no publicados | ⚠️ propios, no publicados | ❌ |
| `author` | ✅ + propios | ✅ | ⚠️ propios | ⚠️ propios | ✅ |
| `editor` | ✅ todos | ✅ | ✅ | ✅ | ✅ |
| `administrator` | ✅ todos | ✅ | ✅ | ✅ | ✅ |

⚠️ = permitido en la matriz, acotado a los objetos propios por `map_meta_cap` en la puerta 2.

### 4.1 Visibilidad por campo

Ejemplo con campos reales de `property`:

| Campo | Anónimo | subscriber | author | editor | admin |
| ----- | :-----: | :--------: | :----: | :----: | :---: |
| `title`, `content` | 👁 | 👁 | ✏️ | ✏️ | ✏️ |
| `price`, `currency` | 👁 | 👁 | ✏️ | ✏️ | ✏️ |
| `rooms`, `built_area` | 👁 | 👁 | ✏️ | ✏️ | ✏️ |
| `agent_id` | 👁 | 👁 | 👁 | ✏️ | ✏️ |
| `latitude`, `longitude` | ❌ | 👁 | 👁 | ✏️ | ✏️ |
| `cadastral_ref` | ❌ | ❌ | 👁 | ✏️ | ✏️ |
| `price_before` | ❌ | ❌ | 👁 | ✏️ | ✏️ |

👁 lectura · ✏️ lectura y escritura · ❌ sin acceso

Los casos interesantes: las **coordenadas exactas** se ocultan al público (dato explotable para localizar un inmueble vacío) y la **referencia catastral** queda restringida por ser identificador registral. Son ejemplos de por qué el eje de campo existe: sin él, exponer `property` obligaría a exponerlo todo.

---

## 5. Aplicación sobre los campos

### 5.1 Lectura — proyección silenciosa

En lectura, los campos no visibles **se eliminan de la respuesta sin avisar**. No se devuelven a `null` ni se marcan como ocultos: simplemente no están.

Devolver `"cadastral_ref": null` confirmaría que el campo existe y que hay algo que ocultar. La ausencia no distingue «no tienes permiso» de «no tiene valor», y esa ambigüedad es deseable.

### 5.2 Escritura — rechazo explícito

En escritura, el comportamiento es el **opuesto**: se rechaza con error en lugar de descartar en silencio.

```
PATCH /codeia/v1/property/42
{ "price": 495000, "cadastral_ref": "1234567AB1234C0001XX" }

→ 403 codeia_field_forbidden
  { "code": "codeia_field_forbidden",
    "message": "No tienes permiso para modificar el campo «cadastral_ref».",
    "data": { "status": 403, "fields": ["cadastral_ref"] } }
```

**La escritura no se aplica parcialmente.** O se acepta la petición entera o se rechaza entera.

Justificación: descartar en silencio un campo vetado devuelve `200 OK` a un cliente que cree haber guardado el dato. Es una inconsistencia silenciosa que aparece mucho después, cuando alguien nota que un valor nunca se actualiza. En escritura, el cliente **necesita** saber que su intención no se cumplió; en lectura, no tiene ninguna intención que frustrar.

### 5.3 Campos invisibles frente a campos vetados

Hay una distinción sutil con consecuencias de seguridad:

| Situación | Respuesta | Por qué |
| --------- | --------- | ------- |
| Campo inexistente en el esquema | `400 codeia_unknown_field` | No hay nada que ocultar |
| Campo existente, **sin visibilidad** para el rol | `400 codeia_unknown_field` | Su existencia es información: se trata como inexistente |
| Campo **visible** pero no escribible | `403 codeia_field_forbidden` | El rol ya sabe que existe; ocultarlo no aporta nada |

Si un rol no puede ni ver `cadastral_ref`, intentar escribirlo devuelve **400 «campo desconocido»**, no 403. Un 403 confirmaría la existencia del campo a quien no debería saber siquiera que está ahí.

---

## 6. Filtrado de colecciones

El permiso de lectura no solo recorta campos: también **restringe qué elementos entran en el listado**.

| Rol | Restricción aplicada a `WP_Query` |
| --- | --------------------------------- |
| Anónimo, `subscriber` | `post_status => 'publish'` |
| `contributor`, `author` | `publish` + los propios en cualquier estado (`author => $id`) |
| `editor`, `administrator` | Sin restricción de estado |

Se aplica **en la consulta**, no filtrando el resultado después. Filtrar a posteriori rompe la paginación: pedir 20 elementos y descartar 7 devuelve 13, y el total de `X-WP-Total` deja de corresponder con lo devuelto.

Para elementos individuales, cuando el post existe pero el rol no puede verlo se responde **`404`, no `403`** — mismo criterio que en §5.3: un 403 confirma la existencia.

---

## 7. Puntos de extensión

| Hook | Firma | Uso |
| ---- | ----- | --- |
| `codeia/permissions/can` | `bool $allowed, PermissionContext $ctx` | Última palabra sobre cualquier decisión |
| `codeia/permissions/field_visible` | `bool $visible, string $field, string $resource, int $user_id` | Visibilidad por campo con lógica propia |
| `codeia/permissions/field_writable` | `bool $writable, string $field, string $resource, int $user_id` | Ídem para escritura |
| `codeia/permissions/collection_args` | `array $args, string $resource, int $user_id` | Restricciones extra sobre `WP_Query` |
| `codeia/permissions/matrix_defaults` | `array $matrix` | Valores por defecto al activar |

`PermissionContext` transporta rol efectivo, recurso, operación, campo, ID de objeto, decisión previa y el nivel de la cascada que la produjo. Sin el motivo de la decisión previa, un filtro de terceros solo puede decidir a ciegas.

**Ejemplo — agentes que solo ven sus propias propiedades:**

```php
add_filter( 'codeia/permissions/collection_args', function ( $args, $resource, $user_id ) {
    if ( 'property' !== $resource || ! user_can( $user_id, 'flavor_agent_role' ) ) {
        return $args;
    }
    $args['meta_query'][] = [
        'key'   => '_property_agent_id',
        'value' => get_user_meta( $user_id, '_linked_agent_post', true ),
    ];
    return $args;
}, 10, 3 );
```

---

## 8. Rendimiento

La resolución de permisos ocurre **por campo y por elemento**: una respuesta de 20 propiedades con 28 campos son 560 evaluaciones.

Mitigaciones:

1. **Resolver por rol, no por elemento.** El conjunto de campos visibles depende del rol, no del post concreto. Se calcula **una vez por petición** y se reutiliza; solo los filtros de terceros que declaren depender del objeto fuerzan reevaluación.
2. **Cachear el conjunto de campos visibles** por (rol, recurso, operación) en memoria de petición, y en caché persistente con la versión de esquema en la clave.
3. **Precalcular las capabilities de objeto en lote.** Para colecciones, `current_user_can('edit_post', $id)` por elemento son N llamadas a `map_meta_cap`. Cuando la restricción es uniforme (autor propio), se aplica en la consulta y se omite la comprobación individual.

El `fields_hash` que entra en la clave de caché de respuesta ([02 §8.1](02-endpoints-dinamicos.md)) es precisamente el hash de este conjunto resuelto.

---

## 9. Lista de verificación

- [ ] Denegación por defecto en toda combinación no declarada
- [ ] Las dos puertas se evalúan siempre; la matriz nunca amplía capabilities
- [ ] Capability de **objeto** comprobada, no solo la de colección
- [ ] Cero comparaciones por nombre de rol en el camino de decisión
- [ ] Restricción de colección aplicada en `WP_Query`, no a posteriori
- [ ] Lectura: campos vetados ausentes, no `null`
- [ ] Escritura: rechazo total con 403, sin aplicación parcial
- [ ] Campo invisible → 400 `unknown_field`, nunca 403
- [ ] Elemento no visible → 404, nunca 403
- [ ] Campos visibles resueltos una vez por petición, no por elemento
- [ ] Cambio de rol de un usuario invalida sus tokens (ver [01 §4.3](01-autenticacion.md))

---

## Documentos relacionados

- [01-autenticacion.md](01-autenticacion.md) — resolución de identidad previa
- [02-endpoints-dinamicos.md](02-endpoints-dinamicos.md) — proyección de campos y filtrado de colecciones
- [03-deteccion-cpt-campos.md](03-deteccion-cpt-campos.md) — el catálogo de campos sobre el que se define la matriz
- [05-subida-imagenes.md](05-subida-imagenes.md) — permiso `upload` y control sobre el post destino
- [08-dashboard-admin.md](08-dashboard-admin.md) — interfaz de edición de la matriz
