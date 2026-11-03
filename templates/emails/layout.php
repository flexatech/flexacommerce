<?php
/**
 * Email layout: the frame every FlexaCommerce email is sent in.
 *
 * Override by copying to yourtheme/flexacommerce/emails/layout.php.
 *
 * @version 1.1.0
 *
 * @var array{email_id:string,heading:string,content:string,site_title:string,site_url:string,style?:array<string,mixed>} $args
 */

defined( 'ABSPATH' ) || exit;

// The store's email style (ADR-085); the defaults are the colors this layout always had.
$fxcm_style  = \Flexa\Commerce\Notifications\Design\MailStyle::clean( $args['style'] ?? [] );
$fxcm_font   = \Flexa\Commerce\Notifications\Design\MailStyle::font_stack( $fxcm_style );
$fxcm_footer = $fxcm_style['footer'] !== '' ? $fxcm_style['footer'] : $args['site_title'];
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $args['heading'] ); ?></title>
</head>
<body style="<?php echo esc_attr( 'margin:0;padding:0;background:' . $fxcm_style['background'] . ';font-family:' . $fxcm_font . ';color:' . $fxcm_style['text'] . ';' ); ?>">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr( $fxcm_style['background'] ); ?>;">
	<tr>
		<td align="center" style="padding:32px 16px;">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:<?php echo esc_attr( $fxcm_style['body'] ); ?>;border:1px solid #e4e4e7;border-radius:8px;">
				<tr>
					<td style="padding:24px 32px;border-bottom:1px solid #e4e4e7;">
						<a href="<?php echo esc_url( $args['site_url'] ); ?>" style="color:#18181b;font-size:16px;font-weight:600;text-decoration:none;"><?php echo esc_html( $args['site_title'] ); ?></a>
					</td>
				</tr>
				<tr>
					<td style="padding:32px 32px 8px;">
						<h1 style="margin:0;font-size:22px;line-height:1.3;font-weight:600;color:<?php echo esc_attr( $fxcm_style['text'] ); ?>;"><?php echo esc_html( $args['heading'] ); ?></h1>
					</td>
				</tr>
				<tr>
					<td style="padding:16px 32px 32px;font-size:15px;line-height:1.6;">
						<?php echo $args['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by the escaped email templates and the content_html filter. ?>
					</td>
				</tr>
			</table>
			<p style="margin:16px 0 0;font-size:12px;color:#71717a;"><?php echo nl2br( esc_html( $fxcm_footer ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped, then line breaks. ?></p>
		</td>
	</tr>
</table>
</body>
</html>
