#!/usr/bin/env bash
# Reproduce Baota WordPress PHP-FPM's restricted open_basedir.
# Calling status must attempt the already authorized sudo command even when
# PHP is unable to stat a root-owned helper outside the website directory.
set -euo pipefail
cd "$(dirname "$0")/.."
php -d "open_basedir=$(pwd)" -r '
define( "ABSPATH", "/" );
function add_action( $tag, $callback ) {}
require "features/private-download-deploy.php";
if ( @is_file( "/usr/local/sbin/jlwa-nginx-helper" ) ) {
    fwrite( STDERR, "Expected PHP site sandbox to hide the root helper\n" );
    exit( 1 );
}
$status = JLWA_Private_Deploy::call( "status" );
if ( ! is_array( $status ) || ! array_key_exists( "ok", $status ) ||
    ! array_key_exists( "message", $status ) ) {
    fwrite( STDERR, "Expected a structured status result\n" );
    exit( 1 );
}
if ( strpos( $status["message"], "未安装受限 Nginx 部署助手" ) !== false ) {
    fwrite( STDERR, "Regressed: falsely interpreted open_basedir as not installed\n" );
    exit( 1 );
}
echo "PHP open_basedir: restricted helper visibility does not imply missing installation\n";
'
