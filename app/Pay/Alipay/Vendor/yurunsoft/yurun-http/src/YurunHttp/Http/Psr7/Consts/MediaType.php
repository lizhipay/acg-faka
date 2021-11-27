<?php

namespace Yurun\Util\YurunHttp\Http\Psr7\Consts;

/**
 * 常见的媒体类型.
 */
abstract class MediaType
{
    const ALL = '*/*';

    const APPLICATION_ATOM_XML = 'application/atom+xml';

    const APPLICATION_FORM_URLENCODED = 'application/x-www-form-urlencoded';

    const APPLICATION_JSON = 'application/json';

    const APPLICATION_JSON_UTF8 = 'application/json;charset=UTF-8';

    const APPLICATION_OCTET_STREAM = 'application/octet-stream';

    const APPLICATION_PDF = 'application/pdf';

    const APPLICATION_PROBLEM_JSON = 'application/problem+json';

    const APPLICATION_PROBLEM_XML = 'application/problem+xml';

    const APPLICATION_RSS_XML = 'application/rss+xml';

    const APPLICATION_STREAM_JSON = 'application/stream+json';

    const APPLICATION_XHTML_XML = 'application/xhtml+xml';

    const APPLICATION_XML = 'application/xml';

    const IMAGE_JPEG = 'image/jpeg';

    const IMAGE_APNG = 'image/apng';

    const IMAGE_PNG = 'image/png';

    const IMAGE_GIF = 'image/gif';

    const IMAGE_WEBP = 'image/webp';

    const MULTIPART_FORM_DATA = 'multipart/form-data';

    const TEXT_EVENT_STREAM = 'text/event-stream';

    const TEXT_HTML = 'text/html';

    const TEXT_MARKDOWN = 'text/markdown';

    const TEXT_PLAIN = 'text/plain';

    const TEXT_XML = 'text/xml';

    /**
     * @var array
     */
    private static $extMap = [
        'Type/sub-type'                                                             => 'Extension',
        'text/h323'                                                                 => '323',
        'application/internet-property-stream'                                      => 'acx',
        'application/postscript'                                                    => 'ai',
        'audio/x-aiff'                                                              => 'aiff',
        'video/x-ms-asf'                                                            => 'asf',
        'audio/basic'                                                               => 'au',
        'video/x-msvideo'                                                           => 'avi',
        'application/olescript'                                                     => 'axs',
        'application/x-bcpio'                                                       => 'bcpio',
        'image/bmp'                                                                 => 'bmp',
        'application/vnd.ms-pkiseccat'                                              => 'cat',
        'application/x-cdf'                                                         => 'cdf',
        'application/x-msclip'                                                      => 'clp',
        'image/x-cmx'                                                               => 'cmx',
        'image/cis-cod'                                                             => 'cod',
        'application/x-cpio'                                                        => 'cpio',
        'application/x-mscardfile'                                                  => 'crd',
        'application/pkix-crl'                                                      => 'crl',
        'application/x-x509-ca-cert'                                                => 'crt',
        'application/x-csh'                                                         => 'csh',
        'text/css'                                                                  => 'css',
        'application/x-msdownload'                                                  => 'dll',
        'application/msword'                                                        => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.template'   => 'dotx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/x-dvi'                                                         => 'dvi',
        'application/x-director'                                                    => 'dxr',
        'text/x-setext'                                                             => 'etx',
        'application/envoy'                                                         => 'evy',
        'application/fractals'                                                      => 'fif',
        'image/gif'                                                                 => 'gif',
        'application/x-gtar'                                                        => 'gtar',
        'application/x-gzip'                                                        => 'gz',
        'application/x-hdf'                                                         => 'hdf',
        'application/winhlp'                                                        => 'hlp',
        'application/mac-binhex40'                                                  => 'hqx',
        'application/hta'                                                           => 'hta',
        'text/x-component'                                                          => 'htc',
        'text/html'                                                                 => 'html',
        'text/webviewhtml'                                                          => 'htt',
        'image/x-icon'                                                              => 'ico',
        'image/ief'                                                                 => 'ief',
        'application/x-iphone'                                                      => 'iii',
        'image/pipeg'                                                               => 'jfif',
        'image/jpeg'                                                                => 'jpg',
        'application/x-javascript'                                                  => 'js',
        'application/x-latex'                                                       => 'latex',
        'video/x-la-asf'                                                            => 'lsf',
        'audio/x-mpegurl'                                                           => 'm3u',
        'application/x-troff-man'                                                   => 'man',
        'application/x-msaccess'                                                    => 'mdb',
        'application/x-troff-me'                                                    => 'me',
        'message/rfc822'                                                            => 'mhtml',
        'audio/mid'                                                                 => 'mid',
        'application/x-msmoney'                                                     => 'mny',
        'video/quicktime'                                                           => 'mov',
        'video/x-sgi-movie'                                                         => 'movie',
        'video/mpeg'                                                                => 'mpeg',
        'application/vnd.ms-project'                                                => 'mpp',
        'application/x-troff-ms'                                                    => 'ms',
        'application/x-msmediaview'                                                 => 'mvb',
        'application/oda'                                                           => 'oda',
        'application/pkcs10'                                                        => 'p10',
        'application/x-pkcs7-mime'                                                  => 'p7m',
        'application/x-pkcs7-certreqresp'                                           => 'p7r',
        'application/x-pkcs7-signature'                                             => 'p7s',
        'image/x-portable-bitmap'                                                   => 'pbm',
        'application/pdf'                                                           => 'pdf',
        'application/x-pkcs12'                                                      => 'pfx',
        'image/x-portable-graymap'                                                  => 'pgm',
        'application/ynd.ms-pkipko'                                                 => 'pko',
        'image/x-portable-anymap'                                                   => 'pnm',
        'image/x-portable-pixmap'                                                   => 'ppm',
        'application/vnd.ms-powerpoint'                                             => 'ppt',
        'application/pics-rules'                                                    => 'prf',
        'application/x-mspublisher'                                                 => 'pub',
        'audio/x-pn-realaudio'                                                      => 'ram',
        'image/x-cmu-raster'                                                        => 'ras',
        'image/x-rgb'                                                               => 'rgb',
        'application/rtf'                                                           => 'rtf',
        'text/richtext'                                                             => 'rtx',
        'application/x-msschedule'                                                  => 'scd',
        'text/scriptlet'                                                            => 'sct',
        'application/set-payment-initiation'                                        => 'setpay',
        'application/set-registration-initiation'                                   => 'setreg',
        'application/x-sh'                                                          => 'sh',
        'application/x-shar'                                                        => 'shar',
        'application/x-stuffit'                                                     => 'sit',
        'application/x-pkcs7-certificates'                                          => 'spc',
        'application/futuresplash'                                                  => 'spl',
        'application/x-wais-source'                                                 => 'src',
        'application/vnd.ms-pkicertstore'                                           => 'sst',
        'application/vnd.ms-pkistl'                                                 => 'stl',
        'image/svg+xml'                                                             => 'svg',
        'application/x-sv4cpio'                                                     => 'sv4cpio',
        'application/x-sv4crc'                                                      => 'sv4crc',
        'application/x-shockwave-flash'                                             => 'swf',
        'application/x-tar'                                                         => 'tar',
        'application/x-tcl'                                                         => 'tcl',
        'application/x-tex'                                                         => 'tex',
        'application/x-texinfo'                                                     => 'texi',
        'application/x-compressed'                                                  => 'tgz',
        'image/tiff'                                                                => 'tiff',
        'application/x-msterminal'                                                  => 'trm',
        'text/tab-separated-values'                                                 => 'tsv',
        'text/plain'                                                                => 'txt',
        'text/iuls'                                                                 => 'uls',
        'application/x-ustar'                                                       => 'ustar',
        'text/x-vcard'                                                              => 'vcf',
        'audio/x-wav'                                                               => 'wav',
        'application/x-msmetafile'                                                  => 'wmf',
        'application/x-mswrite'                                                     => 'wri',
        'image/x-xbitmap'                                                           => 'xbm',
        'application/vnd.ms-excel'                                                  => 'xls',
        'image/x-xpixmap'                                                           => 'xpm',
        'image/x-xwindowdump'                                                       => 'xwd',
        'application/x-compress'                                                    => 'z',
        'application/zip'                                                           => 'zip',
        'application/vnd.android.package-archive'                                   => 'apk',
        'application/x-silverlight-app'                                             => 'xap',
        'application/vnd.iphone'                                                    => 'ipa',
        'text/markdown'                                                             => 'md',
        'text/xml'                                                                  => 'xml',
        'image/webp'                                                                => 'webp',
        'image/png'                                                                 => 'png',
    ];

    /**
     * 获取 ContentType 对应的扩展名(不包含点).
     *
     * @param string $contentType
     *
     * @return string|null
     */
    public static function getExt($contentType)
    {
        list($firstContentType) = explode(';', $contentType, 2);
        if (isset(static::$extMap[$firstContentType]))
        {
            return static::$extMap[$firstContentType];
        }
        else
        {
            return null;
        }
    }
}
