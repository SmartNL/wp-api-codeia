# 08 — Dashboard administrativo

Define la interfaz de configuración: qué pantallas existen, cómo se persiste la configuración y qué garantías de seguridad aplica cada una.

> Todo lo que la API hace se decide aquí. El dashboard **es** la configuración: no hay un fichero de ajustes alternativo ni constantes que dupliquen estas opciones.

---

## 1. Mapa de pantallas

```
Codeia API  (menú de nivel superior, dashicons-rest-api)
│
├── Estado              Diagnóstico del entorno y del esquema
├── Recursos            Qué CPTs y campos se exponen
├── Permisos            Matriz rol × recurso × operación × campo
├── Autenticación       Proveedores activos y su configuración
├── Documentación       Swagger UI embebida
├── Registros           Visor de logs con filtros
└── Herramientas        Rebuild, exportar/importar, regenerar reglas
```

Todas exigen `manage_options`. No se contempla un rol intermedio de «gestor de API»: quien configura qué datos salen del sitio y con qué permisos está tomando decisiones de administración plenas.

---

## 2. Estado

Primera pantalla y la más útil en soporte. Comprueba activamente el entorno y muestra el resultado con semáforo.

| Comprobación | Verde | Ámbar | Rojo |
| ------------ | ----- | ----- | ---- |
| Object cache persistente | Redis/Memcached | Solo transients | — |
| Enlaces permanentes | Activos | — | Desactivados (con alias activo) |
| HTTPS | `is_ssl()` | Entorno local | Producción sin SSL |
| Versión de PHP | ≥ 8.1 | 8.0 | < 8.0 |
| `/uploads` no ejecuta PHP | Confirmado | No comprobable | Ejecuta PHP |
| Esquema | Reciente | > 7 días | Nunca construido |
| Adaptadores de campos | ACF/MB/JE detectados | Ninguno | Versión fuera de rango |
| Límites de subida | Coherentes | Rol > servidor | — |
| Paridad canónica/alias | Idénticas | — | Divergen |
| Conflictos de esquema | Ninguno | Campos ambiguos | Tipos en disputa |

Cada fila enlaza a la explicación del problema y, cuando existe, a la acción que lo corrige. El estado se calcula bajo demanda y se cachea 5 minutos: varias comprobaciones hacen peticiones HTTP al propio sitio y no deben repetirse en cada carga.

**Muestra también los campos descartados** por la lista de exclusión del muestreo ([03 §5.2](03-deteccion-cpt-campos.md)) con su motivo. Es la respuesta a la pregunta más frecuente que generará este plugin: «¿por qué no aparece mi campo?».

---

## 3. Recursos

Dos niveles: catálogo de recursos y detalle de campos.

```
┌─ Recursos detectados ──────────────────────────────────────┐
│  ☑ property        6 taxonomías · 28 campos    [Configurar]│
│  ☐ flavor_agent    1 taxonomía  · 12 campos    [Configurar]│
│  ☐ post            (nativo)     ·  0 campos    [Configurar]│
└────────────────────────────────────────────────────────────┘

┌─ property › Campos ────────────────────────────────────────┐
│ Campo            Expuesto  Nombre      Tipo      Origen     │
│ _property_price     ☑      price       number ▾  db_sample ⚠│
│ _property_rooms     ☑      rooms       integer▾  db_sample ⚠│
│ _property_floors    ☑      floors      integer▾  db_sample ⚠│
│                                        └ ambiguo: 0/1       │
│ _property_agent_id  ☑      agent_id    integer▾  db_sample  │
│                            └ relación → flavor_agent [✓][✗] │
│ _property_cadastral ☐      cadastral_ref string▾ db_sample  │
│                            └ 🔒 meta protegida              │
└────────────────────────────────────────────────────────────┘
```

Elementos que la interfaz debe hacer visibles, porque son las decisiones que el administrador tiene que tomar con información:

- **Origen y confianza** de cada campo. Un `db_sample` lleva aviso: el tipo es inferido.
- **Ambigüedades sin resolver** (`_property_floors` con valores 0/1) marcadas y pendientes de confirmación.
- **Relaciones propuestas** con aceptar/rechazar explícito, nunca activadas solas.
- **Meta protegida** con su candado: exponerla exige confirmación, porque el autor del plugin la marcó como interna.
- **Casillas de filtrable y ordenable** por campo, con aviso de coste cuando el campo no está indexado.

---

## 4. Permisos

La pantalla más compleja de la interfaz: la matriz de [04-permisos.md](04-permisos.md) tiene cuatro ejes y no cabe en una tabla plana.

```
Recurso: [property ▾]

┌──────────────┬──────┬────────┬────────┬────────┬────────┐
│ Rol          │ read │ create │ update │ delete │ upload │
├──────────────┼──────┼────────┼────────┼────────┼────────┤
│ Anónimo      │  ☑   │   ☐    │   ☐    │   ☐    │   —    │
│ subscriber   │  ☑   │   ☐    │   ☐    │   ☐    │   ☐    │
│ author       │  ☑   │   ☑    │  ☑ ⓘ   │  ☑ ⓘ   │   ☑    │
│ editor       │  ☑   │   ☑    │   ☑    │   ☑    │   ☑    │
└──────────────┴──────┴────────┴────────┴────────┴────────┘
   ⓘ acotado a contenido propio por las capabilities de WordPress

▸ Permisos por campo (28 campos)                    [desplegar]
```

Dos requisitos de la interfaz que evitan configuraciones incoherentes:

1. **Marcar en gris lo que las capabilities ya impiden.** Si `subscriber` no tiene `edit_posts`, la casilla `create` se muestra desactivada con la explicación. Permitir marcarla produciría una configuración que la puerta 2 rechazaría siempre — el administrador creería haber concedido algo que no funciona.
2. **Vista previa de efecto.** Un selector «ver como rol X» muestra la respuesta que ese rol obtendría para un elemento de ejemplo, con los campos que vería. Es la forma más directa de verificar una matriz de cuatro ejes.

---

## 5. Arquitectura de la interfaz

### 5.1 Dos mecanismos, un criterio

| Mecanismo | Se usa en | Por qué |
| --------- | --------- | ------- |
| **Settings API** | Autenticación, Herramientas, opciones globales | Formularios que se envían enteros. Sanitización, nonces y `admin_notices` gratis |
| **REST interno + JS** | Recursos, Permisos, Estado, Registros | Estado grande que cambia por celdas; recargar la página en cada casilla es inviable |

El criterio: **si la pantalla es un formulario que se guarda de una vez, Settings API. Si es una tabla con estado que se edita celda a celda, REST interno.**

No se mezclan en la misma pantalla. Una pantalla híbrida —parte Settings API, parte AJAX— tiene dos caminos de guardado, dos de validación y dos de manejo de errores, con la incoherencia garantizada a medio plazo.

### 5.2 Settings API

```php
register_setting( 'codeia_auth', 'codeia_settings', [
    'type'              => 'object',
    'sanitize_callback' => [ $this->sanitizer, 'sanitize_settings' ],
    'show_in_rest'      => false,          // nunca expuesta en REST público
    'default'           => Config::defaults(),
] );
```

`show_in_rest => false` es deliberado: la configuración contiene la matriz de permisos y los ajustes de autenticación. Exponerla en `/wp/v2/settings`, aunque fuera solo a administradores, amplía la superficie sin necesidad.

**El `sanitize_callback` es la única puerta de escritura.** Con toda la configuración en una opción, cada guardado parcial recibe un fragmento y debe fusionarlo con lo existente sin perder el resto. El sanitizador:

1. Fusiona el fragmento entrante con la configuración actual.
2. Valida cada rama contra su esquema (recursos existen, roles existen, tipos válidos).
3. Descarta claves desconocidas — nunca las conserva «por si acaso».
4. Incrementa `config.version` para invalidar las cachés derivadas.
5. Registra qué cambió, quién y cuándo.

El paso 3 importa: conservar claves no reconocidas convierte la opción en un vertedero y abre la puerta a inyectar datos que un futuro `isset()` interprete.

### 5.3 REST interno

Rutas bajo el mismo namespace, con prefijo `admin/`:

```
GET    /codeia/v1/admin/resources
PATCH  /codeia/v1/admin/resources/{post_type}
GET    /codeia/v1/admin/permissions/{post_type}
PATCH  /codeia/v1/admin/permissions/{post_type}
POST   /codeia/v1/admin/schema/rebuild
GET    /codeia/v1/admin/status
GET    /codeia/v1/admin/logs
```

Todas con:

```php
'permission_callback' => fn() => current_user_can( 'manage_options' ),
```

**Nunca `'__return_true'`**, ni siquiera provisionalmente durante el desarrollo. Es el fallo de seguridad más repetido en plugins de WordPress y el que más se cuela en producción, porque en pruebas «funciona igual».

Estas rutas **no aparecen en el documento OpenAPI**: están marcadas como internas y el generador las omite. Documentar la superficie de administración de la API no aporta nada a quien la integra y sí a quien la ataca.

### 5.4 Nonces

Las rutas `admin/` se consumen por cookie desde el navegador del administrador, así que sí requieren nonce — a diferencia de las rutas públicas autenticadas por token ([01 §2.2](01-autenticacion.md)).

```php
wp_add_inline_script( 'codeia-admin', sprintf(
    'window.codeiaAdmin = %s;',
    wp_json_encode( [
        'root'  => esc_url_raw( rest_url( 'codeia/v1/admin/' ) ),
        'nonce' => wp_create_nonce( 'wp_rest' ),
    ] )
), 'before' );
```

El cliente lo envía en `X-WP-Nonce` y `rest_cookie_check_errors` lo verifica. Los formularios de Settings API usan su propio nonce a través de `settings_fields()`.

Los nonces caducan (12–24 h). Una pestaña abierta toda la noche fallará al guardar; la interfaz detecta `rest_cookie_invalid_nonce` y **avisa de que hay que recargar**, en lugar de mostrar un error genérico.

---

## 6. Esquema de configuración y migraciones

### 6.1 Estructura

Una única opción `codeia_settings`, con `autoload = no`.

```php
[
  'version'     => 3,
  'namespace'   => 'codeia',
  'api_version' => 'v1',
  'modules'     => [ 'media' => false, 'openapi' => true, 'rewrite' => false ],
  'auth'        => [ 'providers' => [ 'jwt' => [ 'ttl' => 900, ... ] ] ],
  'resources'   => [ 'property' => [ 'enabled' => true, 'fields' => [ ... ] ] ],
  'permissions' => [ 'property' => [ 'editor' => [ 'read' => true, ... ] ] ],
  'limits'      => [ 'per_page_max' => 100, 'meta_clauses_max' => 4 ],
]
```

`autoload = no` es obligatorio: con muchos recursos y campos la opción llega a cientos de kilobytes. Cargada automáticamente, se leería y deserializaría **en cada petición del sitio**, incluidas las del front-end que nunca tocan la API.

Los secretos (clave de firma JWT) viven en **opciones separadas**, no aquí. Así la configuración puede exportarse e importarse sin arrastrar credenciales.

### 6.2 Migraciones

```php
foreach ( $this->migrations as $target => $migration ) {
    if ( $config['version'] < $target ) {
        $config = $migration( $config );      // idempotente
        $config['version'] = $target;
    }
}
```

Se ejecutan en `plugins_loaded`, al detectar una versión inferior a la del código, y **antes** de que ningún módulo lea la configuración.

Reglas: cada migración es idempotente y no destructiva. Renombrar una clave copia el valor nuevo y deja el viejo una versión más, para que una reversión del plugin no pierda la configuración. La limpieza llega en la migración siguiente.

Antes de migrar se guarda una copia en `codeia_settings_backup_{version}`, conservando las **tres últimas**. Una migración fallida en producción sin copia previa es una reconfiguración manual completa.

---

## 7. Registros

Visor con filtros por nivel, canal, fecha y usuario, sobre la tabla `codeia_logs` ([arquitectura.md §7](arquitectura.md)).

Paginación por cursor sobre `(created_at, id)` — con logs, el offset profundo es exactamente el caso que degrada.

Botones de purga (por antigüedad o total) y descarga en CSV del filtro activo. La descarga se genera en streaming, no en memoria: un volcado de 200 000 líneas construido en un array agota el límite de PHP.

**Los logs no contienen credenciales.** El visor lo recuerda en la interfaz para que nadie asuma lo contrario al compartir una exportación en soporte.

---

## 8. Herramientas

| Acción | Efecto | Confirmación |
| ------ | ------ | ------------ |
| Reconstruir esquema | Rebuild completo con muestreo de BD | Aviso de coste en instalaciones grandes |
| Regenerar reglas | `flush_rewrite_rules()` | No |
| Exportar configuración | Descarga JSON, sin secretos | No |
| Importar configuración | Reemplaza la configuración | **Sí**, con vista previa del diff |
| Restaurar copia | Vuelve a una copia previa de migración | **Sí** |
| Restablecer todo | Configuración por defecto | **Sí**, escribiendo el nombre del sitio |
| Purgar cachés | Vacía los tres grupos | No |

Las destructivas usan `check_admin_referer()` con una acción específica, no un nonce genérico. El restablecimiento total exige teclear el nombre del sitio: es irreversible y no debe poder ejecutarse por un clic accidental.

El **rebuild se ejecuta por lotes vía REST** con barra de progreso, no en una sola petición. En una instalación con decenas de miles de posts el muestreo supera el tiempo máximo de ejecución de PHP, y un timeout a mitad deja el esquema en estado indeterminado.

---

## 9. Lista de verificación

- [ ] `manage_options` en cada pantalla y cada ruta `admin/`
- [ ] Ningún `permission_callback` con `'__return_true'`
- [ ] Nonce `wp_rest` en las llamadas por cookie; aviso claro si caduca
- [ ] `check_admin_referer()` en toda acción destructiva
- [ ] `codeia_settings` con `autoload = no`
- [ ] Secretos en opciones aparte, fuera de la exportación
- [ ] `sanitize_callback` como única puerta de escritura; claves desconocidas descartadas
- [ ] Migraciones idempotentes, con copia previa
- [ ] Rutas `admin/` excluidas del documento OpenAPI
- [ ] Rebuild por lotes con progreso
- [ ] Casillas desactivadas cuando las capabilities ya lo impiden
- [ ] Toda salida escapada (`esc_html`, `esc_attr`, `wp_json_encode`)

---

## Documentos relacionados

- [03-deteccion-cpt-campos.md](03-deteccion-cpt-campos.md) — catálogo que alimenta la pantalla de Recursos
- [04-permisos.md](04-permisos.md) — modelo que edita la matriz
- [01-autenticacion.md](01-autenticacion.md) — proveedores configurables
- [06-swagger-openapi.md](06-swagger-openapi.md) — Swagger UI embebida
- [09-seguridad.md](09-seguridad.md) — nonces, escapado y superficie del admin
- [11-escalabilidad.md](11-escalabilidad.md) — formato de exportación e importación
