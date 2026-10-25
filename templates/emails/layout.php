<?php
/**
 * Email layout: the frame every FlexaCommerce email is sent in.
 *
 * Override by copying to yourtheme/flexacommerce/emails/layout.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,heading:string,content:string,site_title:string,site_url:string} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $args['heading'] ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f5;">
	<tr>
		<td align="center" style="padding:32px 16px;">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #e4e4e7;border-radius:8px;">
				<tr>
					<td style="padding:24px 32px;border-bottom:1px solid #e4e4e7;">
						<a href="<?php echo esc_url( $args['site_url'] ); ?>" style="color:#18181b;font-size:16px;font-weight:600;text-decoration:none;"><?php echo esc_html( $args['site_title'] ); ?></a>
					</td>
				</tr>
				<tr>
					<td style="padding:32px 32px 8px;">
						<h1 style="margin:0;font-size:22px;line-height:1.3;font-weight:600;color:#18181b;"><?php echo esc_html( $args['heading'] ); ?></h1>
					</td>
				</tr>
				<tr>
					<td style="padding:16px 32px 32px;font-size:15px;line-height:1.6;">
						<?php echo $args['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by the escaped email templates and the content_html filter. ?>
					</td>
				</tr>
			</table>
			<p style="margin:16px 0 0;font-size:12px;color:#71717a;"><?php echo esc_html( $args['site_title'] ); ?></p>
		</td>
	</tr>
</table>
</body>
</html>
