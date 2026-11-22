<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

trait HasOrganizationCode
{
    public static function normalizeCode(?string $code): ?string
    {
        $normalized = Str::upper(Str::trim($code ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: self::normalizeCode(...));
    }

    public function organizationLabel(): string
    {
        return $this->code === null ? $this->name : $this->code.' — '.$this->name;
    }
}
