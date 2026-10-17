<?php
/**
 * A photo from the chat is stored without its metadata: no EXIF (GPS
 * coordinates, camera, time) in the stored file, whether it was resized or
 * kept at its size. The test JPEG is generated here: GD image plus an APP1
 * Exif segment with a GPS IFD. Checked by the bytes, which needs no extension.
 */
require __DIR__ . '/support.php';

/** A TIFF block (big-endian) with a GPS IFD: latitude, longitude and their refs. */
function exif_tiff(): string
{
    $rationals = pack('NN', 50, 1) . pack('NN', 30, 1) . pack('NN', 0, 1);   // 50° 30' 0"
    $longitude = pack('NN', 10, 1) . pack('NN', 0, 1) . pack('NN', 0, 1);    // 10° 0' 0"
    $gps = pack('n', 4)
        . pack('nnNa4', 0x0001, 2, 2, "N\0\0\0")                 // GPSLatitudeRef
        . pack('nnNN', 0x0002, 5, 3, 80)                          // GPSLatitude at 80
        . pack('nnNa4', 0x0003, 2, 2, "E\0\0\0")                 // GPSLongitudeRef
        . pack('nnNN', 0x0004, 5, 3, 104)                         // GPSLongitude at 104
        . pack('N', 0);
    $ifd0 = pack('n', 1) . pack('nnNN', 0x8825, 4, 1, 26) . pack('N', 0); // GPSInfo at 26
    return "MM\0\x2a" . pack('N', 8) . $ifd0 . $gps . $rationals . $longitude;
}

/** Inserts an APP1 Exif segment (with GPS) right after the JPEG start marker. */
function exif_jpeg(string $file): void
{
    $jpeg = (string) file_get_contents($file);
    $payload = "Exif\0\0" . exif_tiff();
    $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    file_put_contents($file, substr($jpeg, 0, 2) . $segment . substr($jpeg, 2));
}

$failed = false;
try {
    [$tmp, $app] = fude_fixture();
    $draft = function (array $upload) use ($app, $tmp): array {
        return fude_call($app, 'draft', ['collection' => 'team', 'action' => 'create', 'fields' => ['name' => 'Anna Beispiel']], options: [], files: ['image' => $upload]);
    };
    // Sanity: the upload really carries the Exif block and the GPS tag.
    $small = fude_upload($tmp, 300, 200, 'jpg');
    exif_jpeg($small['tmp_name']);
    ok(str_contains((string) file_get_contents($small['tmp_name']), "Exif\0\0"), 'Upload carries Exif');

    // Smaller than maxWidth (800 in the fixture): kept at its size.
    $smallPath = $small['tmp_name'];
    [$code, $json] = $draft($small);
    same(200, $code, 'Small photo drafted', $json);
    $stored = glob($tmp . '/content/standort/*.jpg') ?: [];
    same(1, count($stored), 'One stored file');
    ok(!str_contains((string) file_get_contents($stored[0]), "Exif\0\0"), 'Small photo stored without Exif', $stored[0]);
    ok(!str_contains((string) file_get_contents($stored[0]), 'GPS'), 'Small photo stored without GPS');
    fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);

    // Larger than maxWidth: resized, EXIF gone with the resampling.
    $large = fude_upload($tmp, 1600, 1000, 'jpg');
    exif_jpeg($large['tmp_name']);
    [$code, $json] = $draft($large);
    same(200, $code, 'Large photo drafted', $json);
    $stored = glob($tmp . '/content/standort/*.jpg') ?: [];
    same(1, count($stored), 'One stored file (large)');
    ok(!str_contains((string) file_get_contents($stored[0]), "Exif\0\0"), 'Large photo stored without Exif', $stored[0]);
    same(800, getimagesize($stored[0])[0], 'Large photo resized to maxWidth');
    fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
    @unlink($smallPath);
} catch (Throwable $e) {
    fwrite(STDERR, 'Exif: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    cleanup($tmp);
}
global $n;
echo "Exif: $n checks\n";
exit($failed ? 1 : 0);
