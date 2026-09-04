# 01 — Sistema de autenticación configurable

Define cómo la API resuelve la identidad de quien llama, con varios métodos conmutables desde el dashboard y un ciclo de vida de credenciales explícito.

> **Autenticación ≠ autorización.** Este documento solo responde *quién eres*. Qué puedes hacer se decide en [04-permisos.md](04-permisos.md).

---

## 1. Modelo: cadena de proveedores

La autenticación se resuelve mediante una **cadena de responsabilidad**. Cada proveedor implementa la misma interfaz y se le pregunta en orden hasta que uno reclama la petición.

```php
interface Authenticator {
    /** ¿Esta petición trae credenciales de mi tipo? Barato: solo inspecciona cabeceras. */
    public function handles( WP_REST_Request $request ): bool;

    /** Resuelve la identidad. user_id > 0, o WP_Error si la credencial es inválida. */
    public function authenticate( WP_REST_Request $request ): int|WP_Error;

    public function id(): string;       // 'jwt', 'api_key', ...
    public function priority(): int;    // orden en la cadena
}
```

La distinción entre `handles()` y `authenticate()` es la clave del diseño: permite diferenciar **«no traes credenciales mías»** (pasar al siguiente proveedor) de **«traes credenciales mías y son inválidas»** (401 inmediato, sin seguir probando).

Sin esa separación, un token JWT caducado caería al siguiente proveedor y acabaría resolviéndose como petición anónima con un 403 confuso, en lugar del 401 correcto que le dice al cliente que debe renovar.

### 1.1 Orden de la cadena

| Prioridad | Proveedor | Detecta por |
| --------- | --------- | ----------- |
| 10 | JWT | `Authorization: Bearer <token>` con 3 segmentos separados por `.` |
| 20 | Token por usuario | `Authorization: Bearer <token>` opaco (sin puntos) |
| 30 | API Key | Cabecera `X-Codeia-Key` |
| 40 | Application Passwords | `Authorization: Basic <base64>` — delega en el núcleo |
| 50 | OAuth2 **(futuro)** | `Authorization: Bearer` con introspección de token |
| — | Anónimo | Ninguna credencial: `user_id = 0` |

JWT y token opaco comparten el esquema `Bearer`; se distinguen por la forma del valor. Es discriminación frágil si en el futuro se emiten tokens opacos con puntos, así que **los tokens opacos se generan con un alfabeto que excluye el punto** — restricción documentada en el generador, no un accidente.

### 1.2 Flujo

```
Petición
   │
   ├─▶ ¿ruta del namespace codeia? ── no ──▶ no intervenir (dejar a WP)
   │
   ▼
Para cada proveedor activo, por prioridad:
   │
   ├─▶ handles()? ── no ──▶ siguiente proveedor
   │        │
   │       sí
   │        ▼
   │   authenticate()
   │        ├── int > 0    ──▶ IDENTIDAD RESUELTA ──▶ fin
   │        └── WP_Error   ──▶ 401 codeia_auth_invalid ──▶ fin (no sigue)
   │
   ▼
Ningún proveedor reclamó la petición
   └─▶ usuario anónimo (user_id = 0)
       Válido: los permisos decidirán si el recurso admite acceso público
```

---

## 2. Middleware: dónde engancha

Dos hooks, con responsabilidades distintas. Confundirlos es el error más común al implementar autenticación REST en WordPress.

| Hook | Prioridad | Responsabilidad |
| ---- | --------- | --------------- |
| `determine_current_user` | 15 | Resolver la identidad y **establecerla** para todo WordPress |
| `rest_authentication_errors` | 10 | Comunicar el **error** de autenticación al REST server |

### 2.1 Por qué `determine_current_user` y no `rest_pre_dispatch`

`rest_pre_dispatch` se ejecuta **después** de que WordPress haya resuelto el usuario actual. Autenticar ahí implicaría llamar a `wp_set_current_user()` a mano, con el usuario ya fijado a 0. Todo lo que se evaluase antes —incluidos filtros de otros plugins y comprobaciones de capabilities durante el arranque de REST— vería un usuario anónimo. El resultado son incoherencias sutiles: `current_user_can()` devolviendo `false` en un contexto y `true` en el siguiente dentro de la misma petición.

`determine_current_user` es el punto donde el núcleo **pregunta** quién es el usuario. Devolver ahí un ID es la forma canónica de integrar autenticación propia, y es exactamente donde el núcleo engancha las Application Passwords (prioridad 20).

**La prioridad 15 es deliberada:** por encima de la autenticación por cookie (prioridad 10) y por debajo de Application Passwords (20). Así, si la petición trae token propio se resuelve primero, sin interferir con la sesión de cookie del administrador que esté navegando el dashboard en otra pestaña.

### 2.2 Dos precauciones obligatorias

**Recursión.** Llamar a `get_current_user_id()` o `current_user_can()` dentro del callback de `determine_current_user` reentra en el mismo filtro y provoca recursión infinita. El proveedor debe trabajar solo con datos de la petición y consultas directas (`get_user_by`, `$wpdb`). Se protege además con una bandera de reentrada.

```php
add_filter( 'determine_current_user', function ( $user_id ) {
    if ( $this->resolving || ! empty( $user_id ) ) {
        return $user_id;                  // ya resuelto por cookie: respetar
    }
    $this->resolving = true;
    $result = $this->chain->resolve();    // nunca llama a current_user_can()
    $this->resolving = false;

    return is_wp_error( $result ) ? $user_id : ( $result ?: $user_id );
}, 15 );
```

**Nonce de cookie.** Cuando la identidad se resuelve por token, hay que devolver `true` desde `rest_authentication_errors` para cortocircuitar `rest_cookie_check_errors`. Si no, el REST server exigirá un nonce `X-WP-Nonce` que un cliente externo no tiene y responderá `rest_cookie_invalid_nonce`. Devolver `true` es lo que declara «esta petición ya está autenticada por otra vía».

Corolario de seguridad: **`true` solo se devuelve cuando un proveedor ha resuelto positivamente una identidad.** Devolverlo de forma incondicional desactivaría la protección CSRF de las peticiones por cookie en toda la instalación — un fallo grave y frecuente en plugins de este tipo.

---

## 3. Proveedores

### 3.1 JWT

**Formato:** JWS compacto, `HS256` por defecto. Firma simétrica porque emisor y verificador son el mismo sitio; RS256 solo aporta valor si un tercero debe verificar sin poder emitir **(futuro)**.

**Claims:**

| Claim | Contenido | Función |
| ----- | --------- | ------- |
| `iss` | `home_url()` | Emisor; rechaza tokens de otra instalación |
| `sub` | ID de usuario | Identidad |
| `iat` / `exp` | Marcas de tiempo | Vigencia — 15 min por defecto |
| `jti` | Identificador único | Permite revocación individual |
| `tv` | *Token version* del usuario | Revocación masiva (ver §4.3) |

**Secreto de firma.** Por orden de preferencia: constante `CODEIA_JWT_SECRET` en `wp-config.php`; si no existe, un secreto de 256 bits generado en la activación y guardado en una opción propia con `autoload = no`.

Nunca se reutiliza `AUTH_KEY` ni ninguna sal del núcleo: comparten propósito con las cookies de sesión, y un secreto compartido entre dos sistemas de credenciales convierte la fuga de uno en el compromiso de ambos. La opción propia permite además rotar sin invalidar las sesiones del admin.

**Validación**, en este orden — barato antes que caro:

```
formato (3 segmentos, base64url) → cabecera (alg == HS256 esperado)
   → firma (hash_equals, tiempo constante) → exp/iat → iss
   → tv coincide con la del usuario → jti no revocado
```

El orden importa: comprobar la firma **antes** que los claims impide que un atacante use el mensaje de error de un claim para sondear tokens no firmados. Y el algoritmo se compara contra el esperado, nunca se lee del token para decidir cómo verificar — así se cierra el ataque `alg: none` y la confusión HS/RS.

### 3.2 Application Passwords

Nativo del núcleo desde WordPress 5.6 y presente en esta instalación (`wp-includes/class-wp-application-passwords.php`). El plugin **no lo reimplementa**: comprueba disponibilidad con `wp_is_application_passwords_available()` y deja que el núcleo autentique.

Aporta gratis: gestión por usuario en su perfil, revocación individual, registro de último uso y auditoría. El coste es que WordPress lo exige sobre HTTPS salvo en entornos locales, y que la credencial es de larga duración sin expiración automática.

Es la opción recomendada para integraciones servidor a servidor: sin infraestructura propia de tokens y con superficie de ataque ya auditada por el núcleo.

### 3.3 API Key propia

Credencial opaca de 32 bytes en `X-Codeia-Key`, pensada para servicios, no para usuarios finales.

Diferencias frente a JWT que justifican su existencia: no caduca (adecuado para un cron externo), se revoca de inmediato (no hay ventana de validez residual) y puede tener un ámbito propio más estrecho que el rol del usuario.

**Almacenamiento.** Se guarda el **hash**, no la clave. La clave en claro se muestra **una sola vez**, al crearla.

| Aspecto | Decisión |
| ------- | -------- |
| Generación | `random_bytes(32)` → base64url, prefijo `ck_` |
| Almacenamiento | `hash('sha256', $key)` en tabla propia |
| Comparación | `hash_equals()`, tiempo constante |
| Búsqueda | Por hash completo, indexado — no por prefijo |
| Metadatos | Etiqueta, creada, último uso, expiración opcional, ámbito |

**SHA-256 y no `wp_hash_password()`:** una clave de 256 bits de entropía aleatoria no necesita el coste de bcrypt, cuyo propósito es frenar el ataque por diccionario contra contraseñas humanas de baja entropía. Aquí bcrypt solo añadiría latencia a cada petición. El razonamiento **no** aplica a contraseñas elegidas por personas.

### 3.4 Token por usuario

Token opaco de larga duración, emitido desde el perfil del usuario. Es a las Application Passwords lo que la API Key es al JWT: misma mecánica de almacenamiento (hash + `hash_equals`), pero ligado a un usuario y a su rol, sin ámbito propio.

Existe para instalaciones donde las Application Passwords están desactivadas —por política, o por servirse sobre HTTP en entornos internos— y hace falta una credencial estable sin montar JWT.

### 3.5 OAuth2 **(futuro)**

No se implementa en la primera versión. Lo que se deja preparado:

- La interfaz `Authenticator` ya admite un proveedor que valide por introspección remota.
- El modelo de ámbitos (*scopes*) de las API Keys es el mismo que consumirá OAuth2.
- El generador de OpenAPI ya contempla `securitySchemes` de tipo `oauth2`.

Lo que faltaría: almacén de clientes, endpoints de autorización y token, consentimiento y PKCE. Es un subsistema completo, no una extensión menor — de ahí que quede fuera.

### 3.6 Comparativa

| | JWT | App Passwords | API Key | Token usuario | OAuth2 |
| --- | --- | --- | --- | --- | --- |
| **Caso de uso** | App/SPA con sesión | Servidor a servidor | Servicio, cron | Integración simple | Terceros |
| **Dónde vive el secreto** | Cliente (corta vida) | Cliente (larga) | Cliente (larga) | Cliente (larga) | Servidor de autorización |
| **Caduca solo** | Sí (15 min) | No | Opcional | Opcional | Sí |
| **Revocable al instante** | Vía `tv`/`jti` | Sí (núcleo) | Sí | Sí | Sí |
| **Apto para cliente público** | Con refresh | No | No | No | Sí (PKCE) |
| **Estado en servidor** | Solo revocados | Núcleo | Tabla propia | Tabla propia | Completo |
| **Implementación propia** | Alta | Ninguna | Media | Media | Muy alta |
| **Requiere HTTPS** | Sí | Sí (forzado) | Sí | Sí | Sí |

**Recomendación por defecto:** Application Passwords activado (coste cero, respaldado por el núcleo) y JWT para clientes que necesiten sesiones cortas con refresh. API Key solo cuando haga falta un ámbito distinto del rol del usuario.

---

## 4. Ciclo de vida de las credenciales

### 4.1 Emisión

```
POST /wp-json/codeia/v1/auth/token
{ "username": "...", "password": "..." }
   │
   ├─▶ rate limit por IP (5 intentos / 15 min) ── excede ──▶ 429
   ├─▶ wp_authenticate() ── falla ──▶ 401 codeia_auth_failed  (mensaje genérico)
   ├─▶ ¿usuario puede usar la API? ── no ──▶ 403
   ▼
{ "access_token": "...", "token_type": "Bearer",
  "expires_in": 900, "refresh_token": "..." }
```

El mensaje de error de credenciales es **idéntico** para usuario inexistente y contraseña incorrecta. Distinguirlos convierte el endpoint en un oráculo de enumeración de usuarios.

El endpoint de emisión está sujeto a un límite más estricto que el resto de la API, por IP y por nombre de usuario en paralelo — de lo contrario, distribuir el ataque entre muchas IPs contra una sola cuenta lo esquiva.

### 4.2 Refresh con rotación

El *refresh token* es opaco, de larga duración (14 días), almacenado hasheado y **de un solo uso**: cada refresco lo invalida y emite uno nuevo.

```
POST /wp-json/codeia/v1/auth/refresh   { "refresh_token": "..." }
   │
   ├─▶ ¿existe y no ha expirado? ── no ──▶ 401
   ├─▶ ¿ya fue usado? ── SÍ ──▶ ⚠ REUTILIZACIÓN DETECTADA
   │                            └─▶ revocar la familia entera + log
   ▼
Nuevo access_token + nuevo refresh_token (misma familia)
```

**Detección de reutilización.** Los tokens de refresco se agrupan en *familias*: cada rotación conserva el identificador de familia del anterior. Que un refresh token ya consumido vuelva a presentarse solo tiene dos explicaciones: una condición de carrera del cliente, o que un atacante lo haya robado. Como no se pueden distinguir, se asume lo peor y **se revoca la familia completa**, forzando reautenticación.

Sin rotación, un refresh token robado da acceso indefinido y silencioso. Con rotación y detección, el robo se manifiesta en cuanto legítimo y atacante usan el token, y el daño se acota.

### 4.3 Revocación

Tres mecanismos con alcance distinto:

| Mecanismo | Alcance | Coste de comprobación | Cuándo |
| --------- | ------- | --------------------- | ------ |
| **Token version** (`tv`) | Todos los tokens de un usuario | Una lectura de user meta (cacheada) | Cambio de contraseña, cambio de rol, «cerrar todas las sesiones» |
| **Lista de revocación** (`jti`) | Un token concreto | Una lectura de caché | Revocación individual |
| **Rotación de secreto** | Todos los tokens del sitio | Nula | Compromiso del secreto de firma |

El **token version** es el mecanismo principal: un entero en user meta (`_codeia_token_version`) que se incorpora al claim `tv` en la emisión y se compara en cada validación. Incrementarlo invalida todos los tokens del usuario de golpe, con una sola escritura y sin mantener listas.

La **lista de revocación** solo almacena `jti` de tokens *no caducados* — pasado su `exp` la entrada sobra y se purga. Así la lista queda acotada por la vigencia (15 min), no por el volumen histórico. Es la razón práctica de que el access token sea de vida corta: hace que la revocación individual sea barata.

Eventos que incrementan `tv` automáticamente: cambio de contraseña (`after_password_reset`, `profile_update`), cambio de rol, desactivación del usuario y revocación manual desde el dashboard.

---

## 5. Seguridad

### 5.1 Transporte

HTTPS obligatorio para todos los métodos. Sobre HTTP, cualquier credencial de este documento viaja en claro. El plugin comprueba `is_ssl()` y, si no se cumple, **rechaza la emisión de credenciales** (no solo advierte) salvo que se declare explícitamente un entorno de desarrollo con `CODEIA_ALLOW_INSECURE_AUTH`. Los entornos locales tipo Local o LocalWP quedan cubiertos por esa constante, que nunca debe llegar a producción.

### 5.2 Almacenamiento

| Credencial | En servidor | En cliente |
| ---------- | ----------- | ---------- |
| JWT access | No se guarda (sin estado) | Memoria; nunca `localStorage` en navegador |
| Refresh token | Hash SHA-256 + familia | Cookie `HttpOnly`, `Secure`, `SameSite=Strict` |
| API Key | Hash SHA-256 | Almacén de secretos del cliente |
| Token usuario | Hash SHA-256 | Almacén de secretos del cliente |
| App Password | Gestionado por el núcleo | — |

La recomendación de no usar `localStorage` para el access token es la mitigación estándar frente a XSS: cualquier script inyectado en la página puede leerlo. Con el token en memoria y el refresh en cookie `HttpOnly`, un XSS puede actuar durante la vida de la página pero no exfiltrar una credencial persistente.

### 5.3 Errores

Deliberadamente poco informativos hacia fuera, detallados hacia el log.

| Código HTTP | `code` | Cuándo | Qué se registra |
| ----------- | ------ | ------ | --------------- |
| 401 | `codeia_auth_invalid` | Credencial mal formada, caducada o con firma inválida | Motivo real, proveedor, IP |
| 401 | `codeia_auth_failed` | Usuario o contraseña incorrectos | Usuario intentado, IP |
| 403 | `codeia_auth_forbidden` | Identidad válida, sin acceso a la API | Usuario, recurso |
| 429 | `codeia_rate_limited` | Demasiados intentos | Identidad, ventana |

El cliente recibe siempre «credencial inválida», sin distinguir caducidad de firma incorrecta ni de token revocado. La única excepción útil es la cabecera `WWW-Authenticate: Bearer error="invalid_token"`, estándar y no explotable para sondear.

### 5.4 Lista de verificación

- [ ] `alg` comparado contra el valor esperado, nunca leído del token
- [ ] Firma verificada con `hash_equals()` (tiempo constante)
- [ ] Secreto propio, jamás `AUTH_KEY` ni sales del núcleo
- [ ] `iss` validado contra `home_url()`
- [ ] Mensaje idéntico para usuario inexistente y contraseña errónea
- [ ] Rate limit por IP **y** por usuario en el endpoint de emisión
- [ ] Refresh de un solo uso, con detección de reutilización por familia
- [ ] `tv` incrementado en cambio de contraseña y de rol
- [ ] Credenciales almacenadas solo hasheadas
- [ ] `rest_authentication_errors` devuelve `true` **solo** tras autenticar
- [ ] Bandera de reentrada en `determine_current_user`
- [ ] Credenciales nunca en los logs, ni siquiera en `debug`

---

## Documentos relacionados

- [arquitectura.md](arquitectura.md) — cadena de proveedores dentro del flujo global
- [04-permisos.md](04-permisos.md) — qué puede hacer la identidad ya resuelta
- [09-seguridad.md](09-seguridad.md) — rate limiting y matriz de amenazas
- [08-dashboard-admin.md](08-dashboard-admin.md) — pantalla de configuración de proveedores
- [06-swagger-openapi.md](06-swagger-openapi.md) — `securitySchemes` derivados de los proveedores activos
