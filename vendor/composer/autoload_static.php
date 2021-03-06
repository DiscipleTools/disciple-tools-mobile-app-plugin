<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit16674d474a2285178b552eedc6194bd0
{
    public static $prefixLengthsPsr4 = array (
        'E' => 
        array (
            'ExponentPhpSDK\\' => 15,
        ),
        'D' => 
        array (
            'Dealerdirect\\Composer\\Plugin\\Installers\\PHPCodeSniffer\\' => 55,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'ExponentPhpSDK\\' => 
        array (
            0 => __DIR__ . '/..' . '/alymosul/exponent-server-sdk-php/lib',
        ),
        'Dealerdirect\\Composer\\Plugin\\Installers\\PHPCodeSniffer\\' => 
        array (
            0 => __DIR__ . '/..' . '/dealerdirect/phpcodesniffer-composer-installer/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit16674d474a2285178b552eedc6194bd0::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit16674d474a2285178b552eedc6194bd0::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
