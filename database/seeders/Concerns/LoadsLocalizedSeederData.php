<?php

namespace ESolution\LaravelAccounting\Database\Seeders\Concerns;

trait LoadsLocalizedSeederData
{
    protected function localizedSeederLanguage(): string
    {
        $language = strtolower((string) config('accounting.default_language', 'id'));

        return in_array($language, ['id', 'en'], true) ? $language : 'id';
    }

    protected function loadLocalizedSeederData(string $filename): array
    {
        $language = $this->localizedSeederLanguage();
        $basePath = dirname(__DIR__).'/data';

        $localizedFile = $basePath.'/'.$language.'/'.$filename;
        if (is_file($localizedFile)) {
            return require $localizedFile;
        }

        $fallbackFile = $basePath.'/id/'.$filename;
        if (is_file($fallbackFile)) {
            return require $fallbackFile;
        }

        return [];
    }
}
