# 05 — Sistema de subida de imágenes

Define el endpoint de subida de medios: validación real del contenido, límites por rol, asociación automática a un post y protección frente a abuso.

> Módulo **desactivado por defecto**. La subida es la superficie de ataque más peligrosa de una API: escribe ficheros en el servidor. Se activa explícitamente.

---

## 1. Endpoint propio o controlador del núcleo

WordPress ya expone `POST /wp/v2/media` mediante `WP_REST_Attachments_Controller`. La primera pregunta es si basta con eso.

| Necesidad | ¿Lo cubre el núcleo? |
| --------- | -------------------- |
| Validación MIME robusta | ✅ Sí, `wp_check_filetype_and_ext()` |
| Creación del adjunto y sus tamaños | ✅ Sí |
| Límite de tamaño **por rol** | ❌ Solo el límite global de PHP/servidor |
| Cuota por usuario y ventana temporal | ❌ |
| Deduplicación por hash | ❌ |
| Asociación validada a un post destino | ⚠️ Acepta `post` sin comprobar permiso de edición sobre él |
| Integración con la matriz de permisos del plugin | ❌ |
| Eliminación de metadatos EXIF | ❌ |

**Decisión: endpoint propio que delega en las funciones del núcleo.** No se subclasifica `WP_REST_Attachments_Controller` —su modelo de permisos está fijado a las capabilities de `attachment` y no admite la matriz de este plugin— pero tampoco se reimplementa el manejo de ficheros: `wp_handle_upload()`, `wp_check_filetype_and_ext()`, `wp_insert_attachment()` y `wp_generate_attachment_metadata()` son código auditado del núcleo y se usan tal cual.

Lo propio es la **capa de política** alrededor: quién, cuánto, con qué frecuencia y hacia qué post.

---

## 2. Contrato

```
POST /wp-json/codeia/v1/media
Content-Type: multipart/form-data
Authorization: Bearer <token>

file          (requerido)  el binario
post_id       (opcional)   post al que asociar
set_featured  (opcional)   marcar como imagen destacada
alt_text      (opcional)   texto alternativo
```

```json
201 Created
{
  "id": 512,
  "url": "https://ejemplo.com/wp-content/uploads/2026/09/piso-malasana.jpg",
  "mime_type": "image/jpeg",
  "width": 2048, "height": 1365, "filesize": 842113,
  "sizes": { "thumbnail": {...}, "medium": {...}, "large": {...} },
  "post_id": 42,
  "deduplicated": false
}
```

Solo `multipart/form-data`. No se acepta base64 en JSON: multiplica por 1,33 el tamaño en tránsito y obliga a mantener el fichero entero en memoria de PHP, en lugar de dejarlo en disco temporal como hace el manejo nativo de subidas.

---

## 3. Cadena de validación

El orden va **de lo barato a lo caro**. Rechazar por tamaño antes de leer el contenido evita gastar E/S en algo que se va a descartar.

```
 1. ¿Módulo activo?                          no ─▶ 404 rest_no_route
 2. ¿Identidad autenticada?                  no ─▶ 401
 3. ¿Permiso 'upload' en la matriz?          no ─▶ 403 codeia_forbidden
 4. ¿current_user_can('upload_files')?       no ─▶ 403
 5. ¿Cuota disponible (nº y bytes)?          no ─▶ 429 codeia_quota_exceeded
 6. ¿Error de subida de PHP?                 sí ─▶ 400 codeia_upload_error
 7. ¿Tamaño ≤ límite del rol?                no ─▶ 413 codeia_file_too_large
 8. ¿Extensión en lista blanca?              no ─▶ 415 codeia_mime_not_allowed
 9. ¿MIME real (finfo) coincide?             no ─▶ 415 codeia_mime_mismatch
10. ¿Es imagen válida (getimagesize)?        no ─▶ 415 codeia_invalid_image
11. ¿Dimensiones dentro de límites?          no ─▶ 413 codeia_dimensions_exceeded
12. ¿Hash ya existe? ──────── sí ─▶ devolver el adjunto existente (200)
13. wp_handle_upload()
14. wp_insert_attachment() + metadatos
15. ¿post_id? → ¿can edit_post(post_id)? no ─▶ 403 (el fichero ya subido se BORRA)
16. Asociar · imagen destacada · registrar cuota
```

El paso 15 merece atención: si la asociación falla por permisos, el fichero **ya está escrito en disco**. Debe eliminarse (`wp_delete_attachment( $id, true )`) antes de responder, o la API se convierte en un almacenamiento anónimo de ficheros huérfanos. Alternativa preferible cuando es posible: validar `post_id` **antes** del paso 13, que es lo que hace la implementación — el orden mostrado refleja el peor caso en que el destino se resuelve tarde.

---

## 4. Validación MIME

### 4.1 Nunca confiar en el cliente

Tres datos vienen del cliente y **ninguno es fiable**:

| Dato | Por qué no vale |
| ---- | --------------- |
| `$_FILES['file']['type']` | Lo envía el navegador. Trivial de falsificar |
| Extensión del nombre | `payload.php.jpg`, `imagen.jpg.php` |
| Cabecera `Content-Type` | Ídem |

La única fuente fiable es **el contenido del fichero en disco**:

```php
$finfo     = new finfo( FILEINFO_MIME_TYPE );
$real_mime = $finfo->file( $file['tmp_name'] );          // del contenido

$checked = wp_check_filetype_and_ext(
    $file['tmp_name'], $file['name'], $allowed_mimes
);

if ( ! $checked['type'] || $checked['type'] !== $real_mime ) {
    return new WP_Error( 'codeia_mime_mismatch', ..., [ 'status' => 415 ] );
}
```

`wp_check_filetype_and_ext()` es la función del núcleo pensada exactamente para esto: corrige la extensión según el contenido real y devuelve `false` cuando no hay correspondencia. La comprobación adicional contra `finfo` cierra el caso en que la lista de MIME permitidos aceptaría el tipo declarado.

### 4.2 Lista blanca

Solo formatos rasterizados de imagen. Por defecto:

| Extensión | MIME |
| --------- | ---- |
| `jpg`, `jpeg` | `image/jpeg` |
| `png` | `image/png` |
| `gif` | `image/gif` |
| `webp` | `image/webp` |
| `avif` | `image/avif` |

**SVG queda fuera y no se ofrece como opción en la interfaz.** Un SVG es XML que admite `<script>`, `<foreignObject>` y referencias externas: subirlo y servirlo desde el dominio del sitio es XSS almacenado con acceso completo a la sesión del administrador. Sanearlo requiere un saneador XML mantenido, fuera del alcance de este plugin. Quien lo necesite, que use un plugin especializado y asuma la decisión.

La lista es ampliable desde el dashboard, con aviso explícito para todo lo que no sea imagen. `get_allowed_mime_types()` sigue actuando como límite superior: este plugin nunca amplía lo que la instalación permite.

### 4.3 Imágenes políglotas

Un fichero puede ser JPEG válido **y** contener PHP en sus metadatos. `getimagesize()` lo acepta; si el servidor ejecutase PHP en `/uploads`, sería ejecución remota de código.

Tres defensas, en capas:

1. **Reprocesar la imagen.** `wp_generate_attachment_metadata()` genera los tamaños derivados con GD o Imagick, y esos derivados no conservan datos arbitrarios. Cuando el rol no es de confianza, el módulo puede reemplazar el original por su versión reprocesada **(propuesto, opt-in)** — se pierde calidad, se gana seguridad.
2. **Nombre de fichero saneado.** `sanitize_file_name()` + `wp_unique_filename()`, aplicados por `wp_handle_upload()`. Eliminan la doble extensión y evitan sobrescrituras.
3. **El directorio de subidas no debe ejecutar PHP.** Es configuración de servidor, no algo que el plugin pueda garantizar. El **panel de estado comprueba y avisa** si `/uploads` responde a una petición de `.php`.

### 4.4 Metadatos EXIF

Las fotografías de móvil incluyen coordenadas GPS, modelo de cámara y fecha exacta.

Esto conecta directamente con [04-permisos.md §4.1](04-permisos.md): allí se ocultan `latitude` y `longitude` al público por ser un dato explotable. Publicar la foto del inmueble con su GPS en el EXIF anula esa protección por completo.

Por defecto, el módulo **elimina los metadatos EXIF de geolocalización** conservando orientación y perfil de color (necesarios para que la imagen se vea correctamente). Configurable a: conservar todo, eliminar solo GPS (por defecto) o eliminar todo.

---

## 5. Límites

### 5.1 Por rol

| Rol | Tamaño máx. | Dimensión máx. | Formatos |
| --- | ----------- | -------------- | -------- |
| `author` | 5 MB | 4000 × 4000 | jpg, png, webp |
| `editor` | 15 MB | 8000 × 8000 | + gif, avif |
| `administrator` | Límite del servidor | Sin límite | Todos los permitidos |

El límite efectivo es **el menor** entre el del rol, `upload_max_filesize`, `post_max_size` y `wp_max_upload_size()`. El panel de estado muestra los cuatro valores: configurar 15 MB por rol con `upload_max_filesize = 2M` produce fallos que parecen del plugin y son del servidor.

**El límite de dimensiones importa aparte del de tamaño.** Un PNG de 30 000 × 30 000 píxeles puede pesar poco comprimido y necesitar gigabytes al descomprimirse en memoria para generar los tamaños derivados — una bomba de descompresión que agota la memoria de PHP. Se comprueba con `getimagesize()` **antes** de procesar.

### 5.2 Cuotas

| Cuota | Por defecto | Ventana |
| ----- | ----------- | ------- |
| Ficheros por usuario | 50 | 1 hora |
| Bytes por usuario | 100 MB | 1 hora |
| Ficheros por IP (anónimo) | 0 | — |

**La subida anónima está prohibida y no es configurable desde la interfaz.** Habilitarla convierte el sitio en alojamiento gratuito de ficheros, con el coste de ancho de banda y la responsabilidad legal que eso implica.

Los contadores usan el mismo mecanismo de ventana deslizante que el rate limiting general ([09-seguridad.md](09-seguridad.md)), en object cache. Sin object cache persistente, las cuotas caen a transients y el panel de estado avisa de la degradación.

### 5.3 Deduplicación

Antes de escribir, se calcula `sha256` del temporal y se consulta un índice (`_codeia_file_hash` en la meta del adjunto). Si ya existe **y** el usuario puede leerlo, se devuelve el adjunto existente con `"deduplicated": true` y código `200` en lugar de `201`.

Ahorra espacio y ancho de banda en el caso real de reintentos de cliente por timeout, donde el fichero llega dos o tres veces. La comprobación de permiso de lectura evita que la deduplicación filtre la existencia de un fichero subido por otro usuario.

---

## 6. Asociación a un post

```
post_id presente
   │
   ├─▶ ¿el post existe?                    no ─▶ 404
   ├─▶ ¿es de un recurso expuesto?         no ─▶ 403
   ├─▶ ¿current_user_can('edit_post',$id)? no ─▶ 403
   ▼
wp_update_post( ['ID' => $att, 'post_parent' => $post_id] )
   │
   └─▶ set_featured ─▶ ¿supports 'thumbnail'? ─▶ set_post_thumbnail()
```

**El permiso se comprueba sobre el post destino, no sobre el adjunto.** Adjuntar una imagen a una propiedad es una modificación de esa propiedad; quien no puede editarla no puede alterar su galería ni su imagen destacada. Es el punto que el controlador nativo no cubre: acepta `post` sin verificar `edit_post` sobre él.

Para añadir a un campo de galería (`_property_gallery`), se aplica además la comprobación de escritura de campo de [04 §5.2](04-permisos.md): un rol que no puede escribir `gallery` no puede añadirle imágenes por esta vía.

---

## 7. Errores

| HTTP | `code` | Cuándo |
| ---- | ------ | ------ |
| 400 | `codeia_upload_error` | Error de PHP en la subida (`UPLOAD_ERR_*`) |
| 400 | `codeia_no_file` | Falta el campo `file` |
| 403 | `codeia_forbidden` | Sin permiso `upload` o sin `upload_files` |
| 403 | `codeia_target_forbidden` | Sin permiso de edición sobre `post_id` |
| 413 | `codeia_file_too_large` | Supera el límite del rol o del servidor |
| 413 | `codeia_dimensions_exceeded` | Supera el límite de píxeles |
| 415 | `codeia_mime_not_allowed` | Formato fuera de la lista blanca |
| 415 | `codeia_mime_mismatch` | Contenido real ≠ extensión declarada |
| 415 | `codeia_invalid_image` | No es una imagen procesable |
| 429 | `codeia_quota_exceeded` | Cuota de ficheros o bytes agotada |
| 500 | `codeia_upload_failed` | `wp_handle_upload()` falló |

En `413` se incluye en `data` el límite aplicable y cuál lo impuso (rol o servidor). Es el único caso donde detallar ayuda al cliente sin revelar nada sensible.

---

## 8. Lista de verificación

- [ ] Módulo desactivado por defecto
- [ ] Subida anónima imposible, no configurable
- [ ] MIME validado por contenido (`finfo` + `wp_check_filetype_and_ext`), nunca por extensión ni cabecera
- [ ] SVG fuera de la lista blanca y sin opción en la interfaz
- [ ] Dimensiones comprobadas antes de generar tamaños (bomba de descompresión)
- [ ] Nombre saneado con `sanitize_file_name()` + `wp_unique_filename()`
- [ ] `edit_post` comprobado sobre el **post destino**
- [ ] Fichero eliminado si la asociación falla después de escribirlo
- [ ] EXIF de GPS eliminado por defecto
- [ ] Cuotas por usuario y ventana, en object cache
- [ ] Panel de estado avisa si `/uploads` ejecuta PHP
- [ ] Límite efectivo = mínimo entre rol y configuración del servidor

---

## Documentos relacionados

- [04-permisos.md](04-permisos.md) — permiso `upload` y escritura sobre campos de galería
- [09-seguridad.md](09-seguridad.md) — ventana deslizante compartida con el rate limiting
- [02-endpoints-dinamicos.md](02-endpoints-dinamicos.md) — `_property_gallery` como campo de relación con adjuntos
- [08-dashboard-admin.md](08-dashboard-admin.md) — configuración de límites y panel de estado
