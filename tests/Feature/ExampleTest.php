<?php

declare(strict_types=1);

test('la ruta de salud /up responde 200', function (): void {
    $this->get('/up')->assertOk();
});
