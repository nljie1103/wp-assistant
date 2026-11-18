<?php
/** Regression: malformed delimiters used to emit "Unknown modifier ']'" on download pages. */

use PHPUnit\Framework\TestCase;

final class DownloadCoverRegexTest extends TestCase {
    /** @return string[] Actual placeholder regexes extracted from shipped PHP source. */
    private function placeholder_patterns( $relative_path ) {
        $source = file_get_contents( dirname( __DIR__ ) . '/' . $relative_path );
        $this->assertNotFalse( $source );

        $matches = array();
        preg_match_all( '~preg_match\(\s*\'([^\']+)\'\s*,\s*\$url\s*\)~', $source, $matches );
        $patterns = array_values( array_filter( $matches[1], function ( $pattern ) {
            return false !== strpos( $pattern, '(?:transparent|spacer|blank|loading' );
        } ) );

        // The technology template contains two guarded normalizers, both need a fix.
        $expected_count = 'features/download-page-templates/technology.php' === $relative_path ? 2 : 1;
        $this->assertCount( $expected_count, $patterns, 'Unexpected placeholder regex count in ' . $relative_path );
        return $patterns;
    }

    public function test_all_placeholder_regexes_compile_without_warnings_and_keep_real_covers() {
        foreach ( array(
            'features/download-page-templates/technology.php',
            'features/download-page-cover-fix.php',
        ) as $relative_path ) {
            foreach ( $this->placeholder_patterns( $relative_path ) as $pattern ) {
                // Treat PHP warnings (including malformed PCRE delimiters) as failures.
                set_error_handler( static function ( $severity, $message ) {
                    throw new ErrorException( $message, 0, $severity );
                } );
                try {
                    foreach ( array(
                        'https://cdn.example.test/transparent.gif',
                        'https://cdn.example.test/spacer_1.PNG',
                        'https://cdn.example.test/blank.webp?v=2',
                        'https://cdn.example.test/loading-image.gif#preview',
                    ) as $url ) {
                        $this->assertSame( 1, preg_match( $pattern, $url ), $relative_path . ': ' . $url );
                    }
                    foreach ( array(
                        'https://cdn.example.test/photos/real-cover.png',
                        'https://cdn.example.test/photos/blanket.jpg',
                        'https://cdn.example.test/photos/cover.webp#v3',
                    ) as $url ) {
                        $this->assertSame( 0, preg_match( $pattern, $url ), $relative_path . ': ' . $url );
                    }
                } finally {
                    restore_error_handler();
                }
            }
        }
    }

    public function test_extended_fallback_rejects_svg_placeholders() {
        foreach ( array(
            'features/download-page-templates/technology.php',
            'features/download-page-cover-fix.php',
        ) as $relative_path ) {
            foreach ( $this->placeholder_patterns( $relative_path ) as $pattern ) {
                if ( false === strpos( $pattern, 'placeholder|spinner|pixel' ) ) {
                    continue;
                }
                $this->assertSame( 1, preg_match( $pattern, 'https://cdn.example.test/placeholder.svg#v3' ) );
                $this->assertSame( 1, preg_match( $pattern, 'https://cdn.example.test/spinner-2.svg' ) );
            }
        }
    }
}
