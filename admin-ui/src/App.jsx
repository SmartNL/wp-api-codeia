import { __ } from '@wordpress/i18n';
import StatusScreen from './screens/StatusScreen.jsx';
import ResourcesScreen from './screens/ResourcesScreen.jsx';
import PermissionsScreen from './screens/PermissionsScreen.jsx';
import AuthScreen from './screens/AuthScreen.jsx';
import DocsScreen from './screens/DocsScreen.jsx';
import LogsScreen from './screens/LogsScreen.jsx';
import ToolsScreen from './screens/ToolsScreen.jsx';

/**
 * Pantalla que corresponde a cada slug del menú.
 *
 * El enrutado va por el slug que imprime PHP, no por la URL: la interfaz se
 * monta una vez por pantalla dentro del admin de WordPress, que ya ha hecho
 * el enrutado del lado del servidor. Un router de cliente duplicaría ese
 * trabajo y rompería el resaltado del menú.
 */
const SCREENS = {
	'codeia-api': StatusScreen,
	'codeia-api-resources': ResourcesScreen,
	'codeia-api-permissions': PermissionsScreen,
	'codeia-api-auth': AuthScreen,
	'codeia-api-docs': DocsScreen,
	'codeia-api-logs': LogsScreen,
	'codeia-api-tools': ToolsScreen,
};

/**
 * Raíz del dashboard.
 *
 * @param {Object} props Propiedades.
 * @return {JSX.Element} Interfaz.
 */
export default function App( { page } ) {
	const Screen = SCREENS[ page ];

	if ( ! Screen ) {
		return (
			<div className="codeia-admin">
				<p>{ __( 'Pantalla desconocida.', 'wp-api-codeia' ) }</p>
			</div>
		);
	}

	return (
		<div className="codeia-admin">
			<Screen />
		</div>
	);
}
