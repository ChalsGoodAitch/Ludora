<?php
/** Verify exact production source bytes installed from ZIPs, without any source mounts. */
$manifest = json_decode( file_get_contents( __DIR__ . '/../artifacts/product-file-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
foreach ( $manifest as $path => $sha ) {
 if ( ! is_file( '/var/www/html/' . $path ) || hash_file( 'sha256', '/var/www/html/' . $path ) !== $sha ) { throw new RuntimeException( 'Installed ZIP mismatch: ' . $path ); }
}
echo 'PASS: ' . count( $manifest ) . " installed product files match production source SHA256 exactly.\n";
