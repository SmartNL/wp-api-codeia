/**
 * Copia Swagger UI desde node_modules a assets/vendor/.
 *
 * Swagger UI se sirve desde el propio sitio y nunca desde un CDN: la pantalla
 * de documentación vive detrás del login del administrador, y cargar ahí un
 * script de terceros le daría ejecución en el contexto del panel.
 *
 * El resultado se versiona en el repositorio para que instalar el plugin no
 * exija Node.
 */
import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const from = join( here, '..', 'node_modules', 'swagger-ui-dist' );
const to = join( here, '..', '..', 'assets', 'vendor', 'swagger-ui' );

const version = JSON.parse(
	readFileSync( join( from, 'package.json' ), 'utf8' )
).version;

mkdirSync( to, { recursive: true } );

for ( const file of [ 'swagger-ui-bundle.js', 'swagger-ui.css', 'LICENSE' ] ) {
	copyFileSync( join( from, file ), join( to, file ) );
}

writeFileSync(
	join( to, 'VERSION' ),
	[
		`swagger-ui-dist ${ version }`,
		'Licencia Apache-2.0.',
		'',
		'Copiado por admin-ui/scripts/vendor-swagger.mjs. No editar a mano.',
		'',
		'Se sirve desde el propio sitio y nunca desde un CDN: la pantalla de',
		'documentacion esta detras del login del administrador y cargar un script',
		'de terceros ahi daria a ese tercero ejecucion en el contexto del panel.',
		'',
	].join( '\n' )
);

process.stdout.write( `Swagger UI ${ version } copiado a assets/vendor/swagger-ui/\n` );
