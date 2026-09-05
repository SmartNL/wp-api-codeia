import { __, sprintf } from '@wordpress/i18n';

/**
 * Cómo se muestra cada origen de campo.
 *
 * El origen es la información más importante de la pantalla: un campo
 * declarado con register_meta() es fiable, uno muestreado de la base de datos
 * es una conjetura, y el administrador debe poder distinguirlos de un vistazo
 * antes de exponerlos en una API pública.
 */
const ORIGINS = {
	manual: { label: __( 'Manual', 'wp-api-codeia' ), tone: '#00a32a' },
	native: { label: 'register_meta', tone: '#00a32a' },
	acf: { label: 'ACF', tone: '#2271b1' },
	metabox: { label: 'Meta Box', tone: '#2271b1' },
	jetengine: { label: 'JetEngine', tone: '#2271b1' },
	db_sample: { label: __( 'Muestreo', 'wp-api-codeia' ), tone: '#dba617' },
};

/**
 * Traduce la confianza a un texto corto.
 *
 * La escala del backend es 0-100: manual 100, nativo 95, proveedores 85 y
 * muestreo 40.
 *
 * @param {number} confidence Valor entre 0 y 100.
 * @return {string} Etiqueta.
 */
export function confidenceLabel( confidence ) {
	if ( typeof confidence !== 'number' ) {
		return '—';
	}

	if ( confidence >= 90 ) {
		return __( 'Alta', 'wp-api-codeia' );
	}

	if ( confidence >= 60 ) {
		return __( 'Media', 'wp-api-codeia' );
	}

	return __( 'Baja', 'wp-api-codeia' );
}

/**
 * Etiqueta de origen con su color.
 *
 * @param {Object} props Propiedades.
 * @return {JSX.Element} Etiqueta.
 */
export function OriginBadge( { origin } ) {
	const meta = ORIGINS[ origin ] || { label: origin || '—', tone: '#646970' };

	return (
		<span
			style={ {
				display: 'inline-block',
				padding: '1px 8px',
				borderRadius: 9,
				fontSize: 11,
				border: `1px solid ${ meta.tone }`,
				color: meta.tone,
			} }
		>
			{ meta.label }
		</span>
	);
}

/**
 * Tabla de campos de un recurso.
 *
 * @param {Object} props Propiedades.
 * @return {JSX.Element} Tabla.
 */
export default function FieldTable( { fields } ) {
	if ( ! fields?.length ) {
		return <p>{ __( 'Este recurso no tiene campos detectados.', 'wp-api-codeia' ) }</p>;
	}

	return (
		<table className="wp-list-table widefat striped">
			<thead>
				<tr>
					<th>{ __( 'Campo expuesto', 'wp-api-codeia' ) }</th>
					<th>{ __( 'Clave almacenada', 'wp-api-codeia' ) }</th>
					<th style={ { width: 100 } }>{ __( 'Tipo', 'wp-api-codeia' ) }</th>
					<th style={ { width: 130 } }>{ __( 'Origen', 'wp-api-codeia' ) }</th>
					<th style={ { width: 90 } }>{ __( 'Confianza', 'wp-api-codeia' ) }</th>
					<th style={ { width: 70 } }>{ __( 'Usos', 'wp-api-codeia' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ fields.map( ( field ) => (
					<tr key={ field.storage_key }>
						<td>
							<strong>{ field.exposed_name }</strong>
							{ field.protected && (
								<span
									title={ __(
										'Meta protegida: WordPress la oculta por defecto.',
										'wp-api-codeia'
									) }
									style={ { marginLeft: 6 } }
								>
									🔒
								</span>
							) }
							{ field.relation && (
								<span
									style={ { marginLeft: 6, fontSize: 11, color: '#2271b1' } }
									title={ __(
										'Relación propuesta. No se activa sola: revísala antes de exponerla.',
										'wp-api-codeia'
									) }
								>
									→ { field.relation }
								</span>
							) }
						</td>
						<td>
							<code>{ field.storage_key }</code>
						</td>
						<td>
							{ field.type }
							{ ! field.single && <span title={ __( 'Múltiple', 'wp-api-codeia' ) }>[]</span> }
							{ field.ambiguous && (
								<span
									style={ { marginLeft: 6, color: '#dba617' } }
									title={ __(
										'Tipo ambiguo: los valores muestreados admiten más de una lectura.',
										'wp-api-codeia'
									) }
								>
									⚠
								</span>
							) }
						</td>
						<td>
							<OriginBadge origin={ field.origin } />
						</td>
						<td
							title={
								typeof field.confidence === 'number'
									? sprintf(
											/* translators: %s: valor numérico de confianza sobre 100. */
											__( 'Confianza: %s/100', 'wp-api-codeia' ),
											String( field.confidence )
									  )
									: undefined
							}
						>
							{ confidenceLabel( field.confidence ) }
						</td>
						<td>{ field.usage_count ?? '—' }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
