<?php
/**
 * CSS extension - A parser-function for adding CSS to articles via file,
 * article or inline rules.
 *
 * See https://www.mediawiki.org/wiki/Extension:CSS for installation and usage
 * details.
 *
 * @file
 * @ingroup Extensions
 * @author Aran Dunkley [http://www.organicdesign.co.nz/nad User:Nad]
 * @author Rusty Burchfield
 * @copyright © 2007-2010 Aran Dunkley
 * @copyright © 2011 Rusty Burchfield
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\CSS;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CSS\Hooks\HookRunner;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\RawPageViewBeforeOutputHook;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Utils\UrlUtils;
use RawAction;
use Wikimedia\CSS\Parser\Parser as CSSParser;
use Wikimedia\CSS\Sanitizer\FontFaceAtRuleSanitizer;
use Wikimedia\CSS\Sanitizer\KeyframesAtRuleSanitizer;
use Wikimedia\CSS\Sanitizer\MediaAtRuleSanitizer;
use Wikimedia\CSS\Sanitizer\NamespaceAtRuleSanitizer;
use Wikimedia\CSS\Sanitizer\PageAtRuleSanitizer;
use Wikimedia\CSS\Sanitizer\StylePropertySanitizer;
use Wikimedia\CSS\Sanitizer\StyleRuleSanitizer;
use Wikimedia\CSS\Sanitizer\StylesheetSanitizer;
use Wikimedia\CSS\Sanitizer\SupportsAtRuleSanitizer;
use Wikimedia\CSS\Util as CSSUtil;

class Hooks implements ParserFirstCallInitHook, RawPageViewBeforeOutputHook {

	private static ?StylesheetSanitizer $sanitizer = null;

	public function __construct(
		private readonly Config $config,
		private readonly HookContainer $hookContainer,
		private readonly TitleFactory $titleFactory,
		private readonly UrlUtils $urlUtils,
	) {
	}

	private function getSanitizer(): StylesheetSanitizer {
		// This function is based on TemplateStyles's Hooks::getSanitizer():
		// https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/TemplateStyles/+/refs/heads/master/includes/Hooks.php
		if ( !self::$sanitizer ) {
			$matcherFactory = new CSSMatcherFactory;

			$propertySanitizer = new StylePropertySanitizer( $matcherFactory );
			$hookRunner = new HookRunner( $this->hookContainer );
			$hookRunner->onCSSPropertySanitizer( $propertySanitizer, $matcherFactory );

			$ruleSanitizers = [
				'style' => new StyleRuleSanitizer( $matcherFactory->cssSelectorList(), $propertySanitizer ),
				'@font-face' => new FontFaceAtRuleSanitizer( $matcherFactory ),
				'@keyframes' => new KeyframesAtRuleSanitizer( $matcherFactory, $propertySanitizer ),
				'@page' => new PageAtRuleSanitizer( $matcherFactory, $propertySanitizer ),
				'@media' => new MediaAtRuleSanitizer( $matcherFactory->cssMediaQueryList() ),
				'@supports' => new SupportsAtRuleSanitizer( $matcherFactory, [
					'declarationSanitizer' => $propertySanitizer,
				] ),

				// Do not include @import due to lack of proper security measures
				'@namespace' => new NamespaceAtRuleSanitizer( $matcherFactory ),
			];

			$ruleSanitizers['@media']->setRuleSanitizers( $ruleSanitizers );
			$ruleSanitizers['@supports']->setRuleSanitizers( $ruleSanitizers );

			self::$sanitizer = new StylesheetSanitizer( $ruleSanitizers );
			$hookRunner->onCSSStylesheetSanitizer( self::$sanitizer, $propertySanitizer, $matcherFactory );
		}
		return self::$sanitizer;
	}

	/**
	 * Maximum nesting depth of CSS functions ('(' opens) accepted by
	 * sanitizeCSS(). The wikimedia/css-sanitizer property-value matcher
	 * is super-exponential in the depth of nested math functions
	 * (calc/min/max/clamp): on this codebase depth 4 sanitises in ~80
	 * ms, depth 5 in ~1 s, depth 6 in ~11 s, depth 7 times out at the
	 * 15 s php max_execution_time. An editor with {{#css:...}} access
	 * can pin a worker per request by submitting a depth-6 payload.
	 *
	 * Real wiki CSS does not approach this depth (selectors like
	 * `:not(...)` are usually depth 1; complex math functions used in
	 * production stay at depth 2-3), so cap the input pre-parse at a
	 * value that leaves real CSS untouched and keeps the worst-case
	 * sanitiser time bounded.
	 */
	private const MAX_PAREN_DEPTH = 5;

	/**
	 * Pre-parse depth bound on '(' nesting. Strings (single or double
	 * quoted, with backslash escapes) are skipped so legitimate CSS that
	 * places parens inside content strings is not falsely rejected.
	 * Comments are not skipped: CSS comments containing five-plus
	 * nested opens are not a real pattern and pre-parse depth is a
	 * heuristic, not a parser.
	 *
	 * @return bool true if the depth limit was exceeded somewhere.
	 */
	private function exceedsMaxParenDepth( string $css ): bool {
		$depth = 0;
		$inStr = '';
		$len = strlen( $css );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $css[$i];
			if ( $inStr !== '' ) {
				if ( $c === '\\' ) {
					$i++;
					continue;
				}
				if ( $c === $inStr ) {
					$inStr = '';
				}
				continue;
			}
			if ( $c === '"' || $c === "'" ) {
				$inStr = $c;
				continue;
			}
			if ( $c === '(' ) {
				if ( ++$depth > self::MAX_PAREN_DEPTH ) {
					return true;
				}
			} elseif ( $c === ')' && $depth > 0 ) {
				$depth--;
			}
		}
		return false;
	}

	/**
	 * Sanitize the provided css
	 */
	private function sanitizeCSS( string $css ): string {
		// Errors are reported vaguely since the previous implementation was also vague, and since doing
		// so could help avoid an amplification DoS (T368594#10146978). This can be revisited though.
		// This also fails silently rather than loudly, supposedly partly for consistency with the former
		// implementation, but actually mainly due to laziness. This can also be revisited.

		if ( $this->exceedsMaxParenDepth( $css ) ) {
			return '/* css-sanitizer rejected: CSS function nesting too deep */';
		}

		$cssParser = CSSParser::newFromString( $css );
		$css = $cssParser->parseStylesheet();
		if ( $cssParser->getParseErrors() ) {
			return '/* css-sanitizer failed to parse CSS */';
		}

		$sanitizer = $this->getSanitizer();
		$sanitizer->clearSanitizationErrors();
		$css = $sanitizer->sanitize( $css );
		if ( !$css || $sanitizer->getSanitizationErrors() ) {
			return '/* css-sanitizer failed to sanitize CSS */';
		}

		// @phan-suppress-next-line PhanTypeMismatchArgument False positive
		$css = CSSUtil::stringify( $css, [ 'minify' => true ] );
		// Sanity check copied from TemplateStyles: Ensure that $css doesn't break out the sanitizer
		if ( preg_match( '!</style!i', $css ) ) {
			return '/* Closing style tag found in resulting CSS */';
		}

		// Sanity check copied from TemplateStyles: Ensure that U+007F doesn't leak out through the sanitizer
		$css = strtr( $css, [ '\x7f' => '�' ] );

		return $css;
	}

	public function cssRender( Parser $parser, string $css ): string {
		$css = trim( $css );
		if ( $css === '' ) {
			return '';
		}
		$title = $this->titleFactory->newFromText( $css );
		$identifier = $this->config->get( 'CSSIdentifier' );
		$rawProtection = [ $identifier => '1' ];
		$headItem = '<!-- Begin Extension:CSS -->';

		if ( $title && $title->exists() ) {
			# Article actually in the db.
			# The whitelist is always in effect; an empty/unset list means
			# no namespace is whitelisted and the file is not delivered.
			$whitelist = $this->config->get( 'CssRawWhitelistedNamespaceIds' ) ?? [];
			if ( in_array( $title->getNamespace(), $whitelist, true ) ) {
				# Namespace whitelisted: deliver the page raw.
				$params = [
					'action' => 'raw',
					'ctype' => 'text/css',
				] + $rawProtection;
				$url = $title->getLocalURL( $params );
				$headItem .= Html::linkedStyle( $url );
			} else {
				# Namespace not whitelisted: refuse delivery.
				# Strip "--" runs so user-supplied content can't escape the
				# HTML comment we're embedding it in.
				$snippet = str_replace( '-', '', substr( $css, 0, 30 ) );
				$headItem .= '<!-- Extension:CSS Error in ' . $snippet
					. ( strlen( $css ) > 30 ? '...' : '' )
					. '. Only namespaces [' . implode( ',', $whitelist ) . '] allowed.'
					. ' You use: ' . (int)$title->getNamespace() . ' (namespace id) -->';
			}
		} elseif ( $css[0] === '/' && !( strlen( $css ) >= 2 && $css[1] === '*' ) ) {
			# Regular file
			$base = $this->config->get( 'CSSPath' ) ??
				$this->config->get( MainConfigNames::StylePath );

			# Defence-in-depth path validation.
			#
			# T369486 / T401526 patched the obvious cases (%2f, %5c, raw
			# backslashes) by replacing those sequences before the
			# urlUtils->expand() prefix check. That approach is brittle:
			# it missed %2e (dot), %252e, NFKC/IDN look-alikes, and any
			# future encoding the browser learns to decode. Rather than
			# try to canonicalise the path (which requires replicating
			# browser URL parsing exactly), restrict the input to a tight
			# allowlist of characters that legitimate static-CSS paths
			# need, and refuse:
			#   - ".." anywhere (path traversal),
			#   - "//" anywhere (a defanged but ugly protocol-relative
			#     look-alike that the $base prepend currently neutralises,
			#     but only by accident of $base being non-empty),
			#   - any path segment starting with "." (.git, .env,
			#     .htaccess, ./ no-op segments) -- there is no legitimate
			#     static-CSS path that requires a dotfile.
			# The expand()+str_starts_with() prefix check is kept as a
			# second line of defence.
			$isSafePath = preg_match( '#^/[A-Za-z0-9._/-]+$#', $css )
				&& !str_contains( $css, '..' )
				&& !str_contains( $css, '//' )
				&& !preg_match( '#(^|/)\.#', $css );

			if ( !$isSafePath ) {
				$headItem .= '<!-- Invalid/malicious path  -->';
			} else {
				$url = wfAppendQuery( $base . $css, $rawProtection );
				$expandedUrl = $this->urlUtils->expand( $url );
				$expandedBase = $this->urlUtils->expand( $base );
				if ( $expandedUrl && $expandedBase
					&& str_starts_with( $expandedUrl, $expandedBase )
				) {
					$headItem .= Html::linkedStyle( $url );
				} else {
					$headItem .= '<!-- Invalid/malicious path  -->';
				}
			}
		} else {
			# sanitized user CSS
			$css = $this->sanitizeCSS( $css );

			# Emit an inline <style> tag instead of a data: URI <link>.
			# The data: URI form is unreliable across browsers and breaks
			# strict CSPs; an inline <style> is what wiki templates that
			# interpolate page variables (e.g. Template:Header's per-page
			# site-notice hide rule) actually need.
			#
			# `type="text/css"` is HTML5-default and MediaWiki strips it
			# from the <style> tag in 1.43+, so passing it as an attribute
			# is a no-op and breaks tests that expect it on the wire.
			$headItem .= Html::inlineStyle( $css );
		}

		$headItem .= '<!-- End Extension:CSS -->';
		$parser->getOutput()->addHeadItem( $headItem );
		return '';
	}

	/**
	 * @param Parser $parser
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'css', [ $this, 'cssRender' ] );
	}

	/**
	 * @param RawAction $rawPage
	 * @param string &$text
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onRawPageViewBeforeOutput( $rawPage, &$text ) {
		$identifier = $this->config->get( 'CSSIdentifier' );

		if ( !$rawPage->getRequest()->getBool( $identifier ) ) {
			return;
		}

		# Skip sanitization only when the requested page itself is in a
		# whitelisted namespace. Checking is_array() alone would let any page
		# on the wiki be served raw via a hand-crafted ?action=raw URL once
		# the whitelist is configured.
		$whitelist = $this->config->get( 'CssRawWhitelistedNamespaceIds' );
		$title = $rawPage->getTitle();
		if ( is_array( $whitelist )
			&& $title
			&& in_array( $title->getNamespace(), $whitelist, true )
		) {
			return;
		}

		$text = $this->sanitizeCSS( $text );
	}
}
