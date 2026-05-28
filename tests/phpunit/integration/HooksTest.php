<?php

namespace MediaWiki\Extension\CSS\Tests\Integration;

use MediaWiki\Extension\CSS\Hooks;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Request\WebRequest;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\CSS\Hooks
 * @group Database
 */
class HooksTest extends MediaWikiIntegrationTestCase {
	private function newInstance(): Hooks {
		$services = $this->getServiceContainer();
		return new Hooks(
			$services->getMainConfig(),
			$services->getHookContainer(),
			$services->getTitleFactory(),
			$services->getUrlUtils()
		);
	}

	/**
	 * @dataProvider provideCssRender
	 */
	public function testCssRender( string $expected, string $css ) {
		$hooks = $this->newInstance();

		$parserOutput = $this->createMock( ParserOutput::class );
		$parserOutput->method( 'addHeadItem' )->with( $expected );

		$parser = $this->createMock( Parser::class );
		$parser->method( 'getOutput' )
			->willReturn( $parserOutput );

		$result = $hooks->cssRender( $parser, $css );

		// The result is always empty.
		$this->assertSame( '', $result );
	}

	public static function provideCssRender() {
		return [
			[ '', '' ],
			[
				'<!-- Begin Extension:CSS --><link rel="stylesheet" ' .
				'href="/skins/skins/MyStyles.css?css-extension=1">' .
				'<!-- End Extension:CSS -->',
				'/skins/MyStyles.css',
			],
			[
				'<!-- Begin Extension:CSS --><!-- Invalid/malicious path  --><!-- End Extension:CSS -->',
				'/../../BadStyles.css',
			],
			// Regression test for T369486:
			[
				'<!-- Begin Extension:CSS --><!-- Invalid/malicious path  --><!-- End Extension:CSS -->',
				'/..\index.php?title=CSS/Path traversal/styles.css&action=raw&ctype=text/css',
			],
			// Regression: %2e (URL-encoded dot) gap missed by T369486/T401526.
			// Browsers decode %2e to "." in path segments per RFC 3986 §6.2.2.3
			// and resolve dot-segments per §5.2.4, so this used to escape $base.
			[
				'<!-- Begin Extension:CSS --><!-- Invalid/malicious path  --><!-- End Extension:CSS -->',
				'/%2e%2e/private.css',
			],
			[
				'<!-- Begin Extension:CSS --><!-- Invalid/malicious path  --><!-- End Extension:CSS -->',
				'/%2E%2E/private.css',
			],
			// Double-encoded variant: %252e -> %2e -> "."
			[
				'<!-- Begin Extension:CSS --><!-- Invalid/malicious path  --><!-- End Extension:CSS -->',
				'/%252e%252e/private.css',
			],
			// Any percent-encoding at all is refused (defence-in-depth).
			[
				'<!-- Begin Extension:CSS --><!-- Invalid/malicious path  --><!-- End Extension:CSS -->',
				'/skins/foo%2fbar.css',
			],
			// Disallowed characters: "?", "#", "@", whitespace, control bytes.
			[
				'<!-- Begin Extension:CSS --><!-- Invalid/malicious path  --><!-- End Extension:CSS -->',
				'/skins/foo.css?x=1',
			],
			[
				'<!-- Begin Extension:CSS --><!-- Invalid/malicious path  --><!-- End Extension:CSS -->',
				'/skins/foo.css#frag',
			],
			// ".." anywhere in the path is refused, even within a filename.
			[
				'<!-- Begin Extension:CSS --><!-- Invalid/malicious path  --><!-- End Extension:CSS -->',
				'/skins/style..css',
			],
			// Positive: hyphens and multi-dot extensions still work.
			[
				'<!-- Begin Extension:CSS --><link rel="stylesheet" ' .
				'href="/skins/skins/foo-bar.min.css?css-extension=1">' .
				'<!-- End Extension:CSS -->',
				'/skins/foo-bar.min.css',
			],
			[
				'<!-- Begin Extension:CSS --><style>' .
				'/* css-sanitizer failed to parse CSS */</style>' .
				'<!-- End Extension:CSS -->',
				'{',
			],
			[
				'<!-- Begin Extension:CSS --><style>' .
				'/* css-sanitizer failed to sanitize CSS */</style>' .
				'<!-- End Extension:CSS -->',
				<<<EOT
				  body {{
				    background: yellow;
				    font-size: 20pt;
				    color: red;
				  }}
				EOT,
			],
			[
				'<!-- Begin Extension:CSS --><style>' .
				'body{background:yellow;font-size:20pt;color:red}</style>' .
				'<!-- End Extension:CSS -->',
				<<<EOT
				  body {
				    background: yellow;
				    font-size: 20pt;
				    color: red;
				  }
				EOT,
			],
		];
	}

	private function captureHeadItem( Hooks $hooks, string $css ): string {
		$captured = '';
		$parserOutput = $this->createMock( ParserOutput::class );
		$parserOutput->expects( $this->once() )
			->method( 'addHeadItem' )
			->willReturnCallback( static function ( $headItem ) use ( &$captured ) {
				$captured = $headItem;
			} );
		$parser = $this->createMock( Parser::class );
		$parser->method( 'getOutput' )->willReturn( $parserOutput );
		$result = $hooks->cssRender( $parser, $css );
		$this->assertSame( '', $result );
		return $captured;
	}

	public function testCssRenderArticleInWhitelistedNamespace() {
		$this->overrideConfigValue( 'CssRawWhitelistedNamespaceIds', [ NS_MEDIAWIKI ] );
		$this->insertPage(
			Title::makeTitle( NS_MEDIAWIKI, 'CssExtForkTest.css' ),
			'.foo { color: red; }'
		);

		$head = $this->captureHeadItem( $this->newInstance(), 'MediaWiki:CssExtForkTest.css' );

		$this->assertStringContainsString( '<!-- Begin Extension:CSS -->', $head );
		$this->assertStringContainsString( '<link rel="stylesheet"', $head );
		$this->assertStringContainsString( 'action=raw', $head );
		$this->assertStringContainsString( 'css-extension=1', $head );
		$this->assertStringContainsString( '<!-- End Extension:CSS -->', $head );
	}

	public function testCssRenderArticleInNonWhitelistedNamespaceRefuses() {
		$this->overrideConfigValue( 'CssRawWhitelistedNamespaceIds', [ NS_MEDIAWIKI ] );
		$this->insertPage(
			Title::makeTitle( NS_USER, 'CssExtForkTestUser/myskin.css' ),
			'.foo { color: red; }'
		);

		$head = $this->captureHeadItem(
			$this->newInstance(),
			'User:CssExtForkTestUser/myskin.css'
		);

		$this->assertStringContainsString( 'Extension:CSS Error in', $head );
		$this->assertStringContainsString( 'Only namespaces [' . NS_MEDIAWIKI . ']', $head );
		$this->assertStringContainsString( 'You use: ' . NS_USER . ' (namespace id)', $head );
		// Crucially: no <link> rendered for a non-whitelisted page.
		$this->assertStringNotContainsString( '<link', $head );
	}

	public function testCssRenderEmbeddedSnippetStripsHyphensToBlockCommentEscape() {
		// Page titles in MediaWiki cannot contain '>', so a literal "-->" run
		// cannot reach this code path. The hyphen strip is belt-and-braces: it
		// also flattens "--" runs that an attacker might combine with future
		// parser quirks to escape the surrounding HTML comment.
		$this->overrideConfigValue( 'CssRawWhitelistedNamespaceIds', [ NS_MEDIAWIKI ] );
		$this->insertPage(
			Title::makeTitle( NS_USER, 'Foo--Bar.css' ),
			'.foo { color: red; }'
		);

		$head = $this->captureHeadItem( $this->newInstance(), 'User:Foo--Bar.css' );

		$this->assertStringContainsString( 'Error in User:FooBar.css', $head );
		$this->assertStringNotContainsString( 'Foo--', $head );
	}

	public function testCssRenderNonExistentArticleFallsThroughToInlineSanitizer() {
		$this->overrideConfigValue( 'CssRawWhitelistedNamespaceIds', [ NS_MEDIAWIKI ] );

		// 'MediaWiki:DefinitelyNotCreated.css' is a syntactically valid title
		// that does not exist -> $title->exists() is false -> cssRender falls
		// through to the inline-CSS branch -> sanitizer rejects the page name
		// as malformed CSS.
		$head = $this->captureHeadItem(
			$this->newInstance(),
			'MediaWiki:DefinitelyNotCreated.css'
		);

		$this->assertStringContainsString(
			'/* css-sanitizer failed to parse CSS */',
			$head
		);
		$this->assertStringNotContainsString( '<link', $head );
	}

	private function makeRawPage( int $namespace, bool $cssExtensionFlag ): object {
		$title = $this->createMock( Title::class );
		$title->method( 'getNamespace' )->willReturn( $namespace );

		$request = $this->createMock( WebRequest::class );
		$request->method( 'getBool' )
			->with( 'css-extension' )
			->willReturn( $cssExtensionFlag );

		// Anonymous stub: RawAction inherits Action::getTitle() which is
		// `final`, so PHPUnit cannot double it via createMock(). The hook
		// signature is untyped (`$rawPage`), so any object exposing
		// getRequest() and getTitle() works.
		return new class( $request, $title ) {
			public function __construct(
				private readonly WebRequest $request,
				private readonly Title $title
			) {
			}

			public function getRequest(): WebRequest {
				return $this->request;
			}

			public function getTitle(): Title {
				return $this->title;
			}
		};
	}

	public function testRawPageViewBypassesSanitizationForWhitelistedNamespace() {
		$this->overrideConfigValue( 'CssRawWhitelistedNamespaceIds', [ NS_MEDIAWIKI ] );
		$rawPage = $this->makeRawPage( NS_MEDIAWIKI, true );

		$text = 'body { -evil-vendor: bad; }';
		$original = $text;
		$this->newInstance()->onRawPageViewBeforeOutput( $rawPage, $text );

		// Admin opted in for MediaWiki: ns; content passes through untouched.
		$this->assertSame( $original, $text );
	}

	public function testRawPageViewSanitizesNonWhitelistedNamespaceWhenWhitelistConfigured() {
		// Regression cover for the gap closed in 54eb3ff: before that commit a
		// configured whitelist disabled sanitization for ALL namespaces, so a
		// User:Attacker/X page requested with ?action=raw&ctype=text/css&
		// css-extension=1 was returned as-is. Must now sanitize.
		$this->overrideConfigValue( 'CssRawWhitelistedNamespaceIds', [ NS_MEDIAWIKI ] );
		$rawPage = $this->makeRawPage( NS_USER, true );

		$text = '{not valid css}';
		$this->newInstance()->onRawPageViewBeforeOutput( $rawPage, $text );

		// '{not valid css}' tokenises as a valid empty block prelude, so
		// the parse step succeeds; the sanitizer rejects it instead.
		$this->assertSame( '/* css-sanitizer failed to sanitize CSS */', $text );
	}

	public function testRawPageViewSanitizesWhenWhitelistUnset() {
		// Legacy behaviour: $wgCssRawWhitelistedNamespaceIds null -> sanitize
		// every css-extension raw view regardless of namespace.
		$this->overrideConfigValue( 'CssRawWhitelistedNamespaceIds', null );
		$rawPage = $this->makeRawPage( NS_MEDIAWIKI, true );

		$text = '{not valid css}';
		$this->newInstance()->onRawPageViewBeforeOutput( $rawPage, $text );

		// '{not valid css}' tokenises as a valid empty block prelude, so
		// the parse step succeeds; the sanitizer rejects it instead.
		$this->assertSame( '/* css-sanitizer failed to sanitize CSS */', $text );
	}

	public function testRawPageViewIsNoOpWithoutCssExtensionFlag() {
		$this->overrideConfigValue( 'CssRawWhitelistedNamespaceIds', null );
		$rawPage = $this->makeRawPage( NS_MEDIAWIKI, false );

		$text = '{not valid css}';
		$original = $text;
		$this->newInstance()->onRawPageViewBeforeOutput( $rawPage, $text );

		$this->assertSame( $original, $text );
	}
}
