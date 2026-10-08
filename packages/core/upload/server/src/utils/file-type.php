<?php

declare(strict_types=1);

namespace Strapi\Upload\Utils;

/**
 * PHP-port addition: magic-number detection standing in for the `file-type` npm package (21.3.4)
 * that `utils/mime-validation.ts` runs on the first 4100 bytes of an upload.
 *
 * Like `file-type`, it only recognises binary signatures: text formats (plain text, CSV, JSON,
 * SVG…) give `null`, and the caller then falls back to the extension. Ported: the common image,
 * audio, video, archive, document and executable formats, including `file-type`'s zip inspection
 * for Office Open XML / OpenDocument / EPUB / JAR / APK / XPI. Exotic formats `file-type` also
 * knows (raw camera formats, CAD, game ROMs…) are not ported and give `null`.
 */
final class FileType
{
    /** @return array{ext: string, mime: string}|null */
    public static function fromBuffer(string $buffer): ?array
    {
        $len = strlen($buffer);
        if ($len < 2) {
            return null;
        }

        $str = static fn (string $s, int $offset = 0): bool => substr($buffer, $offset, strlen($s)) === $s;

        if (self::check($buffer, [0x42, 0x4D]) && $len >= 26 && self::isBmp($buffer)) {
            return ['ext' => 'bmp', 'mime' => 'image/bmp'];
        }
        if (self::check($buffer, [0x1F, 0x8B, 0x08])) {
            return ['ext' => 'gz', 'mime' => 'application/gzip'];
        }
        if (self::check($buffer, [0x4D, 0x5A])) {
            return ['ext' => 'exe', 'mime' => 'application/x-msdownload'];
        }
        if (self::check($buffer, [0xFF, 0xD8, 0xFF])) {
            return ['ext' => 'jpg', 'mime' => 'image/jpeg'];
        }
        if ($str('BZh')) {
            return ['ext' => 'bz2', 'mime' => 'application/x-bzip2'];
        }
        if ($str('ID3')) {
            return ['ext' => 'mp3', 'mime' => 'audio/mpeg'];
        }
        if ($str('GIF')) {
            return ['ext' => 'gif', 'mime' => 'image/gif'];
        }
        if ($str('%PDF')) {
            return ['ext' => 'pdf', 'mime' => 'application/pdf'];
        }
        if (self::check($buffer, [0x50, 0x4B, 0x03, 0x04])) {
            return self::fromZip($buffer);
        }
        if ($str('OggS')) {
            $type = substr($buffer, 28, 8);
            if (str_starts_with($type, 'OpusHead')) {
                return ['ext' => 'opus', 'mime' => 'audio/ogg; codecs=opus'];
            }
            if (str_starts_with($type, "\x80theora")) {
                return ['ext' => 'ogv', 'mime' => 'video/ogg'];
            }
            if (str_starts_with($type, "\x01video\x00")) {
                return ['ext' => 'ogm', 'mime' => 'video/ogg'];
            }
            if (str_starts_with($type, "\x7FFLAC")) {
                return ['ext' => 'oga', 'mime' => 'audio/ogg'];
            }
            if (str_starts_with($type, 'Speex  ')) {
                return ['ext' => 'spx', 'mime' => 'audio/ogg'];
            }
            if (str_starts_with($type, "\x01vorbis")) {
                return ['ext' => 'ogg', 'mime' => 'audio/ogg'];
            }

            return ['ext' => 'ogx', 'mime' => 'application/ogg'];
        }
        if (self::check($buffer, [0x50, 0x4B]) && in_array(ord($buffer[2] ?? "\0"), [0x3, 0x5, 0x7], true) && in_array(ord($buffer[3] ?? "\0"), [0x4, 0x6, 0x8], true)) {
            return ['ext' => 'zip', 'mime' => 'application/zip'];
        }
        if ($str('ftyp', 4) && $len >= 12) {
            return self::fromFtyp($buffer);
        }
        if ($str('MThd')) {
            return ['ext' => 'mid', 'mime' => 'audio/midi'];
        }
        if ($str('wOFF') && (self::check($buffer, [0x00, 0x01, 0x00, 0x00], 4) || $str('OTTO', 4))) {
            return ['ext' => 'woff', 'mime' => 'font/woff'];
        }
        if ($str('wOF2') && (self::check($buffer, [0x00, 0x01, 0x00, 0x00], 4) || $str('OTTO', 4))) {
            return ['ext' => 'woff2', 'mime' => 'font/woff2'];
        }
        if (self::check($buffer, [0xD4, 0xC3, 0xB2, 0xA1]) || self::check($buffer, [0xA1, 0xB2, 0xC3, 0xD4])) {
            return ['ext' => 'pcap', 'mime' => 'application/vnd.tcpdump.pcap'];
        }
        if ($str('8BPS')) {
            return ['ext' => 'psd', 'mime' => 'image/vnd.adobe.photoshop'];
        }
        if ($str('fLaC')) {
            return ['ext' => 'flac', 'mime' => 'audio/flac'];
        }
        if ($str('wvpk')) {
            return ['ext' => 'wv', 'mime' => 'audio/wavpack'];
        }
        if ($str('#!AMR')) {
            return ['ext' => 'amr', 'mime' => 'audio/amr'];
        }
        if ($str('{\\rtf')) {
            return ['ext' => 'rtf', 'mime' => 'application/rtf'];
        }
        if (self::check($buffer, [0x46, 0x4C, 0x56, 0x01])) {
            return ['ext' => 'flv', 'mime' => 'video/x-flv'];
        }
        if (self::check($buffer, [0x00, 0x61, 0x73, 0x6D])) {
            return ['ext' => 'wasm', 'mime' => 'application/wasm'];
        }
        if (self::check($buffer, [0x7F, 0x45, 0x4C, 0x46])) {
            return ['ext' => 'elf', 'mime' => 'application/x-elf'];
        }
        if (self::check($buffer, [0x1A, 0x45, 0xDF, 0xA3])) {
            $window = substr($buffer, 0, 4100);

            return str_contains($window, 'webm')
                ? ['ext' => 'webm', 'mime' => 'video/webm']
                : ['ext' => 'mkv', 'mime' => 'video/matroska'];
        }
        if ($str('RIFF') && $len >= 12) {
            if ($str('WEBP', 8)) {
                return ['ext' => 'webp', 'mime' => 'image/webp'];
            }
            if ($str('AVI', 8)) {
                return ['ext' => 'avi', 'mime' => 'video/vnd.avi'];
            }
            if ($str('WAVE', 8)) {
                return ['ext' => 'wav', 'mime' => 'audio/wav'];
            }
            if ($str('QLCM', 8)) {
                return ['ext' => 'qcp', 'mime' => 'audio/qcelp'];
            }
        }
        if ($str('SQLi')) {
            return ['ext' => 'sqlite', 'mime' => 'application/x-sqlite3'];
        }
        if (self::check($buffer, [0x4E, 0x45, 0x53, 0x1A])) {
            return ['ext' => 'nes', 'mime' => 'application/x-nintendo-nes-rom'];
        }
        if ($str('Cr24')) {
            return ['ext' => 'crx', 'mime' => 'application/x-google-chrome-extension'];
        }
        if ($str('MSCF') || $str('ISc(')) {
            return ['ext' => 'cab', 'mime' => 'application/vnd.ms-cab-compressed'];
        }
        if (self::check($buffer, [0xED, 0xAB, 0xEE, 0xDB])) {
            return ['ext' => 'rpm', 'mime' => 'application/x-rpm'];
        }
        if (self::check($buffer, [0xC5, 0xD0, 0xD3, 0xC6])) {
            return ['ext' => 'eps', 'mime' => 'application/eps'];
        }
        if (self::check($buffer, [0x28, 0xB5, 0x2F, 0xFD])) {
            return ['ext' => 'zst', 'mime' => 'application/zstd'];
        }
        if (self::check($buffer, [0xCA, 0xFE, 0xBA, 0xBE]) || self::check($buffer, [0xFE, 0xED, 0xFA, 0xCE]) || self::check($buffer, [0xFE, 0xED, 0xFA, 0xCF]) || self::check($buffer, [0xCE, 0xFA, 0xED, 0xFE]) || self::check($buffer, [0xCF, 0xFA, 0xED, 0xFE])) {
            return ['ext' => 'macho', 'mime' => 'application/x-mach-binary'];
        }
        if ($str('OTTO')) {
            return ['ext' => 'otf', 'mime' => 'font/otf'];
        }
        if (self::check($buffer, [0x49, 0x49, 0x2A, 0x00]) || self::check($buffer, [0x4D, 0x4D, 0x00, 0x2A])) {
            return ['ext' => 'tif', 'mime' => 'image/tiff'];
        }
        if (self::check($buffer, [0x49, 0x49, 0xBC])) {
            return ['ext' => 'jxr', 'mime' => 'image/vnd.ms-photo'];
        }
        if (self::check($buffer, [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A])) {
            return self::isApng($buffer) ? ['ext' => 'apng', 'mime' => 'image/apng'] : ['ext' => 'png', 'mime' => 'image/png'];
        }
        if (self::check($buffer, [0x37, 0x7A, 0xBC, 0xAF, 0x27, 0x1C])) {
            return ['ext' => '7z', 'mime' => 'application/x-7z-compressed'];
        }
        if (self::check($buffer, [0x52, 0x61, 0x72, 0x21, 0x1A, 0x07]) && in_array(ord($buffer[6] ?? "\xFF"), [0x0, 0x1], true)) {
            return ['ext' => 'rar', 'mime' => 'application/x-rar-compressed'];
        }
        if (self::check($buffer, [0xFD, 0x37, 0x7A, 0x58, 0x5A, 0x00])) {
            return ['ext' => 'xz', 'mime' => 'application/x-xz'];
        }
        if ($str('<?xml ') || $str("\xEF\xBB\xBF<?xml ")) {
            return ['ext' => 'xml', 'mime' => 'application/xml'];
        }
        if (self::check($buffer, [0xD0, 0xCF, 0x11, 0xE0, 0xA1, 0xB1, 0x1A, 0xE1])) {
            return ['ext' => 'cfb', 'mime' => 'application/x-cfb'];
        }
        if (self::check($buffer, [0x00, 0x01, 0x00, 0x00, 0x00])) {
            return ['ext' => 'ttf', 'mime' => 'font/ttf'];
        }
        if (self::check($buffer, [0x00, 0x00, 0x01, 0x00])) {
            return ['ext' => 'ico', 'mime' => 'image/x-icon'];
        }
        if (self::check($buffer, [0x00, 0x00, 0x02, 0x00])) {
            return ['ext' => 'cur', 'mime' => 'image/x-icon'];
        }
        if (self::check($buffer, [0x00, 0x00, 0x01, 0xBA]) || self::check($buffer, [0x00, 0x00, 0x01, 0xB3])) {
            return ['ext' => 'mpg', 'mime' => 'video/mpeg'];
        }
        if (self::check($buffer, [0x00, 0x00, 0x00, 0x0C, 0x6A, 0x50, 0x20, 0x20, 0x0D, 0x0A, 0x87, 0x0A])) {
            return ['ext' => 'jp2', 'mime' => 'image/jp2'];
        }
        if (self::check($buffer, [0xFF, 0x0A]) || self::check($buffer, [0x00, 0x00, 0x00, 0x0C, 0x4A, 0x58, 0x4C, 0x20, 0x0D, 0x0A, 0x87, 0x0A])) {
            return ['ext' => 'jxl', 'mime' => 'image/jxl'];
        }
        if ($str('ustar', 257)) {
            return ['ext' => 'tar', 'mime' => 'application/x-tar'];
        }
        if (self::check($buffer, [0x30, 0x26, 0xB2, 0x75, 0x8E, 0x66, 0xCF, 0x11, 0xA6, 0xD9])) {
            return ['ext' => 'asf', 'mime' => 'application/vnd.ms-asf'];
        }
        if ($str('!<arch>')) {
            return str_contains(substr($buffer, 8, 13), 'debian-binary')
                ? ['ext' => 'deb', 'mime' => 'application/x-deb']
                : ['ext' => 'ar', 'mime' => 'application/x-unix-archive'];
        }
        // MPEG audio frame sync (mp2/mp3, no ID3 tag)
        if (ord($buffer[0]) === 0xFF && (ord($buffer[1]) & 0xE0) === 0xE0) {
            $layer = (ord($buffer[1]) >> 1) & 0x03;
            if ((ord($buffer[1]) & 0x16) === 0x10) {
                return ['ext' => 'aac', 'mime' => 'audio/aac'];
            }
            if ($layer === 0x01) {
                return ['ext' => 'mp3', 'mime' => 'audio/mpeg'];
            }
            if ($layer === 0x02) {
                return ['ext' => 'mp2', 'mime' => 'audio/mpeg'];
            }
            if ($layer === 0x03) {
                return ['ext' => 'mp1', 'mime' => 'audio/mpeg'];
            }
        }

        return null;
    }

    /** @param list<int> $bytes */
    private static function check(string $buffer, array $bytes, int $offset = 0): bool
    {
        if (strlen($buffer) < $offset + count($bytes)) {
            return false;
        }
        foreach ($bytes as $i => $byte) {
            if (ord($buffer[$offset + $i]) !== $byte) {
                return false;
            }
        }

        return true;
    }

    private static function isBmp(string $buffer): bool
    {
        // file-type: `BM` + a known DIB header size
        $dibHeaderSize = unpack('V', substr($buffer, 14, 4));

        return is_array($dibHeaderSize) && in_array($dibHeaderSize[1], [12, 40, 52, 56, 64, 108, 124], true);
    }

    private static function isApng(string $buffer): bool
    {
        $offset = 8;
        $len = strlen($buffer);
        while ($offset + 8 <= $len) {
            $length = unpack('N', substr($buffer, $offset, 4));
            $type = substr($buffer, $offset + 4, 4);
            if (!is_array($length) || $length[1] < 0) {
                return false;
            }
            if ($type === 'acTL') {
                return true;
            }
            if ($type === 'IDAT') {
                return false;
            }
            $offset += 12 + (int) $length[1];
        }

        return false;
    }

    /** @return array{ext: string, mime: string} */
    private static function fromFtyp(string $buffer): array
    {
        $brandMajor = rtrim(str_replace("\0", ' ', substr($buffer, 8, 4)));

        return match (true) {
            $brandMajor === 'avif', $brandMajor === 'avis' => ['ext' => 'avif', 'mime' => 'image/avif'],
            $brandMajor === 'mif1' => ['ext' => 'heic', 'mime' => 'image/heif'],
            $brandMajor === 'msf1' => ['ext' => 'heic', 'mime' => 'image/heif-sequence'],
            $brandMajor === 'heic', $brandMajor === 'heix' => ['ext' => 'heic', 'mime' => 'image/heic'],
            $brandMajor === 'hevc', $brandMajor === 'hevx' => ['ext' => 'heic', 'mime' => 'image/heic-sequence'],
            $brandMajor === 'qt' => ['ext' => 'mov', 'mime' => 'video/quicktime'],
            $brandMajor === 'M4V', $brandMajor === 'M4VH', $brandMajor === 'M4VP' => ['ext' => 'm4v', 'mime' => 'video/x-m4v'],
            $brandMajor === 'M4P' => ['ext' => 'm4p', 'mime' => 'video/mp4'],
            $brandMajor === 'M4B' => ['ext' => 'm4b', 'mime' => 'audio/mp4'],
            $brandMajor === 'M4A' => ['ext' => 'm4a', 'mime' => 'audio/x-m4a'],
            $brandMajor === 'F4V' => ['ext' => 'f4v', 'mime' => 'video/mp4'],
            $brandMajor === 'F4P' => ['ext' => 'f4p', 'mime' => 'video/mp4'],
            $brandMajor === 'F4A' => ['ext' => 'f4a', 'mime' => 'audio/mp4'],
            $brandMajor === 'F4B' => ['ext' => 'f4b', 'mime' => 'audio/mp4'],
            $brandMajor === 'crx' => ['ext' => 'cr3', 'mime' => 'image/x-canon-cr3'],
            str_starts_with($brandMajor, '3g2') => ['ext' => '3g2', 'mime' => 'video/3gpp2'],
            str_starts_with($brandMajor, '3g') => ['ext' => '3gp', 'mime' => 'video/3gpp'],
            default => ['ext' => 'mp4', 'mime' => 'video/mp4'],
        };
    }

    /**
     * `file-type`'s zip inspection over the local file headers present in the buffer.
     *
     * @return array{ext: string, mime: string}
     */
    private static function fromZip(string $buffer): array
    {
        $offset = 0;
        $len = strlen($buffer);
        $hasContentTypes = false;
        $dirs = ['word' => false, 'ppt' => false, 'xl' => false, '3d' => false];

        while ($offset + 30 <= $len && substr($buffer, $offset, 4) === "PK\x03\x04") {
            $header = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength', substr($buffer, $offset + 4, 26));
            if (!is_array($header)) {
                break;
            }
            $filename = substr($buffer, $offset + 30, (int) $header['nameLength']);
            $dataStart = $offset + 30 + (int) $header['nameLength'] + (int) $header['extraLength'];
            $compressedSize = (int) $header['compressedSize'];

            if (str_starts_with($filename, 'word/')) {
                $dirs['word'] = true;
            }
            if (str_starts_with($filename, 'ppt/')) {
                $dirs['ppt'] = true;
            }
            if (str_starts_with($filename, 'xl/')) {
                $dirs['xl'] = true;
            }
            if (str_starts_with($filename, '3D/') && str_ends_with($filename, '.model')) {
                $dirs['3d'] = true;
            }

            if ($filename !== '[Content_Types].xml' && $hasContentTypes) {
                $fromDirs = self::openXmlFromDirs($dirs);
                if ($fromDirs !== null) {
                    return $fromDirs;
                }
            }

            switch ($filename) {
                case 'META-INF/mozilla.rsa':
                    return ['ext' => 'xpi', 'mime' => 'application/x-xpinstall'];
                case 'META-INF/MANIFEST.MF':
                    return ['ext' => 'jar', 'mime' => 'application/java-archive'];
                case 'mimetype':
                    $data = self::readEntry($buffer, $dataStart, $compressedSize, (int) $header['method']);
                    if ($data !== null) {
                        $type = self::fromMimeType(trim($data));
                        if ($type !== null) {
                            return $type;
                        }
                    }

                    return ['ext' => 'zip', 'mime' => 'application/zip'];
                case '[Content_Types].xml':
                    $hasContentTypes = true;
                    $data = self::readEntry($buffer, $dataStart, $compressedSize, (int) $header['method']);
                    if ($data !== null) {
                        if (preg_match('/ContentType="(application\/vnd\.[^"]+)\.main\+xml"/', $data, $m) === 1) {
                            $type = self::fromMimeType($m[1]);
                            if ($type !== null) {
                                return $type;
                            }
                        }

                        return ['ext' => 'zip', 'mime' => 'application/zip'];
                    }
                    break;
                default:
                    if (preg_match('/classes\d*\.dex/', $filename) === 1) {
                        return ['ext' => 'apk', 'mime' => 'application/vnd.android.package-archive'];
                    }
            }

            if (((int) $header['flags'] & 0x08) !== 0 && $compressedSize === 0) {
                break; // data descriptor: the size is unknown here
            }
            $offset = $dataStart + $compressedSize;
        }

        return ($hasContentTypes ? self::openXmlFromDirs($dirs) : null) ?? ['ext' => 'zip', 'mime' => 'application/zip'];
    }

    /**
     * @param array{word: bool, ppt: bool, xl: bool, '3d': bool} $dirs
     * @return array{ext: string, mime: string}|null
     */
    private static function openXmlFromDirs(array $dirs): ?array
    {
        return match (true) {
            $dirs['word'] => ['ext' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            $dirs['ppt'] => ['ext' => 'pptx', 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            $dirs['xl'] => ['ext' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            $dirs['3d'] => ['ext' => '3mf', 'mime' => 'model/3mf'],
            default => null,
        };
    }

    private static function readEntry(string $buffer, int $start, int $size, int $method): ?string
    {
        if ($size <= 0 || $start + $size > strlen($buffer)) {
            return null;
        }
        $raw = substr($buffer, $start, $size);
        if ($method === 0) {
            return $raw;
        }
        if ($method === 8) {
            $inflated = @gzinflate($raw);

            return $inflated === false ? null : $inflated;
        }

        return null;
    }

    /** @return array{ext: string, mime: string}|null */
    private static function fromMimeType(string $mimeType): ?array
    {
        $mimeType = strtolower($mimeType);

        return match ($mimeType) {
            'application/epub+zip' => ['ext' => 'epub', 'mime' => $mimeType],
            'application/vnd.oasis.opendocument.text' => ['ext' => 'odt', 'mime' => $mimeType],
            'application/vnd.oasis.opendocument.text-template' => ['ext' => 'ott', 'mime' => $mimeType],
            'application/vnd.oasis.opendocument.spreadsheet' => ['ext' => 'ods', 'mime' => $mimeType],
            'application/vnd.oasis.opendocument.spreadsheet-template' => ['ext' => 'ots', 'mime' => $mimeType],
            'application/vnd.oasis.opendocument.presentation' => ['ext' => 'odp', 'mime' => $mimeType],
            'application/vnd.oasis.opendocument.presentation-template' => ['ext' => 'otp', 'mime' => $mimeType],
            'application/vnd.oasis.opendocument.graphics' => ['ext' => 'odg', 'mime' => $mimeType],
            'application/vnd.oasis.opendocument.graphics-template' => ['ext' => 'otg', 'mime' => $mimeType],
            'application/vnd.openxmlformats-officedocument.presentationml.slideshow' => ['ext' => 'ppsx', 'mime' => $mimeType],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['ext' => 'xlsx', 'mime' => $mimeType],
            'application/vnd.ms-excel.sheet.macroenabled' => ['ext' => 'xlsm', 'mime' => 'application/vnd.ms-excel.sheet.macroenabled.12'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.template' => ['ext' => 'xltx', 'mime' => $mimeType],
            'application/vnd.ms-excel.template.macroenabled' => ['ext' => 'xltm', 'mime' => 'application/vnd.ms-excel.template.macroenabled.12'],
            'application/vnd.ms-powerpoint.slideshow.macroenabled' => ['ext' => 'ppsm', 'mime' => 'application/vnd.ms-powerpoint.slideshow.macroenabled.12'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['ext' => 'docx', 'mime' => $mimeType],
            'application/vnd.ms-word.document.macroenabled' => ['ext' => 'docm', 'mime' => 'application/vnd.ms-word.document.macroenabled.12'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.template' => ['ext' => 'dotx', 'mime' => $mimeType],
            'application/vnd.ms-word.template.macroenabledtemplate' => ['ext' => 'dotm', 'mime' => 'application/vnd.ms-word.template.macroenabled.12'],
            'application/vnd.openxmlformats-officedocument.presentationml.template' => ['ext' => 'potx', 'mime' => $mimeType],
            'application/vnd.ms-powerpoint.template.macroenabled' => ['ext' => 'potm', 'mime' => 'application/vnd.ms-powerpoint.template.macroenabled.12'],
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['ext' => 'pptx', 'mime' => $mimeType],
            'application/vnd.ms-powerpoint.presentation.macroenabled' => ['ext' => 'pptm', 'mime' => 'application/vnd.ms-powerpoint.presentation.macroenabled.12'],
            'application/vnd.ms-visio.drawing' => ['ext' => 'vsdx', 'mime' => 'application/vnd.visio'],
            'application/vnd.ms-package.3dmanufacturing-3dmodel+xml' => ['ext' => '3mf', 'mime' => 'model/3mf'],
            default => null,
        };
    }
}
