<?php
/**
 * In-memory Zoho boundary for standalone smoke tests. Never performs HTTP.
 * Only smoke.php aliases this double; normal plugin and PHPUnit loading do not.
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Tests;

final class ZohoApiDouble {
	public const SERVICE_BOOKS = 'books';
	private static ?self $instance = null;
	public array $responses = array();
	public array $calls = array();

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function post( string $service, string $path, array $body ): array|\WP_Error {
		$this->calls[] = array( 'method' => 'POST', 'service' => $service, 'path' => $path, 'body' => $body );
		if ( empty( $this->responses ) ) {
			throw new \LogicException( 'Unexpected Zoho call: no synthetic response queued.' );
		}
		return array_shift( $this->responses );
	}

	public function delete( string $service, string $path, array $query ): array {
		$this->calls[] = array( 'method' => 'DELETE', 'service' => $service, 'path' => $path, 'query' => $query );
		return array( 'code' => 0 );
	}
}
