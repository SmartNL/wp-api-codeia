# 09 — Seguridad

Documento transversal. Recoge las defensas que no pertenecen a un módulo concreto y la matriz de amenazas del plugin completo.

> Un plugin que expone contenido por HTTP y escribe ficheros amplía la superficie de ataque del sitio de forma sustancial. Las decisiones de este documento son requisitos, no recomendaciones.

---

## 1. Principios

1. **Denegación por defecto** en permisos, campos, operaciones y módulos.
2. **Validar en el borde.** Un valor que llega al controlador ya pasó `validate_callback`. Ninguna capa interna vuelve a desconfiar de él, así que la del borde no puede fallar.
3. **Listas blancas, nunca negras.** Enumerar lo permitido. Enumerar lo prohibido siempre deja algo fuera.
4. **No confiar en el cliente.** Ni en cabeceras, ni en extensiones, ni en tipos MIME declarados, ni en IDs.
5. **Fallar cerrado.** Si una comprobación no se puede resolver (caché caída, adaptador roto), se deniega.
6. **No revelar de más.** Los errores hacia fuera son genéricos; el detalle va al log.

---

## 2. Rate limiting

### 2.1 Algoritmo

**Ventana deslizante con dos contadores.** Cada identidad tiene dos: el de la ventana actual y el de la anterior. El consumo estimado pondera la anterior por la fracción de ventana transcurrida:

```
estimado = actual + anterior × (1 − transcurrido/ventana)

ventana = 60 s, límite = 120
t = 45 s dentro de la ventana actual
anterior = 100, actual = 40
estimado = 40 + 100 × (1 − 0,75) = 65   →  permitido
```

| Algoritmo | Precisión | Coste | Ráfagas en el borde |
| --------- | --------- | ----- | ------------------- |
| Ventana fija | Baja | 1 clave | Hasta 2× el límite |
| **Ventana deslizante (2 contadores)** | Buena | 2 claves | Acotadas |
| Registro deslizante | Exacta | O(n) por identidad | Ninguna |
| Token bucket | Buena | 2 valores + reloj | Configurables |

Se elige la ventana deslizante de dos contadores: coste constante (dos enteros por identidad) y sin el defecto de la ventana fija, donde 120 peticiones al final de un minuto y 120 al principio del siguiente pasan como 240 en dos segundos.

### 2.2 Claves y límites

```
codeia_rl:{ventana}:user:{user_id}      identidades autenticadas
codeia_rl:{ventana}:ip:{hash(ip)}       anónimas
codeia_rl:{ventana}:auth:{hash(ip)}     endpoint de emisión de tokens
```

| Ámbito | Límite | Ventana |
| ------ | ------ | ------- |
| Anónimo por IP | 60 | 1 min |
| Autenticado por usuario | 300 | 1 min |
| Emisión de token por IP | 5 | 15 min |
| Emisión de token por usuario | 5 | 15 min |
| Subida por usuario | 50 | 1 h |

La IP se guarda **hasheada** con una sal del sitio: es dato personal bajo el RGPD y no hay razón para conservarla en claro en la caché.

Obtenerla es menos trivial de lo que parece. `$_SERVER['REMOTE_ADDR']` es lo único fiable; `X-Forwarded-For` lo puede falsificar el cliente **salvo** que el sitio esté tras un proxy conocido. El plugin usa `REMOTE_ADDR` por defecto y solo lee cabeceras de proxy si el administrador declara los rangos de confianza. Sin esa condición, cualquiera esquiva el límite rotando la cabecera.

El endpoint de emisión limita **por IP y por usuario a la vez**: solo por IP, un ataque distribuido contra una cuenta pasa; solo por usuario, se puede sondear muchas cuentas desde una IP.

### 2.3 Cabeceras

```
X-RateLimit-Limit: 300
X-RateLimit-Remaining: 247
X-RateLimit-Reset: 1757001600
Retry-After: 23          (solo en 429)
```

`Retry-After` en el 429 es lo que permite a un cliente correcto esperar en lugar de reintentar en bucle.

### 2.4 Degradación

Sin object cache persistente, los contadores caen a transients: escrituras en `wp_options` en cada petición y, en instalaciones con varios servidores web, contadores **por servidor** — el límite efectivo se multiplica por el número de nodos.

El panel de estado lo marca en ámbar y lo dice explícitamente. **No se desactiva el rate limiting** por falta de object cache: un límite impreciso protege más que ninguno.

---

## 3. Validación y sanitización

### 3.1 En el borde

Cada argumento se declara con sus dos callbacks. Es la vía del núcleo y se ejecuta **antes** del controlador:

```php
'args' => [
    'per_page' => [
        'type'              => 'integer',
        'default'           => 10,
        'minimum'           => 1,
        'maximum'           => 100,
        'sanitize_callback' => 'absint',
        'validate_callback' => 'rest_validate_request_arg',
    ],
    'orderby' => [
        'type'              => 'string',
        'enum'              => $definition->orderable_fields(),   // lista blanca
        'sanitize_callback' => 'sanitize_key',
    ],
],
```

`enum` construido desde el esquema, no escrito a mano: la lista blanca se mantiene sola cuando cambia la configuración.

### 3.2 Por tipo

| Tipo | Sanitización | Validación |
| ---- | ------------ | ---------- |
| `integer` | `absint` / `intval` | rango |
| `number` | `floatval` | rango |
| `string` | `sanitize_text_field` | longitud, patrón |
| Clave / slug | `sanitize_key` | lista blanca |
| `email` | `sanitize_email` | `is_email` |
| `uri` | `esc_url_raw` | esquema permitido |
| HTML | `wp_kses_post` | — |
| `date-time` | `rest_parse_date` | rango |
| `array` | recursivo por `items` | tamaño máximo |

`sanitize_text_field` en un campo que debe admitir HTML lo destruye; `wp_kses_post` en uno que no debería tenerlo lo permite. El tipo del esquema ([03](03-deteccion-cpt-campos.md)) decide cuál se aplica, y es otra razón por la que la detección de tipos no es cosmética.

---

## 4. Matriz de amenazas

| # | Amenaza | Vector concreto | Mitigación | Dónde |
| - | ------- | --------------- | ---------- | ----- |
| 1 | Inyección SQL | `orderby`, `meta_key`, `filter[]` | Listas blancas del esquema + `$wpdb->prepare` | §5 |
| 2 | XSS almacenado | Valor de meta devuelto y renderizado | `wp_json_encode` en API, escapado en admin | §6 |
| 3 | Escalada de privilegios | Escritura de meta o campos arbitrarios | Lista blanca de campos escribibles | §7 |
| 4 | Asignación masiva | `post_author`, `post_status` en el cuerpo | Campos de sistema no escribibles sin capability | §7 |
| 5 | Abuso de ficheros | Subida de PHP disfrazado | Validación por contenido, lista blanca | [05](05-subida-imagenes.md) |
| 6 | Enumeración de usuarios | Mensajes distintos, parámetro `author` | Mensaje único, `author` restringido | §8 |
| 7 | Divulgación de información | 403 frente a 404, campos ocultos en OpenAPI | 404 uniforme, documento por ámbito | §8 |
| 8 | DoS por consulta | `per_page` alto, `meta_query` compleja | Topes duros | §9 |
| 9 | DoS por descompresión | PNG de dimensiones enormes | Límite de píxeles previo | [05](05-subida-imagenes.md) |
| 10 | CSRF | Rutas `admin/` por cookie | Nonce `wp_rest` | [08](08-dashboard-admin.md) |
| 11 | Robo de credenciales | Token en `localStorage`, HTTP | Vida corta, cookie `HttpOnly`, HTTPS obligatorio | [01](01-autenticacion.md) |
| 12 | Reutilización de refresh | Token robado usado en paralelo | Rotación con detección por familia | [01](01-autenticacion.md) |
| 13 | Acceso directo a ficheros | Petición a un `.php` del plugin | `defined('ABSPATH') \|\| exit;` | §10 |

---

## 5. Inyección SQL

`$wpdb->prepare()` protege **valores**, no identificadores. Los nombres de columna, tabla y las cláusulas `ORDER BY` no admiten marcadores de posición: la única defensa es la lista blanca.

```php
// ❌ el valor está preparado, pero meta_key viene del cliente sin filtrar
$wpdb->prepare( "... WHERE meta_key = %s AND meta_value > %d",
                $_GET['field'], $_GET['min'] );   // meta_key sin validar

// ✅ identificador contra lista blanca, valor preparado
$field = $definition->storage_key_for( $request['field'] );   // null si no existe
if ( null === $field ) {
    return new WP_Error( 'codeia_unknown_field', ..., [ 'status' => 400 ] );
}
$wpdb->prepare( "... WHERE meta_key = %s AND meta_value > %d", $field, $min );
```

El punto no es que `prepare` falle, sino que un `meta_key` no validado permite leer **cualquier** clave meta de la instalación, incluidas las de otros plugins y las internas. Es divulgación de información aunque no haya inyección.

La consulta de muestreo de [03 §5.1](03-deteccion-cpt-campos.md) usa `prepare` para el post type y para los límites numéricos; su única entrada libre es un valor, nunca un identificador.

**Regla:** todo identificador que llega del cliente se traduce a través del esquema. Si la traducción devuelve `null`, la petición se rechaza. El valor del cliente nunca llega a la consulta.

---

## 6. XSS

### 6.1 En las respuestas

`wp_json_encode()` escapa lo necesario para JSON. El riesgo aparece cuando la respuesta se sirve con un `Content-Type` que el navegador interpreta como HTML.

Defensas:

```
Content-Type: application/json; charset=UTF-8
X-Content-Type-Options: nosniff
```

`nosniff` impide que el navegador reinterprete un JSON como HTML — el paso necesario para que un valor de meta con `<script>` se ejecute.

### 6.2 En el dashboard

Es la superficie real. El dashboard muestra valores de meta que provienen de la base de datos y pudieron escribirse por API:

| Contexto | Función |
| -------- | ------- |
| Texto en HTML | `esc_html()` |
| Atributo | `esc_attr()` |
| URL | `esc_url()` |
| Dentro de `<script>` | `wp_json_encode()` |
| HTML permitido | `wp_kses_post()` |

Escapado **en el punto de salida**, no al guardar. Un valor guardado escapado se corrompe al reutilizarse en otro contexto, y el doble escapado produce `&amp;lt;` visible.

Nombres de campo detectados por muestreo: proceden de `meta_key`, que un plugin de terceros pudo crear con caracteres arbitrarios. Se escapan como cualquier otro dato.

---

## 7. Escalada de privilegios

La amenaza más grave de un plugin de este tipo. Tres vectores concretos.

### 7.1 Escritura de meta arbitraria

Un endpoint que acepte `{"meta": {"clave": "valor"}}` sin filtrar permite escribir **cualquier** clave, incluidas las que controlan comportamiento.

Este plugin **no expone escritura de meta genérica**. Solo son escribibles los campos que:

1. están en el esquema del recurso,
2. están marcados como expuestos en la configuración,
3. están marcados como escribibles para el rol ([04](04-permisos.md)),
4. no están en la lista de claves de sistema.

Claves de sistema bloqueadas siempre, incluso si alguien las expone por error: `_wp_page_template`, `_edit_lock`, `_edit_last`, `_wp_attached_file`, `_wp_attachment_metadata`, `_thumbnail_id` (se gestiona por su vía) y cualquier clave que empiece por `_codeia_`.

### 7.2 Meta de usuario

**Los recursos de tipo usuario quedan fuera del alcance de este plugin.** No se exponen, ni siquiera de solo lectura.

La razón: `wp_capabilities` y `wp_user_level` son user meta. Un fallo en el filtrado de escritura sobre usuarios convierte a cualquiera en administrador. El beneficio de exponer usuarios por esta vía no compensa: la REST API nativa ya tiene `/wp/v2/users` con un modelo de permisos auditado.

### 7.3 Asignación masiva

Campos nativos que **no** son escribibles sin comprobación específica:

| Campo | Riesgo | Requisito |
| ----- | ------ | --------- |
| `author` | Atribuir contenido a otro usuario | `edit_others_posts` |
| `status` → `publish` | Publicar sin permiso | `publish_posts` |
| `id` | Sobrescribir otro elemento | Nunca escribible |
| `date` | Manipular orden y programación | `edit_published_posts` |
| `parent` | Reubicar en la jerarquía | `edit_post` sobre el padre |

Un `contributor` que envía `{"status":"publish"}` debe recibir `403`, no ver su borrador publicado. El esquema `PropertyCreate` de [06 §4](06-swagger-openapi.md) refleja exactamente esta distinción.

---

## 8. Divulgación de información

| Situación | Respuesta | Motivo |
| --------- | --------- | ------ |
| Elemento existe, sin permiso de lectura | `404` | `403` confirma su existencia |
| Campo existe, invisible para el rol | `400 unknown_field` | Su nombre es información |
| Usuario no existe / contraseña errónea | Mismo mensaje | Evita enumeración |
| Token caducado / firma inválida / revocado | Mismo mensaje | Evita sondeo |
| Error interno | `500` genérico | El detalle va al log |

Nunca se devuelven al cliente rutas del sistema de ficheros, consultas SQL, trazas ni versiones de componentes. `WP_DEBUG_DISPLAY` activo en producción anula esto: el panel de estado lo detecta y avisa.

El parámetro `author` en colecciones queda restringido a roles con `list_users`; en otro caso, filtrar por autor permite enumerar IDs de usuario válidos.

---

## 9. Denegación de servicio

| Vector | Tope | Configurable |
| ------ | ---- | ------------ |
| `per_page` | 100 | Sí, con máximo duro |
| Cláusulas de `meta_query` | 4 | Sí |
| Profundidad de expansión | 1 | No |
| Longitud de `search` | 200 caracteres | Sí |
| Tamaño del cuerpo | 1 MB (sin subida) | Sí |
| Píxeles de imagen | 8000 × 8000 | Por rol |
| Elementos por escritura en lote | 25 | Sí |

Cada cláusula de `meta_query` añade un `JOIN` sobre `wp_postmeta`, la tabla que más crece en WordPress. Cuatro cláusulas sobre cien mil filas ya es una consulta cara; sin tope, una URL puede tumbar la base de datos.

`search` con `LIKE %término%` no usa índice y hace recorrido completo. Limitar su longitud no lo arregla, pero acota el caso patológico; el rate limiting cubre el resto.

---

## 10. Endurecimiento del código

```php
defined( 'ABSPATH' ) || exit;
```

Primera línea de **todo** fichero PHP del plugin. Sin ella, una petición directa a un fichero del plugin lo ejecuta fuera del contexto de WordPress, sin las funciones cargadas y con los errores potencialmente visibles.

Además:

- `index.php` vacío en cada directorio, para servidores con listado activo.
- Sin `eval()`, sin `extract()`, sin variables variables.
- Sin `unserialize()` sobre datos de petición. Los valores de meta se leen con `maybe_unserialize` a través de `get_post_meta()`, que solo toca datos escritos por el propio sitio.
- Comparación de secretos siempre con `hash_equals()`.
- Aleatoriedad siempre con `random_bytes()` / `wp_generate_password()`, nunca `rand()` ni `uniqid()`.

---

## 11. Lista de verificación

**Entrada**
- [ ] Todo argumento con `sanitize_callback` y `validate_callback`
- [ ] Identificadores (`orderby`, `meta_key`, campos) traducidos por lista blanca del esquema
- [ ] Topes duros en `per_page`, cláusulas meta, expansión y cuerpo

**Autorización**
- [ ] Denegación por defecto en toda combinación
- [ ] `permission_callback` real en cada ruta; ningún `'__return_true'`
- [ ] Capability de objeto además de la de colección
- [ ] Campos de sistema bloqueados aunque se expongan por error
- [ ] Recursos de tipo usuario fuera del alcance

**Salida**
- [ ] `X-Content-Type-Options: nosniff`
- [ ] Escapado en el punto de salida del dashboard
- [ ] 404 y no 403 para elementos no visibles
- [ ] Errores genéricos hacia fuera, detalle al log

**Credenciales**
- [ ] HTTPS obligatorio; sin él no se emiten credenciales
- [ ] Almacenadas solo hasheadas
- [ ] `hash_equals()` en toda comparación
- [ ] Nunca en los logs

**Infraestructura**
- [ ] `defined('ABSPATH') || exit;` en todos los ficheros
- [ ] Rate limiting activo aunque degradado
- [ ] `WP_DEBUG_DISPLAY` desactivado en producción
- [ ] `/uploads` no ejecuta PHP

---

## Documentos relacionados

- [01-autenticacion.md](01-autenticacion.md) — credenciales, revocación, límites de emisión
- [04-permisos.md](04-permisos.md) — modelo de autorización completo
- [05-subida-imagenes.md](05-subida-imagenes.md) — validación de ficheros y cuotas
- [08-dashboard-admin.md](08-dashboard-admin.md) — nonces y superficie del admin
- [10-rendimiento.md](10-rendimiento.md) — object cache compartida con el rate limiting
