<?php

namespace App\SonarApi\Mutations\Inputs;

interface Input
{
    public function toArray(): array;

    public static function typeName(): string;
}