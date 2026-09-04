<?php
/**
 * Stub minimo de WP_Error para la suite unitaria.
 *
 * Replica la parte del contrato que usa el plugin. No se carga en la suite de
 * integracion, donde WordPress aporta la clase real.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_Error' ) ) {

	/**
	 * Equivalente reducido de WP_Error.
	 */
	class WP_Error {

		/**
		 * Codigos de error acumulados.
		 *
		 * @var array<int, string>
		 */
		private array $codes = array();

		/**
		 * Mensajes por codigo.
		 *
		 * @var array<string, array<int, string>>
		 */
		private array $messages = array();

		/**
		 * Datos por codigo.
		 *
		 * @var array<string, mixed>
		 */
		private array $data = array();

		/**
		 * Construye el error.
		 *
		 * @param string $code    Codigo.
		 * @param string $message Mensaje.
		 * @param mixed  $data    Datos adicionales.
		 */
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}

			$this->codes             = array( $code );
			$this->messages[ $code ] = array( $message );
			$this->data[ $code ]     = $data;
		}

		/**
		 * Primer codigo de error.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->codes[0] ?? '';
		}

		/**
		 * Mensaje asociado a un codigo.
		 *
		 * @param string $code Codigo, o cadena vacia para el primero.
		 * @return string
		 */
		public function get_error_message( string $code = '' ): string {
			$code = '' === $code ? $this->get_error_code() : $code;

			return $this->messages[ $code ][0] ?? '';
		}

		/**
		 * Datos asociados a un codigo.
		 *
		 * @param string $code Codigo, o cadena vacia para el primero.
		 * @return mixed
		 */
		public function get_error_data( string $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;

			return $this->data[ $code ] ?? null;
		}

		/**
		 * Indica si hay algun error registrado.
		 *
		 * @return bool
		 */
		public function has_errors(): bool {
			return array() !== $this->codes;
		}
	}
}
