# 07 — Rewrite rules automáticas

Define el alias de rutas en la raíz del dominio: cómo se registra, cuándo se regenera y cómo se evita que choque con el contenido existente.

> Módulo **desactivado por defecto**. La API funciona íntegramente sin él. Es una capa de presentación de URLs, no un requisito funcional.

---

## 1. Dos rutas para la misma API

| | Canónica | Alias |
| --- | --- | --- |
| Forma | `/wp-json/codeia/v1/property` | `/codeia/v1/property` |
| Mecanismo | REST API nativa | Rewrite rule + reenvío interno |
| Disponible | Siempre | Solo si el módulo está activo |
| Requiere enlaces permanentes | No | **Sí** |
| Riesgo de colisión | Ninguno | Con slugs de páginas y CPTs |

La canónica **siempre** existe y es la que se anuncia en el documento OpenAPI como primer `server`. El alias es adicional.

### 1.1 Por qué la canónica vive bajo `/wp-json/`

Servir la API únicamente en la raíz rompería tres cosas que dependen del prefijo estándar:

1. **Descubrimiento.** WordPress emite `Link: <https://sitio/wp-json/>; rel="https://api.w.org/"` en las cabeceras y un `<link>` en el `<head>`. Clientes y herramientas lo usan para localizar la API.
2. **Ecosistema.** `@wordpress/api-fetch`, los SDK y las herramientas de depuración asumen `/wp-json/` o el parámetro `?rest_route=`.
3. **Compatibilidad de respaldo.** Cuando los enlaces permanentes están desactivados, `/wp-json/` deja de funcionar y el núcleo cae a `?rest_route=/`. Ese respaldo **no existe** para un alias propio: sin enlaces permanentes, el alias simplemente no funciona.

El alias cubre el caso de URLs limpias sin renunciar a nada de lo anterior.

---

## 2. Registro

### 2.1 Regla

```php
add_rewrite_rule(
    '^' . preg_quote( $ns, '/' ) . '/(v\d+)/(.+?)/?$',
    'index.php?codeia_api=1&codeia_version=$matches[1]&codeia_route=$matches[2]',
    'top'
);
```

`'top'` es obligatorio. Las reglas de WordPress se evalúan en orden y las de páginas (`(.?.+?)/?$`) capturan casi cualquier cosa. Registrada abajo, la regla nunca se alcanzaría.

Las variables deben declararse o WordPress las descarta:

```php
add_filter( 'query_vars', fn( $vars ) => array_merge(
    $vars, [ 'codeia_api', 'codeia_version', 'codeia_route' ]
) );
```

### 2.2 Reenvío al REST server

La intercepción ocurre en `parse_request`, antes de que WordPress resuelva una consulta principal que no necesitamos.

```php
add_action( 'parse_request', function ( WP $wp ) {
    if ( empty( $wp->query_vars['codeia_api'] ) ) {
        return;
    }
    if ( ! defined( 'REST_REQUEST' ) ) {
        define( 'REST_REQUEST', true );          // antes de dispatch
    }
    $route = '/' . $this->namespace . '/' . $wp->query_vars['codeia_version']
           . '/' . $wp->query_vars['codeia_route'];

    $request = new WP_REST_Request( $_SERVER['REQUEST_METHOD'], $route );
    $request->set_query_params( wp_unslash( $_GET ) );
    $request->set_body( file_get_contents( 'php://input' ) );
    $request->set_headers( $this->collect_headers() );

    $server = rest_get_server();
    echo wp_json_encode( $server->response_to_data(
        $server->dispatch( $request ), false
    ) );
    exit;
} );
```

Tres detalles que no son opcionales:

- **`REST_REQUEST` antes de despachar.** Muchos plugins y el propio núcleo consultan esa constante para ajustar su comportamiento (omitir salida del front-end, cambiar el manejo de errores). Definirla tarde produce diferencias sutiles entre la ruta canónica y el alias, que es justo lo que hay que evitar: **ambas deben comportarse igual**.
- **Reenviar el cuerpo y las cabeceras.** Sin ellos, `POST` y `PATCH` llegan vacíos y la autenticación por `Authorization` no se ve. En algunos servidores esa cabecera no llega a PHP y hay que recuperarla de `getallheaders()` o de `REDIRECT_HTTP_AUTHORIZATION`.
- **`exit` después de emitir.** Si no, WordPress continúa y añade la plantilla del tema detrás del JSON.

Se reenvía la respuesta **a través del mismo `WP_REST_Server`**, no reimplementando el despacho. El alias es un cambio de URL, no una segunda implementación de la API: cualquier lógica de autenticación, permisos o filtros se aplica exactamente igual.

---

## 3. `flush_rewrite_rules()` controlado

### 3.1 Qué cuesta

`flush_rewrite_rules()` regenera **todas** las reglas del sitio —las del núcleo, las de cada CPT, las de cada taxonomía, las de cada plugin— y escribe el resultado en la opción `rewrite_rules`. En una instalación con varios CPTs y taxonomías son cientos de expresiones regulares y una escritura en base de datos.

Llamarlo en `init` lo ejecuta **en cada petición del sitio**, incluidas las del front-end. Es uno de los errores de rendimiento más caros y más frecuentes en plugins de WordPress.

### 3.2 Cuándo sí

| Momento | Acción |
| ------- | ------ |
| Activación del plugin | Registrar reglas → `flush_rewrite_rules()` |
| Desactivación | `flush_rewrite_rules()` (sin registrar: las limpia) |
| Se activa/desactiva el módulo de alias | Marcar bandera → flush diferido |
| Cambia el `namespace` o se añade una versión | Marcar bandera → flush diferido |

**Flush diferido**, nunca inmediato al guardar la configuración:

```php
// Al guardar: solo marcar.
update_option( 'codeia_flush_needed', true );

// En init (prio 99), después de que todo esté registrado:
add_action( 'init', function () {
    if ( get_option( 'codeia_flush_needed' ) ) {
        flush_rewrite_rules( false );          // false: no reescribe .htaccess
        delete_option( 'codeia_flush_needed' );
    }
}, 99 );
```

Diferirlo garantiza que el flush ocurre **después** de que todos los plugins hayan registrado sus reglas. Hacerlo durante el guardado, en `admin_post`, capturaría un estado parcial y podría dejar fuera reglas de terceros registradas más tarde.

`flush_rewrite_rules( false )` evita reescribir `.htaccess`, innecesario para reglas internas y que exige permisos de escritura que en muchos entornos no existen.

### 3.3 Orden en la activación

```
register_activation_hook
   ├─▶ 1. registrar las rewrite rules del plugin
   └─▶ 2. flush_rewrite_rules()
```

Invertirlo es el fallo clásico: se vacían las reglas antes de haber añadido las propias, y el alias devuelve 404 hasta que alguien vuelve a guardar los enlaces permanentes.

---

## 4. Detección de colisiones

El alias ocupa un prefijo en la raíz del dominio. Si existe una página con slug `codeia`, ambas compiten.

### 4.1 Comprobación previa

Antes de permitir activar el alias con un namespace dado:

```
¿Existe una página o post de nivel superior con ese slug?   get_page_by_path()
¿Algún post type usa ese rewrite slug?                      WP_Post_Type->rewrite['slug']
¿Alguna taxonomía lo usa?                                   WP_Taxonomy->rewrite['slug']
¿Es un prefijo reservado del núcleo?                        lista fija
¿Colisiona con la estructura de enlaces permanentes?        análisis de %postname%
```

| Prefijo | Motivo |
| ------- | ------ |
| `wp-json`, `wp-admin`, `wp-content`, `wp-includes` | Núcleo |
| `feed`, `rss`, `rss2`, `atom`, `rdf` | Feeds |
| `page`, `comments`, `search`, `author`, `category`, `tag` | Estructuras del núcleo |
| `embed`, `trackback` | Ídem |
| `robots.txt`, `sitemap.xml`, `wp-sitemap.xml` | Ficheros especiales |

Si hay colisión, la activación se **bloquea** con un mensaje que nombra el conflicto concreto y propone un namespace libre. No se activa con advertencia: el resultado sería una página inaccesible o una API que no responde, y el diagnóstico posterior es difícil.

### 4.2 El caso peligroso

Con enlaces permanentes de tipo `/%postname%/`, **cualquier** entrada de primer nivel compite con el alias. La regla registrada en `'top'` gana, así que un post con slug `codeia` quedaría inaccesible.

Por eso la comprobación no es solo al activar: un post creado **después** puede introducir el conflicto. Un aviso en `save_post` detecta el caso y avisa al autor antes de publicar, en lugar de dejar una entrada rota sin explicación.

---

## 5. Versionado

### 5.1 Convivencia de versiones

`v1` y `v2` coexisten como namespaces REST independientes:

```
/wp-json/codeia/v1/property     → definición congelada
/wp-json/codeia/v2/property     → definición actual
```

La regla de rewrite captura `(v\d+)` genéricamente, así que **añadir una versión no requiere una regla nueva** ni, por tanto, un flush. Solo hay que registrar las rutas REST correspondientes.

### 5.2 Política

| Cambio | ¿Nueva versión? |
| ------ | --------------- |
| Añadir un campo a la respuesta | No |
| Añadir un endpoint | No |
| Añadir un parámetro opcional | No |
| Renombrar o eliminar un campo | **Sí** |
| Cambiar el tipo de un campo | **Sí** |
| Cambiar el formato de la respuesta | **Sí** |
| Endurecer la validación de un parámetro | **Sí** |

Una versión anterior en deprecación responde con cabeceras estándar:

```
Deprecation: true
Sunset: Sat, 01 Aug 2026 00:00:00 GMT
Link: <https://ejemplo.com/wp-json/codeia/v2/property>; rel="successor-version"
```

Política y calendario de deprecación en [11-escalabilidad.md](11-escalabilidad.md).

---

## 6. Diagnóstico

Fallos habituales y su causa, tal como los muestra el panel de estado:

| Síntoma | Causa | Solución |
| ------- | ----- | -------- |
| Alias devuelve 404, canónica funciona | Reglas sin regenerar | Guardar enlaces permanentes o botón «Regenerar reglas» |
| Alias devuelve la página de inicio | Regla registrada sin `'top'` | Corregir prioridad y regenerar |
| Alias devuelve JSON + HTML del tema | Falta `exit` tras emitir | Error de implementación |
| `POST` llega con cuerpo vacío | Cuerpo no reenviado | Error de implementación |
| Alias sin autenticar, canónica sí | `Authorization` no reenviada | Recuperar de `getallheaders()`/`REDIRECT_HTTP_AUTHORIZATION` |
| Ambas devuelven 404 | Enlaces permanentes desactivados | El alias exige enlaces permanentes |

El panel incluye una comprobación activa: lanza una petición a la ruta canónica y otra al alias, compara códigos y cabeceras, y **señala cualquier divergencia**. Que ambas rutas se comporten igual es el invariante que hay que vigilar.

---

## 7. Lista de verificación

- [ ] Módulo desactivado por defecto
- [ ] La ruta canónica funciona siempre, con el alias activo o no
- [ ] Regla registrada con `'top'`
- [ ] `query_vars` declaradas
- [ ] `REST_REQUEST` definida antes de despachar
- [ ] Cuerpo y cabeceras reenviados, `Authorization` incluida
- [ ] `exit` tras emitir la respuesta
- [ ] `flush_rewrite_rules()` nunca en `init` sin bandera
- [ ] Activación: registrar y **después** flush
- [ ] Colisiones comprobadas antes de activar, y en `save_post`
- [ ] Prefijos reservados del núcleo bloqueados
- [ ] Comprobación activa de paridad canónica/alias en el panel

---

## Documentos relacionados

- [arquitectura.md](arquitectura.md) — capa de entrada y namespace configurable
- [02-endpoints-dinamicos.md](02-endpoints-dinamicos.md) — rutas REST que el alias reenvía
- [06-swagger-openapi.md](06-swagger-openapi.md) — ambos `servers` en el documento
- [08-dashboard-admin.md](08-dashboard-admin.md) — activación, diagnóstico y regeneración
- [11-escalabilidad.md](11-escalabilidad.md) — versionado y deprecación
