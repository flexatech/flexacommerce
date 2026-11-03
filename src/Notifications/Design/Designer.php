<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Design;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Notifications\EmailSettings;
use Flexa\Commerce\Notifications\EmailTemplates;
use Flexa\Commerce\Notifications\OrderData;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the HTML an email is sent in (ADR-085). An email with a saved
 * design is laid out from its blocks; any other email goes in the layout
 * template, in the store's colors.
 *
 * The email's own text (the message block) still comes from its template.
 * Each order part in it (lines and totals, addresses, downloads, tracking)
 * is marked by EmailTemplates, so a design that places that part itself
 * takes it out of the message and puts it where the block is, with the
 * same content the email rendered.
 *
 * Everything is tables with inline styles, at most 600 pixels wide, as
 * mail programs expect. A preview adds `data-fxc-block` to each block's
 * row so the designer can tell which block was clicked.
 *
 * @phpstan-import-type Style from MailStyle
 * @phpstan-import-type Design from DesignTree
 * @phpstan-import-type Block from DesignTree
 * @phpstan-type Mail array{email_id:string,to_customer:bool,heading:string,content:string,order:?OrderView}
 */
final class Designer {
	/** What a text block may hold. */
	public const ALLOWED_HTML = [
		'a'      => [
			'href'  => true,
			'title' => true,
		],
		'strong' => [],
		'b'      => [],
		'em'     => [],
		'i'      => [],
		'u'      => [],
		'br'     => [],
	];

	public const TOKENS = [
		'{site_title}',
		'{site_url}',
		'{store_email}',
		'{heading}',
		'{order_number}',
		'{order_date}',
		'{order_total}',
		'{order_url}',
		'{payment_method}',
		'{customer_name}',
		'{customer_first_name}',
		'{customer_email}',
		'{year}',
	];

	private const MUTED = '#71717a';

	private const LINE = '#e4e4e7';

	private const PART_MARK = '#<!--fxc-part:([a-z-]+)-->(.*?)<!--/fxc-part:\1-->#s';

	public function __construct(
		private readonly EmailTemplates $templates,
		private readonly EmailSettings $settings,
		private readonly MailStyle $style,
		private readonly OrderData $data,
	) {}

	public function style(): MailStyle {
		return $this->style;
	}

	/**
	 * The HTML of an email about to be sent.
	 *
	 * @param Mail $mail
	 */
	public function frame( array $mail ): string {
		$design = $this->settings->design( $mail['email_id'] );
		$style  = $this->style->get();
		return $design !== null ? $this->render( $design, $style, $mail, false ) : $this->layout( $style, $mail );
	}

	/**
	 * The email in the layout template, as it was before any design.
	 *
	 * @param Style $style
	 * @param Mail  $mail
	 */
	public function layout( array $style, array $mail ): string {
		$html = $this->templates->render(
			'layout',
			[
				'email_id'   => $mail['email_id'],
				'heading'    => $mail['heading'],
				'content'    => MailStyle::recolor( self::unmark( $mail['content'] ), $style ),
				'site_title' => self::site_title(),
				'site_url'   => home_url( '/' ),
				'style'      => $style,
			]
		);
		return $html;
	}

	/**
	 * @param Design $design
	 * @param Style  $style
	 * @param Mail   $mail
	 */
	public function render( array $design, array $style, array $mail, bool $preview ): string {
		$tokens  = $this->tokens( $mail );
		$placed  = [];
		$message = $mail['content'];
		foreach ( $design['blocks'] as $block ) {
			if ( isset( DesignTree::PARTS[ $block['type'] ] ) ) {
				$placed[ DesignTree::PARTS[ $block['type'] ] ] = '';
			}
		}
		// Take the parts the design places out of the message, keeping what the email rendered.
		$message = (string) preg_replace_callback(
			self::PART_MARK,
			static function ( array $m ) use ( &$placed ): string {
				if ( ! array_key_exists( $m[1], $placed ) ) {
					return $m[2];
				}
				$placed[ $m[1] ] = $m[2];
				return '';
			},
			$message
		);
		$message = MailStyle::recolor( $message, $style );

		$rows = '';
		foreach ( $design['blocks'] as $block ) {
			$part  = DesignTree::PARTS[ $block['type'] ] ?? null;
			$inner = match ( $block['type'] ) {
				'message' => $message,
				default   => $part !== null ? $this->part( $part, $placed[ $part ] ?? '', $mail ) : '',
			};
			$rows .= $this->row( $block, $inner, $style, $tokens, $preview );
		}

		$font = MailStyle::font_stack( $style );
		$card = 'max-width:600px;background:' . $style['body'] . ';border:1px solid ' . self::LINE . ';border-radius:8px;';
		$edge = '<tr><td style="height:20px;font-size:0;line-height:0;">&nbsp;</td></tr>' . "\n";
		return '<!DOCTYPE html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $mail['heading'] ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:' . $style['background'] . ';font-family:' . esc_attr( $font ) . ';color:' . $style['text'] . ';">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:' . $style['background'] . ';"><tr><td align="center" style="padding:32px 16px;">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="' . esc_attr( $card ) . '">'
			. $edge . $rows . $edge
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * What each token stands for in this email, as plain text.
	 *
	 * @param Mail $mail
	 * @return array<string,string>
	 */
	public function tokens( array $mail ): array {
		$order   = $mail['order'];
		$created = $order !== null ? strtotime( $order->created_at . ' UTC' ) : false;
		$name    = $order !== null ? ( $order->customer_name !== '' ? $order->customer_name : (string) $order->billing?->name() ) : '';
		$url     = '';
		if ( $order !== null && $order->id > 0 ) {
			$url = $mail['to_customer'] ? $this->data->customer_url( $order ) : $this->data->admin_url( $order );
		}
		return [
			'{site_title}'          => self::site_title(),
			'{site_url}'            => home_url( '/' ),
			'{store_email}'         => (string) get_option( 'admin_email' ),
			'{heading}'             => $mail['heading'],
			'{order_number}'        => $order !== null ? $order->number : '',
			'{order_date}'          => $created !== false ? (string) wp_date( (string) get_option( 'date_format' ), $created ) : '',
			'{order_total}'         => $order !== null ? wp_strip_all_tags( $this->data->money( $order->total ) ) : '',
			'{order_url}'           => $url,
			'{payment_method}'      => $order !== null ? $order->payment_method_title : '',
			'{customer_name}'       => $name,
			'{customer_first_name}' => $order !== null && $order->billing !== null && $order->billing->first_name !== '' ? $order->billing->first_name : trim( (string) strtok( $name . ' ', ' ' ) ),
			'{customer_email}'      => $order !== null ? ( $order->customer_email !== '' ? $order->customer_email : (string) $order->billing?->email ) : '',
			'{year}'                => (string) wp_date( 'Y' ),
		];
	}

	/** Drops the part marks from a message that goes out as it is. */
	public static function unmark( string $html ): string {
		return (string) preg_replace( '#<!--/?fxc-part:[a-z-]+-->#', '', $html );
	}

	/**
	 * One block as a table row; '' when it has nothing to show in a sent email.
	 *
	 * @param Block                $block
	 * @param Style                $style
	 * @param array<string,string> $tokens
	 */
	private function row( array $block, string $inner, array $style, array $tokens, bool $preview ): string {
		$p       = $block['props'];
		$align   = is_string( $p['align'] ?? null ) ? $p['align'] : 'left';
		$padding = '8px 32px';
		switch ( $block['type'] ) {
			case 'logo':
				$html = $this->logo( $style, (int) ( $p['width'] ?? 0 ) );
				break;
			case 'heading':
				$size = [
					'small'  => 18,
					'medium' => 22,
					'large'  => 28,
				][ (string) $p['size'] ] ?? 22;
				$text = self::fill( (string) $p['text'], $tokens );
				$html = $text === '' && ! $preview ? '' : '<h1 style="margin:0;font-size:' . $size . 'px;line-height:1.3;font-weight:600;color:' . $style['text'] . ';">' . $text . '</h1>';
				break;
			case 'text':
				$text = self::fill( (string) $p['text'], $tokens );
				$html = trim( $text ) === '' ? ( $preview ? self::hint( __( 'Write something in the panel on the right.', 'flexacommerce' ) ) : '' ) : '<div style="font-size:15px;line-height:1.6;">' . str_replace( '<p>', '<p style="margin:0 0 12px;">', wpautop( $text ) ) . '</div>';
				break;
			case 'button':
				$html = $this->button( $p, $style, $tokens, $preview );
				break;
			case 'image':
				$html = $this->image( $p, $tokens, $preview );
				break;
			case 'divider':
				$html = '<div style="border-top:1px solid ' . self::LINE . ';font-size:0;line-height:0;">&nbsp;</div>';
				break;
			case 'spacer':
				$html    = '&nbsp;';
				$padding = '0 32px';
				$height  = (int) $p['height'];
				break;
			case 'message':
				$html = trim( $inner ) === '' ? ( $preview ? self::hint( __( 'This email has no message of its own.', 'flexacommerce' ) ) : '' ) : '<div style="font-size:15px;line-height:1.6;">' . $inner . '</div>';
				break;
			case 'footer':
				$text    = (string) $p['text'] !== '' ? (string) $p['text'] : ( $style['footer'] !== '' ? esc_html( $style['footer'] ) : esc_html( self::site_title() ) );
				$html    = '<div style="font-size:12px;line-height:1.6;color:' . self::MUTED . ';">' . nl2br( self::fill( $text, $tokens ) ) . '</div>';
				$align   = 'center';
				$padding = '16px 32px 8px';
				break;
			default:
				$html = trim( $inner ) === '' ? ( $preview ? self::hint( self::empty_part( $block['type'] ) ) : '' ) : '<div style="font-size:15px;line-height:1.6;">' . $inner . '</div>';
		}
		if ( $html === '' ) {
			return '';
		}
		$cell = 'padding:' . $padding . ';text-align:' . $align . ';';
		if ( isset( $height ) ) {
			$cell .= 'height:' . $height . 'px;font-size:0;line-height:0;';
		}
		$mark = $preview ? ' data-fxc-block="' . esc_attr( $block['id'] ) . '"' : '';
		// One row per line: mail servers cap line length, and long lines get re-encoded.
		return '<tr' . $mark . '><td style="' . esc_attr( $cell ) . '">' . $html . "</td></tr>\n";
	}

	/** @param Style $style */
	private function logo( array $style, int $width ): string {
		$home = esc_url( home_url( '/' ) );
		if ( $style['logo_url'] === '' ) {
			return '<a href="' . $home . '" style="color:' . $style['text'] . ';font-size:18px;font-weight:600;text-decoration:none;">' . esc_html( self::site_title() ) . '</a>';
		}
		$width = $width > 0 ? $width : $style['logo_width'];
		return '<a href="' . $home . '" style="text-decoration:none;"><img src="' . esc_url( $style['logo_url'] ) . '" alt="' . esc_attr( self::site_title() ) . '" width="' . $width . '" style="display:inline-block;width:' . $width . 'px;max-width:100%;height:auto;border:0;"></a>';
	}

	/**
	 * @param array<string,string|int> $p
	 * @param Style                    $style
	 * @param array<string,string>     $tokens
	 */
	private function button( array $p, array $style, array $tokens, bool $preview ): string {
		$url   = self::link( (string) $p['url'], $tokens );
		$label = (string) $p['label'] !== '' ? self::fill( esc_html( (string) $p['label'] ), $tokens ) : esc_html__( 'View your order', 'flexacommerce' );
		if ( $url === '' && ! $preview ) {
			return '';
		}
		$color = (string) $p['color'] !== '' ? (string) $p['color'] : $style['brand'];
		$css   = 'display:inline-block;padding:12px 22px;background:' . $color . ';color:' . $style['button_text'] . ';border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;';
		return '<a href="' . ( $url !== '' ? esc_url( $url ) : '#' ) . '" style="' . esc_attr( $css ) . '">' . $label . '</a>';
	}

	/**
	 * @param array<string,string|int> $p
	 * @param array<string,string>     $tokens
	 */
	private function image( array $p, array $tokens, bool $preview ): string {
		$src = (string) $p['url'];
		if ( $src === '' ) {
			return $preview ? self::hint( __( 'Choose an image in the panel on the right.', 'flexacommerce' ) ) : '';
		}
		$width = (int) $p['width'];
		$size  = $width > 0 ? ' width="' . $width . '"' : '';
		$css   = 'display:inline-block;max-width:100%;height:auto;border:0;' . ( $width > 0 ? 'width:' . $width . 'px;' : 'width:100%;' );
		$img   = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( (string) $p['alt'] ) . '"' . $size . ' style="' . esc_attr( $css ) . '">';
		$link  = self::link( (string) $p['link'], $tokens );
		return $link !== '' ? '<a href="' . esc_url( $link ) . '" style="text-decoration:none;">' . $img . '</a>' : $img;
	}

	/**
	 * A part the design places: what the message held, or the part rendered for this order.
	 *
	 * @param Mail $mail
	 */
	private function part( string $name, string $taken, array $mail ): string {
		if ( $taken !== '' || $mail['order'] === null ) {
			return $taken;
		}
		return self::unmark(
			$this->templates->render(
				'parts/' . $name,
				[
					'email_id'    => $mail['email_id'],
					'order'       => $mail['order'],
					'data'        => $this->data,
					'to_customer' => $mail['to_customer'],
					'templates'   => $this->templates,
				]
			)
		);
	}

	private static function empty_part( string $type ): string {
		return match ( $type ) {
			'order_details' => __( 'The order lines and totals show here.', 'flexacommerce' ),
			'addresses'     => __( 'The billing and shipping addresses show here.', 'flexacommerce' ),
			'downloads'     => __( 'Download links show here when the order has downloadable products.', 'flexacommerce' ),
			'shipments'     => __( 'Tracking numbers show here once the order ships.', 'flexacommerce' ),
			default         => '',
		};
	}

	/** A note shown only in the designer, where a sent email would show nothing. */
	private static function hint( string $text ): string {
		return '<div style="padding:12px;border:1px dashed #d4d4d8;border-radius:6px;font-size:13px;color:' . self::MUTED . ';text-align:center;">' . esc_html( $text ) . '</div>';
	}

	/**
	 * Fills in the tokens of text that is already safe HTML.
	 *
	 * @param array<string,string> $tokens
	 */
	private static function fill( string $html, array $tokens ): string {
		return strtr( wp_kses( $html, self::ALLOWED_HTML ), array_map( 'esc_html', $tokens ) );
	}

	/** @param array<string,string> $tokens */
	private static function link( string $url, array $tokens ): string {
		return $url === '' ? '' : esc_url_raw( strtr( $url, $tokens ), [ 'http', 'https', 'mailto', 'tel' ] );
	}

	private static function site_title(): string {
		return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}
}
