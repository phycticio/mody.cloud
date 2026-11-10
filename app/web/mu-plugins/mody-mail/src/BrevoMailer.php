<?php
/**
 * The PHPMailer that sends through Brevo's transactional API.
 *
 * @package Mody\Mail
 */

namespace Mody\Mail;

use PHPMailer\PHPMailer\Exception;

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';

/**
 * wp_mail() fills it in like WordPress's own PHPMailer. send() turns the
 * message into one request to Brevo's API (POST /v3/smtp/email) instead of
 * calling PHP's mail(). Brevo is the one host it calls.
 *
 * - Recipients: To, Cc and Bcc as given; Reply-To, the first one (Brevo takes
 *   one).
 * - Body: an HTML message is htmlContent (with its plain-text part, if any);
 *   any other is textContent.
 * - Attachments go as base64 content. Brevo has no inline (cid:) images: an
 *   embedded image arrives as an ordinary attachment.
 * - Custom headers go as Brevo's headers.
 *
 * A failure (no key, Brevo unreachable, Brevo refusing the message) throws
 * PHPMailer's exception, so wp_mail() returns false and fires wp_mail_failed.
 * The key is never part of an error message.
 */
class BrevoMailer extends \WP_PHPMailer {

	/** Brevo's endpoint for transactional email. */
	public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

	/** Seconds to wait for Brevo. */
	public const TIMEOUT = 15;

	/**
	 * Brevo's id for the last message sent, '' before one is sent.
	 *
	 * @var string
	 */
	public $brevo_message_id = '';

	/**
	 * Sets it up as wp_mail() sets up its own mailer.
	 *
	 * @param bool $exceptions Whether to throw exceptions for errors; wp_mail() expects true.
	 */
	public function __construct( $exceptions = true ) {
		parent::__construct( $exceptions );

		static::$validator = static function ( $email ) {
			return (bool) is_email( $email );
		};
	}

	/**
	 * Sends the message to Brevo's API.
	 *
	 * @return bool True when Brevo accepted it; false (or an exception) when not.
	 * @throws Exception When it fails and exceptions are on (always, from wp_mail()).
	 */
	public function send() {
		$this->brevo_message_id = '';

		try {
			$this->brevo_message_id = $this->post( $this->payload() );
			return true;
		} catch ( Exception $e ) {
			$this->setError( $e->getMessage() );
			if ( $this->exceptions ) {
				throw $e;
			}
			return false;
		}
	}

	/**
	 * The message as the body of Brevo's request.
	 *
	 * @return array<string, mixed>
	 * @throws Exception When there is no recipient or an attachment cannot be read.
	 */
	public function payload(): array {
		$this->dequeue_addresses();

		$payload = array(
			'sender'  => self::contact( $this->From, $this->FromName ),
			'subject' => $this->Subject,
		);

		$lists = array(
			'to'  => $this->getToAddresses(),
			'cc'  => $this->getCcAddresses(),
			'bcc' => $this->getBccAddresses(),
		);
		foreach ( $lists as $field => $addresses ) {
			foreach ( $addresses as $address ) {
				$payload[ $field ][] = self::contact( $address[0], $address[1] ?? '' );
			}
		}
		if ( empty( $payload['to'] ) && empty( $payload['cc'] ) && empty( $payload['bcc'] ) ) {
			throw new Exception( 'The email has no recipient.' );
		}
		if ( empty( $payload['to'] ) ) {
			// Brevo needs a To; with only Cc or Bcc, the sender is the To, as PHPMailer does with "undisclosed-recipients".
			$payload['to'] = array( self::contact( $this->From, $this->FromName ) );
		}

		$reply_to = array_values( $this->getReplyToAddresses() );
		if ( $reply_to ) {
			$payload['replyTo'] = self::contact( $reply_to[0][0], $reply_to[0][1] ?? '' );
		}

		if ( $this->is_html() ) {
			$payload['htmlContent'] = $this->Body;
			if ( '' !== trim( $this->AltBody ) ) {
				$payload['textContent'] = $this->AltBody;
			}
		} else {
			$payload['textContent'] = $this->Body;
		}

		$headers = array();
		foreach ( $this->getCustomHeaders() as $header ) {
			$headers[ $header[0] ] = $header[1];
		}
		if ( $headers ) {
			$payload['headers'] = $headers;
		}

		foreach ( $this->getAttachments() as $attachment ) {
			$content = $attachment[5] ? $attachment[0] : ( is_readable( $attachment[0] ) ? file_get_contents( $attachment[0] ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
			if ( false === $content ) {
				throw new Exception( sprintf( 'Could not read the attachment %s.', $attachment[2] ) );
			}
			$payload['attachment'][] = array(
				'name'    => '' !== (string) $attachment[2] ? (string) $attachment[2] : (string) $attachment[1],
				'content' => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Brevo's attachment format.
			);
		}

		return $payload;
	}

	/**
	 * Posts the message to Brevo.
	 *
	 * @param array<string, mixed> $payload The message (payload()).
	 * @return string Brevo's message id.
	 * @throws Exception When there is no key, Brevo cannot be reached or refuses it.
	 */
	private function post( array $payload ): string {
		$key = Config::brevo_api_key();
		if ( '' === $key ) {
			throw new Exception( 'No mail transport: BREVO_API_KEY is not set.' );
		}

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			throw new Exception( 'The email could not be encoded for Brevo.' );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'api-key'      => $key,
					'accept'       => 'application/json',
					'content-type' => 'application/json',
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Brevo could not be reached: ' . $response->get_error_message() );
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$answer = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$reason = is_array( $answer ) && isset( $answer['message'] ) ? ': ' . $answer['message'] : '';
			throw new Exception( sprintf( 'Brevo refused the email (HTTP %d%s).', $code, $reason ) );
		}

		return is_array( $answer ) && isset( $answer['messageId'] ) ? (string) $answer['messageId'] : '';
	}

	/**
	 * Adds the addresses PHPMailer has set aside until sending: those with an
	 * international domain, punycoded as PHPMailer's own preSend() does.
	 * Without this they would be dropped, since send() never calls preSend().
	 */
	private function dequeue_addresses(): void {
		foreach ( array_merge( $this->RecipientsQueue, $this->ReplyToQueue ) as $params ) {
			$params[1] = $this->punyencodeAddress( $params[1] );
			$this->addAnAddress( ...array_values( $params ) );
		}
		$this->RecipientsQueue = array();
		$this->ReplyToQueue    = array();
	}

	/**
	 * Whether the message is HTML: its media type, whatever its case and
	 * parameters ("text/html; charset=UTF-8" from wp_mail_content_type).
	 */
	private function is_html(): bool {
		return 'text/html' === strtolower( trim( (string) strtok( (string) $this->ContentType, ';' ) ) );
	}

	/**
	 * One address in Brevo's form: the email, and the name when there is one.
	 *
	 * @param string $email The address.
	 * @param string $name  The name, or ''.
	 * @return array<string, string>
	 */
	private static function contact( string $email, string $name ): array {
		$contact = array( 'email' => $email );
		if ( '' !== trim( $name ) ) {
			$contact['name'] = $name;
		}
		return $contact;
	}
}
