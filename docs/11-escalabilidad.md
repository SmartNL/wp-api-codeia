# 11 — Escalabilidad futura

Define cómo el plugin crece más allá de una instalación única: multisitio, portabilidad de la configuración, versionado de la API, consumo headless y el camino hacia un panel externo.

> Este documento distingue entre lo que se implementa desde la primera versión y lo que solo condiciona **qué debe quedar abstraído ahora** para no bloquearlo después.

---

## 1. Multisitio

### 1.1 Configuración por sitio, con plantilla de red

Cada sitio de la red tiene su propia configuración. Los CPTs, los campos y los roles varían de un sitio a otro, así que una configuración de red única sería inaplicable.

| Dato | Ámbito | Función |
| ---- | ------ | ------- |
| `codeia_settings` | Por sitio | `get_option()` |
| Secreto de firma JWT | Por sitio | `get_option()` |
| Plantilla por defecto de red | Red | `get_site_option()` |
| Módulos permitidos en la red | Red | `get_site_option()` |
| Límites máximos de la red | Red | `get_site_option()` |

La configuración de red actúa como **techo y plantilla**, nunca como sustituto: define los valores iniciales de un sitio nuevo y el máximo que un administrador de sitio puede conceder. Un administrador de red puede prohibir el módulo de subida en toda la red; un administrador de sitio no puede reactivarlo.

**Secretos de firma por sitio, no de red.** Compartirlos permitiría que un token emitido en un sitio se aceptara en otro, cruzando las fronteras de permisos entre sitios de la red.

### 1.2 Tablas

`{$wpdb->prefix}codeia_logs` usa el prefijo **del sitio**, no el de la red: `wp_2_codeia_logs`. Se crea al activar en cada sitio y se elimina en `wp_uninitialize_site`. Sin ese enganche, borrar un sitio deja tablas huérfanas para siempre.

Con activación en red, la creación debe recorrer los sitios existentes **por lotes**. Una red con quinientos sitios no puede procesarse en la petición de activación:

```php
if ( is_multisite() && $network_wide ) {
    // Encolar, no ejecutar: el bucle con switch_to_blog agota el tiempo
    update_site_option( 'codeia_pending_site_setup', get_sites( [ 'fields' => 'ids' ] ) );
}
```

Los sitios pendientes se procesan en `admin_init` en tandas pequeñas, y los nuevos en `wp_initialize_site`.

### 1.3 Caché

Las claves de object cache en multisitio llevan el ID de sitio automáticamente, **salvo** en los grupos declarados como globales. Los grupos de este plugin **no** deben ser globales: el esquema y las respuestas son específicos de cada sitio.

La excepción es el rate limiting por IP, donde tiene sentido contar a través de toda la red — una IP que abusa lo hace contra la infraestructura compartida. Se registra como grupo global de forma explícita:

```php
wp_cache_add_global_groups( [ 'codeia_rl_global' ] );
```

Los límites por usuario siguen siendo por sitio, porque los roles lo son.

---

## 2. Exportación e importación

### 2.1 Formato

```json
{
  "format": "codeia-config",
  "format_version": 1,
  "exported_at": "2026-09-04T10:22:31+00:00",
  "source": {
    "site_url": "https://ejemplo.com",
    "plugin_version": "1.2.0",
    "wp_version": "7.1",
    "post_types": ["property", "flavor_agent"]
  },
  "config": { "...": "codeia_settings sin secretos" },
  "checksum": "sha256:..."
}
```

`format_version` es independiente de la versión del plugin: describe la forma del **fichero**, no del producto. Permite importar en una versión distinta del plugin mientras el formato sea compatible.

**Sin secretos, nunca.** Ni claves de firma, ni API Keys, ni tokens. Un fichero de configuración acaba en un repositorio, en un correo o en un ticket de soporte. Los secretos se regeneran en destino.

El `checksum` detecta corrupción y edición accidental, no manipulación maliciosa: quien puede editar el fichero puede recalcularlo. Su función es evitar importar un JSON truncado.

### 2.2 Importación

```
Subir fichero
   ├─▶ ¿format y format_version compatibles?   no ─▶ rechazar
   ├─▶ ¿checksum correcto?                     no ─▶ advertir, permitir continuar
   ├─▶ Validar contra el esquema de configuración
   ├─▶ COMPARAR con el entorno destino:
   │      · post types que no existen aquí     ─▶ marcar, no importar
   │      · roles que no existen aquí          ─▶ marcar, no importar
   │      · campos no detectados en destino    ─▶ importar como «manual»
   │      · módulos no permitidos por la red   ─▶ descartar
   ├─▶ MOSTRAR DIFF y pedir confirmación
   └─▶ Copia de seguridad → aplicar → migrar → invalidar cachés
```

El paso de comparación es lo que hace la importación segura entre entornos distintos. Importar la configuración de producción en un entorno de desarrollo donde falta un plugin haría referencia a recursos inexistentes; sin la comparación, el resultado sería una configuración silenciosamente rota.

**La confirmación muestra un diff**, no una lista de ficheros. El administrador necesita ver qué permisos cambian antes de aceptar: importar una configuración es una operación de seguridad.

### 2.3 Casos de uso

| Caso | Flujo |
| ---- | ----- |
| Desarrollo → producción | Exportar, importar, revisar diff, regenerar secretos |
| Plantilla multisitio | Exportar de un sitio, guardar como plantilla de red |
| Copia previa a cambio | Exportar antes de tocar la matriz de permisos |
| Soporte | Exportar (sin secretos) y adjuntar al ticket |
| Control de versiones | Exportar a un fichero versionado en el repositorio |

---

## 3. Versionado de la API

### 3.1 Convivencia

`v1` y `v2` son namespaces REST independientes que coexisten. La regla de rewrite captura `(v\d+)` de forma genérica, así que añadir una versión no exige regenerar reglas ([07 §5.1](07-rewrite-rules.md)).

Cada versión tiene su propia definición congelada: `v1` sigue devolviendo lo que devolvía aunque la configuración de `v2` cambie. Se consigue **guardando una instantánea de la definición** al congelar la versión, no recalculándola desde la configuración vigente.

### 3.2 Qué rompe compatibilidad

| Cambio | ¿Nueva versión? |
| ------ | --------------- |
| Añadir campo, endpoint o parámetro opcional | No |
| Añadir un valor a un `enum` de respuesta | No |
| Renombrar o eliminar un campo | **Sí** |
| Cambiar el tipo de un campo | **Sí** |
| Añadir un parámetro obligatorio | **Sí** |
| Endurecer validación de un parámetro existente | **Sí** |
| Cambiar el formato de error | **Sí** |
| Cambiar el valor por defecto de un parámetro | **Sí** |

Los dos últimos se pasan por alto con frecuencia. Cambiar `per_page` de 10 a 20 por defecto altera lo que reciben todos los clientes que no lo especifican; endurecer una validación rompe a quien enviaba un valor que antes se aceptaba.

### 3.3 Deprecación

```
Deprecation: true
Sunset: Sat, 01 Aug 2026 00:00:00 GMT
Link: <https://ejemplo.com/wp-json/codeia/v2/property>; rel="successor-version"
Warning: 299 - "La versión v1 se retirará el 2026-08-01"
```

`Sunset` es la cabecera estándar (RFC 8594) para anunciar la retirada de un recurso.

Calendario mínimo **(propuesto)**:

| Fase | Duración | Estado |
| ---- | -------- | ------ |
| Anuncio | ≥ 90 días antes | Cabeceras + aviso en el dashboard |
| Deprecada | ≥ 180 días | Funciona con normalidad |
| Solo lectura | 30 días | Escrituras devuelven 410 |
| Retirada | — | 410 Gone con enlace al sucesor |

El dashboard registra el **último uso de cada versión** y qué identidades la consumen. Retirar una versión sin saber si alguien la usa es la decisión que más integraciones rompe; con ese dato, la retirada es informada.

---

## 4. Consumo headless

### 4.1 CORS

WordPress envía `Access-Control-Allow-Origin: *` en las respuestas REST mediante `rest_send_cors_headers()`. Es permisivo y suficiente para lecturas públicas, pero **incompatible con credenciales**: el navegador rechaza `Allow-Origin: *` junto a `Allow-Credentials: true`.

Configuración propia:

| Modo | `Allow-Origin` | Credenciales | Uso |
| ---- | -------------- | ------------ | --- |
| Público (por defecto) | `*` | No | API abierta de solo lectura |
| Lista de orígenes | El origen si está en la lista | Sí | SPA propia |
| Mismo origen | `home_url()` | Sí | Front-end del propio sitio |

```php
add_filter( 'rest_pre_serve_request', function ( $served, $result, $request ) {
    $origin = get_http_origin();
    if ( $origin && in_array( $origin, $this->allowed_origins(), true ) ) {
        header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
        header( 'Access-Control-Allow-Credentials: true' );
        header( 'Vary: Origin' );                    // imprescindible
    }
    return $served;
}, 10, 3 );
```

**`Vary: Origin` no es opcional.** Sin él, cualquier caché intermedia —CDN, proxy, caché de página— puede servir a un origen la respuesta con la cabecera CORS de otro. Es un fallo real de configuración de CORS, y con caché delante se convierte en fuga entre orígenes.

Los orígenes se declaran completos (`https://app.ejemplo.com`), sin comodines de subdominio. Un comodín concede acceso a cualquier subdominio, incluidos los que un atacante pudiera controlar por una toma de subdominio.

### 4.2 Preflight

El REST server del núcleo responde a `OPTIONS` y emite `Access-Control-Allow-Methods` y `Allow-Headers`. Las cabeceras propias hay que añadirlas:

```php
add_filter( 'rest_allowed_cors_headers', fn( $h ) => array_merge( $h, [
    'X-Codeia-Key', 'X-RateLimit-Limit', 'X-RateLimit-Remaining',
] ) );
```

Sin declarar `X-Codeia-Key`, el navegador bloquea la petición antes de enviarla y el error aparece como fallo de red, sin nada en los logs del servidor. Es de los problemas más difíciles de diagnosticar en integraciones headless.

Las respuestas de preflight se cachean con `Access-Control-Max-Age: 86400` para evitar un `OPTIONS` por cada petición.

### 4.3 Requisitos de un front-end desacoplado

| Necesidad | Cubierto por |
| --------- | ------------ |
| Listados paginados estables | Paginación por cursor ([02 §6.2](02-endpoints-dinamicos.md)) |
| Respuestas ligeras | `_fields` |
| Menos peticiones | `expand` de relaciones |
| Tipos fiables | Esquema normalizado ([03](03-deteccion-cpt-campos.md)) |
| Contrato para generar clientes | OpenAPI 3.1 ([06](06-swagger-openapi.md)) |
| Autenticación sin cookies | JWT / API Key ([01](01-autenticacion.md)) |
| Caché en cliente | `ETag` + `If-None-Match` **(propuesto)** |

`ETag` con `304 Not Modified` es la mejora pendiente con mejor relación coste/beneficio: convierte una respuesta de 200 KB en una de cabeceras cuando nada ha cambiado. Se calcularía como hash de la respuesta serializada, reutilizando la clave de caché ya existente.

---

## 5. Panel SaaS externo **(futuro)**

No se implementa. Lo que interesa ahora es **no cerrarse la puerta**.

### 5.1 Qué haría falta

Un servicio externo que configure y observe varias instalaciones desde un panel único: catálogo agregado de recursos, plantillas de configuración reutilizables, métricas de uso y alertas.

### 5.2 Qué debe estar abstraído desde ya

| Abstracción | Estado | Por qué |
| ----------- | ------ | ------- |
| Configuración como dato serializable | ✅ Ya | `codeia_settings` es un array plano exportable |
| Almacén de configuración tras interfaz | ⚠️ **Hacer ahora** | Hoy es `get_option()` directo; con `ConfigStore` podría ser remoto |
| Esquema independiente del transporte | ✅ Ya | `SchemaRegistry` no sabe que existe REST |
| Formato de exportación versionado | ✅ Ya | `format_version` independiente del plugin |
| Logs consultables por API | ⚠️ **Hacer ahora** | Ya hay ruta `admin/logs`; basta con no acoplarla al dashboard |
| Identificador estable de instalación | ❌ Falta | UUID generado en la activación, sin datos personales |
| Métricas agregadas | ❌ Falta | Contadores por recurso y operación |

Las dos marcadas **«hacer ahora»** son baratas hoy y caras después. Introducir una interfaz `ConfigStore` cuando solo hay una implementación cuesta unas decenas de líneas; hacerlo cuando hay veinte puntos que llaman a `get_option('codeia_settings')` directamente es una refactorización transversal.

Las marcadas «falta» pueden esperar: no condicionan el diseño actual.

### 5.3 Lo que no se hará

**Ningún dato sale de la instalación sin consentimiento explícito.** Sin telemetría silenciosa, sin llamadas a casa en la activación, sin comprobación de licencia que envíe la URL del sitio. Si un panel externo llega a existir, la instalación se conecta a él por decisión del administrador y con credenciales que este genera.

Es una restricción de diseño, no una promesa de marketing: condiciona que el plugin **funcione al completo sin red saliente**, y que ningún módulo dependa de un servicio remoto.

---

## 6. Lista de verificación

**Multisitio**
- [ ] Configuración y secretos por sitio, nunca de red
- [ ] Opciones de red como techo y plantilla, no como sustituto
- [ ] Tablas con prefijo de sitio; limpieza en `wp_uninitialize_site`
- [ ] Activación en red por lotes, no en una petición
- [ ] Grupos de caché no globales, salvo el límite por IP

**Portabilidad**
- [ ] Exportación sin secretos
- [ ] `format_version` independiente de la versión del plugin
- [ ] Importación con comparación de entorno y diff previo
- [ ] Copia de seguridad automática antes de importar

**Versionado**
- [ ] Instantánea congelada por versión, no recálculo
- [ ] Cabeceras `Deprecation`, `Sunset` y `Link` en versiones deprecadas
- [ ] Registro de último uso por versión

**Headless**
- [ ] `Vary: Origin` siempre que `Allow-Origin` no sea `*`
- [ ] Sin comodines de subdominio en la lista de orígenes
- [ ] Cabeceras propias declaradas en `rest_allowed_cors_headers`
- [ ] `Allow-Credentials` nunca junto a `Allow-Origin: *`

**Futuro**
- [ ] `ConfigStore` tras interfaz desde la primera versión
- [ ] Logs consultables sin acoplamiento al dashboard
- [ ] Cero telemetría sin consentimiento explícito

---

## Documentos relacionados

- [arquitectura.md](arquitectura.md) — configuración como dato y abstracciones del núcleo
- [07-rewrite-rules.md](07-rewrite-rules.md) — convivencia de versiones en las rutas
- [08-dashboard-admin.md](08-dashboard-admin.md) — pantallas de exportación e importación
- [06-swagger-openapi.md](06-swagger-openapi.md) — contrato para clientes generados
- [10-rendimiento.md](10-rendimiento.md) — caché en multisitio
