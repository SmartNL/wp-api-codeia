import '@testing-library/jest-dom/vitest';

/**
 * Datos de arranque que el panel inyecta en window.
 *
 * Sin ellos el cliente de API apunta a la raíz del sitio de pruebas y las
 * peticiones fallan por motivos que no tienen que ver con lo que se prueba.
 */
window.codeiaAdmin = {
	root: 'https://ejemplo.test/wp-json/codeia/v1/admin/',
	nonce: 'nonce-de-prueba',
	specUrl: 'https://ejemplo.test/wp-json/codeia/v1/docs',
	version: '0.9.0',
	namespace: 'codeia',
};

/*
 * jsdom no implementa matchMedia ni ResizeObserver, y @wordpress/components
 * los usa para sus componentes responsivos. Sin estos dobles, cualquier test
 * que renderice un CheckboxControl falla por el entorno y no por el código.
 */
if ( ! window.matchMedia ) {
	window.matchMedia = ( query ) => ( {
		matches: false,
		media: query,
		onchange: null,
		addListener: () => {},
		removeListener: () => {},
		addEventListener: () => {},
		removeEventListener: () => {},
		dispatchEvent: () => false,
	} );
}

if ( ! window.ResizeObserver ) {
	window.ResizeObserver = class {
		observe() {}
		unobserve() {}
		disconnect() {}
	};
}
