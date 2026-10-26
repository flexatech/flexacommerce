<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Transport;

use Flexa\Commerce\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Users and attachments of the site a file came from (doc 05 §4.6). A user
 * is matched by email and created when a customer or an order needs one; an
 * image is downloaded from the source site's URL into the media library.
 * Both are kept in the id map as `user` and `attachment`.
 */
final class Relocation {
	public const USER       = 'user';
	public const ATTACHMENT = 'attachment';

	/** @var array<int,true> attachments that failed to download in this request */
	private array $failed = [];

	public function __construct(
		private readonly FileIndex $index,
		private readonly bool $download,
	) {}

	public function user( int $source_id, Mapping $map, bool $create ): int {
		$known = $map->target_id( self::USER, $source_id );
		if ( $known > 0 && get_userdata( $known ) !== false ) {
			return $known;
		}
		$line  = $this->index->line( FileIndex::USER, $source_id );
		$data  = is_array( $line['data'] ?? null ) ? $line['data'] : [];
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		if ( $email === '' ) {
			return 0;
		}
		$user    = get_user_by( 'email', $email );
		$user_id = $user instanceof \WP_User ? $user->ID : 0;
		if ( $user_id > 0 && is_multisite() && ! is_user_member_of_blog( $user_id ) ) {
			add_user_to_blog( get_current_blog_id(), $user_id, self::role() );
		}
		if ( $user_id === 0 ) {
			if ( ! $create ) {
				return 0;
			}
			$user_id = $this->create_user( $email, $data );
			if ( $user_id === 0 ) {
				return 0;
			}
		}
		$map->remember( self::USER, $source_id, $user_id );
		return $user_id;
	}

	public function attachment( int $source_id, Mapping $map ): int {
		$known = $map->target_id( self::ATTACHMENT, $source_id );
		if ( $known > 0 && get_post_type( $known ) === 'attachment' ) {
			return $known;
		}
		if ( ! $this->download || isset( $this->failed[ $source_id ] ) ) {
			return 0;
		}
		$line = $this->index->line( FileIndex::MEDIA, $source_id );
		$data = is_array( $line['data'] ?? null ) ? $line['data'] : [];
		$url  = esc_url_raw( (string) ( $data['url'] ?? '' ) );
		if ( $url === '' ) {
			return 0;
		}
		$id = $this->sideload( $url, $data );
		if ( $id === 0 ) {
			$this->failed[ $source_id ] = true;
			return 0;
		}
		$map->remember( self::ATTACHMENT, $source_id, $id );
		return $id;
	}

	/** @param array<array-key,mixed> $data */
	private function create_user( string $email, array $data ): int {
		$login = sanitize_user( (string) ( $data['login'] ?? '' ), true );
		$login = $login !== '' ? $login : sanitize_user( strstr( $email, '@', true ) ?: 'customer', true );
		$base  = $login !== '' ? $login : 'customer';
		$login = $base;
		$n     = 2;
		while ( username_exists( $login ) !== false ) {
			$login = $base . '-' . $n;
			++$n;
		}
		$id = wp_insert_user(
			[
				'user_login'      => $login,
				'user_email'      => $email,
				'user_pass'       => wp_generate_password( 24 ),
				'display_name'    => sanitize_text_field( (string) ( $data['display_name'] ?? '' ) ),
				'first_name'      => sanitize_text_field( (string) ( $data['first_name'] ?? '' ) ),
				'last_name'       => sanitize_text_field( (string) ( $data['last_name'] ?? '' ) ),
				'user_registered' => is_string( $data['registered'] ?? null ) ? $data['registered'] : '',
				'role'            => self::role(),
			]
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	private static function role(): string {
		return get_role( 'customer' ) !== null ? 'customer' : (string) get_option( 'default_role', 'subscriber' );
	}

	/** @param array<array-key,mixed> $data */
	private function sideload( string $url, array $data ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = download_url( $url, 30 );
		if ( is_wp_error( $file ) ) {
			return 0;
		}
		$name = sanitize_file_name( (string) ( $data['filename'] ?? '' ) );
		$name = $name !== '' ? $name : wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$id   = media_handle_sideload(
			[
				'name'     => $name,
				'tmp_name' => $file,
			],
			0,
			sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			[
				'post_excerpt' => wp_kses_post( (string) ( $data['caption'] ?? '' ) ),
				'post_content' => wp_kses_post( (string) ( $data['description'] ?? '' ) ),
			]
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $file );
			return 0;
		}
		$alt = sanitize_text_field( (string) ( $data['alt'] ?? '' ) );
		if ( $alt !== '' ) {
			update_post_meta( (int) $id, '_wp_attachment_image_alt', $alt );
		}
		return (int) $id;
	}
}
