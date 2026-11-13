<?php
/**
 * Channels: sources grouped into Direct, Search, Social and Other sites.
 *
 * @package Mody\Analytics
 */

namespace Mody\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * A fixed list, the same on every site (the maintainer's choice, 2026-11-13,
 * tickets/analytics-chat.md, phase 3a). A source is the host of the site
 * that linked in, without `www.` (Collector::source()); '' is direct.
 *
 * Hosts match by their name or any part of it after a dot, so
 * `l.facebook.com` is Facebook and `google.es` is Google. Mastodon has many
 * instances: the known large ones are listed, and hosts that start with
 * `mastodon.` or `social.` count as Social. A missed instance is Other sites.
 */
final class Channels {

	/** The channels, in the order shown. */
	public const CHANNELS = array( 'direct', 'search', 'social', 'other' );

	/** Search engines: a name, matched as the host's first label or registrable part. */
	private const SEARCH = array( 'google', 'bing', 'duckduckgo', 'ecosia', 'qwant', 'startpage', 'search.brave.com', 'yahoo', 'yandex', 'baidu', 'swisscows', 'mojeek', 'metager', 'ask.com', 'aol', 'seznam', 'naver', 'presearch', 'kagi.com', 'you.com', 'perplexity.ai', 'search.lilo.org', 'lilo.org' );

	/** Social networks and communities. */
	private const SOCIAL = array( 'facebook.com', 'fb.com', 'instagram.com', 'linkedin.com', 'lnkd.in', 'x.com', 't.co', 'twitter.com', 'reddit.com', 'bsky.app', 'youtube.com', 'youtu.be', 'tiktok.com', 'pinterest.com', 'pinterest.es', 'threads.net', 'tumblr.com', 'vk.com', 'telegram.org', 't.me', 'whatsapp.com', 'news.ycombinator.com', 'mastodon.social', 'mastodon.online', 'mstdn.social', 'fosstodon.org', 'mas.to', 'hachyderm.io', 'infosec.exchange', 'pixelfed.social', 'lemmy.world', 'discord.com', 'snapchat.com', 'xing.com' );

	/**
	 * A source's channel.
	 *
	 * @param string $source A host, or '' for direct.
	 */
	public static function of( string $source ): string {
		$host = strtolower( trim( $source ) );
		if ( '' === $host ) {
			return 'direct';
		}
		if ( self::matches( $host, self::SOCIAL ) || preg_match( '/^(?:mastodon|social|masto)\./', $host ) ) {
			return 'social';
		}
		foreach ( self::SEARCH as $name ) {
			if ( str_contains( $name, '.' ) ? self::matches( $host, array( $name ) ) : (bool) preg_match( '/(?:^|\.)' . preg_quote( $name, '/' ) . '\.[a-z.]{2,12}$/', $host ) ) {
				return 'search';
			}
		}
		return 'other';
	}

	/**
	 * Whether a host is one of these, or under one of them.
	 *
	 * @param string             $host  The host.
	 * @param array<int, string> $names Hosts.
	 */
	private static function matches( string $host, array $names ): bool {
		foreach ( $names as $name ) {
			if ( $host === $name || str_ends_with( $host, '.' . $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A channel's name.
	 *
	 * @param string $channel One of CHANNELS.
	 */
	public static function label( string $channel ): string {
		return array(
			'direct' => __( 'Direct', 'mody_cloud' ),
			'search' => __( 'Search engines', 'mody_cloud' ),
			'social' => __( 'Social media', 'mody_cloud' ),
			'other'  => __( 'Other sites', 'mody_cloud' ),
		)[ $channel ] ?? $channel;
	}
}
