<?php

declare(strict_types=1);

it('does not register the client-config route by default', function (): void {
    $this->getJson('/broadcasting/appsync')->assertNotFound();
});
