<?php

namespace Dashed\DashedEcommerceRendabiliteit;

use Filament\Panel;
use Filament\Contracts\Plugin;

class DashedEcommerceRendabiliteitPlugin implements Plugin
{
    public function getId(): string
    {
        return 'dashed-ecommerce-rendabiliteit';
    }

    public function register(Panel $panel): void
    {
    }

    public function boot(Panel $panel): void
    {
    }
}
