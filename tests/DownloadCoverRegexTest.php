<?php
/** Regression: malformed delimiters used to emit "Unknown modifier ']'" on download pages. */

use PHPUnit\Framework\TestCase;

final class DownloadCoverRegexTest extends TestCase {
    /** @return string The actual placeholder regex extracted from shipped PHP code. */
    private function placeholder_pattern( $relative_path ) {
        $source = file_get_contents( dirname( __DIR__ ) . '/' . $relative_path );
        $this->assertNotFalse( $source );

        $matches = array();
        preg_match_all( '~preg_match\(\s*\'([^\']+)\'\s*,\s*\$url\s*\)~', $source, $matches );
        $patterns = array_values( array_filter( $matches[1], function ( $pattern ) {
            return false !== strpos( $pattern, '(?:transparent|spacer|blank|loading' );
        } ) );

        $this->assertCount( 1, $patterns, 'Cannot locate the real placeholder image regex in ' . $relative_path );
        return $patterns[0];
    }

    public function test_placeholder_regexes_compile_without_warnings_and_keep_real_covers() {
        foreach ( array(
            'features/download-page-templates/technology.php',
            'features/download-page-cover-fix.php',
        ) as $relative_path ) {
            $pattern = $this->placeholder_pattern( $relative_path );

            // Turn every PHP warning into a hard test failure.
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

    public function test_extended_fallback_rejects_svg_spinner() {
        $pattern = $this->placeholder_pattern( 'features/download-page-cover-fix.php' );
        $this->assertSame( 1, preg_match( $pattern, 'https://cdn.example.test/placeholder.svg#v3' ) );
        $this->assertSame( 1, preg_match( $pattern, 'https://cdn.example.test/spinner-2.svg' ) );
    }
}
